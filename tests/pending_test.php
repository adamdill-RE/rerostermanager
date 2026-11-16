<?php

declare(strict_types=1);

/**
 * The people a team is waiting for (Phase 14.8, spec-v2 §22.2): everyone
 * asked for on a Roster Change Form as an addition to the team on the
 * screen, whom the roster does not list yet, drawn as ghost rows under
 * View My Roster and My Roster Status.
 *
 * What is held here:
 *
 *   1. A pending addition on a kept form's line AND one in a request still
 *      in the chain both appear for the team they name; a removal, a title
 *      change, a voided form, a returned request and a generated request
 *      (which is a line already) do not.
 *   2. The roster's answer ends it: an import_change that created the
 *      number — RcfTracking's own rule — takes the ghost away, and so does
 *      the number already being on that team.
 *   3. Scope is the roster's: a team outside the caller's scope yields
 *      nothing, and no team in view yields nothing.
 *   4. Both screens draw the one partial, the ghost carries its word, and
 *      the ghosts are counted nowhere — not on the cards, not in the
 *      totals.
 *   5. Nothing here writes.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Roster\PendingAdditions;
use Rerm\Roster\RosterPage;
use Rerm\Roster\ScopedQuery;
use Rerm\Roster\StatusPage;

function pg_app(): App
{
    static $app = null;

    return $app ??= App::boot(dirname(__DIR__));
}

function pg_source(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/' . $relative);
}

// ---------------------------------------------------------------------------
// The pure parts
// ---------------------------------------------------------------------------

test('a form\'s Last, First reads as the roster prints a name; anything else is kept as typed', function (): void {
    assertSame('John Smith', PendingAdditions::rosterName('Smith, John'));
    assertSame('John Smith', PendingAdditions::rosterName('  Smith ,  John '));
    assertSame('John Smith', PendingAdditions::rosterName('John Smith'));
    assertSame('Smith, John, Jr', PendingAdditions::rosterName('Smith, John, Jr'));
    assertSame('', PendingAdditions::rosterName(''));
});

test('one ghost per person: a number asked for twice is one person on the way, and the first seen wins', function (): void {
    $line  = ['key' => 'line:1', 'member_number' => '1234567', 'name' => 'A', 'team_id' => 1];
    $again = ['key' => 'entry:9', 'member_number' => '1234567', 'name' => 'A', 'team_id' => 1];
    $named = ['key' => 'line:2', 'member_number' => '', 'name' => 'New Comer', 'team_id' => 1];
    $same  = ['key' => 'entry:3', 'member_number' => '', 'name' => 'new comer', 'team_id' => 1];
    $other = ['key' => 'entry:4', 'member_number' => '', 'name' => 'New Comer', 'team_id' => 2];

    $out = PendingAdditions::distinct([$line, $again, $named, $same, $other]);
    assertSame(['line:1', 'line:2', 'entry:4'], array_column($out, 'key'));
});

test('a search keeps the ghosts every word of it lands on, by number or by name', function (): void {
    $ghosts = [
        ['key' => 'a', 'member_number' => '1234567', 'name' => 'John Smith'],
        ['key' => 'b', 'member_number' => '7654321', 'name' => 'Jane Doe'],
        ['key' => 'c', 'member_number' => '', 'name' => 'Zeb Fin'],
    ];
    assertSame(['a'], array_column(PendingAdditions::searched($ghosts, 'smith john'), 'key'));
    assertSame(['a'], array_column(PendingAdditions::searched($ghosts, '12345'), 'key'));
    assertSame(['c'], array_column(PendingAdditions::searched($ghosts, 'Fin'), 'key'));
    assertSame([], array_column(PendingAdditions::searched($ghosts, 'John Doe'), 'key'), 'every word has to land');
    assertSame(['a', 'b', 'c'], array_column(PendingAdditions::searched($ghosts, ''), 'key'));
});

test('where a line has got to is one sentence that ends with the roster not yet showing it', function (): void {
    assertSame(
        'On an RCF by Alexis Captain, Captain — not yet in the roster',
        PendingAdditions::lineWhereabouts(['submitter' => 'Alexis Captain, Captain', 'source' => 'generated'])
    );
    assertSame(
        'On consolidated RCF #44 by Carl Chair, sent to Rosters 3 Oct 2026 — not yet in the roster',
        PendingAdditions::lineWhereabouts(['submitter' => 'Carl Chair', 'source' => 'consolidated', 'serial' => '44',
            'sent_to_dc_on' => '2026-10-01', 'sent_to_rosters_on' => '2026-10-03'])
    );
    assertSame(
        'On an uploaded RCF #12 dated 1 Feb 2026, sent to the Division Chairman 2 Feb 2026 — not yet in the roster',
        PendingAdditions::lineWhereabouts(['source' => 'uploaded', 'serial' => '12', 'form_date' => '2026-02-01',
            'sent_to_dc_on' => '2026-02-02'])
    );
});

test('the teams a scope is made of: an Officer\'s team or a team set, and nothing for a division or the committee', function (): void {
    $mk = static fn (Level $level, ?int $division, ?int $team, array $set = []): User => new User(
        1, 2, 'PG000001', $level, $division, $team, false, 'Someone', $set
    );

    assertSame([7], ScopedQuery::teamIdsOf($mk(Level::Officer, 3, 7)));
    assertSame([7, 8], ScopedQuery::teamIdsOf($mk(Level::Officer, 3, 7, [7, 8])), 'a team set widens an Officer');
    assertSame([9], ScopedQuery::teamIdsOf($mk(Level::SeniorOfficer, 3, 7, [9])), 'a Vice Chairman\'s set');
    assertSame([], ScopedQuery::teamIdsOf($mk(Level::SeniorOfficer, 3, 7)), 'a division is not a team view');
    assertSame([], ScopedQuery::teamIdsOf($mk(Level::ExecutiveOfficer, 3, 7, [7])), 'the whole committee is not one either');
    assertSame([], ScopedQuery::teamIdsOf($mk(Level::Admin, null, null)));
    assertSame([], ScopedQuery::teamIdsOf($mk(Level::Officer, 3, null)), 'an Officer with no team has no team view');
});

test('both roster screens draw the one partial after their lists, and the partial never writes', function (): void {
    foreach (['app/views/roster.php', 'app/views/dashboard.php'] as $view) {
        $source = pg_source($view);
        assertTrue(str_contains($source, "require \$app->path('app/views/pending-rcf.php');"), $view . ' draws the partial');
        assertTrue(substr_count($source, 'pending-rcf.php') === 1, $view . ' draws it once');
    }

    $partial = pg_source('app/views/pending-rcf.php');
    assertTrue(str_contains($partial, 'Pending RCF'), 'the chip carries the word');
    assertTrue(str_contains($partial, 'class="member ghost"'), 'a ghost row is marked as one');
    assertTrue(!str_contains($partial, '<form'), 'the partial has no form: nothing to log against a ghost');

    foreach (['app/src/Roster/PendingAdditions.php', 'app/views/pending-rcf.php'] as $file) {
        $source = pg_source($file);
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $verb) {
            assertTrue(preg_match('/\b' . $verb . '\s+(INTO|FROM|[a-z_]+\s+SET)\b/i', $source) !== 1, $file . ' never writes');
        }
    }

    // The layout styles the ghost in a word's company, and never in orange text.
    $layout = pg_source('app/views/layout.php');
    assertTrue(str_contains($layout, 'tbody.ghost'), 'the layout styles a ghost row');
    assertTrue(preg_match('/tbody\.ghost[^{]*\{[^}]*color:\s*var\(--rodeo-orange\)/', $layout) !== 1,
        'Rodeo Orange is a border on a ghost, never its text');
});

// ---------------------------------------------------------------------------
// The database
// ---------------------------------------------------------------------------

function pg_pdo(): PDO
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
        $pdo = pg_app()->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    $ready = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rcf_entry'"
    )->fetchColumn();
    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

function pg_teardown(PDO $pdo): void
{
    $members = "SELECT id FROM member WHERE member_number LIKE 'PG%'";
    $users   = "SELECT id FROM app_user WHERE member_id IN ({$members})";
    $forms   = "SELECT id FROM rcf WHERE generated_by IN ({$users})";
    $entries = "SELECT id FROM rcf_entry WHERE requested_by IN ({$users})";

    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
    $pdo->exec("DELETE FROM import_change WHERE import_batch_id IN (SELECT id FROM import_batch WHERE filename LIKE 'PG-%')");
    $pdo->exec("DELETE FROM import_batch WHERE filename LIKE 'PG-%'");
    $pdo->exec("DELETE FROM rcf_entry_step WHERE entry_id IN ({$entries})");
    $pdo->exec("DELETE FROM rcf_entry WHERE requested_by IN ({$users})");
    $pdo->exec("DELETE FROM rcf_row WHERE rcf_id IN ({$forms})");
    $pdo->exec("DELETE FROM rcf WHERE generated_by IN ({$users})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'PG%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'PG %'");
    $pdo->exec("DELETE FROM division WHERE name LIKE 'PG %'");
}

/**
 * One division, two teams, a Captain on each, a member on Team A, and a
 * Vice Chairman on Team A who makes forms and requests.
 *
 * @return array<string, mixed>
 */
function pg_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = pg_pdo();
    pg_teardown($pdo);

    $pdo->prepare('INSERT INTO division (name, is_placeholder) VALUES (:name, 0)')->execute([':name' => 'PG Logistics Division']);
    $division = (int) $pdo->lastInsertId();

    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)');
    $teams      = [];
    foreach (['a' => 'PG Bus Ops Team A', 'b' => 'PG Bus Ops Team B'] as $key => $name) {
        $insertTeam->execute([':name' => $name, ':division' => $division]);
        $teams[$key] = (int) $pdo->lastInsertId();
    }

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, full_name,'
        . ' division_id, team_id, phone, phone_e164, phone_type, email, title, title_level)'
        . " VALUES (:number, :first, :last, '', :full, :division, :team,"
        . " '(555) 555-0141', '+15555550141', 'CELL PHONE', :email, :title, :level)"
    );
    $specs = [
        'capa' => ['a', 'Alexis', 'Captain', 'Captain',       'officer'],
        'capb' => ['b', 'Blake',  'Captain', 'Captain',       'officer'],
        'vc'   => ['a', 'Erin',   'Delta',   'Vice Chairman', 'senior_officer'],
        'm1'   => ['a', 'Robert', 'Alpha',   'Committee Member', 'member'],
    ];
    $members = [];
    $n       = 0;
    foreach ($specs as $key => [$team, $first, $last, $title, $level]) {
        $n++;
        $number = sprintf('PG%06d', $n);
        $insertMember->execute([
            ':number' => $number, ':first' => $first, ':last' => $last, ':full' => $first . ' ' . $last,
            ':division' => $division, ':team' => $teams[$team],
            ':email' => strtolower($first) . '@example.com', ':title' => $title, ':level' => $level,
        ]);
        $members[$key] = ['id' => (int) $pdo->lastInsertId(), 'number' => $number];
    }

    $insertUser = $pdo->prepare(
        "INSERT INTO app_user (member_id, level, granted_level, password_hash, must_change_password, is_active)"
        . " VALUES (:m, :l, NULL, '*', 0, 1)"
    );
    $users = [];
    foreach (['capa' => 'officer', 'capb' => 'officer', 'vc' => 'senior_officer'] as $key => $level) {
        $insertUser->execute([':m' => $members[$key]['id'], ':l' => $level]);
        $users[$key] = (int) $pdo->lastInsertId();
    }

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();

    return $fixture = ['division' => $division, 'teams' => $teams, 'members' => $members, 'users' => $users, 'year' => $year];
}

function pg_user(string $key): User
{
    $f = pg_fixture();
    $m = $f['members'][$key];

    return match ($key) {
        'capa' => new User($f['users'][$key], $m['id'], $m['number'], Level::Officer, $f['division'], $f['teams']['a'], false, 'Alexis Captain'),
        'capb' => new User($f['users'][$key], $m['id'], $m['number'], Level::Officer, $f['division'], $f['teams']['b'], false, 'Blake Captain'),
        'vc'   => new User($f['users'][$key], $m['id'], $m['number'], Level::SeniorOfficer, $f['division'], $f['teams']['a'], false, 'Erin Delta', [$f['teams']['a']]),
        default => throw new RuntimeException($key),
    };
}

/** A kept form with one line, straight into the record, as RcfStore would keep it. */
function pg_form(string $type, string $number, string $name, string $destination, ?int $teamId, array $extra = []): array
{
    $f   = pg_fixture();
    $pdo = pg_pdo();

    $pdo->prepare(
        'INSERT INTO rcf (show_year_id, generated_by, generated_at, source, year_label, submitter, submitter_number,'
        . ' form_date, subcommittee, division_id, team_id, row_count, voided_at)'
        . " VALUES (:year, :by, :at, :source, '2026', 'Erin Delta, Vice Chairman', :sn, :date, :sub, :division, :team, 1, :voided)"
    )->execute([
        ':year' => $f['year'], ':by' => $f['users']['vc'], ':at' => $extra['generated_at'] ?? '2026-09-01 12:00:00',
        ':source' => $extra['source'] ?? 'generated', ':sn' => $f['members']['vc']['number'],
        ':date' => $extra['form_date'] ?? null,
        ':sub' => $teamId === null ? 'PG Logistics Division' : 'PG Logistics Division - ' . ($teamId === $f['teams']['a'] ? 'PG Bus Ops Team A' : 'PG Bus Ops Team B'),
        ':division' => $teamId === null ? $f['division'] : null, ':team' => $teamId,
        ':voided' => $extra['voided_at'] ?? null,
    ]);
    $rcf = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO rcf_row (rcf_id, position, member_id, member_number, member_name, type, rookie, new_title,'
        . ' previous_title, wait_list, remove_reason, new_subcommittee, sponsor, serial, sent_to_dc_on)'
        . " VALUES (:rcf, 1, NULL, :number, :name, :type, 1, :title, '', 0, '', :dest, '', :serial, :dc)"
    )->execute([':rcf' => $rcf, ':number' => $number, ':name' => $name, ':type' => $type,
        ':title' => $extra['title'] ?? 'Committee Member', ':dest' => $destination,
        ':serial' => $extra['serial'] ?? '', ':dc' => $extra['sent_to_dc_on'] ?? null]);

    return ['rcf' => $rcf, 'row' => (int) $pdo->lastInsertId()];
}

/** A request in the chain, straight into the record. */
function pg_entry(string $type, string $status, string $number, string $name, string $destination, ?int $teamId): int
{
    $f   = pg_fixture();
    $pdo = pg_pdo();

    $pdo->prepare(
        'INSERT INTO rcf_entry (show_year_id, requested_by, requested_at, division_id, team_id, subcommittee,'
        . ' member_id, member_number, member_name, type, rookie, new_title, new_subcommittee, status, current_approver_id)'
        . " VALUES (:year, :by, '2026-09-02 12:00:00', :division, :team, 'PG Logistics Division', NULL, :number, :name, :type, 0,"
        . " 'Committee Member', :dest, :status, :approver)"
    )->execute([':year' => $f['year'], ':by' => $f['users']['vc'], ':division' => $teamId === null ? $f['division'] : null,
        ':team' => $teamId, ':number' => $number, ':name' => $name, ':type' => $type, ':dest' => $destination,
        ':status' => $status, ':approver' => $status === 'pending' ? $f['users']['capa'] : null]);

    return (int) $pdo->lastInsertId();
}

function pg_import(string $number, string $kind, string $occurredAt): void
{
    $f   = pg_fixture();
    $pdo = pg_pdo();

    $pdo->prepare(
        'INSERT INTO import_batch (show_year_id, mode, filename, sha256, dry_run, applied_at)'
        . " VALUES (:year, 'update', :file, :sha, 0, :at)"
    )->execute([':year' => $f['year'], ':file' => 'PG-' . uniqid() . '.xls', ':sha' => str_repeat('0', 64), ':at' => $occurredAt]);
    $batch = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO import_change (import_batch_id, member_id, member_number, kind, field, before_value, after_value, occurred_at)'
        . " VALUES (:batch, NULL, :number, :kind, '', NULL, NULL, :at)"
    )->execute([':batch' => $batch, ':number' => $number, ':kind' => $kind, ':at' => $occurredAt]);
}

function pg_ghosts(string $who, array $teams, string $search = ''): array
{
    return (new PendingAdditions(pg_pdo()))->forTeams(pg_user($who), $teams, $search);
}

test('a pending addition on a form line and one in a request both appear for the team they name, and nothing else does', function (): void {
    $f = pg_fixture();

    // On a team-headed form, with the destination filled in (Phase 14.7).
    $line = pg_form('A', 'PG900001', 'Newcomer, Nancy', 'PG Bus Ops Team A', $f['teams']['a'], ['serial' => '7', 'sent_to_dc_on' => '2026-09-03']);
    // On a DIVISION-headed form, where only the destination says the team.
    pg_form('A', 'PG900002', 'Second, Sam', 'PG Bus Ops Team A', null);
    // A line kept before 14.7: no destination, so the heading's team.
    pg_form('A', 'PG900003', 'Third, Tom', '', $f['teams']['a']);
    // A request still in the chain, and one approved and not yet on a form.
    pg_entry('A', 'pending', 'PG900004', 'Fourth, Fay', 'PG Bus Ops Team A', $f['teams']['a']);
    pg_entry('A', 'approved', '', 'Fifth, Finn', 'PG Bus Ops Team A', null);

    // None of these is a ghost of Team A:
    pg_form('R', $f['members']['m1']['number'], 'Alpha, Robert', '', $f['teams']['a']);        // a removal
    pg_form('T', $f['members']['m1']['number'], 'Alpha, Robert', '', $f['teams']['a']);        // a title change
    pg_form('A', 'PG900006', 'Other, Olive', 'PG Bus Ops Team B', $f['teams']['a']);           // bound for Team B
    pg_form('A', 'PG900007', 'Voided, Vic', 'PG Bus Ops Team A', $f['teams']['a'], ['voided_at' => '2026-09-05 00:00:00']);
    pg_entry('A', 'returned', 'PG900008', 'Back, Bea', 'PG Bus Ops Team A', $f['teams']['a']);
    pg_entry('A', 'withdrawn', 'PG900009', 'Gone, Gus', 'PG Bus Ops Team A', $f['teams']['a']);
    pg_entry('A', 'generated', 'PG900010', 'Done, Dan', 'PG Bus Ops Team A', $f['teams']['a']);
    pg_entry('R', 'pending', $f['members']['m1']['number'], 'Alpha, Robert', '', $f['teams']['a']);
    pg_form('A', $f['members']['m1']['number'], 'Alpha, Robert', 'PG Bus Ops Team A', $f['teams']['a']); // already on the team

    $ghosts = pg_ghosts('capa', [$f['teams']['a']]);
    $byNumber = [];
    foreach ($ghosts as $g) {
        $byNumber[$g['member_number'] !== '' ? $g['member_number'] : $g['name']] = $g;
    }
    $keys = array_keys($byNumber);
    sort($keys);
    assertSame(['Finn Fifth', 'PG900001', 'PG900002', 'PG900003', 'PG900004'], $keys, 'exactly the five');
    assertSame(['form', 'form', 'form', 'request', 'request'], array_column($ghosts, 'source'), 'lines first, then requests');

    $first = $byNumber['PG900001'];
    assertSame('form', $first['source']);
    assertSame('Nancy Newcomer', $first['name'], 'Last, First read the roster\'s way');
    assertSame('Committee Member', $first['title']);
    assertSame(true, $first['rookie']);
    assertSame('PG Bus Ops Team A', $first['team_name']);
    assertSame('On an RCF #7 by Erin Delta, Vice Chairman, sent to the Division Chairman 3 Sep 2026 — not yet in the roster', $first['where']);
    assertSame($line['rcf'], $first['rcf_id']);
    assertSame(null, $first['link'], 'a Captain who did not make the form may not open it');

    $request = $byNumber['PG900004'];
    assertSame('request', $request['source']);
    assertSame('RCF request by Erin Delta, waiting on Alexis Captain', $request['where']);
    assertSame('rcf-approvals', $request['link'], 'the Captain it waits on is sent to Waiting for you');

    $approved = $byNumber['Finn Fifth'];
    assertSame('RCF request by Erin Delta, approved and waiting to go on a consolidated form', $approved['where']);
    assertSame(null, $approved['link']);

    // The Vice Chairman made every form and request: the links are theirs.
    $own = [];
    foreach (pg_ghosts('vc', [$f['teams']['a']]) as $g) {
        $own[$g['key']] = $g['link'];
    }
    assertSame('rcf', $own['line:' . $line['row']]);
    assertTrue(in_array('rcf-entries', $own, true), 'the requester is sent to their own requests');

    // Team B's Captain sees Team B's one, and never Team A's five.
    $b = pg_ghosts('capb', [$f['teams']['b']]);
    assertSame(['PG900006'], array_column($b, 'member_number'));
});

test('the roster\'s answer ends it: an import that created the number takes the ghost away, by RcfTracking\'s rule', function (): void {
    $f = pg_fixture();

    assertTrue(in_array('PG900001', array_column(pg_ghosts('capa', [$f['teams']['a']]), 'member_number'), true));

    // An import BEFORE the form says nothing about it.
    pg_import('PG900001', 'created', '2026-08-01 00:00:00');
    assertTrue(in_array('PG900001', array_column(pg_ghosts('capa', [$f['teams']['a']]), 'member_number'), true), 'an earlier import is not the answer');

    // One after it is.
    pg_import('PG900001', 'created', '2026-09-10 00:00:00');
    assertTrue(!in_array('PG900001', array_column(pg_ghosts('capa', [$f['teams']['a']]), 'member_number'), true), 'the roster has shown them');

    // A returned member is the same answer for an addition.
    pg_import('PG900002', 'returned', '2026-09-10 00:00:00');
    assertTrue(!in_array('PG900002', array_column(pg_ghosts('capa', [$f['teams']['a']]), 'member_number'), true));
});

test('scope is the roster\'s: a team outside it yields nothing, no team yields nothing, and a search narrows the ghosts', function (): void {
    $f = pg_fixture();

    assertSame([], pg_ghosts('capb', [$f['teams']['a']]), 'Team A is outside Team B\'s Captain\'s scope');
    assertSame([], pg_ghosts('capa', []), 'no team in view');
    assertSame([], pg_ghosts('capa', [0, -1]), 'no real team in view');

    $found = pg_ghosts('capa', [$f['teams']['a']], 'Tom Third');
    assertSame(['PG900003'], array_column($found, 'member_number'));
    assertSame([], pg_ghosts('capa', [$f['teams']['a']], 'Nobody Here'));
});

test('both screens carry the ghosts for a team view, count them nowhere, and draw none under a drill-down', function (): void {
    $f    = pg_fixture();
    $app  = pg_app();
    $capa = pg_user('capa');

    // View My Roster: the Captain's scope IS the team, so the ghosts ride
    // along with no team[] in the URL — and the totals do not move.
    $roster = RosterPage::fromApp($app)->page($capa, $f['year'], []);
    $numbers = array_column($roster['pending'], 'member_number');
    assertTrue(in_array('PG900003', $numbers, true) && in_array('PG900004', $numbers, true));
    assertTrue(!in_array('PG900001', $numbers, true), 'the one the roster has shown is a member now, not a ghost');
    assertSame(3, $roster['total'], 'the three members of Team A, and no ghost among them');
    foreach ($roster['rows'] as $row) {
        assertTrue(str_starts_with($row['member_number'], 'PG0000'), 'every row is a member');
    }

    // The search narrows both.
    $searched = RosterPage::fromApp($app)->page($capa, $f['year'], ['q' => 'Fourth']);
    assertSame(0, $searched['total']);
    assertSame(['PG900004'], array_column($searched['pending'], 'member_number'));

    // My Roster Status: the same ghosts, counted on no card.
    $status = StatusPage::fromApp($app)->page($capa, $f['year'], ['show' => 'all']);
    assertTrue(in_array('PG900004', array_column($status['pending'], 'member_number'), true));
    assertSame(3, $status['dashboard']['total']);
    assertSame(3, $status['total']);

    // A drill-down draws none: §7.3's figures land on the people counted.
    $drilled = StatusPage::fromApp($app)->page($capa, $f['year'], ['contact' => 'never']);
    assertSame([], $drilled['pending']);

    // A Vice Chairman with a team set: the set is the team view.
    $vc = RosterPage::fromApp($app)->page(pg_user('vc'), $f['year'], []);
    assertTrue(in_array('PG900004', array_column($vc['pending'], 'member_number'), true));

    // Ticking Team B as somebody whose scope does not hold it yields no
    // ghost: the Vice Chairman's set is Team A alone. (An Officer's team[]
    // is dropped before it is read, so the Captain's view stays Team A's.)
    $other = RosterPage::fromApp($app)->page(pg_user('vc'), $f['year'], ['team' => [$f['teams']['b']]]);
    assertSame([], $other['pending']);
    assertSame(0, $other['total']);
});

test('the rendered row carries the word, the name, the team and where it stands, escaped', function (): void {
    $f   = pg_fixture();
    $app = pg_app();

    pg_form('A', '', 'Sharp, <Sally>', 'PG Bus Ops Team A', $f['teams']['a']);

    $pending = pg_ghosts('capa', [$f['teams']['a']]);
    $user    = pg_user('capa');

    ob_start();
    require $app->path('app/views/pending-rcf.php');
    $html = (string) ob_get_clean();

    assertTrue(str_contains($html, 'Pending on an RCF'), 'the heading');
    assertTrue(str_contains($html, '<span class="chip-word">Pending RCF</span>'), 'the word on every ghost');
    assertTrue(str_contains($html, '&lt;Sally&gt; Sharp'), 'escaped, and read the roster\'s way');
    assertTrue(str_contains($html, 'no member number yet'), 'a newcomer with no number says so');
    assertTrue(str_contains($html, 'PG Bus Ops Team A'));
    assertTrue(str_contains($html, 'not yet in the roster'));
    assertTrue(!str_contains($html, 'Log contact'), 'nothing to log against a ghost');
    assertTrue(substr_count($html, 'class="member ghost"') === count($pending), 'one row per ghost');

    $pending = [];
    ob_start();
    require $app->path('app/views/pending-rcf.php');
    assertSame('', trim((string) ob_get_clean()), 'nothing drawn when nobody is pending');
});

test('pending fixtures are cleaned up', function (): void {
    pg_teardown(pg_pdo());
});
