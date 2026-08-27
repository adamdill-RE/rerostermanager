<?php

declare(strict_types=1);

/**
 * The session cookie and the CSRF token.
 *
 * Every assertion here exists because the host's default is WRONG and the
 * failure is silent. `docs/hosting.md` measured all four:
 *
 *   session.cookie_httponly   0    a script can read the cookie
 *   session.cookie_secure     0    it travels over plain http
 *   session.use_strict_mode   0    a session id the server never issued is accepted
 *   session.cookie_path       /    RESM, at /resm/ on this same domain, receives it
 *
 * The last one is the one to fear. reshiftmanager.com serves two applications
 * from one document root, and a cookie scoped to `/` does not fail — it works
 * perfectly, in both applications, until somebody notices that signing into
 * one has done something to the other.
 *
 * `docker/php/php.ini` deliberately reproduces those unsafe defaults locally,
 * so this is not a test of PHP: it is a test that Rerm\Session states all of
 * them itself rather than inheriting any.
 *
 * start() cannot run under a CLI test runner, which is exactly why the
 * decisions live in cookieParams(), name(), savePath() and HARDENING and are
 * asserted as values. start() is then the thin wrapper that applies them.
 *
 * Note that nothing below writes the real mount point. It is configuration,
 * read from one place, and a test that hard-coded it would be asserting the
 * value rather than the mechanism — while quietly becoming the second place
 * the subpath is written down.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Config;
use Rerm\Csrf;
use Rerm\Session;

/** An App with the shape Session reads, and nothing else. */
function session_test_app(array $session = [], string $basePath = '/mount/'): App
{
    return App::boot(dirname(__DIR__), Config::fromArray([
        'app' => [
            'base_path'        => $basePath,
            'display_timezone' => 'America/Chicago',
            'debug'            => false,
        ],
        'session' => $session + ['name' => 'RERMSESS', 'secure' => true, 'lifetime' => 0, 'save_path' => null],
        'db'      => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'rerm', 'user' => 'u', 'pass' => 'p', 'socket' => null],
    ]));
}

// ---------------------------------------------------------------------------
// The cookie
// ---------------------------------------------------------------------------

test('the session cookie is scoped to the mount point, never to the domain root', function (): void {
    // THE assertion in this file. RESM lives at /resm/ on this same domain and
    // this host's default cookie_path is `/`, which would hand our cookie to
    // it and its cookie to us. Nothing about that fails loudly.
    $params = Session::cookieParams(session_test_app([], '/mount/'));

    assertSame('/mount/', $params['path']);

    // Derived from app.base_path, not written down here: mounted somewhere
    // else, the cookie follows.
    assertSame('/elsewhere/', Session::cookieParams(session_test_app([], '/elsewhere/'))['path']);

    // And never the root, whatever the configuration says.
    assertTrue($params['path'] !== '/', 'a cookie on / is a cookie RESM receives');
});

test('the cookie is HttpOnly, and that is not configurable', function (): void {
    // The host ships cookie_httponly = 0. No script in this application reads
    // the session cookie, so there is no case for exposing it to one — and a
    // setting nobody can turn off is a setting nobody turns off by accident.
    assertSame(true, Session::cookieParams(session_test_app())['httponly']);
    assertSame(true, Session::cookieParams(session_test_app(['httponly' => false]))['httponly']);
});

test('the cookie is Secure by default, and only local development turns it off', function (): void {
    // The host ships cookie_secure = 0. It is configurable for exactly one
    // reason: local development is plain http and the browser would never
    // send the cookie back. docker-compose sets RERM_SESSION_SECURE=0;
    // production sets nothing and gets true.
    assertSame(true, Session::cookieParams(session_test_app())['secure']);
    assertSame(false, Session::cookieParams(session_test_app(['secure' => false]))['secure']);

    // Anything that is not exactly true is false. A string '0' out of the
    // environment must not read as truthy.
    assertSame(false, Session::cookieParams(session_test_app(['secure' => '0']))['secure']);
    assertSame(false, Session::cookieParams(session_test_app(['secure' => 1]))['secure']);
});

test('the cookie is SameSite=Lax and host-only', function (): void {
    $params = Session::cookieParams(session_test_app());

    // Lax rather than Strict: a password-recovery link mailed to an officer
    // has to arrive signed in. Every state change is a POST that checks a
    // CSRF token of its own, which is what makes Lax sufficient here.
    assertSame('Lax', $params['samesite']);

    // No domain attribute, so the cookie is host-only rather than shared with
    // every subdomain.
    assertSame('', $params['domain']);

    // A browser-session cookie. The 90-day "keep me signed in" cannot be a
    // PHP session — gc_maxlifetime is 1440s here and collection is not ours —
    // so it is a DB-backed rotating token instead (spec 3.4).
    assertSame(0, $params['lifetime']);
});

test('fixation and URL session ids are both refused', function (): void {
    // Both ship OFF on this host. use_strict_mode is what makes a session id
    // the server never issued unusable; use_only_cookies is what stops one
    // travelling in a URL where it reaches the access log and any Referer.
    assertSame('1', Session::HARDENING['session.use_strict_mode']);
    assertSame('1', Session::HARDENING['session.use_only_cookies']);
});

test('the cookie name is ours, and distinct from the application beside us', function (): void {
    assertSame('RERMSESS', Session::name(session_test_app()));
    assertSame('OTHER', Session::name(session_test_app(['name' => 'OTHER'])));

    // Two cookies from two applications on one domain must not be able to
    // collide. RESM's is RESMSESS.
    assertTrue(Session::name(session_test_app()) !== 'RESMSESS');
});

test('session files land in our own directory, outside the document root', function (): void {
    // Not the cPanel-wide directory RESM would otherwise be sharing with us:
    // two applications writing session files into one directory is two
    // applications able to read each other's.
    $path = Session::savePath(session_test_app());

    assertSame(dirname(__DIR__) . '/var/sessions', $path);
    assertSame('/somewhere/else', Session::savePath(session_test_app(['save_path' => '/somewhere/else'])));

    // var/ is 0700 and outside public_html — .cpanel.yml puts it there and
    // .gitignore keeps it out of the repository.
    assertTrue(!str_contains($path, 'public'), 'session files must not be web-reachable');
});

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

test('a token is 256 bits of hex and is stable within a session', function (): void {
    $_SESSION = [];

    $token = Csrf::token();
    assertSame(64, strlen($token), '32 random bytes, hex encoded');
    assertTrue(ctype_xdigit($token), 'hex only');

    // One token per session, not one per form: it survives the back button and
    // a second tab, and the failure mode of a rotating token is an Admin who
    // has learned that the apply button sometimes needs pressing twice.
    assertSame($token, Csrf::token());
    assertSame($token, Csrf::token());
});

test('two sessions do not share a token', function (): void {
    $_SESSION = [];
    $first = Csrf::token();

    $_SESSION = [];
    $second = Csrf::token();

    assertTrue($first !== $second, 'a token predictable across sessions is not a token');
});

test('check() accepts the session token and refuses everything else', function (): void {
    $_SESSION = [];
    $token = Csrf::token();

    assertSame(true, Csrf::check($token));

    assertSame(false, Csrf::check(''), 'an empty token is not a match');
    assertSame(false, Csrf::check('deadbeef'), 'a short guess');
    assertSame(false, Csrf::check(str_repeat('a', 64)), 'a well-formed guess');
    assertSame(false, Csrf::check(substr($token, 0, 63)), 'a truncated token');
    assertSame(false, Csrf::check($token . 'a'), 'a lengthened token');
    // Case matters: hash_equals is a byte comparison, not a hex comparison.
    assertSame(false, Csrf::check(strtoupper($token)));
});

test('check() refuses when the session holds no token at all', function (): void {
    // The session-not-started case, and the direction it has to fail in. If a
    // route ever renders a form without starting a session, every POST to it
    // is refused — which is an Admin pressing a button twice, rather than an
    // application accepting a forged one.
    $_SESSION = [];

    assertSame(false, Csrf::check('anything'));
    assertSame(false, Csrf::check(str_repeat('0', 64)));
});

test('check() reads the POST body when given nothing', function (): void {
    $_SESSION = [];
    $token = Csrf::token();

    $_POST = [Csrf::FIELD => $token];
    assertSame(true, Csrf::check());

    $_POST = [Csrf::FIELD => 'wrong'];
    assertSame(false, Csrf::check());

    // A missing field, and a field that is not a string — an array named
    // csrf[] would otherwise reach hash_equals and raise.
    $_POST = [];
    assertSame(false, Csrf::check());

    $_POST = [Csrf::FIELD => ['array']];
    assertSame(false, Csrf::check());

    $_POST = [];
});

test('the hidden field is escaped HTML carrying the token', function (): void {
    $_SESSION = [];
    $token = Csrf::token();
    $field = Csrf::field();

    assertTrue(str_contains($field, 'type="hidden"'));
    assertTrue(str_contains($field, 'name="' . Csrf::FIELD . '"'));
    assertTrue(str_contains($field, 'value="' . $token . '"'));

    // The token is hex, so nothing here can need escaping — which is the point
    // of asserting it: if the token generator ever changes, e() is already in
    // the path rather than being remembered later.
    $_SESSION['csrf_token'] = 'a"><script>alert(1)</script>';
    assertTrue(!str_contains(Csrf::field(), '<script>'), 'every rendered value goes through e()');

    $_SESSION = [];
});

test('a token that is not 64 hex characters is replaced rather than trusted', function (): void {
    // A session carrying a short, empty or non-string token — a truncated
    // write, a session file from an older format — must produce a new one
    // rather than a check() that compares against rubbish.
    foreach (['', 'short', 12345, null, ['array'], str_repeat('z', 64), str_repeat(' ', 64)] as $rubbish) {
        $_SESSION = ['csrf_token' => $rubbish];
        $token = Csrf::token();

        assertSame(64, strlen($token), 'rubbish in the session must be replaced');
        assertTrue(ctype_xdigit($token), 'and replaced with a real one');
        assertSame(true, Csrf::check($token));
        assertSame(false, Csrf::check(is_string($rubbish) ? $rubbish : 'x'), 'the rubbish must not still verify');
    }

    $_SESSION = [];
});
