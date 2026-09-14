<?php

declare(strict_types=1);

namespace Rerm\Admin;

use PDO;
use Rerm\App;
use Rerm\Auth\User;
use Rerm\Forms\RcfTracking;
use Rerm\Import\ImportHistory;
use Rerm\Roster\RosterPage;

/**
 * Look Up Members (Phase 12, spec-v2 §13) — a pasted list of member numbers,
 * answered with where each one stands today.
 *
 * The question it exists for arrives as a list: Rodeo Houston's membership
 * office writes back about a dozen people, a Division Chairman forwards an
 * email naming eight, somebody has a column in a spreadsheet — and for each
 * of them an Admin wants the same five things without opening five screens
 * per person. Who are they and where are they filed (the member row); when
 * did we first see them (`first_imported_at`, and the `created` row if the
 * record reaches back that far); what did the last import change about them
 * (`import_change`, §3); and was a Roster Change Form ever made that named
 * them, and by whom (`rcf_row`, §12).
 *
 * One reader, four queries for the whole list however long it is, and the
 * rows come back IN THE ORDER GIVEN: the list was pasted from somewhere,
 * and a table in the same order can be read across against it. A number the
 * roster does not hold is handed back as such, with the one thing its shape
 * suggests, never silently dropped.
 *
 * Admin only, through `Capability::LookUpMembers`, so there is no
 * `ScopedQuery` here — the same decision as Import History and for the same
 * reason: the member being asked about is the one whose team is not known.
 *
 * Read-only. Nothing here writes, and nothing here ever should: it answers a
 * question, and the screens that change things are the ones already named.
 */
final class LookupPage
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RcfTracking $tracking,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db(), RcfTracking::fromApp($app));
    }

    /**
     * Everything the screen needs for what was typed.
     *
     * @return array<string, mixed> the parser's report (`numbers`,
     *   `duplicates`, `ignored`, `read_as`, `over`, `given`, `truncated`)
     *   plus `rows` (found members, order given), `unknown` (numbers the
     *   roster does not hold, each with a hint), `found` and `ignored_more`
     */
    public function lookUp(User $user, string $raw): array
    {
        $parsed = MemberNumbers::parse($raw);

        $members = $this->members($parsed['numbers']);

        // A number typed without its leading zeros (Phase 12): the natural
        // key is a string and `0123456` is not `123456`, but a person
        // reading it off a screen that dropped the zero meant the member
        // who has it. One member matching that way is taken and said so;
        // two would be a guess, and neither is taken.
        $missing = [];
        foreach ($parsed['numbers'] as $number) {
            if (!isset($members[mb_strtoupper($number)])) {
                $missing[] = $number;
            }
        }
        foreach ($this->byStrippedZeros($missing) as $typed => $row) {
            $row['typed']                   = (string) $typed;
            $members[mb_strtoupper((string) $typed)] = $row;
        }

        $found = [];
        $rows  = [];
        foreach ($parsed['numbers'] as $number) {
            $row = $members[mb_strtoupper($number)] ?? null;
            if ($row === null) {
                continue;
            }
            $found[] = $row;
        }

        $history = $this->history(array_map(static fn (array $r): string => $r['member_number'], $found));
        $forms   = $this->forms($user, $found);

        foreach ($found as $row) {
            $number = $row['member_number'];
            $rows[] = $row
                + ($history[$number] ?? self::noHistory())
                + ['rcfs' => $forms[$number] ?? []];
        }

        $unknown = [];
        foreach ($parsed['numbers'] as $number) {
            if (!isset($members[mb_strtoupper($number)])) {
                $unknown[] = ['number' => $number, 'hint' => MemberNumbers::hint($number)];
            }
        }

        $ignored = $parsed['ignored'];

        return $parsed + [
            'rows'         => $rows,
            'unknown'      => $unknown,
            'found'        => count($rows),
            'ignored'      => array_slice($ignored, 0, MemberNumbers::IGNORED_SHOWN),
            'ignored_more' => max(0, count($ignored) - MemberNumbers::IGNORED_SHOWN),
        ];
    }

    // -----------------------------------------------------------------------
    // The member rows
    // -----------------------------------------------------------------------

    /**
     * The member row for each number the roster holds, keyed by the number
     * in upper case — the column's collation is case-insensitive, so the
     * key is too. System rows are not members and are not returned, the
     * same line Import History draws.
     *
     * @param array<int, string> $numbers
     * @return array<string, array<string, mixed>>
     */
    private function members(array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }

        $places = [];
        $bind   = [];
        foreach (array_values($numbers) as $i => $number) {
            $places[]       = ":n{$i}";
            $bind[":n{$i}"] = $number;
        }

        $read = $this->pdo->prepare(
            $this->memberSelect() . ' WHERE m.is_system = 0 AND m.member_number IN (' . implode(', ', $places) . ')'
        );
        $read->execute($bind);

        $members = [];
        foreach ($read->fetchAll() as $row) {
            $members[mb_strtoupper((string) $row['member_number'])] = self::memberRow($row);
        }

        return $members;
    }

    /**
     * Members whose number, with its leading zeros removed, equals a typed
     * digits-only number — exactly one each, keyed by what was typed.
     *
     * @param array<int, string> $typed
     * @return array<string, array<string, mixed>>
     */
    private function byStrippedZeros(array $typed): array
    {
        $digits = array_values(array_filter(
            $typed,
            static fn (string $n): bool => preg_match('/^[1-9]\d*$/', $n) === 1
        ));
        if ($digits === []) {
            return [];
        }

        $places = [];
        $bind   = [];
        foreach ($digits as $i => $number) {
            $places[]       = ":z{$i}";
            $bind[":z{$i}"] = $number;
        }

        $read = $this->pdo->prepare(
            $this->memberSelect()
            . " WHERE m.is_system = 0 AND m.member_number LIKE '0%'"
            . " AND TRIM(LEADING '0' FROM m.member_number) IN (" . implode(', ', $places) . ')'
        );
        $read->execute($bind);

        $matches = [];
        foreach ($read->fetchAll() as $row) {
            $stripped = ltrim((string) $row['member_number'], '0');
            $matches[$stripped][] = self::memberRow($row);
        }

        $out = [];
        foreach ($matches as $stripped => $rows) {
            if (count($rows) === 1) {
                $out[(string) $stripped] = $rows[0];
            }
        }

        return $out;
    }

    /** The columns every member read here needs, with the two placements named. */
    private function memberSelect(): string
    {
        return 'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name, m.title,'
            . ' m.first_imported_at, m.purged_at, m.dropped_since_import_id,'
            . ' t.name AS team_name, d.name AS division_name, d.is_placeholder,'
            . ' db.applied_at AS dropped_at, db.filename AS dropped_filename'
            . ' FROM member m'
            . ' LEFT JOIN team t ON t.id = m.team_id'
            . ' INNER JOIN division d ON d.id = m.division_id'
            . ' LEFT JOIN import_batch db ON db.id = m.dropped_since_import_id';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function memberRow(array $row): array
    {
        $number = (string) $row['member_number'];

        return [
            'id'            => (int) $row['id'],
            'member_number' => $number,
            'typed'         => $number,
            'name'          => RosterPage::displayName(
                (string) $row['preferred_name'],
                (string) $row['first_name'],
                (string) $row['last_name'],
                $number
            ),
            'title'         => (string) $row['title'],
            'team_name'     => (string) ($row['team_name'] ?? ''),
            'division_name' => (string) $row['division_name'],
            'placeholder'   => (int) $row['is_placeholder'] === 1,
            // Purged wins over dropped: a purge is deliberate and only
            // Restore undoes it, where the next import undoes a drop
            // (CLAUDE.md). The two are never one word.
            'state'         => $row['purged_at'] !== null
                ? 'purged'
                : ($row['dropped_since_import_id'] !== null ? 'dropped' : 'present'),
            'purged_at'     => $row['purged_at'] === null ? null : (string) $row['purged_at'],
            'dropped_batch' => $row['dropped_since_import_id'] === null ? null : (int) $row['dropped_since_import_id'],
            'dropped_at'    => $row['dropped_at'] === null ? null : (string) $row['dropped_at'],
            'first_seen'    => $row['first_imported_at'] === null ? null : (string) $row['first_imported_at'],
        ];
    }

    // -----------------------------------------------------------------------
    // What the imports recorded
    // -----------------------------------------------------------------------

    /**
     * For each member number: how many changes the record holds, the import
     * that first recorded them, and everything the LAST import that touched
     * them changed — the rows of one batch, because one file can move a
     * member's team and title at once and "last change" means the file,
     * not one cell of it.
     *
     * Two queries for the whole list: one grouped, one for the latest
     * batch's rows through a derived table. Matched by number, which every
     * `import_change` row carries whether or not its member id resolved
     * (010), and which is also what was typed.
     *
     * @param array<int, string> $numbers
     * @return array<string, array<string, mixed>>
     */
    private function history(array $numbers): array
    {
        $numbers = array_values(array_unique(array_filter($numbers, static fn (string $n): bool => $n !== '')));
        if ($numbers === []) {
            return [];
        }

        // A named placeholder cannot be reused within one statement, so the
        // derived table and the outer WHERE each get their own set.
        $outer = [];
        $inner = [];
        $bind  = [];
        foreach ($numbers as $i => $number) {
            $outer[]        = ":o{$i}";
            $inner[]        = ":i{$i}";
            $bind[":o{$i}"] = $number;
            $bind[":i{$i}"] = $number;
        }
        $outerIn = '(' . implode(', ', $outer) . ')';
        $innerIn = '(' . implode(', ', $inner) . ')';

        $totals = $this->pdo->prepare(
            'SELECT c.member_number, COUNT(*) AS changes,'
            . " MIN(CASE WHEN c.kind = 'created' THEN c.import_batch_id END) AS created_batch,"
            . " MIN(CASE WHEN c.kind = 'created' THEN c.occurred_at END) AS created_at"
            . " FROM import_change c WHERE c.member_number IN {$outerIn} GROUP BY c.member_number"
        );
        $totals->execute(array_filter($bind, static fn (string $k): bool => str_starts_with($k, ':o'), ARRAY_FILTER_USE_KEY));

        $history = [];
        foreach ($totals->fetchAll() as $row) {
            $history[(string) $row['member_number']] = [
                'changes_total' => (int) $row['changes'],
                'first_batch'   => $row['created_batch'] === null ? null : (int) $row['created_batch'],
                'first_at'      => $row['created_at'] === null ? null : (string) $row['created_at'],
                'last_change'   => null,
            ];
        }

        if ($history === []) {
            return [];
        }

        $latest = $this->pdo->prepare(
            'SELECT c.member_number, c.kind, c.field, c.before_value, c.after_value, c.occurred_at,'
            . ' c.import_batch_id, b.filename'
            . ' FROM import_change c'
            . ' INNER JOIN ('
            . '   SELECT member_number, MAX(import_batch_id) AS last_batch'
            . "   FROM import_change WHERE member_number IN {$innerIn} GROUP BY member_number"
            . ' ) latest ON latest.member_number = c.member_number AND latest.last_batch = c.import_batch_id'
            . ' INNER JOIN import_batch b ON b.id = c.import_batch_id'
            . " WHERE c.member_number IN {$outerIn}"
            . ' ORDER BY c.member_number, c.id'
        );
        $latest->execute($bind);

        foreach ($latest->fetchAll() as $row) {
            $number = (string) $row['member_number'];
            $kind   = (string) $row['kind'];
            $field  = (string) $row['field'];

            $entry = &$history[$number]['last_change'];
            $entry ??= [
                'at'       => (string) $row['occurred_at'],
                'batch'    => (int) $row['import_batch_id'],
                'filename' => (string) $row['filename'],
                'appeared' => false,
                'items'    => [],
            ];

            if ($kind === 'created') {
                // The last import to touch them is the one that first
                // recorded them: nothing has changed since they appeared.
                $entry['appeared'] = true;
            } else {
                $entry['items'][] = [
                    'kind'        => $kind,
                    'kind_label'  => ImportHistory::KINDS[$kind] ?? $kind,
                    'field'       => $field,
                    'field_label' => ImportHistory::fieldLabel($field),
                    'before'      => $row['before_value'] === null ? null : (string) $row['before_value'],
                    'after'       => $row['after_value'] === null ? null : (string) $row['after_value'],
                ];
            }
            unset($entry);
        }

        return $history;
    }

    /** @return array<string, mixed> */
    private static function noHistory(): array
    {
        return ['changes_total' => 0, 'first_batch' => null, 'first_at' => null, 'last_change' => null];
    }

    // -----------------------------------------------------------------------
    // What the forms recorded
    // -----------------------------------------------------------------------

    /**
     * Every Roster Change Form line naming any of the found members, grouped
     * by member number, newest form first — through the tracking reader, so
     * each line carries what the member card's list carries: the change,
     * who generated the form, the RCF number, the two dates and whether the
     * roster shows it.
     *
     * @param array<int, array<string, mixed>> $found
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function forms(User $user, array $found): array
    {
        if ($found === []) {
            return [];
        }

        $byId = [];
        foreach ($found as $row) {
            $byId[$row['id']] = $row['member_number'];
        }

        $lines = $this->tracking->forMembers(
            $user,
            array_keys($byId),
            array_values($byId)
        );

        $forms = [];
        foreach ($lines as $line) {
            $number = $line['member_id'] !== null && isset($byId[$line['member_id']])
                ? $byId[$line['member_id']]
                : (string) $line['member_number'];
            $forms[$number][] = $line;
        }

        return $forms;
    }
}
