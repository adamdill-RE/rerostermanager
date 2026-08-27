<?php

declare(strict_types=1);

namespace Rerm\Auth;

use PDO;
use Rerm\App;

/**
 * The auth_token table — the REAL session (spec 3.4).
 *
 * The PHP session on this host cannot be the login: gc_maxlifetime is 1440s
 * and garbage collection belongs to the host, so anything longer than about
 * twenty minutes has to live in a row we govern. The PHP session therefore
 * holds one thing — an auth_token id — and this table is where a login
 * actually exists, which is also what makes revocation immediate: delete the
 * belief, not the cookie.
 *
 * The cookie is `selector.verifier`. The selector is an indexed lookup key
 * and is useless alone; only a SHA-256 of the verifier is stored, compared
 * with hash_equals — so a copy of this table is not a bag of valid cookies.
 *
 * ROTATION IS A COMPARE-AND-SWAP, and only the winner sends a new cookie.
 * Two requests can race to resume the same token (two tabs, a prefetch); the
 * UPDATE names the old selector and verifier hash in its WHERE, so exactly
 * one of them moves the row. The loser is refused for that one request and
 * NOTHING is revoked: a known selector with a wrong verifier is
 * indistinguishable from a request that lost the race, and signing an
 * officer out over a race they cannot see is the worse failure. The refusal
 * is audited by the caller either way.
 */
final class TokenStore
{
    /** validateCookie(): the selector is not on file at all. */
    public const REFUSED_UNKNOWN = 'unknown';

    /** validateCookie(): known selector, wrong verifier — refuse, audit, do NOT revoke. */
    public const REFUSED_MISMATCH = 'mismatch';

    /** validateCookie(): the row exists but is revoked or past its expiry. */
    public const REFUSED_DEAD = 'dead';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $rememberDays = 90,
        private readonly int $sessionHours = 24,
    ) {
    }

    public static function fromApp(App $app): self
    {
        $config = $app->config();

        return new self(
            $app->db(),
            (int) $config->get('auth.remember_days', 90),
            (int) $config->get('auth.session_token_hours', 24),
        );
    }

    /**
     * A new token for a fresh sign-in.
     *
     * @return array{id: int, cookie: string, persistent: bool}
     */
    public function issue(int $userId, bool $persistent, string $userAgent, string $ip): array
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));

        $this->pdo->prepare(
            'INSERT INTO auth_token (user_id, selector, verifier_hash, is_persistent, expires_at, user_agent, ip) '
            . 'VALUES (:user, :selector, :hash, :persistent, :expires, :agent, :ip)'
        )->execute([
            ':user'       => $userId,
            ':selector'   => $selector,
            ':hash'       => hash('sha256', $verifier),
            ':persistent' => $persistent ? 1 : 0,
            ':expires'    => $this->expiry($persistent),
            ':agent'      => substr($userAgent, 0, 255),
            ':ip'         => substr($ip, 0, 45),
        ]);

        return [
            'id'         => (int) $this->pdo->lastInsertId(),
            'cookie'     => $selector . '.' . $verifier,
            'persistent' => $persistent,
        ];
    }

    /**
     * The row behind a session's token id, if it is still a login.
     *
     * @return array<string, mixed>|null
     */
    public function activeById(int $id): ?array
    {
        $read = $this->pdo->prepare(
            'SELECT * FROM auth_token WHERE id = :id AND revoked_at IS NULL AND expires_at > :now'
        );
        $read->execute([':id' => $id, ':now' => App::now()]);
        $row = $read->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Checks a `selector.verifier` cookie against the table.
     *
     * @return array<string, mixed>|string the live row, or a REFUSED_* constant
     */
    public function validateCookie(string $cookie): array|string
    {
        $dot = strpos($cookie, '.');
        if ($dot === false) {
            return self::REFUSED_UNKNOWN;
        }

        $selector = substr($cookie, 0, $dot);
        $verifier = substr($cookie, $dot + 1);
        if ($selector === '' || $verifier === '') {
            return self::REFUSED_UNKNOWN;
        }

        $read = $this->pdo->prepare('SELECT * FROM auth_token WHERE selector = :selector');
        $read->execute([':selector' => $selector]);
        $row = $read->fetch();

        if (!is_array($row)) {
            return self::REFUSED_UNKNOWN;
        }

        // Compared before liveness, so a wrong verifier learns nothing about
        // whether the token it is guessing at is still good.
        if (!hash_equals((string) $row['verifier_hash'], hash('sha256', $verifier))) {
            return self::REFUSED_MISMATCH;
        }

        if ($row['revoked_at'] !== null || (string) $row['expires_at'] <= App::now()) {
            return self::REFUSED_DEAD;
        }

        return $row;
    }

    /**
     * Rotates a validated token: new selector, new verifier, expiry pushed
     * out (rolling — spec 3.4's 90 days are 90 days of absence, not of age).
     *
     * @param array<string, mixed> $token the row validateCookie() returned
     *
     * @return ?string the new cookie, or null when a concurrent request won
     */
    public function rotate(array $token): ?string
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));

        // The compare-and-swap. Old selector and old hash in the WHERE mean
        // this moves the row only if nobody else already has.
        $update = $this->pdo->prepare(
            'UPDATE auth_token SET selector = :selector, verifier_hash = :hash, '
            . 'last_used_at = :now, expires_at = :expires '
            . 'WHERE id = :id AND selector = :old_selector AND verifier_hash = :old_hash '
            . 'AND revoked_at IS NULL'
        );
        $update->execute([
            ':selector'     => $selector,
            ':hash'         => hash('sha256', $verifier),
            ':now'          => App::now(),
            ':expires'      => $this->expiry((int) $token['is_persistent'] === 1),
            ':id'           => (int) $token['id'],
            ':old_selector' => (string) $token['selector'],
            ':old_hash'     => (string) $token['verifier_hash'],
        ]);

        return $update->rowCount() === 1 ? $selector . '.' . $verifier : null;
    }

    /**
     * Marks a session-resumed token used and slides its expiry.
     *
     * Skipped when the row was touched inside the last minute, so a screen
     * making three requests does not make three writes to a database on
     * another machine.
     */
    public function touch(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE auth_token SET last_used_at = :now, expires_at = '
            . 'IF(is_persistent = 1, :remember, :session) '
            . 'WHERE id = :id AND revoked_at IS NULL '
            . 'AND (last_used_at IS NULL OR last_used_at < :stale)'
        )->execute([
            ':now'      => App::now(),
            ':remember' => $this->expiry(true),
            ':session'  => $this->expiry(false),
            ':id'       => $id,
            ':stale'    => gmdate('Y-m-d H:i:s', time() - 60),
        ]);
    }

    public function revoke(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE auth_token SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL'
        )->execute([':now' => App::now(), ':id' => $id]);
    }

    /**
     * Every live token for an account, bar at most one.
     *
     * Changing a password revokes every OTHER session (spec 3.2); a reset by
     * emailed link revokes all of them, because none of them is the person
     * standing at the reset form.
     */
    public function revokeAllFor(int $userId, ?int $exceptId = null): void
    {
        $sql  = 'UPDATE auth_token SET revoked_at = :now WHERE user_id = :user AND revoked_at IS NULL';
        $bind = [':now' => App::now(), ':user' => $userId];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except';
            $bind[':except'] = $exceptId;
        }

        $this->pdo->prepare($sql)->execute($bind);
    }

    private function expiry(bool $persistent): string
    {
        $seconds = $persistent
            ? $this->rememberDays * 86400
            : $this->sessionHours * 3600;

        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }
}
