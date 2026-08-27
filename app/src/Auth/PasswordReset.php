<?php

declare(strict_types=1);

namespace Rerm\Auth;

use PDO;
use Rerm\App;

/**
 * Single-use password reset tokens (spec 3.3): 60 minutes, tied to one
 * account, spent the moment they are used.
 *
 * Same selector/verifier split as auth_token, for the same reason — the
 * emailed half is never what is stored, so a copy of this table cannot reset
 * anybody's password.
 *
 * The email wording lives here too, because it is load-bearing rather than
 * cosmetic: two addresses in the real roster are shared by four people, the
 * two people behind each inbox hold DIFFERENT titles, and a household inbox
 * is the normal case. A reset email that does not name its member number in
 * the subject and the first line hands the wrong account to whoever opens
 * the mail first (docs/data-findings.md 5, spec 3.3). Keeping the wording
 * beside the token means a test can hold both to the spec at once.
 */
final class PasswordReset
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $minutes = 60,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self(
            $app->db(),
            (int) $app->config()->get('auth.reset_token_minutes', 60),
        );
    }

    /**
     * A fresh token for this account. The return value is the emailed half;
     * nothing recoverable is stored.
     */
    public function issue(int $userId, string $ip): string
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));

        $this->pdo->prepare(
            'INSERT INTO password_reset (user_id, selector, verifier_hash, expires_at, requested_ip) '
            . 'VALUES (:user, :selector, :hash, :expires, :ip)'
        )->execute([
            ':user'     => $userId,
            ':selector' => $selector,
            ':hash'     => hash('sha256', $verifier),
            ':expires'  => gmdate('Y-m-d H:i:s', time() + $this->minutes * 60),
            ':ip'       => substr($ip, 0, 45),
        ]);

        return $selector . '.' . $verifier;
    }

    /**
     * How many unexpired, unspent tokens this account already has.
     *
     * The forgot screen stops issuing past a handful: the response never
     * changes (no enumeration), but an attacker replaying the form must not
     * be able to fill a shared household inbox on this application's behalf.
     */
    public function outstandingFor(int $userId): int
    {
        $read = $this->pdo->prepare(
            'SELECT COUNT(*) FROM password_reset '
            . 'WHERE user_id = :user AND used_at IS NULL AND expires_at > :now'
        );
        $read->execute([':user' => $userId, ':now' => App::now()]);

        return (int) $read->fetchColumn();
    }

    /**
     * The row a `selector.verifier` token names, if it is still spendable.
     *
     * @return array<string, mixed>|null
     */
    public function validate(string $token): ?array
    {
        $dot = strpos($token, '.');
        if ($dot === false) {
            return null;
        }

        $selector = substr($token, 0, $dot);
        $verifier = substr($token, $dot + 1);
        if ($selector === '' || $verifier === '') {
            return null;
        }

        $read = $this->pdo->prepare('SELECT * FROM password_reset WHERE selector = :selector');
        $read->execute([':selector' => $selector]);
        $row = $read->fetch();

        if (!is_array($row)) {
            return null;
        }
        if (!hash_equals((string) $row['verifier_hash'], hash('sha256', $verifier))) {
            return null;
        }
        if ($row['used_at'] !== null || (string) $row['expires_at'] <= App::now()) {
            return null;
        }

        return $row;
    }

    /**
     * Spends a token — a compare-and-swap on used_at, so two submissions of
     * the same link change exactly one password between them.
     */
    public function consume(int $id): bool
    {
        $update = $this->pdo->prepare(
            'UPDATE password_reset SET used_at = :now WHERE id = :id AND used_at IS NULL'
        );
        $update->execute([':now' => App::now(), ':id' => $id]);

        return $update->rowCount() === 1;
    }

    /**
     * The subject line, spec 3.3 verbatim: the member number is IN the
     * subject, because the inbox it lands in may serve two members.
     */
    public static function emailSubject(string $memberNumber): string
    {
        return "Reset the password for Rodeo Express member number {$memberNumber}";
    }

    /**
     * The body. First line names the member number; the household caveat is
     * the spec's own wording.
     */
    public static function emailBody(string $memberNumber, string $link, int $minutes): string
    {
        return "This resets the password for member number {$memberNumber} only.\n"
            . "If you or someone in your household uses a different member number,\n"
            . "this link will not change that account's password.\n"
            . "\n"
            . "Reset it here (the link works once, for {$minutes} minutes):\n"
            . "\n"
            . "    {$link}\n"
            . "\n"
            . "If you did not ask for this, you can ignore it — nothing has changed.\n";
    }
}
