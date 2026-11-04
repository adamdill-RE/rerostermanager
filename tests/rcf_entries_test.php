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
use Rerm\Auth\TitleMap;
use Rerm\Auth\User;
use Rerm\Forms\ApprovalFlow;
use Rerm\Forms\ApprovalNotice;
use Rerm\Forms\RcfEntries;
use Rerm\Forms\RcfPage;
use Rerm\Forms\RcfStore;
use Rerm\Forms\RcfTracking;
use Rerm\Forms\RosterChangeForm;
use Rerm\Mail\Mailer;
use Rerm\Routes;
use Rerm\View;

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

test('the menu carries the three workflow tiles under RCFs and the flow under Administer', function (): void {
    // Under Team Functions at Phase 14; under RCFs, by the owner's names,
    // since Phase 14.4 (spec-v2 §18.1). The routes and the capabilities are
    // unchanged: a screen was renamed, not a power.
    $menu = af_source('app/src/Menu.php');
    foreach ([
        ['Create RCF Requests', 'rcf-entries', 'rcfs'],
        ['Approve RCF Requests', 'rcf-approvals', 'rcfs'],
        ['Export Approved Requests to RCF Forms', 'rcf-consolidate', 'rcfs'],
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
    foreach (['app/src/Forms/ApprovalFlow.php', 'app/src/Forms/RcfEntries.php', 'app/src/Forms/ApprovalNotice.php'] as $file) {
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
    assertSame(1, substr_count($grid, '<datalist id="rcf-member-names">'));
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
    // On a form, the sentence says whether Rodeo Houston has processed it yet
    // (Phase 14.2): `landed` is what the imports since recorded for the line.
    assertSame('On form #44 — not yet in the roster', RcfEntries::whereabouts($base + ['status' => 'generated']));
    assertSame('On form #44 — not yet in the roster', RcfEntries::whereabouts($base + ['status' => 'generated', 'landed' => null]));
    assertSame('On form #44 — in the roster', RcfEntries::whereabouts($base + ['status' => 'generated', 'landed' => ['at' => '2026-03-02 15:00:00', 'batch' => 9]]));
    assertSame('Returned by Bob Approver: Already resigned.', RcfEntries::whereabouts($base + ['status' => 'returned']));
    assertSame('Withdrawn', RcfEntries::whereabouts($base + ['status' => 'withdrawn']));
});

test('a consolidated form may be voided by its generator or an Admin, and nothing else may be voided by anybody', function (): void {
    $user = static fn (int $id, Level $l): User => new User($id, 1, 'AF000000', $l, null, null, false, 'T');
    $form = ['source' => 'consolidated', 'generated_by' => 7, 'voided_at' => null];

    assertSame(true, RcfEntries::mayVoid($user(7, Level::SeniorOfficer), $form), 'the generator, holding generate_consolidated_rcf');
    assertSame(true, RcfEntries::mayVoid($user(7, Level::ExecutiveOfficer), $form));
    assertSame(true, RcfEntries::mayVoid($user(9, Level::Admin), $form), 'an Admin, whoever generated it');
    assertSame(false, RcfEntries::mayVoid($user(8, Level::SeniorOfficer), $form), 'another Senior Officer: not theirs');
    assertSame(false, RcfEntries::mayVoid($user(8, Level::ExecutiveOfficer), $form), 'seeing every form (view_all_forms) is not voiding one');
    assertSame(false, RcfEntries::mayVoid($user(7, Level::Officer), $form), 'a generator who no longer holds the capability');

    assertSame(false, RcfEntries::mayVoid($user(9, Level::Admin), ['source' => 'generated', 'generated_by' => 9, 'voided_at' => null]), 'a Create Forms form was sent; the record stands');
    assertSame(false, RcfEntries::mayVoid($user(9, Level::Admin), ['source' => 'uploaded', 'generated_by' => 9, 'voided_at' => null]), 'an uploaded form arrived; the record stands');
    assertSame(false, RcfEntries::mayVoid($user(9, Level::Admin), array_replace($form, ['voided_at' => '2026-03-02 15:00:00'])), 'once');
    assertSame(false, RcfEntries::mayVoid($user(7, Level::SeniorOfficer), array_replace($form, ['voided_at' => '2026-03-02 15:00:00'])), 'for the generator too');

    // The screen offers the control only where the write would take it, and
    // the write asks again (public/index.php): both read the one rule.
    assertTrue(str_contains(af_source('app/views/rcf.php'), 'value="void"'));
    assertTrue(str_contains(af_source('app/views/rcf.php'), 'if ($mayVoid)'));
    $index = af_source('public/index.php');
    assertTrue(str_contains($index, "Rerm\\Forms\\RcfEntries::mayVoid(\$user, \$rcf['form'])"), 'the screen asks');
    assertTrue(str_contains($index, "->void(\$user, \$id, (string) (\$_POST['note'] ?? ''))"), 'and the POST asks again, through void()');
    assertSame('void_consolidated_rcf', Action::VoidConsolidatedRcf->value);

    // A voided form is stamped, never deleted: the test that reads both
    // classes for a DELETE still holds, and the stamp is the filter.
    foreach (['app/src/Forms/RcfEntries.php', 'app/src/Forms/RcfTracking.php'] as $file) {
        assertSame(0, preg_match('/\\bDELETE\\s+FROM\\b/i', af_source($file)), "{$file} has no DELETE at all");
    }
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
    $pdo->exec("DELETE FROM import_change WHERE import_batch_id IN (SELECT id FROM import_batch WHERE filename LIKE 'AF-%')");
    $pdo->exec("DELETE FROM import_batch WHERE filename LIKE 'AF-%'");
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
        // The title, as the roster spells it (Phase 14.7): the one thing
        // that tells a Vice Chairman from a Division Vice Chairman.
        title: match ($key) {
            'cap' => 'Captain',
            'vc'  => 'Vice Chairman',
            'dvc' => 'Division Vice Chairman',
            'dc'  => 'Division Chairman',
            default => 'Committee Member',
        },
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
            0 => ['type' => 'T', 'member_number' => $f['members']['m3']['number'], 'new_title' => 'Assistant Captain'],
            // A title since Phase 14.7 (spec-v2 §21.5): an addition without one
            // is refused. The team is the heading's, filled in (§21.2).
            1 => ['type' => 'A', 'member_name' => 'Newcomer Sample', 'new_title' => 'Committee Member', 'sponsor' => 'Erin Delta', 'rookie' => '1', 'wait_list' => '1'],
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

    // How many route to each account — what Create RCF Requests uses to
    // tell a final approver whose own row is not set which fix they need
    // (Phase 14.5, spec-v2 §19.2). The chain is vc → dvc → dc.
    assertSame(1, $accounts[$f['users']['dvc']]['approves'], 'the Vice Chairman routes to the Division Vice Chairman');
    assertSame(1, $accounts[$f['users']['dc']]['approves']);
    assertSame(0, $accounts[$f['users']['vc']]['approves'], 'nobody routes to the Vice Chairman');
    assertSame(1, $flow->approvesCount($f['users']['dc']));
    assertSame(0, $flow->approvesCount($f['users']['adm']));
    assertSame([7 => 2, 9 => 1], ApprovalFlow::approvesCounts([
        1 => ['approver' => 7, 'top' => false],
        2 => ['approver' => 7, 'top' => false],
        7 => ['approver' => 9, 'top' => false],
        9 => ['approver' => null, 'top' => true],
    ]), 'pure, over a table');
    assertSame(1, af_entries()->routeFor(af_user('dc'))['approves'], 'and the entries read it through the flow');

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
    // A Vice Chairman may not fill in INTERVIEW REQUIRED OR SPONSORED BY
    // (Phase 14.7, spec-v2 §21.4): what arrived in the cell is dropped.
    assertSame('', $add['sponsor']);
    assertSame('Committee Member', $add['new_title']);
    assertSame('AF Bus Ops Team B', $add['new_subcommittee'], 'an addition on a team-headed form joins that team (§21.2)');
    assertSame(['name' => 'AF Bus Ops Team B', 'source' => 'rcf'], $add['team'], 'and the list says so, from the request itself (§21.1)');
    assertSame('T', $title['type']);
    assertSame($f['members']['m3']['id'], $title['member_id'], 'the number resolved to the member');
    assertSame('Echo <b>, Frank', $title['member_name'], 'the roster\'s name, last name first, unescaped in the record');
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
    $noTeam = (new RcfPage(af_pdo()))->formFromInput(af_user('vc'), ['row' => [0 => ['type' => 'R', 'member_name' => 'Somebody']]]);
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
    assertSame(['advanced' => 0, 'approved' => 0, 'notices' => []], $entries->approve(af_user('dc'), $ids));

    assertSame(['advanced' => 2, 'approved' => 0, 'notices' => []], $entries->approve(af_user('dvc'), $ids));
    assertSame([], $entries->waitingFor(af_user('dvc')));
    $atDc = $entries->waitingFor(af_user('dc'));
    assertSame(2, count($atDc));
    assertSame(null, $atDc[0]['next_approver'], 'the top of the chain: then ready to generate');
    assertSame('Waiting on Carl Chair', $atDc[0]['where']);
    assertSame(2, count($atDc[0]['steps']));
    assertSame('approved', $atDc[0]['steps'][1]['action']);
    assertSame('Bob Approver', $atDc[0]['steps'][1]['actor_name']);

    // Approving again as dvc writes nothing: it is not waiting on them now.
    assertSame(['advanced' => 0, 'approved' => 0, 'notices' => []], $entries->approve(af_user('dvc'), $ids));

    assertSame(['advanced' => 0, 'approved' => 2, 'notices' => []], $entries->approve(af_user('dc'), $ids));
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
        0 => ['type' => 'R', 'member_number' => $f['members']['m3']['number'], 'remove_reason' => '4'],
        1 => ['type' => 'R', 'member_name' => 'Gone Sample', 'remove_reason' => '1'],
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
        'row'          => [0 => ['type' => 'T', 'member_number' => $f['members']['m1']['number'], 'new_title' => 'Captain']],
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
    assertSame(['Alpha, Robert', 'Echo <b>, Frank', 'Newcomer Sample'], array_map(static fn (array $l): string => $l['member_name'], $lines));
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
    $onForms = array_filter($entries->mine(af_user('vc')), static fn (array $e): bool => $e['where'] === 'On form #' . $rcfId . ' — not yet in the roster');
    assertSame(2, count($onForms), 'both of the Vice Chairman\'s approved entries say which form they are on, and that HLSR has not shown it yet');
    foreach ($onForms as $e) {
        assertSame(null, $e['landed']);
        assertSame('Not yet in the roster', $e['trail'][count($e['trail']) - 1], 'the trail ends with what the roster says');
        assertSame('Asked by Erin Delta', $e['trail'][0]);
        assertTrue(in_array('On form #' . $rcfId . ' by Carl Chair', $e['trail'], true), 'and names the form and who generated it');
    }
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
    assertSame('Echo <b>, Frank', $built['entries'][1]['member_name']);
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
        'row'          => [0 => ['type' => 'R', 'member_number' => $f['members']['m2']['number'], 'remove_reason' => '5']],
    ]));
    $entries->submit(af_user('dc'), $f['year'], $page->formFromInput(af_user('dc'), [
        'subcommittee' => 't:' . $f['teams']['b'],
        'row'          => [
            0 => ['type' => 'A', 'member_name' => 'Second Newcomer', 'new_title' => 'Committee Member', 'sponsor' => 'Carl Chair'],
            1 => ['type' => 'A', 'member_name' => 'Third Newcomer', 'new_title' => 'Committee Member', 'sponsor' => 'Carl Chair'],
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
    $entries->submit($vc, $f['year'], af_vc_form([0 => ['type' => 'T', 'member_number' => $f['members']['m3']['number'], 'new_title' => 'Captain']]));

    $html = af_render('rcf-entries', 'Create RCF Requests', [
        'user'   => $vc,
        'rcf'    => (new RcfPage($pdo))->page($vc, ['subcommittee' => 't:' . $f['teams']['b']]),
        'mine'   => $entries->mine($vc),
        'counts' => $entries->counts($vc),
        'route'  => $entries->routeFor($vc),
    ]);
    assertTrue(str_contains($html, '<h1>Create RCF Requests</h1>'));
    assertTrue(str_contains($html, 'waits on <strong>Bob Approver</strong>'), 'the route in a sentence');
    assertSame(1, substr_count($html, '<datalist id="rcf-members">'), 'the shared grid, once');
    assertTrue(str_contains($html, 'value="submit"'), 'the submit button');
    assertTrue(str_contains($html, 'Echo &lt;b&gt;, Frank'), 'a member name with markup is escaped');
    assertSame(0, substr_count($html, 'Echo <b>'), 'and never raw');
    assertTrue(str_contains($html, 'Returned by Bob Approver'), 'the returned one carries its note');
    assertTrue(str_contains($html, 'value="withdraw"'), 'a pending one can be withdrawn');
    assertTrue(str_contains($html, 'aria-label="RCF requests"'), 'the strip');
    assertSame(0, substr_count($html, '<script'));
    assertTrue(strlen($html) < 100 * 1024, 'Create RCF Entries is ' . strlen($html) . ' bytes');

    // Nothing drawn: no grid, the sentence instead.
    $cap = af_render('rcf-entries', 'Create RCF Requests', [
        'user'   => af_user('adm'),
        'rcf'    => (new RcfPage($pdo))->page(af_user('adm'), []),
        'mine'   => [],
        'counts' => ['mine' => 0, 'waiting' => 0, 'ready' => 0],
        'route'  => ['state' => 'unset', 'approver' => null],
    ]);
    assertTrue(str_contains($cap, 'Nobody is set as your approver yet'));
    assertTrue(str_contains($cap, 'marks you <strong>Top of the chain</strong>'), 'and the sentence names both fixes');
    assertSame(0, substr_count($cap, 'value="submit"'), 'no submit when entries would be refused');

    // The final approver's trap (Phase 14.5, spec-v2 §19.2): an account
    // other people route to, whose own row was never drawn. The sentence
    // says that the fix is "top of the chain", not "an approver".
    $top = af_render('rcf-entries', 'Create RCF Requests', [
        'user'   => af_user('adm'),
        'rcf'    => (new RcfPage($pdo))->page(af_user('adm'), []),
        'mine'   => [],
        'counts' => ['mine' => 0, 'waiting' => 0, 'ready' => 0],
        'route'  => ['state' => 'unset', 'approver' => null, 'approves' => 3],
    ]);
    assertTrue(str_contains($top, '3 accounts route their requests to you'), 'who routes to them');
    assertTrue(str_contains($top, 'marks you <strong>Top of the chain</strong>'), 'the fix, by its name on the flow screen');
    assertSame(0, substr_count($top, 'value="submit"'));

    $dvc  = af_user('dvc');
    $html = af_render('rcf-approvals', 'Approve RCF Requests', [
        'user'    => $dvc,
        'waiting' => $entries->waitingFor($dvc),
        'decided' => $entries->decidedBy($dvc),
        'counts'  => $entries->counts($dvc),
    ]);
    assertTrue(str_contains($html, '<h1>Approve RCF Requests</h1>'));
    assertSame(1, substr_count($html, 'name="ids[]"'), 'one tick box per waiting entry');
    assertTrue(str_contains($html, 'then to Carl Chair'), 'where it goes next');
    assertTrue(str_contains($html, 'name="note"'), 'the return note');
    assertTrue(str_contains($html, 'Decided by you'));
    assertTrue(str_contains($html, 'Echo &lt;b&gt;, Frank'));
    assertSame(0, substr_count($html, '<script'));
    assertTrue(strlen($html) < 100 * 1024, 'Approve RCF Entries is ' . strlen($html) . ' bytes');

    $dc    = af_user('dc');
    $ready = $entries->readyFor($dc);
    $html  = af_render('rcf-consolidate', 'Export Approved Requests to RCF Forms', [
        'user'   => $dc,
        'ready'  => $ready,
        'cut'    => RcfEntries::cut($ready, 'division'),
        'by'     => 'division',
        'forms'  => $entries->generatedForms($dc),
        'counts' => $entries->counts($dc),
    ]);
    assertTrue(str_contains($html, '<h1>Export Approved Requests to RCF Forms</h1>'));
    assertTrue(str_contains($html, 'Form 1 of 1 &mdash; AF Logistics Division'), 'the cut, shown before it is made');
    assertTrue(str_contains($html, 'value="generate"'));
    assertTrue(str_contains($html, 'name="by"'), 'the grouping choice');
    assertTrue(str_contains($html, 'Forms you have generated'));
    assertSame(6, substr_count($html, 'rcf?id='), 'the three forms generated so far link to their pages, and each carries a Download form posting there');
    assertSame(3, substr_count($html, 'value="download"'), 'one Download per generated form (Phase 14.3)');
    $view = af_source('app/views/rcf-consolidate.php');
    assertSame(substr_count($view, '<form method="post"'), substr_count($view, 'Csrf::field()'), 'every one of them carries the token');
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
    assertTrue(str_contains($html, 'RCF requests not yet on a form'));
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
    assertTrue(str_contains($html, 'The requests this form was made from'));
    assertTrue(str_contains($html, 'the first download is from here'));
    // Never downloaded yet, so not "again" (Phase 14.3); a Create Forms form
    // left as a file when it was made, so it always is.
    assertTrue(str_contains($html, '<h2>Download it</h2>'));
    assertTrue(str_contains($html, '>Download the form</button>'));
    assertSame(0, substr_count($html, 'Download the form again'));
    $pdo->prepare('UPDATE rcf SET regenerated_count = 1, last_regenerated_at = UTC_TIMESTAMP() WHERE id = :id')->execute([':id' => $formId]);
    $again = af_render('rcf', 'Roster Change Form', [
        'user'    => $dc,
        'rcf'     => $tracking->one($dc, $formId),
        'entries' => $entries->onForm($dc, $formId),
    ]);
    assertTrue(str_contains($again, '<h2>Download it again</h2>'), 'once it has been');
    assertTrue(str_contains($again, '>Download the form again</button>'));
    $pdo->prepare('UPDATE rcf SET regenerated_count = 0, last_regenerated_at = NULL WHERE id = :id')->execute([':id' => $formId]);

    // The route lands one generated form on its own page and several on the
    // list (Phase 14.3): read from the handler, since it 303s.
    $index = af_source('public/index.php');
    assertTrue(str_contains($index, "redirect(\$app, 'rcf?id=' . \$result['forms'][0]);"), 'one form: its page');
    assertTrue(str_contains($index, "if (\$n === 1) {"), 'and only one');
    assertTrue(str_contains($index, "redirect(\$app, 'rcf-consolidate#generated');"), 'several: the list');
});

// ---------------------------------------------------------------------------
// After the form (Phase 14.2, spec-v2 §17): HLSR's answer, and the void
// ---------------------------------------------------------------------------

/** An applied import that changed one thing about one member number, at a moment. */
function af_import(string $number, string $kind, string $field, string $occurredAt): int
{
    $f   = af_fixture();
    $pdo = af_pdo();

    $pdo->prepare(
        'INSERT INTO import_batch (show_year_id, mode, filename, sha256, dry_run, applied_at)'
        . " VALUES (:year, 'update', :file, :sha, 0, :at)"
    )->execute([':year' => $f['year'], ':file' => 'AF-' . uniqid() . '.xls', ':sha' => str_repeat('0', 64), ':at' => $occurredAt]);
    $batch = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO import_change (import_batch_id, member_id, member_number, kind, field, before_value, after_value, occurred_at)'
        . ' VALUES (:batch, NULL, :number, :kind, :field, :before, :after, :at)'
    )->execute([':batch' => $batch, ':number' => $number, ':kind' => $kind, ':field' => $field,
        ':before' => 'Committee Member', ':after' => 'Assistant Captain', ':at' => $occurredAt]);

    return $batch;
}

test('a generated entry says when the roster showed the change — read from the import, on every list in the chain', function (): void {
    $f       = af_fixture();
    $pdo     = af_pdo();
    $entries = af_entries();

    // The first consolidated form: the Division Chairman's bundle, with the
    // Vice Chairman's title change for m3 on it.
    $forms  = $entries->generatedForms(af_user('dc'));
    $first  = $forms[count($forms) - 1];
    $onForm = $entries->onForm(af_user('dc'), (int) $first['id']);
    assertSame(3, count($onForm));
    foreach ($onForm as $e) {
        assertSame(null, $e['landed'], 'nothing has been imported since');
        assertSame('Not yet in the roster', $e['trail'][count($e['trail']) - 1]);
    }

    // An import a minute from now records exactly what the line asked for.
    $at    = (new DateTimeImmutable('+1 minute', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $batch = af_import($f['members']['m3']['number'], 'updated', 'title', $at);

    $byMember = [];
    foreach ($entries->onForm(af_user('dc'), (int) $first['id']) as $e) {
        $byMember[(string) $e['member_name']] = $e;
    }
    $m3 = $byMember['Echo <b>, Frank'];
    assertSame($batch, $m3['landed']['batch'], 'the import that fulfilled it');
    assertSame($at, $m3['landed']['at']);
    assertSame('title', $m3['landed']['field']);
    assertSame('Assistant Captain', $m3['landed']['after']);
    assertSame('On form #' . $first['id'] . ' — in the roster', $m3['where']);
    assertSame('In the roster — HLSR processed it', $m3['trail'][count($m3['trail']) - 1]);
    assertSame(
        ['Asked by Erin Delta', 'Approved by Bob Approver', 'Approved by Carl Chair', 'On form #' . $first['id'] . ' by Carl Chair', 'In the roster — HLSR processed it'],
        $m3['trail'],
        'the whole chain, then HLSR\'s answer'
    );
    // The other title change on the form is m1's, which the import did not
    // touch; the addition has no number to watch for.
    assertSame(null, $byMember['Alpha, Robert']['landed']);
    assertSame(null, $byMember['Newcomer Sample']['landed']);

    // The same answer wherever the entry is listed: the requester's list,
    // the approver's "decided by you", and the form's own line.
    $mine = array_values(array_filter($entries->mine(af_user('vc')), static fn (array $e): bool => $e['rcf_id'] === (int) $first['id'] && $e['type'] === 'T'));
    assertSame(1, count($mine));
    assertSame($batch, $mine[0]['landed']['batch']);
    $decided = array_values(array_filter($entries->decidedBy(af_user('dvc')), static fn (array $e): bool => $e['id'] === $m3['id']));
    assertSame($batch, $decided[0]['landed']['batch']);
    $line = (new RcfTracking($pdo))->one(af_user('dc'), (int) $first['id'])['rows'][1];
    assertSame('Echo <b>, Frank', $line['member_name']);
    assertSame($batch, $line['landed']['batch'], 'the form\'s page derives it the same way');

    // And the screen says so: the chip reads In the roster, with the import.
    $html = af_render('rcf-entries', 'Create RCF Requests', [
        'user'   => af_user('vc'),
        'rcf'    => (new RcfPage($pdo))->page(af_user('vc'), []),
        'mine'   => $entries->mine(af_user('vc')),
        'counts' => $entries->counts(af_user('vc')),
        'route'  => $entries->routeFor(af_user('vc')),
    ]);
    assertSame(1, substr_count($html, '>In the roster</span>'), 'one entry has landed');
    assertTrue(str_contains($html, 'In the roster — HLSR processed it'), 'the trail says it');
    assertTrue(str_contains($html, 'Approved by Bob Approver · Approved by Carl Chair'), 'after the approvals');
    assertTrue(str_contains($html, 'Not yet in the roster'), 'and the other generated entry is still waiting on HLSR');
});

test('voiding a consolidated form takes it and its entries out of view for everybody, deletes nothing, and is audited', function (): void {
    $f        = af_fixture();
    $pdo      = af_pdo();
    $entries  = af_entries();
    $tracking = new RcfTracking($pdo);
    $store    = new RcfStore($pdo);
    $dc       = af_user('dc');
    $vc       = af_user('vc');

    $forms = $entries->generatedForms($dc);
    $id    = (int) $forms[count($forms) - 1]['id'];
    $m3    = $f['members']['m3'];

    // Before: visible everywhere it should be.
    assertTrue($tracking->one($dc, $id) !== null);
    assertSame(3, count($entries->onForm($dc, $id)));
    $mineBefore    = count($entries->mine($vc));
    $decidedBefore = count($entries->decidedBy(af_user('dvc')));
    $changesBefore = $tracking->changes($dc, 'all')['counts']['all'];
    $formsBefore   = count($tracking->mine($dc));
    assertSame(1, count(array_filter($tracking->forMember($dc, $m3['id'], $m3['number']), static fn (array $l): bool => $l['rcf_id'] === $id)));
    assertSame(1, count(array_filter($tracking->search($dc, 'Frank Echo')['matches'], static fn (array $l): bool => $l['rcf_id'] === $id)));

    // Not theirs: the Division Vice Chairman holds the capability and is in
    // the chain, but did not generate it; a Captain holds nothing.
    assertSame(null, $entries->void(af_user('dvc'), $id, 'Test run.'));
    assertSame(null, $entries->void(af_user('cap'), $id, 'Test run.'));
    assertSame(null, $entries->void($dc, 0, 'Test run.'));
    assertSame(null, $entries->void($dc, 999999999, 'Test run.'), 'no such form is the same answer');
    // Theirs, but no note: refused in words, nothing written.
    assertSame(-1, $entries->void($dc, $id, "  \n "));
    assertTrue($tracking->one($dc, $id) !== null, 'still there');
    assertSame(0, count(af_audit('dc', Action::VoidConsolidatedRcf)));

    // Theirs, with a note.
    assertSame(3, $entries->void($dc, $id, "Test  run of the\napproval flow; not sent."));

    // Gone from view for everybody: the form…
    assertSame(null, $tracking->one($dc, $id), 'the generator');
    assertSame(null, $tracking->one(af_user('adm'), $id), 'an Admin');
    assertSame($formsBefore - 1, count($tracking->mine($dc)));
    assertSame(0, count(array_filter($tracking->others(af_user('adm'))['forms'], static fn (array $frm): bool => $frm['id'] === $id)));
    assertSame(0, count(array_filter($tracking->search($dc, 'Frank Echo')['matches'], static fn (array $l): bool => $l['rcf_id'] === $id)), 'the search');
    assertSame(0, count(array_filter($tracking->forMember($dc, $m3['id'], $m3['number']), static fn (array $l): bool => $l['rcf_id'] === $id)), 'the member card');
    assertSame(0, count(array_filter($tracking->forMembers($dc, [$m3['id']], [$m3['number']]), static fn (array $l): bool => $l['rcf_id'] === $id)), 'Look Up Members');
    assertSame($changesBefore - 3, $tracking->changes($dc, 'all')['counts']['all'], 'the change table');
    assertSame(null, $tracking->track($dc, $id, ['track' => ['all' => ['serial' => 'DC-9']]]), 'nor can it be tracked');
    assertSame(null, $tracking->markToday($dc, $id, 'dc'));
    assertSame(0, count(array_filter($entries->generatedForms($dc), static fn (array $frm): bool => $frm['id'] === $id)), 'Forms you have generated');
    // …and the entries it was cut from, for the requester and every approver.
    assertSame([], $entries->onForm($dc, $id));
    assertSame($mineBefore - 2, count($entries->mine($vc)), 'the Vice Chairman\'s two entries on it');
    assertSame($decidedBefore - 2, count($entries->decidedBy(af_user('dvc'))));
    assertSame(0, count(array_filter($entries->mine($dc), static fn (array $e): bool => $e['rcf_id'] === $id)), 'the Division Chairman\'s own entry on it');
    assertSame(0, count(array_filter($entries->pendingFor(af_user('adm')), static fn (array $e): bool => $e['rcf_id'] === $id)));

    // Nothing was deleted: the form, its lines and its entries are all there,
    // stamped, each entry with a voided step carrying the note.
    $count = static function (string $sql, int $id) use ($pdo): int {
        $read = $pdo->prepare($sql);
        $read->execute([':id' => $id]);

        return (int) $read->fetchColumn();
    };
    assertSame(1, $count('SELECT COUNT(*) FROM rcf WHERE id = :id AND voided_at IS NOT NULL AND voided_by = ' . (int) $f['users']['dc'], $id));
    $read = $pdo->prepare('SELECT void_note FROM rcf WHERE id = :id');
    $read->execute([':id' => $id]);
    assertSame('Test run of the approval flow; not sent.', $read->fetchColumn(), 'collapsed and trimmed, like a return note');
    assertSame(3, $count('SELECT COUNT(*) FROM rcf_row WHERE rcf_id = :id', $id));
    assertSame(3, $count("SELECT COUNT(*) FROM rcf_entry WHERE rcf_id = :id AND status = 'generated' AND voided_at IS NOT NULL", $id));
    assertSame(3, $count("SELECT COUNT(*) FROM rcf_entry_step s INNER JOIN rcf_entry e ON e.id = s.entry_id WHERE e.rcf_id = :id AND s.action = 'voided' AND s.note = 'Test run of the approval flow; not sent.'", $id));
    assertSame(0, $count("SELECT COUNT(*) FROM rcf_entry_step s INNER JOIN rcf_entry e ON e.id = s.entry_id WHERE e.rcf_id = :id AND s.action = 'voided' AND s.actor_user_id <> " . (int) $f['users']['dc'], $id));

    // One audit row, naming the form, the entries and the note; the form's
    // only remaining appearance.
    $audit = af_audit('dc', Action::VoidConsolidatedRcf);
    assertSame(1, count($audit));
    assertSame('rcf', $audit[0]['entity']);
    assertSame((string) $id, $audit[0]['entity_id']);
    $after = json_decode((string) $audit[0]['after_json'], true);
    assertSame(true, $after['voided']);
    assertSame('Test run of the approval flow; not sent.', $after['note']);
    assertSame(3, count($after['entries']));
    assertSame('AF Logistics Division', json_decode((string) $audit[0]['before_json'], true)['sub_committee']);

    // Twice is nothing: already voided is not voidable.
    assertSame(null, $entries->void($dc, $id, 'Again.'));
    assertSame(1, count(af_audit('dc', Action::VoidConsolidatedRcf)));

    // A form made on Create Forms is never voidable, even by an Admin: it
    // was sent, and the record of that stands.
    $sent = $store->store($dc, $f['year'], (new RcfPage($pdo))->formFromInput($dc, [
        'subcommittee' => 't:' . $f['teams']['a'],
        'row'          => [0 => ['type' => 'T', 'member_number' => $f['members']['m1']['number'], 'new_title' => 'Captain']],
    ]));
    assertSame(null, $entries->void(af_user('adm'), $sent, 'Test.'));
    assertTrue($tracking->one($dc, $sent) !== null, 'still visible');

    // The screen: the fold is offered to the generator on a consolidated
    // form and to nobody on a Create Forms one.
    $other = $entries->generatedForms($dc)[0];
    $rcf   = $tracking->one($dc, (int) $other['id']);
    $html  = af_render('rcf', 'Roster Change Form', [
        'user'    => $dc,
        'rcf'     => $rcf,
        'entries' => $entries->onForm($dc, (int) $other['id']),
        'mayVoid' => RcfEntries::mayVoid($dc, $rcf['form']),
    ]);
    assertTrue(str_contains($html, 'Void this form'));
    assertSame(1, substr_count($html, 'value="void"'));
    assertTrue(str_contains($html, 'name="note"') && str_contains($html, 'required'), 'the note is required');
    $view = af_source('app/views/rcf.php');
    assertSame(substr_count($view, '<form method="post"'), substr_count($view, 'Csrf::field()'), 'every POST form on the page carries the token');
    assertTrue(strlen($html) < 100 * 1024, 'the form page is ' . strlen($html) . ' bytes');

    $rcf  = $tracking->one($dc, $sent);
    $html = af_render('rcf', 'Roster Change Form', [
        'user'    => $dc,
        'rcf'     => $rcf,
        'entries' => [],
        'mayVoid' => RcfEntries::mayVoid($dc, $rcf['form']),
    ]);
    assertSame(0, substr_count($html, 'value="void"'), 'a Create Forms form cannot be voided');
});

// ---------------------------------------------------------------------------
// The one email (Phase 14.6, spec-v2 §20.2)
// ---------------------------------------------------------------------------

/**
 * A notifier whose wire is a recorder, with every Mailer interlock OPEN —
 * so a message that does not arrive was stopped by the notice's own rules
 * and nothing else. The recovery tests' pattern (mail_test.php).
 *
 * @param array<int, array{0:string,1:string,2:string}> $wire
 */
function af_notice(array &$wire, bool $enabled = true, bool $mailerEnabled = true): ApprovalNotice
{
    $mailer = new Mailer(
        $mailerEnabled,
        Mailer::TRANSPORT_SEND,
        [],
        5,
        'noreply@example.com',
        'Test Mailer',
        false,
        sys_get_temp_dir() . '/rerm-af-mail-' . getmypid(),
        function (string $to, string $subject, string $body) use (&$wire): bool {
            $wire[] = [$to, $subject, $body];

            return true;
        }
    );

    return new ApprovalNotice(af_pdo(), $mailer, 'https://host.example.com/rerm/rcf-approvals', $enabled);
}

test('the account requests are routed to is emailed once — on submission and on approval — and nobody else, ever', function (): void {
    $f = af_fixture();
    af_reset_entries();
    af_flow()->save(af_user('adm'), ['approver' => [
        (string) $f['users']['vc']  => (string) $f['users']['dvc'],
        (string) $f['users']['dvc'] => (string) $f['users']['dc'],
        (string) $f['users']['dc']  => ApprovalFlow::TOP,
    ]]);

    $wire    = [];
    $entries = new RcfEntries(af_pdo(), af_flow(), af_app()->displayTimezone(), af_notice($wire));

    // Submission: two rows, ONE message, to the requester's approver.
    $result = $entries->submit(af_user('vc'), $f['year'], af_vc_form());
    assertSame(null, $result['refused']);
    assertSame(['outcome' => ApprovalNotice::SENT, 'name' => 'Bob Approver', 'count' => 2], $result['notice']);
    assertSame(1, count($wire), 'one message however many rows');
    [$to, $subject, $body] = $wire[0];
    assertSame('bob@example.com', $to, 'the Division Vice Chairman, at the address on the roster');
    assertSame('Rodeo Express Roster: 2 RCF requests are waiting for your approval', $subject);
    assertTrue(str_contains($body, 'Hi Bob Approver,'));
    // Who asked, as the signed-in account is named — the fixture's User
    // objects carry their key as displayName, so "vc" here is "Erin Delta"
    // on the real thing.
    assertTrue(str_contains($body, 'vc submitted them.'), 'who asked');
    assertTrue(str_contains($body, 'https://host.example.com/rerm/rcf-approvals'), 'and where to go');

    // No member in it: not the name, not the number, not the change.
    foreach (['Frank', 'Echo', 'Newcomer', 'Assistant Captain', $f['members']['m3']['number'], 'AF Bus Ops'] as $leak) {
        assertSame(0, substr_count($body . $subject, $leak), "the email must not carry '{$leak}'");
    }

    // Approval that passes them on: one message to the NEXT approver,
    // saying who passed them.
    $ids    = array_map(static fn (array $e): int => (int) $e['id'], $entries->mine(af_user('vc')));
    $result = $entries->approve(af_user('dvc'), $ids);
    assertSame(2, $result['advanced']);
    assertSame([['outcome' => ApprovalNotice::SENT, 'name' => 'Carl Chair', 'count' => 2]], $result['notices']);
    assertSame(2, count($wire));
    assertSame('carl@example.com', $wire[1][0]);
    assertTrue(str_contains($wire[1][2], 'dvc approved them and passed them on to you.'), 'who passed them on');

    // The approval that ENDS the chain emails nobody: the approver is the
    // one who generates, and they are standing at the screen.
    $result = $entries->approve(af_user('dc'), $ids);
    assertSame(2, $result['approved']);
    assertSame([], $result['notices']);
    assertSame(2, count($wire));

    // A return goes to the requester's list, not their inbox.
    $entries->submit(af_user('vc'), $f['year'], af_vc_form());
    assertSame(3, count($wire), 'the new submission told the approver');
    $pending = array_map(static fn (array $e): int => (int) $e['id'], $entries->waitingFor(af_user('dvc')));
    assertSame(2, $entries->sendBack(af_user('dvc'), $pending, 'Not this year.'));
    assertSame(3, count($wire), 'a return emails nobody');

    // Nor does a withdrawal.
    $entries->submit(af_user('vc'), $f['year'], af_vc_form());
    assertSame(4, count($wire));
    $pending = array_map(static fn (array $e): int => (int) $e['id'], $entries->waitingFor(af_user('dvc')));
    assertTrue($entries->withdraw(af_user('vc'), $pending[0]));
    assertSame(4, count($wire), 'a withdrawal emails nobody');

    // The top of the chain is approved on submission, so nobody is routed
    // to and nobody is told.
    $top = $entries->submit(af_user('dc'), $f['year'], (new RcfPage(af_pdo()))->formFromInput(af_user('dc'), [
        'subcommittee' => 't:' . $f['teams']['a'],
        'row'          => [0 => ['type' => 'T', 'member_number' => $f['members']['m1']['number'], 'new_title' => 'Captain']],
    ]));
    assertSame('top', $top['state']);
    assertSame(null, $top['notice']);
    assertSame(4, count($wire));

    af_reset_entries();
});

test('the notice goes only to a Vice Chairman or above, only to an address on the roster, and only when switched on', function (): void {
    $f   = af_fixture();
    $pdo = af_pdo();

    // Below the floor: a Captain is never emailed, whatever the flow says.
    // The flow cannot draw one today; this holds when it can.
    $wire = [];
    assertSame(ApprovalNotice::INELIGIBLE, af_notice($wire)->waiting($f['users']['cap'], 1, 'Erin Delta', ApprovalNotice::SUBMITTED)['outcome']);
    assertSame([], $wire);

    // Nor an account that does not exist.
    assertSame(ApprovalNotice::INELIGIBLE, af_notice($wire)->waiting(0, 1, 'Erin Delta', ApprovalNotice::SUBMITTED)['outcome']);

    // A Division Vice Chairman whose effective level has since dropped
    // below a Vice Chairman's — a grant withdrawn, a title lost on import.
    $pdo->prepare("UPDATE app_user SET granted_level = 'officer' WHERE id = :id")->execute([':id' => $f['users']['dvc']]);
    try {
        assertSame(ApprovalNotice::INELIGIBLE, af_notice($wire)->waiting($f['users']['dvc'], 1, 'Erin Delta', ApprovalNotice::SUBMITTED)['outcome']);
    } finally {
        $pdo->prepare('UPDATE app_user SET granted_level = NULL WHERE id = :id')->execute([':id' => $f['users']['dvc']]);
    }
    assertSame([], $wire);

    // No address on the roster: said so, and nothing sent.
    $pdo->prepare('UPDATE member SET email = NULL WHERE id = :id')->execute([':id' => $f['members']['dvc']['id']]);
    try {
        $notice = af_notice($wire)->waiting($f['users']['dvc'], 2, 'Erin Delta', ApprovalNotice::SUBMITTED);
        assertSame(['outcome' => ApprovalNotice::NO_EMAIL, 'name' => 'Bob Approver', 'count' => 2], $notice);
        assertSame('Bob Approver has no email address on the roster, so let them know yourself.', ApprovalNotice::sentence($notice));
    } finally {
        $pdo->prepare('UPDATE member SET email = :email WHERE id = :id')->execute([':email' => 'bob@example.com', ':id' => $f['members']['dvc']['id']]);
    }
    assertSame([], $wire);

    // Its own switch off: nothing reaches the Mailer at all.
    assertSame(ApprovalNotice::OFF, af_notice($wire, false)->waiting($f['users']['dvc'], 1, 'Erin Delta', ApprovalNotice::SUBMITTED)['outcome']);
    assertSame([], $wire);

    // The switch on but a Mailer interlock shut (mail.enabled false, as a
    // fresh deploy ships): blocked, nothing on the wire, and the officer
    // is told to pass the word themselves.
    $notice = af_notice($wire, true, false)->waiting($f['users']['dvc'], 1, 'Erin Delta', ApprovalNotice::SUBMITTED);
    assertSame(ApprovalNotice::BLOCKED, $notice['outcome']);
    assertSame('Email notices are switched off here, so let Bob Approver know yourself.', ApprovalNotice::sentence($notice));
    assertSame([], $wire);

    // Every interlock open and the switch on: sent, once.
    assertSame(ApprovalNotice::SENT, af_notice($wire)->waiting($f['users']['dvc'], 1, 'Erin Delta', ApprovalNotice::SUBMITTED)['outcome']);
    assertSame(1, count($wire));
    assertSame('bob@example.com', $wire[0][0]);
});

// ---------------------------------------------------------------------------
// Phase 14.7 (spec-v2 §21): the team on every list, an addition's team, the
// second look, the sponsor's cell, and the title list
// ---------------------------------------------------------------------------

test('the team a line or a request is about is the form\'s first and the roster\'s second, and the note says which', function (): void {
    // What is ON THE FORM wins: an addition's destination, then the heading's
    // team. A division heading says nothing about the team, so the roster's
    // is shown — marked as the roster's. Nothing, from nowhere, otherwise.
    assertSame(['name' => 'AF Bus Ops Team B', 'source' => 'rcf'],
        RcfTracking::teamFor(['type' => 'A', 'new_subcommittee' => 'AF Bus Ops Team B', 'form_team' => 'AF Bus Ops Team A', 'roster_team' => '']),
        'an addition joins the team in NEW SUB-COMMITTEE, whatever the heading');
    assertSame(['name' => 'AF Bus Ops Team A', 'source' => 'rcf'],
        RcfTracking::teamFor(['type' => 'T', 'new_subcommittee' => '', 'form_team' => 'AF Bus Ops Team A', 'roster_team' => 'AF Bus Ops Team B']),
        'the heading\'s team beats the roster\'s: the form said so');
    assertSame(['name' => 'AF Bus Ops Team A', 'source' => 'rcf'],
        RcfTracking::teamFor(['type' => 'S', 'new_subcommittee' => 'AF Bus Ops Team B', 'form_team' => 'AF Bus Ops Team A', 'roster_team' => '']),
        'a team change is about the team they are on; the destination is the change');
    assertSame(['name' => 'AF Bus Ops Team B', 'source' => 'roster'],
        RcfTracking::teamFor(['type' => 'T', 'new_subcommittee' => '', 'form_team' => '', 'roster_team' => 'AF Bus Ops Team B']),
        'a division heading: the roster\'s team, marked');
    assertSame(['name' => '', 'source' => ''],
        RcfTracking::teamFor(['type' => 'A', 'new_subcommittee' => '', 'form_team' => '', 'roster_team' => '']),
        'a newcomer on a division-headed form kept before this phase');

    // One function draws it everywhere, with the note only where the roster
    // answered.
    assertSame('AF Bus Ops Team A', View::rcfTeam(['name' => 'AF Bus Ops Team A', 'source' => 'rcf']));
    assertSame('AF Bus Ops Team B <span class="why">from the roster &mdash; not on the form</span>',
        View::rcfTeam(['name' => 'AF Bus Ops Team B', 'source' => 'roster']));
    assertSame('<span class="why">no team on the form, and not on the roster</span>', View::rcfTeam(['name' => '', 'source' => '']));
    assertTrue(str_contains(View::rcfTeam(['name' => 'Team <b>', 'source' => 'rcf']), 'Team &lt;b&gt;'), 'escaped');

    // Every list that shows an RCF line or request draws it through the one
    // function: the three workflow screens, Track RCFs, one form, the
    // member card and Look Up Members.
    foreach (['rcf-entries', 'rcf-approvals', 'rcf-consolidate', 'rcfs', 'rcf', 'member', 'lookup'] as $view) {
        assertTrue(str_contains(af_source("app/views/{$view}.php"), 'View::rcfTeam('), "{$view} shows the team");
    }
    // And both shapers read the one rule, with the roster's team joined
    // live, never kept.
    assertTrue(str_contains(af_source('app/src/Forms/RcfEntries.php'), 'RcfTracking::teamFor(['));
    assertTrue(str_contains(af_source('app/src/Forms/RcfTracking.php'), "'team'               => self::teamFor(["));
    assertSame(0, preg_match('/roster_team/', af_source('db/migrations/013_rcf_entries.sql')), 'the roster\'s team is not a column');
});

test('what a row needs a second look for, and what refuses it outright, are two pure rules', function (): void {
    $team     = ['key' => 't:1', 'name' => 'AF Bus Ops Team A', 'label' => 'AF Logistics Division - AF Bus Ops Team A', 'team_id' => 1, 'division_id' => null];
    $division = ['key' => 'd:1', 'name' => 'AF Logistics Division', 'label' => 'AF Logistics Division', 'team_id' => null, 'division_id' => 1];
    $row      = static fn (array $cells): array => array_replace(RosterChangeForm::emptyEntry(), ['member_number' => 'AF000001', 'member_name' => 'Alpha, Robert'], $cells);

    // No team anywhere on the line: a division heading and a blank NEW
    // SUB-COMMITTEE. A team heading answers it; so does a destination.
    assertSame(true, RcfPage::needsConfirming($row(['type' => 'T', 'new_title' => 'Captain']), $division));
    assertSame(true, RcfPage::needsConfirming($row(['type' => 'R', 'remove_reason' => '4']), $division));
    assertSame(true, RcfPage::needsConfirming($row(['type' => 'T', 'new_title' => 'Captain']), null), 'no heading at all');
    assertSame(false, RcfPage::needsConfirming($row(['type' => 'T', 'new_title' => 'Captain']), $team));
    assertSame(false, RcfPage::needsConfirming($row(['type' => 'T', 'new_title' => 'Captain', 'new_subcommittee' => 'AF Bus Ops Team B']), $division));
    // A team change with no destination, whatever the heading: the exact
    // mistake the look exists to catch.
    assertSame(true, RcfPage::needsConfirming($row(['type' => 'S']), $team));
    assertSame(true, RcfPage::needsConfirming($row(['type' => 'S & T', 'new_title' => 'Captain']), $team));
    assertSame(false, RcfPage::needsConfirming($row(['type' => 'S', 'new_subcommittee' => 'AF Bus Ops Team B']), $division));
    // Never an addition (refused instead), never a blank row.
    assertSame(false, RcfPage::needsConfirming($row(['type' => 'A', 'new_title' => 'Committee Member']), $division));
    assertSame(false, RcfPage::needsConfirming(RosterChangeForm::emptyEntry(), $division));

    // What is refused: a title missing where one is required, a title typed
    // off the list, an addition with no team, with a division, or with a
    // team that is not on the roster.
    $options  = [$division, $team, ['key' => 't:2', 'name' => 'AF Bus Ops Team B', 'label' => 'AF Logistics Division - AF Bus Ops Team B', 'team_id' => 2, 'division_id' => null]];
    assertSame([], RcfPage::problems(1, $row(['type' => 'T', 'new_title' => 'Captain']), [], $options));
    assertSame([], RcfPage::problems(1, $row(['type' => 'R', 'remove_reason' => '4']), [], $options), 'a removal needs no title');
    assertSame([], RcfPage::problems(1, $row(['type' => 'S', 'new_subcommittee' => 'AF Bus Ops Team B']), [], $options), 'nor a team change');
    assertSame([], RcfPage::problems(1, RosterChangeForm::emptyEntry(), ['new_title' => 'x'], $options), 'a blank row is nothing');
    assertSame(['Row 3: pick the title under Change/add title — it is required for title change.'],
        RcfPage::problems(3, $row(['type' => 'T']), [], $options));
    assertSame(['Row 3: "Asst Captain" is not a title on the roster. Pick one from the list under Change/add title.'],
        RcfPage::problems(3, $row(['type' => 'T']), ['new_title' => 'Asst Captain'], $options), 'what was typed is named, not reported missing');
    assertSame(['Row 2: pick the title under Change/add title — it is required for sub-committee change (team change) & title change.'],
        RcfPage::problems(2, $row(['type' => 'S & T', 'new_subcommittee' => 'AF Bus Ops Team B']), [], $options));
    assertSame(['Row 1: an addition needs the team they will be added to. Pick it under New sub-committee.'],
        RcfPage::problems(1, $row(['type' => 'A', 'new_title' => 'Committee Member']), [], $options));
    assertSame(['Row 1: "AF Logistics Division" is a division. Pick the team they will be added to from the list under New sub-committee.'],
        RcfPage::problems(1, $row(['type' => 'A', 'new_title' => 'Committee Member', 'new_subcommittee' => 'AF Logistics Division']), [], $options));
    assertSame(['Row 1: "Nowhere Team" is not a team on the roster. Pick the team they will be added to from the list under New sub-committee.'],
        RcfPage::problems(1, $row(['type' => 'A', 'new_title' => 'Committee Member', 'new_subcommittee' => 'Nowhere Team']), [], $options));
    assertSame([], RcfPage::problems(1, $row(['type' => 'A', 'new_title' => 'Committee Member', 'new_subcommittee' => 'AF Bus Ops Team B']), [], $options));
    assertSame(2, count(RcfPage::problems(1, $row(['type' => 'A']), [], $options)), 'both at once, both named');

    // The key is for exactly these rows on this form, and nothing else.
    $confirm = [['member_number' => 'AF000001', 'member_name' => 'Alpha, Robert', 'type' => 'T', 'type_label' => 'Title Change', 'roster_team' => 'AF Bus Ops Team A', 'on_roster' => '1']];
    $key     = RcfPage::confirmKey($confirm, $division);
    assertSame(1, preg_match('/^[0-9a-f]{64}$/', $key));
    assertSame($key, RcfPage::confirmKey($confirm, $division), 'stable');
    assertTrue($key !== RcfPage::confirmKey($confirm, $team), 'another heading is another question');
    assertTrue($key !== RcfPage::confirmKey([array_replace($confirm[0], ['type' => 'R'])], $division), 'a changed row is another question');
    assertSame('', RcfPage::confirmKey([], $division), 'nothing to confirm, no key');
    assertSame(true, RcfPage::confirmed(['confirm' => [], 'confirm_key' => ''], []), 'nothing to confirm is confirmed');
    assertSame(false, RcfPage::confirmed(['confirm' => $confirm, 'confirm_key' => $key], []));
    assertSame(false, RcfPage::confirmed(['confirm' => $confirm, 'confirm_key' => $key], ['confirm_teams' => 'yes']));
    assertSame(true, RcfPage::confirmed(['confirm' => $confirm, 'confirm_key' => $key], ['confirm_teams' => $key]));

    // The sponsor's cell: a Division Vice Chairman and above. Level says
    // Senior Officer for a Vice Chairman too; the title's breadth tells
    // them apart. A granted Senior Officer with a member's title may.
    $user = static fn (Level $l, string $title): User => new User(1, 1, 'AF000000', $l, null, null, false, 'T', [], $title);
    assertSame(true, RcfPage::maySponsor($user(Level::Admin, 'Committee Member')));
    assertSame(true, RcfPage::maySponsor($user(Level::ExecutiveOfficer, 'Division Chairman')));
    assertSame(true, RcfPage::maySponsor($user(Level::SeniorOfficer, 'Division Vice Chairman')));
    assertSame(true, RcfPage::maySponsor($user(Level::SeniorOfficer, 'Coordinator')));
    assertSame(true, RcfPage::maySponsor($user(Level::SeniorOfficer, 'Committee Member')), 'granted the level: the division\'s breadth');
    assertSame(false, RcfPage::maySponsor($user(Level::SeniorOfficer, 'Vice Chairman')), 'the one Senior Officer who sees a team');
    assertSame(false, RcfPage::maySponsor($user(Level::SeniorOfficer, 'vice chairman ')), 'matched as the title map matches');
    assertSame(false, RcfPage::maySponsor($user(Level::Officer, 'Captain')));
    assertSame(false, RcfPage::maySponsor($user(Level::Member, 'Committee Member')));
    assertSame('Vice Chairman', User::fromRow([
        'id' => 1, 'member_id' => 1, 'member_number' => 'AF000000', 'effective_level' => 'senior_officer', 'must_change_password' => 0,
        'scope_division_id' => null, 'scope_team_id' => null, 'division_id' => 1, 'team_id' => 2, 'title' => ' Vice Chairman ',
        'first_name' => 'Erin', 'last_name' => 'Delta', 'preferred_name' => '',
    ])->title, 'a real login carries the title the loader selects');

    // The screens: the title is a <select> on every row, the sponsor cell
    // is disabled for whoever may not, the second look is one partial drawn
    // inside both forms, and both handlers hold the form through one
    // function before anything is built or written.
    $grid = af_source('app/views/rcf-grid.php');
    assertTrue(str_contains($grid, '<select name="row[<?= $i ?>][new_title]"'), 'a list, not a text box');
    assertSame(0, substr_count($grid, "'new_title', 'New title'"), 'and no text box left');
    assertTrue(str_contains($grid, 'if ($maySponsor)'));
    assertTrue(str_contains($grid, '<input type="text" disabled'));
    foreach (['form-rcf', 'rcf-entries'] as $view) {
        assertTrue(str_contains(af_source("app/views/{$view}.php"), "require __DIR__ . '/rcf-confirm.php'"), "{$view} draws the second look");
    }
    $confirmView = af_source('app/views/rcf-confirm.php');
    assertTrue(str_contains($confirmView, 'name="confirm_teams"'));
    assertTrue(str_contains($confirmView, 'role="alertdialog"'));
    assertSame(0, substr_count($confirmView, '<script'), 'no script: the CSP forbids it');
    $index = af_source('public/index.php');
    assertSame(2, substr_count($index, 'rcf_held($form, $_POST,'), 'both grid screens hold the form the same way');
    assertTrue(str_contains(af_source('app/src/Forms/RcfEntries.php'), "\$form['problems']"), 'and submit() refuses a problem again');
});

test('a title is picked from one list, an addition names its team, and the sponsor cell is dropped for whoever may not fill it', function (): void {
    $f    = af_fixture();
    $page = new RcfPage(af_pdo());

    // The list: every title on the roster, and every one the export spells.
    $titles = $page->titles();
    foreach (['Captain', 'Vice Chairman', 'Division Vice Chairman', 'Division Chairman', 'Committee Member'] as $title) {
        assertTrue(in_array($title, $titles, true), "{$title} is on the roster, so it is offered");
    }
    foreach (array_keys(TitleMap::MAP) as $title) {
        assertTrue(in_array($title, $titles, true), "{$title} is a title the export spells, so it is offered");
    }
    assertSame(count($titles), count(array_unique($titles)), 'once each');
    $sorted = $titles;
    usort($sorted, 'strcasecmp');
    assertSame($sorted, $titles, 'in order');
    assertTrue(in_array('Committee Member', $page->page(af_user('vc'), [])['titles'], true), 'the screen is handed it');

    // On a form headed with a TEAM: a title off the list is dropped and
    // named; an addition takes the heading's team; a removal needs no title.
    $form = $page->formFromInput(af_user('dvc'), [
        'subcommittee' => 't:' . $f['teams']['a'],
        'row'          => [
            0 => ['type' => 'T', 'member_number' => $f['members']['m1']['number'], 'new_title' => 'Asst Captain'],
            1 => ['type' => 'A', 'member_name' => 'Newcomer Sample', 'new_title' => 'Committee Member', 'sponsor' => 'Bob Approver'],
            2 => ['type' => 'R', 'member_number' => $f['members']['m2']['number'], 'remove_reason' => '4'],
            3 => ['type' => 'T', 'member_number' => $f['members']['m2']['number']],
        ],
    ]);
    assertSame('', $form['entries'][0]['new_title'], 'a spelling the roster does not use is not written');
    assertSame('Committee Member', $form['entries'][1]['new_title']);
    assertSame('AF Bus Ops Team A', $form['entries'][1]['new_subcommittee'], 'the addition joins the team the form is headed with');
    assertSame('Bob Approver', $form['entries'][1]['sponsor'], 'a Division Vice Chairman sponsors');
    assertSame([
        'Row 1: "Asst Captain" is not a title on the roster. Pick one from the list under Change/add title.',
        'Row 4: pick the title under Change/add title — it is required for title change.',
    ], $form['problems']);
    assertSame([], $form['confirm'], 'a team heading names the team on every row');
    assertSame('', $form['confirm_key']);

    // On a form headed with a DIVISION: an addition has to say which team,
    // and it has to be a team.
    $form = $page->formFromInput(af_user('dvc'), [
        'subcommittee' => 'd:' . $f['division'],
        'row'          => [
            0 => ['type' => 'A', 'member_name' => 'Newcomer Sample', 'new_title' => 'Committee Member'],
            1 => ['type' => 'A', 'member_name' => 'Second Newcomer', 'new_title' => 'Committee Member', 'new_subcommittee' => 'AF Logistics Division'],
            2 => ['type' => 'A', 'member_name' => 'Third Newcomer', 'new_title' => 'Committee Member', 'new_subcommittee' => 'af logistics division - af bus ops team b'],
        ],
    ]);
    assertSame('', $form['entries'][0]['new_subcommittee']);
    assertSame('AF Bus Ops Team B', $form['entries'][2]['new_subcommittee'], 'picked by label, written as the team\'s own name');
    assertSame([
        'Row 1: an addition needs the team they will be added to. Pick it under New sub-committee.',
        'Row 2: "AF Logistics Division" is a division. Pick the team they will be added to from the list under New sub-committee.',
    ], $form['problems']);

    // The sponsor's cell is dropped for a Vice Chairman and a Captain,
    // whatever arrived in it, and the screen draws it disabled for them.
    $vc = $page->formFromInput(af_user('vc'), [
        'subcommittee' => 't:' . $f['teams']['b'],
        'row'          => [0 => ['type' => 'A', 'member_name' => 'Newcomer Sample', 'new_title' => 'Committee Member', 'sponsor' => 'Erin Delta']],
    ]);
    assertSame('', $vc['entries'][0]['sponsor']);
    assertSame(false, $page->page(af_user('vc'), [])['may_sponsor']);
    assertSame(false, $page->page(af_user('cap'), [])['may_sponsor']);
    assertSame(true, $page->page(af_user('dvc'), [])['may_sponsor']);
    assertSame(true, $page->page(af_user('dc'), [])['may_sponsor']);
    assertSame(true, $page->page(af_user('adm'), [])['may_sponsor']);
    $html = af_render('rcf-entries', 'Create RCF Requests', [
        'user' => af_user('vc'), 'rcf' => $page->page(af_user('vc'), ['subcommittee' => 't:' . $f['teams']['b']]),
        'mine' => [], 'counts' => ['mine' => 0, 'waiting' => 0, 'ready' => 0],
        'route' => ['state' => 'approver', 'approver' => ['id' => 1, 'name' => 'Bob Approver'], 'approves' => 0],
    ]);
    assertSame(5, substr_count($html, 'placeholder="DVC or above"'), 'five rows, five disabled cells');
    assertSame(0, substr_count($html, 'name="row[0][sponsor]"'), 'and nothing to submit in them');
    assertSame(5, substr_count($html, '][new_title]"'), 'five title lists');
    assertTrue(str_contains($html, '<option>Committee Member</option>'), 'the title, once per option, as its own value');
    $html = af_render('rcf-entries', 'Create RCF Requests', [
        'user' => af_user('dvc'), 'rcf' => $page->page(af_user('dvc'), ['subcommittee' => 't:' . $f['teams']['a']]),
        'mine' => [], 'counts' => ['mine' => 0, 'waiting' => 0, 'ready' => 0],
        'route' => ['state' => 'approver', 'approver' => ['id' => 1, 'name' => 'Carl Chair'], 'approves' => 0],
    ]);
    assertSame(0, substr_count($html, 'placeholder="DVC or above"'));
    assertSame(5, substr_count($html, 'name="row[0][sponsor]"') + substr_count($html, 'name="row[1][sponsor]"') + substr_count($html, 'name="row[2][sponsor]"')
        + substr_count($html, 'name="row[3][sponsor]"') + substr_count($html, 'name="row[4][sponsor]"'), 'a Division Vice Chairman gets the cell');

    // submit() refuses a problem again, writing nothing — after the route,
    // so an account with nothing drawn still learns that first.
    af_reset_entries();
    af_flow()->save(af_user('adm'), ['approver' => [
        (string) $f['users']['vc']  => (string) $f['users']['dvc'],
        (string) $f['users']['dvc'] => (string) $f['users']['dc'],
        (string) $f['users']['dc']  => ApprovalFlow::TOP,
    ]]);
    $result = af_entries()->submit(af_user('vc'), $f['year'], af_vc_form([0 => ['type' => 'A', 'member_name' => 'Newcomer Sample']]));
    assertSame([], $result['created']);
    assertSame('approver', $result['state']);
    assertTrue(str_starts_with((string) $result['refused'], 'Row 1: pick the title under Change/add title'), (string) $result['refused']);
    assertSame(0, (int) af_pdo()->query("SELECT COUNT(*) FROM rcf_entry WHERE requested_by = {$f['users']['vc']}")->fetchColumn(), 'nothing written');
    af_flow()->save(af_user('adm'), ['approver' => [(string) $f['users']['vc'] => '']]);
    $result = af_entries()->submit(af_user('vc'), $f['year'], af_vc_form([0 => ['type' => 'A', 'member_name' => 'Newcomer Sample']]));
    assertSame('unset', $result['state'], 'the flow is the first thing a requester is told about');
    af_flow()->save(af_user('adm'), ['approver' => [(string) $f['users']['vc'] => (string) $f['users']['dvc']]]);
});

test('a row naming no team is held for a second look with what the roster says, and the team is on every list once it is a request', function (): void {
    $f    = af_fixture();
    $page = new RcfPage(af_pdo());
    af_reset_entries();

    // A DIVISION heading: a title change, a removal, a team change with no
    // destination, somebody not on the roster, and an addition with a team.
    // Everything but the addition is asked about; the addition is not, because
    // it names its team.
    $input = [
        'subcommittee' => 'd:' . $f['division'],
        'row'          => [
            0 => ['type' => 'T', 'member_number' => $f['members']['m1']['number'], 'new_title' => 'Captain'],
            1 => ['type' => 'R', 'member_number' => $f['members']['m3']['number'], 'remove_reason' => '4'],
            2 => ['type' => 'S', 'member_number' => $f['members']['m2']['number']],
            3 => ['type' => 'T', 'member_number' => 'AF999999', 'member_name' => 'Nobody Known', 'new_title' => 'Captain'],
            4 => ['type' => 'A', 'member_name' => 'Newcomer Sample', 'new_title' => 'Committee Member', 'new_subcommittee' => 'AF Bus Ops Team A'],
        ],
    ];
    $form = $page->formFromInput(af_user('dvc'), $input);
    assertSame([], $form['problems']);
    assertSame(4, count($form['confirm']));
    assertSame(['member_number' => $f['members']['m1']['number'], 'member_name' => 'Alpha, Robert', 'type' => 'T', 'type_label' => 'Title Change',
        'roster_team' => 'AF Bus Ops Team A', 'on_roster' => '1'], $form['confirm'][0], 'what the roster says their team is');
    assertSame('AF Bus Ops Team B', $form['confirm'][1]['roster_team']);
    assertSame('S', $form['confirm'][2]['type']);
    assertSame(['roster_team' => '', 'on_roster' => ''], array_intersect_key($form['confirm'][3], ['roster_team' => 1, 'on_roster' => 1]), 'nobody the caller can see: no team to show, said so');
    assertSame(false, RcfPage::confirmed($form, $input), 'held');
    assertSame(true, RcfPage::confirmed($form, $input + ['confirm_teams' => $form['confirm_key']]), 'until the key comes back');

    // The same rows, edited: another key, so the question is asked again.
    $edited = $input;
    $edited['row'][0]['type'] = 'R';
    assertTrue($page->formFromInput(af_user('dvc'), $edited)['confirm_key'] !== $form['confirm_key']);

    // The screen draws the question above the grid, inside the form, with
    // the key and the two buttons — and a Note in the bar.
    $html = af_render('rcf-entries', 'Create RCF Requests', [
        'user' => af_user('dvc'), 'rcf' => $page->page(af_user('dvc'), $input), 'confirm' => $form,
        'mine' => [], 'counts' => ['mine' => 0, 'waiting' => 0, 'ready' => 0],
        'route' => ['state' => 'approver', 'approver' => ['id' => 1, 'name' => 'Carl Chair'], 'approves' => 0],
    ]);
    assertTrue(str_contains($html, 'Check the team before you go on'));
    assertTrue(str_contains($html, '<input type="hidden" name="confirm_teams" value="' . $form['confirm_key'] . '">'));
    assertTrue(str_contains($html, 'on the roster as <strong>AF Bus Ops Team A</strong>'));
    assertTrue(str_contains($html, 'not on the roster you can see, so there is no team to show'));
    assertTrue(str_contains($html, 'a team change with no new team given'));
    assertTrue(str_contains($html, 'The roster is right — submit these requests'));
    assertTrue(str_contains($html, 'value="load" class="quiet">Go back and change the rows'));
    assertTrue(strpos($html, 'id="confirm"') < strpos($html, '<table class="rcf">'), 'above the grid');
    assertTrue(strpos($html, 'id="confirm"') > strpos($html, '<form method="post"'), 'inside the form, so the rows travel with the answer');
    assertTrue(str_contains($html, 'name="row[0][member_number]"') && str_contains($html, 'value="' . $f['members']['m1']['number'] . '"'), 'nothing typed is lost');
    $html = af_render('form-rcf', 'Roster Change Form', ['user' => af_user('dvc'), 'rcf' => $page->page(af_user('dvc'), $input), 'confirm' => $form]);
    assertTrue(str_contains($html, 'The roster is right — download the form'), 'the same card on Create Forms, with its own verb');
    $html = af_render('form-rcf', 'Roster Change Form', ['user' => af_user('dvc'), 'rcf' => $page->page(af_user('dvc'), $input)]);
    assertSame(0, substr_count($html, 'id="confirm"'), 'and not when nothing is held');

    // A TEAM heading asks about nothing — except a team change with no
    // destination, whatever the heading.
    $team = $page->formFromInput(af_user('dvc'), ['subcommittee' => 't:' . $f['teams']['a'], 'row' => [
        0 => ['type' => 'T', 'member_number' => $f['members']['m1']['number'], 'new_title' => 'Captain'],
        1 => ['type' => 'S', 'member_number' => $f['members']['m2']['number']],
        2 => ['type' => 'S', 'member_number' => $f['members']['m2']['number'], 'new_subcommittee' => 'AF Bus Ops Team B'],
    ]]);
    assertSame(1, count($team['confirm']));
    assertSame($f['members']['m2']['number'], $team['confirm'][0]['member_number']);

    // Once submitted, every list carries the team: the heading's for the
    // team-headed request, the roster's — marked — for the division-headed
    // one, the destination for the addition. submit() does not ask the
    // question again: the screen did, and a request is what was confirmed.
    af_flow()->save(af_user('adm'), ['approver' => [
        (string) $f['users']['vc']  => (string) $f['users']['dvc'],
        (string) $f['users']['dvc'] => (string) $f['users']['dc'],
        (string) $f['users']['dc']  => ApprovalFlow::TOP,
    ]]);
    $entries = af_entries();
    $result  = $entries->submit(af_user('dvc'), $f['year'], $form);
    assertSame(5, count($result['created']));
    $mine = array_reverse($entries->mine(af_user('dvc')));
    assertSame(['name' => 'AF Bus Ops Team A', 'source' => 'roster'], $mine[0]['team'], 'a division heading: the roster\'s team, marked');
    assertSame(['name' => 'AF Bus Ops Team B', 'source' => 'roster'], $mine[1]['team']);
    assertSame(['name' => 'AF Bus Ops Team A', 'source' => 'roster'], $mine[2]['team'], 'a team change with no destination: the team they are on');
    assertSame(['name' => '', 'source' => ''], $mine[3]['team'], 'nobody the roster knows');
    assertSame(['name' => 'AF Bus Ops Team A', 'source' => 'rcf'], $mine[4]['team'], 'the addition\'s destination, from the request itself');
    $result = $entries->submit(af_user('vc'), $f['year'], af_vc_form());
    assertSame(2, count($result['created']));
    foreach ($entries->mine(af_user('vc')) as $entry) {
        assertSame(['name' => 'AF Bus Ops Team B', 'source' => 'rcf'], $entry['team'], 'a team heading: the form said so');
    }
    foreach ($entries->waitingFor(af_user('dvc')) as $entry) {
        assertSame('rcf', $entry['team']['source']);
    }
    $waiting = $entries->waitingFor(af_user('dc'));
    assertSame(5, count($waiting));
    assertSame('roster', $waiting[0]['team']['source']);
    foreach ($entries->pendingFor(af_user('dc')) as $entry) {
        assertTrue(isset($entry['team']['name'], $entry['team']['source']));
    }

    // And the screens print it with the note where the roster answered.
    $html = af_render('rcf-approvals', 'Approve RCF Requests', [
        'user' => af_user('dc'), 'waiting' => $waiting, 'decided' => [], 'counts' => ['mine' => 0, 'waiting' => 5, 'ready' => 0],
    ]);
    assertTrue(str_contains($html, '<th scope="col">Team</th>'));
    assertTrue(str_contains($html, 'AF Bus Ops Team A <span class="why">from the roster &mdash; not on the form</span>'));
    assertTrue(str_contains($html, 'no team on the form, and not on the roster'));
    $html = af_render('rcf-entries', 'Create RCF Requests', [
        'user' => af_user('vc'), 'rcf' => $page->page(af_user('vc'), []), 'mine' => $entries->mine(af_user('vc')),
        'counts' => ['mine' => 2, 'waiting' => 0, 'ready' => 0],
        'route' => ['state' => 'approver', 'approver' => ['id' => 1, 'name' => 'Bob Approver'], 'approves' => 0],
    ]);
    assertSame(2, substr_count($html, '<td data-label="Team">AF Bus Ops Team B</td>'), 'from the request, no note');

    af_reset_entries();
});

test('rcf entries fixtures are cleaned up', function (): void {
    $pdo = af_pdo();
    af_fixture();
    af_teardown($pdo);

    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM member WHERE member_number LIKE 'AF%'")->fetchColumn());
    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM rcf_entry e INNER JOIN app_user u ON u.id = e.requested_by INNER JOIN member m ON m.id = u.member_id WHERE m.member_number LIKE 'AF%'")->fetchColumn());
});
