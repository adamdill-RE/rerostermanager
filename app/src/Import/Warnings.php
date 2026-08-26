<?php

declare(strict_types=1);

namespace Rerm\Import;

use PDO;

/**
 * Import warnings (spec 6.4): never fatal, always listed, always attributed to
 * a row number and — where there is one — a member number.
 *
 * Two things make this a class rather than an array. The first is grouping: a
 * complete import of the real export produces 72 `no_division` and 116
 * `non_cell_phone` rows, and a single `duplicate_member_number` buried in that
 * list is the one that matters. The preview therefore shows counts by kind,
 * expandable to rows, and the counts are kept here as they accumulate rather
 * than recounted from 1,954 rows afterwards.
 *
 * The second is memory. Warnings are flushed to `import_warning` in batches as
 * they are raised, so a pathological file cannot build a 200,000-entry array
 * inside a 128M limit.
 */
final class Warnings
{
    // ---------------------------------------------------------------------
    // The ten kinds of spec 6.4, in the order the specification lists them.
    // ---------------------------------------------------------------------

    /** Title not in the spec 4.2 map — imported as Member, never as an officer. */
    public const UNKNOWN_TITLE = 'unknown_title';

    /** `Subcommittee 3` blank — 72 rows in the sample; lands in `(No Division)`. */
    public const NO_DIVISION = 'no_division';

    /** Blank email — the member cannot recover a password. 1 row in the sample. */
    public const NO_EMAIL = 'no_email';

    /** Phone type is not `CELL PHONE` — no text link. 116 rows in the sample. */
    public const NON_CELL_PHONE = 'non_cell_phone';

    /** Address already used by a different member number. 2 pairs in the sample. */
    public const SHARED_EMAIL = 'shared_email';

    /** Team seen under a different division. 7 teams in the sample. */
    public const TEAM_DIVISION_CONFLICT = 'team_division_conflict';

    /** Same member number twice in one file. The later row is skipped. */
    public const DUPLICATE_MEMBER_NUMBER = 'duplicate_member_number';

    /** Team mode, and the row belongs to another team. The row is skipped. */
    public const WRONG_TEAM = 'wrong_team';

    /** Team name not previously seen — created by the apply. */
    public const NEW_TEAM = 'new_team';

    /** Cannot normalise to E.164 — imported as display text only, no tel: link. */
    public const UNPARSEABLE_PHONE = 'unparseable_phone';

    /**
     * The ten of spec 6.4, and exactly those. tests/import_test.php asserts
     * every one of them can be made to fire.
     *
     * @var array<int, string>
     */
    public const SPEC_KINDS = [
        self::UNKNOWN_TITLE,
        self::NO_DIVISION,
        self::NO_EMAIL,
        self::NON_CELL_PHONE,
        self::SHARED_EMAIL,
        self::TEAM_DIVISION_CONFLICT,
        self::DUPLICATE_MEMBER_NUMBER,
        self::WRONG_TEAM,
        self::NEW_TEAM,
        self::UNPARSEABLE_PHONE,
    ];

    // ---------------------------------------------------------------------
    // Three kinds spec 6.4 does not name, each for a row the import would
    // otherwise have to drop in silence. They are listed separately so the ten
    // above stay recognisably the specification's list.
    // ---------------------------------------------------------------------

    /**
     * No `Customer Number` on the row. Skipped — there is no natural key, so
     * the row cannot be matched to a member or created as one, and inventing a
     * key would produce a duplicate person on the next import.
     */
    public const MISSING_MEMBER_NUMBER = 'missing_member_number';

    /**
     * A metric cell holding something other than `Y`, `N` or blank. Imported
     * as `unknown`. Spec 6.1 asks for this in as many words: sentinel
     * normalisation is deliberately NOT applied to the metric columns, "where
     * only Y and N are meaningful and anything else deserves a warning".
     */
    public const UNEXPECTED_METRIC_VALUE = 'unexpected_metric_value';

    /**
     * The row's member number belongs to a row this application created rather
     * than one Rodeo Houston sent (`member.is_system`). Skipped: the master
     * administrator is not on the committee and an import never creates,
     * updates, absents or purges one. It cannot happen with the seeded number,
     * which is deliberately outside the export's observed range — but a
     * collision that silently rewrote the only account able to sign in is not
     * a failure worth leaving to arithmetic.
     */
    public const SYSTEM_MEMBER_NUMBER = 'system_member_number';

    /**
     * Update mode found a member the roster does not have. Ignored, and
     * reported: spec 6.2 says "ignore, warn" in as many words. An update
     * import refreshes metrics and contact details on people already known;
     * creating from one would let a partial file quietly add members nobody
     * reviewed.
     */
    public const NOT_IN_ROSTER = 'not_in_roster';

    /** @var array<int, string> */
    public const EXTRA_KINDS = [
        self::MISSING_MEMBER_NUMBER,
        self::UNEXPECTED_METRIC_VALUE,
        self::SYSTEM_MEMBER_NUMBER,
        self::NOT_IN_ROSTER,
    ];

    /** Every kind this import can raise. @var array<int, string> */
    public const KINDS = [
        self::UNKNOWN_TITLE,
        self::NO_DIVISION,
        self::NO_EMAIL,
        self::NON_CELL_PHONE,
        self::SHARED_EMAIL,
        self::TEAM_DIVISION_CONFLICT,
        self::DUPLICATE_MEMBER_NUMBER,
        self::WRONG_TEAM,
        self::NEW_TEAM,
        self::UNPARSEABLE_PHONE,
        self::MISSING_MEMBER_NUMBER,
        self::UNEXPECTED_METRIC_VALUE,
        self::SYSTEM_MEMBER_NUMBER,
        self::NOT_IN_ROSTER,
    ];

    /** What the preview calls each kind, and what it means for the Admin. */
    public static function headline(string $kind): string
    {
        return match ($kind) {
            self::UNKNOWN_TITLE           => 'Title not recognised — imported as Member, with no login',
            self::NO_DIVISION             => 'No division on the row — placed in (No Division)',
            self::NO_EMAIL                => 'No email address — this member cannot recover a password',
            self::NON_CELL_PHONE          => 'Phone is not a cell — no text link will be offered',
            self::SHARED_EMAIL            => 'Email address shared with another member',
            self::TEAM_DIVISION_CONFLICT  => 'Team appears under more than one division',
            self::DUPLICATE_MEMBER_NUMBER => 'Member number appears twice in this file — the later row was skipped',
            self::WRONG_TEAM              => 'Row belongs to a different team — skipped, not retargeted',
            self::NEW_TEAM                => 'Team name not seen before — it will be created',
            self::UNPARSEABLE_PHONE       => 'Phone number could not be normalised — no call or text link',
            self::MISSING_MEMBER_NUMBER   => 'No Customer Number on the row — skipped, there is no key to match on',
            self::UNEXPECTED_METRIC_VALUE => 'Metric cell is neither Y nor N nor blank — imported as Not reported',
            self::SYSTEM_MEMBER_NUMBER    => 'Member number belongs to an application account — skipped',
            self::NOT_IN_ROSTER           => 'Not in the roster — an update import never creates a member',
            default                       => $kind,
        };
    }

    /**
     * Ordered for the preview: the kinds that changed or dropped something
     * first, the merely informational ones last. A 72-row `no_division` list
     * must not push a single `duplicate_member_number` off the top of the page.
     *
     * @var array<int, string>
     */
    private const SEVERITY = [
        self::MISSING_MEMBER_NUMBER,
        self::DUPLICATE_MEMBER_NUMBER,
        self::SYSTEM_MEMBER_NUMBER,
        self::WRONG_TEAM,
        self::NOT_IN_ROSTER,
        self::UNKNOWN_TITLE,
        self::TEAM_DIVISION_CONFLICT,
        self::UNEXPECTED_METRIC_VALUE,
        self::UNPARSEABLE_PHONE,
        self::SHARED_EMAIL,
        self::NEW_TEAM,
        self::NO_EMAIL,
        self::NO_DIVISION,
        self::NON_CELL_PHONE,
    ];

    /** @var array<int, array{int, ?string, string, string}> row, number, kind, detail */
    private array $pending = [];

    /** @var array<string, int> */
    private array $counts = [];

    private int $total = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $batchId,
        private readonly int $flushEvery = 500,
    ) {
    }

    public function add(string $kind, int $rowNumber, ?string $memberNumber, string $detail = ''): void
    {
        $this->pending[] = [$rowNumber, $memberNumber, $kind, $detail];
        $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;
        $this->total++;

        if (count($this->pending) >= $this->flushEvery) {
            $this->flush();
        }
    }

    /** Writes everything buffered. Safe to call with nothing pending. */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $values = [];
        $bind   = [];
        foreach ($this->pending as $i => [$rowNumber, $memberNumber, $kind, $detail]) {
            // A named placeholder cannot be reused within one statement —
            // emulated prepares are off — so every occurrence gets its own
            // name rather than a shared one.
            $values[] = "(:b{$i}, :r{$i}, :m{$i}, :k{$i}, :d{$i})";
            $bind[":b{$i}"] = $this->batchId;
            $bind[":r{$i}"] = $rowNumber;
            $bind[":m{$i}"] = $memberNumber;
            $bind[":k{$i}"] = $kind;
            $bind[":d{$i}"] = mb_substr($detail, 0, 500);
        }

        $statement = $this->pdo->prepare(
            // `row_number` needs its backticks: it is a reserved word (the window
            // function) in both MySQL 8.0 and MariaDB 10.11, and unquoted it is a
            // syntax error rather than a column not found.
            'INSERT INTO `import_warning` (`import_batch_id`, `row_number`, `member_number`, `kind`, `detail`) VALUES '
            . implode(', ', $values)
        );
        $statement->execute($bind);

        $this->pending = [];
    }

    public function total(): int
    {
        return $this->total;
    }

    /** @return array<string, int> kind => count, in severity order */
    public function counts(): array
    {
        $ordered = [];
        foreach (self::SEVERITY as $kind) {
            if (isset($this->counts[$kind])) {
                $ordered[$kind] = $this->counts[$kind];
            }
        }
        // A kind added to the class but not to SEVERITY still gets reported.
        foreach ($this->counts as $kind => $count) {
            $ordered[$kind] ??= $count;
        }

        return $ordered;
    }

    /**
     * Counts by kind for a batch already written, in severity order.
     *
     * @return array<string, int>
     */
    public static function countsFor(PDO $pdo, int $batchId): array
    {
        $read = $pdo->prepare(
            'SELECT kind, COUNT(*) AS n FROM import_warning WHERE import_batch_id = :batch GROUP BY kind'
        );
        $read->execute([':batch' => $batchId]);

        $counts = [];
        foreach ($read->fetchAll() as $row) {
            $counts[(string) $row['kind']] = (int) $row['n'];
        }

        $ordered = [];
        foreach (self::SEVERITY as $kind) {
            if (isset($counts[$kind])) {
                $ordered[$kind] = $counts[$kind];
            }
        }
        foreach ($counts as $kind => $count) {
            $ordered[$kind] ??= $count;
        }

        return $ordered;
    }

    /**
     * The rows behind one kind, capped — the preview expands a kind on demand
     * and nobody reads 1,954 of them.
     *
     * @return array<int, array{row_number: int, member_number: ?string, detail: string}>
     */
    public static function rowsFor(PDO $pdo, int $batchId, string $kind, int $limit = 50): array
    {
        $read = $pdo->prepare(
            'SELECT `row_number`, `member_number`, `detail` FROM `import_warning` '
            . 'WHERE `import_batch_id` = :batch AND `kind` = :kind ORDER BY `row_number` LIMIT ' . max(1, $limit)
        );
        $read->execute([':batch' => $batchId, ':kind' => $kind]);

        $rows = [];
        foreach ($read->fetchAll() as $row) {
            $rows[] = [
                'row_number'    => (int) $row['row_number'],
                'member_number' => $row['member_number'] === null ? null : (string) $row['member_number'],
                'detail'        => (string) $row['detail'],
            ];
        }

        return $rows;
    }
}
