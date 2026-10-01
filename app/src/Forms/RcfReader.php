<?php

declare(strict_types=1);

namespace Rerm\Forms;

use Rerm\Admin\MemberNumbers;
use Rerm\Import\ContactRow;
use Rerm\Roster\Spreadsheet;
use Throwable;

/**
 * Reads a Roster Change Form somebody filled in by hand (Phase 13, spec-v2
 * §14.3) — the Rodeo Houston template, as it actually comes back by email.
 *
 * `RosterChangeForm` WRITES the template, cell by cell, from a transcription
 * of the workbook; this is the other direction, and it deliberately does
 * not read cell by cell. A form that has been through three Vice Chairmen's
 * copies of Excel has had a column widened, a row deleted above the grid, a
 * second sheet added, the numbers typed as numbers and the tick boxes typed
 * as "Yes". So:
 *
 *   * **the file is read by its bytes, not its name** (`Spreadsheet::open`,
 *     spec-v1 §6.1) — `.xls`, `.xlsx`, and the same grid saved as CSV;
 *   * **the columns are found by their labels**: the row holding `MEMBER
 *     NAME` and `HLS&R NO` is the header, on whichever sheet carries it, and
 *     the ten entry columns are wherever `*TYPE`, `ROOKIE`, `CHANGE/ADD
 *     TITLE`, `PREVIOUS TITLE`, `WAIT LIST`, `REMOVE REASON`, `NEW
 *     SUB-COMMITTEE` and `INTERVIEW REQUIRED or SPONSORED BY` sit in that row
 *     or the two under it (the template splits them across three);
 *   * **the entry rows are the numbered ones** — `1)` to `25)` in the first
 *     column, at the position printed — and, without a numbering column,
 *     the twenty-five rows after the header block;
 *   * **the header cells are the ones after their labels**: the submitter
 *     after "Name & Title of whom is submitting this form:", the date after
 *     "Date:", the sub-committee after "Sub-Committee:", the Division
 *     Chairman's number after "CHANGE FORM #", the show year from
 *     "RODEO 2027" in the title.
 *
 * WHAT A PERSON TYPES IS READ AS THEY MEANT IT, AND EVERY RESHAPING IS
 * REPORTED. `s&t` is `S & T`; `4) Member Resigned` is reason `4`; `Yes` ticks
 * a box; Excel's `1234567.0` is the member number it meant, through the same
 * code Look Up Members uses (`MemberNumbers::unformat`, §13.2); a date typed
 * as `3/1/2027` or `March 1, 2027`, or stored as a real date cell, is the day
 * it says, through the contact import's own parser. Each of those lands in
 * `warnings` as `read_as`, so the preview can show "type 's&t' read as
 * 'S & T'" beside the line.
 *
 * WHAT IT CANNOT READ IT KEEPS AND SAYS, NEVER GUESSES. A type that is none
 * of the five codes, or a reason that is none of the six, is kept AS TYPED
 * and flagged — the form is a record of what was asked, and a line silently
 * corrected to a code its author did not write is a different request. An
 * unreadable date leaves the form undated and says so. A file with no header
 * labels on any sheet, or no filled line, is refused by name
 * (`RcfReadException`) and never staged.
 *
 * Pure: no database, no session, no state. What the stage then resolves —
 * members, teams, the show year, duplicates — is `RcfUpload`'s business.
 */
final class RcfReader
{
    /**
     * The kinds of thing the reader has to say about a form, in the order
     * the preview lists them. The two `unknown_*` kinds are the ones most
     * worth a look: the line is kept as typed and will never read as
     * "in the roster" until somebody who knows what was meant fixes it.
     */
    public const UNKNOWN_TYPE   = 'unknown_type';
    public const UNKNOWN_REASON = 'unknown_reason';
    public const NO_TYPE        = 'no_type';
    public const NO_NAME        = 'no_name';
    public const NO_NUMBER      = 'no_number';
    public const NUMBER_UNREAD  = 'number_unread';
    public const TICK_UNREAD    = 'tick_unread';
    public const NO_DATE        = 'no_date';
    public const DATE_UNREAD    = 'date_unread';
    public const NO_SUBMITTER   = 'no_submitter';
    public const NO_SUBCOMMITTEE = 'no_subcommittee';
    public const COLUMN_MISSING = 'column_missing';
    public const READ_AS        = 'read_as';
    public const SERIAL_FOUND   = 'serial_found';

    /** @var array<int, string> */
    public const KINDS = [
        self::UNKNOWN_TYPE, self::UNKNOWN_REASON, self::NO_TYPE, self::NO_NAME, self::NO_NUMBER,
        self::NUMBER_UNREAD, self::TICK_UNREAD, self::NO_DATE, self::DATE_UNREAD,
        self::NO_SUBMITTER, self::NO_SUBCOMMITTEE, self::COLUMN_MISSING, self::READ_AS, self::SERIAL_FOUND,
    ];

    /**
     * Where each entry field sits, by the words over the column — matched
     * against the normalised text of the header row and the two under it,
     * joined, so `CHANGE/ADD` over `TITLE` reads as one label. Longest
     * phrase first within a field; fields in this order, each taking the
     * first column still free that carries one of its phrases.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMN_LABELS = [
        'member_name'      => ['MEMBER NAME', 'NAME'],
        'member_number'    => ['HLS R NO', 'HLSR NO', 'HLS R NUMBER', 'HLSR NUMBER', 'MEMBER NUMBER', 'MEMBER NO', 'CUSTOMER NUMBER'],
        'type'             => ['TYPE'],
        'rookie'           => ['ROOKIE'],
        'new_title'        => ['CHANGE ADD TITLE', 'CHANGE ADD', 'NEW TITLE'],
        'previous_title'   => ['PREVIOUS TITLE', 'PREVIOUS'],
        'wait_list'        => ['WAIT LIST', 'WAITLIST'],
        'remove_reason'    => ['REMOVE REASON', 'REASON'],
        'new_subcommittee' => ['NEW SUB COMMITTEE', 'NEW TEAM', 'SUB COMMITTEE'],
        'sponsor'          => ['INTERVIEW REQUIRED OR SPONSORED BY', 'SPONSORED BY', 'SPONSOR'],
    ];

    /** The header row is the one carrying the member name label and a number label. */
    private const NAME_LABELS   = ['MEMBER NAME'];
    private const NUMBER_LABELS = ['HLS R NO', 'HLSR NO', 'HLS R NUMBER', 'HLSR NUMBER', 'MEMBER NUMBER', 'MEMBER NO', 'CUSTOMER NUMBER'];

    /** How many rows under the header row carry the rest of the labels. */
    private const HEADER_DEPTH = 2;

    /**
     * What a person writes in a `*TYPE` cell, with everything but letters
     * removed and upper-cased, and the code they meant.
     *
     * @var array<string, string>
     */
    private const TYPE_WORDS = [
        'A' => 'A', 'ADD' => 'A', 'ADDITION' => 'A', 'NEW' => 'A', 'NEWMEMBER' => 'A',
        'R' => 'R', 'REMOVE' => 'R', 'REMOVAL' => 'R', 'DELETE' => 'R', 'DROP' => 'R', 'RESIGNED' => 'R',
        'T' => 'T', 'TITLE' => 'T', 'TITLECHANGE' => 'T',
        'S' => 'S', 'SUB' => 'S', 'SUBCOMMITTEE' => 'S', 'SUBCOMMITTEECHANGE' => 'S',
        'TEAM' => 'S', 'TEAMCHANGE' => 'S', 'TRANSFER' => 'S', 'SUBCOMMITTEECHANGETEAMCHANGE' => 'S',
        'ST' => 'S & T', 'TS' => 'S & T', 'SANDT' => 'S & T', 'TANDS' => 'S & T',
        'SUBCOMMITTEECHANGETEAMCHANGETITLECHANGE' => 'S & T', 'SUBCOMMITTEECHANGETITLECHANGE' => 'S & T',
        'TEAMCHANGETITLECHANGE' => 'S & T', 'TEAMANDTITLE' => 'S & T', 'TEAMTITLE' => 'S & T',
    ];

    /** Cell contents that tick a box, upper-cased and trimmed. */
    private const TICKED   = ['1', 'TRUE', 'Y', 'YES', 'X', '√', '✓', '✔', '☑', 'T', 'CHECKED'];
    /** And that leave it clear. */
    private const UNTICKED = ['', '0', 'FALSE', 'N', 'NO', 'F', '☐', '-', '—', 'NONE', 'UNCHECKED'];

    /**
     * Reads one file.
     *
     * @return array{
     *     format: string, sheet: string, columns: array<string, ?int>, rows: int,
     *     form: array{year: string, submitter: string, date: string, subcommittee: string,
     *         entries: array<int, array<string, string>>, form_date: string,
     *         submitter_number: string, division_id: ?int, team_id: ?int},
     *     serial: string, raw_date: string, title: string,
     *     warnings: array<int, array{row: ?int, kind: string, detail: string}>
     * }
     *
     * @throws RcfReadException when the file is not a Roster Change Form
     */
    public static function read(string $path): array
    {
        try {
            $reader = Spreadsheet::open($path);
            $format = Spreadsheet::detect($path);
            $sheets = $reader->sheets();
        } catch (Throwable $e) {
            throw new RcfReadException(
                'This file could not be opened as a spreadsheet: ' . $e->getMessage()
            );
        }

        // The first sheet carrying the form's header wins. A workbook can
        // hold a cover sheet, a notes sheet or last year's form beside this
        // year's; the one with the grid is the form.
        $tried = [];
        foreach ($sheets as $sheetName) {
            try {
                $rows = [];
                foreach ($reader->rows($sheetName) as $row) {
                    $rows[] = $row;
                }
            } catch (Throwable $e) {
                $tried[] = $sheetName . ' (' . $e->getMessage() . ')';
                continue;
            }

            $header = self::findHeader($rows);
            if ($header === null) {
                $tried[] = $sheetName;
                continue;
            }

            return self::parse($rows, $header, $format, $sheetName);
        }

        throw new RcfReadException(sprintf(
            'This is not a Roster Change Form: no sheet carries the form\'s column headers '
            . '(MEMBER NAME and HLS&R NO over the grid). %s',
            $format === 'csv'
                ? 'It is not an Excel workbook either — it was read as plain text, and no form was in it.'
                : 'Looked at: ' . ($tried === [] ? 'no sheets at all' : implode(', ', $tried)) . '.'
        ));
    }

    // -----------------------------------------------------------------------
    // Finding the grid
    // -----------------------------------------------------------------------

    /**
     * The index of the row carrying the entry-column headers, or null.
     *
     * @param array<int, array<int, string>> $rows
     */
    private static function findHeader(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            $hasName = false;
            $hasNo   = false;
            foreach ($row as $cell) {
                $label = self::label($cell);
                if ($label === '') {
                    continue;
                }
                if (in_array($label, self::NAME_LABELS, true)) {
                    $hasName = true;
                }
                if (in_array($label, self::NUMBER_LABELS, true)) {
                    $hasNo = true;
                }
            }
            if ($hasName && $hasNo) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Which column carries which entry field, read off the header block.
     *
     * @param array<int, array<int, string>> $rows
     * @return array<string, ?int> field => column index, null when no label was found
     */
    private static function columns(array $rows, int $header): array
    {
        // One label per column: the header row's words, then the words in
        // the two rows under it, so "CHANGE/ADD" + "TITLE" is one phrase.
        $labels = [];
        for ($r = $header; $r <= $header + self::HEADER_DEPTH; $r++) {
            foreach ($rows[$r] ?? [] as $column => $cell) {
                $word = self::label($cell);
                if ($word === '') {
                    continue;
                }
                $labels[$column] = trim(($labels[$column] ?? '') . ' ' . $word);
            }
        }
        ksort($labels);

        $columns = [];
        $taken   = [];
        foreach (self::COLUMN_LABELS as $field => $phrases) {
            $columns[$field] = null;
            foreach ($phrases as $phrase) {
                foreach ($labels as $column => $label) {
                    if (isset($taken[$column])) {
                        continue;
                    }
                    if (preg_match('/(?<![A-Z0-9])' . preg_quote($phrase, '/') . '(?![A-Z0-9])/', $label) === 1) {
                        $columns[$field] = $column;
                        $taken[$column]  = true;
                        continue 3;
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * Everything on the sheet, once the header is known.
     *
     * @param array<int, array<int, string>> $rows
     * @return array<string, mixed>
     */
    private static function parse(array $rows, int $header, string $format, string $sheetName): array
    {
        $warnings = [];
        $columns  = self::columns($rows, $header);

        foreach ($columns as $field => $column) {
            if ($column === null) {
                $warnings[] = self::warning(null, self::COLUMN_MISSING, sprintf(
                    'No column headed for %s was found over the grid, so every line reads blank there.',
                    self::fieldWord($field)
                ));
            }
        }

        // ----- The header cells, above the grid -----------------------------

        $title     = '';
        $year      = '';
        $serial    = '';
        $submitter = '';
        $rawDate   = '';
        $subcommittee = '';

        for ($r = 0; $r < $header; $r++) {
            $row = $rows[$r];
            foreach ($row as $column => $cell) {
                $text  = self::text($cell);
                $label = self::label($cell);
                if ($text === '') {
                    continue;
                }

                if ($title === '' && preg_match('/\bRODEO\s+(\d{4})\b/i', $text, $m) === 1) {
                    $title = $text;
                    $year  = $m[1];
                    continue;
                }

                if ($serial === '' && str_contains($label, 'CHANGE FORM')) {
                    // The number is after the # in the label's own cell, or in
                    // the next cell to the right — the box the template leaves
                    // beside "CHANGE FORM #".
                    $after = trim((string) preg_replace('/^.*#/su', '', $text));
                    if ($after === '' || $after === $text) {
                        $after = self::valueAfter($row, $column);
                    }
                    $serial = self::bound($after, RcfTracking::SERIAL_MAX);
                    continue;
                }

                if ($submitter === '' && (str_contains($label, 'SUBMITTING THIS FORM') || str_contains($label, 'NAME TITLE'))) {
                    $submitter = self::bound(self::valueAfter($row, $column), 255);
                    continue;
                }

                if ($rawDate === '' && $label === 'DATE') {
                    $rawDate = self::valueAfter($row, $column);
                    continue;
                }

                if ($subcommittee === '' && ($label === 'SUB COMMITTEE' || $label === 'SUBCOMMITTEE')) {
                    $subcommittee = self::bound(self::valueAfter($row, $column), 255);
                    continue;
                }
            }
        }

        if ($submitter === '') {
            $warnings[] = self::warning(null, self::NO_SUBMITTER, 'The form does not say who submitted it (the cell after "Name & Title of whom is submitting this form" is blank).');
        }
        if ($subcommittee === '') {
            $warnings[] = self::warning(null, self::NO_SUBCOMMITTEE, 'The form does not name its sub-committee (the cell after "Sub-Committee:" is blank).');
        }

        $formDate = '';
        if ($rawDate === '') {
            $warnings[] = self::warning(null, self::NO_DATE, 'The form carries no date, so the RCF date is left blank and the day it was kept stands in for it.');
        } else {
            $formDate = self::isoDate($rawDate) ?? '';
            if ($formDate === '') {
                $warnings[] = self::warning(null, self::DATE_UNREAD, sprintf(
                    'The date "%s" could not be read as a day, so the RCF date is left blank.',
                    self::bound($rawDate, 40)
                ));
            } elseif (self::american($formDate) !== trim($rawDate) && trim($rawDate) !== $formDate) {
                // A real date cell comes back as the ISO day, which is not a
                // reshaping; a typed "March 1, 2027" is, and is said.
                $warnings[] = self::warning(null, self::READ_AS, sprintf('Date "%s" read as %s.', self::bound($rawDate, 40), self::american($formDate)));
            }
        }

        if ($serial !== '') {
            $warnings[] = self::warning(null, self::SERIAL_FOUND, sprintf(
                'The form carries the Division Chairman\'s number %s in its CHANGE FORM # box; it will be written to every line.',
                $serial
            ));
        }

        // ----- The entry rows --------------------------------------------------

        $entries = [];
        for ($i = 0; $i < RosterChangeForm::ROWS; $i++) {
            $entries[$i] = array_replace(RosterChangeForm::emptyEntry(), [
                'rookie'    => RosterChangeForm::UNTICKED,
                'wait_list' => RosterChangeForm::UNTICKED,
            ]);
        }

        $filled = 0;
        foreach (self::entryRows($rows, $header) as $position => $row) {
            [$entry, $lineWarnings] = self::entry($row, $columns, $position);
            if ($entry === null) {
                continue;
            }
            $entries[$position - 1] = $entry;
            $filled++;
            foreach ($lineWarnings as $warning) {
                $warnings[] = $warning;
            }
        }

        if ($filled === 0) {
            throw new RcfReadException(
                'This Roster Change Form has nothing filled in: the header was found'
                . ($subcommittee === '' ? '' : ' (' . $subcommittee . ')')
                . ' but none of its twenty-five lines names anybody. There is no request to keep.'
            );
        }

        return [
            'format'   => $format,
            'sheet'    => $sheetName,
            'columns'  => $columns,
            'rows'     => $filled,
            'form'     => [
                'year'             => $year,
                'submitter'        => $submitter,
                'date'             => $formDate === '' ? '' : self::american($formDate),
                'subcommittee'     => $subcommittee,
                'entries'          => $entries,
                'form_date'        => $formDate,
                'submitter_number' => '',
                'division_id'      => null,
                'team_id'          => null,
            ],
            'serial'   => $serial,
            'raw_date' => $rawDate,
            'title'    => $title,
            'warnings' => $warnings,
        ];
    }

    /**
     * The entry rows, keyed by printed position (1–25).
     *
     * Numbered rows win: the rows under the header block whose first
     * column reads `7)` or `7`, at that position. A sheet with no such
     * numbering — somebody deleted the column — falls back to the
     * twenty-five rows after the block, in order.
     *
     * @param array<int, array<int, string>> $rows
     * @return array<int, array<int, string>>
     */
    private static function entryRows(array $rows, int $header): array
    {
        $start = $header + self::HEADER_DEPTH + 1;

        $numbered = [];
        for ($r = $start; $r < count($rows); $r++) {
            $first = self::text($rows[$r][0] ?? '');
            if (preg_match('/^(\d{1,2})\s*[).:]?$/', $first, $m) !== 1) {
                continue;
            }
            $position = (int) $m[1];
            if ($position < 1 || $position > RosterChangeForm::ROWS || isset($numbered[$position])) {
                continue;
            }
            $numbered[$position] = $rows[$r];
        }

        if ($numbered !== []) {
            ksort($numbered);

            return $numbered;
        }

        $sequential = [];
        $position   = 0;
        for ($r = $start; $r < count($rows) && $position < RosterChangeForm::ROWS; $r++) {
            $position++;
            $sequential[$position] = $rows[$r];
        }

        return $sequential;
    }

    /**
     * One entry row, read into the form's own shape — or null for a blank
     * line. Returns the entry and whatever had to be said about it.
     *
     * @param array<int, string>   $row
     * @param array<string, ?int>  $columns
     * @return array{0: ?array<string, string>, 1: array<int, array{row: ?int, kind: string, detail: string}>}
     */
    private static function entry(array $row, array $columns, int $position): array
    {
        $cell = static fn (string $field): string => $columns[$field] === null
            ? ''
            : self::text($row[$columns[$field]] ?? '');

        $raw = [];
        foreach (array_keys(RosterChangeForm::emptyEntry()) as $field) {
            $raw[$field] = $cell($field);
        }

        // Blank by the form's own rule: the tick boxes do not count, so a
        // row carrying nothing but an unticked (or ticked) box is still blank.
        if (RosterChangeForm::entryIsBlank($raw)) {
            return [null, []];
        }

        $warnings = [];
        $entry    = RosterChangeForm::emptyEntry();

        // The member: a number column and a name column, read forgivingly.
        // A name cell holding "Jane Sample - 1234567" with the number column
        // blank is read the way the generator's own picker reads it.
        $number = self::memberNumber($raw['member_number']);
        $name   = self::bound(self::collapse($raw['member_name']), 160);

        if ($raw['member_number'] !== '' && $number === '') {
            $warnings[] = self::warning($position, self::NUMBER_UNREAD, sprintf(
                'HLS&R NO "%s" has no digits in it, so no member number was read.',
                self::bound($raw['member_number'], 40)
            ));
        } elseif ($number !== '' && $number !== trim($raw['member_number'])) {
            $warnings[] = self::warning($position, self::READ_AS, sprintf(
                'HLS&R NO "%s" read as %s.',
                self::bound($raw['member_number'], 40),
                $number
            ));
        }

        if ($number === '' && $name !== '') {
            [$fromName, $nameOnly] = RcfPage::parseMember($name);
            if ($fromName !== '') {
                $number = self::memberNumber($fromName);
                $name   = $nameOnly;
                $warnings[] = self::warning($position, self::READ_AS, sprintf(
                    'MEMBER NAME "%s" read as %s, member number %s.',
                    self::bound($raw['member_name'], 60),
                    $name,
                    $number
                ));
            }
        }

        if ($name === '') {
            $warnings[] = self::warning($position, self::NO_NAME, 'No member name on this line'
                . ($number === '' ? ', and no member number either.' : '; the roster\'s name is used if the number is known.'));
        }
        if ($number === '') {
            $warnings[] = self::warning($position, self::NO_NUMBER, 'No member number on this line, so the roster cannot be watched for it'
                . ' — an addition typed in by name alone is like this until Rodeo Houston files them.');
        }

        $entry['member_number'] = $number;
        $entry['member_name']   = $name;

        // The code.
        [$type, $typeChanged] = self::type($raw['type']);
        if ($raw['type'] === '') {
            $warnings[] = self::warning($position, self::NO_TYPE, 'No *TYPE code on this line, so what it asks for is not known.');
        } elseif ($type === null) {
            $type = self::bound(self::collapse($raw['type']), 8);
            $warnings[] = self::warning($position, self::UNKNOWN_TYPE, sprintf(
                '*TYPE "%s" is none of %s; kept as typed, and the line cannot read as in the roster until it is one.',
                self::bound($raw['type'], 20),
                implode(', ', array_keys(RosterChangeForm::TYPES))
            ));
        } elseif ($typeChanged) {
            $warnings[] = self::warning($position, self::READ_AS, sprintf('*TYPE "%s" read as %s.', self::bound($raw['type'], 20), $type));
        }
        $entry['type'] = $type ?? '';

        // The reason.
        [$reason, $reasonChanged] = self::reason($raw['remove_reason']);
        if ($raw['remove_reason'] !== '' && $reason === null) {
            $reason = self::bound(self::collapse($raw['remove_reason']), 8);
            $warnings[] = self::warning($position, self::UNKNOWN_REASON, sprintf(
                'REMOVE REASON "%s" is none of 1 to 6; kept as typed.',
                self::bound($raw['remove_reason'], 40)
            ));
        } elseif ($reasonChanged) {
            $warnings[] = self::warning($position, self::READ_AS, sprintf(
                'REMOVE REASON "%s" read as %s (%s).',
                self::bound($raw['remove_reason'], 40),
                $reason,
                RosterChangeForm::REMOVE_REASONS[$reason] ?? ''
            ));
        }
        $entry['remove_reason'] = $reason ?? '';

        // The tick boxes.
        foreach (['rookie' => 'RE ROOKIE', 'wait_list' => 'WAIT LIST'] as $field => $word) {
            $tick = self::tick($raw[$field]);
            if ($tick === null) {
                $warnings[] = self::warning($position, self::TICK_UNREAD, sprintf(
                    '%s "%s" is neither a yes nor a no; read as not ticked.',
                    $word,
                    self::bound($raw[$field], 20)
                ));
                $tick = RosterChangeForm::UNTICKED;
            }
            $entry[$field] = $tick;
        }

        // Free text, as the generator bounds it.
        foreach (['new_title', 'previous_title', 'new_subcommittee', 'sponsor'] as $field) {
            $entry[$field] = self::bound(self::collapse($raw[$field]), 160);
        }

        return [$entry, $warnings];
    }

    // -----------------------------------------------------------------------
    // Reading one cell the way a person meant it
    // -----------------------------------------------------------------------

    /**
     * A `*TYPE` cell as one of the five codes: the code, and whether the
     * cell had to be reshaped to get there. Null for a word that is none of
     * them.
     *
     * @return array{0: ?string, 1: bool}
     */
    public static function type(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [null, false];
        }

        if (isset(RosterChangeForm::TYPES[$trimmed])) {
            return [$trimmed, false];
        }

        // "A = Addition", as the legend prints it, means A.
        $head = trim((string) strtok($trimmed, '='));

        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $head));
        if (isset(self::TYPE_WORDS[$letters])) {
            return [self::TYPE_WORDS[$letters], true];
        }

        // "Sub-Committee Change (Team Change) & Title Change" and friends:
        // the legend's own words, in any punctuation.
        foreach (RosterChangeForm::TYPES as $code => $description) {
            if ($letters === strtoupper((string) preg_replace('/[^A-Za-z]/', '', $description))) {
                return [$code, true];
            }
        }

        return [null, false];
    }

    /**
     * A `**REMOVE REASON` cell as one of the six numbers: `4`, `4)`, `4.`,
     * `4) Member Resigned`, `Member Resigned`. Null for anything else.
     *
     * @return array{0: ?string, 1: bool}
     */
    public static function reason(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [null, false];
        }

        if (isset(RosterChangeForm::REMOVE_REASONS[$trimmed])) {
            return [$trimmed, false];
        }

        if (preg_match('/^\s*(\d)(?:\.0+)?\s*[).:\-]?(?:\s|$)/u', $trimmed, $m) === 1
            && isset(RosterChangeForm::REMOVE_REASONS[$m[1]])
        ) {
            return [$m[1], true];
        }

        $words = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $trimmed));
        if ($words !== '') {
            foreach (RosterChangeForm::REMOVE_REASONS as $number => $description) {
                $bare = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $description));
                // "Leadership Recommendation" is enough for reason 3 without
                // the parenthetical; "Resigned" alone is not enough for 4.
                $short = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) preg_replace('/\(.*\)/', '', $description)));
                if ($words === $bare || $words === $short) {
                    return [(string) $number, true];
                }
            }
        }

        return [null, false];
    }

    /**
     * A checkbox cell: `'1'` ticked, `'0'` not, null for a word that is
     * neither — which the caller reads as not ticked and says so.
     */
    public static function tick(string $raw): ?string
    {
        $word = strtoupper(trim($raw));
        if (in_array($word, self::TICKED, true)) {
            return RosterChangeForm::TICKED;
        }
        if (in_array($word, self::UNTICKED, true)) {
            return RosterChangeForm::UNTICKED;
        }

        return null;
    }

    /**
     * An `HLS&R NO` cell as the member number it meant: Excel's `1234567.0`,
     * `1,234,567` and `1.234567E+6` are the digits (§13.2, through the same
     * code), spaces are nothing, letters are kept (a member number is
     * letters and digits, never arithmetic) and the case is the table's.
     * '' for a cell with no digit in it.
     */
    public static function memberNumber(string $raw): string
    {
        $value = str_replace(["\u{FEFF}", "\u{00A0}", ' ', "\t"], '', trim($raw));
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{1,3}(?:,\d{3}){1,3}$/', $value) === 1) {
            $value = str_replace(',', '', $value);
        }

        $value = MemberNumbers::unformat($value);
        $value = trim($value, " \t\"'#:.");

        if (preg_match('/\d/', $value) !== 1 || preg_match('/^[A-Za-z0-9-]+$/', $value) !== 1) {
            return '';
        }

        return mb_substr(strtoupper($value), 0, 32);
    }

    /**
     * A date cell — a real one comes back from the readers as `Y-m-d`, a
     * typed one as whatever was typed — as the ISO day it names, or null.
     * Through the contact import's parser, so `3/1/2027` is the first of
     * March here exactly as it is there.
     */
    public static function isoDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Pinned to UTC and read back as a day, so no zone can move it.
        $utc = ContactRow::parseDate($raw, 'UTC');

        return $utc === null ? null : substr($utc, 0, 10);
    }

    /** `YYYY-MM-DD` as `M/D/YYYY`, as the cell holds it and RcfPage writes it. */
    public static function american(string $isoDate): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate);

        return $parsed instanceof \DateTimeImmutable ? $parsed->format('n/j/Y') : '';
    }

    // -----------------------------------------------------------------------
    // Cells and labels
    // -----------------------------------------------------------------------

    /**
     * A cell's text, tidied: a byte order mark and non-breaking spaces gone,
     * trimmed. Not collapsed — a value keeps its inner spacing until the
     * field's own bound says otherwise.
     */
    private static function text(string $cell): string
    {
        return trim(str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $cell));
    }

    /**
     * A cell's text as a LABEL: upper case, everything but letters and
     * digits a single space, trimmed. `*TYPE` is `TYPE`, `HLS&R NO` is
     * `HLS R NO`, `**REMOVE REASON  ` is `REMOVE REASON`, `Sub-Committee:`
     * is `SUB COMMITTEE`.
     */
    private static function label(string $cell): string
    {
        $upper = mb_strtoupper(self::text($cell));
        $bare  = (string) preg_replace('/[^A-Z0-9]+/u', ' ', $upper);

        return trim($bare);
    }

    /**
     * The first cell to the right of a label that holds a value rather than
     * another label — "Date:" is followed by three empty cells, the date,
     * and then "Sub-Committee:", and an undated form must not read the
     * second label as its date.
     *
     * @param array<int, string> $row
     */
    private static function valueAfter(array $row, int $labelColumn): string
    {
        for ($c = $labelColumn + 1; $c < count($row); $c++) {
            $text = self::text($row[$c] ?? '');
            if ($text === '') {
                continue;
            }
            if (str_ends_with($text, ':')) {
                return '';
            }
            $label = self::label($text);
            if ($label === 'DATE' || $label === 'SUB COMMITTEE' || str_contains($label, 'DC USE ONLY')) {
                return '';
            }

            return $text;
        }

        return '';
    }

    /** Whitespace collapsed to one space, trimmed. */
    private static function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Bounded at a length, never refused. */
    private static function bound(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    /** The printed word for a field, for a warning. */
    private static function fieldWord(string $field): string
    {
        return match ($field) {
            'member_name'      => 'MEMBER NAME',
            'member_number'    => 'HLS&R NO',
            'type'             => '*TYPE',
            'rookie'           => 'RE ROOKIE',
            'new_title'        => 'CHANGE/ADD TITLE',
            'previous_title'   => 'PREVIOUS TITLE',
            'wait_list'        => 'WAIT LIST',
            'remove_reason'    => '**REMOVE REASON',
            'new_subcommittee' => 'NEW SUB-COMMITTEE (New Team)',
            'sponsor'          => 'INTERVIEW REQUIRED or SPONSORED BY',
            default            => $field,
        };
    }

    /** @return array{row: ?int, kind: string, detail: string} */
    private static function warning(?int $row, string $kind, string $detail): array
    {
        return ['row' => $row, 'kind' => $kind, 'detail' => $detail];
    }
}
