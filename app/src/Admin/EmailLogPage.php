<?php

declare(strict_types=1);

namespace Rerm\Admin;

use DateTimeImmutable;
use PDO;
use Rerm\App;
use Rerm\Mail\EmailLog;
use Rerm\Roster\RosterPage;

/**
 * The Email Log read (Phase 14.8, spec-v2 §22) — every message the
 * application tried to send, newest first, filterable by outcome, purpose
 * and date.
 *
 * Read-only, in every sense, for the audit log's reason: a row here is the
 * record of an attempt, written by the Mailer at the moment it knew what
 * became of the message (Rerm\Mail\EmailLog), and a log somebody can edit
 * answers no question worth asking. The filter is a GET so "every blocked
 * message in October" is a link.
 *
 * The three columns the request asked for are the three the row keeps:
 * what prompted it (the purpose and its sentence), who it went to (the
 * account and the address), and whether it went (the outcome, with the
 * interlock or the error beside it). The body is never kept — a recovery
 * link is a credential — so nothing here shows one.
 */
final class EmailLogPage
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $pageSizeDefault,
        private readonly int $pageSizeLarge,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self(
            $app->db(),
            (int) $app->config()->get('roster.page_size_mobile', 50),
            (int) $app->config()->get('roster.page_size_desktop', 100),
        );
    }

    /**
     * @param array<string, mixed> $input the raw query string, untrusted
     * @return array<string, mixed>
     */
    public function page(array $input): array
    {
        $where = ['1 = 1'];
        $bind  = [];

        // The outcome and the purpose, each validated against the words the
        // writer uses — anything else is "all", never an error and never
        // the input.
        $outcome = is_string($input['outcome'] ?? null) && isset(EmailLog::OUTCOMES[$input['outcome']])
            ? $input['outcome']
            : '';
        if ($outcome !== '') {
            $where[]          = 'l.outcome = :outcome';
            $bind[':outcome'] = $outcome;
        }

        $purpose = is_string($input['purpose'] ?? null) && isset(EmailLog::PURPOSES[$input['purpose']])
            ? $input['purpose']
            : '';
        if ($purpose !== '') {
            $where[]          = 'l.purpose = :purpose';
            $bind[':purpose'] = $purpose;
        }

        // The dates: the audit log's rule, inclusive at both ends in UTC.
        $from = self::date($input['from'] ?? '');
        $to   = self::date($input['to'] ?? '');

        if ($from !== null) {
            $where[]       = 'l.occurred_at >= :from';
            $bind[':from'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where[]     = 'l.occurred_at < :to';
            $bind[':to'] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }

        // A recipient, by member number: "did this person get it" is the
        // question the screen exists for. Resolves to the account, so an
        // address that has since changed on the roster still finds them.
        $memberNumber = trim(is_string($input['member'] ?? null) ? $input['member'] : '');
        if ($memberNumber !== '') {
            $find = $this->pdo->prepare(
                'SELECT u.id FROM app_user u INNER JOIN member m ON m.id = u.member_id'
                . ' WHERE m.member_number = :number'
            );
            $find->execute([':number' => mb_substr($memberNumber, 0, 32)]);
            $userId = $find->fetchColumn();
            if ($userId !== false) {
                $where[]            = 'l.recipient_user_id = :recipient';
                $bind[':recipient'] = (int) $userId;
            } else {
                $where[] = '1 = 0';
            }
        }

        $predicate = implode(' AND ', $where);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM email_log l WHERE {$predicate}");
        $count->execute($bind);
        $total = (int) $count->fetchColumn();

        $size = (int) ($input['size'] ?? $this->pageSizeDefault);
        if ($size !== $this->pageSizeDefault && $size !== $this->pageSizeLarge) {
            $size = $this->pageSizeDefault;
        }

        $pages  = max(1, (int) ceil($total / $size));
        $page   = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $size;

        // Newest first, always: "did the email go just now" is the question.
        $read = $this->pdo->prepare(
            'SELECT l.id, l.occurred_at, l.purpose, l.reason, l.recipient_user_id, l.recipient,'
            . ' l.subject, l.transport, l.outcome, l.detail, l.actor_user_id,'
            . ' rm.member_number AS r_number, rm.first_name AS r_first, rm.last_name AS r_last,'
            . ' rm.preferred_name AS r_preferred,'
            . ' am.member_number AS a_number, am.first_name AS a_first, am.last_name AS a_last,'
            . ' am.preferred_name AS a_preferred'
            . ' FROM email_log l'
            . ' LEFT JOIN app_user ru ON ru.id = l.recipient_user_id'
            . ' LEFT JOIN member rm ON rm.id = ru.member_id'
            . ' LEFT JOIN app_user au ON au.id = l.actor_user_id'
            . ' LEFT JOIN member am ON am.id = au.member_id'
            . " WHERE {$predicate}"
            . " ORDER BY l.occurred_at DESC, l.id DESC LIMIT {$size} OFFSET {$offset}"
        );
        $read->execute($bind);

        $name = static fn (array $r, string $p): ?string => $r[$p . '_number'] === null ? null : RosterPage::displayName(
            (string) $r[$p . '_preferred'],
            (string) $r[$p . '_first'],
            (string) $r[$p . '_last'],
            (string) $r[$p . '_number']
        );

        $rows = [];
        foreach ($read->fetchAll() as $row) {
            $rows[] = [
                'id'               => (int) $row['id'],
                'occurred_at'      => (string) $row['occurred_at'],
                'purpose'          => (string) $row['purpose'],
                'purpose_word'     => EmailLog::PURPOSES[(string) $row['purpose']] ?? (string) $row['purpose'],
                'reason'           => (string) $row['reason'],
                'recipient'        => (string) $row['recipient'],
                'recipient_name'   => $name($row, 'r'),
                'recipient_number' => $row['r_number'] === null ? null : (string) $row['r_number'],
                'subject'          => (string) $row['subject'],
                'transport'        => (string) $row['transport'],
                'outcome'          => (string) $row['outcome'],
                'outcome_word'     => EmailLog::OUTCOMES[(string) $row['outcome']] ?? (string) $row['outcome'],
                'delivered'        => in_array((string) $row['outcome'], EmailLog::DELIVERED, true),
                'detail'           => (string) $row['detail'],
                'actor_name'       => $name($row, 'a'),
                'actor_number'     => $row['a_number'] === null ? null : (string) $row['a_number'],
            ];
        }

        return [
            'rows'         => $rows,
            'total'        => $total,
            'page'         => $page,
            'pages'        => $pages,
            'size'         => $size,
            'size_default' => $this->pageSizeDefault,
            'size_large'   => $this->pageSizeLarge,
            'from_row'     => $total === 0 ? 0 : $offset + 1,
            'to_row'       => $offset + count($rows),

            'outcome'  => $outcome,
            'purpose'  => $purpose,
            'from'     => $from ?? '',
            'to'       => $to ?? '',
            'member'   => mb_substr($memberNumber, 0, 32),

            'outcomes' => EmailLog::OUTCOMES,
            'purposes' => EmailLog::PURPOSES,
        ];
    }

    /** A YYYY-MM-DD from a date input, or null for anything else. */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date === false ? null : $date->format('Y-m-d');
    }
}
