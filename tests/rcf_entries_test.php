<?php

declare(strict_types=1);

/**
 * RCF entries and the approval flow (Phase 14, spec-v2 §15): one requested
 * change at a time, up a chain of approvers an Admin draws, onto a
 * consolidated Roster Change Form kept and tracked like every other.
 *
 * The fixture is generated and the expectations are TRANSCRIBED beside it.
 * Generated, never real: this repository is public. Member numbers here are
 * 'AF000001', addresses are @example.com and phones are the reserved
 * (555) 555-01xx fiction range.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Scope;
use Rerm\Auth\User;
use Rerm\Forms\ApprovalFlow;
use Rerm\Forms\RcfEntries;
use Rerm\Forms\RcfPage;
use Rerm\Forms\RcfStore;
use Rerm\Forms\RcfTracking;
use Rerm\Forms\RosterChangeForm;
use Rerm\Routes;

function af_app(): App
{
    return $GLOBALS['rerm_app'];
}

function af_source(string $relative): string
{
    return (string) file_get_contents(__DIR__ . '/../' . $relative);
}

// ---------------------------------------------------------------------------
// What needs no database: the matrix, the routes, the tiles, the verbs
// ---------------------------------------------------------------------------

test('the four entry routes are guarded by their own capabilities, transcribed from spec-v2 §15.2', function (): void {
    assertSame(Capability::CreateRcfEntries->value, Routes::guard('rcf-entries'));
    assertSame(Capability::ApproveRcfEntries->value, Routes::guard('rcf-approvals'));
    assertSame(Capability::GenerateConsolidatedRcf->value, Routes::guard('rcf-consolidate'));
    assertSame(Capability::ManageApprovalFlow->value, Routes::guard('approval-flow'));

    // Senior Officer / Scoped; Senior Officer / Everywhere; Senior Officer /
    // Everywhere; Admin / Everywhere — the table, row by row.
    assertSame(Level::SeniorOfficer, Capability::CreateRcfEntries->minimumLevel());
    assertSame(Scope::Scoped, Capability::CreateRcfEntries->scope());
    assertSame(Level::SeniorOfficer, Capability::ApproveRcfEntries->minimumLevel());
    assertSame(Scope::Everywhere, Capability::ApproveRcfEntries->scope());
    assertSame(Level::SeniorOfficer, Capability::GenerateConsolidatedRcf->minimumLevel());
    assertSame(Scope::Everywhere, Capability::GenerateConsolidatedRcf->scope());
    assertSame(Level::Admin, Capability::ManageApprovalFlow->minimumLevel());
    assertSame(Scope::Everywhere, Capability::ManageApprovalFlow->scope());

    // A Captain (Officer) holds none of them; a Vice Chairman (Senior
    // Officer) holds the first three and not the flow.
    $user = static fn (Level $l): User => new User(1, 1, 'AF000000', $l, null, null, false, 'T');
    foreach ([Capability::CreateRcfEntries, Capability::ApproveRcfEntries, Capability::GenerateConsolidatedRcf] as $cap) {
        assertSame(false, Access::mayUse($user(Level::Officer), $cap), $cap->value . ' is not an Officer\'s');
        assertSame(true, Access::mayUse($user(Level::SeniorOfficer), $cap), $cap->value . ' is a Senior Officer\'s');
    }
    assertSame(false, Access::mayUse($user(Level::ExecutiveOfficer), Capability::ManageApprovalFlow));
    assertSame(true, Access::mayUse($user(Level::Admin), Capability::ManageApprovalFlow));
});

test('the menu carries the three workflow tiles under Team Functions and the flow under Administer', function (): void {
    $menu = af_source('app/views/menu.php');
    foreach ([
        ['Create RCF Entries', 'rcf-entries', 'lead'],
        ['Approve RCF Entries', 'rcf-approvals', 'lead'],
        ['Consolidate RCFs', 'rcf-consolidate', 'lead'],
        ['Create Approval Flow', 'approval-flow', 'administer'],
    ] as [$label, $route, $group]) {
        $line = '';
        foreach (explode("\n", $menu) as $candidate) {
            if (str_contains($candidate, "'label' => '{$label}'")) {
                $line = $candidate;
            }
        }
        assertTrue($line !== '', "the tile {$label} exists");
        assertTrue(str_contains($line, "'route' => '{$route}'"), "{$label} links to {$route}");
        assertTrue(str_contains($line, "'group' => '{$group}'"), "{$label} is under {$group}");
    }
});

test('every hand an entry passes through is its own audit verb', function (): void {
    foreach ([
        'submit_rcf_entry' => Action::SubmitRcfEntry,
        'approve_rcf_entry' => Action::ApproveRcfEntry,
        'return_rcf_entry' => Action::ReturnRcfEntry,
        'withdraw_rcf_entry' => Action::WithdrawRcfEntry,
        'generate_consolidated_rcf' => Action::GenerateConsolidatedRcf,
        'set_approver' => Action::SetApprover,
    ] as $value => $case) {
        assertSame($value, $case->value);
        assertTrue($case->label() !== '');
    }
});

test('nothing in the flow or the entries deletes a record or writes the roster', function (): void {
    foreach (['app/src/Forms/ApprovalFlow.php', 'app/src/Forms/RcfEntries.php'] as $file) {
        $source = af_source($file);
        foreach (['rcf_entry', 'rcf_entry_step', 'rcf_approver', 'rcf', 'rcf_row', 'member', 'contact_log', 'audit_log', 'import_change'] as $table) {
            assertSame(0, preg_match('/\bDELETE\s+FROM\s+`?' . $table . '`?\b/i', $source), "{$file} must never DELETE FROM {$table}");
        }
        assertSame(0, preg_match('/\bDELETE\s+FROM\b/i', $source), "{$file} has no DELETE at all");
        assertSame(0, preg_match('/\bTRUNCATE\b|\bDROP\s+TABLE\b/i', $source));
        assertSame(0, preg_match('/(UPDATE|INSERT\s+INTO)\s+`?member`?\b/i', $source), "{$file} never writes member");
        assertSame(0, preg_match('/(UPDATE|INSERT\s+INTO)\s+`?rcf_row`?\b/i', $source), "{$file} writes a line only through RcfStore");
    }
});

test('the grid is one partial drawn by both screens, and the three workflow screens share one strip', function (): void {
    assertTrue(str_contains(af_source('app/views/form-rcf.php'), "require __DIR__ . '/rcf-grid.php'"), 'the form draws the partial');
    assertTrue(str_contains(af_source('app/views/rcf-entries.php'), "require __DIR__ . '/rcf-grid.php'"), 'and so does Create RCF Entries');
    $grid = af_source('app/views/rcf-grid.php');
    assertSame(1, substr_count($grid, '<datalist id="rcf-members">'));
    assertSame(1, substr_count($grid, '<datalist id="rcf-subcommittees">'));
    assertSame(1, substr_count($grid, '<datalist id="rcf-officers">'));

    foreach (['rcf-entries', 'rcf-approvals', 'rcf-consolidate'] as $view) {
        assertTrue(str_contains(af_source("app/views/{$view}.php"), "View::rcfEntriesNav(\$app, \$user, '{$view}'"), "{$view} carries the strip, current");
    }

    // A member on an entry links to their card from each screen, and the way
    // back is whitelisted by name.
    $index = af_source('public/index.php');
    foreach (['rcf-entries', 'rcf-approvals', 'rcf-consolidate'] as $from) {
        assertTrue(str_contains($index, "'{$from}'     => ['{$from}',") || str_contains($index, "'{$from}'   => ['{$from}',") || str_contains($index, "'{$from}' => ['{$from}',"), "member_from knows {$from}");
    }
});

test('every form that writes on the four screens carries a CSRF field', function (): void {
    foreach (['rcf-entries', 'rcf-approvals', 'rcf-consolidate', 'approval-flow'] as $view) {
        $source = af_source("app/views/{$view}.php");
        $forms  = substr_count($source, '<form method="post"');
        assertTrue($forms > 0, "{$view} has a form");
        assertSame($forms, substr_count($source, 'Csrf::field()'), "{$view}: every POST form carries the token");
    }
});

// ---------------------------------------------------------------------------
// Pure: the walk, the cut, the words
// ---------------------------------------------------------------------------

test('the walk up a flow finds the top, names a loop, and refuses an account with nothing drawn', function (): void {
    $table = [
        1 => ['approver' => 2, 'top' => false],
        2 => ['approver' => 3, 'top' => false],
        3 => ['approver' => null, 'top' => true],
        4 => ['approver' => null, 'top' => false],   // not set
        5 => ['approver' => 6, 'top' => false],
        6 => ['approver' => 5, 'top' => false],      // a loop
        7 => ['approver' => 8, 'top' => false],      // 8 has no row: the walk ends there
    ];

    assertSame(['chain' => [2, 3], 'end' => 'top'], ApprovalFlow::walk($table, 1), 'Sheila → Bob → Carl');
    assertSame(['chain' => [3], 'end' => 'top'], ApprovalFlow::walk($table, 2));
    assertSame(['chain' => [], 'end' => 'top'], ApprovalFlow::walk($table, 3), 'the top approves their own');
    assertSame(['chain' => [], 'end' => 'unset'], ApprovalFlow::walk($table, 4), 'nothing drawn: entries refused');
    assertSame(['chain' => [], 'end' => 'unset'], ApprovalFlow::walk($table, 9), 'no row at all is not set');
    assertSame('loop', ApprovalFlow::walk($table, 5)['end'], 'a circle is named');
    assertSame([6, 5], ApprovalFlow::walk($table, 5)['chain'], 'with the accounts in it');
    assertSame(['chain' => [8], 'end' => 'top'], ApprovalFlow::walk($table, 7), 'an approver with nothing above them ends the chain');
});

/** An entry as shape() would hand it, for the cut. */
function af_entry(int $id, string $division, ?int $divisionId, string $team, ?int $teamId, string $name = 'Someone Sample'): array
{
    return [
        'id'            => $id,
        'division_id'   => $divisionId,
        'division_name' => $division,
        'team_id'       => $teamId,
        'team_name'     => $team,
        'subcommittee'  => $division !== '' ? $division . ' - ' . $team : $team,
        'member_name'   => $name,
        'type'          => 'T',
    ];
}

test('the cut makes one form per division or per team, twenty-five lines each, and never prints the placeholder', function (): void {
    $entries = [];
    for ($i = 1; $i <= 51; $i++) {
        $entries[] = af_entry($i, 'AF Logistics Division', 1, $i % 2 === 0 ? 'AF Bus Ops Team A' : 'AF Bus Ops Team B', $i % 2 === 0 ? 10 : 11,
            sprintf('Member %02d', 52 - $i));
    }
    // Two more in another division, and one under the placeholder.
    $entries[] = af_entry(60, 'AF Member Services Division', 2, 'AF Greeters', 20);
    $entries[] = af_entry(61, 'AF Member Services Division', 2, 'AF Greeters', 20);
    $entries[] = af_entry(70, '', null, 'AF Orphan Team', 30);

    $byDivision = RcfEntries::cut($entries, 'division');
    assertSame(5, count($byDivision), 'fifty-one in one division is three forms; two more divisions-worth make five');
    assertSame('AF Logistics Division', $byDivision[0]['label'], 'headed with the division alone');
    assertSame(1, $byDivision[0]['division_id']);
    assertSame(null, $byDivision[0]['team_id']);
    assertSame([25, 25, 1], array_map(static fn (array $f): int => count($f['entries']), array_slice($byDivision, 0, 3)));
    assertSame('AF Member Services Division', $byDivision[3]['label']);
    assertSame(2, count($byDivision[3]['entries']));
    // The placeholder's team groups under its own name, unprefixed.
    assertSame('AF Orphan Team', $byDivision[4]['label']);
    assertSame(30, $byDivision[4]['team_id']);
    assertSame(0, substr_count(json_encode($byDivision) ?: '', 'No Division'));

    // Within a form the order is team, then name: Team A's twenty-five sort
    // first, by name.
    $first = $byDivision[0]['entries'];
    assertSame('AF Bus Ops Team A', $first[0]['team_name']);
    assertSame('Member 02', $first[0]['member_name']);
    assertSame('AF Bus Ops Team A', $first[24]['team_name']);
    assertSame('AF Bus Ops Team B', $byDivision[1]['entries'][0]['team_name'], 'Team B begins on the second form');

    // By team: Team B (26 lines, the first seen) is two forms, Team A one,
    // then the Greeters and the orphan team.
    $byTeam = RcfEntries::cut($entries, 'team');
    assertSame(5, count($byTeam), 'Team B (26: two forms) + Team A (25) + Greeters + Orphan');
    assertSame('AF Logistics Division - AF Bus Ops Team B', $byTeam[0]['label'], 'headed Division - Team');
    assertSame(11, $byTeam[0]['team_id']);
    assertSame(null, $byTeam[0]['division_id']);
    assertSame([25, 1, 25, 2, 1], array_map(static fn (array $f): int => count($f['entries']), $byTeam));
    assertSame('AF Orphan Team', $byTeam[4]['label'], 'the placeholder\'s team, unprefixed');

    assertSame(count(RcfEntries::cut($entries, 'nonsense')), count($byDivision), 'an unknown grouping is the default');
});

test('where an entry is reads as one sentence per state', function (): void {
    $base = ['current_approver_name' => 'Bob Approver', 'final_approver_name' => 'Carl Chair', 'rcf_id' => 44,
        'returned_by_name' => 'Bob Approver', 'return_note' => 'Already resigned.'];
    assertSame('Waiting on Bob Approver', RcfEntries::whereabouts($base + ['status' => 'pending']));
    assertSame('Approved by Carl Chair — awaiting a form', RcfEntries::whereabouts($base + ['status' => 'approved']));
    assertSame('On form #44', RcfEntries::whereabouts($base + ['status' => 'generated']));
    assertSame('Returned by Bob Approver: Already resigned.', RcfEntries::whereabouts($base + ['status' => 'returned']));
    assertSame('Withdrawn', RcfEntries::whereabouts($base + ['status' => 'withdrawn']));
});

// ---------------------------------------------------------------------------
// The database under test
// ---------------------------------------------------------------------------

function af_pdo(): PDO
{
    static $pdo = null;
    static $failure = null;

    if ($failure !== null) {
        skip($failure);
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = af_app()->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    return $pdo;
}

function af_teardown(PDO $pdo): void
{
    $members = "SELECT id FROM member WHERE member_number LIKE 'AF%'";
    $users   = "SELECT id FROM app_user WHERE member_id IN ({$members})";
    $forms   = "SELECT id FROM rcf WHERE generated_by IN ({$users})";
    $entries = "SELECT id FROM rcf_entry WHERE requested_by IN ({$users})";

    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
    $pdo->exec("DELETE FROM rcf_entry_step WHERE entry_id IN ({$entries})");
    $pdo->exec("DELETE FROM rcf_entry WHERE requested_by IN ({$users})");
    $pdo->exec("DELETE FROM rcf_approver WHERE app_user_id IN ({$users})");
    $pdo->exec("DELETE FROM rcf_row WHERE rcf_id IN ({$forms})");
    $pdo->exec("DELETE FROM rcf WHERE generated_by IN ({$users})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'AF%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'AF %'");
    $pdo->exec("DELETE FROM division WHERE name LIKE 'AF %'");
}

/**
 * One division, two teams, and five people with accounts:
 *
 *   AF Logistics Division   AF Bus Ops Team A   cap (Captain, Officer), m1, m2
 *                           AF Bus Ops Team B   vc (Vice Chairman, Senior Officer), m3
 *   dvc — the Division Vice Chairman, a Senior Officer scoped to the division, on Team A
 *   dc  — the Division Chairman, an Executive Officer, on Team A
 *   adm — a Committee Member granted Admin
 *
 * The chain the tests draw is vc → dvc → dc, with dc at the top.
 *
 * @return array<string, mixed>
 */
function af_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = af_pdo();
    af_teardown($pdo);

    $pdo->prepare('INSERT INTO division (name, is_placeholder) VALUES (:name, 0)')->execute([':name' => 'AF Logistics Division']);
    $division = (int) $pdo->lastInsertId();

    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)');
    $teams      = [];
    foreach (['a' => 'AF Bus Ops Team A', 'b' => 'AF Bus Ops Team B'] as $key => $name) {
        $insertTeam->execute([':name' => $name, ':division' => $division]);
        $teams[$key] = (int) $pdo->lastInsertId();
    }

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, full_name,'
        . ' division_id, team_id, phone, phone_e164, phone_type, email, title, title_level)'
        . " VALUES (:number, :first, :last, '', :full, :division, :team,"
        . " '(555) 555-0131', '+15555550131', 'CELL PHONE', :email, :title, :level)"
    );
    $specs = [
        'cap' => ['a', 'Alexis', 'Captain',   'Captain',                 'officer'],
        'vc'  => ['b', 'Erin',   'Delta',     'Vice Chairman',           'senior_officer'],
        'dvc' => ['a', 'Bob',    'Approver',  'Division Vice Chairman',  'senior_officer'],
        'dc'  => ['a', 'Carl',   'Chair',     'Division Chairman',       'executive_officer'],
        'adm' => ['a', 'Ada',    'Admin',     'Committee Member',        'member'],
        'm1'  => ['a', 'Robert', 'Alpha',     'Committee Member',        'member'],
        'm2'  => ['a', 'Carol',  'Bravo',     'Committee Member',        'member'],
        'm3'  => ['b', 'Frank',  'Echo <b>',  'Committee Member',        'member'],
    ];
    $members = [];
    $n       = 0;
    foreach ($specs as $key => [$team, $first, $last, $title, $level]) {
        $n++;
        $number = sprintf('AF%06d', $n);
        $insertMember->execute([
            ':number' => $number, ':first' => $first, ':last' => $last, ':full' => $first . ' ' . $last,
            ':division' => $division, ':team' => $teams[$team],
            ':email' => strtolower($first) . '@example.com', ':title' => $title, ':level' => $level,
        ]);
        $members[$key] = ['id' => (int) $pdo->lastInsertId(), 'number' => $number];
    }

    $insertUser = $pdo->prepare(
        "INSERT INTO app_user (member_id, level, granted_level, password_hash, must_change_password, is_active)"
        . " VALUES (:m, :l, :g, '*', 0, 1)"
    );
    $users = [];
    foreach (['cap' => ['officer', null], 'vc' => ['senior_officer', null], 'dvc' => ['senior_officer', null],
        'dc' => ['executive_officer', null], 'adm' => ['member', 'admin']] as $key => [$level, $granted]) {
        $insertUser->execute([':m' => $members[$key]['id'], ':l' => $level, ':g' => $granted]);
        $users[$key] = (int) $pdo->lastInsertId();
    }

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();

    return $fixture = ['division' => $division, 'teams' => $teams, 'members' => $members, 'users' => $users, 'year' => $year];
}

function af_user(string $key): User
{
    $f     = af_fixture();
    $level = match ($key) {
        'cap'      => Level::Officer,
        'vc', 'dvc' => Level::SeniorOfficer,
        'dc'       => Level::ExecutiveOfficer,
        default    => Level::Admin,
    };
    $team = $key === 'vc' ? $f['teams']['b'] : $f['teams']['a'];

    return new User(
        id: $f['users'][$key],
        memberId: $f['members'][$key]['id'],
        memberNumber: $f['members'][$key]['number'],
        level: $level,
        scopeDivisionId: $f['division'],
        scopeTeamId: $team,
        mustChangePassword: false,
        displayName: $key,
        // A Vice Chairman sees one team (CLAUDE.md); the Division Vice
        // Chairman sees the division.
        scopeTeamIds: $key === 'vc' ? [$team] : [],
    );
}

function af_flow(): ApprovalFlow
{
    return new ApprovalFlow(af_pdo());
}

function af_entries(): RcfEntries
{
    return new RcfEntries(af_pdo(), af_flow(), af_app()->displayTimezone());
}

/** The Vice Chairman's grid: a title change for m3 and an addition typed in. */
function af_vc_form(array $rows = null): array
{
    $f = af_fixture();

    return (new RcfPage(af_pdo()))->formFromInput(af_user('vc'), [
        'subcommittee' => 't:' . $f['teams']['b'],
        'row'          => $rows ?? [
            0 => ['type' => 'T', 'member' => $f['members']['m3']['number'], 'new_title' => 'Assistant Captain'],
            1 => ['type' => 'A', 'member' => 'Newcomer Sample', 'sponsor' => 'Erin Delta', 'rookie' => '1', 'wait_list' => '1'],
        ],
    ]);
}

function af_reset_entries(): void
{
    $f     = af_fixture();
    $pdo   = af_pdo();
    $users = implode(', ', array_map('intval', $f['users']));
    $pdo->exec("DELETE FROM rcf_entry_step WHERE entry_id IN (SELECT id FROM rcf_entry WHERE requested_by IN ({$users}))");
    $pdo->exec("DELETE FROM rcf_entry WHERE requested_by IN ({$users})");
    $pdo->exec("DELETE FROM rcf_row WHERE rcf_id IN (SELECT id FROM rcf WHERE generated_by IN ({$users}))");
    $pdo->exec("DELETE FROM rcf WHERE generated_by IN ({$users})");
}

function af_audit(string $key, Action $action): array
{
    $read = af_pdo()->prepare('SELECT * FROM audit_log WHERE actor_user_id = :a AND action = :act ORDER BY id');
    $read->execute([':a' => af_fixture()['users'][$key], ':act' => $action->value]);

    return $read->fetchAll();
}

// ---------------------------------------------------------------------------
// The flow
// ---------------------------------------------------------------------------

test('an account with nothing drawn has its entries refused, naming the screen', function (): void {
    af_fixture();
    af_reset_entries();

    $result = af_entries()->submit(af_user('vc'), af_fixture()['year'], af_vc_form());
    assertSame([], $result['created']);
    assertSame('unset', $result['state']);
    assertTrue(str_contains((string) $result['refused'], 'Create Approval Flow'));
    $read = af_pdo()->prepare('SELECT COUNT(*) FROM rcf_entry WHERE requested_by = :me');
    $read->execute([':me' => af_fixture()['users']['vc']]);
    assertSame(0, (int) $read->fetchColumn(), 'nothing was written');
});

test('an Admin draws the chain vc → dvc → dc, a loop is refused by name with nothing written, and every save is audited', function (): void {
    $f    = af_fixture();
    $flow = af_flow();

    // Only Senior Officer and above are listed, active: the Captain is not.
    $ids = array_map(static fn (array $a): int => (int) $a['id'], $flow->accounts());
    assertTrue(!in_array($f['users']['cap'], $ids, true), 'a Captain is not in the flow');
    foreach (['vc', 'dvc', 'dc', 'adm'] as $key) {
        assertTrue(in_array($f['users'][$key], $ids, true), "{$key} is listed");
    }

    $result = $flow->save(af_user('adm'), ['approver' => [
        (string) $f['users']['vc']  => (string) $f['users']['dvc'],
        (string) $f['users']['dvc'] => (string) $f['users']['dc'],
        (string) $f['users']['dc']  => ApprovalFlow::TOP,
    ]]);
    assertSame(['saved' => 3, 'refused' => null], $result);

    // The chain reads in names beside the row.
    $accounts = [];
    foreach ($flow->accounts() as $account) {
        $accounts[(int) $account['id']] = $account;
    }
    assertSame(['Bob Approver', 'Carl Chair'], $accounts[$f['users']['vc']]['chain']);
    assertSame('top', $accounts[$f['users']['vc']]['chain_end']);
    assertSame('approver', $accounts[$f['users']['vc']]['state']);
    assertSame('top', $accounts[$f['users']['dc']]['state']);
    assertSame([], $accounts[$f['users']['dc']]['chain']);
    assertSame('unset', $accounts[$f['users']['adm']]['state'], 'the Admin has nothing drawn');

    // Routing reads the same table; an Admin with no row is the top.
    assertSame(['state' => 'approver', 'approver' => $f['users']['dvc']], $flow->routeFor(af_user('vc')));
    assertSame(['state' => 'top', 'approver' => null], $flow->routeFor(af_user('dc')));
    assertSame(['state' => 'top', 'approver' => null], $flow->routeFor(af_user('adm')));

    // dc → vc would close the circle: refused, named, nothing written.
    $refused = $flow->save(af_user('adm'), ['approver' => [(string) $f['users']['dc'] => (string) $f['users']['vc']]]);
    assertSame(0, $refused['saved']);
    assertTrue(str_contains((string) $refused['refused'], 'loop'));
    assertTrue(str_contains((string) $refused['refused'], 'Carl Chair') && str_contains((string) $refused['refused'], 'Erin Delta'));
    assertSame('top', $flow->routeFor(af_user('dc'))['state'], 'dc is still the top');

    // Self, an unlisted account and an unchanged value all write nothing.
    assertSame(0, $flow->save(af_user('adm'), ['approver' => [(string) $f['users']['vc'] => (string) $f['users']['vc']]])['saved']);
    assertSame(0, $flow->save(af_user('adm'), ['approver' => [(string) $f['users']['vc'] => (string) $f['users']['cap']]])['saved']);
    assertSame(0, $flow->save(af_user('adm'), ['approver' => [(string) $f['users']['vc'] => (string) $f['users']['dvc']]])['saved']);

    $audit = af_audit('adm', Action::SetApprover);
    assertSame(3, count($audit), 'one row per account drawn, none for the refusals or the no-ops');
    $after = json_decode((string) $audit[0]['after_json'], true);
    assertSame('approver', $after['state']);
    assertSame('Bob Approver', $after['approver_name']);
    assertSame('unset', json_decode((string) $audit[0]['before_json'], true)['state']);

    // Nothing was deleted to get there: every drawn account has a row.
    assertSame(3, (int) af_pdo()->query('SELECT COUNT(*) FROM rcf_approver WHERE app_user_id IN (' . implode(',', [$f['users']['vc'], $f['users']['dvc'], $f['users']['dc']]) . ')')->fetchColumn());
});

// ---------------------------------------------------------------------------
// An entry's life
// ---------------------------------------------------------------------------

test('submitting makes one entry per filled row, resolved like a line of a form, waiting on the first approver', function (): void {
    $f = af_fixture();
    af_reset_entries();
    $entries = af_entries();

    $result = $entries->submit(af_user('vc'), $f['year'], af_vc_form());
    assertSame(null, $result['refused']);
    assertSame(2, count($result['created']));
    assertSame('approver', $result['state']);
    assertSame('Bob Approver', $result['approver']['name']);

    $mine = $entries->mine(af_user('vc'));
    assertSame(2, count($mine));
    // Newest first: the addition was inserted second.
    [$add, $title] = $mine;
    assertSame('A', $add['type']);
    assertSame('Newcomer Sample', $add['member_name']);
    assertSame('', $add['member_number']);
    assertSame(null, $add['member_id']);
    assertTrue($add['rookie'] && $add['wait_list']);
    assertSame('Erin Delta', $add['sponsor']);
    assertSame('T', $title['type']);
    assertSame($f['members']['m3']['id'], $title['member_id'], 'the number resolved to the member');
    assertSame('Frank Echo <b>', $title['member_name'], 'the name as the roster spells it, unescaped in the record');
    assertSame('Committee Member', $title['previous_title'], 'filled in from the roster, as on the form');
    assertSame('Assistant Captain', $title['new_title']);
    assertSame('AF Logistics Division - AF Bus Ops Team B', $title['subcommittee']);
    assertSame($f['teams']['b'], $title['team_id']);
    assertSame('pending', $title['status']);
    assertSame($f['users']['dvc'], $title['current_approver_id']);
    assertSame('Waiting on Bob Approver', $title['where']);
    assertSame('Committee Member', $title['was']);
    assertSame('Assistant Captain', $title['becomes']);
    assertSame(1, count($title['steps']));
    assertSame('submitted', $title['steps'][0]['action']);
    assertSame('Erin Delta', $title['steps'][0]['actor_name']);

    assertSame(['mine' => 2, 'waiting' => 0, 'ready' => 0], $entries->counts(af_user('vc')));
    assertSame(['mine' => 0, 'waiting' => 2, 'ready' => 0], $entries->counts(af_user('dvc')));
    assertSame(['mine' => 0, 'waiting' => 0, 'ready' => 0], $entries->counts(af_user('dc')));

    $audit = af_audit('vc', Action::SubmitRcfEntry);
    assertSame(2, count($audit));
    assertSame('pending', json_decode((string) $audit[0]['after_json'], true)['status']);

    // Blank grid and no sub-committee are refused in words, writing nothing.
    assertTrue(str_contains((string) $entries->submit(af_user('vc'), $f['year'], af_vc_form([]))['refused'], 'Nothing is filled in'));
    $noTeam = (new RcfPage(af_pdo()))->formFromInput(af_user('vc'), ['row' => [0 => ['type' => 'R', 'member' => 'Somebody']]]);
    assertTrue(str_contains((string) $entries->submit(af_user('vc'), $f['year'], $noTeam)['refused'], 'sub-committee'));
    assertSame(2, count($entries->mine(af_user('vc'))));
});

test('approving moves an entry up the chain and the last approver approves it outright; a stranger approves nothing', function (): void {
    $f       = af_fixture();
    $entries = af_entries();
    $ids     = array_map(static fn (array $e): int => (int) $e['id'], $entries->mine(af_user('vc')));

    // Waiting on the Division Vice Chairman, who sees where it goes next.
    $waiting = $entries->waitingFor(af_user('dvc'));
    assertSame(2, count($waiting));
    assertSame('Carl Chair', $waiting[0]['next_approver']['name']);
    assertSame([], $entries->waitingFor(af_user('dc')), 'nothing has reached the Division Chairman yet');

    // dc cannot approve what is not waiting on them.
    assertSame(['advanced' => 0, 'approved' => 0], $entries->approve(af_user('dc'), $ids));

    assertSame(['advanced' => 2, 'approved' => 0], $entries->approve(af_user('dvc'), $ids));
    assertSame([], $entries->waitingFor(af_user('dvc')));
    $atDc = $entries->waitingFor(af_user('dc'));
    assertSame(2, count($atDc));
    assertSame(null, $atDc[0]['next_approver'], 'the top of the chain: then ready to generate');
    assertSame('Waiting on Carl Chair', $atDc[0]['where']);
    assertSame(2, count($atDc[0]['steps']));
    assertSame('approved', $atDc[0]['steps'][1]['action']);
    assertSame('Bob Approver', $atDc[0]['steps'][1]['actor_name']);

    // Approving again as dvc writes nothing: it is not waiting on them now.
    assertSame(['advanced' => 0, 'approved' => 0], $entries->approve(af_user('dvc'), $ids));

    assertSame(['advanced' => 0, 'approved' => 2], $entries->approve(af_user('dc'), $ids));
    $ready = $entries->readyFor(af_user('dc'));
    assertSame(2, count($ready));
    assertSame('approved', $ready[0]['status']);
    assertSame($f['users']['dc'], $ready[0]['final_approver_id']);
    assertSame('Approved by Carl Chair — awaiting a form', $ready[0]['where']);
    assertSame([], $entries->readyFor(af_user('dvc')), 'it did not end with the Division Vice Chairman');
    assertSame(2, count($entries->readyFor(af_user('adm'))), 'an Admin generates every approved entry');

    assertSame(['mine' => 2, 'waiting' => 0, 'ready' => 0], $entries->counts(af_user('vc')));
    assertSame(['mine' => 0, 'waiting' => 0, 'ready' => 2], $entries->counts(af_user('dc')));

    // Decided by: both approvers see the two, with where each is now.
    assertSame(2, count($entries->decidedBy(af_user('dvc'))));
    assertSame('approved', $entries->decidedBy(af_user('dvc'))[0]['status']);

    assertSame(2, count(af_audit('dvc', Action::ApproveRcfEntry)));
    assertSame($f['users']['dc'], json_decode((string) af_audit('dvc', Action::ApproveRcfEntry)[0]['after_json'], true)['waiting_on']);
    assertSame('approved', json_decode((string) af_audit('dc', Action::ApproveRcfEntry)[0]['after_json'], true)['status']);
});

test('a return needs a note and ends the entry with it; a withdrawal is the requester\'s alone, while it is pending', function (): void {
    $f       = af_fixture();
    $entries = af_entries();

    $created = $entries->submit(af_user('vc'), $f['year'], af_vc_form([
        0 => ['type' => 'R', 'member' => $f['members']['m3']['number'], 'remove_reason' => '4'],
        1 => ['type' => 'R', 'member' => 'Gone Sample', 'remove_reason' => '1'],
    ]))['created'];
    [$first, $second] = $created;

    assertSame(null, $entries->sendBack(af_user('dvc'), [$first], '   '), 'no note, no return');
    assertSame(0, $entries->sendBack(af_user('dc'), [$first], 'Not mine to say.'), 'not waiting on dc');
    assertSame(1, $entries->sendBack(af_user('dvc'), [$first], "Already  resigned in\nMarch; no form needed."));

    $mine = [];
    foreach ($entries->mine(af_user('vc')) as $entry) {
        $mine[(int) $entry['id']] = $entry;
    }
    assertSame('returned', $mine[$first]['status']);
    assertSame('Already resigned in March; no form needed.', $mine[$first]['return_note'], 'collapsed and trimmed');
    assertSame('Returned by Bob Approver: Already resigned in March; no form needed.', $mine[$first]['where']);
    assertSame('returned', $mine[$first]['steps'][1]['action']);
    assertSame(null, $mine[$first]['current_approver_id']);

    // Only the requester withdraws, and only a pending one.
    assertSame(false, $entries->withdraw(af_user('dvc'), $second), 'the approver cannot withdraw it');
    assertSame(false, $entries->withdraw(af_user('vc'), $first), 'a returned one is already decided');
    assertSame(true, $entries->withdraw(af_user('vc'), $second));
    assertSame(false, $entries->withdraw(af_user('vc'), $second), 'twice writes nothing');
    $statuses = [];
    foreach ($entries->mine(af_user('vc')) as $entry) {
        $statuses[(int) $entry['id']] = $entry['status'];
    }
    assertSame('withdrawn', $statuses[$second]);
    assertSame('returned', $statuses[$first]);
    assertSame([], $entries->waitingFor(af_user('dvc')), 'neither waits on anybody now');

    assertSame(1, count(af_audit('dvc', Action::ReturnRcfEntry)));
    assertSame(1, count(af_audit('vc', Action::WithdrawRcfEntry)));

    // Nothing was deleted: every entry ever submitted by vc is still there.
    $count = af_pdo()->prepare('SELECT COUNT(*) FROM rcf_entry WHERE requested_by = :me');
    $count->execute([':me' => $f['users']['vc']]);
    assertSame(4, (int) $count->fetchColumn());
});

test('the top of the chain is approved on submission, with a step that says so', function (): void {
    $f       = af_fixture();
    $entries = af_entries();

    $result = $entries->submit(af_user('dc'), $f['year'], (new RcfPage(af_pdo()))->formFromInput(af_user('dc'), [
        'subcommittee' => 't:' . $f['teams']['a'],
        'row'          => [0 => ['type' => 'T', 'member' => $f['members']['m1']['number'], 'new_title' => 'Captain']],
    ]));
    assertSame('top', $result['state']);
    assertSame(1, count($result['created']));

    $own = $entries->mine(af_user('dc'))[0];
    assertSame('approved', $own['status']);
    assertSame($f['users']['dc'], $own['final_approver_id']);
    assertSame(['submitted', 'approved'], array_map(static fn (array $s): string => $s['action'], $own['steps']));
    assertTrue(str_contains($own['steps'][1]['note'], 'Top of the chain'));
    assertSame(3, count($entries->readyFor(af_user('dc'))), 'with the two the Vice Chairman asked for');
});

test('who may see an entry: the requester, the chain, the final approver, and an Executive Officer; a Captain sees nothing', function (): void {
    $entries = af_entries();

    $vc  = $entries->pendingFor(af_user('vc'));
    assertSame(2, count($vc), 'the two approved ones; returned and withdrawn are not in flight');
    foreach ($vc as $entry) {
        assertSame(true, $entry['viewable']);
    }
    assertSame(2, count($entries->pendingFor(af_user('dvc'))), 'passed through the Division Vice Chairman');
    assertSame(3, count($entries->pendingFor(af_user('dc'))), 'the Division Chairman sees everything: view_all_forms');
    assertSame(3, count($entries->pendingFor(af_user('adm'))));
    assertSame([], $entries->pendingFor(af_user('cap')), 'a Captain is on no chain');

    $entry = ['requested_by' => 5, 'current_approver_id' => 6, 'final_approver_id' => null, 'actors' => [5, 7]];
    $user  = static fn (int $id, Level $l): User => new User($id, 1, 'AF000000', $l, null, null, false, 'T');
    assertSame(true, RcfEntries::mayView($user(5, Level::SeniorOfficer), $entry), 'the requester');
    assertSame(true, RcfEntries::mayView($user(6, Level::SeniorOfficer), $entry), 'waiting on them');
    assertSame(true, RcfEntries::mayView($user(7, Level::SeniorOfficer), $entry), 'acted on it');
    assertSame(false, RcfEntries::mayView($user(8, Level::SeniorOfficer), $entry), 'a stranger');
    assertSame(true, RcfEntries::mayView($user(8, Level::ExecutiveOfficer), $entry), 'view_all_forms');
});

// ---------------------------------------------------------------------------
// Generating
// ---------------------------------------------------------------------------

test('generating keeps one consolidated form per division from the ticked entries, marks them, and the form regenerates', function (): void {
    $f       = af_fixture();
    $pdo     = af_pdo();
    $entries = af_entries();
    $store   = new RcfStore($pdo);

    $ready = $entries->readyFor(af_user('dc'));
    assertSame(3, count($ready));
    $ids = array_map(static fn (array $e): int => (int) $e['id'], $ready);

    // The Division Vice Chairman has nothing to generate, whatever they tick.
    assertSame(['forms' => [], 'entries' => 0], $entries->generate(af_user('dvc'), $f['year'], $ids, 'division', $store));

    // Two teams, one division: by division is ONE form.
    $result = $entries->generate(af_user('dc'), $f['year'], $ids, 'division', $store);
    assertSame(1, count($result['forms']));
    assertSame(3, $result['entries']);
    $rcfId = $result['forms'][0];

    $form = $pdo->prepare('SELECT * FROM rcf WHERE id = :id');
    $form->execute([':id' => $rcfId]);
    $kept = $form->fetch();
    assertSame('consolidated', $kept['source']);
    assertSame('AF Logistics Division', $kept['subcommittee'], 'headed with the division');
    assertSame($f['division'], (int) $kept['division_id']);
    assertSame(null, $kept['team_id']);
    assertSame('Carl Chair, Division Chairman', $kept['submitter'], 'the generator, Name, Title');
    assertSame($f['members']['dc']['number'], $kept['submitter_number']);
    assertSame(3, (int) $kept['row_count']);
    assertSame((new DateTimeImmutable('today', af_app()->displayTimezone()))->format('Y-m-d'), $kept['form_date']);

    // The lines, in team-then-name order: Team A's Robert Alpha, then Team
    // B's Frank Echo and Newcomer Sample.
    $rows = $pdo->prepare('SELECT * FROM rcf_row WHERE rcf_id = :id ORDER BY position');
    $rows->execute([':id' => $rcfId]);
    $lines = $rows->fetchAll();
    assertSame([1, 2, 3], array_map(static fn (array $l): int => (int) $l['position'], $lines));
    assertSame(['Robert Alpha', 'Frank Echo <b>', 'Newcomer Sample'], array_map(static fn (array $l): string => $l['member_name'], $lines));
    assertSame(['T', 'T', 'A'], array_map(static fn (array $l): string => $l['type'], $lines));
    assertSame(1, (int) $lines[2]['rookie']);
    assertSame(1, (int) $lines[2]['wait_list']);
    assertSame($f['members']['m3']['id'], (int) $lines[1]['member_id']);

    // Every entry taken is on the form, pointing at its own line.
    $taken = $pdo->prepare('SELECT id, status, rcf_id, rcf_row_id, member_name FROM rcf_entry WHERE rcf_id = :id ORDER BY rcf_row_id');
    $taken->execute([':id' => $rcfId]);
    $marked = $taken->fetchAll();
    assertSame(3, count($marked));
    foreach ($marked as $i => $m) {
        assertSame('generated', $m['status']);
        assertSame((int) $lines[$i]['id'], (int) $m['rcf_row_id']);
        assertSame($lines[$i]['member_name'], $m['member_name']);
    }
    assertSame([], $entries->readyFor(af_user('dc')), 'nothing left to generate');
    assertSame(['mine' => 0, 'waiting' => 0, 'ready' => 0], $entries->counts(af_user('dc')));
    // The Vice Chairman's own list says which form each of their two
    // approved entries landed on (the other two are returned and withdrawn).
    $onForms = array_filter($entries->mine(af_user('vc')), static fn (array $e): bool => $e['where'] === 'On form #' . $rcfId);
    assertSame(2, count($onForms), 'both of the Vice Chairman\'s approved entries say which form they are on');
    $onForm = $entries->onForm(af_user('dc'), $rcfId);
    assertSame(3, count($onForm));
    assertSame('generated', $onForm[0]['steps'][count($onForm[0]['steps']) - 1]['action']);

    // The form is an ordinary kept form: tracked, listed, regenerable.
    $tracking = new RcfTracking($pdo);
    $one      = $tracking->one(af_user('dc'), $rcfId);
    assertSame('consolidated', $one['form']['source']);
    assertSame(3, $one['form']['rows']);
    assertSame(1, count(array_filter($tracking->mine(af_user('dc')), static fn (array $frm): bool => (int) $frm['id'] === $rcfId)));
    $built = $store->form($rcfId);
    assertSame('AF Logistics Division', $built['subcommittee']);
    assertSame('Frank Echo <b>', $built['entries'][1]['member_name']);
    assertSame(RosterChangeForm::TICKED, $built['entries'][2]['rookie']);
    assertSame(RosterChangeForm::UNTICKED, $built['entries'][5]['rookie'], 'a blank row carries an unticked box');

    $forms = $entries->generatedForms(af_user('dc'));
    assertSame(1, count($forms));
    assertSame(3, $forms[0]['entries']);
    assertSame([], $entries->generatedForms(af_user('vc')));

    $audit = af_audit('dc', Action::GenerateConsolidatedRcf);
    assertSame(1, count($audit));
    $after = json_decode((string) $audit[0]['after_json'], true);
    assertSame($rcfId, $after['rcf_id']);
    assertSame(3, $after['rows']);
    assertSame(sort($ids) ? $ids : $ids, (static function (array $a): array { sort($a); return $a; })($after['entries']));
    assertSame('division', $after['grouped_by']);

    // Generating again with the same ids writes nothing: they are generated.
    assertSame(['forms' => [], 'entries' => 0], $entries->generate(af_user('dc'), $f['year'], $ids, 'division', $store));
});

test('by team the same entries are two forms, each headed Division - Team', function (): void {
    $f       = af_fixture();
    $pdo     = af_pdo();
    $entries = af_entries();
    $store   = new RcfStore($pdo);

    // Three more entries at the top of the chain, two teams.
    $page = new RcfPage($pdo);
    $entries->submit(af_user('dc'), $f['year'], $page->formFromInput(af_user('dc'), [
        'subcommittee' => 't:' . $f['teams']['a'],
        'row'          => [0 => ['type' => 'R', 'member' => $f['members']['m2']['number'], 'remove_reason' => '5']],
    ]));
    $entries->submit(af_user('dc'), $f['year'], $page->formFromInput(af_user('dc'), [
        'subcommittee' => 't:' . $f['teams']['b'],
        'row'          => [
            0 => ['type' => 'A', 'member' => 'Second Newcomer', 'sponsor' => 'Carl Chair'],
            1 => ['type' => 'A', 'member' => 'Third Newcomer', 'sponsor' => 'Carl Chair'],
        ],
    ]));
    $ids = array_map(static fn (array $e): int => (int) $e['id'], $entries->readyFor(af_user('dc')));
    assertSame(3, count($ids));

    // Only two ticked: the untick is honoured.
    $result = $entries->generate(af_user('dc'), $f['year'], array_slice($ids, 0, 2), 'team', $store);
    assertSame(2, count($result['forms']), 'Team A and Team B');
    assertSame(2, $result['entries']);
    $read = $pdo->prepare('SELECT subcommittee, team_id, division_id FROM rcf WHERE id IN (:a, :b) ORDER BY id');
    $read->execute([':a' => $result['forms'][0], ':b' => $result['forms'][1]]);
    $kept = $read->fetchAll();
    assertSame('AF Logistics Division - AF Bus Ops Team A', $kept[0]['subcommittee']);
    assertSame($f['teams']['a'], (int) $kept[0]['team_id']);
    assertSame(null, $kept[0]['division_id']);
    assertSame('AF Logistics Division - AF Bus Ops Team B', $kept[1]['subcommittee']);
    assertSame(1, count($entries->readyFor(af_user('dc'))), 'the unticked one is still ready');
});

// ---------------------------------------------------------------------------
// The screens
// ---------------------------------------------------------------------------

function af_render(string $view, string $title, array $data): string
{
    $app = af_app();
    $_SESSION ??= [];
    $wide    = true;
    $notices = [];
    extract($data, EXTR_SKIP);

    ob_start();
    require $app->path('app/views/' . $view . '.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

test('the four screens render, escape what they show, carry the strip and stay inside the byte budget', function (): void {
    $f       = af_fixture();
    $pdo     = af_pdo();
    $entries = af_entries();
    $vc      = af_user('vc');

    // One more pending entry so every list has something in it.
    $entries->submit($vc, $f['year'], af_vc_form([0 => ['type' => 'T', 'member' => $f['members']['m3']['number'], 'new_title' => 'Captain']]));

    $html = af_render('rcf-entries', 'Create RCF Entries', [
        'user'   => $vc,
        'rcf'    => (new RcfPage($pdo))->page($vc, ['subcommittee' => 't:' . $f['teams']['b']]),
        'mine'   => $entries->mine($vc),
        'counts' => $entries->counts($vc),
        'route'  => $entries->routeFor($vc),
    ]);
    assertTrue(str_contains($html, '<h1>Create RCF Entries</h1>'));
    assertTrue(str_contains($html, 'waits on <strong>Bob Approver</strong>'), 'the route in a sentence');
    assertSame(1, substr_count($html, '<datalist id="rcf-members">'), 'the shared grid, once');
    assertTrue(str_contains($html, 'value="submit"'), 'the submit button');
    assertTrue(str_contains($html, 'Frank Echo &lt;b&gt;'), 'a member name with markup is escaped');
    assertSame(0, substr_count($html, 'Echo <b>'), 'and never raw');
    assertTrue(str_contains($html, 'Returned by Bob Approver'), 'the returned one carries its note');
    assertTrue(str_contains($html, 'value="withdraw"'), 'a pending one can be withdrawn');
    assertTrue(str_contains($html, 'aria-label="RCF entries"'), 'the strip');
    assertSame(0, substr_count($html, '<script'));
    assertTrue(strlen($html) < 100 * 1024, 'Create RCF Entries is ' . strlen($html) . ' bytes');

    // Nothing drawn: no grid, the sentence instead.
    $cap = af_render('rcf-entries', 'Create RCF Entries', [
        'user'   => af_user('adm'),
        'rcf'    => (new RcfPage($pdo))->page(af_user('adm'), []),
        'mine'   => [],
        'counts' => ['mine' => 0, 'waiting' => 0, 'ready' => 0],
        'route'  => ['state' => 'unset', 'approver' => null],
    ]);
    assertTrue(str_contains($cap, 'Nobody is set as your approver yet'));
    assertSame(0, substr_count($cap, 'value="submit"'), 'no submit when entries would be refused');

    $dvc  = af_user('dvc');
    $html = af_render('rcf-approvals', 'Approve RCF Entries', [
        'user'    => $dvc,
        'waiting' => $entries->waitingFor($dvc),
        'decided' => $entries->decidedBy($dvc),
        'counts'  => $entries->counts($dvc),
    ]);
    assertTrue(str_contains($html, '<h1>Approve RCF Entries</h1>'));
    assertSame(1, substr_count($html, 'name="ids[]"'), 'one tick box per waiting entry');
    assertTrue(str_contains($html, 'then to Carl Chair'), 'where it goes next');
    assertTrue(str_contains($html, 'name="note"'), 'the return note');
    assertTrue(str_contains($html, 'Decided by you'));
    assertTrue(str_contains($html, 'Frank Echo &lt;b&gt;'));
    assertSame(0, substr_count($html, '<script'));
    assertTrue(strlen($html) < 100 * 1024, 'Approve RCF Entries is ' . strlen($html) . ' bytes');

    $dc    = af_user('dc');
    $ready = $entries->readyFor($dc);
    $html  = af_render('rcf-consolidate', 'Consolidate RCFs', [
        'user'   => $dc,
        'ready'  => $ready,
        'cut'    => RcfEntries::cut($ready, 'division'),
        'by'     => 'division',
        'forms'  => $entries->generatedForms($dc),
        'counts' => $entries->counts($dc),
    ]);
    assertTrue(str_contains($html, '<h1>Consolidate RCFs</h1>'));
    assertTrue(str_contains($html, 'Form 1 of 1 &mdash; AF Logistics Division'), 'the cut, shown before it is made');
    assertTrue(str_contains($html, 'value="generate"'));
    assertTrue(str_contains($html, 'name="by"'), 'the grouping choice');
    assertTrue(str_contains($html, 'Forms you have generated'));
    assertSame(3, substr_count($html, 'rcf?id='), 'the three forms generated so far link to their pages');
    assertSame(0, substr_count($html, '<script'));
    assertTrue(strlen($html) < 100 * 1024, 'Consolidate RCFs is ' . strlen($html) . ' bytes');

    $html = af_render('approval-flow', 'Approval Flow', [
        'user'     => af_user('adm'),
        'accounts' => af_flow()->accounts(),
        'selected' => $f['users']['vc'],
    ]);
    assertTrue(str_contains($html, '<h1>Approval Flow</h1>'));
    assertTrue(str_contains($html, '&rarr; Bob Approver → Carl Chair'), 'the chain in names');
    assertSame(1, substr_count($html, '<select'), 'one row open at a time');
    assertTrue(str_contains($html, 'Nobody &mdash; top of the chain'));
    assertTrue(str_contains($html, 'Top of the chain</span>'), 'dc is the top');
    assertSame(0, substr_count($html, 'Alexis Captain'), 'a Captain is not in the flow');
    assertSame(0, substr_count($html, '<script'));
    assertTrue(strlen($html) < 100 * 1024, 'Approval Flow is ' . strlen($html) . ' bytes');

    // Track RCFs carries the entries in flight, and a consolidated form says so.
    $tracking = new RcfTracking($pdo);
    $html     = af_render('rcfs', 'Track RCFs', [
        'user'     => $dc,
        'tracking' => [
            'mine'    => $tracking->mine($dc),
            'others'  => $tracking->others($dc),
            'search'  => $tracking->search($dc, ''),
            'changes' => $tracking->changes($dc),
            'upload'  => null,
            'entries' => $entries->pendingFor($dc),
        ],
    ]);
    assertTrue(str_contains($html, 'RCF entries not yet on a form'));
    assertTrue(str_contains($html, 'Waiting on Bob Approver'));
    assertTrue(str_contains($html, '<span class="chip chip-info">Consolidated</span>'), 'the consolidated forms are marked');
    assertTrue(strlen($html) < 100 * 1024, 'Track RCFs is ' . strlen($html) . ' bytes');

    $formId = $entries->generatedForms($dc)[0]['id'];
    $html   = af_render('rcf', 'Roster Change Form', [
        'user'    => $dc,
        'rcf'     => $tracking->one($dc, $formId),
        'entries' => $entries->onForm($dc, $formId),
    ]);
    assertTrue(str_contains($html, 'Consolidated</span> by Carl Chair'));
    assertTrue(str_contains($html, 'The entries this form was made from'));
    assertTrue(str_contains($html, 'the first download is from here'));
});

test('rcf entries fixtures are cleaned up', function (): void {
    $pdo = af_pdo();
    af_fixture();
    af_teardown($pdo);

    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM member WHERE member_number LIKE 'AF%'")->fetchColumn());
    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM rcf_entry e INNER JOIN app_user u ON u.id = e.requested_by INNER JOIN member m ON m.id = u.member_id WHERE m.member_number LIKE 'AF%'")->fetchColumn());
});
