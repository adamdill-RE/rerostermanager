<?php

declare(strict_types=1);

namespace Rerm\Admin;

use PDO;
use Rerm\App;
use Rerm\Roster\RosterPage;

/**
 * The Flagged for Purge read (spec 6.5).
 *
 * This is the one screen in the application whose whole subject is the
 * members every other screen hides. `ScopedQuery::visible()` filters out
 * `purged_at IS NOT NULL` and `absent_since_import_id IS NOT NULL`, which is
 * exactly the population here, so this class deliberately does NOT use it —
 * and asserts the two columns it does read in their place. It keeps
 * `is_system = 0` unconditionally: the seeded master administrator is an
 * account, not a committee member, and offering an Admin the chance to purge
 * the only row that can sign in is how a database becomes unreachable.
 *
 * There is no scope predicate either, and that is the capability's doing
 * rather than an omission: purge carries `Capability::ImportRoster`, which is
 * Admin / Everywhere, so there is no narrower scope to apply. Every write
 * still asks `Access::allows()` with a Subject per member (Rerm\Admin\Purge),
 * because a bulk purge of fifty is fifty questions and not one.
 *
 * Two lists, because there are two jobs:
 *
 *   flagged   the members the last complete or team import did not see, with
 *             the batch that flagged them and when it ran. An import
 *             un-flags a member who reappears; nothing else does.
 *   purged    the members somebody has already purged. They are here so
 *             Restore exists (Phase 8 decided 4) — an import does NOT clear
 *             `purged_at`, so without this list a mistaken purge is
 *             invisible forever and needs somebody at the database.
 */
final class PurgePage
{
    /** Which list the screen is showing. */
    public const LISTS = ['flagged', 'purged'];

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
        $list = is_string($input['list'] ?? null) && in_array($input['list'], self::LISTS, true)
            ? $input['list']
            : 'flagged';

        // The two populations, as one predicate each. `is_system = 0` is in
        // both: the master administrator is never flagged, never purged and
        // never offered.
        $where = $list === 'purged'
            ? 'm.is_system = 0 AND m.purged_at IS NOT NULL'
            : 'm.is_system = 0 AND m.purged_at IS NULL AND m.absent_since_import_id IS NOT NULL';

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM member m WHERE {$where}")->fetchColumn();

        // Page size is one of exactly two configured values. It also bounds
        // the checkbox count: max_input_vars is 1000 on this host with SILENT
        // truncation, and 100 checkboxes plus the token, the action and the
        // typed confirmation is comfortably inside it (docs/hosting.md).
        $size = (int) ($input['size'] ?? $this->pageSizeDefault);
        if ($size !== $this->pageSizeDefault && $size !== $this->pageSizeLarge) {
            $size = $this->pageSizeDefault;
        }

        $pages  = max(1, (int) ceil($count / $size));
        $page   = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $size;

        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.title, m.purged_at, m.absent_since_import_id,'
            . ' t.name AS team_name, d.name AS division_name,'
            . ' b.started_at AS batch_started_at, b.filename AS batch_filename, b.mode AS batch_mode,'
            . ' (SELECT COUNT(*) FROM contact_log c WHERE c.member_id = m.id) AS contact_count,'
            . ' (SELECT COUNT(*) FROM assignment a WHERE a.member_id = m.id AND a.removed_at IS NULL)'
            . '   AS assignment_count'
            . ' FROM member m'
            . ' LEFT JOIN team t ON t.id = m.team_id'
            . ' INNER JOIN division d ON d.id = m.division_id'
            . ' LEFT JOIN import_batch b ON b.id = m.absent_since_import_id'
            . " WHERE {$where}"
            . ' ORDER BY m.last_name ASC, m.first_name ASC, m.id ASC'
            . " LIMIT {$size} OFFSET {$offset}"
        );
        $read->execute();

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
                'purged_at'     => $row['purged_at'] !== null ? (string) $row['purged_at'] : null,
                'batch_id'      => $row['absent_since_import_id'] !== null
                    ? (int) $row['absent_since_import_id']
                    : null,
                'batch_started_at' => $row['batch_started_at'] !== null
                    ? (string) $row['batch_started_at']
                    : null,
                'batch_filename'   => (string) ($row['batch_filename'] ?? ''),
                'batch_mode'       => (string) ($row['batch_mode'] ?? ''),

                // What a purge does NOT take with it, said out loud on the
                // row (spec 5.5, 6.5): nothing cascades, so these numbers are
                // unchanged by purging and by restoring.
                'contact_count'    => (int) $row['contact_count'],
                'assignment_count' => (int) $row['assignment_count'],
            ];
        }

        // The other list's size, so the toggle can say how much is over there
        // without a second page load.
        $otherWhere = $list === 'purged'
            ? 'm.is_system = 0 AND m.purged_at IS NULL AND m.absent_since_import_id IS NOT NULL'
            : 'm.is_system = 0 AND m.purged_at IS NOT NULL';
        $otherCount = (int) $this->pdo->query("SELECT COUNT(*) FROM member m WHERE {$otherWhere}")
            ->fetchColumn();

        return [
            'list'         => $list,
            'rows'         => $rows,
            'total'        => $count,
            'other_total'  => $otherCount,
            'page'         => $page,
            'pages'        => $pages,
            'size'         => $size,
            'size_default' => $this->pageSizeDefault,
            'size_large'   => $this->pageSizeLarge,
            'from'         => $count === 0 ? 0 : $offset + 1,
            'to'           => $offset + count($rows),
            'confirm_word' => Purge::CONFIRM_WORD,
            'max_selection' => Purge::MAX_SELECTION,
        ];
    }
}
