<?php

declare(strict_types=1);

namespace Rerm\Auth;

use PDO;
use Rerm\App;

/**
 * The login rate limit (spec 3.5), with two limbs.
 *
 * The specified limb: 10 failures FROM ONE IP inside 15 minutes buys a
 * 60-second lockout. Deliberately loose — this is an internal roster tool and
 * a locked-out Captain is a worse outcome than a guessing attempt.
 *
 * The second limb keys the same window and lockout on the MEMBER NUMBER, and
 * exists because the IP limb alone does not cover the attack this application
 * actually invites: member numbers are 6–7 digits, 1,954 of them are valid,
 * and every imported account starts life on one published password. An
 * attacker rotating IPs against a single member number never trips an IP
 * count; ten failures against one account trips this one whoever sends them.
 * The schema anticipated it — ix_login_attempt_member has no other reader.
 *
 * Both limbs read the same config values; neither replaces the other, and a
 * request is refused when either says so.
 *
 * Successes are recorded as well as failures, so the audit can tell a typo
 * from an attack (spec 3.5). An attempt refused BY the throttle is not
 * recorded as a failure: it proved nothing about the password, and counting
 * it would let a hammering attacker hold the lockout open forever — turning
 * a 60-second brake into the denial of service the loose numbers exist to
 * avoid.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $attempts = 10,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 60,
    ) {
    }

    public static function fromApp(App $app): self
    {
        $config = $app->config();

        return new self(
            $app->db(),
            (int) $config->get('auth.lockout_attempts', 10),
            (int) $config->get('auth.lockout_window_seconds', 900),
            (int) $config->get('auth.lockout_seconds', 60),
        );
    }

    /**
     * Seconds until this request may try again, or null to proceed.
     *
     * The lockout runs from the LATEST failure in the window: once the count
     * reaches the ceiling, the next 60 seconds are closed, and a fresh
     * failure after that starts a fresh 60 seconds. The larger of the two
     * limbs' answers is reported, so the message on the screen is never
     * shorter than the truth.
     */
    public function retryAfter(string $ip, string $memberNumber): ?int
    {
        $wait = $this->limbRetryAfter('ip', $ip);

        if ($memberNumber !== '') {
            $wait = max($wait ?? 0, $this->limbRetryAfter('member_number', $memberNumber) ?? 0);
            $wait = $wait === 0 ? null : $wait;
        }

        return $wait;
    }

    public function recordFailure(string $ip, string $memberNumber): void
    {
        $this->record($ip, $memberNumber, false);
    }

    public function recordSuccess(string $ip, string $memberNumber): void
    {
        $this->record($ip, $memberNumber, true);
    }

    /** @param 'ip'|'member_number' $column */
    private function limbRetryAfter(string $column, string $value): ?int
    {
        // $column is one of two literals from this file, never input. The
        // window bound is computed in PHP so both limbs and both engines
        // compare against the same UTC clock the rows were stamped with.
        $read = $this->pdo->prepare(
            "SELECT COUNT(*) AS failures, MAX(occurred_at) AS latest FROM login_attempt "
            . "WHERE `{$column}` = :value AND succeeded = 0 AND occurred_at >= :since"
        );
        $read->execute([
            ':value' => $value,
            ':since' => self::utc(time() - $this->windowSeconds),
        ]);

        $row = $read->fetch();
        if (!is_array($row) || (int) $row['failures'] < $this->attempts || $row['latest'] === null) {
            return null;
        }

        $lockedUntil = strtotime((string) $row['latest'] . ' UTC') + $this->lockoutSeconds;
        $remaining   = $lockedUntil - time();

        return $remaining > 0 ? $remaining : null;
    }

    private function record(string $ip, string $memberNumber, bool $succeeded): void
    {
        $this->pdo->prepare(
            'INSERT INTO login_attempt (ip, member_number, succeeded) VALUES (:ip, :member, :ok)'
        )->execute([
            ':ip'     => substr($ip, 0, 45),
            ':member' => substr($memberNumber, 0, 32),
            ':ok'     => $succeeded ? 1 : 0,
        ]);
    }

    private static function utc(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
