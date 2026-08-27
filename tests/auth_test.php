<?php

declare(strict_types=1);

/**
 * Identity and sessions (spec 3): the password rules, both rate-limit limbs,
 * the rotating token store with its compare-and-swap, single-use resets, and
 * the route guard table.
 *
 * The database tests write only rows they mark — member number AUTHTEST1 and
 * addresses in 203.0.113.0/24 (TEST-NET-3, reserved for documentation) — and
 * the last test deletes everything it created, in RESTRICT-safe order.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Capability;
use Rerm\Auth\LoginThrottle;
use Rerm\Auth\Password;
use Rerm\Auth\PasswordReset;
use Rerm\Auth\TokenStore;
use Rerm\Routes;

// ---------------------------------------------------------------------------
// Password rules (spec 3.2) — pure, at bcrypt cost 4 so the suite stays fast
// ---------------------------------------------------------------------------

test('a password shorter than the minimum is refused with the reason', function (): void {
    $passwords = new Password(PASSWORD_BCRYPT, 4, 8, '1234');

    assertTrue($passwords->problemWith('seven77') !== null, '7 characters is short');
    assertTrue(str_contains((string) $passwords->problemWith(''), 'at least 8'));
    assertSame(null, $passwords->problemWith('eight888'), '8 characters is enough');
});

test('the shipped initial password is never acceptable, even when long enough', function (): void {
    // With an 8-minimum, "1234" already fails on length; this pins the rule
    // itself by shipping a longer initial value into the checker.
    $passwords = new Password(PASSWORD_BCRYPT, 4, 8, 'initial-password');

    $problem = $passwords->problemWith('initial-password');
    assertTrue($problem !== null && str_contains($problem, 'initial password'));

    // And at minimum 4, the literal 1234 is caught by the forbidden rule.
    $short = new Password(PASSWORD_BCRYPT, 4, 4, '1234');
    assertTrue($short->problemWith('1234') !== null, '"changed" to itself is not changed');
});

test('hash, verify and needsRehash round-trip at the configured cost', function (): void {
    $passwords = new Password(PASSWORD_BCRYPT, 4, 8, '1234');

    $hash = $passwords->hash('a fine passphrase');
    assertSame(true, $passwords->verify('a fine passphrase', $hash));
    assertSame(false, $passwords->verify('a different one', $hash));

    assertSame(false, $passwords->needsRehash($hash), 'same cost, no rehash');
    assertSame(true, (new Password(PASSWORD_BCRYPT, 5, 8, '1234'))->needsRehash($hash),
        'a raised cost re-hashes on the next successful login');
});

test('the locked sentinel "*" verifies for NO input, through the same verify() login uses', function (): void {
    // The seeded master administrator ships with password_hash = '*'
    // (migration 003). The login path must not special-case it — verify()
    // must simply refuse everything, the sentinel itself included.
    $passwords = new Password(PASSWORD_BCRYPT, 4, 8, '1234');

    foreach (['', '*', '1234', 'password', '987654321', str_repeat('*', 60), "\0", 'null'] as $attempt) {
        assertSame(false, $passwords->verify($attempt, '*'), var_export($attempt, true) . " against '*'");
    }
});

// ---------------------------------------------------------------------------
// The route guard table (Rerm\Routes)
// ---------------------------------------------------------------------------

test('every route declares a guard this application knows how to enforce', function (): void {
    $known = [Routes::PUBLIC, Routes::SIGNED_IN, Routes::STATUS_KEY, Routes::SETUP_KEY];

    assertTrue(Routes::GUARDS !== [], 'the table exists and is not empty');

    foreach (Routes::GUARDS as $route => $guard) {
        assertTrue(
            in_array($guard, $known, true) || Capability::tryFrom($guard) !== null,
            "route '{$route}' declares '{$guard}', which is neither a guard mode nor a capability"
        );
    }

    assertSame(null, Routes::guard('no-such-route'), 'an undeclared path has no guard — and 404s');
});

test('the public routes are exactly the ones that exist so a session can', function (): void {
    $public = array_keys(array_filter(Routes::GUARDS, fn (string $g): bool => $g === Routes::PUBLIC));
    sort($public);

    assertSame(['forgot', 'login', 'reset'], $public,
        'nothing that shows member data may be public');
});

test('/import requires Capability::ImportRoster — the setup-key era is over', function (): void {
    assertSame(Capability::ImportRoster->value, Routes::guard('import'));
});

test('every dispatch arm in index.php is a declared route, and every declared route is dispatched', function (): void {
    // The switch in public/index.php and the guard table must be the same
    // list: an arm the table does not name would be unreachable dead code,
    // and — the dangerous direction — a route someone adds to the switch
    // without deciding its guard must fail THIS test rather than ship open.
    $source = (string) file_get_contents(__DIR__ . '/../public/index.php');
    assertTrue($source !== '', 'public/index.php is readable');

    preg_match_all("/^\\s*case '([^']*)':/m", $source, $matches);
    $arms = array_values(array_unique($matches[1]));

    // Not the upload-error match() arms — only labels that are route paths.
    // Route paths never contain an uppercase letter or underscore constant;
    // the regex above only sees string literals, and the one match() in the
    // file switches on integers, so every capture IS a route label.
    $declared = array_keys(Routes::GUARDS);

    sort($arms);
    sort($declared);

    assertSame($declared, $arms, 'Routes::GUARDS and the dispatch switch disagree');
});

// ---------------------------------------------------------------------------
// The database under test
// ---------------------------------------------------------------------------

function auth_pdo(): PDO
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
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_token'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/** One fixture member with one account, created once, deleted by the last test. */
function auth_fixture_user(): int
{
    static $userId = null;

    if ($userId !== null) {
        return $userId;
    }

    $pdo = auth_pdo();

    $division = (int) $pdo->query(
        "SELECT id FROM division WHERE name = '(No Division)'"
    )->fetchColumn();

    $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, division_id, title_level) '
        . "VALUES ('AUTHTEST1', 'Fixture', 'Officer', :division, 'officer')"
    )->execute([':division' => $division]);
    $memberId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO app_user (member_id, level, password_hash, must_change_password, is_active) '
        . "VALUES (:member, 'officer', '*', 1, 1)"
    )->execute([':member' => $memberId]);

    return $userId = (int) $pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// The rate limit, both limbs (spec 3.5)
// ---------------------------------------------------------------------------

/** @param array<int, array{ip: string, member: string, ok: int, ago: int}> $rows */
function auth_attempts(array $rows): void
{
    $insert = auth_pdo()->prepare(
        'INSERT INTO login_attempt (ip, member_number, succeeded, occurred_at) '
        . 'VALUES (:ip, :member, :ok, :at)'
    );

    foreach ($rows as $row) {
        $insert->execute([
            ':ip'     => $row['ip'],
            ':member' => $row['member'],
            ':ok'     => $row['ok'],
            ':at'     => gmdate('Y-m-d H:i:s', time() - $row['ago']),
        ]);
    }
}

test('the IP limb locks after 10 failures in the window, for about 60 seconds', function (): void {
    $throttle = new LoginThrottle(auth_pdo(), 10, 900, 60);

    auth_attempts(array_fill(0, 9, ['ip' => '203.0.113.10', 'member' => '1000001', 'ok' => 0, 'ago' => 30]));
    assertSame(null, $throttle->retryAfter('203.0.113.10', '9999999'), '9 failures is under the ceiling');

    auth_attempts([['ip' => '203.0.113.10', 'member' => '1000001', 'ok' => 0, 'ago' => 10]]);
    $wait = $throttle->retryAfter('203.0.113.10', '9999999');

    assertTrue($wait !== null, 'the tenth failure locks');
    assertTrue($wait >= 1 && $wait <= 60, "runs from the latest failure, got {$wait}s");

    assertSame(null, $throttle->retryAfter('203.0.113.11', '9999999'),
        'a different address is not locked by it');
});

test('the member-number limb locks the account whoever sends the failures', function (): void {
    // The attack the IP limb cannot see: one member number, rotated
    // addresses, a published initial password. Ten failures against the
    // number trips this limb from an address that has never failed once.
    $throttle = new LoginThrottle(auth_pdo(), 10, 900, 60);

    $rows = [];
    for ($i = 0; $i < 10; $i++) {
        $rows[] = ['ip' => "203.0.113.{$i}", 'member' => '1000777', 'ok' => 0, 'ago' => 20];
    }
    auth_attempts($rows);

    assertTrue($throttle->retryAfter('203.0.113.200', '1000777') !== null,
        'a fresh IP against the hammered number is refused');
    assertSame(null, $throttle->retryAfter('203.0.113.200', '1000778'),
        'a different member number is untouched');
});

test('successes never count towards a lockout', function (): void {
    $throttle = new LoginThrottle(auth_pdo(), 10, 900, 60);

    auth_attempts(array_fill(0, 12, ['ip' => '203.0.113.50', 'member' => '1000002', 'ok' => 1, 'ago' => 15]));

    assertSame(null, $throttle->retryAfter('203.0.113.50', '1000002'),
        'an officer signing in three times a day is not an attack');
});

test('the window and the lockout both expire', function (): void {
    $throttle = new LoginThrottle(auth_pdo(), 10, 900, 60);

    // Ten failures, all older than the 15-minute window: no lock.
    auth_attempts(array_fill(0, 10, ['ip' => '203.0.113.60', 'member' => '1000003', 'ok' => 0, 'ago' => 1000]));
    assertSame(null, $throttle->retryAfter('203.0.113.60', '1000003'));

    // Ten failures inside the window whose LATEST is two minutes old: the
    // count stands but the 60-second lockout has run out, so the next
    // attempt gets to try — and to fail, which would start a fresh 60.
    auth_attempts(array_fill(0, 10, ['ip' => '203.0.113.61', 'member' => '1000004', 'ok' => 0, 'ago' => 120]));
    assertSame(null, $throttle->retryAfter('203.0.113.61', '1000004'));
});

test('recordFailure and recordSuccess both land, distinguishably', function (): void {
    $throttle = new LoginThrottle(auth_pdo(), 10, 900, 60);

    $throttle->recordFailure('203.0.113.70', '1000005');
    $throttle->recordSuccess('203.0.113.70', '1000005');

    $read = auth_pdo()->prepare(
        'SELECT succeeded FROM login_attempt WHERE ip = :ip ORDER BY id'
    );
    $read->execute([':ip' => '203.0.113.70']);

    assertSame([0, 1], array_map('intval', $read->fetchAll(PDO::FETCH_COLUMN)),
        'the audit can tell a typo from an attack only if both are recorded');
});

// ---------------------------------------------------------------------------
// The token store (spec 3.4)
// ---------------------------------------------------------------------------

test('a token issues, validates, and reads back by id', function (): void {
    $store = new TokenStore(auth_pdo(), 90, 24);
    $user  = auth_fixture_user();

    $issued = $store->issue($user, false, 'test agent', '203.0.113.90');

    assertTrue(str_contains($issued['cookie'], '.'), 'the cookie is selector.verifier');
    assertSame(32, strlen(explode('.', $issued['cookie'])[0]), 'CHAR(32) selector');

    $row = $store->validateCookie($issued['cookie']);
    assertTrue(is_array($row), 'the freshly issued cookie validates');
    assertSame($user, (int) $row['user_id']);

    assertTrue($store->activeById($issued['id']) !== null, 'and the session id path sees it too');
});

test('a wrong verifier is refused as a mismatch; an unknown selector as unknown', function (): void {
    $store  = new TokenStore(auth_pdo(), 90, 24);
    $issued = $store->issue(auth_fixture_user(), false, '', '');

    [$selector] = explode('.', $issued['cookie']);

    assertSame(TokenStore::REFUSED_MISMATCH, $store->validateCookie($selector . '.' . str_repeat('ab', 32)));
    assertSame(TokenStore::REFUSED_UNKNOWN, $store->validateCookie(str_repeat('ff', 16) . '.' . str_repeat('ab', 32)));
    assertSame(TokenStore::REFUSED_UNKNOWN, $store->validateCookie('no-dot-at-all'));

    // THE RULE THAT MATTERS: the mismatch revoked nothing. The real cookie
    // still works, because a lost rotation race looks exactly like this.
    assertTrue(is_array($store->validateCookie($issued['cookie'])),
        'a wrong verifier must not sign the real session out');
});

test('rotation is a compare-and-swap: one winner, and the loser revokes nothing', function (): void {
    $store  = new TokenStore(auth_pdo(), 90, 24);
    $issued = $store->issue(auth_fixture_user(), true, '', '');

    $row = $store->validateCookie($issued['cookie']);
    assertTrue(is_array($row));

    $fresh = $store->rotate($row);
    assertTrue($fresh !== null, 'the first rotation wins');
    assertTrue($fresh !== $issued['cookie'], 'and moves both halves');

    // The same stale row again — a second tab that read the row before the
    // first tab's UPDATE landed. It must LOSE, quietly.
    assertSame(null, $store->rotate($row), 'the loser gets null, not an exception');

    // And lose only that request: the family survives, on the new cookie.
    assertTrue(is_array($store->validateCookie($fresh)), 'the winner\'s cookie lives');
    assertSame(TokenStore::REFUSED_UNKNOWN, $store->validateCookie($issued['cookie']),
        'the old selector is gone, not lingering');
});

test('revocation is immediate, and revoking-all can spare the current session', function (): void {
    $store = new TokenStore(auth_pdo(), 90, 24);
    $user  = auth_fixture_user();

    $one   = $store->issue($user, false, '', '');
    $two   = $store->issue($user, true, '', '');
    $three = $store->issue($user, true, '', '');

    $store->revoke($one['id']);
    assertSame(TokenStore::REFUSED_DEAD, $store->validateCookie($one['cookie']));
    assertSame(null, $store->activeById($one['id']));

    // Changing a password revokes every OTHER session (spec 3.2): the device
    // at the form keeps its login, the phone on the bar loses its.
    $store->revokeAllFor($user, $two['id']);
    assertTrue(is_array($store->validateCookie($two['cookie'])), 'the excepted session survives');
    assertSame(TokenStore::REFUSED_DEAD, $store->validateCookie($three['cookie']));

    // A reset by emailed link spares nobody.
    $store->revokeAllFor($user);
    assertSame(TokenStore::REFUSED_DEAD, $store->validateCookie($two['cookie']));
});

test('an expired token is dead however it is presented', function (): void {
    $store  = new TokenStore(auth_pdo(), 90, 24);
    $issued = $store->issue(auth_fixture_user(), false, '', '');

    auth_pdo()->prepare('UPDATE auth_token SET expires_at = :past WHERE id = :id')
        ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 10), ':id' => $issued['id']]);

    assertSame(TokenStore::REFUSED_DEAD, $store->validateCookie($issued['cookie']));
    assertSame(null, $store->activeById($issued['id']));
});

// ---------------------------------------------------------------------------
// Password reset tokens (spec 3.3)
// ---------------------------------------------------------------------------

test('a reset token issues, validates once, and is spent by consume()', function (): void {
    $resets = new PasswordReset(auth_pdo(), 60);
    $user   = auth_fixture_user();

    $token = $resets->issue($user, '203.0.113.91');
    $row   = $resets->validate($token);

    assertTrue(is_array($row), 'a fresh token validates');
    assertSame($user, (int) $row['user_id'], 'tied to exactly one account');

    assertSame(true, $resets->consume((int) $row['id']), 'the first spend wins');
    assertSame(false, $resets->consume((int) $row['id']), 'the second — a re-submitted form — loses');
    assertSame(null, $resets->validate($token), 'a spent token no longer validates');
});

test('an expired or tampered reset token validates as nothing at all', function (): void {
    $resets = new PasswordReset(auth_pdo(), 60);
    $token  = $resets->issue(auth_fixture_user(), '');

    [$selector] = explode('.', $token);
    assertSame(null, $resets->validate($selector . '.' . str_repeat('cd', 32)), 'wrong verifier');
    assertSame(null, $resets->validate('rubbish'), 'not even the right shape');

    auth_pdo()->prepare('UPDATE password_reset SET expires_at = :past WHERE selector = :selector')
        ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 10), ':selector' => $selector]);

    assertSame(null, $resets->validate($token), '60 minutes means 60 minutes');
});

test('outstandingFor counts only spendable tokens, so /forgot can stop at its ceiling', function (): void {
    $resets = new PasswordReset(auth_pdo(), 60);
    $user   = auth_fixture_user();

    $before = $resets->outstandingFor($user);
    $token  = $resets->issue($user, '');
    assertSame($before + 1, $resets->outstandingFor($user));

    $row = $resets->validate($token);
    assertTrue(is_array($row));
    $resets->consume((int) $row['id']);
    assertSame($before, $resets->outstandingFor($user), 'a spent token frees its slot');
});

// ---------------------------------------------------------------------------
// Cleanup — RESTRICT-safe order, and always last in this file
// ---------------------------------------------------------------------------

test('auth fixtures are cleaned up', function (): void {
    $pdo = auth_pdo();

    $memberId = $pdo->query("SELECT id FROM member WHERE member_number = 'AUTHTEST1'")->fetchColumn();

    if ($memberId !== false) {
        $users = $pdo->prepare('SELECT id FROM app_user WHERE member_id = :m');
        $users->execute([':m' => (int) $memberId]);
        $userIds = array_map('intval', $users->fetchAll(PDO::FETCH_COLUMN));

        foreach ($userIds as $userId) {
            $pdo->prepare('DELETE FROM auth_token WHERE user_id = :u')->execute([':u' => $userId]);
            $pdo->prepare('DELETE FROM password_reset WHERE user_id = :u')->execute([':u' => $userId]);
            $pdo->prepare('DELETE FROM app_user WHERE id = :u')->execute([':u' => $userId]);
        }

        $pdo->prepare('DELETE FROM member WHERE id = :m')->execute([':m' => (int) $memberId]);
    }

    $pdo->exec("DELETE FROM login_attempt WHERE ip LIKE '203.0.113.%'");

    assertSame(
        0,
        (int) $pdo->query("SELECT COUNT(*) FROM member WHERE member_number = 'AUTHTEST1'")->fetchColumn(),
        'nothing this file created may leak into the import tests'
    );
});
