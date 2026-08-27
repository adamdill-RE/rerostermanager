<?php

declare(strict_types=1);

/**
 * Assign Officers (spec 7.4, Phase 6): the four buckets proven disjoint and
 * complete over a team, bucket 2 caught from CURRENT data for both of the
 * ways an import breaks an assignment, the decided-5 ordering, and every
 * refusal the write path owes — the cap, the out-of-scope member, the
 * cross-team officer, the closed year, the oversized selection.
 *
 * The fixture is generated and the expectations are TRANSCRIBED beside it,
 * not computed by the code under test: bucket membership is written out per
 * member, so a change to the query that quietly reclassifies somebody fails
 * here rather than being confirmed by its own arithmetic.
 *
 * Generated, never real: this repository is public. Member numbers here are
 * 'AS000001', phones are the reserved (555) 555-01xx fiction range, and
 * addresses are @example.com.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Roster\AssignOfficers;
use Rerm\Roster\AssignPage;
use Rerm\Roster\EligibleOfficers;
use Rerm\Roster\StatusPage;
use Rerm\Routes;

// ---------------------------------------------------------------------------
// The matrix, the route and the rules that need no database
// ---------------------------------------------------------------------------

test('the assign route is guarded by the capability, not merely by being signed in', function (): void {
    assertSame(Capability::AssignOfficers->value, Routes::guard('assign'));
    assertSame(Level::Officer, Capability::AssignOfficers->minimumLevel());
    assertSame(Rerm\Auth\Scope::Scoped, Capability::AssignOfficers->scope());
});

test('the menu tile points at the screen now that it exists', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../app/views/menu.php');
    assertTrue($source !== '', 'app/views/menu.php is readable');

    $line = '';
    foreach (explode("\n", $source) as $candidate) {
        if (str_contains($candidate, 'Capability::AssignOfficers')) {
            $line = $candidate;
        }
    }

    assertTrue($line !== '', 'the menu names Capability::AssignOfficers');
    assertTrue(str_contains($line, "'route' => 'assign'"), "the tile links to /assign, got: {$line}");
});

test('eligibility qualifies exactly Officer and above — decided in PHP, never by SQL string order', function (): void {
    // The trap this asserts against: the level column is an ENUM and
    // `>= 'officer'` in a WHERE clause compares strings, where 'admin' sorts
    // BELOW 'officer' and the Chairman quietly stops being an officer.
    assertSame(
        ['officer', 'senior_officer', 'executive_officer', 'admin'],
        EligibleOfficers::levelValues()
    );
    assertSame(Level::Officer, EligibleOfficers::FLOOR);
});

test('every outcome the write path can return is one the handler answers', function (): void {
    // Transcribed a second time, deliberately: an outcome added to the class
    // without a sentence in the handler reaches the officer as the WRONG
    // sentence — a refusal read as "nobody was selected" is a bug nobody
    // files, because the screen looked like it worked.
    assertSame(
        [
            'assigned', 'removed', 'nothing_selected', 'nothing_to_do', 'refused_all',
            'bad_officer', 'bad_action', 'too_many', 'no_year', 'year_closed',
        ],
        AssignOfficers::OUTCOMES
    );

    $source = (string) file_get_contents(__DIR__ . '/../public/index.php');
    assertTrue($source !== '', 'public/index.php is readable');

    foreach (AssignOfficers::OUTCOMES as $outcome) {
        assertTrue(
            str_contains($source, "=== '{$outcome}'"),
            "the handler branches on the '{$outcome}' outcome"
        );
    }
});

test('the three list buckets are the ones the URL may spell; bucket 3 is a roll-up', function (): void {
    assertSame(['unassigned', 'ineligible', 'assigned'], AssignPage::BUCKETS);
    assertSame(['assign', 'assign_all_unassigned', 'remove'], AssignOfficers::ACTIONS);
});

// ---------------------------------------------------------------------------
// The database under test — the same accessor pattern as the other suites
// ---------------------------------------------------------------------------

function as_pdo(): PDO
{
    static $pdo = null;
    static $failure = null;

    if ($failure !== null) {
        skip($failure);
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    try {
        $pdo = $app->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    $ready = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assignment'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

function as_teardown(PDO $pdo): void
{
    $members = "SELECT id FROM member WHERE member_number LIKE 'AS%'";
    $users   = "SELECT id FROM app_user WHERE member_id IN ({$members})";

    // RESTRICT-safe order: audit rows point at app_user, assignments point at
    // both member and app_user, and member is last.
    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
    $pdo->exec("DELETE FROM contact_log WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM assignment WHERE member_id IN ({$members}) OR officer_member_id IN ({$members})");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'AS%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'AS Team %'");
}

/**
 * Four teams across two divisions.
 *
 * Team 1 is the working team and holds every case worth testing:
 *
 *   off1   Captain                    eligible
 *   off2   Assistant Captain          eligible
 *   off3   Committee Member + GRANT   eligible — the Allowed User, durable
 *   off4   Committee Member, no grant NOT eligible — demoted by an import
 *   off7   Captain                    eligible — the fourth, for the cap
 *   off6   Captain, purged            NOT eligible, and invisible everywhere
 *   off5   Vice Chairman on TEAM 2    NOT eligible for team 1 — moved teams
 *
 * and the members:
 *
 *   never1 never2  unassigned, never contacted
 *   old            unassigned, contacted 20 days ago
 *   recent         unassigned, contacted 2 days ago
 *   demoted        assigned to off4          -> bucket 2 (demotion)
 *   moved          assigned to off5          -> bucket 2 (moved team)
 *   mixed          assigned to off1 AND off4 -> bucket 2, and only off4's row
 *                                               may ever be touched
 *   full           assigned to off1 off2 off3 -> bucket 4, AT CAP
 *   one            assigned to off1           -> bucket 4
 *
 * Team 2 (same division) holds off5 and one member. Team 3 (same division)
 * holds two members and NO officer at all — bucket 3. Team 4 is in the other
 * division and holds the out-of-scope member.
 *
 * @return array<string, mixed>
 */
function as_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = as_pdo();
    as_teardown($pdo);

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();
    assertTrue($year > 0, 'the seeded active show year exists');

    $real = [];
    foreach ($pdo->query('SELECT id FROM division WHERE is_placeholder = 0 ORDER BY id')->fetchAll() as $row) {
        $real[] = (int) $row['id'];
    }
    assertTrue(count($real) >= 2, 'two real divisions to span');
    [$divisionA, $divisionB] = $real;

    $teams      = [];
    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)');
    foreach ([1 => $divisionA, 2 => $divisionA, 3 => $divisionA, 4 => $divisionB] as $n => $division) {
        $insertTeam->execute([':name' => sprintf('AS Team %02d', $n), ':division' => $division]);
        $teams[$n] = (int) $pdo->lastInsertId();
    }

    // last_name is what the name ordering sorts on, so it is spelled out per
    // member rather than generated: the decided-5 assertion below is a
    // literal list, and a generated name would make it unreadable.
    $specs = [
        'never1'  => ['team' => 1, 'last' => 'Never1', 'title' => 'Committee Member',  'level' => 'member',  'contacted_days' => null],
        'never2'  => ['team' => 1, 'last' => 'Never2', 'title' => 'Committee Member',  'level' => 'member',  'contacted_days' => null],
        'old'     => ['team' => 1, 'last' => 'Old',    'title' => 'Committee Member',  'level' => 'member',  'contacted_days' => 20],
        'recent'  => ['team' => 1, 'last' => 'Recent', 'title' => 'Committee Member',  'level' => 'member',  'contacted_days' => 2],
        'demoted' => ['team' => 1, 'last' => 'Demoted', 'title' => 'Committee Member', 'level' => 'member',  'contacted_days' => null],
        'moved'   => ['team' => 1, 'last' => 'Moved',  'title' => 'Committee Member',  'level' => 'member',  'contacted_days' => null],
        'mixed'   => ['team' => 1, 'last' => 'Mixed',  'title' => 'Committee Member',  'level' => 'member',  'contacted_days' => null],
        'full'    => ['team' => 1, 'last' => 'Full',   'title' => 'Committee Member',  'level' => 'member',  'contacted_days' => null],
        'one'     => ['team' => 1, 'last' => 'One',    'title' => 'Committee Member',  'level' => 'member',  'contacted_days' => null],

        // The officers. off6 is purged and off5 sits on team 2.
        'off1'    => ['team' => 1, 'last' => 'Off1', 'title' => 'Captain',           'level' => 'officer', 'contacted_days' => null],
        'off2'    => ['team' => 1, 'last' => 'Off2', 'title' => 'Assistant Captain', 'level' => 'officer', 'contacted_days' => null],
        'off3'    => ['team' => 1, 'last' => 'Off3', 'title' => 'Committee Member',  'level' => 'member',  'contacted_days' => null],
        'off4'    => ['team' => 1, 'last' => 'Off4', 'title' => 'Committee Member',  'level' => 'member',  'contacted_days' => null],
        'off7'    => ['team' => 1, 'last' => 'Off7', 'title' => 'Captain',           'level' => 'officer', 'contacted_days' => null],
        'off6'    => ['team' => 1, 'last' => 'Off6', 'title' => 'Captain',           'level' => 'officer', 'contacted_days' => null, 'purged' => true],
        'off5'    => ['team' => 2, 'last' => 'Off5', 'title' => 'Vice Chairman',     'level' => 'officer', 'contacted_days' => null],

        't2one'   => ['team' => 2, 'last' => 'T2one', 'title' => 'Committee Member', 'level' => 'member', 'contacted_days' => null],
        'senior'  => ['team' => 2, 'last' => 'Senior', 'title' => 'Division Vice Chairman', 'level' => 'senior_officer', 'contacted_days' => null],

        // Team 3: two members and nobody who could ever be assigned them.
        't3one'   => ['team' => 3, 'last' => 'T3one', 'title' => 'Committee Member', 'level' => 'member', 'contacted_days' => null],
        't3two'   => ['team' => 3, 'last' => 'T3two', 'title' => 'Committee Member', 'level' => 'member', 'contacted_days' => null],

        // The other division, and a member on no team at all.
        'outsider' => ['team' => 4, 'last' => 'Outsider', 'title' => 'Committee Member', 'level' => 'member', 'contacted_days' => null],
        'noteam'   => ['team' => null, 'last' => 'Noteam', 'title' => 'Committee Member', 'level' => 'member', 'contacted_days' => null],
    ];

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, division_id, team_id,'
        . ' phone, phone_e164, phone_type, email, title, title_level, purged_at)'
        . " VALUES (:number, 'Member', :last, '', :division, :team,"
        . " '(555) 555-0104', '+15555550104', 'CELL PHONE', :email, :title, :level, :purged)"
    );

    $members = [];
    $n       = 0;
    foreach ($specs as $key => $spec) {
        $n++;
        $number   = sprintf('AS%06d', $n);
        $division = $spec['team'] === 4 ? $divisionB : $divisionA;
        $insertMember->execute([
            ':number'   => $number,
            ':last'     => $spec['last'],
            ':division' => $division,
            ':team'     => $spec['team'] === null ? null : $teams[$spec['team']],
            ':email'    => strtolower($number) . '@example.com',
            ':title'    => $spec['title'],
            ':level'    => $spec['level'],
            ':purged'   => ($spec['purged'] ?? false) ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $members[$key] = [
            'id'     => (int) $pdo->lastInsertId(),
            'number' => $number,
            'last'   => $spec['last'],
            'team'   => $spec['team'],
        ];
    }

    // Accounts. off3 is the Allowed User — level 'member' from their title,
    // granted_level 'officer' on top, which is what keeps them assignable
    // through every future import. off4 is the demotion: the account survives
    // deactivated, with NO grant behind it, so eligibility ends.
    $insertAccount = $pdo->prepare(
        'INSERT INTO app_user (member_id, level, granted_level, password_hash, must_change_password, is_active)'
        . " VALUES (:member, :level, :granted, '*', 0, :active)"
    );

    $accounts = [
        'off1'   => ['level' => 'officer',        'granted' => null,      'active' => 1],
        'off2'   => ['level' => 'officer',        'granted' => null,      'active' => 1],
        'off3'   => ['level' => 'member',         'granted' => 'officer', 'active' => 1],
        'off4'   => ['level' => 'member',         'granted' => null,      'active' => 0],
        'off5'   => ['level' => 'officer',        'granted' => null,      'active' => 1],
        'off7'   => ['level' => 'officer',        'granted' => null,      'active' => 1],
        'senior' => ['level' => 'senior_officer', 'granted' => null,      'active' => 1],
    ];

    $users = [];
    foreach ($accounts as $key => $account) {
        $insertAccount->execute([
            ':member'  => $members[$key]['id'],
            ':level'   => $account['level'],
            ':granted' => $account['granted'],
            ':active'  => $account['active'],
        ]);
        $users[$key] = (int) $pdo->lastInsertId();
    }

    // Contacts, so the decided-5 ordering has something to order.
    $insertContact = $pdo->prepare(
        'INSERT INTO contact_log (member_id, show_year_id, contacted_by, contact_type, occurred_at, notes)'
        . " VALUES (:member, :year, :by, 'call', :at, 'Fixture call.')"
    );
    foreach ($specs as $key => $spec) {
        if ($spec['contacted_days'] !== null) {
            $insertContact->execute([
                ':member' => $members[$key]['id'],
                ':year'   => $year,
                ':by'     => $users['off1'],
                ':at'     => gmdate('Y-m-d H:i:s', time() - $spec['contacted_days'] * 86400),
            ]);
        }
    }

    register_shutdown_function(static fn () => as_teardown(as_pdo()));

    $fixture = [
        'year'      => $year,
        'teams'     => $teams,
        'divisionA' => $divisionA,
        'divisionB' => $divisionB,
        'members'   => $members,
        'users'     => $users,
    ];

    as_baseline();

    return $fixture;
}

/**
 * The assignment state every bucket test starts from, re-established rather
 * than assumed: these tests write, and a test that depended on the one before
 * it would pass in the order it was written and nowhere else.
 */
function as_baseline(): void
{
    $f   = $GLOBALS['as_fixture_cache'] ?? null;
    $f ??= as_fixture();
    $GLOBALS['as_fixture_cache'] = $f;

    $pdo = as_pdo();
    $ids = implode(', ', array_map(static fn (array $m): int => $m['id'], $f['members']));
    $pdo->exec("DELETE FROM assignment WHERE member_id IN ({$ids}) OR officer_member_id IN ({$ids})");

    $insert = $pdo->prepare(
        'INSERT INTO assignment (member_id, officer_member_id, show_year_id, assigned_by)'
        . ' VALUES (:member, :officer, :year, :by)'
    );

    $baseline = [
        ['demoted', 'off4'],
        ['moved',   'off5'],
        ['mixed',   'off1'],
        ['mixed',   'off4'],
        ['full',    'off1'],
        ['full',    'off2'],
        ['full',    'off3'],
        ['one',     'off1'],
    ];

    foreach ($baseline as [$member, $officer]) {
        $insert->execute([
            ':member'  => $f['members'][$member]['id'],
            ':officer' => $f['members'][$officer]['id'],
            ':year'    => $f['year'],
            ':by'      => $f['users']['off1'],
        ]);
    }
}

/** The Captain of team 1 — an Officer, scoped to that team and nothing else. */
function as_officer(): User
{
    $f = as_fixture();

    return new User(
        $f['users']['off1'],
        $f['members']['off1']['id'],
        $f['members']['off1']['number'],
        Level::Officer,
        $f['divisionA'],
        $f['teams'][1],
        false,
        'Member Off1'
    );
}

/** The other Captain on team 1, who holds nothing — the launch state. */
function as_officer7(): User
{
    $f = as_fixture();

    return new User(
        $f['users']['off7'],
        $f['members']['off7']['id'],
        $f['members']['off7']['number'],
        Level::Officer,
        $f['divisionA'],
        $f['teams'][1],
        false,
        'Member Off7'
    );
}

/** A Senior Officer over division A — teams 1, 2 and 3, never team 4. */
function as_senior(): User
{
    $f = as_fixture();

    return new User(
        $f['users']['senior'],
        $f['members']['senior']['id'],
        $f['members']['senior']['number'],
        Level::SeniorOfficer,
        $f['divisionA'],
        $f['teams'][2],
        false,
        'Member Senior'
    );
}

function as_page(User $user, array $input = []): array
{
    $f = as_fixture();

    return AssignPage::fromApp($GLOBALS['rerm_app'])->page($user, $f['year'], $input);
}

function as_apply(User $user, array $input): array
{
    return AssignOfficers::fromApp($GLOBALS['rerm_app'])->apply($user, $input);
}

/** The officer member ids currently assigned to a member, in id order. */
function as_current(string $memberKey): array
{
    $f = as_fixture();

    $read = as_pdo()->prepare(
        'SELECT officer_member_id FROM assignment WHERE member_id = :member'
        . ' AND show_year_id = :year AND removed_at IS NULL ORDER BY officer_member_id'
    );
    $read->execute([':member' => $f['members'][$memberKey]['id'], ':year' => $f['year']]);

    return array_map(static fn (array $r): int => (int) $r['officer_member_id'], $read->fetchAll());
}

/** Every assignment row for a member, removed ones included. */
function as_rowCount(string $memberKey): int
{
    $f = as_fixture();

    $read = as_pdo()->prepare('SELECT COUNT(*) FROM assignment WHERE member_id = :member');
    $read->execute([':member' => $f['members'][$memberKey]['id']]);

    return (int) $read->fetchColumn();
}

/** @return array<int, string> the member keys a page's rows name, in order */
function as_keys(array $rows): array
{
    $f    = as_fixture();
    $byId = [];
    foreach ($f['members'] as $key => $member) {
        $byId[$member['id']] = $key;
    }

    return array_map(static fn (array $row): string => $byId[(int) $row['id']] ?? '?', $rows);
}

// ---------------------------------------------------------------------------
// The buckets — membership transcribed, never computed by the code under test
// ---------------------------------------------------------------------------

test('the four buckets are disjoint and cover the team', function (): void {
    as_baseline();
    $page = as_page(as_officer());

    // Team 1, visible: nine members plus off1, off2, off3, off4 and off7.
    // off6 is purged and is in no bucket at all, which is the point of a
    // purge being a soft delete rather than a flag a screen has to remember.
    assertSame(14, (int) $page['counts']['total'], 'visible members on team 1');
    assertSame(9, (int) $page['counts']['unassigned']);
    assertSame(3, (int) $page['counts']['ineligible']);
    assertSame(2, (int) $page['counts']['assigned']);
    assertSame(
        (int) $page['counts']['total'],
        (int) $page['counts']['unassigned']
            + (int) $page['counts']['ineligible']
            + (int) $page['counts']['assigned'],
        'the buckets partition the team: no member is in two, and none in none'
    );
});

test('bucket 2 catches both ways an import breaks an assignment', function (): void {
    as_baseline();
    $page = as_page(as_officer(), ['bucket' => 'ineligible']);

    // demoted: their officer lost the title and holds no grant.
    // moved:   their officer is still an officer, on another team.
    // mixed:   one broken officer and one good one — the member is here, and
    //          the good officer is still shown as good.
    assertSame(['demoted', 'mixed', 'moved'], as_keys($page['rows']), 'ordered by name');

    $byKey = array_combine(as_keys($page['rows']), $page['rows']);

    assertSame(1, count($byKey['demoted']['officers']));
    assertTrue(!$byKey['demoted']['officers'][0]['eligible'], 'a demotion breaks the assignment');

    assertSame(1, count($byKey['moved']['officers']));
    assertTrue(!$byKey['moved']['officers'][0]['eligible'], 'moving team breaks the assignment');

    $mixed = $byKey['mixed']['officers'];
    assertSame(2, count($mixed));
    $flags = [];
    foreach ($mixed as $officer) {
        $flags[] = $officer['eligible'];
    }
    assertSame([true, false], $flags, 'off1 is still valid, off4 is not — ordered by officer name');
});

test('decided 5: never contacted first, then oldest contact, then name', function (): void {
    as_baseline();
    $page = as_page(as_officer(), ['bucket' => 'unassigned']);

    assertSame(
        ['never1', 'never2', 'off1', 'off2', 'off3', 'off4', 'off7', 'old', 'recent'],
        as_keys($page['rows']),
        'the most invisible members surface first'
    );
});

test('the other buckets order by name — they are review, not triage', function (): void {
    as_baseline();
    $page = as_page(as_officer(), ['bucket' => 'assigned']);

    assertSame(['full', 'one'], as_keys($page['rows']));
});

// ---------------------------------------------------------------------------
// The officer picker
// ---------------------------------------------------------------------------

test('the picker: title-derived in, Allowed User in, demoted out, other team out, purged out', function (): void {
    as_baseline();
    $f        = as_fixture();
    $officers = (new EligibleOfficers(as_pdo()))->forTeam($f['teams'][1], $f['year']);

    $names = array_map(static fn (array $o): string => $o['name'], $officers);
    assertSame(
        ['Member Off1', 'Member Off2', 'Member Off3', 'Member Off7'],
        $names,
        'off4 was demoted with no grant, off5 moved to team 2, off6 is purged'
    );

    // The Allowed User is in the list on the strength of the GRANT alone:
    // their title says Committee Member and every future import will keep
    // saying so, which is exactly what designation is for (spec 6.6).
    $byName = array_combine($names, $officers);
    assertSame(Level::Officer, $byName['Member Off3']['level']);
    assertSame('Committee Member', $byName['Member Off3']['title']);

    // The load is the whole load-balancing mechanism (spec 7.4): off1 holds
    // mixed, full and one; off2 and off3 hold full; off7 holds nobody.
    assertSame(3, $byName['Member Off1']['assigned_count']);
    assertSame(1, $byName['Member Off2']['assigned_count']);
    assertSame(1, $byName['Member Off3']['assigned_count']);
    assertSame(0, $byName['Member Off7']['assigned_count']);
});

// ---------------------------------------------------------------------------
// Assigning
// ---------------------------------------------------------------------------

test('assign is additive: a member accumulates officers up to the cap', function (): void {
    as_baseline();
    $f  = as_fixture();
    $me = as_officer();

    foreach (['off1', 'off2', 'off3'] as $i => $officer) {
        $result = as_apply($me, [
            'action'            => 'assign',
            'officer_member_id' => (string) $f['members'][$officer]['id'],
            'member_id'         => [(string) $f['members']['never1']['id']],
        ]);
        assertSame('assigned', $result['outcome']);
        assertSame(1, (int) $result['assigned'], "pass {$i}");
        assertSame($i + 1, count(as_current('never1')));
    }

    assertSame(
        [
            $f['members']['off1']['id'],
            $f['members']['off2']['id'],
            $f['members']['off3']['id'],
        ],
        as_current('never1')
    );
});

test('the fourth is refused, and the refusal names the three they already have', function (): void {
    as_baseline();
    $f = as_fixture();

    // 'full' arrives at the cap: off1, off2 and off3.
    $result = as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off7']['id'],
        'member_id'         => [(string) $f['members']['full']['id']],
    ]);

    assertSame('assigned', $result['outcome'], 'the action ran; this member did not');
    assertSame(0, (int) $result['assigned']);
    assertSame(1, count($result['at_cap']));
    assertSame('Member Full', $result['at_cap'][0]['name']);
    assertSame(
        ['Member Off1', 'Member Off2', 'Member Off3'],
        $result['at_cap'][0]['officers'],
        'the message names who is already on them — never a bare refusal'
    );
    assertSame(3, count(as_current('full')), 'nothing was added');
});

test('a bulk action skips the members at cap by name and still lands the rest', function (): void {
    as_baseline();
    $f = as_fixture();

    $result = as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off7']['id'],
        'member_id'         => [
            (string) $f['members']['full']['id'],
            (string) $f['members']['never1']['id'],
            (string) $f['members']['never2']['id'],
        ],
    ]);

    assertSame('assigned', $result['outcome']);
    assertSame(2, (int) $result['assigned'], 'the two under the cap landed');
    assertSame(1, count($result['at_cap']));
    assertSame('Member Full', $result['at_cap'][0]['name']);
    assertSame([$f['members']['off7']['id']], as_current('never1'));
    assertSame([$f['members']['off7']['id']], as_current('never2'));
});

test('a duplicate current assignment is a no-op, not an error', function (): void {
    as_baseline();
    $f    = as_fixture();
    $mine = ['action' => 'assign', 'officer_member_id' => (string) $f['members']['off1']['id'],
        'member_id' => [(string) $f['members']['one']['id']]];

    $result = as_apply(as_officer(), $mine);

    assertSame('assigned', $result['outcome']);
    assertSame(0, (int) $result['assigned']);
    assertSame(1, (int) $result['already']);
    assertSame(1, count(as_current('one')), 'no second live row');
});

test('decided 3: the re-point clears ONLY the broken row, in one action', function (): void {
    as_baseline();
    $f = as_fixture();

    // 'mixed' holds off1 (valid) and off4 (demoted). Assigning off2 must
    // clear off4 and leave off1 exactly where it is.
    $result = as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off2']['id'],
        'member_id'         => [(string) $f['members']['mixed']['id']],
    ]);

    assertSame('assigned', $result['outcome']);
    assertSame(1, (int) $result['assigned']);
    assertSame(1, (int) $result['repointed'], 'the broken row, and only it');

    assertSame(
        [$f['members']['off1']['id'], $f['members']['off2']['id']],
        as_current('mixed'),
        'the officer who is still valid keeps the member'
    );
    assertSame(3, as_rowCount('mixed'), 'the cleared row survives as history, never deleted');

    // And the member has left bucket 2 without any flag being written.
    $page = as_page(as_officer(), ['bucket' => 'ineligible']);
    assertSame(['demoted', 'moved'], as_keys($page['rows']));
});

test('a re-point that cannot complete touches nothing at all', function (): void {
    as_baseline();
    $f   = as_fixture();
    $pdo = as_pdo();

    // Three valid officers AND a broken one: the cap has no room for a
    // replacement, so the broken row must survive rather than leaving the
    // member with fewer officers than they started with.
    $pdo->prepare(
        'INSERT INTO assignment (member_id, officer_member_id, show_year_id, assigned_by)'
        . ' VALUES (:member, :officer, :year, :by)'
    )->execute([
        ':member'  => $f['members']['full']['id'],
        ':officer' => $f['members']['off4']['id'],
        ':year'    => $f['year'],
        ':by'      => $f['users']['off1'],
    ]);

    $result = as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off7']['id'],
        'member_id'         => [(string) $f['members']['full']['id']],
    ]);

    assertSame(0, (int) $result['assigned']);
    assertSame(0, (int) $result['repointed'], 'half a re-point is not a re-point');
    assertSame(1, count($result['at_cap']));
    assertSame(4, count(as_current('full')));
});

// ---------------------------------------------------------------------------
// The refusals the write path owes, whatever the screen offered
// ---------------------------------------------------------------------------

test('an out-of-scope member id is refused server-side, through route-shaped input', function (): void {
    as_baseline();
    $f = as_fixture();

    // The team-1 Captain naming a member of another division, exactly as a
    // hand-made POST would. Reaching the route proved their level; only the
    // per-member Subject check proves the member is theirs.
    $result = as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off1']['id'],
        'member_id'         => [(string) $f['members']['outsider']['id']],
    ]);

    assertSame('refused_all', $result['outcome']);
    assertSame(1, (int) $result['refused']);
    assertSame(0, (int) $result['assigned']);
    assertSame([], as_current('outsider'));
});

test('one out-of-scope id among many is counted and skipped; the rest still land', function (): void {
    as_baseline();
    $f = as_fixture();

    $result = as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off7']['id'],
        'member_id'         => [
            (string) $f['members']['outsider']['id'],
            (string) $f['members']['t2one']['id'],
            (string) $f['members']['never1']['id'],
            '99999999',
        ],
    ]);

    assertSame('assigned', $result['outcome']);
    assertSame(1, (int) $result['assigned']);
    // The other division, the other team in the same division, and an id that
    // does not exist — all indistinguishable in the answer, on purpose.
    assertSame(3, (int) $result['refused']);
    assertSame([], as_current('t2one'));
});

test('decided 4: a cross-team officer is refused however the request arrived', function (): void {
    as_baseline();
    $f = as_fixture();

    // The Senior Officer has team 1 AND team 2 in scope, so the member check
    // passes and the SAME-TEAM rule is the only thing standing between a
    // team-2 officer and a team-1 member.
    $result = as_apply(as_senior(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off5']['id'],
        'member_id'         => [(string) $f['members']['never1']['id']],
    ]);

    assertSame('refused_all', $result['outcome']);
    assertSame(1, (int) $result['cross_team']);
    assertSame([], as_current('never1'));

    // And mixed: the team-2 member lands, the team-1 member does not.
    $result = as_apply(as_senior(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off5']['id'],
        'member_id'         => [
            (string) $f['members']['never1']['id'],
            (string) $f['members']['t2one']['id'],
        ],
    ]);
    assertSame('assigned', $result['outcome']);
    assertSame(1, (int) $result['assigned']);
    assertSame(1, (int) $result['cross_team']);
    assertSame([$f['members']['off5']['id']], as_current('t2one'));
});

test('an ineligible or invisible target officer is refused before anything is read', function (): void {
    as_baseline();
    $f = as_fixture();

    foreach (['off4' => 'demoted, no grant', 'off6' => 'purged'] as $officer => $why) {
        $result = as_apply(as_officer(), [
            'action'            => 'assign',
            'officer_member_id' => (string) $f['members'][$officer]['id'],
            'member_id'         => [(string) $f['members']['never1']['id']],
        ]);
        assertSame('bad_officer', $result['outcome'], $why);
    }

    // A member who is not an officer at all, and an id that is nobody.
    assertSame('bad_officer', as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['never2']['id'],
        'member_id'         => [(string) $f['members']['never1']['id']],
    ])['outcome']);
    assertSame('bad_officer', as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => '99999999',
        'member_id'         => [(string) $f['members']['never1']['id']],
    ])['outcome']);

    assertSame([], as_current('never1'), 'nothing was written by any of them');
});

test('a closed show year refuses every action, read fresh on each one', function (): void {
    as_baseline();
    $f   = as_fixture();
    $pdo = as_pdo();

    $pdo->prepare('UPDATE show_year SET is_open = 0 WHERE id = :id')->execute([':id' => $f['year']]);

    try {
        foreach (
            [
                ['action' => 'assign', 'officer_member_id' => (string) $f['members']['off7']['id'],
                    'member_id' => [(string) $f['members']['never1']['id']]],
                ['action' => 'assign_all_unassigned', 'officer_member_id' => (string) $f['members']['off7']['id']],
                ['action' => 'remove', 'remove_officer_id' => (string) $f['members']['off1']['id'],
                    'member_id' => [(string) $f['members']['one']['id']]],
            ] as $input
        ) {
            assertSame('year_closed', as_apply(as_officer(), $input)['outcome'], $input['action']);
        }

        assertSame([], as_current('never1'));
        assertSame([$f['members']['off1']['id']], as_current('one'), 'the removal did not happen either');
    } finally {
        $pdo->prepare('UPDATE show_year SET is_open = 1 WHERE id = :id')->execute([':id' => $f['year']]);
    }
});

test('an oversized selection is refused rather than silently truncated', function (): void {
    as_baseline();
    $f = as_fixture();

    $ids = array_map('strval', range(1, AssignOfficers::MAX_SELECTION + 1));

    $result = as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off7']['id'],
        'member_id'         => $ids,
    ]);

    assertSame('too_many', $result['outcome']);
    assertSame(0, (int) $result['assigned']);
});

test('an action the request did not name writes nothing', function (): void {
    as_baseline();
    $f = as_fixture();

    foreach (['', 'delete', 'assign; DROP TABLE assignment'] as $action) {
        $result = as_apply(as_officer(), [
            'action'            => $action,
            'officer_member_id' => (string) $f['members']['off7']['id'],
            'member_id'         => [(string) $f['members']['never1']['id']],
        ]);
        assertSame('bad_action', $result['outcome']);
    }

    assertSame([], as_current('never1'));
});

// ---------------------------------------------------------------------------
// Removing, and re-assigning after a removal
// ---------------------------------------------------------------------------

test('remove stamps removed_at; the row survives and the history stays answerable', function (): void {
    as_baseline();
    $f = as_fixture();

    $before = as_rowCount('one');

    $result = as_apply(as_officer(), [
        'action'            => 'remove',
        'remove_officer_id' => (string) $f['members']['off1']['id'],
        'member_id'         => [(string) $f['members']['one']['id']],
    ]);

    assertSame('removed', $result['outcome']);
    assertSame(1, (int) $result['removed']);
    assertSame([], as_current('one'));
    assertSame($before, as_rowCount('one'), 'superseded, never deleted');

    $read = as_pdo()->prepare(
        'SELECT COUNT(*) FROM assignment WHERE member_id = :member AND removed_at IS NOT NULL'
    );
    $read->execute([':member' => $f['members']['one']['id']]);
    assertSame(1, (int) $read->fetchColumn());
});

test('remove-all-current takes every officer at once', function (): void {
    as_baseline();
    $f = as_fixture();

    $result = as_apply(as_officer(), [
        'action'            => 'remove',
        'remove_officer_id' => AssignOfficers::REMOVE_ALL,
        'member_id'         => [(string) $f['members']['full']['id']],
    ]);

    assertSame('removed', $result['outcome']);
    assertSame(3, (int) $result['removed']);
    assertSame([], as_current('full'));
    assertSame(3, as_rowCount('full'));
});

test('re-assigning after a removal works — the is_current key only guards live rows', function (): void {
    as_baseline();
    $f = as_fixture();

    as_apply(as_officer(), [
        'action'            => 'remove',
        'remove_officer_id' => (string) $f['members']['off1']['id'],
        'member_id'         => [(string) $f['members']['one']['id']],
    ]);

    $result = as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off1']['id'],
        'member_id'         => [(string) $f['members']['one']['id']],
    ]);

    assertSame('assigned', $result['outcome']);
    assertSame(1, (int) $result['assigned']);
    assertSame([$f['members']['off1']['id']], as_current('one'));
    assertSame(2, as_rowCount('one'), 'one live row and one removed one, behind it');
});

test('removing a member who was not assigned to that officer changes nothing', function (): void {
    as_baseline();
    $f = as_fixture();

    $result = as_apply(as_officer(), [
        'action'            => 'remove',
        'remove_officer_id' => (string) $f['members']['off7']['id'],
        'member_id'         => [(string) $f['members']['one']['id']],
    ]);

    assertSame('nothing_to_do', $result['outcome']);
    assertSame([$f['members']['off1']['id']], as_current('one'));
});

test('remove refuses an out-of-scope member the same way assign does', function (): void {
    as_baseline();
    $f = as_fixture();

    $result = as_apply(as_officer(), [
        'action'            => 'remove',
        'remove_officer_id' => AssignOfficers::REMOVE_ALL,
        'member_id'         => [(string) $f['members']['outsider']['id']],
    ]);

    assertSame('refused_all', $result['outcome']);
    assertSame(1, (int) $result['refused']);
});

// ---------------------------------------------------------------------------
// The quick action, resolved server-side
// ---------------------------------------------------------------------------

test('assign-all-unassigned resolves its own set and never crosses the team', function (): void {
    as_baseline();
    $f = as_fixture();

    $before = as_page(as_officer());
    assertSame(9, (int) $before['counts']['unassigned']);

    // No member_id[] at all: the whole point is that eighty-five ids never
    // travel through a form against max_input_vars 1000.
    $result = as_apply(as_officer(), [
        'action'            => 'assign_all_unassigned',
        'officer_member_id' => (string) $f['members']['off7']['id'],
    ]);

    assertSame('assigned', $result['outcome']);
    assertSame(9, (int) $result['assigned']);
    assertSame(9, (int) $result['officer_load']);

    $after = as_page(as_officer());
    assertSame(0, (int) $after['counts']['unassigned']);
    assertSame(3, (int) $after['counts']['ineligible'], 'bucket 2 is untouched — those are assigned');
    assertSame(11, (int) $after['counts']['assigned']);

    // Team 2 and team 3 are on other teams, and off7 is on team 1.
    assertSame([], as_current('t2one'));
    assertSame([], as_current('t3one'));

    // And a second run has nothing to do rather than something to say.
    assertSame('nothing_to_do', as_apply(as_officer(), [
        'action'            => 'assign_all_unassigned',
        'officer_member_id' => (string) $f['members']['off7']['id'],
    ])['outcome']);
});

test('assign-all-unassigned honours the scope, not just the team', function (): void {
    as_baseline();
    $f = as_fixture();

    // The Senior Officer covers division A, so the same action on a team-2
    // officer reaches team 2 and nothing else.
    $result = as_apply(as_senior(), [
        'action'            => 'assign_all_unassigned',
        'officer_member_id' => (string) $f['members']['off5']['id'],
    ]);

    assertSame('assigned', $result['outcome']);
    // Team 2's visible members: off5, t2one and the Senior Officer.
    assertSame(3, (int) $result['assigned']);
    assertSame([$f['members']['off5']['id']], as_current('t2one'));
    assertSame([], as_current('never1'), 'team 1 was not touched');
});

// ---------------------------------------------------------------------------
// Bucket 3, and the members nobody can be given
// ---------------------------------------------------------------------------

test('bucket 3: a team with no eligible officer is counted with its members', function (): void {
    as_baseline();
    $page = as_page(as_senior());

    $thin = [];
    foreach ($page['thin_teams'] as $team) {
        $thin[(string) $team['name']] = (int) $team['members'];
    }

    assertTrue(isset($thin['AS Team 03']), 'the team with no officer at all is named');
    assertSame(2, $thin['AS Team 03'], 'with the members it cannot cover');
    assertTrue(!isset($thin['AS Team 01']), 'a team with officers is not thin');
    assertTrue(!isset($thin['AS Team 02']), 'nor is team 2 — off5 moved onto it');
    assertTrue((int) $page['thin_members'] >= 2);
});

test('members on no team at all are counted rather than silently unassignable', function (): void {
    as_baseline();
    $page = as_page(as_senior());

    assertTrue((int) $page['no_team_members'] >= 1, 'the member with a NULL team is surfaced');
});

test('a team with no officer offers no picker and no way to assign', function (): void {
    as_baseline();
    $f    = as_fixture();
    $page = as_page(as_senior(), ['team' => (string) $f['teams'][3]]);

    assertSame($f['teams'][3], $page['team_id']);
    assertSame([], $page['officers'], 'nobody on this team may be assigned to');
    assertSame(2, (int) $page['counts']['unassigned']);
});

// ---------------------------------------------------------------------------
// The screen's own state
// ---------------------------------------------------------------------------

test('an Officer is pinned to their team; the team parameter is not theirs to set', function (): void {
    as_baseline();
    $f    = as_fixture();
    $page = as_page(as_officer(), ['team' => (string) $f['teams'][2]]);

    assertSame($f['teams'][1], $page['team_id'], 'their own team, whatever the URL said');
    assertTrue(!$page['can_choose_team']);
});

test('a Senior Officer picks from their scope, and an out-of-scope team is simply not a choice', function (): void {
    as_baseline();
    $f = as_fixture();

    $landing = as_page(as_senior());
    assertTrue($landing['can_choose_team']);
    assertSame(null, $landing['team_id'], 'no team chosen yet: the picker');

    $names = array_map(static fn (array $t): string => (string) $t['name'], $landing['teams']);
    sort($names);
    assertSame(['AS Team 01', 'AS Team 02', 'AS Team 03'], $names, 'team 4 is the other division');

    // Naming team 4 anyway lands on the picker, not on team 4 and not on an
    // error: an id that is not in scope is not one of the choices.
    $other = as_page(as_senior(), ['team' => (string) $f['teams'][4]]);
    assertSame(null, $other['team_id']);
});

test('page size is one of exactly two configured values, and the count line is exact', function (): void {
    as_baseline();
    $f = as_fixture();

    $page = as_page(as_officer(), ['bucket' => 'unassigned', 'size' => '7']);
    assertSame($page['size_default'], $page['size'], 'an unoffered size falls back');
    assertSame(9, (int) $page['total']);
    assertSame(1, (int) $page['from']);
    assertSame(9, (int) $page['to']);

    $large = as_page(as_officer(), ['bucket' => 'unassigned', 'size' => (string) $page['size_large']]);
    assertSame($page['size_large'], $large['size']);

    // The page number clamps to what exists rather than rendering an empty
    // page with a confident heading.
    $beyond = as_page(as_officer(), ['bucket' => 'unassigned', 'page' => '99']);
    assertSame(1, (int) $beyond['page']);
    assertSame($f['teams'][1], $beyond['team_id']);
});

test('the pre-tick state is whitelisted, never echoed', function (): void {
    as_baseline();

    assertSame('all', as_page(as_officer(), ['sel' => 'all'])['sel']);
    assertSame('outstanding', as_page(as_officer(), ['sel' => 'outstanding'])['sel']);
    assertSame('', as_page(as_officer(), ['sel' => '"><script>'])['sel']);
    assertSame('unassigned', as_page(as_officer(), ['bucket' => 'nonsense'])['bucket']);
});

// ---------------------------------------------------------------------------
// What the writes leave behind
// ---------------------------------------------------------------------------

test('every assignment and removal is audited with the actor', function (): void {
    as_baseline();
    $f   = as_fixture();
    $pdo = as_pdo();

    $pdo->prepare('DELETE FROM audit_log WHERE actor_user_id = :actor')
        ->execute([':actor' => $f['users']['off1']]);

    as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off2']['id'],
        'member_id'         => [(string) $f['members']['mixed']['id']],
    ]);

    $read = $pdo->prepare(
        'SELECT action, entity, after_json FROM audit_log WHERE actor_user_id = :actor ORDER BY id'
    );
    $read->execute([':actor' => $f['users']['off1']]);
    $rows = $read->fetchAll();

    // The re-point is two facts and both are recorded: a broken assignment
    // was cleared, and a new one was made. There is no removed_by column by
    // design — this row IS the attribution.
    $actions = array_map(static fn (array $r): string => (string) $r['action'], $rows);
    assertSame(['remove_assignment', 'assign_officer'], $actions);
    assertSame(['assignment', 'assignment'], array_map(
        static fn (array $r): string => (string) $r['entity'],
        $rows
    ));
    assertTrue(str_contains((string) $rows[0]['after_json'], 'repointed'), 'the reason is recorded');
});

test('contact_log is untouched by everything this screen does', function (): void {
    as_baseline();
    $f = as_fixture();

    $count = static function () use ($f): int {
        $read = as_pdo()->prepare(
            "SELECT COUNT(*) FROM contact_log WHERE member_id IN"
            . " (SELECT id FROM member WHERE member_number LIKE 'AS%')"
        );
        $read->execute();

        return (int) $read->fetchColumn();
    };

    $before = $count();
    assertTrue($before > 0, 'the fixture logged some contacts');

    as_apply(as_officer(), [
        'action'            => 'assign_all_unassigned',
        'officer_member_id' => (string) $f['members']['off7']['id'],
    ]);
    as_apply(as_officer(), [
        'action'            => 'remove',
        'remove_officer_id' => AssignOfficers::REMOVE_ALL,
        'member_id'         => [(string) $f['members']['full']['id']],
    ]);

    assertSame($before, $count(), 'the record of who called whom survives every assignment change');
});

test('Phase 5 comes alive: My Roster Status defaults to mine once an assignment exists', function (): void {
    as_baseline();
    $f    = as_fixture();
    $them = as_officer7();

    // off7 holds nothing at baseline — the launch state the Phase 5 test
    // covers, and the branch Phase 6's writes make real in production.
    $before = StatusPage::fromApp($GLOBALS['rerm_app'])->page($them, $f['year'], []);
    assertTrue(!$before['has_assignments']);
    assertSame('team', $before['mode']);

    as_apply(as_officer(), [
        'action'            => 'assign',
        'officer_member_id' => (string) $f['members']['off7']['id'],
        'member_id'         => [(string) $f['members']['never1']['id']],
    ]);

    $after = StatusPage::fromApp($GLOBALS['rerm_app'])->page($them, $f['year'], []);
    assertTrue($after['has_assignments']);
    assertSame('mine', $after['mode'], 'the officer lands on the people they were given');
    assertSame(1, count($after['rows']));
    assertSame($f['members']['never1']['id'], (int) $after['rows'][0]['id']);
});

test('assign fixtures are cleaned up', function (): void {
    as_teardown(as_pdo());

    $left = (int) as_pdo()->query(
        "SELECT COUNT(*) FROM member WHERE member_number LIKE 'AS%'"
    )->fetchColumn();
    assertSame(0, $left);
});
