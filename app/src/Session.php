<?php

declare(strict_types=1);

namespace Rerm;

/**
 * The PHP session, started with every cookie setting stated explicitly.
 *
 * Not one of them may be left to the host. Every default that matters is
 * WRONG here (docs/hosting.md, measured): `cookie_httponly` is off,
 * `cookie_secure` is off, `use_strict_mode` is off, and `cookie_path` is `/`
 * — which would hand our cookie to RESM at /resm/ on the same domain, and
 * theirs to us.
 *
 * docker/php/php.ini deliberately reproduces those unsafe defaults locally, so
 * code that relies on one fails on a laptop rather than on the server.
 *
 * WHAT THIS IS NOT: the long login. `session.gc_maxlifetime` is 1440s here and
 * garbage collection belongs to the host, not to us, so a 90-day "keep me
 * signed in" cannot be a PHP session. That is a DB-backed rotating
 * selector/verifier token in `auth_token`, and it arrives with Phase 3. This
 * holds what a single browser session needs — in Phase 2, the CSRF token and
 * the id of the import batch being previewed.
 */
final class Session
{
    private static bool $started = false;

    public static function start(App $app): void
    {
        if (self::$started || PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        $config = $app->config();

        $savePath = $config->get('session.save_path', null);
        if (!is_string($savePath) || $savePath === '') {
            // Ours, not the cPanel-wide directory RESM would otherwise be
            // sharing with us. Outside the document root, and 0700.
            $savePath = $app->path('var/sessions');
        }
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        session_name((string) $config->get('session.name', 'RERMSESS'));

        session_set_cookie_params([
            'lifetime' => (int) $config->get('session.lifetime', 0),
            // /rerm/, from app.base_path and from nowhere else. A cookie
            // scoped to / on this domain is a cookie RESM receives.
            'path'     => $app->url(),
            'domain'   => '',
            'secure'   => $config->get('session.secure', true) === true,
            'httponly' => true,
            // Lax rather than Strict: a link mailed to an officer has to
            // arrive signed in, and every state change here is a POST that
            // checks a CSRF token of its own.
            'samesite' => 'Lax',
        ]);

        // Refuses a session id the server never issued, which is what makes
        // fixation an attack that does not work rather than one nobody tried.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();
        self::$started = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }
}
