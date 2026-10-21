<?php

declare(strict_types=1);

/**
 * Phase 14.1 — Sign In As (spec-v2 §16): an Admin runs their session as
 * another account, sees exactly what that account sees, and comes back.
 *
 * Two halves. The first reads the source — the matrix row, the two routes,
 * the tile, the bar, the one form on the screen — and needs no database.
 * The second drives Rerm\Auth\Auth through a session array the way the
 * front controller does, against a fixture of four accounts, and asserts
 * every refusal, the re-check on every request, the audit pair and the
 * marker on every row written in between.
 *
 * The fixture is generated and the expectations are TRANSCRIBED beside it.
 * Member numbers are 'SIA00001' and so on; nothing here is a real person.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\Admin\SignInAsPage;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Auth;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Scope;
use Rerm\Auth\User;
use Rerm\Routes;
use Rerm\Session;

function sia_app(): App
{
    return $GLOBALS['rerm_app'];
}

function sia_source(string $relative): string
{
    $source = (string) file_get_contents(__DIR__ . '/../' . $relative);
    assertTrue($source !== '', $relative . ' is readable');

    return $source;
}

function sia_user(Level $level, int $id = 1, string $name = 'Test User'): User
{
    return new User(
        id: $id,
        memberId: 100 + $id,
        memberNumber: '100000' . $id,
        level: $level,
        scopeDivisionId: 1,
        scopeTeamId: 1,
        mustChangePassword: false,
        displayName: $name,
    );
}

/** Renders a view inside the shell with the data render() would pass. */
function sia_render(string $view, string $title, array $data): string
{
    $app = sia_app();
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
// What needs no database: the row, the routes, the tile, the bar
// ---------------------------------------------------------------------------

test('sign_in_as is Admin / Everywhere, its own row — transcribed from spec-v2 §16.2', function (): void {
    assertSame(Level::Admin, Capability::SignInAs->minimumLevel());
    assertSame(Scope::Everywhere, Capability::SignInAs->scope());
    assertSame('sign_in_as', Capability::SignInAs->value);
});

test('the screen is guarded by the capability; the way back is only signed-in, because it is pressed as somebody else', function (): void {
    assertSame(Capability::SignInAs->value, Routes::guard('sign-in-as'));
    assertSame(Routes::SIGNED_IN, Routes::guard('sign-out-as'));

    // And the way back is POST-only with a token, like logout: a GET that
    // changed who a session runs as is a GET an <img src> can send.
    $front = sia_source('public/index.php');
    $arm   = strpos($front, "case 'sign-out-as':");
    assertTrue($arm !== false, 'the arm exists');
    $body = substr($front, (int) $arm, 600);
    assertTrue(str_contains($body, "\$_SERVER['REQUEST_METHOD'] === 'POST' && Rerm\\Csrf::check()"), 'POST and a token');
    assertTrue(str_contains($body, "redirect(\$app, 'menu');"), 'a GET lands on the menu');
});

test('the tile is under Administer, and both audit verbs have a label', function (): void {
    $menu = sia_source('app/src/Menu.php');
    assertSame(1, preg_match("/'cap' => Capability::SignInAs,.*'route' => 'sign-in-as',.*'group' => 'administer'/", $menu), 'one tile, Administer');

    assertSame('Signed in as another account', Action::SignInAs->label());
    assertSame('Returned to own account', Action::SignOutAs->label());
    assertSame('sign_in_as', Action::SignInAs->value);
    assertSame('sign_out_as', Action::SignOutAs->value);
});

test('the front controller runs as the account, names the Admin beside it, and does not pin them to the account\'s password', function (): void {
    $front = sia_source('public/index.php');

    // $actor travels with $user on every render, and the audit marker is
    // set once per request from Auth, before any handler runs.
    assertTrue(str_contains($front, 'global $user, $actor;'), 'render() passes the actor');
    assertTrue(str_contains($front, "\$data += ['user' => \$user, 'actor' => \$actor];"));
    assertTrue(str_contains($front, 'Rerm\Audit\AuditLog::setActingAdmin($actor?->id);'), 'the marker is set from the dispatch head');

    // The forced first change is the ACCOUNT's, not the Admin's.
    assertTrue(
        str_contains($front, "if (\$user->mustChangePassword && \$actor === null && \$path !== 'password' && \$path !== 'logout') {"),
        'the pin is skipped while acting'
    );

    // And /password is refused outright while acting, with a sentence.
    $arm  = strpos($front, "case 'password':");
    $body = substr($front, (int) $arm, 700);
    assertTrue(str_contains($body, 'if ($actor !== null) {'), '/password reads the actor');
    assertTrue(str_contains($body, 'Not while signed in as'), 'and says why');
    assertTrue(strpos($body, 'if ($actor !== null) {') < strpos($body, 'password_act('), 'before the change is attempted');
});

test('the bar says Signed in as and offers the way back only while acting, on every screen', function (): void {
    $officer = sia_user(Level::Officer, 2, 'Olive Officer');
    $admin   = sia_user(Level::Admin, 1, 'Ada Admin');

    $plain = sia_render('menu', 'Menu', ['user' => $officer]);
    assertTrue(!str_contains($plain, 'Signed in as</span>'), 'no word when nobody is acting');
    assertTrue(!str_contains($plain, 'sign-out-as'), 'no way back when nobody is acting');
    assertTrue(str_contains($plain, 'Olive Officer &middot; Officer'), 'the who line as before');
    assertSame(1, substr_count($plain, 'class="topbar"'), 'the bar carries no acting class');

    $acting = sia_render('menu', 'Menu', ['user' => $officer, 'actor' => $admin]);
    assertTrue(str_contains($acting, '<span class="as">Signed in as</span>'), 'the word, in the bar');
    assertTrue(str_contains($acting, 'Olive Officer &middot; Officer'), 'the ACCOUNT\'s name and level, not the Admin\'s');
    assertTrue(str_contains($acting, 'action="/rerm/sign-out-as"'), 'the way back posts to sign-out-as');
    assertTrue(str_contains($acting, '>Back to Ada Admin (Admin)</button>'), 'and names the Admin');
    assertTrue(str_contains($acting, 'class="topbar wide acting"') || str_contains($acting, 'class="topbar acting"'), 'the bar itself says so');
    assertTrue(str_contains($acting, '>Sign out</button>'), 'Sign out is still offered — it ends everything');

    // The way back carries a token: it is a POST that changes who the
    // session runs as.
    $form = substr($acting, (int) strpos($acting, 'class="back-to"'), 400);
    assertTrue(str_contains($form, 'name="' . Rerm\Csrf::FIELD . '"'), 'a CSRF field in the form');

    // The nav strip is drawn for an acting Admin even when the account has
    // never set its password — that account is exactly the one worth
    // walking a flow with, and the pin does not apply to the Admin.
    $fresh = new User(2, 102, '1000002', Level::Officer, 1, 1, true, 'Olive Officer');
    $pinned = sia_render('menu', 'Menu', ['user' => $fresh]);
    assertTrue(!str_contains($pinned, 'aria-label="Screens"'), 'no nav while a change is forced on the person themselves');
    $walked = sia_render('menu', 'Menu', ['user' => $fresh, 'actor' => $admin]);
    assertTrue(str_contains($walked, 'aria-label="Screens"'), 'the nav, while an Admin is in their shoes');
});

test('the screen is one form: one token, a button per account, and no per-row form', function (): void {
    $view = sia_source('app/views/sign-in-as.php');
    assertSame(1, substr_count($view, '<form method="post"'), 'one POST form');
    assertSame(1, substr_count($view, 'Csrf::field()'), 'one token');
    assertTrue(str_contains($view, 'name="user_id" value="<?= e((string) $row[\'id\']) ?>"'), 'the button carries the account');
    assertTrue(str_contains($view, 'Admin accounts are not offered'), 'the screen says what it will not do');
});

// ---------------------------------------------------------------------------
// The database under test
// ---------------------------------------------------------------------------

function sia_pdo(): PDO
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
        $pdo = sia_app()->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    $ready = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rcf_approver'"
    )->fetchColumn();
    if ($ready === 0) {
        $failure = 'database is not migrated through 013 — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/**
 * Four accounts on one team: an Admin (granted), a second Admin, an Officer
 * by title, a Senior Officer by title, and a deactivated Officer. Plus a
 * flow: the Senior Officer is approved by the second Admin, who is the top.
 *
 * @return array{division: int, team: int, members: array<string, int>, users: array<string, int>}
 */
function sia_fixture(): array
{
    static $fixture = null;
    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = sia_pdo();

    $division = (int) $pdo->query("SELECT id FROM division WHERE name = '(No Division)'")->fetchColumn();
    $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)')
        ->execute([':name' => 'SIA Bus Ops Team Z', ':division' => $division]);
    $team = (int) $pdo->lastInsertId();

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, full_name,'
        . ' division_id, team_id, title, title_level)'
        . " VALUES (:number, :first, :last, '', :full, :division, :team, :title, :level)"
    );
    $specs = [
        'adm'  => ['Ada',   'Admin',     'Committee Member', 'member'],
        'adm2' => ['Abe',   'Second',    'Committee Member', 'member'],
        'off'  => ['Olive', 'Officer',   'Captain',          'officer'],
        'sen'  => ['Sam',   'Senior',    'Vice Chairman',    'senior_officer'],
        'gone' => ['Gus',   'Gone',      'Captain',          'officer'],
    ];
    $members = [];
    $n       = 0;
    foreach ($specs as $key => [$first, $last, $title, $level]) {
        $n++;
        $insertMember->execute([
            ':number' => sprintf('SIA%05d', $n), ':first' => $first, ':last' => $last,
            ':full' => $first . ' ' . $last, ':division' => $division, ':team' => $team,
            ':title' => $title, ':level' => $level,
        ]);
        $members[$key] = (int) $pdo->lastInsertId();
    }

    $insertUser = $pdo->prepare(
        'INSERT INTO app_user (member_id, level, granted_level, password_hash, must_change_password, is_active)'
        . " VALUES (:m, :l, :g, '*', :must, :active)"
    );
    $users = [];
    foreach ([
        'adm'  => ['member', 'admin', 0, 1],
        'adm2' => ['member', 'admin', 0, 1],
        'off'  => ['officer', null, 1, 1],
        'sen'  => ['senior_officer', null, 0, 1],
        'gone' => ['officer', null, 0, 0],
    ] as $key => [$level, $granted, $must, $active]) {
        $insertUser->execute([':m' => $members[$key], ':l' => $level, ':g' => $granted, ':must' => $must, ':active' => $active]);
        $users[$key] = (int) $pdo->lastInsertId();
    }

    $flow = $pdo->prepare('INSERT INTO rcf_approver (app_user_id, approver_user_id, is_top, set_by) VALUES (:u, :a, :top, :by)');
    $flow->execute([':u' => $users['sen'], ':a' => $users['adm2'], ':top' => 0, ':by' => $users['adm']]);
    $flow->execute([':u' => $users['adm2'], ':a' => null, ':top' => 1, ':by' => $users['adm']]);

    return $fixture = ['division' => $division, 'team' => $team, 'members' => $members, 'users' => $users];
}

/** A fresh Auth, the way every request builds one, over the same $_SESSION. */
function sia_auth(): Auth
{
    return Auth::fromApp(sia_app());
}

/** Signs the session in as a fixture account, as /login would. */
function sia_sign_in(string $key): Auth
{
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    $auth = sia_auth();
    $auth->signIn(sia_fixture()['users'][$key], false);

    return $auth;
}

/** The audit rows for an action naming an app_user, newest first. */
function sia_audit(Action $action, int $entityId): array
{
    $read = sia_pdo()->prepare(
        'SELECT actor_user_id, after_json FROM audit_log WHERE action = :a AND entity = :e AND entity_id = :id ORDER BY id DESC'
    );
    $read->execute([':a' => $action->value, ':e' => 'app_user', ':id' => (string) $entityId]);

    return $read->fetchAll();
}

test('an Admin signs in as an Officer: the request runs as the Officer, with the Officer\'s scope, and the Admin is the account holder', function (): void {
    $f    = sia_fixture();
    $auth = sia_sign_in('adm');
    assertSame(Level::Admin, $auth->currentUser()?->level);
    assertTrue(!$auth->acting());

    assertSame(null, $auth->signInAs($f['users']['off']));

    // The same Auth answers at once, and a fresh one — the next request —
    // answers the same from the session alone.
    foreach ([$auth, sia_auth()] as $a) {
        $user = $a->currentUser();
        assertSame($f['users']['off'], $user?->id, 'runs as the Officer');
        assertSame(Level::Officer, $user?->level);
        assertSame($f['team'], $user?->scopeTeamId, 'with the Officer\'s own team as scope');
        assertSame('Olive Officer', $user?->displayName);
        assertTrue($user?->mustChangePassword === true, 'the account\'s own flags, untouched');
        assertTrue($a->acting());
        assertSame($f['users']['adm'], $a->accountHolder()?->id, 'the Admin is the account holder');
    }

    // Audited as the Admin, naming the account.
    $rows = sia_audit(Action::SignInAs, $f['users']['off']);
    assertTrue(count($rows) >= 1, 'a sign_in_as row');
    assertSame($f['users']['adm'], (int) $rows[0]['actor_user_id']);
    $after = json_decode((string) $rows[0]['after_json'], true);
    assertSame('SIA00003', $after['member_number']);
    assertSame('officer', $after['level']);
    assertTrue(!array_key_exists(AuditLog::ACTING_KEY, $after), 'the Admin\'s own row carries no marker');

    // And from this point in the request every row is marked, because the
    // request now runs as the account.
    assertSame($f['users']['adm'], AuditLog::actingAdmin());
    AuditLog::setActingAdmin(null);
});

test('every refusal is a sentence, and none of them writes the key', function (): void {
    $f    = sia_fixture();
    $auth = sia_sign_in('adm');

    $cases = [
        'another Admin'  => $f['users']['adm2'],
        'your own'       => $f['users']['adm'],
        'deactivated'    => $f['users']['gone'],
        'does not exist' => 99999999,
    ];
    foreach ($cases as $word => $id) {
        $refusal = $auth->signInAs($id);
        assertTrue(is_string($refusal) && str_contains($refusal, $word), "refused with '{$word}': " . var_export($refusal, true));
        assertSame(null, Session::get(Auth::ACTING_KEY), 'nothing written');
        assertTrue(!sia_auth()->acting());
    }

    // And not by anybody below Admin, whatever the session says.
    $auth = sia_sign_in('sen');
    $refusal = $auth->signInAs($f['users']['off']);
    assertTrue(is_string($refusal) && str_contains($refusal, 'Only an Admin'));
    assertSame(null, Session::get(Auth::ACTING_KEY));
});

test('the key is re-checked on every request: a non-Admin holder, an Admin target or a deactivated target drops it', function (): void {
    $f = sia_fixture();

    // A planted key under an Officer's session is ignored and forgotten.
    sia_sign_in('off');
    Session::set(Auth::ACTING_KEY, $f['users']['sen']);
    $auth = sia_auth();
    assertSame($f['users']['off'], $auth->currentUser()?->id, 'the Officer is themselves');
    assertTrue(!$auth->acting());
    assertSame(null, Session::get(Auth::ACTING_KEY), 'the key is gone');

    // Under an Admin: a key naming another Admin, or a deactivated account.
    foreach (['adm2', 'gone'] as $target) {
        sia_sign_in('adm');
        Session::set(Auth::ACTING_KEY, $f['users'][$target]);
        $auth = sia_auth();
        assertSame($f['users']['adm'], $auth->currentUser()?->id, "not acting as {$target}");
        assertTrue(!$auth->acting());
        assertSame(null, Session::get(Auth::ACTING_KEY));
    }

    // And a real acting-as ends the moment the holder stops being an Admin.
    $auth = sia_sign_in('adm');
    assertSame(null, $auth->signInAs($f['users']['off']));
    assertTrue(sia_auth()->acting());
    sia_pdo()->prepare("UPDATE app_user SET granted_level = 'executive_officer' WHERE id = :id")->execute([':id' => $f['users']['adm']]);
    try {
        $auth = sia_auth();
        assertSame($f['users']['adm'], $auth->currentUser()?->id, 'back to themselves');
        assertSame(Level::ExecutiveOfficer, $auth->currentUser()?->level);
        assertTrue(!$auth->acting());
        assertSame(null, Session::get(Auth::ACTING_KEY), 'and the key is forgotten, not merely ignored');
    } finally {
        sia_pdo()->prepare("UPDATE app_user SET granted_level = 'admin' WHERE id = :id")->execute([':id' => $f['users']['adm']]);
        AuditLog::setActingAdmin(null);
    }
});

test('every audit row written while acting carries the Admin\'s id beside the account\'s', function (): void {
    $f   = sia_fixture();
    $pdo = sia_pdo();
    $log = new AuditLog($pdo);
    $off = sia_user(Level::Officer, $f['users']['off']);

    try {
        AuditLog::setActingAdmin($f['users']['adm']);
        assertSame($f['users']['adm'], AuditLog::actingAdmin());

        // With a payload, the marker joins it; the actor column stays the
        // account's, because that is who the application ran as.
        $log->record($off, Action::SetTeamArea, 'team', 'SIA-T1', ['area' => 'x'], ['area' => 'y']);
        // With no payload at all, the marker IS the payload.
        $log->record($off, Action::SetTeamArea, 'team', 'SIA-T2');
    } finally {
        AuditLog::setActingAdmin(null);
    }
    // Cleared, nothing is marked.
    $log->record($off, Action::SetTeamArea, 'team', 'SIA-T3', null, ['area' => 'z']);

    $read = $pdo->prepare("SELECT entity_id, actor_user_id, before_json, after_json FROM audit_log WHERE entity = 'team' AND entity_id LIKE 'SIA-T%' ORDER BY entity_id");
    $read->execute();
    $rows = $read->fetchAll();
    assertSame(3, count($rows));

    assertSame($f['users']['off'], (int) $rows[0]['actor_user_id'], 'the actor is the account');
    assertSame(['area' => 'y', AuditLog::ACTING_KEY => $f['users']['adm']], json_decode((string) $rows[0]['after_json'], true));
    assertSame(['area' => 'x'], json_decode((string) $rows[0]['before_json'], true), 'before is untouched');
    assertSame([AuditLog::ACTING_KEY => $f['users']['adm']], json_decode((string) $rows[1]['after_json'], true));
    assertSame(['area' => 'z'], json_decode((string) $rows[2]['after_json'], true), 'no marker once cleared');
    assertSame(null, AuditLog::actingAdmin());
});

test('the way back restores the Admin and is audited; Sign out ends both', function (): void {
    $f    = sia_fixture();
    $auth = sia_sign_in('adm');
    assertSame(null, $auth->signInAs($f['users']['sen']));

    $auth = sia_auth();
    assertSame($f['users']['sen'], $auth->currentUser()?->id);
    $auth->signOutAs();
    assertTrue(!$auth->acting());
    assertSame($f['users']['adm'], $auth->currentUser()?->id, 'the Admin again, at once');
    assertSame($f['users']['adm'], sia_auth()->currentUser()?->id, 'and on the next request');
    assertSame(null, Session::get(Auth::ACTING_KEY));

    $rows = sia_audit(Action::SignOutAs, $f['users']['sen']);
    assertTrue(count($rows) >= 1, 'a sign_out_as row');
    assertSame($f['users']['adm'], (int) $rows[0]['actor_user_id'], 'written as the Admin');
    assertSame(['member_number' => 'SIA00004'], json_decode((string) $rows[0]['after_json'], true), 'the account, and no marker');
    assertSame(null, AuditLog::actingAdmin(), 'the marker is cleared with the key');

    // Pressed again, nothing happens and nothing is written.
    $before = count(sia_audit(Action::SignOutAs, $f['users']['sen']));
    sia_auth()->signOutAs();
    assertSame($before, count(sia_audit(Action::SignOutAs, $f['users']['sen'])));

    // Switching straight from one account to another closes the first on
    // the record, so every sign_in_as has its sign_out_as.
    $auth = sia_sign_in('adm');
    assertSame(null, $auth->signInAs($f['users']['off']));
    $outBefore = count(sia_audit(Action::SignOutAs, $f['users']['off']));
    assertSame(null, $auth->signInAs($f['users']['sen']));
    assertSame($outBefore + 1, count(sia_audit(Action::SignOutAs, $f['users']['off'])), 'the Officer was signed out of first');
    assertSame($f['users']['sen'], sia_auth()->currentUser()?->id);
    AuditLog::setActingAdmin(null);

    // Sign out revokes the real login and forgets the acting key with it.
    $auth = sia_auth();
    $auth->signOut();
    assertSame(null, Session::get(Auth::ACTING_KEY));
    assertSame(null, Session::get(Auth::SESSION_KEY));
    assertSame(null, sia_auth()->currentUser());
});

test('the screen lists active, non-Admin accounts other than the caller, with where each one\'s entries go', function (): void {
    $f     = sia_fixture();
    $admin = sia_user(Level::Admin, $f['users']['adm']);
    $page  = SignInAsPage::fromApp(sia_app())->page($admin, ['q' => 'SIA']);

    // 'SIA' is three characters: the search floor, and it matches the five
    // fixture member numbers — of which two are Admins (one the caller)
    // and one is deactivated.
    assertTrue($page['search_applied']);
    assertSame(2, $page['total']);
    $byId = [];
    foreach ($page['rows'] as $row) {
        $byId[$row['id']] = $row;
    }
    assertSame([$f['users']['sen'], $f['users']['off']], array_keys($byId), 'broadest level first, then by name');

    $sen = $byId[$f['users']['sen']];
    assertSame('Sam Senior', $sen['name']);
    assertSame(Level::SeniorOfficer, $sen['level']);
    assertSame('title', $sen['source']);
    assertSame('SIA Bus Ops Team Z', $sen['team_name']);
    assertSame('', $sen['division_name'], '(No Division) is blank, as everywhere');
    assertSame(['state' => 'approver', 'approver' => 'Abe Second'], $sen['flow'], 'approved by the second Admin, by name');

    $off = $byId[$f['users']['off']];
    assertSame('below', $off['flow']['state'], 'an Officer is below the flow\'s floor');
    assertTrue($off['must_change']);

    // A Senior Officer with nothing drawn reads "not set"; the top reads so.
    sia_pdo()->prepare('DELETE FROM rcf_approver WHERE app_user_id = :u')->execute([':u' => $f['users']['sen']]);
    try {
        $page = SignInAsPage::fromApp(sia_app())->page($admin, ['q' => 'SIA']);
        foreach ($page['rows'] as $row) {
            if ($row['id'] === $f['users']['sen']) {
                assertSame('unset', $row['flow']['state']);
            }
        }
    } finally {
        sia_pdo()->prepare('INSERT INTO rcf_approver (app_user_id, approver_user_id, is_top, set_by) VALUES (:u, :a, 0, :by)')
            ->execute([':u' => $f['users']['sen'], ':a' => $f['users']['adm2'], ':by' => $f['users']['adm']]);
    }
    // The second Admin is the top — and is not listed, being an Admin.
    $second = sia_user(Level::Admin, $f['users']['adm2']);
    $page   = SignInAsPage::fromApp(sia_app())->page($second, ['q' => 'SIA']);
    assertSame(2, $page['total'], 'the first Admin is not offered to the second either');

    // Too short a search shows everybody and says so.
    $page = SignInAsPage::fromApp(sia_app())->page($admin, ['q' => 'SI']);
    assertTrue($page['search_too_short']);
    assertTrue(!$page['search_applied']);
    assertTrue($page['total'] >= 2);

    // Rendered: a button per account naming them, escaped, one form.
    $html = sia_render('sign-in-as', 'Sign In As', [
        'user'     => $admin,
        'wide'     => true,
        'signInAs' => SignInAsPage::fromApp(sia_app())->page($admin, ['q' => 'SIA']),
    ]);
    assertSame(1, substr_count($html, '<form method="post" action="/rerm/sign-in-as">'));
    assertTrue(str_contains($html, 'value="' . $f['users']['sen'] . '">'));
    assertTrue(str_contains($html, 'Sign in as Sam Senior'));
    assertTrue(str_contains($html, 'Sign in as Olive Officer'));
    assertSame(1, preg_match('/data-label="RCF requests go to">\s*Abe Second\s*<\/td>/', $html), 'the approver\'s name in the flow cell');
    assertTrue(!str_contains($html, 'Sign in as Ada Admin'), 'never the caller');
    assertTrue(!str_contains($html, 'Sign in as Gus Gone'), 'never a deactivated account');
});

test('sign-in-as fixtures are cleaned up', function (): void {
    $f   = sia_fixture();
    $pdo = sia_pdo();

    $ids = implode(', ', array_map('intval', $f['users']));
    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$ids}) OR (entity = 'app_user' AND entity_id IN ('" . implode("', '", array_map('strval', $f['users'])) . "'))");
    $pdo->exec("DELETE FROM audit_log WHERE entity = 'team' AND entity_id LIKE 'SIA-T%'");
    $pdo->exec("DELETE FROM auth_token WHERE user_id IN ({$ids})");
    $pdo->exec("DELETE FROM rcf_approver WHERE app_user_id IN ({$ids}) OR set_by IN ({$ids})");
    $pdo->exec("DELETE FROM app_user WHERE id IN ({$ids})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'SIA%'");
    $pdo->exec("DELETE FROM team WHERE name = 'SIA Bus Ops Team Z'");
    $_SESSION = [];

    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM member WHERE member_number LIKE 'SIA%'")->fetchColumn());
});
