<?php

declare(strict_types=1);

namespace Rerm;

use Rerm\Auth\Capability;

/**
 * Every route this application serves, and what each one requires.
 *
 * The guard is declared HERE, beside the route name, rather than remembered
 * inside each handler — so "is every route guarded?" is a question a test can
 * answer by reading one table (tests/auth_test.php does exactly that, and
 * also asserts that every dispatch arm in public/index.php appears here). A
 * route this table does not name is a 404 before any handler runs.
 *
 * Guard values, enforced in public/index.php:
 *
 *   PUBLIC        no session required — the routes that exist so somebody
 *                 can GET a session. Everything they render is a form.
 *   SIGNED_IN     any authenticated user, whatever their level
 *   STATUS_KEY    app.status_key, constant-time, 404 without it
 *   SETUP_KEY     app.setup_key — the no-shell bootstrap; the route stops
 *                 existing when the key is removed from config.local.php
 *   anything else a Capability backing value, checked through
 *                 Access::mayUse() after authentication
 */
final class Routes
{
    public const PUBLIC     = 'public';
    public const SIGNED_IN  = 'signed_in';
    public const STATUS_KEY = 'status_key';
    public const SETUP_KEY  = 'setup_key';

    /**
     * route path (as App::requestPath() spells it) => guard.
     *
     * Capability guards are written as ->value strings because a constant
     * expression cannot hold an enum instance; guard() hands back the string
     * and index.php resolves it with Capability::from().
     *
     * @var array<string, string>
     */
    public const GUARDS = [
        // The signed-in landing page — the 7.0 menu. Anonymous requests are
        // redirected to /login rather than shown anything.
        ''         => self::SIGNED_IN,

        // Identity (spec 3). login/forgot/reset are public because they are
        // how a session comes to exist; logout and password are for whoever
        // already has one. /password is also where must_change_password pins
        // every request until the forced first change is done.
        'login'    => self::PUBLIC,
        'logout'   => self::SIGNED_IN,
        'password' => self::SIGNED_IN,
        'forgot'   => self::PUBLIC,
        'reset'    => self::PUBLIC,

        // View My Roster (spec 7.2) — Officer and above. The level check is
        // here; the rows themselves come through ScopedQuery::forUser(), so
        // reaching the screen never means seeing past one's scope.
        'roster'   => Capability::ViewRoster->value,

        // The import, behind the capability it was always meant for. Admin
        // only, everywhere-scoped; the setup-key era ended with Phase 3.
        'import'   => Capability::ImportRoster->value,

        // Operational, key-guarded, unchanged from Phase 0.
        'status'   => self::STATUS_KEY,
        'setup'    => self::SETUP_KEY,
    ];

    /** The guard for a path, or null — and null means 404, never "no guard". */
    public static function guard(string $path): ?string
    {
        return self::GUARDS[$path] ?? null;
    }
}
