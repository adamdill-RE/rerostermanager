<?php

declare(strict_types=1);

namespace Rerm\Import;

use PDO;
use Rerm\App;
use Rerm\Roster\Metric;
use Rerm\Roster\RosterPage;

/**
 * Import History (Phase 10) — the read side of `import_change`, and the
 * screen that answers a question Rodeo Houston's export cannot.
 *
 * Their file is a snapshot with no history in it at all: this is the
 * committee as of the moment somebody pressed Export. So when a member's team
 * changes, or a Captain arrives as a Committee Member, or somebody simply
 * stops appearing, the only way to find out WHEN — and therefore which file
 * did it, and therefore who to ask about it — was to keep every spreadsheet
 * and diff them by hand.
 *
 * `import_batch` already answers it in aggregate, and has since Phase 2: how
 * many rows a file created, updated and dropped, which metrics moved, which
 * teams were new, how many warnings of each kind. That summary was written at
 * stage time and then only ever read by the preview, which is thrown away.
 * This screen reads it back, permanently, and puts the field-level record
 * beside it.
 *
 * Three questions, three shapes, one screen:
 *
 *   * **Every import.** The list, newest first, with its stored summary. What
 *     came in, when, from which file, applied by whom.
 *   * **One import.** What it changed, grouped by field with counts, and
 *     drillable to the people in each group — including, first, the people it
 *     dropped.
 *   * **One member.** Everything every import has ever done to them, oldest
 *     at the bottom: appeared, moved team, lost a title, disappeared, came
 *     back.
 *
 * Read-only, in every sense — like the Audit Log, and for the same reason. It
 * carries no write path and never will: a record somebody can edit answers no
 * question worth asking. It also does not undo anything, and nothing here
 * should ever grow the ability to: a wrong import is fixed by importing the
 * right file, which diffs against the roster as it now stands (spec 6.3).
 *
 * Admin only, through `Capability::ImportRoster`, so there is no
 * `ScopedQuery` here. That is deliberate rather than an omission: the whole
 * value of the screen is seeing a member move BETWEEN teams and divisions,
 * and a scoped read would show half of such a move and hide the other half,
 * which is worse than not showing it at all.
 */
final class ImportHistory
{
    /**
     * How the diff's own column names read on a screen.
     *
     * The keys are exactly what `Importer::diff()` writes, which is exactly
     * what `import_change`.`field` stores: the raw column name. Labelling
     * here rather than at write time is what lets the label be improved
     * without rewriting history, and what keeps the stored vocabulary equal
     * to the importer's.
     *
     * A field this map does not know renders as itself (`fieldLabel()`), so a
     * column a future phase adds to the import is legible here the day it
     * lands rather than the day somebody remembers this file.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'title'                            => 'Title',
        'title_level'                      => 'Access level from title',
        'first_name'                       => 'First name',
        'last_name'                        => 'Last name',
        'preferred_name'                   => 'Preferred name',
        'full_name'                        => 'Full name',
        'prefix'                           => 'Prefix',
        'address'                          => 'Address',
        'city'                             => 'City',
        'state'                            => 'State',
        'zip'                              => 'ZIP',
        'phone'                            => 'Phone',
        'phone_e164'                       => 'Phone (dialable form)',
        'phone_type'                       => 'Phone type',
        'email'                            => 'Email',
        'legal_name_verified'              => 'Legal name verified',
        'is_rookie'                        => 'Rookie',
        'in_other_committees'              => 'In other committees',
        'badge_pickup_person'              => 'Badge pickup person',
        'badge_released'                   => 'Badge released',
        'ltc_applied'                      => 'LTC applied',
        'badge_released_date_raw'          => 'Badge released date',
        'badge_issue_date_raw'             => 'Badge issue date',
        'eligible_for_service_history_raw' => 'Eligible for service history',
        'eligibility_updated_by_raw'       => 'Eligibility updated by',
        'team'                             => 'Team',
        'division'                         => 'Division',
    ];

    /**
     * The kinds, in the order the screen offers them — the two that answer
     * "somebody disappeared" first, because that is the sentence that brings
     * people to this screen.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        'dropped'  => 'Dropped from the roster',
        'returned' => 'Back on the roster',
        'created'  => 'First appeared',
        'updated'  => 'Changed',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $pageSize,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db(), (int) $app->config()->get('roster.page_size_desktop', 100));
    }

    /**
     * Everything the screen needs, for whichever of its three shapes the
     * query string asked for.
     *
     * `$input` is the raw query string, untrusted and normalised here so the
     * view renders only decided values.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function page(array $input): array
    {
        $memberQuery = trim((string) ($input['member'] ?? ''));
        $batchId     = (int) ($input['batch'] ?? 0);

        if ($memberQuery !== '') {
            return $this->memberView($memberQuery, $input);
        }

        if ($batchId > 0) {
            return $this->batchView($batchId, $input);
        }

        return [
            'view'     => 'batches',
            'q'        => '',
            'batches'  => $this->batches(),
        ] + self::emptyPaging();
    }

    // -----------------------------------------------------------------------
    // Every import
    // -----------------------------------------------------------------------

    /**
     * Batches that touched the roster, newest first — applied and failed
     * both, because a failed one wrote rows too and is the only answer to
     * "the roster changed and no import says it did".
     *
     * Staged-but-unapplied batches are NOT here. They changed nothing, they
     * are swept after 24 hours, and offering one beside imports that really
     * happened is how somebody comes to believe a file was applied when it
     * was only ever read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function batches(int $limit = 50): array
    {
        $read = $this->pdo->query(
            'SELECT b.id, b.mode, b.filename, b.rows_read, b.rows_created, b.rows_updated,'
            . ' b.rows_unchanged, b.rows_dropped, b.warnings_count, b.started_at, b.applied_at,'
            . ' b.failed_at, b.failure_reason, b.summary_json,'
            . ' y.label AS show_year_label, t.name AS team_name,'
            . ' m.member_number AS actor_number, m.first_name AS actor_first,'
            . ' m.last_name AS actor_last, m.preferred_name AS actor_preferred'
            . ' FROM import_batch b'
            . ' INNER JOIN show_year y ON y.id = b.show_year_id'
            . ' LEFT JOIN team t ON t.id = b.team_id'
            . ' LEFT JOIN app_user u ON u.id = b.uploaded_by'
            . ' LEFT JOIN member m ON m.id = u.member_id'
            . ' WHERE b.applied_at IS NOT NULL OR b.failed_at IS NOT NULL'
            . ' ORDER BY b.id DESC LIMIT ' . max(1, $limit)
        );

        $batches = [];
        foreach ($read->fetchAll() as $row) {
            $batches[] = self::decorateBatch($row);
        }

        return $batches;
    }

    // -----------------------------------------------------------------------
    // One import
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function batchView(int $batchId, array $input): array
    {
        $read = $this->pdo->prepare(
            'SELECT b.*, y.label AS show_year_label, t.name AS team_name,'
            . ' m.member_number AS actor_number, m.first_name AS actor_first,'
            . ' m.last_name AS actor_last, m.preferred_name AS actor_preferred'
            . ' FROM import_batch b'
            . ' INNER JOIN show_year y ON y.id = b.show_year_id'
            . ' LEFT JOIN team t ON t.id = b.team_id'
            . ' LEFT JOIN app_user u ON u.id = b.uploaded_by'
            . ' LEFT JOIN member m ON m.id = u.member_id'
            . ' WHERE b.id = :id'
        );
        $read->execute([':id' => $batchId]);
        $row = $read->fetch();

        if (!is_array($row)) {
            return [
                'view'    => 'batches',
                'q'       => '',
                'missing' => $batchId,
                'batches' => $this->batches(),
            ] + self::emptyPaging();
        }

        $batch = self::decorateBatch($row);

        // The kind and field narrowing. Both are validated against what this
        // batch actually holds rather than against a list of what a batch
        // could hold, so the screen can never offer a group that is empty.
        $groups = $this->groups($batchId);

        $kind  = is_string($input['kind'] ?? null) ? $input['kind'] : '';
        $field = is_string($input['field'] ?? null) ? $input['field'] : '';

        $known = false;
        foreach ($groups as $group) {
            if ($group['kind'] === $kind && $group['field'] === $field) {
                $known = true;
            }
        }
        if (!$known) {
            // Not an error: this screen is reached by link and a group can be
            // narrowed away by a later import. An unknown pair shows the
            // whole batch, which is where the link came from.
            $kind  = '';
            $field = '';
        }

        $where = ['c.import_batch_id = :batch'];
        $bind  = [':batch' => $batchId];
        if ($kind !== '') {
            $where[]        = 'c.kind = :kind';
            $bind[':kind']  = $kind;
            $where[]        = 'c.field = :field';
            $bind[':field'] = $field;
        }

        $paging = $this->rows(implode(' AND ', $where), $bind, $input);

        return [
            'view'   => 'batch',
            'q'      => '',
            'batch'  => $batch,
            'groups' => $groups,
            'kind'   => $kind,
            'field'  => $field,
        ] + $paging;
    }

    /**
     * What one import changed, grouped by kind and field, with a count each —
     * the shape of the whole diff on one screen, before anybody reads a
     * single row of it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groups(int $batchId): array
    {
        $read = $this->pdo->prepare(
            'SELECT kind, field, COUNT(*) AS members FROM import_change'
            . ' WHERE import_batch_id = :batch GROUP BY kind, field'
        );
        $read->execute([':batch' => $batchId]);

        $groups = [];
        foreach ($read->fetchAll() as $row) {
            $kind  = (string) $row['kind'];
            $field = (string) $row['field'];

            $groups[] = [
                'kind'    => $kind,
                'field'   => $field,
                'members' => (int) $row['members'],
                'label'   => $kind === 'updated'
                    ? self::fieldLabel($field)
                    : (self::KINDS[$kind] ?? $kind),
                // Dropped first, then returned, then created, then each
                // changed field alphabetically: the order somebody scanning
                // for "who disappeared" needs, not the order SQL happened to
                // return.
                'sort'    => (int) array_search($kind, array_keys(self::KINDS), true),
            ];
        }

        usort($groups, static function (array $a, array $b): int {
            return [$a['sort'], $a['label']] <=> [$b['sort'], $b['label']];
        });

        return $groups;
    }

    // -----------------------------------------------------------------------
    // One member
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function memberView(string $query, array $input): array
    {
        $matches = $this->findMembers($query);

        // One match is the answer; anything else is a question, and asking it
        // is better than guessing. Names are not unique in this roster — 1,951
        // distinct of 1,954 — so a name that matches two people must never
        // silently become one of them.
        if (count($matches) !== 1) {
            return [
                'view'    => 'search',
                'q'       => $query,
                'matches' => $matches,
            ] + self::emptyPaging();
        }

        $member = $matches[0];

        // BY NUMBER, not by id. A create whose id could not be resolved still
        // carries the number, and the number is what survives a member row
        // being rebuilt. Both are matched so a row written either way is
        // found.
        $paging = $this->rows(
            '(c.member_id = :member_id OR c.member_number = :member_number)',
            [':member_id' => (int) $member['id'], ':member_number' => (string) $member['member_number']],
            $input
        );

        return [
            'view'   => 'member',
            'q'      => $query,
            'member' => $member,
        ] + $paging;
    }

    /**
     * Members matching what somebody typed: a member number, or part of a
     * name. Capped, because this box is a way of finding one person and not a
     * second roster screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findMembers(string $query, int $limit = 25): array
    {
        $like = '%' . RosterPage::escapeLike($query) . '%';

        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.purged_at, m.dropped_since_import_id, t.name AS team_name, d.name AS division_name'
            . ' FROM member m'
            . ' LEFT JOIN team t ON t.id = m.team_id'
            . ' LEFT JOIN division d ON d.id = m.division_id'
            . ' WHERE m.is_system = 0 AND ('
            . "   m.member_number = :exact"
            . "   OR m.first_name LIKE :first ESCAPE '\\\\'"
            . "   OR m.last_name LIKE :last ESCAPE '\\\\'"
            . "   OR m.preferred_name LIKE :preferred ESCAPE '\\\\'"
            . "   OR m.member_number LIKE :number ESCAPE '\\\\'"
            . ' ) ORDER BY m.last_name, m.first_name, m.id LIMIT ' . max(1, $limit)
        );
        $read->execute([
            ':exact'     => $query,
            ':first'     => $like,
            ':last'      => $like,
            ':preferred' => $like,
            ':number'    => $like,
        ]);

        $matches = [];
        foreach ($read->fetchAll() as $row) {
            $matches[] = [
                'id'            => (int) $row['id'],
                'member_number' => (string) $row['member_number'],
                'name'          => RosterPage::displayName(
                    (string) $row['preferred_name'],
                    (string) $row['first_name'],
                    (string) $row['last_name'],
                    (string) $row['member_number']
                ),
                'team_name'     => (string) ($row['team_name'] ?? ''),
                'division_name' => (string) ($row['division_name'] ?? ''),
                'purged'        => $row['purged_at'] !== null,
                'dropped'       => $row['dropped_since_import_id'] !== null,
            ];
        }

        // An exact member number wins outright. Somebody who typed a number
        // typed the natural key, and a substring match on another number
        // should not make them choose between it and itself.
        foreach ($matches as $match) {
            if ($match['member_number'] === $query) {
                return [$match];
            }
        }

        return $matches;
    }

    // -----------------------------------------------------------------------
    // The rows, and the paging both views share
    // -----------------------------------------------------------------------

    /**
     * One page of change rows under whatever predicate the caller built.
     *
     * Newest first, always, and the batch travels with every row: a change
     * with no import beside it is a fact with no cause, which is the state
     * this whole table exists to end.
     *
     * @param array<string, mixed> $bind
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function rows(string $predicate, array $bind, array $input): array
    {
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM import_change c WHERE {$predicate}");
        $count->execute($bind);
        $total = (int) $count->fetchColumn();

        $size   = $this->pageSize;
        $pages  = max(1, (int) ceil($total / $size));
        $page   = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $size;

        $read = $this->pdo->prepare(
            'SELECT c.id, c.import_batch_id, c.member_id, c.member_number, c.kind, c.field,'
            . ' c.before_value, c.after_value, c.occurred_at,'
            . ' b.filename, b.mode, b.applied_at, b.failed_at,'
            . ' m.first_name, m.last_name, m.preferred_name'
            . ' FROM import_change c'
            . ' INNER JOIN import_batch b ON b.id = c.import_batch_id'
            . ' LEFT JOIN member m ON m.id = c.member_id'
            . " WHERE {$predicate}"
            . " ORDER BY c.id DESC LIMIT {$size} OFFSET {$offset}"
        );
        $read->execute($bind);

        $rows = [];
        foreach ($read->fetchAll() as $row) {
            $rows[] = [
                'id'            => (int) $row['id'],
                'batch_id'      => (int) $row['import_batch_id'],
                'filename'      => (string) $row['filename'],
                'mode'          => (string) $row['mode'],
                'member_number' => (string) $row['member_number'],
                'name'          => $row['first_name'] === null
                    ? (string) $row['member_number']
                    : RosterPage::displayName(
                        (string) $row['preferred_name'],
                        (string) $row['first_name'],
                        (string) $row['last_name'],
                        (string) $row['member_number']
                    ),
                'kind'        => (string) $row['kind'],
                'kind_label'  => self::KINDS[(string) $row['kind']] ?? (string) $row['kind'],
                'field'       => (string) $row['field'],
                'field_label' => self::fieldLabel((string) $row['field']),
                'before'      => $row['before_value'] === null ? null : (string) $row['before_value'],
                'after'       => $row['after_value'] === null ? null : (string) $row['after_value'],
                'occurred_at' => (string) $row['occurred_at'],
            ];
        }

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'size'     => $size,
            'from_row' => $total === 0 ? 0 : $offset + 1,
            'to_row'   => $offset + count($rows),
        ];
    }

    /** @return array<string, mixed> */
    private static function emptyPaging(): array
    {
        return [
            'rows'     => [],
            'total'    => 0,
            'page'     => 1,
            'pages'    => 1,
            'size'     => 0,
            'from_row' => 0,
            'to_row'   => 0,
        ];
    }

    // -----------------------------------------------------------------------
    // Shared decoration
    // -----------------------------------------------------------------------

    /**
     * A batch row with its summary decoded and its actor named.
     *
     * The summary is the one written at stage time and kept ever since —
     * metric flips, new teams, warning counts, the first twenty dropped
     * member numbers. It is decoded HERE rather than in the view so a batch
     * whose JSON a future migration wrote badly renders as a batch with no
     * summary rather than taking the page down.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decorateBatch(array $row): array
    {
        $summary = json_decode((string) ($row['summary_json'] ?? ''), true);
        $summary = is_array($summary) ? $summary : [];

        $partial = is_array($summary['applied_before_failure'] ?? null)
            ? $summary['applied_before_failure']
            : [];

        return [
            'id'             => (int) $row['id'],
            'mode'           => (string) $row['mode'],
            'filename'       => (string) $row['filename'],
            'show_year'      => (string) $row['show_year_label'],
            'team_name'      => (string) ($row['team_name'] ?? ''),
            'rows_read'      => (int) $row['rows_read'],
            'rows_created'   => (int) $row['rows_created'],
            'rows_updated'   => (int) $row['rows_updated'],
            'rows_unchanged' => (int) $row['rows_unchanged'],
            'rows_dropped'   => (int) $row['rows_dropped'],
            'warnings'       => (int) $row['warnings_count'],
            'started_at'     => (string) $row['started_at'],
            'applied_at'     => $row['applied_at'] === null ? null : (string) $row['applied_at'],
            'failed_at'      => $row['failed_at'] === null ? null : (string) $row['failed_at'],
            'failure_reason' => (string) ($row['failure_reason'] ?? ''),
            'actor'          => $row['actor_number'] === null ? null : RosterPage::displayName(
                (string) $row['actor_preferred'],
                (string) $row['actor_first'],
                (string) $row['actor_last'],
                (string) $row['actor_number']
            ),

            'metric_flips'     => is_array($summary['metric_flips'] ?? null) ? $summary['metric_flips'] : [],
            'new_teams'        => is_array($summary['new_teams'] ?? null) ? $summary['new_teams'] : [],
            'warning_counts'   => is_array($summary['warning_counts'] ?? null) ? $summary['warning_counts'] : [],
            'dropped_examples' => is_array($summary['dropped_examples'] ?? null)
                ? $summary['dropped_examples']
                : [],
            'partial'          => $partial,
        ];
    }

    /**
     * How a stored field name reads on the screen.
     *
     * A metric is spelled by `Rerm\Roster\Metric`, so "Committee Dues" is the
     * same two words here as on every other screen; anything the map above
     * does not know renders as its own column name, which is legible enough
     * to act on and is never a blank cell.
     */
    public static function fieldLabel(string $field): string
    {
        if ($field === '') {
            return '';
        }

        if (str_starts_with($field, 'metric:')) {
            $metric = Metric::tryFrom(substr($field, 7));

            return $metric === null ? substr($field, 7) : $metric->label();
        }

        return self::FIELD_LABELS[$field] ?? $field;
    }
}
