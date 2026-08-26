<?php

declare(strict_types=1);

namespace Rerm\Auth;

/**
 * Title to access level (spec 4.2), in the export's own spelling.
 *
 * This is the one place the map is written down in the application, and
 * tests/title_map_test.php transcribes it a second time so that changing it
 * has to be done twice, on purpose. A level here is not cosmetic: it decides
 * who gets an account created on the next import and what they can see.
 *
 * **The strings are the export's, not the prose brief's.** Four of them
 * disagree, and each disagreement breaks an exact comparison silently
 * (docs/data-findings.md 3):
 *
 *   brief "Division Chairmen"        -> data `Division Chairman`   (singular)
 *   brief "Divisional Vice Chairmen" -> data `Division Vice Chairman`
 *   brief "Lifetime Vice President"  -> data `Lifetime Vice Presidents`
 *   brief (absent)                   -> data `Lifetime Director`   (1 person)
 *
 * Three decisions are encoded here rather than argued again anywhere else:
 *
 *   * `Division Chairman` is EXECUTIVE, not division-scoped. All four are
 *     filed under Logistics Division in the export, so their own placement
 *     cannot name what they run; executive scope makes the question moot.
 *   * `Coordinator` and `Ambassador` are SENIOR OFFICER. The brief lists both
 *     in two places at once; the higher reading was confirmed, and it affects
 *     12 people (OI-2, closed).
 *   * Lifetime and Past titles get NO login. The brief's rule — "any title
 *     other than Committee Member or Lifetime Committeemen is an officer" —
 *     would have handed accounts to 8 Lifetime Vice Presidents, 4 Past
 *     Committee Chairmen and 1 Lifetime Director.
 *
 * **An unrecognised title is a Member with a warning naming it** (spec 6.4,
 * `unknown_title`). It never silently becomes an officer, and that direction
 * is deliberate: a title nobody anticipated granting an account is a security
 * problem, while one denying an account is a phone call.
 */
final class TitleMap
{
    /**
     * The map, exactly as spec 4.2 lists it, keyed by the export's spelling.
     *
     * Values are Level backing values rather than Level cases because a
     * constant expression cannot hold an enum instance; level() resolves them.
     *
     * @var array<string, string>
     */
    public const MAP = [
        // Executive Officer — the whole committee.
        'Chairman'                 => 'executive_officer',
        'Vice President'           => 'executive_officer',
        'Officer in Charge'        => 'executive_officer',
        'Division Chairman'        => 'executive_officer',

        // Senior Officer — their own division.
        'Division Vice Chairman'   => 'senior_officer',
        'Coordinator'              => 'senior_officer',
        'Ambassador'               => 'senior_officer',

        // Officer — their own team.
        'Vice Chairman'            => 'officer',
        'Captain'                  => 'officer',
        'Assistant Captain'        => 'officer',

        // Member — no login.
        'Committee Member'         => 'member',
        'Lifetime Committeemen'    => 'member',
        'Lifetime Vice Presidents' => 'member',
        'Lifetime Director'        => 'member',
        'Past Committee Chairman'  => 'member',
    ];

    /**
     * The level a title confers, or Member for one this map does not know.
     *
     * Matching is on a normalised key — surrounding whitespace trimmed,
     * internal runs of whitespace collapsed, case folded — for the same reason
     * headers are matched that way (spec 6.1): the difference between
     * `Captain` and `Captain ` is a spreadsheet artefact, not a different job.
     * What is deliberately NOT forgiven is a difference in words, which is
     * where all four documented mismatches live: `Divisional Vice Chairman`
     * does not match `Division Vice Chairman` and must not, because a map that
     * guessed at near-misses would eventually guess an officer into existence.
     */
    public static function level(string $title): Level
    {
        $key = self::key($title);

        foreach (self::MAP as $known => $level) {
            if (self::key($known) === $key) {
                return Level::from($level);
            }
        }

        return Level::Member;
    }

    /**
     * Is this a title the map knows?
     *
     * False is what raises the `unknown_title` warning. It is a separate
     * question from level(), because "Member because the map says so" and
     * "Member because nobody recognised this" are the same level and very
     * different facts.
     */
    public static function knows(string $title): bool
    {
        $key = self::key($title);

        foreach (array_keys(self::MAP) as $known) {
            if (self::key($known) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every title the map knows, in the export's spelling.
     *
     * @return array<int, string>
     */
    public static function titles(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Titles conferring the given level, in the export's spelling.
     *
     * @return array<int, string>
     */
    public static function titlesFor(Level $level): array
    {
        $titles = [];
        foreach (self::MAP as $title => $value) {
            if ($value === $level->value) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /** Trimmed, whitespace-collapsed, case-folded. */
    private static function key(string $title): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($title));

        return mb_strtolower($collapsed ?? trim($title), 'UTF-8');
    }
}
