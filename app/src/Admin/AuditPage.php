<?php

declare(strict_types=1);

namespace Rerm\Admin;

use DateTimeImmutable;
use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Roster\RosterPage;

/**
 * The Audit Log read (spec 7.5) — filterable by actor, action and date.
 *
 * Read-only, in every sense. There is no write path on this screen and there
 * never will be: an audit row is append-only and outlives whatever it
 * describes, which is why `entity` and `entity_id` carry no foreign key
 * (001_schema.sql). A log somebody can edit answers no question worth asking.
 *
 * **The action filter is the reason `Rerm\Audit\Action` exists** (Phase 8,
 * open 2). Before it, twenty-two action strings were typed into six files;
 * a filter over that is a filter that silently matches nothing the first time
 * somebody writes `password_change`. The options here are the enum's cases —
 * plus whatever ELSE the table actually holds, from a DISTINCT read, so a
 * string written by a version of this application that predates the enum is
 * still findable. `Action::describe()` names both kinds, and never throws on
 * one it does not know.
 *
 * The actor filter is a select of the accounts that have actually acted,
 * rather than a search over 196 users: a list of who has done something is
 * short, and it is the list somebody investigating actually wants.
 */
final class AuditPage
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

        // The actor. 0 means everybody; a real id narrows. There is no
        // "system" option because a null actor is a real case — a refused
        // session token and the CLI password reset both have none — and it is
        // offered as its own choice below.
        $actor = (string) ($input['actor'] ?? '');
        if ($actor === 'none') {
            $where[] = 'a.actor_user_id IS NULL';
        } elseif ($actor !== '' && ctype_digit($actor)) {
            $where[]        = 'a.actor_user_id = :actor';
            $bind[':actor'] = (int) $actor;
        } else {
            $actor = '';
        }

        // The action. Validated against what the column can hold rather than
        // against the enum alone, so a historical string is filterable.
        $actionInput = is_string($input['action'] ?? null) ? $input['action'] : '';
        $actions     = $this->actionsInUse();
        $action      = in_array($actionInput, $actions, true) ? $actionInput : '';
        if ($action !== '') {
            $where[]         = 'a.action = :action';
            $bind[':action'] = $action;
        }

        // The dates. Inclusive at both ends, which is what a person means by
        // "from the 1st to the 3rd" — so the upper bound is the START of the
        // day after. Parsed with DateTimeImmutable: `intl` is absent from this
        // host and there is no IntlDateFormatter to reach for.
        //
        // The column is UTC and the input is a plain date, so this is a UTC
        // window. A contact logged at 7pm Houston time on the 3rd lands on the
        // 4th in UTC; that is honest for an audit trail, and the screen says
        // the times are UTC.
        $from = self::date($input['from'] ?? '');
        $to   = self::date($input['to'] ?? '');

        if ($from !== null) {
            $where[]       = 'a.occurred_at >= :from';
            $bind[':from'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where[]     = 'a.occurred_at < :to';
            $bind[':to'] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }

        $predicate = implode(' AND ', $where);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log a WHERE {$predicate}");
        $count->execute($bind);
        $total = (int) $count->fetchColumn();

        $size = (int) ($input['size'] ?? $this->pageSizeDefault);
        if ($size !== $this->pageSizeDefault && $size !== $this->pageSizeLarge) {
            $size = $this->pageSizeDefault;
        }

        $pages  = max(1, (int) ceil($total / $size));
        $page   = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $size;

        // Newest first, always. An audit log is read from the top: "what just
        // happened" is the question, and "what happened in 2026" is the
        // date filter's job.
        $read = $this->pdo->prepare(
            'SELECT a.id, a.actor_user_id, a.action, a.entity, a.entity_id,'
            . ' a.before_json, a.after_json, a.occurred_at, a.ip,'
            . ' m.member_number AS actor_number, m.first_name AS actor_first,'
            . ' m.last_name AS actor_last, m.preferred_name AS actor_preferred'
            . ' FROM audit_log a'
            . ' LEFT JOIN app_user u ON u.id = a.actor_user_id'
            . ' LEFT JOIN member m ON m.id = u.member_id'
            . " WHERE {$predicate}"
            . " ORDER BY a.occurred_at DESC, a.id DESC LIMIT {$size} OFFSET {$offset}"
        );
        $read->execute($bind);

        $rows = [];
        foreach ($read->fetchAll() as $row) {
            $rows[] = [
                'id'          => (int) $row['id'],
                'action'      => (string) $row['action'],
                'action_word' => Action::describe((string) $row['action']),
                'entity'      => (string) $row['entity'],
                'entity_id'   => (string) $row['entity_id'],
                'occurred_at' => (string) $row['occurred_at'],
                'ip'          => (string) $row['ip'],
                'actor'       => $row['actor_number'] !== null
                    ? RosterPage::displayName(
                        (string) $row['actor_preferred'],
                        (string) $row['actor_first'],
                        (string) $row['actor_last'],
                        (string) $row['actor_number']
                    )
                    : null,
                'actor_number' => $row['actor_number'] !== null ? (string) $row['actor_number'] : null,

                // The payloads, pretty-printed for a human. Decoded here
                // rather than in the view so a row whose JSON a future
                // migration wrote badly renders as its raw text instead of
                // taking the page down.
                'before' => self::pretty($row['before_json']),
                'after'  => self::pretty($row['after_json']),
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

            'actor'   => $actor,
            'action'  => $action,
            'from'    => $from ?? '',
            'to'      => $to ?? '',

            'actors'  => $this->actors(),
            'actions' => self::actionOptions($actions),
        ];
    }

    /**
     * The actions the table actually holds, so the filter can never offer one
     * that matches nothing — and never omits one that does.
     *
     * @return array<int, string>
     */
    private function actionsInUse(): array
    {
        $read = $this->pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action');

        return array_map('strval', $read->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Those actions as value => label, through Action::describe() so a known
     * one reads as English and an unknown one reads as itself.
     *
     * @param array<int, string> $inUse
     * @return array<string, string>
     */
    private static function actionOptions(array $inUse): array
    {
        $options = [];
        foreach ($inUse as $value) {
            $options[$value] = Action::describe($value);
        }

        asort($options);

        return $options;
    }

    /**
     * The accounts that have actually acted. A list of who has done something
     * is short; a list of every account is 196 names, most of which have
     * never appeared in this table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function actors(): array
    {
        $read = $this->pdo->query(
            'SELECT u.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' COUNT(*) AS entries'
            . ' FROM audit_log a'
            . ' INNER JOIN app_user u ON u.id = a.actor_user_id'
            . ' INNER JOIN member m ON m.id = u.member_id'
            . ' GROUP BY u.id, m.member_number, m.first_name, m.last_name, m.preferred_name'
            . ' ORDER BY m.last_name, m.first_name, u.id'
        );

        $actors = [];
        foreach ($read->fetchAll() as $row) {
            $actors[] = [
                'id'      => (int) $row['id'],
                'name'    => RosterPage::displayName(
                    (string) $row['preferred_name'],
                    (string) $row['first_name'],
                    (string) $row['last_name'],
                    (string) $row['member_number']
                ),
                'entries' => (int) $row['entries'],
            ];
        }

        return $actors;
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

    /**
     * A JSON payload as indented text a person can read, or '' for none.
     * Invalid JSON comes back as its own raw text: an audit log that throws
     * on its own history is an audit log nobody can open.
     */
    private static function pretty(mixed $json): string
    {
        if (!is_string($json) || trim($json) === '') {
            return '';
        }

        $decoded = json_decode($json, true);

        if ($decoded === null && strtolower(trim($json)) !== 'null') {
            return $json;
        }

        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? $json : $encoded;
    }
}
