<?php

declare(strict_types=1);

namespace Rerm\Auth;

use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Session;

/**
 * Who is making this request.
 *
 * Resolved once per request, from two places in this order:
 *
 *   1. The PHP session, which holds ONE thing: an auth_token id (spec 3.4).
 *      The row is the login; the session is a cache of which row.
 *   2. Failing that, the `selector.verifier` cookie — the 90-day "keep me
 *      signed in". Resuming from it ROTATES the token (a compare-and-swap in
 *      TokenStore) and only the winning request sends the new cookie.
 *
 * A cookie that names a known selector with a wrong verifier is refused and
 * audited but revokes NOTHING: a request that lost the rotation race lands in
 * exactly that state, and signing an officer out over a race they cannot see
 * is the worse failure (spec 3.4). The next request rides the winner's fresh
 * cookie.
 *
 * The browser cookie is never cleared on a refusal, for the same reason — a
 * Set-Cookie from the losing request would race the winner's.
 *
 * SIGN IN AS (Phase 14.1, spec-v2 §16): an Admin may run a request AS another
 * account, to see the application exactly as that account sees it. The PHP
 * session then holds a second thing, the app_user id being acted as, and
 * currentUser() returns THAT account, built by the same loadUser() the real
 * login is built by — so its level, its division, its team set and every
 * screen's scope resolve exactly as they would for the person themselves.
 * The real login is unchanged underneath: the token row, the cookie and the
 * session pointer all still belong to the Admin, accountHolder() still says
 * so, and the acting-as key is re-checked on EVERY request against the
 * account holder's level, so a revoked Admin does not stay in anybody's
 * shoes. It lives in the PHP session only, never in auth_token: it cannot
 * outlive the browser session, and it ends with a Sign out either way.
 */
final class Auth
{
    public const SESSION_KEY = 'auth_token_id';

    /** The app_user id an Admin is signed in as, when they are (spec-v2 §16). */
    public const ACTING_KEY = 'acting_as_user_id';

    private User|false|null $resolved = false;

    private ?int $tokenId = null;

    /**
     * The account that actually signed in, when currentUser() is somebody
     * else; null whenever nobody is acting. Set by resolve() alone.
     */
    private ?User $holder = null;

    public function __construct(
        private readonly App $app,
        private readonly TokenStore $tokens,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self($app, TokenStore::fromApp($app));
    }

    /** The remember-me cookie's name — distinct from the PHP session's. */
    public function cookieName(): string
    {
        return (string) $this->app->config()->get('auth.cookie_name', 'RERMAUTH');
    }

    /**
     * The user this request RUNS AS. While an Admin is signed in as another
     * account this is that account; every route guard, every Access check
     * and every ScopedQuery reads it, which is the whole point.
     */
    public function currentUser(): ?User
    {
        if ($this->resolved !== false) {
            return $this->resolved;
        }

        return $this->resolved = $this->resolve();
    }

    /**
     * The account that actually signed in — the Admin, while they are signed
     * in as somebody else; otherwise the same person currentUser() returns.
     */
    public function accountHolder(): ?User
    {
        $current = $this->currentUser();

        return $this->holder ?? $current;
    }

    /** Is this request being run as an account other than the one that signed in? */
    public function acting(): bool
    {
        $this->currentUser();

        return $this->holder !== null;
    }

    /** The auth_token id behind the current user, for "every session but this one". */
    public function currentTokenId(): ?int
    {
        $this->currentUser();

        return $this->tokenId;
    }

    /**
     * Establishes a login for a verified user.
     *
     * The session id is regenerated FIRST: a session id that existed before
     * authentication is a session id somebody else may have planted, and
     * use_strict_mode narrows that attack without closing it.
     */
    public function signIn(int $userId, bool $remember): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $issued = $this->tokens->issue(
            $userId,
            $remember,
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );

        Session::set(self::SESSION_KEY, $issued['id']);
        $this->sendCookie(
            $issued['cookie'],
            $remember
                ? time() + (int) $this->app->config()->get('auth.remember_days', 90) * 86400
                : 0
        );

        $this->resolved = false;
        $this->tokenId  = $issued['id'];
        $this->holder   = null;
    }

    /**
     * Ends the current login: the token row is revoked — which is what
     * actually signs out, everywhere the cookie may have been copied — and
     * then the session and cookie stop pointing at it.
     */
    public function signOut(): void
    {
        $tokenId = Session::get(self::SESSION_KEY);
        if (is_int($tokenId)) {
            $this->tokens->revoke($tokenId);
        } else {
            $cookie = $_COOKIE[$this->cookieName()] ?? '';
            $row    = is_string($cookie) && $cookie !== '' ? $this->tokens->validateCookie($cookie) : null;
            if (is_array($row)) {
                $this->tokens->revoke((int) $row['id']);
            }
        }

        Session::forget(self::SESSION_KEY);
        // Signing out ends the acting-as as well: the real login is what is
        // being ended, and there is nothing to be somebody else's shoes in
        // once it is gone.
        Session::forget(self::ACTING_KEY);
        $this->sendCookie('', time() - 86400);

        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $this->resolved = null;
        $this->tokenId  = null;
        $this->holder   = null;
    }

    // -----------------------------------------------------------------------
    // Sign in as (Phase 14.1, spec-v2 §16)
    // -----------------------------------------------------------------------

    /**
     * Starts running this session as another account.
     *
     * Four refusals, each a sentence the screen shows, and every one of them
     * is re-asked by resolve() on the next request rather than trusted from
     * the session: the account holder must be an Admin, the target must be
     * an active account, the target must not be an Admin (nothing is seen
     * that way that the Admin cannot already see, and one Admin acting under
     * another's name is a false record), and the target must not be the
     * holder themselves. Audited as the Admin, naming the account.
     *
     * @return string|null the refusal, or null when the session now runs as
     *         the account
     */
    public function signInAs(int $userId): ?string
    {
        $holder = $this->accountHolder();
        if ($holder === null || $holder->level !== Level::Admin) {
            return 'Only an Admin may sign in as another account.';
        }
        if ($userId === $holder->id) {
            return 'That is your own account.';
        }

        $target = $this->loadUser($userId);
        if ($target === null) {
            return 'That account does not exist or is deactivated.';
        }
        if ($target->level === Level::Admin) {
            return 'An Admin cannot sign in as another Admin: there is nothing they would see that you cannot, and anything done would be recorded under the wrong name.';
        }

        // Switching straight from one account to another closes the first
        // on the record, so every sign_in_as row has its sign_out_as.
        $this->signOutAs();

        Session::set(self::ACTING_KEY, $target->id);

        (new AuditLog($this->app->db()))->record(
            $holder,
            Action::SignInAs,
            'app_user',
            (string) $target->id,
            null,
            ['member_number' => $target->memberNumber, 'level' => $target->level->value]
        );

        $this->resolved = false;
        $this->holder   = null;

        // From here to the end of this request every audit row is the
        // Admin's hand under the account's name (AuditLog's marker); the
        // front controller sets the same thing at the head of every later
        // request from acting().
        AuditLog::setActingAdmin($holder->id);

        return null;
    }

    /**
     * Back to the account that signed in. A no-op when nobody is acting, so
     * the header's button can never do harm when pressed twice.
     */
    public function signOutAs(): void
    {
        if (!$this->acting()) {
            return;
        }

        $acted  = $this->currentUser();
        $holder = $this->holder;

        Session::forget(self::ACTING_KEY);
        $this->resolved = false;
        $this->holder   = null;

        // The request runs as the Admin again, so the sign_out_as row below
        // and anything after it is theirs plainly, with no marker.
        AuditLog::setActingAdmin(null);

        if ($acted !== null && $holder !== null) {
            (new AuditLog($this->app->db()))->record(
                $holder,
                Action::SignOutAs,
                'app_user',
                (string) $acted->id,
                null,
                ['member_number' => $acted->memberNumber]
            );
        }
    }

    /**
     * The account the session is acting as, if the real login may still act
     * as it; otherwise the real login, with the stale key forgotten.
     *
     * Re-checked every request, on purpose. A session that held the key
     * when its holder was an Admin is not evidence the holder still is one:
     * a grant can be revoked underneath it, the target can be deactivated
     * by an import, or promoted to Admin by a designation. Each of those
     * ends the acting-as on the next request rather than being honoured.
     */
    private function actingUser(User $holder): User
    {
        $actingId = Session::get(self::ACTING_KEY);
        if (!is_int($actingId)) {
            return $holder;
        }

        $target = $holder->level === Level::Admin && $actingId !== $holder->id
            ? $this->loadUser($actingId)
            : null;

        if ($target === null || $target->level === Level::Admin) {
            Session::forget(self::ACTING_KEY);

            return $holder;
        }

        $this->holder = $holder;

        return $target;
    }

    private function resolve(): ?User
    {
        // The cheap path: the session already names a token.
        $tokenId = Session::get(self::SESSION_KEY);
        if (is_int($tokenId)) {
            $token = $this->tokens->activeById($tokenId);
            if ($token !== null) {
                $this->tokens->touch($tokenId);
                $user = $this->loadUser((int) $token['user_id']);
                if ($user !== null) {
                    $this->tokenId = $tokenId;

                    return $this->actingUser($user);
                }
            }

            // Revoked, expired, or the account went inactive under it. The
            // stale pointer goes; the cookie may still resume a DIFFERENT,
            // live token below.
            Session::forget(self::SESSION_KEY);
        }

        $cookie = $_COOKIE[$this->cookieName()] ?? '';
        if (!is_string($cookie) || $cookie === '') {
            return null;
        }

        $result = $this->tokens->validateCookie($cookie);

        if ($result === TokenStore::REFUSED_MISMATCH) {
            $this->audit(Action::AuthTokenRefused, 'known selector, wrong verifier — refused, not revoked (spec 3.4)');

            return null;
        }
        if (!is_array($result)) {
            return null;
        }

        $fresh = $this->tokens->rotate($result);
        if ($fresh === null) {
            // Lost the compare-and-swap to a sibling request. Same treatment
            // as a mismatch, and for the same reason.
            $this->audit(Action::AuthTokenRefused, 'lost a rotation race — refused, not revoked (spec 3.4)');

            return null;
        }

        $user = $this->loadUser((int) $result['user_id']);
        if ($user === null) {
            return null;
        }

        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Session::set(self::SESSION_KEY, (int) $result['id']);
        $this->sendCookie(
            $fresh,
            (int) $result['is_persistent'] === 1
                ? time() + (int) $this->app->config()->get('auth.remember_days', 90) * 86400
                : 0
        );
        $this->tokenId = (int) $result['id'];

        // The cookie path starts a fresh PHP session, so there is no
        // acting-as key to honour here — actingUser() finds none and hands
        // the real login back. Routed through it anyway so there is one
        // answer to "who is this request", not two.
        return $this->actingUser($user);
    }

    /**
     * The one JOIN that builds a User: account and member together, active
     * rows only. effective_level is the schema's VIRTUAL column — nothing
     * here re-derives granted_level ?? level.
     */
    private function loadUser(int $userId): ?User
    {
        $read = $this->app->db()->prepare(
            'SELECT u.id, u.member_id, u.effective_level, u.must_change_password, '
            . 'u.scope_division_id, u.scope_team_id, '
            . 'm.member_number, m.first_name, m.last_name, m.preferred_name, '
            // m.title since Phase 8.5: a title carries a default scope
            // breadth as well as a level, and User::fromRow needs it to
            // resolve the third branch of the scope.
            . 'm.title, m.division_id, m.team_id '
            . 'FROM app_user u INNER JOIN member m ON m.id = u.member_id '
            . 'WHERE u.id = :id AND u.is_active = 1'
        );
        $read->execute([':id' => $userId]);
        $row = $read->fetch();

        if (!is_array($row)) {
            return null;
        }

        return User::fromRow($row, $this->teamScope($userId));
    }

    /**
     * The teams this account is narrowed to (Phase 8.5), or an empty list.
     *
     * A second query rather than a join: the row above is one row per user
     * and this is a list, so joining would multiply the user's own columns by
     * the number of teams and make every other field arrive N times. It costs
     * one round trip on a request that already made one, against a scope that
     * is read on every page.
     *
     * @return array<int, int>
     */
    private function teamScope(int $userId): array
    {
        $read = $this->app->db()->prepare(
            'SELECT team_id FROM app_user_team WHERE app_user_id = :id ORDER BY team_id'
        );
        $read->execute([':id' => $userId]);

        return array_map('intval', $read->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function sendCookie(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $params = Session::cookieParams($this->app);

        setcookie($this->cookieName(), $value, [
            'expires'  => $expires,
            'path'     => (string) $params['path'],
            'domain'   => (string) $params['domain'],
            'secure'   => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => (string) $params['samesite'],
        ]);
    }

    /**
     * No actor by design: the whole point of a refused token is that
     * nobody was authenticated, so AuditLog is handed null.
     */
    private function audit(Action $action, string $detail): void
    {
        (new AuditLog($this->app->db()))
            ->record(null, $action, 'auth_token', '', null, ['detail' => $detail]);
    }
}
