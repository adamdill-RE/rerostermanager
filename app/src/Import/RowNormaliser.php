<?php

declare(strict_types=1);

namespace Rerm\Import;

use Rerm\Auth\TitleMap;

/**
 * One export row, turned into the values the schema stores.
 *
 * Everything here is a pure function of a string, which is what the readers
 * hand back (`Rerm\Roster\SpreadsheetReader`) and what `Customer Number`
 * requires — it is a 6-7 digit identifier, and a reader or a normaliser that
 * took it through a float would turn 1234567 into 1234567.0.
 *
 * Three decisions live in this file and nowhere else.
 */
final class RowNormaliser
{
    /**
     * Text meaning "nothing" (spec 6.1, docs/data-findings.md 9.3).
     *
     * Six cells in the real export hold one of these in `Prefix` or
     * `Preferred Name`. A member whose preferred name is `N/A` must not be
     * greeted as "N/A Smith".
     *
     * @var array<int, string>
     */
    public const SENTINELS = ['N/A', 'NA', 'NONE', 'NULL', '-'];

    /**
     * And the ONLY two columns it is applied to.
     *
     * Deliberately not the metric columns: there, `Y` and `N` are the only
     * meaningful values and anything else deserves a warning rather than being
     * quietly discarded. Not the name columns either — a surname of `None` is
     * unlikely, but so is being renamed by an importer.
     *
     * @var array<int, string>
     */
    public const SENTINEL_COLUMNS = [HeaderMap::PREFIX, HeaderMap::PREFERRED_NAME];

    /** The one phone type that can receive a text (docs/data-findings.md 5). */
    public const CELL_PHONE = 'CELL PHONE';

    /**
     * The four scored metrics and the fifth that is not, mapped to the export
     * column each reads. Keys are the `member_metric.metric` ENUM.
     *
     * Harassment training is here because it is imported and displayed; it is
     * excluded from every completion percentage because 1,716 of 1,954 rows
     * are blank and blank is not N (spec, OI-3).
     *
     * @var array<string, string>
     */
    public const METRICS = [
        'hlsr_dues'           => HeaderMap::SHOW_DUES,
        'committee_dues'      => HeaderMap::COMMITTEE_DUES,
        'indemnity'           => HeaderMap::INDEMNITY,
        'background_check'    => HeaderMap::BACKGROUND_CHECK,
        'harassment_training' => HeaderMap::HARASSMENT_TRAINING,
    ];

    /** The four that enter a completion percentage. @var array<int, string> */
    public const SCORED_METRICS = ['hlsr_dues', 'committee_dues', 'indemnity', 'background_check'];

    /**
     * Column widths, so a long value is shortened rather than raising.
     *
     * The connection runs STRICT_ALL_TABLES, which is what stops a member
     * number being silently truncated to a different member. The cost is that
     * an over-long free-text field would abort the whole import instead of the
     * one field, so every text value is cut to its column here, where the
     * limit is visible beside the field it belongs to.
     *
     * `member_number` is NOT in this list on purpose: a key too long for its
     * column is a file that is not a roster, and shortening it would match or
     * create the wrong person. That row is skipped instead.
     *
     * @var array<string, int>
     */
    public const WIDTHS = [
        'first_name'                       => 64,
        'last_name'                        => 64,
        'preferred_name'                   => 64,
        'full_name'                        => 160,
        'prefix'                           => 32,
        'address'                          => 160,
        'city'                             => 96,
        'state'                            => 32,
        'zip'                              => 16,
        'phone'                            => 32,
        'phone_type'                       => 32,
        'email'                            => 255,
        'title'                            => 96,
        'badge_pickup_person'              => 160,
        'badge_released_date_raw'          => 32,
        'badge_issue_date_raw'             => 32,
        'eligible_for_service_history_raw' => 32,
        'eligibility_updated_by_raw'       => 128,
    ];

    /** As stored in `member.member_number`. */
    public const MEMBER_NUMBER_WIDTH = 32;

    /**
     * Sentinel text to ''. Applied only to the two columns above.
     *
     * Case-insensitive, because the sample carries `N/A`, `None`, `none` and
     * `Na` — four spellings of the same non-value in six cells.
     */
    public static function sentinel(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        foreach (self::SENTINELS as $sentinel) {
            if (mb_strtolower($trimmed, 'UTF-8') === mb_strtolower($sentinel, 'UTF-8')) {
                return '';
            }
        }

        return $trimmed;
    }

    /**
     * A phone number as two values: what a human reads, and what a link dials.
     *
     * Every number in the observed export is `(NNN) NNN-NNNN` exactly, with no
     * exceptions, so this is cheap — but "cheap today" is not "always", and a
     * number that cannot be normalised is imported as display text with a
     * warning rather than dropped or guessed at. `tel:` and `sms:` are simply
     * absent for it, which is honest; a link that dials the wrong number is
     * not.
     *
     * The display form is the imported string, unchanged. It is what the
     * member recognises on a screen and what an officer will read aloud.
     *
     * @return array{display: string, e164: ?string}
     */
    public static function phone(string $raw): array
    {
        $display = self::text(trim($raw), self::WIDTHS['phone']);

        if ($display === '') {
            return ['display' => '', 'e164' => null];
        }

        $digits = preg_replace('/\D+/', '', $display) ?? '';

        // NANP: ten digits, or eleven with the country code. An area code and
        // an exchange both start 2-9, which is what separates a real number
        // from ten digits of something else.
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10 && $digits[0] >= '2' && $digits[3] >= '2') {
            return ['display' => $display, 'e164' => '+1' . $digits];
        }

        // An already-international number, written with its +.
        if (str_starts_with(trim($display), '+') && strlen($digits) >= 8 && strlen($digits) <= 15) {
            return ['display' => $display, 'e164' => '+' . $digits];
        }

        return ['display' => $display, 'e164' => null];
    }

    /** Can this member be sent a text? 116 of 1,954 cannot. */
    public static function isCellPhone(string $phoneType): bool
    {
        return mb_strtoupper(trim($phoneType), 'UTF-8') === self::CELL_PHONE;
    }

    /**
     * A metric cell as the `member_metric.imported_value` ENUM.
     *
     * Tri-state, and the third state is load-bearing: 1,716 of 1,954
     * harassment-training cells are blank, and blank is NOT N. Scoring it as a
     * failure would show a committee at 7% compliance on something nobody is
     * tracking yet.
     *
     * Sentinel normalisation is deliberately not applied. `N/A` here does not
     * become blank — it is neither Y nor N, so it becomes `unknown` AND raises
     * a warning, because a metric column holding prose is a file problem
     * somebody should look at.
     *
     * @return 'Y'|'N'|'unknown'
     */
    public static function metric(string $raw): string
    {
        $value = mb_strtoupper(trim($raw), 'UTF-8');

        // Exactly Y and N, and nothing helpfully adjacent. Accepting `YES`
        // or `1` would look generous and would quietly swallow the very cell
        // spec 6.1 wants a warning about.
        return match ($value) {
            'Y'     => 'Y',
            'N'     => 'N',
            default => 'unknown',
        };
    }

    /** Was the cell meaningful, or is it the "anything else" spec 6.1 warns on? */
    public static function metricIsUnexpected(string $raw): bool
    {
        return trim($raw) !== '' && self::metric($raw) === 'unknown';
    }

    /** A Y/N flag column as the TINYINT(1) the schema stores. */
    public static function flag(string $raw): int
    {
        return self::metric($raw) === 'Y' ? 1 : 0;
    }

    /** Trimmed and cut to its column, in characters rather than bytes. */
    public static function text(string $value, int $width): string
    {
        return mb_substr(trim($value), 0, $width, 'UTF-8');
    }

    /**
     * The whole row, as the columns `member` holds.
     *
     * Only the fields HLSR owns (spec 6.6). Nothing this application decides
     * — a grant, a scope override, a password, a contact, an assignment, a
     * progress status, a team's area — appears here or anywhere downstream of
     * here, which is what makes "an import never overwrites what we know" a
     * property of the code rather than a rule somebody has to remember.
     *
     * `team_name` and `division_name` are carried as strings, not ids: the
     * rows they resolve to may not exist until the apply, and a dry run must
     * not create reference data for an import that is then abandoned.
     *
     * @param array<int, string> $row
     *
     * @return array<string, mixed>
     */
    public static function normalise(HeaderMap $headers, array $row): array
    {
        $phone = self::phone($headers->value($row, HeaderMap::PHONE));
        $email = self::text($headers->value($row, HeaderMap::EMAIL), self::WIDTHS['email']);
        $title = self::text($headers->value($row, HeaderMap::TITLE), self::WIDTHS['title']);

        return [
            // The natural key. Not truncated — see WIDTHS.
            'member_number' => trim($headers->value($row, HeaderMap::CUSTOMER_NUMBER)),

            'title'       => $title,
            'title_level' => TitleMap::level($title)->value,

            'team_name'     => self::text($headers->value($row, HeaderMap::SUBCOMMITTEE_1), 128),
            'division_name' => self::text($headers->value($row, HeaderMap::SUBCOMMITTEE_3), 128),

            'first_name'     => self::text($headers->value($row, HeaderMap::FIRST_NAME), self::WIDTHS['first_name']),
            'last_name'      => self::text($headers->value($row, HeaderMap::LAST_NAME), self::WIDTHS['last_name']),
            // The two sentinel columns, and the only two.
            'preferred_name' => self::text(
                self::sentinel($headers->value($row, HeaderMap::PREFERRED_NAME)),
                self::WIDTHS['preferred_name']
            ),
            'prefix'         => self::text(
                self::sentinel($headers->value($row, HeaderMap::PREFIX)),
                self::WIDTHS['prefix']
            ),
            'full_name'      => self::text($headers->value($row, HeaderMap::FULL_NAME), self::WIDTHS['full_name']),

            'address' => self::text($headers->value($row, HeaderMap::ADDRESS), self::WIDTHS['address']),
            'city'    => self::text($headers->value($row, HeaderMap::CITY), self::WIDTHS['city']),
            'state'   => self::text($headers->value($row, HeaderMap::STATE), self::WIDTHS['state']),
            'zip'     => self::text($headers->value($row, HeaderMap::ZIP), self::WIDTHS['zip']),

            'phone'      => $phone['display'],
            'phone_e164' => $phone['e164'],
            'phone_type' => self::text($headers->value($row, HeaderMap::PHONE_TYPE), self::WIDTHS['phone_type']),

            // NULL, not '', when absent: one member in the sample has no
            // address at all, and `member.email` is nullable so that "no
            // address on file" is a fact the recovery screen can state rather
            // than an empty string it has to interpret.
            'email' => $email === '' ? null : $email,

            'legal_name_verified' => self::flag($headers->value($row, HeaderMap::LEGAL_NAME_VERIFIED)),
            'is_rookie'           => self::flag($headers->value($row, HeaderMap::ROOKIE)),
            'in_other_committees' => self::flag($headers->value($row, HeaderMap::IN_OTHER_COMMITTEES)),
            'badge_pickup_person' => self::text(
                $headers->value($row, HeaderMap::BADGE_PICKUP_PERSON),
                self::WIDTHS['badge_pickup_person']
            ),

            // The six dead columns (docs/data-findings.md 1). Imported so that
            // a future export which starts populating one is not silently
            // discarded; surfaced on no screen until it carries data. The four
            // that have never held a value at all are kept as raw text — a
            // typed DATE would have to guess a format nobody has observed, and
            // would fail an entire import the first time it guessed wrong.
            'badge_released'                   => self::flag($headers->value($row, HeaderMap::BADGE_RELEASED)),
            'ltc_applied'                      => self::flag($headers->value($row, HeaderMap::LTC_APPLIED)),
            'badge_released_date_raw'          => self::text(
                $headers->value($row, HeaderMap::BADGE_RELEASED_DATE),
                self::WIDTHS['badge_released_date_raw']
            ),
            'badge_issue_date_raw'             => self::text(
                $headers->value($row, HeaderMap::BADGE_ISSUE_DATE),
                self::WIDTHS['badge_issue_date_raw']
            ),
            'eligible_for_service_history_raw' => self::text(
                $headers->value($row, HeaderMap::ELIGIBLE_SERVICE),
                self::WIDTHS['eligible_for_service_history_raw']
            ),
            'eligibility_updated_by_raw'       => self::text(
                $headers->value($row, HeaderMap::ELIGIBILITY_UPDATED),
                self::WIDTHS['eligibility_updated_by_raw']
            ),
        ];
    }

    /**
     * The five metric values on a row, keyed by the `metric` ENUM.
     *
     * @param array<int, string> $row
     *
     * @return array<string, string>
     */
    public static function metrics(HeaderMap $headers, array $row): array
    {
        $values = [];
        foreach (self::METRICS as $metric => $column) {
            $values[$metric] = self::metric($headers->value($row, $column));
        }

        return $values;
    }
}
