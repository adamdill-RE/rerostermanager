<?php

declare(strict_types=1);

/**
 * Phase 8.5 — the six features the owner asked for after using Phase 8.
 *
 * Most of this file is about the two that change what somebody can SEE, and
 * both are asserted the same way: against a fixture, by asking the two
 * readers separately and requiring the same answer. `ScopedQuery` decides
 * which rows a query returns and `Access` decides whether an action on one
 * member is allowed, and a scope one narrows but the other does not is a
 * member an officer can act on and cannot see.
 *
 * The other assertion worth naming: promoting 21 Vice Chairmen must not
 * demote the 20 Senior Officers who already exist. That is a whole test on
 * its own, because it is the failure this design was chosen to avoid rather
 * than one it would announce.
 *
 * Generated, never real: this repository is public. Member numbers here are
 * 'FF000001', addresses are @example.com, phones are the reserved
 * (555) 555-01xx range and streets are Example Way.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\Admin\Designate;
use Rerm\Admin\DesignatePage;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Auth\Access;
use Rerm\Auth\Auth;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Password;
use Rerm\Auth\Subject;
use Rerm\Auth\TitleMap;
use Rerm\Auth\User;
use Rerm\Roster\EligibleOfficers;
use Rerm\Roster\DroppedPage;
use Rerm\Roster\ScopedQuery;
use Rerm\Routes;

// ---------------------------------------------------------------------------
// The rules that need no database
// ---------------------------------------------------------------------------

test('the dropped route is guarded, scoped, and shares view_roster', function (): void {
    // It is the roster filtered to the people who fell off it, so it takes
    // the roster's capability rather than a sixteenth row in the matrix.
    assertSame(Capability::ViewRoster->value, Routes::guard('dropped'));
    assertSame(Level::Officer, Capability::ViewRoster->minimumLevel());
    assertSame(15, count(Capability::cases()), 'the matrix is still 15 rows');
});

test('the dropped screen has no write path at all', function (): void {
    // Purge and restore stay Admin, on /purge, behind the typed word. What an
    // officer needs here is to know, not to act.
    $source = (string) file_get_contents(__DIR__ . '/../app/views/dropped.php');
    assertTrue($source !== '', 'app/views/dropped.php is readable');

    assertSame(0, preg_match('/method=["\']post/i', $source), 'no POST');
    assertSame(0, preg_match('/\bCsrf\b/', $source), 'nothing to protect, so nothing to check');
});

test('ScopedQuery offers dropped members through a SEPARATE method, never a flag', function (): void {
    // The design decision this feature turns on. A boolean parameter on
    // forUser() is exactly how an ordinary roster read one day starts
    // including dropped members: somebody threads it through from two layers
    // up and every test still passes.
    $source = (string) file_get_contents(__DIR__ . '/../app/src/Roster/ScopedQuery.php');

    assertTrue(
        str_contains($source, 'public static function droppedForUser(User $user, string $alias'),
        'the permissive shape has its own name'
    );
    assertSame(
        1,
        preg_match('/public static function forUser\(User \$user, string \$alias = \'m\'\): self/', $source),
        'forUser() still takes a user and an alias, and nothing else'
    );

    // The two base predicates are opposites and both keep the other two
    // columns: a purged member belongs on neither screen.
    assertTrue(str_contains(ScopedQuery::visible('m'), 'm.dropped_since_import_id IS NULL'));
    assertTrue(str_contains(ScopedQuery::dropped('m'), 'm.dropped_since_import_id IS NOT NULL'));

    foreach ([ScopedQuery::visible('m'), ScopedQuery::dropped('m')] as $predicate) {
        assertTrue(str_contains($predicate, 'm.is_system = 0'), 'the system row is in neither');
        assertTrue(str_contains($predicate, 'm.purged_at IS NULL'), 'a purged member is in neither');
    }
});

test('the word absent is gone from the roster vocabulary, in the schema too', function (): void {
    // Phase 8.5 feature 2. The rename had to reach the column and the ENUM
    // value, not only the prose — a schema that contradicts the screens is
    // the drift this repository refuses everywhere else.
    foreach ([
        'app/src/Roster/ScopedQuery.php',
        'app/src/Admin/Purge.php',
        'app/src/Admin/PurgePage.php',
        'app/src/Import/Importer.php',
    ] as $file) {
        $source = (string) file_get_contents(__DIR__ . '/../' . $file);
        assertSame(
            0,
            substr_count($source, 'absent_since_import_id'),
            $file . ' uses the renamed column'
        );
    }

    // The migration renames all three places, and says why it is not atomic.
    $migration = (string) file_get_contents(
        __DIR__ . '/../db/migrations/007_rename_absent_to_dropped.sql'
    );
    assertTrue($migration !== '', 'migration 007 is readable');

    foreach ([
        'RENAME COLUMN `absent_since_import_id` TO `dropped_since_import_id`',
        'RENAME COLUMN `rows_absent` TO `rows_dropped`',
    ] as $statement) {
        assertTrue(str_contains($migration, $statement), 'migration 007 contains: ' . $statement);
    }

    // Schema migrations cannot be atomic — MySQL commits on DDL — so it must
    // never claim to be.
    assertSame(0, substr_count($migration, 'rerm:atomic'), '007 does not claim to be atomic');
});

test('dropped and purged stay distinct on the screens that show both', function (): void {
    // The real hazard of the rename: after it, the two words sound alike and
    // mean states with different reversibility. Every screen showing either
    // has to keep saying which.
    $dropped = (string) file_get_contents(__DIR__ . '/../app/views/dropped.php');
    assertTrue(
        str_contains($dropped, 'Dropped is not removed'),
        'the dropped screen says what a drop is not'
    );
    assertTrue(
        str_contains($dropped, 'only they can'),
        'and that purging is somebody else\'s, separate, act'
    );
});

test('the nav strip is in the layout, guarded, and renders for a user only', function (): void {
    // One file, no per-view change: render() extracts the view's data into
    // its own scope and then requires layout.php from there, so $user is
    // already present for the screens that pass one.
    $layout = (string) file_get_contents(__DIR__ . '/../app/views/layout.php');

    assertTrue(str_contains($layout, 'class="topbar'), 'the strip exists');
    assertTrue(
        str_contains($layout, 'isset($user) && $user instanceof Rerm\\Auth\\User'),
        'and renders only for a signed-in user'
    );
    assertTrue(str_contains($layout, 'position: sticky'), 'it is sticky, which is the whole point');
    assertTrue(str_contains($layout, 'min-height: 56px'), 'and meets the 56px touch target');

    // No JavaScript, ever: the CSP forbids it and this is a sticky bar, not
    // a menu that needs opening.
    assertSame(0, preg_match('/<script/i', $layout), 'no script');
});

test('render() sources the signed-in user itself, so no route can lose the nav', function (): void {
    // The bar above only appears when $user reaches the layout, and for a
    // while that depended on each route remembering to pass one. Twenty of
    // the thirty-four render() calls did not, and /import — a 1,954-row diff,
    // the longest screen in the application and the one that needs a way back
    // most — was among them.
    //
    // So render() now fills the gap once, from the single $user the
    // dispatcher establishes before any handler runs. This reads the source
    // rather than a rendered page because the property being protected is
    // "no route has to remember", which no single page can demonstrate.
    $front = (string) file_get_contents(__DIR__ . '/../public/index.php');

    $from = strpos($front, 'function render(');
    assertTrue($from !== false, 'render() is findable');
    $to   = strpos($front, "\nfunction ", (int) $from + 1);
    $body = substr($front, (int) $from, (int) $to - (int) $from);

    assertTrue(str_contains($body, 'global $user;'), 'render() reaches for the request user');
    assertTrue(
        str_contains($body, '$data += [\'user\' => $user];'),
        'and fills it in as a default'
    );

    // += and not an assignment: a route that passes its own user must still
    // win, because a screen showing the wrong name is worse than one showing
    // no bar at all.
    assertSame(0, substr_count($body, '$data[\'user\'] ='), 'never overwrites a route');

    // Before extract(), or both the view and the layout would miss it.
    assertTrue(
        strpos($body, 'global $user;') < strpos($body, 'extract($data, EXTR_SKIP)'),
        'the default is in place before the data is extracted'
    );
});

test('the import preview carries a team on every table that names a member', function (): void {
    $view = (string) file_get_contents(__DIR__ . '/../app/views/import.php');

    // Three row tables; all three now name a team. "New members" always did.
    assertSame(
        3,
        substr_count($view, '<td data-label="Team">'),
        'created, changed and dropped rows all show a team'
    );
    assertSame(
        3,
        substr_count($view, '<th scope="col">Team</th>'),
        'and all three declare the column'
    );
});

// ---------------------------------------------------------------------------
// The fixture
// ---------------------------------------------------------------------------

function ff_pdo(): PDO
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
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_user_team'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

function ff_teardown(PDO $pdo): void
{
    $members = "SELECT id FROM member WHERE member_number LIKE 'FF%'";

    // app_user_team before app_user, app_user before member: every foreign
    // key here RESTRICTs.
    $doomed = $pdo->query("SELECT id FROM app_user WHERE member_id IN ({$members})")
        ->fetchAll(PDO::FETCH_COLUMN);

    if ($doomed !== []) {
        $ids = implode(', ', array_map('intval', $doomed));
        $pdo->exec("DELETE FROM app_user_team WHERE app_user_id IN ({$ids})");
        $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$ids})");
        // auth_token RESTRICTs against app_user, and one test inserts a live
        // session so it can watch a reset kill it.
        $pdo->exec("DELETE FROM auth_token WHERE user_id IN ({$ids})");
        $pdo->exec("DELETE FROM password_reset WHERE user_id IN ({$ids})");
        $pdo->exec("DELETE FROM member_metric WHERE progress_by IN ({$ids})");
        $pdo->exec("DELETE FROM contact_log WHERE contacted_by IN ({$ids})");
        // MySQL refuses to UPDATE a table while SELECTing from it (1093);
        // MariaDB allows it. Production is MySQL, so the ids came out first.
        $pdo->exec("UPDATE app_user SET granted_by = NULL WHERE granted_by IN ({$ids})");
    }

    $pdo->exec("DELETE FROM audit_log WHERE entity_id LIKE 'FF%'");
    $pdo->exec("DELETE FROM contact_log WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM assignment WHERE member_id IN ({$members}) OR officer_member_id IN ({$members})");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("UPDATE member SET dropped_since_import_id = NULL, last_seen_import_id = NULL "
        . "WHERE member_number LIKE 'FF%'");
    $pdo->exec("DELETE FROM import_warning WHERE import_batch_id IN "
        . "(SELECT id FROM (SELECT id FROM import_batch WHERE filename LIKE 'FF-%') x)");
    $pdo->exec("DELETE FROM import_batch WHERE filename LIKE 'FF-%'");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'FF%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'FF %'");
    $pdo->exec("DELETE FROM division WHERE name LIKE 'FF %'");
}

/**
 * One division, three teams, and one of each kind of officer this phase
 * cares about.
 *
 *   FF Division
 *     FF Alpha    vc (Vice Chairman)  coord (Coordinator)  a1  a2   drop_a
 *     FF Beta     b1                                              drop_b
 *     FF Gamma    g1
 *
 * `vc` is the promoted title: Senior Officer level, team breadth, so with
 * nothing recorded they see FF Alpha alone. `coord` is one of the twenty who
 * already existed and must keep the whole division. `drop_a` and `drop_b` are
 * dropped by a batch, one on each of two teams, so a scoped dropped list has
 * something to get wrong.
 *
 * @return array<string, mixed>
 */
function ff_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = ff_pdo();
    ff_teardown($pdo);

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();

    $pdo->exec("INSERT INTO division (name) VALUES ('FF Division')");
    $division = (int) $pdo->lastInsertId();

    $teams = [];
    foreach (['FF Alpha', 'FF Beta', 'FF Gamma'] as $name) {
        $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:n, :d)')
            ->execute([':n' => $name, ':d' => $division]);
        $teams[$name] = (int) $pdo->lastInsertId();
    }

    $pdo->prepare(
        'INSERT INTO import_batch (show_year_id, mode, filename, sha256, dry_run, applied_at)'
        . " VALUES (:y, 'complete', 'FF-roster.xls', :sha, 0, UTC_TIMESTAMP())"
    )->execute([':y' => $year, ':sha' => str_repeat('f', 64)]);
    $batch = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, title, title_level,'
        . ' division_id, team_id, address, city, state, zip, phone, phone_e164, phone_type, email,'
        . ' dropped_since_import_id)'
        . ' VALUES (:n, :f, :l, :t, :tl, :d, :tm, :a, :c, :s, :z, :ph, :pe, :pt, :em, :drop)'
    );

    /** key => [number, first, last, title, level, team, dropped] */
    $people = [
        'vc'     => ['FF000001', 'Vera',  'Vice',  'Vice Chairman',    'senior_officer', 'FF Alpha', false],
        'coord'  => ['FF000002', 'Cora',  'Coord', 'Coordinator',      'senior_officer', 'FF Alpha', false],
        'exec'   => ['FF000003', 'Evan',  'Exec',  'Division Chairman', 'executive_officer', 'FF Alpha', false],
        'cap'    => ['FF000004', 'Cal',   'Cap',   'Captain',          'officer',        'FF Beta',  false],
        'a1'     => ['FF000005', 'Ann',   'Alpha', 'Committee Member', 'member',         'FF Alpha', false],
        'a2'     => ['FF000006', 'Abe',   'Alpha', 'Committee Member', 'member',         'FF Alpha', false],
        'b1'     => ['FF000007', 'Bea',   'Beta',  'Committee Member', 'member',         'FF Beta',  false],
        'g1'     => ['FF000008', 'Gil',   'Gamma', 'Committee Member', 'member',         'FF Gamma', false],
        'drop_a' => ['FF000009', 'Dora',  'Gone',  'Committee Member', 'member',         'FF Alpha', true],
        'drop_b' => ['FF000010', 'Dan',   'Gone',  'Committee Member', 'member',         'FF Beta',  true],
    ];

    $ids = [];
    foreach ($people as $key => $row) {
        [$number, $first, $last, $title, $level, $team, $dropped] = $row;

        $insert->execute([
            ':n' => $number, ':f' => $first, ':l' => $last, ':t' => $title, ':tl' => $level,
            ':d' => $division, ':tm' => $teams[$team],
            ':a' => '1 Example Way', ':c' => 'Houston', ':s' => 'TX', ':z' => '77001',
            ':ph' => '(555) 555-0100', ':pe' => '+15555550100', ':pt' => 'CELL PHONE',
            ':em' => strtolower($key) . '@example.com',
            ':drop' => $dropped ? $batch : null,
        ]);
        $ids[$key] = (int) $pdo->lastInsertId();
    }

    $passwords = Password::fromApp($GLOBALS['rerm_app']);
    $account   = $pdo->prepare(
        'INSERT INTO app_user (member_id, level, password_hash, must_change_password, is_active)'
        . ' VALUES (:m, :l, :h, 0, 1)'
    );

    $users = [];
    foreach (['vc' => 'senior_officer', 'coord' => 'senior_officer',
              'exec' => 'executive_officer', 'cap' => 'officer'] as $key => $level) {
        $account->execute([
            ':m' => $ids[$key], ':l' => $level,
            ':h' => $passwords->hash('known-password'),
        ]);
        $users[$key] = (int) $pdo->lastInsertId();
    }

    return $fixture = [
        'year'     => $year,
        'division' => $division,
        'teams'    => $teams,
        'batch'    => $batch,
        'ids'      => $ids,
        'users'    => $users,
    ];
}

/**
 * The signed-in User for a fixture account, loaded THROUGH Auth so the team
 * scope is read the way a real request reads it.
 */
function ff_user(string $key): User
{
    $f    = ff_fixture();
    $auth = Auth::fromApp($GLOBALS['rerm_app']);

    $load = new ReflectionMethod($auth, 'loadUser');
    $load->setAccessible(true);

    $user = $load->invoke($auth, $f['users'][$key]);
    assertTrue($user instanceof User, "fixture account {$key} loads");

    return $user;
}

/** The fixture members a user can actually see, by member number. */
function ff_visible(User $user): array
{
    $scoped = ScopedQuery::forUser($user);
    $read   = ff_pdo()->prepare(
        'SELECT m.member_number FROM member m WHERE ' . $scoped->predicate()
        . " AND m.member_number LIKE 'FF%' ORDER BY m.member_number"
    );
    $read->execute($scoped->bindings());

    return $read->fetchAll(PDO::FETCH_COLUMN);
}

/** Access's answer about one fixture member, for the same capability. */
function ff_allows(User $user, string $memberKey): bool
{
    $f    = ff_fixture();
    $read = ff_pdo()->prepare('SELECT id, division_id, team_id FROM member WHERE id = :id');
    $read->execute([':id' => $f['ids'][$memberKey]]);

    return Access::allows($user, Capability::ViewRoster, Subject::fromMemberRow($read->fetch()));
}

// ---------------------------------------------------------------------------
// Feature 6 — the scope, which is the part that can go wrong quietly
// ---------------------------------------------------------------------------

test('a Vice Chairman is a Senior Officer who sees their own team, and no more', function (): void {
    $vc = ff_user('vc');

    assertSame(Level::SeniorOfficer, $vc->level, 'the level moved');
    assertSame([ff_fixture()['teams']['FF Alpha']], $vc->scopeTeamIds, 'the scope did not');

    // Exactly the visible members of FF Alpha. drop_a is on that team and is
    // NOT here, because a dropped member is out of every ordinary read.
    assertSame(
        ['FF000001', 'FF000002', 'FF000003', 'FF000005', 'FF000006'],
        ff_visible($vc)
    );
});

test('promoting 21 Vice Chairmen does not demote the 20 Senior Officers who exist', function (): void {
    // The failure this design was chosen to avoid, and the one that would not
    // announce itself: a Coordinator, an Ambassador or a Division Vice
    // Chairman quietly narrowed from their division to one team.
    $coord = ff_user('coord');

    assertSame(Level::SeniorOfficer, $coord->level);
    assertSame([], $coord->scopeTeamIds, 'no narrowing, so the division stands');
    assertSame(ff_fixture()['division'], $coord->scopeDivisionId);

    // Every visible member of the division, across all three teams.
    assertSame(
        ['FF000001', 'FF000002', 'FF000003', 'FF000004',
            'FF000005', 'FF000006', 'FF000007', 'FF000008'],
        ff_visible($coord)
    );
});

test('the two readers agree, member by member', function (): void {
    // ScopedQuery says which rows; Access says whether an action is allowed.
    // A scope one narrows and the other does not is a member an officer can
    // act on and cannot see, which no screen would reveal.
    foreach (['vc', 'coord', 'exec', 'cap'] as $key) {
        $user    = ff_user($key);
        $visible = ff_visible($user);

        foreach (['a1', 'b1', 'g1'] as $target) {
            $number = ff_fixture()['ids'][$target];
            $inList = in_array(
                (string) ff_pdo()->query("SELECT member_number FROM member WHERE id = {$number}")
                    ->fetchColumn(),
                $visible,
                true
            );

            assertSame(
                $inList,
                ff_allows($user, $target),
                "{$key} vs {$target}: ScopedQuery and Access must agree"
            );
        }
    }
});

test('a team set narrows a Senior Officer, and only to the teams named', function (): void {
    $f     = ff_fixture();
    $admin = ff_user('exec');

    // An Executive is not an Admin, so it cannot set one — spec 4.4.
    $refused = Designate::fromApp($GLOBALS['rerm_app'])->apply($admin, [
        'action' => 'team_scope', 'member_id' => (string) $f['ids']['coord'],
        'team_scope' => [(string) $f['teams']['FF Beta']],
    ]);
    assertSame('refused', $refused['outcome'], 'the team set is Admin-only');

    // Promote the Executive to Admin for the rest of this test.
    ff_pdo()->prepare("UPDATE app_user SET granted_level = 'admin' WHERE id = :id")
        ->execute([':id' => $f['users']['exec']]);

    $set = Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'team_scope', 'member_id' => (string) $f['ids']['coord'],
        'team_scope' => [(string) $f['teams']['FF Beta'], (string) $f['teams']['FF Gamma']],
    ]);
    assertSame('team_scope_set', $set['outcome']);

    $coord = ff_user('coord');
    assertSame(2, count($coord->scopeTeamIds));

    // Beta and Gamma only — the division they would otherwise have is gone.
    assertSame(['FF000004', 'FF000007', 'FF000008'], ff_visible($coord));
    assertSame(false, ff_allows($coord, 'a1'), 'Alpha is outside the set');
    assertSame(true, ff_allows($coord, 'b1'));
    assertSame(true, ff_allows($coord, 'g1'));
});

test('clearing the team set puts them back where their title says', function (): void {
    $f = ff_fixture();

    $cleared = Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'team_scope', 'member_id' => (string) $f['ids']['coord'], 'team_scope' => [],
    ]);
    assertSame('team_scope_cleared', $cleared['outcome']);

    $coord = ff_user('coord');
    assertSame([], $coord->scopeTeamIds);
    assertSame(8, count(ff_visible($coord)), 'the whole division again');

    // And it is logged both ways, with real JSON.
    $read = ff_pdo()->prepare(
        'SELECT before_json, after_json FROM audit_log WHERE action = :a AND entity_id = :e'
        . ' ORDER BY id DESC LIMIT 1'
    );
    $read->execute([':a' => Action::SetTeamScope->value, ':e' => 'FF000002']);
    $row = $read->fetch();

    assertTrue(is_array($row), 'the clearing is in the audit log');
    assertSame(2, count(json_decode((string) $row['before_json'], true)['teams']));
    assertSame([], json_decode((string) $row['after_json'], true)['teams']);
});

test('an Officer can be given a second team, and it widens sight only', function (): void {
    $f = ff_fixture();

    // Phase 8.6 reverses the Senior-Officer-only rule of 8.5. The case that
    // reversed it: a Captain runs their own team and helps with another,
    // which a single scope_team_id cannot say.
    $cap = ff_user('cap');
    assertSame([], $cap->scopeTeamIds, 'no set to begin with');
    assertSame(['FF000004', 'FF000007'], ff_visible($cap), 'their own team');

    $result = Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'team_scope', 'member_id' => (string) $f['ids']['cap'],
        'team_scope' => [
            (string) $f['teams']['FF Beta'],
            (string) $f['teams']['FF Alpha'],
        ],
    ]);
    assertSame('team_scope_set', $result['outcome']);

    // Both readers move together, which is the whole point of resolving the
    // scope once: a member the query shows and Access refuses is a bug that
    // only surfaces when somebody tries to act.
    $widened = ff_user('cap');
    assertSame(2, count($widened->scopeTeamIds));
    assertSame(
        ['FF000001', 'FF000002', 'FF000003', 'FF000004', 'FF000005', 'FF000006', 'FF000007'],
        ff_visible($widened),
        'now both teams'
    );
    assertTrue(ff_allows($widened, 'a1'), 'and they may act on the team they were given');
    assertTrue(!ff_allows($widened, 'g1'), 'but not on a team nobody ticked');

    // Restore. The fixture is shared, so a test that widens this Officer and
    // walks away makes every later reader of their scope pass or fail by
    // ordering rather than by rule — which is exactly what happened.
    Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'team_scope', 'member_id' => (string) $f['ids']['cap'],
        'team_scope' => [],
    ]);
    assertSame([], ff_user('cap')->scopeTeamIds, 'and the widening is put back');
});

test('a team set widens sight without making them an assignable officer', function (): void {
    $f = ff_fixture();

    // Settled with the owner, 28 August: assignment stays same-team, decided
    // by the officer's OWN member row in EligibleOfficers, which never reads
    // scope. Somebody helping chase a team is not thereby its officer of
    // record, and the Assign screen must not start naming them as one.
    Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'team_scope', 'member_id' => (string) $f['ids']['cap'],
        'team_scope' => [
            (string) $f['teams']['FF Beta'],
            (string) $f['teams']['FF Alpha'],
        ],
    ]);

    $officers = (new EligibleOfficers(ff_pdo()))
        ->forTeam($f['teams']['FF Alpha'], $f['year']);
    $numbers = array_map(static fn (array $o): string => (string) $o['member_number'], $officers);

    assertTrue(
        !in_array('FF000004', $numbers, true),
        'the widened Officer is not offered as an FF Alpha officer'
    );

    // Restore. The fixture is shared, so a test that widens this Officer and
    // walks away makes every later reader of their scope pass or fail by
    // ordering rather than by rule — which is exactly what happened.
    Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'team_scope', 'member_id' => (string) $f['ids']['cap'],
        'team_scope' => [],
    ]);
    assertSame([], ff_user('cap')->scopeTeamIds, 'and the widening is put back');
});

test('an Officer with no set still sees exactly their own team', function (): void {
    // The reversal above must not widen anybody by default: an empty set
    // means "not narrowed by this mechanism", never "every team".
    $f   = ff_fixture();
    $cap = ff_user('cap');

    assertSame([], $cap->scopeTeamIds);
    assertSame($f['teams']['FF Beta'], $cap->scopeTeamId);
    assertSame(['FF000004', 'FF000007'], ff_visible($cap));
});

test('the level select opens on what is in force, never on Member', function (): void {
    // The bug: the option was marked selected by comparing to granted_level,
    // which is NULL for every title-derived officer. Nothing matched, so the
    // browser fell back to the first option — Member, the first enum case —
    // and an Admin who opened a row for some other reason and pressed Grant
    // durably demoted an Officer while the row still read "Officer".
    $view = (string) file_get_contents(__DIR__ . '/../app/views/designate.php');

    assertTrue(
        str_contains($view, '$current = $row[\'granted_level\'] ?? $row[\'effective_level\'];'),
        'the selected option is decided by the effective level'
    );
    assertSame(
        0,
        substr_count($view, '$row[\'granted_level\'] === $level ? \' selected\' : \'\''),
        'and no longer by the grant alone'
    );
});

test('a title-derived Officer opens on Officer, and Member is not preselected', function (): void {
    $f    = ff_fixture();
    $page = DesignatePage::fromApp($GLOBALS['rerm_app']);

    // The Captain's level comes from their TITLE, so granted_level is NULL.
    // This is the exact row the bug fired on: nothing matched, the browser
    // fell back to the first option, and pressing Grant wrote member.
    $view = $page->page(ff_user('exec'), ['q' => 'FF000004', 'member' => (string) $f['ids']['cap']]);
    $html = ff_render('designate', 'Designate Users', [
        'user' => ff_user('exec'), 'notices' => [], 'designate' => $view,
    ]);

    assertSame(
        1,
        preg_match('/<option value="officer"\s+selected>/', $html),
        'the control opens on the level in force'
    );
    assertSame(
        0,
        preg_match('/<option value="member"\s+selected>/', $html),
        'and Member is not the silent default'
    );
});

test('the division override is offered only to the levels that read it', function (): void {
    // ScopedQuery and Access both consult team_id and never division_id below
    // Senior Officer, so the control did nothing for an Officer: an Admin
    // could set it, be told it saved, and see no change.
    $view = (string) file_get_contents(__DIR__ . '/../app/views/designate.php');
    assertTrue(
        str_contains($view, '$row[\'may_division_scope\']'),
        'the division form is gated on the flag'
    );

    // And the flag means what it says.
    $page = (string) file_get_contents(__DIR__ . '/../app/src/Admin/DesignatePage.php');
    assertTrue(
        str_contains($page, '\'may_division_scope\' => $effective !== null'),
        'set from the effective level'
    );
    assertTrue(
        str_contains($page, 'atLeast(Level::SeniorOfficer)'),
        'at Senior Officer and above'
    );

    // The single-team select is gone with it: two controls for one idea is
    // how the two come to disagree.
    assertSame(
        0,
        substr_count($view, 'name="scope_team_id"'),
        'no single-team select beside the checkbox set'
    );
});

test('the scope form cannot clear a team override it does not render', function (): void {
    $f = ff_fixture();

    // The division form no longer posts scope_team_id, so scope() must read
    // ABSENT as "leave alone" and only an empty string as "clear". Without
    // that, saving a division would silently drop a single-team override.
    $designate = Designate::fromApp($GLOBALS['rerm_app']);

    $designate->apply(ff_user('exec'), [
        'action' => 'scope', 'member_id' => (string) $f['ids']['coord'],
        'scope_division_id' => '', 'scope_team_id' => (string) $f['teams']['FF Beta'],
    ]);
    assertSame($f['teams']['FF Beta'], ff_user('coord')->scopeTeamId, 'override is set');

    // A division-only POST, exactly as the form now sends it.
    $designate->apply(ff_user('exec'), [
        'action' => 'scope', 'member_id' => (string) $f['ids']['coord'],
        'scope_division_id' => (string) $f['divisions']['FF Division'],
    ]);
    assertSame(
        $f['teams']['FF Beta'],
        ff_user('coord')->scopeTeamId,
        'and survives a division save that never mentioned it'
    );
});

test('controls stop being full-width slabs above 720px', function (): void {
    // RESM is one screen used one-handed outdoors in February and every
    // control is sized for that. RERM is also a desk tool — the tables
    // already transform at this width and the controls never did, so Search
    // sat as a 64px slab across a 1300px page.
    $layout = (string) file_get_contents(__DIR__ . '/../app/views/layout.php');

    assertTrue(str_contains($layout, 'button {'), 'the phone rule still exists');
    assertTrue(
        str_contains($layout, 'min-height: 64px'),
        'and still gives a phone its 64px target'
    );

    $desktop = strpos($layout, '@media (min-width: 720px) {', strpos($layout, 'button:focus-visible'));
    assertTrue($desktop !== false, 'a desktop counterpart exists');

    $block = substr($layout, (int) $desktop, 900);
    assertTrue(str_contains($block, 'width: auto'), 'buttons size to content');
    assertTrue(str_contains($block, 'min-width: 12rem'), 'with a floor so they stay tappable');
});

test('the gate widened to Officer, not to everybody', function (): void {
    $f = ff_fixture();

    // An Executive Officer already sees the whole committee, so narrowing one
    // would put a WHERE clause on a query that should have none. Refused by
    // name rather than silently ignored, so the Admin finds out.
    $result = Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'team_scope', 'member_id' => (string) $f['ids']['exec'],
        'team_scope' => [(string) $f['teams']['FF Alpha']],
    ]);
    assertSame('not_scopable', $result['outcome']);
});

test('an explicit division override beats the title default', function (): void {
    // A Vice Chairman defaults to their own team — but if an Admin has said
    // "this division", that is an answer and the default must not
    // second-guess it.
    $f = ff_fixture();

    Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'scope', 'member_id' => (string) $f['ids']['vc'],
        'scope_division_id' => (string) $f['division'], 'scope_team_id' => '',
    ]);

    $vc = ff_user('vc');
    assertSame([], $vc->scopeTeamIds, 'the title default stood down');
    assertSame(8, count(ff_visible($vc)), 'and the named division stands');

    // Put it back for the tests after this one.
    Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'scope', 'member_id' => (string) $f['ids']['vc'],
        'scope_division_id' => '', 'scope_team_id' => '',
    ]);
    assertSame(1, count(ff_user('vc')->scopeTeamIds));
});

// ---------------------------------------------------------------------------
// Feature 5 — the dropped list, scoped
// ---------------------------------------------------------------------------

test('the dropped list is scoped exactly like every other roster read', function (): void {
    $page = DroppedPage::fromApp($GLOBALS['rerm_app']);
    $f    = ff_fixture();

    // A Vice Chairman sees Alpha's dropped member and not Beta's.
    $vc = $page->page(ff_user('vc'), $f['year'], []);
    assertSame(
        ['FF000009'],
        array_map(static fn (array $r): string => $r['member_number'], $vc['rows'])
    );

    // Their Coordinator, division-scoped, sees both — in the screen's own
    // order, which is the batch descending and then the name. Both were
    // dropped by the same import, so the name decides: Dan before Dora.
    $coord = $page->page(ff_user('coord'), $f['year'], []);
    assertSame(
        ['FF000010', 'FF000009'],
        array_map(static fn (array $r): string => $r['member_number'], $coord['rows'])
    );

    // The Officer on Beta sees only Beta's.
    $cap = $page->page(ff_user('cap'), $f['year'], []);
    assertSame(
        ['FF000010'],
        array_map(static fn (array $r): string => $r['member_number'], $cap['rows'])
    );
});

test('the dropped list says which import dropped them', function (): void {
    $f    = ff_fixture();
    $page = DroppedPage::fromApp($GLOBALS['rerm_app'])->page(ff_user('coord'), $f['year'], []);

    foreach ($page['rows'] as $row) {
        assertSame($f['batch'], (int) $row['batch_id'], 'the batch that dropped them');
        assertSame('FF-roster.xls', $row['batch_filename']);
        assertTrue($row['dropped_at'] !== null, 'and when it ran');
    }

    // The contact actions are the point of the row — this is the screen
    // somebody rings from to ask whether the person actually left.
    assertSame(true, $page['rows'][0]['can_call']);
    assertSame(true, $page['rows'][0]['can_text'], 'CELL PHONE, so a text works');
    assertSame(true, $page['rows'][0]['can_email']);
});

test('a dropped member is on the dropped list and nowhere else', function (): void {
    // The whole reason the screen exists: every ordinary read hides them.
    $coord = ff_user('coord');

    assertTrue(!in_array('FF000009', ff_visible($coord), true), 'not on the roster');

    // Access answers SCOPE, not visibility — deliberately, and it always
    // has: "two inputs only… deliberately nothing else". What keeps a
    // dropped member unreachable is that every read which could produce a
    // Subject for one excludes them first, so the question is never asked.
    // That is the guarantee worth testing, so it is tested against the write
    // path rather than against the matrix.
    $logged = Rerm\Roster\LogContact::fromApp($GLOBALS['rerm_app'])->log($coord, [
        'member_id'    => (string) ff_fixture()['ids']['drop_a'],
        'contact_type' => 'call',
        'notes'        => 'should never land',
    ]);
    assertSame('not_found', $logged['outcome'], 'a dropped member takes no contact');

    $dropped = DroppedPage::fromApp($GLOBALS['rerm_app'])
        ->page($coord, ff_fixture()['year'], []);
    assertTrue(
        in_array('FF000009', array_map(
            static fn (array $r): string => $r['member_number'],
            $dropped['rows']
        ), true),
        'but they are here'
    );
});

test('a purged member is on neither list', function (): void {
    // Dropped and purged are different states, and this is where the rename
    // could have blurred them.
    $f = ff_fixture();

    ff_pdo()->prepare('UPDATE member SET purged_at = UTC_TIMESTAMP() WHERE id = :id')
        ->execute([':id' => $f['ids']['drop_a']]);

    try {
        $coord   = ff_user('coord');
        $dropped = DroppedPage::fromApp($GLOBALS['rerm_app'])->page($coord, $f['year'], []);

        assertSame(
            ['FF000010'],
            array_map(static fn (array $r): string => $r['member_number'], $dropped['rows']),
            'a purged member leaves the dropped list too'
        );
        assertTrue(!in_array('FF000009', ff_visible($coord), true));
    } finally {
        ff_pdo()->prepare('UPDATE member SET purged_at = NULL WHERE id = :id')
            ->execute([':id' => $f['ids']['drop_a']]);
    }
});

// ---------------------------------------------------------------------------
// Feature 1 — the Admin password reset
// ---------------------------------------------------------------------------

test('nobody can reset the password of somebody who outranks them', function (): void {
    // The load-bearing line of the feature. A reset sets the password to a
    // value the actor knows, so it is equivalent to taking the account: a
    // Senior Officer who could reset an Admin has escalated through a button
    // labelled "help".
    $f = ff_fixture();

    $result = Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('coord'), [
        'action' => 'reset_password', 'member_id' => (string) $f['ids']['exec'],
    ]);
    assertSame('refused', $result['outcome']);

    // The Executive's password is untouched.
    $hash = (string) ff_pdo()->query(
        "SELECT password_hash FROM app_user WHERE id = {$f['users']['exec']}"
    )->fetchColumn();
    assertTrue(
        Password::fromApp($GLOBALS['rerm_app'])->verify('known-password', $hash),
        'their password still works'
    );
});

test('a reset lands, forces a change, and kills every session', function (): void {
    $f         = ff_fixture();
    $passwords = Password::fromApp($GLOBALS['rerm_app']);

    // A live session to observe dying.
    ff_pdo()->prepare(
        'INSERT INTO auth_token (user_id, selector, verifier_hash, expires_at)'
        . ' VALUES (:u, :s, :v, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))'
    )->execute([
        ':u' => $f['users']['cap'], ':s' => str_repeat('c', 32), ':v' => str_repeat('d', 64),
    ]);

    $result = Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'reset_password', 'member_id' => (string) $f['ids']['cap'],
    ]);
    assertSame('password_reset', $result['outcome']);

    $row = ff_pdo()->query(
        "SELECT password_hash, must_change_password FROM app_user WHERE id = {$f['users']['cap']}"
    )->fetch();

    assertSame(false, $passwords->verify('known-password', (string) $row['password_hash']),
        'the old password stops working');
    assertSame(true, $passwords->verify('1234', (string) $row['password_hash']),
        'and the shipped initial one starts');
    assertSame(1, (int) $row['must_change_password'], 'they must choose a new one');

    $live = (int) ff_pdo()->query(
        "SELECT COUNT(*) FROM auth_token WHERE user_id = {$f['users']['cap']} AND revoked_at IS NULL"
    )->fetchColumn();
    assertSame(0, $live, 'every session is revoked, not all but one');
});

test('the master administrator cannot be reset from a screen', function (): void {
    // It has /setup and bin/set-admin-password.php. A web screen that could
    // seize it would widen the blast radius of one stolen Admin session for
    // no benefit — and this falls out of the member read excluding is_system,
    // rather than being a special case that could be forgotten.
    $master = (int) ff_pdo()->query(
        "SELECT id FROM member WHERE member_number = '" . App::MASTER_ADMIN_NUMBER . "'"
    )->fetchColumn();

    $result = Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'reset_password', 'member_id' => (string) $master,
    ]);
    assertSame('not_found', $result['outcome']);
});

test('a member with no login has no password to reset', function (): void {
    $f = ff_fixture();

    $result = Designate::fromApp($GLOBALS['rerm_app'])->apply(ff_user('exec'), [
        'action' => 'reset_password', 'member_id' => (string) $f['ids']['a1'],
    ]);
    assertSame('no_account', $result['outcome']);
});

test('the reset is audited with who, whom and what rank', function (): void {
    $read = ff_pdo()->prepare(
        'SELECT actor_user_id, after_json FROM audit_log WHERE action = :a AND entity_id = :e'
        . ' ORDER BY id DESC LIMIT 1'
    );
    $read->execute([':a' => Action::PasswordResetByAdmin->value, ':e' => 'FF000004']);
    $row = $read->fetch();

    assertTrue(is_array($row), 'the reset is logged');
    assertSame(ff_fixture()['users']['exec'], (int) $row['actor_user_id']);

    $after = json_decode((string) $row['after_json'], true);
    assertSame('all revoked', $after['sessions']);
    assertSame('officer', $after['target_level'], 'and how far the actor reached');
});

// ---------------------------------------------------------------------------
// The screens render
// ---------------------------------------------------------------------------

/**
 * One view inside the real page shell, as index.php renders it.
 *
 * @param array<string, mixed> $data
 */
function ff_render(string $view, string $title, array $data): string
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    $_SESSION ??= [];
    $wide = true;
    extract($data, EXTR_SKIP);

    ob_start();
    require $app->path('app/views/' . $view . '.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

test('the nav strip renders for a signed-in screen and not for an anonymous one', function (): void {
    $f    = ff_fixture();
    $year = ['id' => $f['year'], 'label' => '2027', 'is_open' => true];

    $signedIn = ff_render('dropped', 'Dropped Members', [
        'user'    => ff_user('coord'),
        'year'    => $year,
        'dropped' => DroppedPage::fromApp($GLOBALS['rerm_app'])
            ->page(ff_user('coord'), $f['year'], []),
    ]);

    assertSame(1, substr_count($signedIn, 'class="topbar'), 'the bar is there, once');
    assertSame(1, substr_count($signedIn, 'class="brand"'), 'and the name is not repeated');
    assertTrue(str_contains($signedIn, '&larr; Menu</a>'), 'with the way back');

    // The login screen passes no user and gets no bar.
    $anonymous = ff_render('login', 'Sign in', [
        'notices' => [], 'memberNumber' => '',
    ]);
    assertSame(0, substr_count($anonymous, 'class="topbar'), 'no bar without a user');
    assertSame(1, substr_count($anonymous, 'class="brand"'), 'the name still shows');
});

test('the dropped screen renders, escaped, inside the byte budget', function (): void {
    $f    = ff_fixture();
    $html = ff_render('dropped', 'Dropped Members', [
        'user'    => ff_user('coord'),
        'year'    => ['id' => $f['year'], 'label' => '2027', 'is_open' => true],
        'dropped' => DroppedPage::fromApp($GLOBALS['rerm_app'])
            ->page(ff_user('coord'), $f['year'], []),
    ]);

    assertTrue(str_contains($html, 'Dropped Members'));
    assertTrue(str_contains($html, 'Dora Gone'), 'the dropped member is on it');
    assertTrue(str_contains($html, 'tel:+15555550100'), 'and can be rung');
    assertTrue(
        strlen($html) < 100000,
        'the page is ' . number_format(strlen($html)) . ' bytes, against a 100KB budget'
    );
});

test('the team-scope picker is offered to Officers and Senior Officers alike', function (): void {
    $f    = ff_fixture();
    $page = DesignatePage::fromApp($GLOBALS['rerm_app']);

    // The Coordinator is a Senior Officer.
    $senior = $page->page(ff_user('exec'), ['q' => 'FF000002', 'member' => (string) $f['ids']['coord']]);
    $html   = ff_render('designate', 'Designate Users', [
        'user' => ff_user('exec'), 'notices' => [], 'designate' => $senior,
    ]);
    assertTrue(str_contains($html, 'name="team_scope[]"'), 'the picker is there');
    assertTrue(str_contains($html, 'Teams they cover'), 'under a level-neutral legend');
    assertTrue(
        str_contains($html, 'name="scope_division_id"'),
        'and a Senior Officer is offered the division override'
    );

    // The Captain is an Officer, and since Phase 8.6 gets the picker too —
    // their own team plus any they help with.
    $officer = $page->page(ff_user('exec'), ['q' => 'FF000004', 'member' => (string) $f['ids']['cap']]);
    $html    = ff_render('designate', 'Designate Users', [
        'user' => ff_user('exec'), 'notices' => [], 'designate' => $officer,
    ]);
    assertTrue(str_contains($html, 'name="team_scope[]"'), 'the picker is there for an Officer');

    // But NOT the division override: nothing below Senior Officer reads it,
    // so offering it would be a control that saves and changes nothing.
    assertTrue(
        !str_contains($html, 'name="scope_division_id"'),
        'and the division override is withheld from an Officer'
    );

    // The reset control is offered for both, because both have accounts.
    assertTrue(str_contains($html, 'value="reset_password"'), 'the reset is offered');
});

test('the fixture cleans up after itself', function (): void {
    $pdo = ff_pdo();
    ff_fixture();
    ff_teardown($pdo);

    foreach ([
        "SELECT COUNT(*) FROM member WHERE member_number LIKE 'FF%'",
        "SELECT COUNT(*) FROM team WHERE name LIKE 'FF %'",
        "SELECT COUNT(*) FROM division WHERE name LIKE 'FF %'",
        "SELECT COUNT(*) FROM import_batch WHERE filename LIKE 'FF-%'",
    ] as $sql) {
        assertSame(0, (int) $pdo->query($sql)->fetchColumn(), $sql);
    }
});
