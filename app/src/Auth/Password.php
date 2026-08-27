<?php

declare(strict_types=1);

namespace Rerm\Auth;

use Rerm\App;

/**
 * Hashing and the password rules (spec 3.2), in one place.
 *
 * The rules are deliberately short: at least auth.min_password_length
 * characters, and never the shipped initial password. Length is what matters,
 * and a complexity rule nobody can satisfy produces a sticky note on a
 * monitor.
 *
 * bcrypt at cost 11 — ~50ms on this host, measured (docs/hosting.md) — for
 * parity with the sibling application and predictable timing. NEVER hash in
 * a loop: Phase 2 learned that 196 derivations in one request spends a third
 * of the 30-second execution budget, which is why the importer derives its
 * shared initial hash exactly once.
 *
 * verify() is a straight password_verify(), with no special cases. The seeded
 * master administrator ships with password_hash = '*' — the /etc/shadow
 * convention for a locked account, not a hash of anything — and
 * password_verify() already returns false against it for every input
 * including '*' itself. A special case here would be a second implementation
 * of that guarantee, and the login path must not have one.
 */
final class Password
{
    public function __construct(
        private readonly string $algo = PASSWORD_BCRYPT,
        private readonly int $cost = 11,
        private readonly int $minLength = 8,
        private readonly string $forbidden = '1234',
    ) {
    }

    public static function fromApp(App $app): self
    {
        $config = $app->config();

        return new self(
            (string) $config->get('auth.password_algo', PASSWORD_BCRYPT),
            (int) $config->get('auth.password_cost', 11),
            (int) $config->get('auth.min_password_length', 8),
            (string) $config->get('auth.default_password', '1234'),
        );
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algo, ['cost' => $this->cost]);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Re-hash on successful login when the stored parameters lag config —
     * the one moment the plaintext is legitimately in hand (spec, Phase 3).
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo, ['cost' => $this->cost]);
    }

    /**
     * Why a proposed password is refused, or null when it is acceptable.
     *
     * A sentence rather than a boolean, so every screen that sets a password
     * says the same thing and none invents its own rule.
     */
    public function problemWith(string $password): ?string
    {
        // Bytes, not code points, and that is the right measure here: the
        // limit exists as a floor on guessing work, and multibyte characters
        // only add to it.
        if (strlen($password) < $this->minLength) {
            return "The password must be at least {$this->minLength} characters.";
        }

        // 196 imported accounts share this value until first sign-in
        // (spec 3.1). It is in every wordlist, and "changed" to itself is not
        // changed.
        if ($password === $this->forbidden) {
            return 'That is the shipped initial password. Choose a different one.';
        }

        return null;
    }
}
