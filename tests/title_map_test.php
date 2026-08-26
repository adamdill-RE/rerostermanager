<?php

declare(strict_types=1);

/**
 * The title map (spec 4.2), transcribed a SECOND time.
 *
 * That is the whole point of this file and it is why the table below is
 * written out by hand rather than read from Rerm\Auth\TitleMap. A test that
 * looped over TitleMap::MAP and asserted each entry equalled itself would pass
 * on every possible map, including one where somebody promoted
 * `Lifetime Committeemen` to Officer. Two transcriptions mean a change has to
 * be made twice, on purpose, by somebody who read what they were changing.
 *
 * What a wrong entry costs, concretely: 115 Lifetime Committeemen with
 * accounts they never asked for and a shared password of 1234, or 82 Captains
 * who cannot sign in the week before the show.
 *
 * Counts in the comments are measured against the real 1,954-row export
 * (docs/data-findings.md 3) and are here so a level change reads as "this
 * moves 115 people", not "this edits a line".
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\Auth\Level;
use Rerm\Auth\TitleMap;

/**
 * Spec 4.2, independently transcribed. Do not generate this from TitleMap.
 *
 * @return array<string, string>
 */
function title_map_expected(): array
{
    return [
        // Executive Officer — the whole committee. 7 people.
        'Chairman'                 => 'executive_officer',  // 1
        'Vice President'           => 'executive_officer',  // 1
        'Officer in Charge'        => 'executive_officer',  // 1
        'Division Chairman'        => 'executive_officer',  // 4 — singular, and NOT division-scoped

        // Senior Officer — their own division. 20 people.
        'Division Vice Chairman'   => 'senior_officer',     // 8 — "Division", never "Divisional"
        'Coordinator'              => 'senior_officer',     // 5 — OI-2, closed: the higher reading
        'Ambassador'               => 'senior_officer',     // 7 — OI-2, closed

        // Officer — their own team. 169 people.
        'Vice Chairman'            => 'officer',            // 21
        'Captain'                  => 'officer',            // 82
        'Assistant Captain'        => 'officer',            // 66

        // Member — no login at all. 1,758 people.
        'Committee Member'         => 'member',             // 1630
        'Lifetime Committeemen'    => 'member',             // 115 — plural
        'Lifetime Vice Presidents' => 'member',             // 8   — plural
        'Lifetime Director'        => 'member',             // 1   — absent from the prose brief
        'Past Committee Chairman'  => 'member',             // 4
    ];
}

test('every title in the map confers the level spec 4.2 gives it', function (): void {
    foreach (title_map_expected() as $title => $expected) {
        assertSame(
            $expected,
            TitleMap::level($title)->value,
            "title {$title}"
        );
    }
});

test('the map holds exactly the fifteen titles and no more', function (): void {
    $expected = array_keys(title_map_expected());
    $actual   = TitleMap::titles();

    sort($expected);
    sort($actual);

    // A sixteenth entry is how a title nobody reviewed acquires a level. The
    // export carries exactly 15 distinct titles and every one of them is here.
    assertSame($expected, $actual, 'the set of mapped titles changed');
});

test('an unrecognised title is a Member, and is reported as unrecognised', function (): void {
    foreach (['Grand Marshal', 'Deputy Chairman', 'Volunteer', ''] as $title) {
        assertSame(Level::Member, TitleMap::level($title), "title {$title}");
        assertSame(false, TitleMap::knows($title), "title {$title}");
    }

    // The two facts are separate on purpose: a Committee Member and a title
    // nobody has ever seen are both Level::Member, and only one of them is a
    // warning the Admin has to read.
    assertSame(true, TitleMap::knows('Committee Member'));
    assertSame(Level::Member, TitleMap::level('Committee Member'));
});

test('the brief spellings the export contradicts do NOT match', function (): void {
    // Each of these is what docs/spec-v1.md was originally written with, and
    // each would have silently demoted real officers had the map kept it.
    // They must miss, so that a future export using one raises unknown_title
    // rather than being quietly accepted as a near-enough match.
    foreach ([
        'Division Chairmen',        // plural — 4 Executives
        'Divisional Vice Chairman', // "Divisional" — 8 Senior Officers
        'Lifetime Vice President',  // singular
        'Vice-Chairman',            // hyphenated — 21 Officers
        'Asst Captain',             // abbreviated — 66 Officers
    ] as $wrong) {
        assertSame(false, TitleMap::knows($wrong), "near miss {$wrong} must not match");
        assertSame(Level::Member, TitleMap::level($wrong), "near miss {$wrong}");
    }
});

test('spacing and case are forgiven; words are not', function (): void {
    // A spreadsheet cell carrying a trailing space is not a different job, and
    // the header matcher already forgives exactly this (spec 6.1). What is
    // never forgiven is a difference in the WORDS — that is where all four
    // documented mismatches live, and the test above pins them.
    foreach ([' Captain', 'Captain ', "Captain\t", 'CAPTAIN', 'captain', 'Division  Vice  Chairman'] as $variant) {
        assertSame(true, TitleMap::knows($variant), "variant " . var_export($variant, true));
    }

    assertSame(Level::Officer, TitleMap::level('  captain  '));
    assertSame(Level::SeniorOfficer, TitleMap::level('Division  Vice  Chairman'));
});

test('no title in the map confers Admin', function (): void {
    // Admin is designated, never inherited from a roster (spec 4.1). A title
    // that granted it would mean Rodeo Houston's spreadsheet could hand
    // somebody import, export and show-year control.
    foreach (TitleMap::titles() as $title) {
        assertTrue(
            TitleMap::level($title) !== Level::Admin,
            "title {$title} must not confer Admin"
        );
    }

    assertSame([], TitleMap::titlesFor(Level::Admin));
});

test('exactly the Member titles withhold a login', function (): void {
    $withoutLogin = [];
    foreach (TitleMap::titles() as $title) {
        if (!TitleMap::level($title)->grantsLogin()) {
            $withoutLogin[] = $title;
        }
    }
    sort($withoutLogin);

    $expected = [
        'Committee Member',
        'Lifetime Committeemen',
        'Lifetime Director',
        'Lifetime Vice Presidents',
        'Past Committee Chairman',
    ];
    sort($expected);

    // 1,758 of 1,954 people. Every name added to this list is an account that
    // stops being created; every name removed is a batch of accounts that
    // start being created with the password 1234.
    assertSame($expected, $withoutLogin);
});

test('levels rank low to high and compare through atLeast', function (): void {
    assertSame(1, Level::Member->rank());
    assertSame(2, Level::Officer->rank());
    assertSame(3, Level::SeniorOfficer->rank());
    assertSame(4, Level::ExecutiveOfficer->rank());
    assertSame(5, Level::Admin->rank());

    assertTrue(Level::Officer->atLeast(Level::Officer));
    assertTrue(Level::Admin->atLeast(Level::Member));
    assertTrue(!Level::Officer->atLeast(Level::SeniorOfficer));

    // The reason rank comparison lives in PHP: sorted as strings, 'officer'
    // comes after 'admin', so a SQL comparison would rank an Officer above an
    // Admin. tests/schema_test.php pins the column's declaration order; this
    // pins that nothing reads it as text.
    assertTrue('officer' > 'admin', 'the alphabetical trap this method exists to avoid');
});

test('the backing values are exactly what the schema stores', function (): void {
    // member.title_level and app_user.level are ENUMs of these five strings.
    // A case renamed here without a migration writes a value the column
    // refuses under STRICT_ALL_TABLES — loudly, which is the good outcome —
    // or, without it, silently stores ''.
    $values = array_map(static fn (Level $l): string => $l->value, Level::cases());

    assertSame(
        ['member', 'officer', 'senior_officer', 'executive_officer', 'admin'],
        $values
    );
});
