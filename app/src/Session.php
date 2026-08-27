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
    /**
     * Settings applied before every session_start(), and NOT left to the host.
     *
     * use_strict_mode refuses a session id the server never issued, which is
     * what makes fixation an attack that does not work rather than one nobody
     * has tried. use_only_cookies refuses one supplied in a URL, which is what
     * stops a signed-in officer pasting their own session into a group chat
     * along with the link.
     *
     * Both ship OFF on this host, and docker/php/php.ini reproduces that so
     * code relying on either default fails on a laptop instead of on the
     * server.
     *
     * @var array<string, string>
     */
    public const HARDENING = [
        'session.use_strict_mode'  => '1',
        'session.use_only_cookies' => '1',
    ];

    private static bool $started = false;

    /**
     * The cookie parameters, as a value.
     *
     * Separated from start() so that they can be ASSERTED. Every one of them
     * is a security decision whose host default is wrong, and start() cannot
     * run under the CLI test runner at all — which would have left the five
     * settings that keep this application's session out of RESM's hands as
     * the only untested thing in the codebase.
     *
     * @return array<string, mixed>
     */
    public static function cookieParams(App $app): array
    {
        $config = $app->config();

        return [
            'lifetime' => (int) $config->get('session.lifetime', 0),
            // The mount point, from app.base_path and from nowhere else. This
            // domain also serves RESM at /resm/, and a cookie scoped to `/`
            // — which is this host's default — is a cookie RESM receives.
            'path'     => $app->url(),
            // Empty, so the cookie is host-only. A domain attribute would
            // widen it to every subdomain.
            'domain'   => '',
            // Configurable ONLY because local development is plain http;
            // docker-compose sets RERM_SESSION_SECURE=0 and production does
            // not, so the default here is true rather than the host's false.
            'secure'   => $config->get('session.secure', true) === true,
            // Never configurable. No script in this application reads the
            // session cookie, so there is no case for exposing it to one.
            'httponly' => true,
            // Lax rather than Strict: a recovery link mailed to an officer has
            // to arrive signed in, and every state change here is a POST that
            // checks a CSRF token of its own.
            'samesite' => 'Lax',
        ];
    }

    /** The cookie's name — distinct from RESM's, which shares this domain. */
    public static function name(App $app): string
    {
        return (string) $app->config()->get('session.name', 'RERMSESS');
    }

    /**
     * Where session files live.
     *
     * Ours by default, not the cPanel-wide directory RESM would otherwise be
     * sharing with us — two applications writing session files into one
     * directory is two applications able to read each other's.
     */
    public static function savePath(App $app): string
    {
        $configured = $app->config()->get('session.save_path', null);

        return is_string($configured) && $configured !== ''
            ? $configured
            : $app->path('var/sessions');
    }

    public static function start(App $app): void
    {
        if (self::$started || PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        $savePath = self::savePath($app);
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        session_name(self::name($app));
        session_set_cookie_params(self::cookieParams($app));

        foreach (self::HARDENING as $setting => $value) {
            ini_set($setting, $value);
        }

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
