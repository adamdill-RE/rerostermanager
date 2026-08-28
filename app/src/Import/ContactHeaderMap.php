<?php

declare(strict_types=1);

namespace Rerm\Import;

/**
 * The header row of a contact history file, matched by name and by ALIAS.
 *
 * The difference from `HeaderMap` is the whole reason this is a separate
 * class. That one reads a file Rodeo Houston generates: 33 columns, spelled
 * the same way every time, and a file that does not spell them that way is
 * last year's export and should be rejected rather than guessed at.
 *
 * This one reads a file an officer kept by hand. Nobody agreed a header row
 * with them in advance, the column is called `Date` or `Contact Date` or
 * `When`, and rejecting it over the difference means somebody retypes eighty
 * rows. So each field carries a list of spellings, first match wins, and the
 * preview says in plain words which column it read for which field — because
 * the failure this creates, and `HeaderMap` does not, is reading the wrong
 * column and saying nothing.
 *
 * Matching is otherwise identical: case-insensitive, whitespace-collapsed,
 * position-independent. A file may carry any number of extra columns and this
 * class has no opinion about them.
 *
 * ONE column is required — the one naming the member. Everything else has a
 * defensible default: no date means the row cannot be placed in time and is
 * skipped one at a time rather than failing the file, no type means `call`,
 * no officer means the batch default, and no notes means no notes.
 */
final class ContactHeaderMap
{
    /**
     * Who the contact was with, by the natural key. Preferred over a name
     * wherever the file has it — 1,954 of 1,954 unique, against 1,951 names.
     */
    public const MEMBER_NUMBER = 'member_number';

    /** Who the contact was with, by name. Resolved WITHIN the batch's team. */
    public const MEMBER_NAME = 'member_name';

    /** Split-name files: both halves, joined before matching. */
    public const FIRST_NAME = 'first_name';
    public const LAST_NAME  = 'last_name';

    /** When it happened. The reason this import exists. */
    public const OCCURRED_AT = 'occurred_at';

    /** call / text / email / in person / other, spelled loosely. */
    public const CONTACT_TYPE = 'contact_type';

    /** What was said. */
    public const NOTES = 'notes';

    /** Which officer made it — overriding the batch default, per row. */
    public const OFFICER = 'officer';

    /**
     * Field => the spellings that mean it, in preference order.
     *
     * Deliberately short lists of things people actually write, not every
     * string that could conceivably mean the field. A greedy alias list is
     * how `Follow Up Date` silently becomes the contact date.
     *
     * @var array<string, array<int, string>>
     */
    public const ALIASES = [
        self::MEMBER_NUMBER => [
            'Customer Number', 'Member Number', 'Member #', 'Member No',
            'Customer #', 'Number', 'Member Id', 'Member ID',
        ],
        self::MEMBER_NAME => [
            'Member', 'Member Name', 'Name', 'Full Name', 'Contact',
            'Contact Name', 'Person',
        ],
        self::FIRST_NAME => ['First Name', 'First', 'Given Name'],
        self::LAST_NAME  => ['Last Name', 'Last', 'Surname'],
        self::OCCURRED_AT => [
            'Date', 'Contact Date', 'Date Contacted', 'Occurred At',
            'Occurred', 'When', 'Contacted On', 'Date of Contact',
        ],
        self::CONTACT_TYPE => [
            'Type', 'Contact Type', 'Method', 'Contact Method', 'How',
            'Channel',
        ],
        self::NOTES => [
            'Notes', 'Note', 'Comment', 'Comments', 'Detail', 'Details',
            'Summary', 'Result', 'Outcome',
        ],
        self::OFFICER => [
            'Contacted By', 'Officer', 'Logged By', 'By', 'Caller',
            'Made By', 'Contacted by Officer',
        ],
    ];

    /**
     * @param array<string, int> $byField   field => column index
     * @param array<string, string> $spelledFor field => the header it matched
     * @param array<int, string> $spelled   headers as the file spells them
     */
    private function __construct(
        private readonly array $byField,
        private readonly array $spelledFor,
        private readonly array $spelled,
    ) {
    }

    /**
     * @param array<int, string> $headerRow the file's first row, as strings
     *
     * @throws ImportException when no column names the member
     */
    public static function fromHeaderRow(array $headerRow): self
    {
        $byKey   = [];
        $spelled = [];

        foreach ($headerRow as $index => $header) {
            $header = (string) $header;
            if (trim($header) === '') {
                continue;
            }

            $spelled[] = $header;
            $key       = self::key($header);

            // First occurrence wins, as in HeaderMap and for the same reason:
            // otherwise which column is read depends on column order.
            if (!isset($byKey[$key])) {
                $byKey[$key] = (int) $index;
            }
        }

        $byField    = [];
        $spelledFor = [];
        $taken      = [];

        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $key = self::key($alias);
                if (!isset($byKey[$key]) || isset($taken[$key])) {
                    continue;
                }

                // A column answers ONE field. Without this, a file with only
                // `Name` would offer it to member_name and then again to
                // nothing else — harmless — but a file with `Contact` would
                // hand the same column to the member and, through a longer
                // alias list, to something else too. One column, one meaning.
                $byField[$field]    = $byKey[$key];
                $spelledFor[$field] = self::spellingOf($headerRow, $byKey[$key]);
                $taken[$key]        = true;
                break;
            }
        }

        $namesMember = isset($byField[self::MEMBER_NUMBER])
            || isset($byField[self::MEMBER_NAME])
            || isset($byField[self::LAST_NAME]);

        if (!$namesMember) {
            throw new ImportException(sprintf(
                "No column in this file names the member the contact was with.\n\n"
                . "The headers it does contain are:\n  %s\n\n"
                . "One of these is needed (any spelling from the list):\n"
                . "  member number — %s\n"
                . "  or a name      — %s\n"
                . "  or a last name — %s\n\n"
                . 'Column order does not matter and extra columns are ignored.',
                $spelled === [] ? '(none — the first row is empty)' : implode("\n  ", $spelled),
                implode(', ', self::ALIASES[self::MEMBER_NUMBER]),
                implode(', ', self::ALIASES[self::MEMBER_NAME]),
                implode(', ', self::ALIASES[self::LAST_NAME])
            ));
        }

        return new self($byField, $spelledFor, $spelled);
    }

    public function has(string $field): bool
    {
        return isset($this->byField[$field]);
    }

    /**
     * One cell, trimmed, or '' when this file has no column for the field.
     *
     * @param array<int, string> $row
     */
    public function value(array $row, string $field): string
    {
        $index = $this->byField[$field] ?? null;
        if ($index === null) {
            return '';
        }

        return trim((string) ($row[$index] ?? ''));
    }

    /**
     * The member's name as this file gives it: a single name column, or first
     * and last joined, whichever it carries. Empty when it carries neither.
     *
     * @param array<int, string> $row
     */
    public function memberName(array $row): string
    {
        $single = $this->value($row, self::MEMBER_NAME);
        if ($single !== '') {
            return $single;
        }

        $first = $this->value($row, self::FIRST_NAME);
        $last  = $this->value($row, self::LAST_NAME);

        return trim($first . ' ' . $last);
    }

    /**
     * Which header was read for which field — the preview's first table, and
     * the answer to "is it reading the right column".
     *
     * @return array<string, string>
     */
    public function mapping(): array
    {
        return $this->spelledFor;
    }

    /**
     * Headers this import did not use. Not an error, and shown rather than
     * hidden: a file with a `Date` column the import ignored because it also
     * had `Contact Date` is worth one line on the preview.
     *
     * @return array<int, string>
     */
    public function unused(): array
    {
        $used = [];
        foreach ($this->spelledFor as $header) {
            $used[self::key($header)] = true;
        }

        $extra = [];
        foreach ($this->spelled as $header) {
            if (!isset($used[self::key($header)])) {
                $extra[] = $header;
            }
        }

        return $extra;
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

    /** @param array<int, string> $headerRow */
    private static function spellingOf(array $headerRow, int $index): string
    {
        return trim((string) ($headerRow[$index] ?? ''));
    }

    /** Trimmed, whitespace-collapsed, case-folded — the matching key. */
    private static function key(string $header): string
    {
        $header    = str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $header);
        $collapsed = preg_replace('/\s+/u', ' ', trim($header));

        return mb_strtolower($collapsed ?? trim($header), 'UTF-8');
    }
}
