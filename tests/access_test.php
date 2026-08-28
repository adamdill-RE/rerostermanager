<?php

declare(strict_types=1);

/**
 * The access model (spec 4), transcribed a SECOND time.
 *
 * The matrix below is written out by hand from spec 4.5, independently of
 * Rerm\Auth\Capability — the same discipline title_map_test.php applies and
 * for the same reason: widening who can read 1,954 people's home addresses
 * must take two deliberate edits, not one.
 *
 * The behavioural half exercises Access::allows() with hand-built users and
 * subjects: an Officer against their own team and somebody else's, a Senior
 * Officer against two divisions and the placeholder, the null-subject rule,
 * and the grant cap.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Scope;
use Rerm\Auth\Subject;
use Rerm\Auth\User;

/** A user at a level, scoped to a division and team. */
function access_user(Level $level, ?int $divisionId = null, ?int $teamId = null, int $memberId = 100): User
{
    return new User(
        id: 1,
        memberId: $memberId,
        memberNumber: '1000001',
        level: $level,
        scopeDivisionId: $divisionId,
        scopeTeamId: $teamId,
        mustChangePassword: false,
        displayName: 'Test User',
    );
}

function access_subject(int $memberId = 200, ?int $divisionId = null, ?int $teamId = null): Subject
{
    return new Subject($memberId, $divisionId, $teamId);
}

// ---------------------------------------------------------------------------
// The matrix, spec 4.5, row by row
// ---------------------------------------------------------------------------

test('the capability matrix matches spec 4.5, transcribed independently', function (): void {
    // Capability value => [minimum level value, scope]. THE SECOND
    // TRANSCRIPTION — from the spec table, not from Capability.php.
    $spec = [
        'view_own_record'          => ['member', Scope::Own],
        'change_own_password'      => ['member', Scope::Own],
        'view_roster'              => ['officer', Scope::Scoped],
        'log_contact'              => ['officer', Scope::Scoped],
        'set_metric_progress'      => ['officer', Scope::Scoped],
        'assign_officers'          => ['officer', Scope::Scoped],
        'view_status_dashboard'    => ['officer', Scope::Scoped],
        // Phase 8 decided 3 moved export_roster here from Admin / Everywhere.
        // There is ONE export and every row of it goes through
        // ScopedQuery::forUser(), so breadth is decided by who is asking; an
        // Officer exporting their own team exports data they already read,
        // row by row, on View My Roster. Spec 4.5 was edited in the same
        // commit, and this line is transcribed FROM that table.
        'export_roster'            => ['officer', Scope::Scoped],
        'view_committee_dashboard' => ['senior_officer', Scope::Scoped],
        'designate_allowed_user'   => ['senior_officer', Scope::Scoped],
        'import_roster'            => ['admin', Scope::Everywhere],
        'manage_show_year'         => ['admin', Scope::Everywhere],
        'designate_admin'          => ['admin', Scope::Everywhere],
        'manage_teams'             => ['admin', Scope::Everywhere],
        'view_audit_log'           => ['admin', Scope::Everywhere],
    ];

    assertSame(count($spec), count(Capability::cases()), 'spec 4.5 has exactly this many rows');

    foreach ($spec as $value => [$minimum, $scope]) {
        $capability = Capability::from($value);
        assertSame($minimum, $capability->minimumLevel()->value, "minimum level of {$value}");
        assertSame($scope, $capability->scope(), "scope of {$value}");
    }
});

test('the five levels rank in spec 4.1 order and only Member confers no login', function (): void {
    assertSame(1, Level::Member->rank());
    assertSame(2, Level::Officer->rank());
    assertSame(3, Level::SeniorOfficer->rank());
    assertSame(4, Level::ExecutiveOfficer->rank());
    assertSame(5, Level::Admin->rank());

    assertSame(false, Level::Member->grantsLogin());
    foreach ([Level::Officer, Level::SeniorOfficer, Level::ExecutiveOfficer, Level::Admin] as $level) {
        assertSame(true, $level->grantsLogin(), $level->value . ' grants a login');
    }
});

// ---------------------------------------------------------------------------
// Scoped: an Officer reaches their team, a Senior Officer their division
// ---------------------------------------------------------------------------

test('an Officer reaches exactly their own team', function (): void {
    $officer = access_user(Level::Officer, divisionId: 1, teamId: 10);

    assertSame(true, Access::allows($officer, Capability::LogContact, access_subject(teamId: 10, divisionId: 1)));
    assertSame(false, Access::allows($officer, Capability::LogContact, access_subject(teamId: 11, divisionId: 1)),
        'another team in the same division is out of scope');
    assertSame(false, Access::allows($officer, Capability::LogContact, access_subject(teamId: null, divisionId: 1)),
        'a member with no team is on no team of theirs');
});

test('an Officer with no team reaches nobody, never everybody', function (): void {
    // 41 of 96 teams are thin and titles move on import; an Officer whose
    // scope resolves to no team must see nothing rather than everything.
    $officer = access_user(Level::Officer, divisionId: 1, teamId: null);

    assertSame(false, Access::allows($officer, Capability::ViewRoster, access_subject(teamId: 10, divisionId: 1)));
    assertSame(false, Access::allows($officer, Capability::ViewRoster, access_subject(teamId: null, divisionId: 1)),
        'null must not match null');
});

test('a Senior Officer reaches exactly their own division', function (): void {
    $senior = access_user(Level::SeniorOfficer, divisionId: 3, teamId: 30);

    assertSame(true, Access::allows($senior, Capability::ViewRoster, access_subject(divisionId: 3, teamId: 44)),
        'the whole division, not just their own team — the breadth is the job (spec 4.3)');
    assertSame(false, Access::allows($senior, Capability::ViewRoster, access_subject(divisionId: 4, teamId: 44)));
    assertSame(false, Access::allows($senior, Capability::ViewRoster, access_subject(divisionId: null, teamId: 44)));
});

test('a Senior Officer can be scoped to the placeholder division', function (): void {
    // (No Division) is a real division row (spec 5.1a). Scoping an officer to
    // it is the point: those 72 members get an owner, which a NULL could
    // never give them.
    $senior = access_user(Level::SeniorOfficer, divisionId: 5, teamId: null);

    assertSame(true, Access::allows($senior, Capability::ViewRoster, access_subject(divisionId: 5, teamId: null)));
});

test('Executive Officers and Admins reach the whole committee', function (): void {
    foreach ([Level::ExecutiveOfficer, Level::Admin] as $level) {
        $user = access_user($level, divisionId: 1, teamId: 10);

        assertSame(true, Access::allows($user, Capability::LogContact, access_subject(divisionId: 4, teamId: 90)),
            $level->value . ' is not bounded by their own placement');
        assertSame(true, Access::allows($user, Capability::LogContact, access_subject(divisionId: null, teamId: null)));
    }
});

test('level floors hold: a Member cannot view a roster, an Officer cannot see the committee dashboard', function (): void {
    $member  = access_user(Level::Member, divisionId: 1, teamId: 10);
    $officer = access_user(Level::Officer, divisionId: 1, teamId: 10);
    $inScope = access_subject(divisionId: 1, teamId: 10);

    assertSame(false, Access::allows($member, Capability::ViewRoster, $inScope));
    assertSame(false, Access::allows($officer, Capability::ViewCommitteeDashboard, $inScope));
    assertSame(false, Access::allows($officer, Capability::ImportRoster));
});

// ---------------------------------------------------------------------------
// The null-subject rule (spec 4.5)
// ---------------------------------------------------------------------------

test('a scoped capability with no subject is DENIED, whatever the level', function (): void {
    // "May this officer log a contact?" without naming the member is not a
    // question with an answer, and answering yes is how an Officer edits
    // another team's data.
    foreach ([Level::Officer, Level::SeniorOfficer, Level::ExecutiveOfficer, Level::Admin] as $level) {
        $user = access_user($level, divisionId: 1, teamId: 10);
        assertSame(false, Access::allows($user, Capability::LogContact, null),
            $level->value . ' must still name a subject');
    }
});

test('an everywhere capability needs no subject — and mayUse() is only ever the level check', function (): void {
    $admin   = access_user(Level::Admin);
    $officer = access_user(Level::Officer, divisionId: 1, teamId: 10);

    assertSame(true, Access::allows($admin, Capability::ImportRoster));
    assertSame(true, Access::allows($admin, Capability::ImportRoster, access_subject()));

    assertSame(true, Access::mayUse($officer, Capability::ViewRoster));
    assertSame(false, Access::mayUse($officer, Capability::ImportRoster));
    assertSame(false, Access::mayUse(access_user(Level::Member), Capability::ViewRoster));
});

// ---------------------------------------------------------------------------
// Own scope
// ---------------------------------------------------------------------------

test('own-scope capabilities reach the user themself and nobody else', function (): void {
    $user = access_user(Level::Member, memberId: 100);

    assertSame(true, Access::allows($user, Capability::ViewOwnRecord, access_subject(memberId: 100)));
    assertSame(false, Access::allows($user, Capability::ViewOwnRecord, access_subject(memberId: 101)));
    assertSame(false, Access::allows($user, Capability::ViewOwnRecord, null));

    // Being an Admin does not turn "own" into "anyone" — that is view_roster.
    $admin = access_user(Level::Admin, memberId: 100);
    assertSame(false, Access::allows($admin, Capability::ChangeOwnPassword, access_subject(memberId: 101)));
});

// ---------------------------------------------------------------------------
// Grants (spec 4.4): at or below their own level
// ---------------------------------------------------------------------------

test('a grant is capped at the granter\'s own level', function (): void {
    $senior = access_user(Level::SeniorOfficer, divisionId: 1);
    $exec   = access_user(Level::ExecutiveOfficer);
    $admin  = access_user(Level::Admin);

    assertSame(true, Access::mayGrant($senior, Level::SeniorOfficer));
    assertSame(true, Access::mayGrant($senior, Level::Officer));
    assertSame(false, Access::mayGrant($senior, Level::ExecutiveOfficer),
        'Senior Officers cannot create Executives');

    assertSame(true, Access::mayGrant($exec, Level::ExecutiveOfficer));
    assertSame(false, Access::mayGrant($exec, Level::Admin),
        'only an Admin creates an Admin');

    assertSame(true, Access::mayGrant($admin, Level::Admin));
});

test('Officers and Members grant nothing at all', function (): void {
    // designate_allowed_user starts at Senior Officer (spec 4.5).
    foreach ([Level::Member, Level::Officer] as $level) {
        $user = access_user($level, divisionId: 1, teamId: 10);
        assertSame(false, Access::mayGrant($user, Level::Officer), $level->value . ' may not grant');
        assertSame(false, Access::mayGrant($user, Level::Member), $level->value . ' may not grant even Member');
    }
});

// ---------------------------------------------------------------------------
// The forbidden column
// ---------------------------------------------------------------------------

test('team.area appears nowhere in Access, ScopedQuery or the eligibility rule', function (): void {
    // The column is display grouping, seeded by a prefix heuristic and
    // editable by an Admin (CLAUDE.md). A permission that read it would move
    // with a cosmetic edit. This holds the SOURCE clean, comments included,
    // so it cannot creep in as "just documentation" and then get referenced.
    // Phase 6 adds the two files that decide who may HOLD twenty people —
    // the same class of decision, held to the same rule.
    foreach ([
        'Auth/Access.php',
        'Roster/ScopedQuery.php',
        'Roster/EligibleOfficers.php',
        'Roster/AssignOfficers.php',
    ] as $file) {
        $source = (string) file_get_contents(__DIR__ . '/../app/src/' . $file);
        assertTrue($source !== '', $file . ' is readable');
        assertSame(0, preg_match('/\barea\b/i', $source), $file . ' must never mention team.area');
    }
});

// ---------------------------------------------------------------------------
// User::fromRow — the row-to-scope resolution
// ---------------------------------------------------------------------------

test('User::fromRow takes effective_level verbatim and never re-derives it', function (): void {
    // The schema computes effective_level = granted_level ?? level as a
    // VIRTUAL column. fromRow must read the answer, so a designated Senior
    // Officer whose title says Member arrives as a Senior Officer.
    $user = User::fromRow([
        'id' => 7, 'member_id' => 9, 'member_number' => '1000002',
        'effective_level' => 'senior_officer', 'must_change_password' => 0,
        'scope_division_id' => null, 'scope_team_id' => null,
        'division_id' => 3, 'team_id' => 40,
        'first_name' => 'Alex', 'last_name' => 'Example', 'preferred_name' => '',
    ]);

    assertSame(Level::SeniorOfficer, $user->level);
    assertSame(3, $user->scopeDivisionId, 'scope falls back to the member\'s own division');
    assertSame(40, $user->scopeTeamId, 'and their own team');
    assertSame('Alex Example', $user->displayName);
});

test('an explicit Admin scope override wins over the member record', function (): void {
    $user = User::fromRow([
        'id' => 7, 'member_id' => 9, 'member_number' => '1000002',
        'effective_level' => 'officer', 'must_change_password' => 1,
        'scope_division_id' => 8, 'scope_team_id' => 77,
        'division_id' => 3, 'team_id' => 40,
        'first_name' => 'Alex', 'last_name' => 'Example', 'preferred_name' => 'Lex',
    ]);

    assertSame(8, $user->scopeDivisionId);
    assertSame(77, $user->scopeTeamId);
    assertSame(true, $user->mustChangePassword);
    assertSame('Lex Example', $user->displayName, 'preferred name leads, as on every screen');
});

test('Subject::fromMemberRow carries the member\'s own placement', function (): void {
    $subject = Subject::fromMemberRow(['id' => 12, 'division_id' => 4, 'team_id' => null]);

    assertSame(12, $subject->memberId);
    assertSame(4, $subject->divisionId);
    assertSame(null, $subject->teamId);
});
