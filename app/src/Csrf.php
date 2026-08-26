<?php

declare(strict_types=1);

namespace Rerm;

/**
 * A per-session CSRF token. EVERY POST in this application checks one.
 *
 * Reaching a route proves nothing: a form on another site can post to ours
 * with the browser's cookies attached, and the two most dangerous POSTs here
 * — apply an import, and confirm a purge — are both one click and both
 * irreversible in practice. Holding a token that only a page we rendered could
 * have contained is what separates "the Admin did this" from "the Admin
 * visited a page while signed in".
 *
 * One token per session rather than one per form: it survives the back button
 * and a second tab, which a rotating token does not, and the failure mode of a
 * rotating token is an Admin who has learned that the apply button sometimes
 * needs pressing twice.
 */
final class Csrf
{
    private const KEY = 'csrf_token';

    public const FIELD = 'csrf';

    public static function token(): string
    {
        $token = Session::get(self::KEY);

        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::set(self::KEY, $token);
        }

        return $token;
    }

    /**
     * Is this request carrying the session's token?
     *
     * hash_equals, not ===: a comparison that returns early leaks how much of
     * a guess was right, one byte at a time.
     */
    public static function check(?string $supplied = null): bool
    {
        $supplied ??= is_string($_POST[self::FIELD] ?? null) ? (string) $_POST[self::FIELD] : '';

        $expected = Session::get(self::KEY);
        if (!is_string($expected) || $expected === '' || $supplied === '') {
            return false;
        }

        return hash_equals($expected, $supplied);
    }

    /** The hidden input, escaped, for a form. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e(self::token()) . '">';
    }
}
