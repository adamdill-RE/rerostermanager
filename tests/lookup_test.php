<?php

declare(strict_types=1);

/**
 * Look Up Members (Phase 12, spec-v2 §13): a pasted list of member numbers,
 * read the way a person meant it, answered with where each one stands —
 * placement, on the roster or not, first seen, what the last import
 * changed, every Roster Change Form that named them — in the order given.
 *
 * The fixture is generated and the expectations are TRANSCRIBED beside it.
 * Generated, never real: this repository is public. Member numbers here are
 * 'LK000001' and one invented digits-only number with leading zeros,
 * addresses are @example.com and phones are the reserved (555) 555-01xx
 * fiction range.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\Admin\LookupPage;
use Rerm\Admin\MemberNumbers;
use Rerm\App;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Scope;
use Rerm\Auth\User;
use Rerm\Forms\RcfTracking;
use Rerm\Routes;

function lk_app(): App
{
    return $GLOBALS['rerm_app'];
}

function lk_source(string $relative): string
{
    return (string) file_get_contents(__DIR__ . '/../' . $relative);
}

// ---------------------------------------------------------------------------
// What needs no database: the route, the capability, the tile, the verbs
// ---------------------------------------------------------------------------

test('the lookup route is guarded by its own Admin / Everywhere capability', function (): void {
    assertSame(Capability::LookUpMembers->value, Routes::guard('lookup'));
    assertSame('look_up_members', Capability::LookUpMembers->value);

    // spec-v2 §13.3, transcribed: Admin / Everywhere.
    assertSame(Level::Admin, Capability::LookUpMembers->minimumLevel());
    assertSame(Scope::Everywhere, Capability::LookUpMembers->scope());

    $user = static fn (Level $l): User => new User(1, 1, 'LK000000', $l, null, null, false, 'L');
    assertSame(false, Access::mayUse($user(Level::ExecutiveOfficer), Capability::LookUpMembers),
        'a Division Chairman does not hold it');
    assertSame(true, Access::mayUse($user(Level::Admin), Capability::LookUpMembers));
    assertSame(true, Access::allows($user(Level::Admin), Capability::LookUpMembers), 'Everywhere: no subject needed');
});

test('the menu tile is behind look_up_members, in Administer, and points at the screen', function (): void {
    $menu = lk_source('app/views/menu.php');
    assertSame(1, preg_match(
        "/Capability::LookUpMembers,\\s+'label' => 'Look Up Members',\\s+'route' => 'lookup',.*'group' => 'administer'/",
        $menu
    ), 'one tile, one line, the Administer group');
});

test('the POST is CSRF-checked, nothing writes, and nothing redirects', function (): void {
    $index = lk_source('public/index.php');
    $act   = substr($index, (int) strpos($index, 'function lookup_act('));
    $act   = substr($act, 0, (int) strpos($act, "\n}\n") + 3);

    assertTrue(str_contains($act, 'Rerm\Csrf::check()'), 'every POST checks the token');
    assertTrue(str_contains($act, 'stale_form_notice()'), 'and a stale token draws the box again with the list in it');
    assertSame(0, substr_count($act, 'redirect('), 'a read re-renders; there is no state change to get past');
    assertTrue(str_contains($act, "\$_GET)['numbers']"), 'a GET with numbers is looked up too');

    assertTrue(str_contains(lk_source('app/views/lookup.php'), 'Csrf::field()'), 'the form carries the token');

    // Read-only, in the sense admin_test reads every write path for.
    foreach (['app/src/Admin/LookupPage.php', 'app/src/Admin/MemberNumbers.php'] as $file) {
        $source = lk_source($file);
        assertSame(0, preg_match('/\b(INSERT|UPDATE|DELETE|TRUNCATE|DROP|ALTER)\b/', $source), "{$file} never writes");
    }

    // The way back from a member card is the numbers, re-whitelisted as text.
    assertTrue(str_contains($index, "'lookup'    => ['lookup', 'Look Up Members']"), 'a card opened from the list knows the way back');
    assertTrue(str_contains($index, "'lookup'    => return_query(\$state, ['numbers' => ['text' => 400]])"), 'and the list rides along, bounded');
});

// ---------------------------------------------------------------------------
// Reading the list (spec-v2 §13.2) — transcribed case by case
// ---------------------------------------------------------------------------

test('commas, spaces, new lines, semicolons and pipes all separate; the order is kept', function (): void {
    $numbers = static fn (string $raw): array => MemberNumbers::parse($raw)['numbers'];

    assertSame(['1234567', '2345678'], $numbers('1234567, 2345678'));
    assertSame(['1234567', '2345678'], $numbers('1234567,2345678'));
    assertSame(['1234567', '2345678'], $numbers('1234567 ,2345678'));
    assertSame(['1234567', '2345678'], $numbers('1234567 2345678'));
    assertSame(['1234567', '2345678'], $numbers("1234567\r\n2345678\n"), 'a column pasted from a spreadsheet');
    assertSame(['1234567', '2345678', '3456789', '4567890'], $numbers("1234567; 2345678 | 3456789 / 4567890\t"));
    assertSame(['2345678', '1234567'], $numbers('2345678, 1234567'), 'the order given, not sorted');
    assertSame([], $numbers(''));
    assertSame([], $numbers("  ,,, \n ; "));
});

test('quotes, brackets, a hash sign and sentence punctuation are read past', function (): void {
    $parsed = MemberNumbers::parse('"1234567", (2345678) #3456789 №4567890. \'5678901\' [6789012]');

    assertSame(['1234567', '2345678', '3456789', '4567890', '5678901', '6789012'], $parsed['numbers']);
    assertSame([], $parsed['read_as'], 'trimming punctuation is not reshaping a number');
    assertSame([], $parsed['ignored']);
});

test('what Excel does to a number is undone, and said', function (): void {
    // General format with thousands separators, as copied from the cell.
    $parsed = MemberNumbers::parse("1,234,567\n2,345,678");
    assertSame(['1234567', '2345678'], $parsed['numbers']);
    assertSame(['1,234,567' => '1234567', '2,345,678' => '2345678'], $parsed['read_as']);

    // A float, and the same number in scientific notation: both 1234567,
    // so the second is a repeat.
    $parsed = MemberNumbers::parse('1234567.0, 1.234567E+6, 1234567.00');
    assertSame(['1234567'], $parsed['numbers']);
    assertSame(['1234567' => 2], $parsed['duplicates']);
    assertSame(['1234567.0' => '1234567', '1.234567E+6' => '1234567', '1234567.00' => '1234567'], $parsed['read_as']);

    assertSame('1234567', MemberNumbers::unformat('1.234567e6'));
    assertSame('1200000', MemberNumbers::unformat('1.2E+6'));
    assertSame('1000000', MemberNumbers::unformat('1E+6'));
    assertSame('1.2345678E+6', MemberNumbers::unformat('1.2345678E+6'), 'not an integer — left as it came');
    assertSame('1234567.5', MemberNumbers::unformat('1234567.5'), 'only a zero fraction is an artefact');
});

test('leading zeros are kept, and case does not make two numbers of one', function (): void {
    $parsed = MemberNumbers::parse('0123456, 123456');
    assertSame(['0123456', '123456'], $parsed['numbers'], 'the natural key is a string');

    $parsed = MemberNumbers::parse('TK000001, tk000001, Tk000001');
    assertSame(['TK000001'], $parsed['numbers'], 'the first spelling is kept');
    assertSame(['TK000001' => 2], $parsed['duplicates']);
});

test('words are set aside and named, never silently dropped', function (): void {
    $parsed = MemberNumbers::parse("Customer Number\n1234567\n2345678 and 3456789\nN/A");
    assertSame(['1234567', '2345678', '3456789'], $parsed['numbers']);
    assertSame(['Customer', 'Number', 'and', 'N', 'A'], $parsed['ignored']);
    assertSame(3, $parsed['given']);

    $parsed = MemberNumbers::parse('nothing here at all');
    assertSame([], $parsed['numbers']);
    assertSame(0, $parsed['given']);
    assertSame(4, count($parsed['ignored']));
});

test('a run of digits is never split, and the hint says what it might be', function (): void {
    $parsed = MemberNumbers::parse('12345672345678');
    assertSame(['12345672345678'], $parsed['numbers'], 'nobody here knows where the split goes');
    assertSame('two numbers run together?', MemberNumbers::hint('12345672345678'));
    assertSame('too short — part of a longer number?', MemberNumbers::hint('123'));
    assertSame('not all digits', MemberNumbers::hint('12A4567'));
    assertSame('', MemberNumbers::hint('1234567'));
    // Every real member number is digits (docs/data-findings.md); a token
    // with letters in it that the roster does not hold is worth saying so.
    assertSame('not all digits', MemberNumbers::hint('LK000001'));
});

test('full-width digits and separators from a phone are read as their ASCII', function (): void {
    $parsed = MemberNumbers::parse('１２３４５６７，２３４５６７８；　３４５６７８９');
    assertSame(['1234567', '2345678', '3456789'], $parsed['numbers']);
    // An encoding, not a reshaping: the digits are the same digits, so
    // nothing is reported as read differently.
    assertSame([], $parsed['read_as']);

    $parsed = MemberNumbers::parse("\u{FEFF}1234567\u{00A0}2345678");
    assertSame(['1234567', '2345678'], $parsed['numbers'], 'a BOM and a non-breaking space');
});

test('the cap is 300, everything past it is listed rather than cut, and the text has a ceiling', function (): void {
    $list   = [];
    for ($i = 1; $i <= 301; $i++) {
        $list[] = sprintf('9%06d', $i);
    }
    $parsed = MemberNumbers::parse(implode(', ', $list));

    assertSame(300, MemberNumbers::MAX);
    assertSame(300, count($parsed['numbers']));
    assertSame(['9000301'], $parsed['over']);
    assertSame(301, $parsed['given']);
    assertSame(false, $parsed['truncated']);

    $parsed = MemberNumbers::parse(str_repeat('1234567, ', 20000));
    assertSame(true, $parsed['truncated'], 'a whole spreadsheet pasted by mistake is cut at the ceiling');
    assertSame(['1234567'], $parsed['numbers']);
});

// ---------------------------------------------------------------------------
// The database under test
// ---------------------------------------------------------------------------

function lk_pdo(): PDO
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
        $pdo = lk_app()->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    return $pdo;
}

/** The digits-only fixture number, with the leading zeros a screen might drop. */
const LK_ZERO_NUMBER = '00043101';

function lk_teardown(PDO $pdo): void
{
    $members = "SELECT id FROM member WHERE member_number LIKE 'LK%' OR member_number = '" . LK_ZERO_NUMBER . "'";
    $users   = "SELECT id FROM app_user WHERE member_id IN ({$members})";
    $forms   = "SELECT id FROM rcf WHERE generated_by IN ({$users})";

    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
    $pdo->exec("DELETE FROM rcf_row WHERE rcf_id IN ({$forms})");
    $pdo->exec("DELETE FROM rcf WHERE generated_by IN ({$users})");
    $pdo->exec("DELETE FROM import_change WHERE member_number LIKE 'LK%' OR member_number = '" . LK_ZERO_NUMBER . "'");
    $pdo->exec("UPDATE member SET dropped_since_import_id = NULL, last_seen_import_id = NULL"
        . " WHERE member_number LIKE 'LK%' OR member_number = '" . LK_ZERO_NUMBER . "'");
    $pdo->exec("DELETE FROM import_batch WHERE filename LIKE 'LK-%'");
    $pdo->exec("DELETE FROM contact_log WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'LK%' OR member_number = '" . LK_ZERO_NUMBER . "'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'LK %'");
    $pdo->exec("DELETE FROM division WHERE name LIKE 'LK %'");
}

/**
 * One division, one team, an Admin with an account, and five members whose
 * records differ in exactly the ways the screen has to tell apart:
 *
 *   m1  LK000001  on the roster; created by import 1, then import 2 changed
 *                 their title and team; one RCF line names them
 *   m2  LK000002  DROPPED by import 2, which recorded it
 *   m3  LK000003  on the roster, predates the record: no import_change rows
 *   m4  LK000004  PURGED
 *   m5  00043101  on the roster, created by import 1 and untouched since —
 *                 the number a screen shows as 43101
 *
 * @return array<string, mixed>
 */
function lk_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = lk_pdo();
    lk_teardown($pdo);

    $pdo->prepare('INSERT INTO division (name, is_placeholder) VALUES (:name, 0)')->execute([':name' => 'LK Logistics Division']);
    $division = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)')
        ->execute([':name' => 'LK Bus Ops Team A', ':division' => $division]);
    $team = (int) $pdo->lastInsertId();

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();

    $insertBatch = $pdo->prepare(
        'INSERT INTO import_batch (show_year_id, mode, filename, sha256, rows_read, applied_at, dry_run, started_at)'
        . " VALUES (:year, 'complete', :file, :sha, 5, :applied, 0, :applied2)"
    );
    $batches = [];
    foreach (['1' => '2026-03-01 15:00:00', '2' => '2026-06-15 15:00:00'] as $n => $at) {
        $insertBatch->execute([
            ':year' => $year, ':file' => "LK-roster-{$n}.xls", ':sha' => hash('sha256', "LK-{$n}"),
            ':applied' => $at, ':applied2' => $at,
        ]);
        $batches[$n] = (int) $pdo->lastInsertId();
    }

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, full_name,'
        . ' division_id, team_id, phone, phone_e164, phone_type, email, title, title_level,'
        . ' first_imported_at, dropped_since_import_id, purged_at)'
        . " VALUES (:number, :first, :last, '', :full, :division, :team,"
        . " '(555) 555-0131', '+15555550131', 'CELL PHONE', :email, :title, :level,"
        . ' :first_seen, :dropped, :purged)'
    );
    $specs = [
        'admin' => ['LK000009', 'Ada',    'Admin',   'Committee Member', 'member', '2026-03-01 15:00:00', null, null],
        'm1'    => ['LK000001', 'Robert', 'Alpha',   'Captain',          'officer', '2026-03-01 15:00:00', null, null],
        'm2'    => ['LK000002', 'Carol',  'Bravo',   'Committee Member', 'member', '2026-03-01 15:00:00', $batches['2'], null],
        'm3'    => ['LK000003', 'Frank',  'Echo',    'Committee Member', 'member', '2025-09-01 15:00:00', null, null],
        'm4'    => ['LK000004', 'Grace',  'Foxtrot', 'Committee Member', 'member', '2026-03-01 15:00:00', null, '2026-07-01 15:00:00'],
        'm5'    => [LK_ZERO_NUMBER, 'Hal', 'Golf',   'Committee Member', 'member', '2026-03-01 15:00:00', null, null],
    ];
    $members = [];
    foreach ($specs as $key => [$number, $first, $last, $title, $level, $firstSeen, $dropped, $purged]) {
        $insertMember->execute([
            ':number' => $number, ':first' => $first, ':last' => $last, ':full' => $first . ' ' . $last,
            ':division' => $division, ':team' => $team,
            ':email' => strtolower($first) . '@example.com', ':title' => $title, ':level' => $level,
            ':first_seen' => $firstSeen, ':dropped' => $dropped, ':purged' => $purged,
        ]);
        $members[$key] = ['id' => (int) $pdo->lastInsertId(), 'number' => $number];
    }

    $pdo->prepare(
        "INSERT INTO app_user (member_id, level, granted_level, password_hash, must_change_password, is_active)"
        . " VALUES (:m, 'member', 'admin', '*', 0, 1)"
    )->execute([':m' => $members['admin']['id']]);
    $adminUser = (int) $pdo->lastInsertId();

    // What the imports recorded (010): created, updated, dropped.
    $change = $pdo->prepare(
        'INSERT INTO import_change (import_batch_id, member_id, member_number, kind, field, before_value, after_value, occurred_at)'
        . ' VALUES (:batch, :member, :number, :kind, :field, :before, :after, :at)'
    );
    $rows = [
        [$batches['1'], 'm1', 'created', '', null, null, '2026-03-01 15:00:00'],
        [$batches['1'], 'm2', 'created', '', null, null, '2026-03-01 15:00:00'],
        [$batches['1'], 'm5', 'created', '', null, null, '2026-03-01 15:00:00'],
        [$batches['2'], 'm1', 'updated', 'title', 'Committee Member', 'Captain', '2026-06-15 15:00:00'],
        [$batches['2'], 'm1', 'updated', 'team', 'LK Bus Ops Team B', 'LK Bus Ops Team A', '2026-06-15 15:00:00'],
        [$batches['2'], 'm2', 'dropped', '', null, null, '2026-06-15 15:00:00'],
    ];
    foreach ($rows as [$batch, $key, $kind, $field, $before, $after, $at]) {
        $change->execute([
            ':batch' => $batch, ':member' => $members[$key]['id'], ':number' => $members[$key]['number'],
            ':kind' => $kind, ':field' => $field, ':before' => $before, ':after' => $after, ':at' => $at,
        ]);
    }

    // One kept form (011) with one line naming m1, made by the Admin, and
    // the same form's second line naming a newcomer by name only.
    $pdo->prepare(
        'INSERT INTO rcf (show_year_id, generated_by, generated_at, year_label, submitter, submitter_number,'
        . ' form_date, subcommittee, team_id, row_count)'
        . " VALUES (:year, :by, '2026-05-01 15:00:00', '2027', 'Ada Admin, Committee Member', 'LK000009',"
        . " '2026-05-01', 'LK Logistics Division - LK Bus Ops Team A', :team, 2)"
    )->execute([':year' => $year, ':by' => $adminUser, ':team' => $team]);
    $rcf = (int) $pdo->lastInsertId();

    $line = $pdo->prepare(
        'INSERT INTO rcf_row (rcf_id, position, member_id, member_number, member_name, type, previous_title, new_title, serial, sent_to_dc_on)'
        . ' VALUES (:rcf, :position, :member, :number, :name, :type, :previous, :new, :serial, :dc)'
    );
    $line->execute([
        ':rcf' => $rcf, ':position' => 1, ':member' => $members['m1']['id'], ':number' => 'LK000001',
        ':name' => 'Robert Alpha', ':type' => 'T', ':previous' => 'Committee Member', ':new' => 'Captain',
        ':serial' => 'DC-17', ':dc' => '2026-05-02',
    ]);
    $line->execute([
        ':rcf' => $rcf, ':position' => 2, ':member' => null, ':number' => '',
        ':name' => 'Newcomer Sample', ':type' => 'A', ':previous' => '', ':new' => 'Committee Member',
        ':serial' => '', ':dc' => null,
    ]);

    return $fixture = [
        'division' => $division, 'team' => $team, 'year' => $year, 'batches' => $batches,
        'members' => $members, 'admin_user' => $adminUser, 'rcf' => $rcf,
    ];
}

function lk_admin(): User
{
    $f = lk_fixture();

    return new User(
        id: $f['admin_user'],
        memberId: $f['members']['admin']['id'],
        memberNumber: $f['members']['admin']['number'],
        level: Level::Admin,
        scopeDivisionId: null,
        scopeTeamId: null,
        mustChangePassword: false,
        displayName: 'Ada Admin',
    );
}

function lk_page(): LookupPage
{
    return new LookupPage(lk_pdo(), new RcfTracking(lk_pdo(), lk_app()->displayTimezone()));
}

function lk_render(string $view, string $title, array $data): string
{
    $app = lk_app();
    $_SESSION ??= [];
    $wide = false;
    extract($data, EXTR_SKIP);

    ob_start();
    require $app->path('app/views/' . $view . '.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------
// The answer (spec-v2 §13.4)
// ---------------------------------------------------------------------------

test('the rows come back in the order given, one per member the roster holds', function (): void {
    $f      = lk_fixture();
    $answer = lk_page()->lookUp(lk_admin(), "LK000003, LK000001\nLK000002; LK000004 43101 LK000001 Customer LK999999");

    assertSame(6, $answer['given'], 'six distinct numbers were read');
    assertSame(5, $answer['found']);
    assertSame(
        ['LK000003', 'LK000001', 'LK000002', 'LK000004', LK_ZERO_NUMBER],
        array_map(static fn (array $r): string => $r['member_number'], $answer['rows']),
        'the order typed, with the zero-stripped number resolved in its place'
    );
    assertSame(['LK000001' => 1], $answer['duplicates']);
    assertSame(['Customer'], $answer['ignored']);
    assertSame([['number' => 'LK999999', 'hint' => 'not all digits']], $answer['unknown']);

    // Placement is the member row's, as imported.
    $m1 = $answer['rows'][1];
    assertSame($f['members']['m1']['id'], $m1['id']);
    assertSame('Robert Alpha', $m1['name']);
    assertSame('Captain', $m1['title']);
    assertSame('LK Bus Ops Team A', $m1['team_name']);
    assertSame('LK Logistics Division', $m1['division_name']);
    assertSame(false, $m1['placeholder']);
});

test('on the roster, dropped and purged are three different words, and purged wins', function (): void {
    $f      = lk_fixture();
    $answer = lk_page()->lookUp(lk_admin(), 'LK000001 LK000002 LK000004');
    [$m1, $m2, $m4] = $answer['rows'];

    assertSame('present', $m1['state']);
    assertSame(null, $m1['dropped_batch']);

    assertSame('dropped', $m2['state']);
    assertSame($f['batches']['2'], $m2['dropped_batch'], 'the import that did not list them');
    assertSame('2026-06-15 15:00:00', $m2['dropped_at']);

    assertSame('purged', $m4['state']);
    assertSame('2026-07-01 15:00:00', $m4['purged_at']);
});

test('first seen is the member row\'s date, with the import that recorded it when the record reaches back', function (): void {
    $f      = lk_fixture();
    $answer = lk_page()->lookUp(lk_admin(), 'LK000001 LK000003');
    [$m1, $m3] = $answer['rows'];

    assertSame('2026-03-01 15:00:00', $m1['first_seen']);
    assertSame($f['batches']['1'], $m1['first_batch'], 'the created row names the import');

    assertSame('2025-09-01 15:00:00', $m3['first_seen'], 'the date is on the member row whatever the record holds');
    assertSame(null, $m3['first_batch'], 'no created row: they predate the record');
});

test('the last change is everything the last import that touched them changed — or the fact that none has', function (): void {
    $f      = lk_fixture();
    $answer = lk_page()->lookUp(lk_admin(), 'LK000001 LK000002 LK000003 43101');
    [$m1, $m2, $m3, $m5] = $answer['rows'];

    // m1: import 2 changed two fields at once; both are the last change.
    assertSame(3, $m1['changes_total']);
    assertSame($f['batches']['2'], $m1['last_change']['batch']);
    assertSame('2026-06-15 15:00:00', $m1['last_change']['at']);
    assertSame('LK-roster-2.xls', $m1['last_change']['filename']);
    assertSame(false, $m1['last_change']['appeared']);
    assertSame(
        [['Title', 'Committee Member', 'Captain'], ['Team', 'LK Bus Ops Team B', 'LK Bus Ops Team A']],
        array_map(static fn (array $i): array => [$i['field_label'], $i['before'], $i['after']], $m1['last_change']['items']),
        'labelled the way Import History labels them, in the order recorded'
    );

    // m2: the last thing an import did was drop them.
    assertSame('dropped', $m2['last_change']['items'][0]['kind']);
    assertSame('Dropped from the roster', $m2['last_change']['items'][0]['kind_label']);

    // m3: nothing recorded at all — not the same as nothing changed.
    assertSame(0, $m3['changes_total']);
    assertSame(null, $m3['last_change']);

    // m5: created and never touched since — they just appeared.
    assertSame(1, $m5['changes_total']);
    assertSame(true, $m5['last_change']['appeared']);
    assertSame([], $m5['last_change']['items']);
});

test('a number typed without its leading zeros finds the member who has them, and says so', function (): void {
    $answer = lk_page()->lookUp(lk_admin(), '43101, 0043101, 00043101');

    // Three spellings of one member: the exact one is found outright, the
    // stripped one is matched and marked, and a partial strip is neither.
    $numbers = array_map(static fn (array $r): array => [$r['member_number'], $r['typed']], $answer['rows']);
    assertSame([[LK_ZERO_NUMBER, '43101'], [LK_ZERO_NUMBER, LK_ZERO_NUMBER]], $numbers);
    assertSame([['number' => '0043101', 'hint' => '']], $answer['unknown'], 'not stripped to the bone is not matched');
});

test('every Roster Change Form line naming a member rides on their row, with who made it', function (): void {
    $f      = lk_fixture();
    $answer = lk_page()->lookUp(lk_admin(), 'LK000001 LK000002');
    [$m1, $m2] = $answer['rows'];

    assertSame(1, count($m1['rcfs']));
    $line = $m1['rcfs'][0];
    assertSame($f['rcf'], $line['rcf_id']);
    assertSame('2026-05-01 15:00:00', $line['generated_at']);
    assertSame('Ada Admin', $line['generator_name']);
    assertSame('Title Change — Committee Member → Captain', $line['change']);
    assertSame('DC-17', $line['serial']);
    assertSame('2026-05-02', $line['sent_to_dc_on']);
    assertSame(null, $line['sent_to_rosters_on']);
    assertSame(true, $line['viewable'], 'an Admin holds view_all_forms');
    // Import 2 changed the title AFTER the form: the line landed.
    assertSame($f['batches']['2'], $line['landed']['batch']);

    assertSame([], $m2['rcfs'], 'no form ever named them');
});

test('an empty or wordless list is answered, not errored', function (): void {
    $answer = lk_page()->lookUp(lk_admin(), '');
    assertSame(0, $answer['given']);
    assertSame([], $answer['rows']);
    assertSame([], $answer['unknown']);

    $answer = lk_page()->lookUp(lk_admin(), 'Customer Number');
    assertSame(0, $answer['given']);
    assertSame(['Customer', 'Number'], $answer['ignored']);
});

// ---------------------------------------------------------------------------
// The screen
// ---------------------------------------------------------------------------

test('the screen keeps what was typed, reports how it was read, and draws the table in that order', function (): void {
    $f     = lk_fixture();
    $typed = "LK000001, LK000002\nLK000003 LK000004 43101 LK000001 Customer LK999999 1234567.0";
    $html  = lk_render('lookup', 'Look Up Members', [
        'user' => lk_admin(), 'notices' => [], 'typed' => $typed,
        'result' => lk_page()->lookUp(lk_admin(), $typed),
    ]);

    assertTrue(str_contains($html, '<textarea id="numbers" name="numbers"'), 'the box');
    assertTrue(str_contains($html, e($typed) . '</textarea>'), 'with the list still in it, escaped');
    assertTrue(str_contains($html, 'name="' . Rerm\Csrf::FIELD . '"'), 'and the token');
    assertSame(0, substr_count($html, ' autofocus'), 'the box does not steal focus from an answer');

    // The report, above the table.
    assertTrue(str_contains($html, '<strong>7 numbers</strong> read'), 'seven distinct: five found, one unknown, one Excel float');
    assertTrue(str_contains($html, '5 members found'));
    assertTrue(str_contains($html, '2 not on the roster'));
    assertTrue(str_contains($html, '1 repeat</strong>') || str_contains($html, '1 repeat set aside'), 'the repeat is counted');
    assertTrue(str_contains($html, '1 word ignored'));
    assertTrue(str_contains($html, '>Not on the roster</span>'));
    assertTrue(str_contains($html, '<span class="mono">LK999999</span>'));
    assertTrue(str_contains($html, '>Read as</span>'));
    assertTrue(str_contains($html, '<span class="mono">1234567.0</span> &rarr; <span class="mono">1234567</span>'));
    assertTrue(str_contains($html, '<span class="mono">Customer</span>'), 'the ignored word is named');

    // The table, in the order given.
    $rows = [];
    preg_match_all('/<td data-label="Number" class="mono"><div class="in">\s*([^<\s]+)/', $html, $rows);
    assertSame(['LK000001', 'LK000002', 'LK000003', 'LK000004', LK_ZERO_NUMBER], $rows[1]);
    assertTrue(str_contains($html, '<span class="why">typed 43101</span>'), 'the zero-stripped match says what was typed');

    // The three roster words, each with its word and never hue alone.
    assertTrue(str_contains($html, '>On the roster</span>'));
    assertTrue(str_contains($html, '>Dropped</span>'));
    assertTrue(str_contains($html, 'import-history?batch=' . $f['batches']['2']), 'the dropping import is a link');
    assertTrue(str_contains($html, '>Purged</span>'));

    // First seen and last change.
    assertTrue(str_contains($html, 'import-history?batch=' . $f['batches']['1']), 'the creating import is a link');
    assertTrue(str_contains($html, '>None recorded</span>'), 'm3 predates the record');
    assertTrue(str_contains($html, '>None since they appeared</span>'), 'm5 just appeared');
    assertTrue(str_contains($html, 'Title:'), 'm1\'s last change names the field');
    assertTrue(str_contains($html, '<span class="mono">Committee Member</span> &rarr; <span class="mono">Captain</span>'));
    assertTrue(str_contains($html, 'import-history?member=LK000001'), 'the whole history is one link away');
    assertTrue(str_contains($html, '>Dropped from the roster</span>'), 'm2\'s last change is the drop');

    // The forms, folded.
    assertTrue(str_contains($html, '<summary>1 form</summary>'));
    assertTrue(str_contains($html, 'by Ada Admin'));
    assertTrue(str_contains($html, 'Title Change — Committee Member → Captain'));
    assertTrue(str_contains($html, 'RCF # DC-17'));
    assertTrue(str_contains($html, 'to the DC 2 May 2026'));
    assertTrue(str_contains($html, 'to Rosters not yet'));
    assertTrue(str_contains($html, 'rcf?id=' . $f['rcf']), 'and the form is a link');
    assertSame(4, substr_count($html, '>None</span>'), 'the other four have none');

    // Every name links to the card, with the way back carrying the list.
    assertTrue(str_contains($html, 'member?id=' . $f['members']['m1']['id'] . '&amp;from=lookup&amp;back=numbers%3D'));

    // Spec 10: under 100KB on first paint, with the fixture's five rows.
    assertTrue(strlen($html) < 100 * 1024, 'the page is ' . number_format(strlen($html)) . ' bytes');
});

test('before a lookup the screen is the box alone, focused, with no report and no table', function (): void {
    $html = lk_render('lookup', 'Look Up Members', [
        'user' => lk_admin(), 'notices' => [], 'typed' => '', 'result' => null,
    ]);

    assertTrue(str_contains($html, ' autofocus>'), 'the box opens focused');
    assertSame(0, substr_count($html, '<table'), 'no table');
    assertSame(0, substr_count($html, 'numbers</strong> read'), 'no report');
    assertTrue(str_contains($html, 'Up to 300 at a time'), 'the cap is said');
});

test('a list nobody is on says so in words', function (): void {
    $html = lk_render('lookup', 'Look Up Members', [
        'user' => lk_admin(), 'notices' => [], 'typed' => 'Customer Number',
        'result' => lk_page()->lookUp(lk_admin(), 'Customer Number'),
    ]);

    assertTrue(str_contains($html, 'No member numbers were read.'));
    assertTrue(str_contains($html, 'Everything typed was words'));
    assertSame(0, substr_count($html, '<table'));
});

test('teardown', function (): void {
    lk_teardown(lk_pdo());
});
