<?php

declare(strict_types=1);

namespace Rerm\Auth;

use Rerm\App;
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
 */
final class Auth
{
    public const SESSION_KEY = 'auth_token_id';

    private User|false|null $resolved = false;

    private ?int $tokenId = null;

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

    public function currentUser(): ?User
    {
        if ($this->resolved !== false) {
            return $this->resolved;
        }

        return $this->resolved = $this->resolve();
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
        $this->sendCookie('', time() - 86400);

        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $this->resolved = null;
        $this->tokenId  = null;
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

                    return $user;
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
            $this->audit('auth_token_refused', 'known selector, wrong verifier — refused, not revoked (spec 3.4)');

            return null;
        }
        if (!is_array($result)) {
            return null;
        }

        $fresh = $this->tokens->rotate($result);
        if ($fresh === null) {
            // Lost the compare-and-swap to a sibling request. Same treatment
            // as a mismatch, and for the same reason.
            $this->audit('auth_token_refused', 'lost a rotation race — refused, not revoked (spec 3.4)');

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

        return $user;
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
            . 'm.division_id, m.team_id '
            . 'FROM app_user u INNER JOIN member m ON m.id = u.member_id '
            . 'WHERE u.id = :id AND u.is_active = 1'
        );
        $read->execute([':id' => $userId]);
        $row = $read->fetch();

        return is_array($row) ? User::fromRow($row) : null;
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

    private function audit(string $action, string $detail): void
    {
        $this->app->db()->prepare(
            'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, after_json, ip) '
            . 'VALUES (NULL, :action, :entity, :entity_id, :after_json, :ip)'
        )->execute([
            ':action'     => $action,
            ':entity'     => 'auth_token',
            ':entity_id'  => '',
            ':after_json' => json_encode(['detail' => $detail], JSON_UNESCAPED_SLASHES),
            ':ip'         => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    }
}
