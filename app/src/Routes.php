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
        // The signed-in landing page. Since Phase 5 the handler renders My
        // Roster Status for anyone who may use it — an officer signing in to
        // chase people should already be looking at who to chase (spec 7.0)
        // — and the menu for anyone who may not (a future Member-level
        // Allowed User has no dashboard). Anonymous requests are redirected
        // to /login rather than shown anything.
        ''         => self::SIGNED_IN,

        // My Roster Status (spec 7.1), by its own name. NOT 'status' — that
        // is the Phase 0 ops health check. The level check is here; the rows
        // come through ScopedQuery::forUser(), and the mutation below
        // re-checks Access::allows() with a Subject per member.
        'dashboard' => Capability::ViewStatusDashboard->value,

        // The 7.0 menu, moved off the landing path by Phase 5. Linked from
        // the dashboard; every redirect($app) back to '' keeps working.
        'menu'     => self::SIGNED_IN,

        // The dashboard's write: a contact_log insert plus optional metric
        // progress, POST-only, CSRF-checked, and refused per member by
        // Access::allows() with a Subject — the route guard alone proves
        // only the level.
        'log-contact' => Capability::LogContact->value,

        // Identity (spec 3). login/forgot/reset are public because they are
        // how a session comes to exist; logout and password are for whoever
        // already has one. /password is also where must_change_password pins
        // every request until the forced first change is done.
        'login'    => self::PUBLIC,
        'logout'   => self::SIGNED_IN,
        'password' => self::SIGNED_IN,
        'forgot'   => self::PUBLIC,
        'reset'    => self::PUBLIC,

        // Assign Officers (spec 7.4) — Officer and above, scoped. One route
        // for the screen and its writes: the form posts back to the page it
        // came from and 303s to the same team, bucket and page, so a separate
        // write path would be a second name for one screen. The guard covers
        // both verbs; every mutation inside re-checks Access::allows() with a
        // Subject per selected member, because a bulk POST is fifty
        // per-member questions and not one.
        'assign'   => Capability::AssignOfficers->value,

        // View My Roster (spec 7.2) — Officer and above. The level check is
        // here; the rows themselves come through ScopedQuery::forUser(), so
        // reaching the screen never means seeing past one's scope.
        'roster'   => Capability::ViewRoster->value,

        // Dropped Members (Phase 8.5) — Officer and above, SCOPED, and it
        // shares view_roster rather than inventing a capability: it is the
        // roster, filtered to the people who fell off it, and an officer
        // noticing that one of their own is gone is the same job. Read-only
        // — purge and restore stay Admin, on /purge.
        'dropped'  => Capability::ViewRoster->value,

        // The Committee Dashboard (spec 7.3) — Senior Officer and above, and
        // the first route whose capability floor is above Officer. Read-only:
        // there is no write path on it at all, so no POST and no CSRF check —
        // but the guard here and ScopedQuery inside CommitteePage are not
        // optional, and a Senior Officer reaching it still sees exactly their
        // own division's groups.
        'committee' => Capability::ViewCommitteeDashboard->value,

        // The import, behind the capability it was always meant for. Admin
        // only, everywhere-scoped; the setup-key era ended with Phase 3.
        'import'   => Capability::ImportRoster->value,

        // Import History (Phase 10) — Admin, and import_roster's own
        // capability rather than a seventh, because it is the second half of
        // the same job: whoever may rewrite 1,954 rows from a file is exactly
        // who needs to be able to see what the last file did. Read-only, so
        // no POST and no CSRF check — the only controls on it are a search
        // box and links.
        //
        // NOT scoped, deliberately. The value of the screen is watching a
        // member move BETWEEN teams and divisions, and a scoped read would
        // show half of such a move and hide the other half, which is worse
        // than not showing it.
        'import-history' => Capability::ImportRoster->value,

        // Import Contact History (spec 6.7) — Admin, and its OWN capability
        // rather than import_roster's. The roster import refreshes what Rodeo
        // Houston knows; this one writes rows into contact_log, which no
        // roster import may ever touch, and attributes each of them to a named
        // officer. Two different powers, two different names, so either can be
        // held without the other.
        //
        // Both verbs on one route, like /teams and /assign: the upload, the
        // apply and the discard all post back to the screen that drew them.
        'import-contacts' => Capability::ImportContactHistory->value,

        // ----- The Admin screens (spec 7.5), Phase 8 -----------------------

        // Designate Users (spec 7.5, 4.4) — Senior Officer and above, and the
        // first screen ever to write app_user.granted_level or a scope
        // override. Both verbs on one route: the grant and revoke forms post
        // back to the search they came from and 303 to it. Every write
        // re-checks Access::allows() with a Subject AND Access::mayGrant()
        // with the level, because the cap is on the LEVEL and the scope is on
        // the MEMBER and they are two different questions.
        'designate' => Capability::DesignateAllowedUser->value,

        // Flagged for Purge (spec 6.5) — the second half of the import
        // lifecycle, so it carries the import's capability rather than
        // inventing a seventh: Admin, everywhere. A purge is a soft delete
        // and Restore is on the same screen, because an import does not clear
        // purged_at and without Restore a mistake needs somebody at the
        // database.
        'purge'    => Capability::ImportRoster->value,

        // Export Roster (spec 7.5, Phase 8 decided 3) — Officer and above,
        // SCOPED. One export, one code path, breadth decided by
        // ScopedQuery::forUser(). The file itself is a POST: it is audited,
        // and its body is ~85 people's home addresses, which is not something
        // a GET an <img src> can send should produce.
        'export'   => Capability::ExportRoster->value,

        // ----- Create Forms (spec-v2 §1, §2), Phase 9 --------------------

        // The forms menu — Officer and above, and the only thing on it so far
        // is the Roster Change Form. It is a route rather than a section of
        // /menu because a form is a thing somebody comes here to make, and
        // because the second and third forms need somewhere to appear.
        'forms'    => Capability::CreateForms->value,

        // The Roster Change Form itself (spec-v2 §2). Both verbs on one
        // route, like /assign and /teams: the GET draws the form for a chosen
        // sub-committee and the POST turns what was typed into an .xlsx, and
        // the two are one screen.
        //
        // Hyphenated and flat — 'form-rcf', not 'forms/rcf'. Every route in
        // this application is one segment, .htaccess rewrites with a relative
        // substitution and no RewriteBase, and there is no reason to be the
        // first request to find out how LiteSpeed resolves that from a
        // subdirectory.
        'form-rcf' => Capability::CreateForms->value,

        // Show Year (spec 5.1) — Admin. Create, set active, open/close, and
        // the rollover that carries eligible assignments into a new year.
        // 'show-year', hyphenated like 'log-contact'.
        'show-year' => Capability::ManageShowYear->value,

        // The Audit Log (spec 7.5) — Admin, read-only, filterable by actor,
        // action and date. No write path at all, so no POST and no CSRF.
        'audit'    => Capability::ViewAuditLog->value,

        // Manage Teams (spec 7.3) — Admin. team.area only: it is display
        // grouping, and a test holds Access, ScopedQuery, EligibleOfficers
        // and AssignOfficers clean of the column, comments included.
        'teams'    => Capability::ManageTeams->value,

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
