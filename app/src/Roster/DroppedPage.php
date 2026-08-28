<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;
use Rerm\App;
use Rerm\Auth\User;

/**
 * Dropped Members (Phase 8.5) — the people who fell off the roster, in the
 * caller's own scope.
 *
 * Every other read in the application hides them: `ScopedQuery::visible()`
 * excludes `dropped_since_import_id IS NOT NULL`, which is what stops a
 * member who was not in the last file from cluttering a dashboard. The cost
 * of that was a question nobody could ask — "has anyone on my team stopped
 * being on the roster?" — because the only screen that knew was the Admin
 * purge list, which is Admin-only and unscoped.
 *
 * So this reads through `ScopedQuery::droppedForUser()`: the SAME scope
 * predicate as every other roster read, over the population the others
 * exclude. An Officer sees their own team's dropped members, a Senior Officer
 * their division's, an Executive the committee's.
 *
 * **Read-only, and deliberately.** Purging and restoring stay on the Admin
 * screen behind `Capability::ImportRoster`, with the typed confirmation. What
 * an officer needs here is to KNOW — so they can ring the person and find out
 * whether they actually left — not to act.
 *
 * Dropped is not purged, and the screen says so rather than assuming the
 * reader remembers: a dropped member was missed by one import and comes back
 * automatically the moment they reappear in a file.
 */
final class DroppedPage
{
    /**
     * Sort keys as the URL spells them -> the column each one means. The
     * user's value chooses FROM this table and never reaches the SQL string.
     *
     * The default is the batch, descending: the most recent import's losses
     * are the ones worth a phone call this week.
     */
    private const SORTS = [
        'dropped' => 'b.started_at',
        'name'    => 'm.last_name',
        'team'    => 't.name',
        'number'  => 'm.member_number',
    ];

    public const DEFAULT_SORT = 'dropped';
    public const DEFAULT_DIR  = 'desc';

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

    /** @return array<int, string> the sort keys the URL may name */
    public static function sortKeys(): array
    {
        return array_keys(self::SORTS);
    }

    /**
     * @param array<string, mixed> $input the raw query string, untrusted
     * @return array<string, mixed>
     */
    public function page(User $user, int $showYearId, array $input): array
    {
        $scoped = ScopedQuery::droppedForUser($user);
        $where  = $scoped->predicate();
        $bind   = $scoped->bindings();

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM member m WHERE {$where}");
        $count->execute($bind);
        $total = (int) $count->fetchColumn();

        $sortKey = is_string($input['sort'] ?? null) && isset(self::SORTS[$input['sort']])
            ? $input['sort']
            : self::DEFAULT_SORT;
        $dir     = ($input['dir'] ?? self::DEFAULT_DIR) === 'asc' ? 'asc' : 'desc';
        $orderBy = self::SORTS[$sortKey] . ' ' . strtoupper($dir)
            . ', m.last_name ASC, m.first_name ASC, m.id ASC';

        $size = (int) ($input['size'] ?? $this->pageSizeDefault);
        if ($size !== $this->pageSizeDefault && $size !== $this->pageSizeLarge) {
            $size = $this->pageSizeDefault;
        }

        $pages  = max(1, (int) ceil($total / $size));
        $page   = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $size;

        // Which import dropped them, and when, is the column that turns this
        // from a list into an answer — so it is joined rather than looked up
        // per row. LIMIT and OFFSET are integers cast above; a string-bound
        // LIMIT fails on the native protocol.
        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.title, m.phone, m.phone_e164, m.phone_type, m.email,'
            . ' t.name AS team_name, d.name AS division_name,'
            . ' b.id AS batch_id, b.started_at AS dropped_at, b.mode AS batch_mode,'
            . ' b.filename AS batch_filename,'
            . ' (SELECT COUNT(*) FROM contact_log c'
            . '   WHERE c.member_id = m.id AND c.show_year_id = :contact_year) AS contact_count'
            . ' FROM member m'
            . ' LEFT JOIN team t ON t.id = m.team_id'
            . ' INNER JOIN division d ON d.id = m.division_id'
            . ' LEFT JOIN import_batch b ON b.id = m.dropped_since_import_id'
            . " WHERE {$where} ORDER BY {$orderBy} LIMIT {$size} OFFSET {$offset}"
        );
        $read->execute($bind + [':contact_year' => $showYearId]);

        $rows = [];
        foreach ($read->fetchAll() as $row) {
            $rows[] = [
                'id'            => (int) $row['id'],
                'member_number' => (string) $row['member_number'],
                'name'          => RosterPage::displayName(
                    (string) $row['preferred_name'],
                    (string) $row['first_name'],
                    (string) $row['last_name'],
                    (string) $row['member_number']
                ),
                'title'         => (string) $row['title'],
                'team_name'     => (string) ($row['team_name'] ?? ''),
                'division_name' => (string) $row['division_name'],

                'batch_id'       => $row['batch_id'] !== null ? (int) $row['batch_id'] : null,
                'dropped_at'     => $row['dropped_at'] !== null ? (string) $row['dropped_at'] : null,
                'batch_mode'     => (string) ($row['batch_mode'] ?? ''),
                'batch_filename' => (string) ($row['batch_filename'] ?? ''),

                'contact_count' => (int) $row['contact_count'],

                // The contact actions, same rule as every other roster screen
                // (spec 8.4): sms: only for a CELL PHONE, mailto: only when
                // an address exists. This is the screen where somebody rings
                // to ask "have you left?", so the buttons matter more here
                // than almost anywhere.
                'phone'      => (string) $row['phone'],
                'phone_e164' => (string) ($row['phone_e164'] ?? ''),
                'email'      => trim((string) ($row['email'] ?? '')),
                'can_call'   => (string) ($row['phone_e164'] ?? '') !== '',
                'can_text'   => (string) ($row['phone_e164'] ?? '') !== ''
                    && (string) $row['phone_type'] === 'CELL PHONE',
                'can_email'  => trim((string) ($row['email'] ?? '')) !== '',
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
            'from'         => $total === 0 ? 0 : $offset + 1,
            'to'           => $offset + count($rows),
            'sort'         => $sortKey,
            'dir'          => $dir,
        ];
    }
}
