<?php

declare(strict_types=1);

/**
 * Track RCFs (Phase 11, spec-v2 §12): every Roster Change Form produced is
 * kept, can be downloaded again, and carries — per line — the Division
 * Chairman's RCF number, the day it went to the Division Chairman, the day
 * it went to Rosters, and whether the roster now shows it.
 *
 * The fixture is generated and the expectations are TRANSCRIBED beside it.
 * Generated, never real: this repository is public. Member numbers here are
 * 'TK000001', addresses are @example.com and phones are the reserved
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
use Rerm\Forms\FormSheet;
use Rerm\Forms\RcfPage;
use Rerm\Forms\RcfStore;
use Rerm\Forms\RcfTracking;
use Rerm\Forms\RosterChangeForm;
use Rerm\Roster\MemberPage;
use Rerm\Routes;

function tk_app(): App
{
    return $GLOBALS['rerm_app'];
}

function tk_source(string $relative): string
{
    return (string) file_get_contents(__DIR__ . '/../' . $relative);
}

// ---------------------------------------------------------------------------
// What needs no database: the route, the capability, the verbs, the tile
// ---------------------------------------------------------------------------

test('both tracking routes are guarded by create_forms, and everyone else\'s forms by view_all_forms', function (): void {
    assertSame(Capability::CreateForms->value, Routes::guard('rcfs'));
    assertSame(Capability::CreateForms->value, Routes::guard('rcf'));

    // spec-v2 §12.3, transcribed: Executive Officer / Everywhere — the first
    // capability with that shape.
    assertSame(Level::ExecutiveOfficer, Capability::ViewAllForms->minimumLevel());
    assertSame(Scope::Everywhere, Capability::ViewAllForms->scope());
    assertSame('view_all_forms', Capability::ViewAllForms->value);

    // A Senior Officer — a Vice Chairman — does not hold it; a Division
    // Chairman and an Admin do.
    $user = static fn (Level $l): User => new User(1, 1, 'TK000000', $l, null, null, false, 'T');
    assertSame(false, Access::mayUse($user(Level::SeniorOfficer), Capability::ViewAllForms));
    assertSame(true, Access::mayUse($user(Level::ExecutiveOfficer), Capability::ViewAllForms));
    assertSame(true, Access::mayUse($user(Level::Admin), Capability::ViewAllForms));
    // Everywhere: no subject is needed.
    assertSame(true, Access::allows($user(Level::ExecutiveOfficer), Capability::ViewAllForms));
});

test('the menu tile is behind create_forms and points at the list', function (): void {
    $menu = tk_source('app/views/menu.php');
    assertTrue(str_contains($menu, "'label' => 'Track RCFs'"), 'the tile is called Track RCFs');
    assertTrue(str_contains($menu, "'route' => 'rcfs'"), 'and it links to the list');
    assertTrue(str_contains(tk_source('app/views/forms.php'), "url('rcfs')"), 'Create Forms links to it too');
});

test('downloading a kept form again and changing its tracking are their own audit verbs', function (): void {
    assertSame('regenerate_form', Action::RegenerateForm->value);
    assertSame('track_form', Action::TrackForm->value);
    assertTrue(Action::RegenerateForm->label() !== '' && Action::TrackForm->label() !== '');

    $tracking = tk_source('app/src/Forms/RcfTracking.php');
    assertTrue(str_contains($tracking, 'Action::TrackForm'), 'the tracking write goes to the audit log');
    assertTrue(str_contains(tk_source('public/index.php'), 'audit($user, $form, (int) $built[\'rows\'], $id, true)'),
        'and Download again is logged as the second verb');
});

test('nothing in the tracking code deletes a form, a line, or anything the roster owns', function (): void {
    // rcf and rcf_row are RECORDS (011): a kept form is the answer to "was
    // one ever submitted", and a deletable answer is no answer. The two
    // files that write them are read for the same DELETEs admin_test reads
    // every other write path for.
    foreach (['app/src/Forms/RcfStore.php', 'app/src/Forms/RcfTracking.php'] as $file) {
        $source = tk_source($file);
        foreach (['rcf', 'rcf_row', 'member', 'contact_log', 'audit_log', 'import_change'] as $table) {
            assertSame(0, preg_match('/\bDELETE\s+FROM\s+`?' . $table . '`?\b/i', $source), "{$file} must never DELETE FROM {$table}");
        }
        assertSame(0, preg_match('/\bTRUNCATE\b|\bDROP\s+TABLE\b/i', $source), "{$file} has no TRUNCATE or DROP");
        // And no import-owned column is written: the only UPDATEs are the
        // tracking columns and the regeneration count.
        assertSame(0, preg_match('/UPDATE\s+`?member`?\b/i', $source), "{$file} never writes member");
    }
});

test('the change summary reads the line in the form\'s own vocabulary', function (): void {
    $line = static fn (array $o): array => $o + [
        'type' => '', 'new_title' => '', 'previous_title' => '', 'remove_reason' => '', 'new_subcommittee' => '',
    ];

    assertSame('Addition — Committee Member', RcfTracking::changeSummary($line(['type' => 'A', 'new_title' => 'Committee Member'])));
    assertSame('Remove — Member Resigned', RcfTracking::changeSummary($line(['type' => 'R', 'remove_reason' => '4'])));
    assertSame('Title Change — Committee Member → Captain',
        RcfTracking::changeSummary($line(['type' => 'T', 'previous_title' => 'Committee Member', 'new_title' => 'Captain'])));
    assertSame('Sub-Committee Change (Team Change) — TK Bus Ops Team B',
        RcfTracking::changeSummary($line(['type' => 'S', 'new_subcommittee' => 'TK Bus Ops Team B'])));
    assertSame('No type', RcfTracking::changeSummary($line([])));
});

// ---------------------------------------------------------------------------
// The database under test
// ---------------------------------------------------------------------------

function tk_pdo(): PDO
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
        $pdo = tk_app()->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    return $pdo;
}

function tk_teardown(PDO $pdo): void
{
    $members = "SELECT id FROM member WHERE member_number LIKE 'TK%'";
    $users   = "SELECT id FROM app_user WHERE member_id IN ({$members})";
    $forms   = "SELECT id FROM rcf WHERE generated_by IN ({$users})";

    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
    $pdo->exec("DELETE FROM rcf_row WHERE rcf_id IN ({$forms})");
    $pdo->exec("DELETE FROM rcf WHERE generated_by IN ({$users})");
    $pdo->exec("DELETE FROM import_change WHERE member_number LIKE 'TK%'");
    $pdo->exec("DELETE FROM import_batch WHERE filename LIKE 'TK-%'");
    $pdo->exec("DELETE FROM contact_log WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'TK%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'TK %'");
    $pdo->exec("DELETE FROM division WHERE name LIKE 'TK %'");
}

/**
 * One division, two teams, and four people with accounts at three levels:
 *
 *   TK Logistics Division   TK Bus Ops Team A   cap (Captain, Officer), m1, m2
 *                           TK Bus Ops Team B   vc (Vice Chairman, Senior Officer), m3
 *   dc — the Division Chairman, an Executive Officer, on Team A
 *
 * The Captain and the Vice Chairman each make forms; the Division Chairman
 * sees both; the Captain sees only their own.
 *
 * @return array<string, mixed>
 */
function tk_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = tk_pdo();
    tk_teardown($pdo);

    $pdo->prepare('INSERT INTO division (name, is_placeholder) VALUES (:name, 0)')->execute([':name' => 'TK Logistics Division']);
    $division = (int) $pdo->lastInsertId();

    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)');
    $teams      = [];
    foreach (['a' => 'TK Bus Ops Team A', 'b' => 'TK Bus Ops Team B'] as $key => $name) {
        $insertTeam->execute([':name' => $name, ':division' => $division]);
        $teams[$key] = (int) $pdo->lastInsertId();
    }

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, full_name,'
        . ' division_id, team_id, phone, phone_e164, phone_type, email, title, title_level)'
        . " VALUES (:number, :first, :last, '', :full, :division, :team,"
        . " '(555) 555-0121', '+15555550121', 'CELL PHONE', :email, :title, :level)"
    );
    $specs = [
        'cap' => ['a', 'Alexis', 'Captain',  'Captain',                'officer'],
        'vc'  => ['b', 'Erin',   'Delta',    'Vice Chairman',          'senior_officer'],
        'dc'  => ['a', 'Dana',   'Chair',    'Division Chairman',      'executive_officer'],
        'm1'  => ['a', 'Robert', 'Alpha',    'Committee Member',       'member'],
        'm2'  => ['a', 'Carol',  'Bravo',    'Committee Member',       'member'],
        'm3'  => ['b', 'Frank',  'Echo',     'Committee Member',       'member'],
    ];
    $members = [];
    $n       = 0;
    foreach ($specs as $key => [$team, $first, $last, $title, $level]) {
        $n++;
        $number = sprintf('TK%06d', $n);
        $insertMember->execute([
            ':number' => $number, ':first' => $first, ':last' => $last, ':full' => $first . ' ' . $last,
            ':division' => $division, ':team' => $teams[$team],
            ':email' => strtolower($first) . '@example.com', ':title' => $title, ':level' => $level,
        ]);
        $members[$key] = ['id' => (int) $pdo->lastInsertId(), 'number' => $number];
    }

    $insertUser = $pdo->prepare(
        "INSERT INTO app_user (member_id, level, password_hash, must_change_password, is_active) VALUES (:m, :l, '*', 0, 1)"
    );
    $users = [];
    foreach (['cap' => 'officer', 'vc' => 'senior_officer', 'dc' => 'executive_officer'] as $key => $level) {
        $insertUser->execute([':m' => $members[$key]['id'], ':l' => $level]);
        $users[$key] = (int) $pdo->lastInsertId();
    }

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();

    return $fixture = ['division' => $division, 'teams' => $teams, 'members' => $members, 'users' => $users, 'year' => $year];
}

function tk_user(string $key): User
{
    $f     = tk_fixture();
    $level = match ($key) {
        'cap' => Level::Officer,
        'vc'  => Level::SeniorOfficer,
        default => Level::ExecutiveOfficer,
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
        scopeTeamIds: $level === Level::SeniorOfficer ? [$team] : [],
    );
}

/** The Captain's form: a title change for m1 and an addition typed in by hand. */
function tk_captain_form(): array
{
    $f = tk_fixture();

    return (new RcfPage(tk_pdo()))->formFromInput(tk_user('cap'), [
        'subcommittee' => 't:' . $f['teams']['a'],
        'date'         => '2027-03-01',
        'row'          => [
            0 => ['type' => 'T', 'member' => $f['members']['m1']['number'], 'new_title' => 'Assistant Captain'],
            // Row three, not two: a gap the regeneration has to print blank.
            2 => ['type' => 'A', 'member' => 'Newcomer Sample', 'sponsor' => 'Erin Delta', 'rookie' => '1', 'wait_list' => '1'],
        ],
    ]);
}

/** Forgets every form the fixture's users kept, so a test can count. */
function tk_reset_forms(): void
{
    $f     = tk_fixture();
    $users = implode(', ', array_map('intval', $f['users']));
    tk_pdo()->exec("DELETE FROM rcf_row WHERE rcf_id IN (SELECT id FROM rcf WHERE generated_by IN ({$users}))");
    tk_pdo()->exec("DELETE FROM rcf WHERE generated_by IN ({$users})");
}

/** Keeps a form for a user, and returns its id. */
function tk_keep(string $key, ?array $form = null): int
{
    $f    = tk_fixture();
    $form ??= tk_captain_form();

    return (new RcfStore(tk_pdo()))->store(tk_user($key), $f['year'], $form);
}

// ---------------------------------------------------------------------------
// Keeping a form
// ---------------------------------------------------------------------------

test('a produced form is kept as printed: header, filled rows only, at their positions', function (): void {
    $f    = tk_fixture();
    $pdo  = tk_pdo();
    $form = tk_captain_form();
    $id   = tk_keep('cap', $form);

    $kept = $pdo->query("SELECT * FROM rcf WHERE id = {$id}")->fetch();
    assertSame('Alexis Captain, Captain', $kept['submitter'], 'G4 as printed');
    assertSame($f['members']['cap']['number'], $kept['submitter_number']);
    assertSame('2027-03-01', $kept['form_date'], 'D5, as the ISO day it was made from');
    assertSame('TK Logistics Division - TK Bus Ops Team A', $kept['subcommittee'], 'G5 as printed');
    assertSame($f['teams']['a'], (int) $kept['team_id'], 'and what it named');
    assertSame(null, $kept['division_id']);
    assertSame(2, (int) $kept['row_count'], 'two filled rows of twenty-five');
    assertSame($f['users']['cap'], (int) $kept['generated_by']);
    assertSame($f['year'], (int) $kept['show_year_id']);

    $rows = $pdo->query("SELECT * FROM rcf_row WHERE rcf_id = {$id} ORDER BY position")->fetchAll();
    assertSame(2, count($rows), 'blank rows are not stored');
    assertSame([1, 3], array_map(static fn (array $r): int => (int) $r['position'], $rows), 'at the positions typed');

    assertSame('T', $rows[0]['type']);
    assertSame($f['members']['m1']['id'], (int) $rows[0]['member_id'], 'the number resolved to the member');
    assertSame('Robert Alpha', $rows[0]['member_name'], 'the name the roster spelled');
    assertSame('Committee Member', $rows[0]['previous_title'], 'filled in from the roster at the time');
    assertSame('Assistant Captain', $rows[0]['new_title']);

    assertSame('A', $rows[1]['type']);
    assertSame(null, $rows[1]['member_id'], 'a newcomer typed by name has no row to point at');
    assertSame('', $rows[1]['member_number']);
    assertSame('Newcomer Sample', $rows[1]['member_name']);
    assertSame(1, (int) $rows[1]['rookie']);
    assertSame(1, (int) $rows[1]['wait_list']);
    assertSame('Erin Delta', $rows[1]['sponsor']);

    // Tracking starts empty: nothing is assumed about where a form went.
    foreach ($rows as $row) {
        assertSame('', $row['serial']);
        assertSame(null, $row['sent_to_dc_on']);
        assertSame(null, $row['sent_to_rosters_on']);
        assertSame(null, $row['tracked_by']);
    }
});

test('a kept form regenerates the same sheet, cell for cell, whatever the roster now says', function (): void {
    $f    = tk_fixture();
    $pdo  = tk_pdo();
    $form = tk_captain_form();
    $id   = tk_keep('cap', $form);

    // The roster moves on: the title change is granted. The kept form must
    // still print what was asked, not what is now true.
    $pdo->exec("UPDATE member SET title = 'Assistant Captain' WHERE id = {$f['members']['m1']['id']}");

    $rebuilt = (new RcfStore($pdo))->form($id);
    assertTrue($rebuilt !== null);
    assertSame($form['year'], $rebuilt['year']);
    assertSame($form['submitter'], $rebuilt['submitter']);
    assertSame($form['date'], $rebuilt['date'], 'the American date, as the cell holds it');
    assertSame($form['subcommittee'], $rebuilt['subcommittee']);
    assertSame(RosterChangeForm::ROWS, count($rebuilt['entries']));
    assertSame($form['entries'], $rebuilt['entries'], 'every entry, blank ones included, identical');

    $sheet = static function (array $form): string {
        $s = FormSheet::create(
            sys_get_temp_dir(),
            tk_app()->path('app/templates/rcf/styles.xml'),
            'Sheet1',
            tk_app()->path('app/templates/rcf/featurePropertyBag.xml')
        );
        RosterChangeForm::draw($s, $form);

        return $s->sheet();
    };
    assertSame($sheet($form), $sheet($rebuilt), 'the sheet XML is byte for byte the original');

    assertSame(null, (new RcfStore($pdo))->form(0), 'no such form is null');

    // The roster as the later tests expect it.
    $pdo->exec("UPDATE member SET title = 'Committee Member' WHERE id = {$f['members']['m1']['id']}");
});

// ---------------------------------------------------------------------------
// Who sees what
// ---------------------------------------------------------------------------

test('an officer sees the forms they made; an Executive Officer sees everybody else\'s below', function (): void {
    $f       = tk_fixture();
    $capForm = tk_keep('cap');
    $vcForm  = tk_keep('vc', (new RcfPage(tk_pdo()))->formFromInput(tk_user('vc'), [
        'subcommittee' => 't:' . $f['teams']['b'],
        'row'          => [0 => ['type' => 'R', 'member' => $f['members']['m3']['number'], 'remove_reason' => '4']],
    ]));

    $tracking = new RcfTracking(tk_pdo());

    $mine = $tracking->mine(tk_user('cap'));
    assertTrue(in_array($capForm, array_column($mine, 'id'), true), 'the Captain sees their own');
    assertTrue(!in_array($vcForm, array_column($mine, 'id'), true), 'and not the Vice Chairman\'s');
    assertSame(null, $tracking->others(tk_user('cap')), 'an Officer has no second group');
    assertSame(null, $tracking->others(tk_user('vc')), 'nor does a Senior Officer');

    $others = $tracking->others(tk_user('dc'));
    assertTrue($others !== null, 'a Division Chairman has');
    $ids = array_column($others['forms'], 'id');
    assertTrue(in_array($capForm, $ids, true) && in_array($vcForm, $ids, true), 'and it holds both');
    assertSame('Alexis Captain', $others['forms'][array_search($capForm, $ids, true)]['generator_name'], 'with who made it');
    assertSame([], $tracking->mine(tk_user('dc')), 'nothing of their own is repeated there');

    // One form: the same rule, and the answer for nobody else is null.
    assertTrue($tracking->one(tk_user('cap'), $capForm) !== null);
    assertSame(null, $tracking->one(tk_user('cap'), $vcForm), 'somebody else\'s form does not exist for an Officer');
    assertSame(null, $tracking->one(tk_user('vc'), $capForm));
    assertTrue($tracking->one(tk_user('dc'), $vcForm) !== null);
    assertSame(null, $tracking->one(tk_user('dc'), 0));
});

test('the list counts where each form\'s lines have got to', function (): void {
    $id       = tk_keep('cap');
    $tracking = new RcfTracking(tk_pdo());
    $pdo      = tk_pdo();

    $before = $tracking->one(tk_user('cap'), $id)['form'];
    assertSame(2, $before['rows']);
    assertSame(0, $before['dc']);
    assertSame(0, $before['rosters']);
    assertSame(0, $before['numbered']);
    assertSame(0, $before['landed']);
    assertSame([], $before['serials']);

    $pdo->exec("UPDATE rcf_row SET serial = '2027-14', sent_to_dc_on = '2027-03-02' WHERE rcf_id = {$id} AND position = 1");

    $after = $tracking->one(tk_user('cap'), $id)['form'];
    assertSame(1, $after['dc']);
    assertSame(1, $after['numbered']);
    assertSame(['2027-14'], $after['serials']);
});

// ---------------------------------------------------------------------------
// Tracking: the save, the whole-form row, and the today buttons
// ---------------------------------------------------------------------------

test('the whole-form row wins over the lines; a line alone changes only itself; an emptied date clears', function (): void {
    $id       = tk_keep('cap');
    $pdo      = tk_pdo();
    $tracking = new RcfTracking($pdo);
    $rowIds   = array_column($pdo->query("SELECT id FROM rcf_row WHERE rcf_id = {$id} ORDER BY position")->fetchAll(), 'id');
    [$r1, $r3] = array_map('intval', $rowIds);

    // Save 1: the every-line row numbers the whole form and dates it to the
    // DC; a line's own field, typed at the same time, loses.
    $changed = $tracking->track(tk_user('cap'), $id, ['track' => [
        'all' => ['serial' => '  2027-14 ', 'dc' => '2027-03-02', 'rosters' => ''],
        $r1   => ['serial' => 'ignored', 'dc' => '2027-01-01', 'rosters' => ''],
        $r3   => ['serial' => '', 'dc' => '', 'rosters' => ''],
    ]]);
    assertSame(2, $changed);

    $rows = $pdo->query("SELECT * FROM rcf_row WHERE rcf_id = {$id} ORDER BY position")->fetchAll();
    assertSame('2027-14', $rows[0]['serial'], 'collapsed and trimmed, and the whole-form value');
    assertSame('2027-14', $rows[1]['serial']);
    assertSame('2027-03-02', $rows[0]['sent_to_dc_on']);
    assertSame('2027-03-02', $rows[1]['sent_to_dc_on']);
    assertSame(null, $rows[0]['sent_to_rosters_on'], 'a blank whole-form date sets nothing');
    assertSame(tk_fixture()['users']['cap'], (int) $rows[0]['tracked_by']);
    assertTrue($rows[0]['tracked_at'] !== null);

    // Save 2: nothing in the every-line row, so each line is written as it
    // came back — one line goes to Rosters, the other's DC date is cleared.
    $changed = $tracking->track(tk_user('cap'), $id, ['track' => [
        'all' => ['serial' => '', 'dc' => '', 'rosters' => ''],
        $r1   => ['serial' => '2027-14', 'dc' => '2027-03-02', 'rosters' => '2027-03-05'],
        $r3   => ['serial' => '2027-15', 'dc' => '', 'rosters' => ''],
    ]]);
    assertSame(2, $changed);

    $rows = $pdo->query("SELECT * FROM rcf_row WHERE rcf_id = {$id} ORDER BY position")->fetchAll();
    assertSame('2027-03-05', $rows[0]['sent_to_rosters_on']);
    assertSame('2027-03-02', $rows[0]['sent_to_dc_on'], 'untouched');
    assertSame('2027-15', $rows[1]['serial'], 'the exception: the DC split the form');
    assertSame(null, $rows[1]['sent_to_dc_on'], 'an emptied date box clears the date');

    // Save 3: the same thing again changes nothing and writes nothing.
    $changed = $tracking->track(tk_user('cap'), $id, ['track' => [
        $r1 => ['serial' => '2027-14', 'dc' => '2027-03-02', 'rosters' => '2027-03-05'],
        $r3 => ['serial' => '2027-15', 'dc' => '', 'rosters' => ''],
    ]]);
    assertSame(0, $changed);

    // A value that is not a date keeps what was there; a line on another
    // form, or one that does not exist, writes nothing anywhere.
    $changed = $tracking->track(tk_user('cap'), $id, ['track' => [
        $r1     => ['dc' => 'yesterday'],
        999999  => ['serial' => 'nope', 'dc' => '2027-01-01'],
    ]]);
    assertSame(0, $changed);
    assertSame('2027-03-02', $pdo->query("SELECT sent_to_dc_on FROM rcf_row WHERE id = {$r1}")->fetchColumn());

    // An RCF number is bounded, not refused.
    $tracking->track(tk_user('cap'), $id, ['track' => [$r1 => ['serial' => str_repeat('9', 60)]]]);
    assertSame(RcfTracking::SERIAL_MAX, strlen((string) $pdo->query("SELECT serial FROM rcf_row WHERE id = {$r1}")->fetchColumn()));

    // Two audit rows and a third for the bound — never one for a save that
    // changed nothing — each naming the lines, before and after.
    $audits = $pdo->query(
        "SELECT before_json, after_json FROM audit_log WHERE action = 'track_form' AND entity = 'rcf'"
        . " AND entity_id = '{$id}' ORDER BY id"
    )->fetchAll();
    assertSame(3, count($audits));
    $first = json_decode((string) $audits[0]['after_json'], true);
    assertSame('2027-14', $first['rows']['1']['serial']);
    assertSame('2027-03-02', $first['rows']['3']['sent_to_dc_on']);
    $firstBefore = json_decode((string) $audits[0]['before_json'], true);
    assertSame('', $firstBefore['rows']['1']['serial']);
    assertSame(null, $firstBefore['rows']['1']['sent_to_dc_on']);
});

test('the today button dates every line not yet dated, and never the ones that are', function (): void {
    $id       = tk_keep('cap');
    $pdo      = tk_pdo();
    $tracking = new RcfTracking($pdo, new DateTimeZone('America/Chicago'));

    $pdo->exec("UPDATE rcf_row SET sent_to_dc_on = '2027-03-02' WHERE rcf_id = {$id} AND position = 1");

    assertSame(1, $tracking->markToday(tk_user('cap'), $id, 'dc'), 'one line had no date');
    $today = (new DateTimeImmutable('today', new DateTimeZone('America/Chicago')))->format('Y-m-d');
    $rows  = $pdo->query("SELECT sent_to_dc_on FROM rcf_row WHERE rcf_id = {$id} ORDER BY position")->fetchAll();
    assertSame('2027-03-02', $rows[0]['sent_to_dc_on'], 'the dated line keeps its day');
    assertSame($today, $rows[1]['sent_to_dc_on'], 'the undated one is today, in Houston');

    assertSame(0, $tracking->markToday(tk_user('cap'), $id, 'dc'), 'pressing it again changes nothing');
    assertSame(2, $tracking->markToday(tk_user('cap'), $id, 'rosters'));
    assertSame(null, $tracking->markToday(tk_user('cap'), $id, 'moon'), 'a step that does not exist is refused');
});

test('somebody who may not see a form may not track it, and the Division Chairman may track anybody\'s', function (): void {
    $id       = tk_keep('cap');
    $tracking = new RcfTracking(tk_pdo());

    assertSame(null, $tracking->track(tk_user('vc'), $id, ['track' => ['all' => ['serial' => 'X']]]));
    assertSame(null, $tracking->markToday(tk_user('vc'), $id, 'dc'));
    assertSame(2, $tracking->track(tk_user('dc'), $id, ['track' => ['all' => ['serial' => 'DC-1']]]),
        'the Division Chairman numbers the Captain\'s form');
    assertSame('DC-1', $tracking->one(tk_user('cap'), $id)['rows'][0]['serial'], 'and the Captain sees the number');
    assertSame('Dana Chair', $tracking->one(tk_user('cap'), $id)['rows'][0]['tracked_by_name'], 'and who wrote it');
});

// ---------------------------------------------------------------------------
// The derived step: the roster shows it
// ---------------------------------------------------------------------------

/** An applied import that changed one thing about one member number, after a moment. */
function tk_import(string $number, string $kind, string $field, string $occurredAt): int
{
    $f   = tk_fixture();
    $pdo = tk_pdo();

    $pdo->prepare(
        'INSERT INTO import_batch (show_year_id, mode, filename, sha256, dry_run, applied_at)'
        . " VALUES (:year, 'update', :file, :sha, 0, :at)"
    )->execute([':year' => $f['year'], ':file' => 'TK-' . uniqid() . '.xls', ':sha' => str_repeat('0', 64), ':at' => $occurredAt]);
    $batch = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO import_change (import_batch_id, member_id, member_number, kind, field, occurred_at)'
        . ' VALUES (:batch, NULL, :number, :kind, :field, :at)'
    )->execute([':batch' => $batch, ':number' => $number, ':kind' => $kind, ':field' => $field, ':at' => $occurredAt]);

    return $batch;
}

test('a line has landed when an import since says what it asked for, and not before', function (): void {
    $f        = tk_fixture();
    $pdo      = tk_pdo();
    $tracking = new RcfTracking($pdo);
    $m1       = $f['members']['m1']['number'];
    $m2       = $f['members']['m2']['number'];

    // A title change for m1 and a removal for m2, generated "yesterday".
    $id = tk_keep('cap', (new RcfPage($pdo))->formFromInput(tk_user('cap'), [
        'subcommittee' => 't:' . $f['teams']['a'],
        'row'          => [
            0 => ['type' => 'T', 'member' => $m1, 'new_title' => 'Assistant Captain'],
            1 => ['type' => 'R', 'member' => $m2, 'remove_reason' => '4'],
        ],
    ]));
    $pdo->exec("UPDATE rcf SET generated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = {$id}");

    $rows = $tracking->one(tk_user('cap'), $id)['rows'];
    assertSame(null, $rows[0]['landed']);
    assertSame(null, $rows[1]['landed']);

    // An import BEFORE the form changed m1's title: not this line's news.
    tk_import($m1, 'updated', 'title', (new DateTimeImmutable('-3 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'));
    assertSame(null, $tracking->one(tk_user('cap'), $id)['rows'][0]['landed'], 'an earlier import does not count');

    // The wrong kind of change after the form does not either.
    tk_import($m1, 'updated', 'team', (new DateTimeImmutable('-2 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'));
    assertSame(null, $tracking->one(tk_user('cap'), $id)['rows'][0]['landed'], 'a team change is not a title change');

    // The right one does, and the FIRST such import is the day it landed.
    $at    = (new DateTimeImmutable('-1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $batch = tk_import($m1, 'updated', 'title', $at);
    tk_import($m1, 'updated', 'title', (new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'));
    $landed = $tracking->one(tk_user('cap'), $id)['rows'][0]['landed'];
    assertSame($at, $landed['at']);
    assertSame($batch, $landed['batch']);

    // A removal lands when the member is dropped.
    assertSame(null, $tracking->one(tk_user('cap'), $id)['rows'][1]['landed']);
    tk_import($m2, 'dropped', '', (new DateTimeImmutable('-30 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'));
    assertTrue($tracking->one(tk_user('cap'), $id)['rows'][1]['landed'] !== null, 'dropped is what a removal asked for');

    assertSame(2, $tracking->one(tk_user('cap'), $id)['form']['landed'], 'and the form counts both');
});

// ---------------------------------------------------------------------------
// Finding a member
// ---------------------------------------------------------------------------

test('a member is found on any form the caller may see, by number or by name, every word landing', function (): void {
    $f        = tk_fixture();
    tk_reset_forms();
    $capForm  = tk_keep('cap');
    $vcForm   = tk_keep('vc', (new RcfPage(tk_pdo()))->formFromInput(tk_user('vc'), [
        'subcommittee' => 't:' . $f['teams']['b'],
        'row'          => [0 => ['type' => 'R', 'member' => $f['members']['m3']['number'], 'remove_reason' => '4']],
    ]));
    $tracking = new RcfTracking(tk_pdo());

    $byNumber = $tracking->search(tk_user('cap'), $f['members']['m1']['number']);
    assertSame(1, count($byNumber['matches']));
    assertSame($capForm, $byNumber['matches'][0]['rcf_id']);
    assertSame('Title Change — Committee Member → Assistant Captain', $byNumber['matches'][0]['change']);

    assertSame(1, count($tracking->search(tk_user('cap'), 'Alpha Robert')['matches']), 'both words, either order');
    assertSame(0, count($tracking->search(tk_user('cap'), 'Robert Echo')['matches']), 'and both must land');
    assertSame(1, count($tracking->search(tk_user('cap'), 'Newcomer')['matches']), 'a name typed in is found by its name');

    // The Captain cannot find the Vice Chairman's member; the Division
    // Chairman can find everyone's.
    assertSame(0, count($tracking->search(tk_user('cap'), 'Frank Echo')['matches']));
    $dc = $tracking->search(tk_user('dc'), 'Frank Echo');
    assertSame(1, count($dc['matches']));
    assertSame($vcForm, $dc['matches'][0]['rcf_id']);

    $short = $tracking->search(tk_user('cap'), 'Al');
    assertSame(true, $short['too_short']);
    assertSame([], $short['matches']);
    assertSame(false, $tracking->search(tk_user('cap'), '')['too_short'], 'nothing typed is not too short');
});

test('the member card lists every form a member is on, saying which the caller may open', function (): void {
    $f       = tk_fixture();
    tk_reset_forms();
    $capForm = tk_keep('cap');
    $tracking = new RcfTracking(tk_pdo());

    // The Vice Chairman also puts m1 on a form — a transfer onto their team.
    $vcForm = tk_keep('vc', (new RcfPage(tk_pdo()))->formFromInput(tk_user('vc'), [
        'subcommittee' => 't:' . $f['teams']['b'],
        'row'          => [0 => ['type' => 'S', 'member' => $f['members']['m1']['number'] . ' - Robert Alpha', 'new_subcommittee' => 'TK Bus Ops Team B']],
    ]));

    $lines = $tracking->forMember(tk_user('cap'), $f['members']['m1']['id'], $f['members']['m1']['number']);
    assertSame(2, count($lines), 'both forms, though the Captain made only one');
    assertSame($vcForm, $lines[0]['rcf_id'], 'newest first');
    assertSame(false, $lines[0]['viewable'], 'the Captain may not open the Vice Chairman\'s');
    assertSame(true, $lines[1]['viewable']);
    assertSame($capForm, $lines[1]['rcf_id']);

    // And the card carries them.
    $page = (new MemberPage(tk_pdo()))->page(tk_user('cap'), $f['year'], $f['members']['m1']['id']);
    assertTrue($page !== null);
    assertSame(2, count($page['rcfs']));
});

// ---------------------------------------------------------------------------
// The screens
// ---------------------------------------------------------------------------

function tk_render(string $view, string $title, array $data): string
{
    $app = tk_app();
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

test('the list renders its three parts and escapes what it shows', function (): void {
    $f        = tk_fixture();
    $capForm  = tk_keep('cap');
    $tracking = new RcfTracking(tk_pdo());

    $html = tk_render('rcfs', 'Track RCFs', [
        'user'     => tk_user('dc'),
        'tracking' => [
            'mine'   => $tracking->mine(tk_user('dc')),
            'others' => $tracking->others(tk_user('dc')),
            'search' => $tracking->search(tk_user('dc'), 'Robert <Alpha>'),
        ],
    ]);

    assertTrue(str_contains($html, '<h1>Track RCFs</h1>'));
    assertTrue(str_contains($html, 'name="member"'), 'the search box');
    assertTrue(str_contains($html, 'Everyone else&rsquo;s RCFs'), 'the second group, for an Executive');
    assertTrue(str_contains($html, 'Alexis Captain'), 'with who made each form');
    assertTrue(str_contains($html, 'rcf?id=' . $capForm), 'each form links to its page');
    assertTrue(str_contains($html, 'Robert &lt;Alpha&gt;'), 'the term is escaped');
    assertSame(0, substr_count($html, '<Alpha>'), 'and never raw');
    assertSame(0, substr_count($html, '<script'), 'no JavaScript');
    assertTrue(strlen($html) < 100 * 1024, 'first paint is ' . strlen($html) . ' bytes');

    $officer = tk_render('rcfs', 'Track RCFs', [
        'user'     => tk_user('cap'),
        'tracking' => [
            'mine'   => $tracking->mine(tk_user('cap')),
            'others' => null,
            'search' => $tracking->search(tk_user('cap'), ''),
        ],
    ]);
    assertSame(0, substr_count($officer, 'Everyone else'), 'an Officer has no second group');
    assertTrue(str_contains($officer, 'Your RCFs'));
});

test('the form\'s page renders every line with its three controls, the every-line row, and the two today buttons', function (): void {
    $id       = tk_keep('cap');
    $tracking = new RcfTracking(tk_pdo());

    $html = tk_render('rcf', 'Roster Change Form', ['user' => tk_user('cap'), 'rcf' => $tracking->one(tk_user('cap'), $id)]);

    assertTrue(str_contains($html, 'TK Logistics Division - TK Bus Ops Team A'));
    assertTrue(str_contains($html, 'name="track[all][serial]"'), 'the every-line row');
    assertSame(3, substr_count($html, '][serial]"'), 'every line and the whole-form row carry an RCF number box');
    assertSame(3, substr_count($html, '][dc]"'));
    assertSame(3, substr_count($html, '][rosters]"'));
    assertSame(1, substr_count($html, 'value="dc_today"'));
    assertSame(1, substr_count($html, 'value="rosters_today"'));
    assertSame(1, substr_count($html, 'value="download"'), 'Download again is a POST');
    assertTrue(str_contains($html, 'Newcomer Sample'));
    assertTrue(str_contains($html, 'no member number to watch for'), 'a line with no number says why it cannot land');
    assertTrue(str_contains($html, 'from=rcf'), 'a member on the form links to their card, with the way back');
    assertSame(0, substr_count($html, '<script'));
    assertTrue(strlen($html) < 100 * 1024, 'first paint is ' . strlen($html) . ' bytes');
});

test('rcf tracking fixtures are cleaned up', function (): void {
    $pdo = tk_pdo();
    tk_fixture();
    tk_teardown($pdo);

    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM member WHERE member_number LIKE 'TK%'")->fetchColumn());
    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM import_change WHERE member_number LIKE 'TK%'")->fetchColumn());
});
