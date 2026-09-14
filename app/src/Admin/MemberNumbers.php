<?php

declare(strict_types=1);

namespace Rerm\Admin;

/**
 * A pasted list of member numbers, read the way a person meant it (Phase
 * 12, spec-v2 §13.2).
 *
 * The box on Look Up Members asks for "comma-separated", and what arrives is
 * whatever the last place the numbers lived produced: a column copied out of
 * a spreadsheet (one per line), an email ("1234567, 2345678 and 3456789"),
 * a cell Excel formatted for them (`1,234,567` or `1234567.0` or
 * `1.234567E+6`), a list typed with the spaces in the wrong places. Every
 * one of those is a list of member numbers to the person who pasted it, and
 * refusing it with "expected commas" would make them re-type it by hand —
 * which is the job this screen exists to remove.
 *
 * So the reading is generous and the REPORT is exact. Nothing is silently
 * dropped: every token that was not read as a number is handed back under
 * `ignored`, every number that was reshaped is handed back as `read_as`
 * (typed => read), every repeat is counted, and everything past the cap is
 * listed rather than quietly cut. The screen prints all of it above the
 * table, so a person can see that "Customer Number" was a header and not a
 * member, and that `1234567.0` was looked up as `1234567`.
 *
 * What it never does is guess at a number it cannot read. A run of fourteen
 * digits is not split into two sevens — nobody here knows where the split
 * goes — it is looked up as typed, found nowhere, and printed with the hint
 * that two numbers may have run together. Leading zeros are kept: the
 * natural key is a string (CLAUDE.md), and `0123456` is not `123456`.
 *
 * Pure: no database, no session, no state. The lookup itself is
 * `Rerm\Admin\LookupPage`.
 */
final class MemberNumbers
{
    /**
     * How many are looked up at once. The Roster Change Form's picker draws
     * the same line at 300 (spec-v2 §2.4) and for the same reason: past it,
     * the page stops being a thing somebody reads and becomes an export,
     * and the export is a different screen with a different audit row.
     */
    public const MAX = 300;

    /** Input past this is not read at all — a whole spreadsheet pasted by mistake. */
    public const MAX_BYTES = 65536;

    /** How many ignored words the report names before it counts the rest. */
    public const IGNORED_SHOWN = 20;

    /**
     * Characters a token is trimmed of at either end: quotes in four
     * spellings, brackets, a hash or numero sign somebody typed before a
     * number, and the punctuation a sentence leaves behind.
     */
    private const TRIM = " \t\"'`()[]{}<>#:.!?*-_";

    /**
     * Full-width digits and separators, as a paste from some phones and
     * some spreadsheets spells them, mapped to the ASCII this table stores.
     *
     * @var array<string, string>
     */
    private const WIDE = [
        '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
        '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        '，' => ',', '、' => ',', '；' => ';', '　' => ' ',
        '“' => '"', '”' => '"', '‘' => "'", '’' => "'", '№' => '#',
    ];

    /**
     * @return array{
     *     numbers: array<int, string>,
     *     duplicates: array<string, int>,
     *     ignored: array<int, string>,
     *     read_as: array<string, string>,
     *     over: array<int, string>,
     *     given: int,
     *     truncated: bool
     * } `numbers` in the order first given, each once; `duplicates` the
     *   extra times a number was listed; `ignored` every token with no digit
     *   in it; `read_as` typed => what it was read as; `over` the numbers
     *   past MAX, not looked up; `given` how many distinct numbers were
     *   read in all; `truncated` whether the input was cut at MAX_BYTES
     */
    public static function parse(string $raw): array
    {
        $truncated = strlen($raw) > self::MAX_BYTES;
        if ($truncated) {
            $raw = substr($raw, 0, self::MAX_BYTES);
        }

        // A byte order mark from a saved file, non-breaking spaces from a
        // web page, and full-width characters from a phone are all spaces
        // or ASCII to the person who pasted them.
        $text = str_replace(["\u{FEFF}", "\u{00A0}", "\u{2007}", "\u{202F}"], ['', ' ', ' ', ' '], $raw);
        $text = strtr($text, self::WIDE);

        $numbers    = [];
        $seen       = [];
        $duplicates = [];
        $ignored    = [];
        $readAs     = [];

        // Whitespace, newlines, semicolons, pipes, slashes and tabs are
        // separators outright. A comma is NOT, yet: `1,234,567` is one
        // number wearing thousands separators, and only a token can say so.
        $tokens = preg_split('/[\s;|\/\\\\]+/u', $text) ?: [];

        foreach ($tokens as $token) {
            $token = trim($token, self::TRIM . ',');
            if ($token === '') {
                continue;
            }

            if (preg_match('/^\d{1,3}(?:,\d{3}){1,3}$/', $token) === 1) {
                // Excel's General format with separators, as copied.
                $fragments = [[$token, str_replace(',', '', $token)]];
            } else {
                $fragments = array_map(
                    static fn (string $f): array => [$f, $f],
                    explode(',', $token)
                );
            }

            foreach ($fragments as [$typed, $fragment]) {
                $typed    = trim($typed, self::TRIM);
                $fragment = trim($fragment, self::TRIM);
                if ($fragment === '') {
                    continue;
                }

                $read = self::unformat($fragment);

                if (preg_match('/\d/', $read) !== 1) {
                    // "and", "Customer Number", "N/A": a word, not a number.
                    // Listed so the person sees it was read and set aside.
                    $ignored[] = mb_substr($typed, 0, 40);

                    continue;
                }

                $read = mb_substr($read, 0, 32);
                if ($read !== $typed) {
                    $readAs[$typed] = $read;
                }

                // Case-insensitive, as the column's collation is: the same
                // number in two spellings is one member.
                $key = mb_strtoupper($read);
                if (isset($seen[$key])) {
                    $duplicates[$seen[$key]] = ($duplicates[$seen[$key]] ?? 0) + 1;

                    continue;
                }

                $seen[$key] = $read;
                $numbers[]  = $read;
            }
        }

        $over = array_slice($numbers, self::MAX);

        return [
            'numbers'    => array_slice($numbers, 0, self::MAX),
            'duplicates' => $duplicates,
            'ignored'    => $ignored,
            'read_as'    => $readAs,
            'over'       => $over,
            'given'      => count($numbers),
            'truncated'  => $truncated,
        ];
    }

    /**
     * The two shapes a spreadsheet gives a number that is not a number to
     * it: `1234567.0` (a General cell holding a float, the exact artefact
     * `Spreadsheet::open()` refuses coming the other way) and `1.234567E+6`
     * (the same cell, narrower). Both come back as the digits they meant.
     * Anything else is returned as it came.
     */
    public static function unformat(string $fragment): string
    {
        if (preg_match('/^(\d+)\.0+$/', $fragment, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^(\d)(?:\.(\d+))?[eE]\+?(\d{1,2})$/', $fragment, $m) === 1) {
            $mantissa = $m[1] . ($m[2] ?? '');
            $exponent = (int) $m[3];
            $decimals = strlen($m[2] ?? '');

            // An integer only when the exponent reaches every fractional
            // digit; `1.2345678E+6` is 1234567.8, which is nobody's number.
            if ($exponent >= $decimals) {
                return $mantissa . str_repeat('0', $exponent - $decimals);
            }
        }

        return $fragment;
    }

    /**
     * Why a number the roster does not hold might have been typed that way,
     * in one short clause the screen prints beside it — or '' when nothing
     * about its shape suggests anything.
     */
    public static function hint(string $number): string
    {
        if (preg_match('/^\d{12,}$/', $number) === 1) {
            return 'two numbers run together?';
        }

        if (preg_match('/^\d{1,3}$/', $number) === 1) {
            return 'too short — part of a longer number?';
        }

        if (preg_match('/^\d+$/', $number) !== 1) {
            return 'not all digits';
        }

        return '';
    }
}
