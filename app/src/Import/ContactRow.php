<?php

declare(strict_types=1);

namespace Rerm\Import;

use DateTimeImmutable;
use DateTimeZone;
use Rerm\Roster\LogContact;

/**
 * The pure half of the contact history import: turning what a spreadsheet
 * cell says into a date and a contact type, with no database anywhere near it.
 *
 * It is separate from `ContactImporter` because these two questions — "is
 * `10/14/25` the fourteenth of October or the tenth of the fourteenth month"
 * and "does `vm` mean a call" — are where a history load is most likely to be
 * quietly wrong, and a static method taking a string and returning a string is
 * a thing a test can enumerate a hundred cases of in a second without a MySQL
 * anywhere. tests/contact_import_test.php does exactly that.
 */
final class ContactRow
{
    /**
     * US order, because the file comes from a Houston committee and every
     * date in the sample export is written that way. `03/04/2026` is the
     * fourth of March.
     *
     * ISO is tried FIRST regardless, because `2026-03-04` is unambiguous and
     * a spreadsheet that writes it means it — and because both spreadsheet
     * readers in this application hand back date cells already formatted that
     * way (XlsReader::serialToDate, XlsxReader::numericOrDate), so this is
     * the shape most real files arrive in.
     *
     * @var array<int, string>
     */
    /**
     * Below this, a four-digit-year format has almost certainly eaten a
     * two-digit year. Generous by decades against the thing it is protecting:
     * this application tracks a contact history that began in 2025.
     */
    private const MIN_YEAR = 1990;

    private const FORMATS = [
        // Unambiguous, and what a date-formatted cell becomes.
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d',
        // Typed by a person, US order.
        'n/j/Y H:i:s', 'n/j/Y H:i', 'n/j/Y', 'n/j/y',
        'n-j-Y H:i', 'n-j-Y', 'n-j-y',
        'n.j.Y', 'n.j.y',
        // Spelled month, either order.
        'j M Y', 'j M y', 'j F Y', 'M j Y', 'M j, Y', 'F j Y', 'F j, Y',
        'j-M-Y', 'j-M-y', 'd-M-Y',
    ];

    /**
     * What a person writes in a `Type` column, and which of
     * `LogContact::TYPES` it means.
     *
     * `vm` and `voicemail` are calls: a voicemail is a call that was not
     * answered, the officer did the work, and the alternative is eleven rows
     * landing as `other` and reading as though nobody knows what happened.
     *
     * @var array<string, string>
     */
    private const TYPE_WORDS = [
        'call' => 'call', 'called' => 'call', 'phone' => 'call',
        'phone call' => 'call', 'telephone' => 'call', 'rang' => 'call',
        'vm' => 'call', 'voicemail' => 'call', 'voice mail' => 'call',
        'left voicemail' => 'call', 'left vm' => 'call', 'lvm' => 'call',
        'left message' => 'call', 'c' => 'call',

        'text' => 'text', 'texted' => 'text', 'sms' => 'text',
        'text message' => 'text', 'txt' => 'text', 'message' => 'text',
        't' => 'text',

        'email' => 'email', 'e-mail' => 'email', 'emailed' => 'email',
        'mail' => 'email', 'e' => 'email',

        'in person' => 'in_person', 'in-person' => 'in_person',
        'in_person' => 'in_person', 'inperson' => 'in_person',
        'person' => 'in_person', 'face to face' => 'in_person',
        'f2f' => 'in_person', 'met' => 'in_person', 'meeting' => 'in_person',
        'visit' => 'in_person', 'p' => 'in_person',

        'other' => 'other', 'o' => 'other',
    ];

    /**
     * A cell to a UTC `Y-m-d H:i:s`, or null when it is not a date.
     *
     * A date with no time becomes noon LOCAL, not midnight, and that is a
     * decision rather than an accident. Midnight Chicago is 05:00 or 06:00
     * UTC the same day — fine — but midnight UTC is the previous EVENING in
     * Chicago, so a contact logged as the 14th would display as the 13th on
     * every screen in this application. Noon is far enough from both
     * boundaries that no rounding, no daylight-saving shift and no display
     * conversion can move the date.
     *
     * $timezone is the application's display zone (`America/Chicago`), passed
     * in rather than read from config here so this class stays pure.
     */
    public static function parseDate(string $raw, string $timezone = 'America/Chicago'): ?string
    {
        $raw = self::tidy($raw);
        if ($raw === '') {
            return null;
        }

        $zone = self::zone($timezone);

        foreach (self::FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $raw, $zone);
            if ($parsed === false) {
                continue;
            }

            // createFromFormat is forgiving: '13/45/2026' parses as a date in
            // 2027 rather than failing. Re-formatting and comparing is what
            // turns "it did not throw" into "it is the date on the page".
            if (!self::roundTrips($parsed, $format, $raw, $zone)) {
                continue;
            }

            // `3/4/26` matches `n/j/Y` before it ever reaches `n/j/y`, and
            // year 26 round-trips perfectly — it is a real, parseable date in
            // the reign of Tiberius. The formats cannot distinguish the two
            // readings; plausibility can, and the four-digit reading is the
            // wrong one for every string this rules out.
            if ((int) $parsed->format('Y') < self::MIN_YEAR) {
                continue;
            }

            // The '!' resets unspecified fields to the epoch, so a
            // time-bearing format that matched keeps its time and a date-only
            // one reads midnight — which is the case noon is for.
            if (!self::carriesTime($format)) {
                $parsed = $parsed->setTime(12, 0, 0);
            }

            return $parsed
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        return null;
    }

    /**
     * A cell to one of `LogContact::TYPES`, or null when the word is not one
     * this application knows.
     *
     * Null rather than a fallback to `call`, deliberately. A row saying
     * `Facebook` is a row about a channel this application does not model,
     * and recording it as a phone call would put a fact in the permanent
     * record that nobody asserted. The importer's answer is to keep the row,
     * land it as `other`, and say so on the preview — which is a decision the
     * Admin can see and this function should not make silently.
     */
    public static function parseType(string $raw): ?string
    {
        $word = mb_strtolower(self::tidy($raw), 'UTF-8');
        if ($word === '') {
            return null;
        }

        // Punctuation people put in a type column: "call - no answer",
        // "text/sms", "email (bounced)". The leading word is the type and the
        // rest is a note about it.
        $word = trim($word, " \t.:;,-–—/()[]");

        if (isset(self::TYPE_WORDS[$word])) {
            $type = self::TYPE_WORDS[$word];

            return in_array($type, LogContact::TYPES, true) ? $type : null;
        }

        // "called and left a voicemail" — longest phrase first, so
        // 'left voicemail' is preferred over 'call' when both appear.
        static $byLength = null;
        if ($byLength === null) {
            $byLength = self::TYPE_WORDS;
            uksort($byLength, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        }

        foreach ($byLength as $needle => $type) {
            // Whole words only: 'e' must not match inside 'never reached'.
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $word) === 1) {
                return in_array($type, LogContact::TYPES, true) ? $type : null;
            }
        }

        return null;
    }

    /** Collapses whitespace and strips the characters a paste drags in. */
    private static function tidy(string $raw): string
    {
        $raw = str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $raw);
        $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;

        return trim($raw);
    }

    private static function zone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * Did the parse actually consume the string, or did it invent a date from
     * a rollover? Compared on the parts the format names, so `n/j/y` matching
     * `3/4/26` is accepted while `13/45/2026` is not.
     */
    private static function roundTrips(
        DateTimeImmutable $parsed,
        string $format,
        string $raw,
        DateTimeZone $zone
    ): bool {
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return false;
        }

        // A two-digit year is the one case where re-formatting cannot prove
        // anything: PHP resolves '26' to 2026 and formatting it back gives
        // '26' whatever the century. Accepting it is the point of the format.
        $reformatted = $parsed->setTimezone($zone)->format($format);

        return self::comparable($reformatted) === self::comparable($raw);
    }

    /**
     * Case, padding and punctuation insensitive, for the round-trip
     * comparison only.
     *
     * Leading zeros go because `03/04/2026` and `3/4/2026` are the same date
     * written twice, and only one of them is what `n/j/Y` formats back. The
     * alternative is a second padded format beside every unpadded one, which
     * doubles a list whose length is already the risk.
     */
    private static function comparable(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\b0+(\d)/', '$1', $value) ?? $value;

        return str_replace([',', '.'], '', $value);
    }

    private static function carriesTime(string $format): bool
    {
        return str_contains($format, 'H') || str_contains($format, 'G')
            || str_contains($format, 'h') || str_contains($format, 'g');
    }
}
