<?php

declare(strict_types=1);

namespace Rerm\Import;

/**
 * The export's header row, matched BY NAME and never by position (spec 6.1).
 *
 * Rodeo Houston's file has 33 columns today. Position matching would work
 * until the day one is inserted, at which point every column after it reads
 * the value beside it — `Show Dues` becomes `Committee Dues`, addresses become
 * cities — and nothing throws. A roster imported that way looks plausible and
 * is entirely wrong, which is the failure mode this class exists to make
 * impossible.
 *
 * Matching is case-insensitive and ignores surrounding whitespace, because
 * `Primary Email `, `PRIMARY EMAIL` and `Primary Email` are the same column
 * and a spreadsheet exported twice will not spell them the same way twice.
 *
 * A file missing `Customer Number`, `Title` or `Subcommittee 1` is rejected
 * outright, listing the headers it did find — the one message that turns "the
 * import says my file is corrupt" into "ah, that is last year's export".
 */
final class HeaderMap
{
    // The 33 columns of the observed export (docs/data-findings.md 1). Named
    // constants so no header string is typed twice anywhere in the import, and
    // so a rename is one edit rather than a search.
    public const TITLE                = 'Title';
    public const CUSTOMER_NUMBER      = 'Customer Number';
    public const NAME                 = 'Name';
    public const FULL_NAME            = 'Full Name';
    public const PREFIX               = 'Prefix';
    public const FIRST_NAME           = 'First Name';
    public const LAST_NAME            = 'Last Name';
    public const PREFERRED_NAME       = 'Preferred Name';
    public const LEGAL_NAME_VERIFIED  = 'Legal Name Verified';
    public const SUBCOMMITTEE_1       = 'Subcommittee 1';
    public const SUBCOMMITTEE_2       = 'Subcommittee 2';
    public const SUBCOMMITTEE_3       = 'Subcommittee 3';
    public const ADDRESS              = 'Address';
    public const CITY                 = 'City';
    public const STATE                = 'State';
    public const ZIP                  = 'Zip';
    public const PHONE                = 'Primary Phone';
    public const PHONE_TYPE           = 'Primary Phone Type';
    public const EMAIL                = 'Primary Email';
    public const SHOW_DUES            = 'Show Dues';
    public const COMMITTEE_DUES       = 'Committee Dues';
    public const INDEMNITY            = 'Indemnity';
    public const BACKGROUND_CHECK     = 'Background Check Completed';
    public const HARASSMENT_TRAINING  = 'Harassment prevention training';
    public const ROOKIE               = 'Rookie';
    public const BADGE_RELEASED       = 'Badge Released';
    public const BADGE_RELEASED_DATE  = 'Badge Released Date';
    public const BADGE_ISSUE_DATE     = 'Badge Issue Date';
    public const BADGE_PICKUP_PERSON  = 'Badge Pickup Person';
    public const ELIGIBLE_SERVICE     = 'Eligible for Service History';
    public const ELIGIBILITY_UPDATED  = 'Eligibility Updated By';
    public const LTC_APPLIED          = 'LTC Applied';
    public const IN_OTHER_COMMITTEES  = 'In Other Committees';

    /**
     * Without these three there is no import to run.
     *
     * `Customer Number` is the natural key and nothing can be matched or
     * created without it. `Title` decides who gets an account. `Subcommittee 1`
     * is the team, which is the unit every roster screen is organised by. A
     * file lacking any of them is not a roster, and guessing is worse than
     * refusing.
     *
     * @var array<int, string>
     */
    public const REQUIRED = [
        self::CUSTOMER_NUMBER,
        self::TITLE,
        self::SUBCOMMITTEE_1,
    ];

    /**
     * Every column this import understands. `Subcommittee 2` is deliberately
     * absent: it is junk in the observed export (`Tba 9` x1898) and is not
     * imported, so it belongs on neither this list nor any warning.
     *
     * @var array<int, string>
     */
    public const KNOWN = [
        self::TITLE, self::CUSTOMER_NUMBER, self::NAME, self::FULL_NAME,
        self::PREFIX, self::FIRST_NAME, self::LAST_NAME, self::PREFERRED_NAME,
        self::LEGAL_NAME_VERIFIED, self::SUBCOMMITTEE_1, self::SUBCOMMITTEE_3,
        self::ADDRESS, self::CITY, self::STATE, self::ZIP,
        self::PHONE, self::PHONE_TYPE, self::EMAIL,
        self::SHOW_DUES, self::COMMITTEE_DUES, self::INDEMNITY,
        self::BACKGROUND_CHECK, self::HARASSMENT_TRAINING,
        self::ROOKIE, self::BADGE_RELEASED, self::BADGE_RELEASED_DATE,
        self::BADGE_ISSUE_DATE, self::BADGE_PICKUP_PERSON,
        self::ELIGIBLE_SERVICE, self::ELIGIBILITY_UPDATED, self::LTC_APPLIED,
        self::IN_OTHER_COMMITTEES,
    ];

    /**
     * @param array<string, int> $byKey     normalised header -> column index
     * @param array<int, string> $spelled   headers as the file spells them
     * @param array<int, string> $duplicate headers that appeared more than once
     */
    private function __construct(
        private readonly array $byKey,
        private readonly array $spelled,
        private readonly array $duplicate,
    ) {
    }

    /**
     * @param array<int, string> $headerRow the file's first row, as strings
     *
     * @throws ImportException when a required column is absent
     */
    public static function fromHeaderRow(array $headerRow): self
    {
        $byKey     = [];
        $spelled   = [];
        $duplicate = [];

        foreach ($headerRow as $index => $header) {
            $header = (string) $header;
            if (trim($header) === '') {
                continue;
            }

            $spelled[] = $header;
            $key       = self::key($header);

            // First occurrence wins. A file with the column twice is already
            // odd; silently reading the later one would make which value is
            // imported depend on column order, which is precisely the
            // dependency this class exists to remove.
            if (isset($byKey[$key])) {
                $duplicate[] = $header;
                continue;
            }

            $byKey[$key] = (int) $index;
        }

        $missing = [];
        foreach (self::REQUIRED as $required) {
            if (!isset($byKey[self::key($required)])) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new ImportException(sprintf(
                "This file is missing %s: %s.\n\nThe headers it does contain are:\n  %s\n\n"
                . 'Headers are matched by name, not by position, so column order does not '
                . 'matter — but these three have to be present under these names.',
                count($missing) === 1 ? 'a required column' : 'required columns',
                implode(', ', $missing),
                $spelled === [] ? '(none — the first row is empty)' : implode("\n  ", $spelled)
            ));
        }

        return new self($byKey, $spelled, $duplicate);
    }

    public function has(string $column): bool
    {
        return isset($this->byKey[self::key($column)]);
    }

    /** The file's column index for a known column, or null. */
    public function index(string $column): ?int
    {
        return $this->byKey[self::key($column)] ?? null;
    }

    /**
     * One cell, trimmed, or '' when the column is absent from this file.
     *
     * '' rather than null for an absent column so that a roster missing an
     * optional column imports with that field blank instead of failing — the
     * six dead columns (docs/data-findings.md 1) are exactly the ones a future
     * export is most likely to drop.
     *
     * @param array<int, string> $row
     */
    public function value(array $row, string $column): string
    {
        $index = $this->index($column);
        if ($index === null) {
            return '';
        }

        return trim((string) ($row[$index] ?? ''));
    }

    /**
     * Headers as the file spells them, in file order.
     *
     * @return array<int, string>
     */
    public function headers(): array
    {
        return $this->spelled;
    }

    /**
     * Headers that appeared more than once. The later ones are ignored.
     *
     * @return array<int, string>
     */
    public function duplicates(): array
    {
        return $this->duplicate;
    }

    /**
     * Headers this import does not read. Not an error — a file may carry extra
     * columns and the import is not the place to have an opinion about them.
     *
     * @return array<int, string>
     */
    public function unrecognised(): array
    {
        $known = [];
        foreach (self::KNOWN as $column) {
            $known[self::key($column)] = true;
        }
        // Junk, but a known column name rather than a surprise.
        $known[self::key(self::SUBCOMMITTEE_2)] = true;

        $extra = [];
        foreach ($this->spelled as $header) {
            if (!isset($known[self::key($header)])) {
                $extra[] = $header;
            }
        }

        return $extra;
    }

    /**
     * Columns this import reads that the file does not carry.
     *
     * @return array<int, string>
     */
    public function absent(): array
    {
        $absent = [];
        foreach (self::KNOWN as $column) {
            if (!$this->has($column)) {
                $absent[] = $column;
            }
        }

        return $absent;
    }

    /** Trimmed, whitespace-collapsed, case-folded — the matching key. */
    private static function key(string $header): string
    {
        // A BOM on the first header survives the CSV reader's own stripping
        // only if it was written mid-file, but a non-breaking space in a
        // hand-edited header is common and invisible.
        $header    = str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $header);
        $collapsed = preg_replace('/\s+/u', ' ', trim($header));

        return mb_strtolower($collapsed ?? trim($header), 'UTF-8');
    }
}
