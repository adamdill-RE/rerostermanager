<?php

declare(strict_types=1);

namespace Rerm\Auth;

/**
 * Everything a route or a button can require (spec 4.5), with the minimum
 * level and the scope each one carries.
 *
 * This is the matrix, written down once. tests/access_test.php transcribes it
 * a second time, row by row, so that widening a capability has to be done
 * twice, on purpose — the same discipline TitleMap uses, for the same reason:
 * a one-character change here decides who can read 1,954 people's home
 * addresses.
 *
 * The backing values are the strings routes declare (Rerm\Routes) and the
 * audit log records, so a capability crosses those boundaries as ->value and
 * comes back through from().
 *
 * What the three scopes mean is Access's business, in one sentence each:
 *
 *   Own         the subject must be the signed-in user's own member row
 *   Scoped      the subject must fall inside the user's division or team
 *   Everywhere  no subject; the level alone answers the question
 */
enum Capability: string
{
    // Anybody with a login, about themselves only.
    case ViewOwnRecord     = 'view_own_record';
    case ChangeOwnPassword = 'change_own_password';

    // Officer and above, inside their scope.
    case ViewRoster          = 'view_roster';
    case LogContact          = 'log_contact';
    case SetMetricProgress   = 'set_metric_progress';
    case AssignOfficers      = 'assign_officers';
    case ViewStatusDashboard = 'view_status_dashboard';

    // Officer and above, inside their scope — MOVED HERE BY PHASE 8, from
    // Admin / Everywhere, deliberately (Phase 8 decided 3).
    //
    // There is ONE export and every row of it comes through
    // ScopedQuery::forUser(), exactly like every other roster read, so
    // breadth is decided by who is asking rather than by which button they
    // pressed: an Admin gets the committee, a Senior Officer their division,
    // an Officer their team. Kept at Admin / Everywhere the capability would
    // have needed a second, scoped code path beside it — and two paths that
    // must agree about who may read 1,954 home addresses is the arrangement
    // this matrix exists to avoid.
    //
    // The floor is Officer because an Officer exporting their own team
    // exports data they already read, row by row, on View My Roster. The
    // shape is view_roster's, for view_roster's reason: the route guard
    // answers "may they use this screen" and ScopedQuery answers "which
    // rows". Spec 4.5 and 7.5 were updated in the same commit.
    case ExportRoster        = 'export_roster';

    /**
     * Create Forms (spec-v2 §2) — Officer and above, SCOPED, and the first
     * capability of v2.
     *
     * The shape is `export_roster`'s, for `export_roster`'s reason. A Roster
     * Change Form names members and carries their member numbers, and the
     * picker that puts them on it reads through `ScopedQuery::forUser()` like
     * every other member read: an Officer fills one in for their team, a
     * Division Chairman for their division. Breadth is decided by who is
     * asking rather than by which button they pressed, so there is one code
     * path rather than a scoped one and a full one that have to agree.
     *
     * The floor is Officer because filling in an RCF is an Officer's job —
     * they are the person who knows somebody has resigned — and because
     * everything the form shows them about a member, they already read row by
     * row on View My Roster.
     *
     * It is NOT `export_roster` reused. That one means "may take the roster
     * away as a file"; this one means "may produce committee paperwork". They
     * are different powers over different documents, and either should be
     * grantable without the other.
     */
    case CreateForms         = 'create_forms';

    // Senior Officer and above, inside their scope.
    case ViewCommitteeDashboard = 'view_committee_dashboard';
    case DesignateAllowedUser   = 'designate_allowed_user';

    // Admin, everywhere.
    case ImportRoster   = 'import_roster';

    /**
     * Loading a contact history from a file (spec 6.7) — Admin, everywhere,
     * and its OWN capability rather than a second use of import_roster.
     *
     * Two reasons, and the second is the real one. First, import_roster means
     * "may refresh what Rodeo Houston knows"; this means "may write rows into
     * the permanent contact record", and CLAUDE.md keeps those two ownerships
     * apart everywhere else in the application. Second, and unlike anything
     * else here, it ATTRIBUTES WORK TO OTHER PEOPLE: every row it writes says
     * a named officer contacted a named member on a named day. A capability
     * that says so by name can be reasoned about, and can be taken away on
     * its own.
     */
    case ImportContactHistory = 'import_contact_history';
    case ManageShowYear = 'manage_show_year';
    case DesignateAdmin = 'designate_admin';
    case ManageTeams    = 'manage_teams';
    case ViewAuditLog   = 'view_audit_log';

    /**
     * The floor. Levels include everything below them (spec 4.1), so the
     * check is always atLeast(), never equality.
     */
    public function minimumLevel(): Level
    {
        return match ($this) {
            self::ViewOwnRecord,
            self::ChangeOwnPassword       => Level::Member,

            self::ViewRoster,
            self::LogContact,
            self::SetMetricProgress,
            self::AssignOfficers,
            self::ViewStatusDashboard,
            self::ExportRoster,
            self::CreateForms             => Level::Officer,

            self::ViewCommitteeDashboard,
            self::DesignateAllowedUser    => Level::SeniorOfficer,

            self::ImportRoster,
            self::ImportContactHistory,
            self::ManageShowYear,
            self::DesignateAdmin,
            self::ManageTeams,
            self::ViewAuditLog            => Level::Admin,
        };
    }

    public function scope(): Scope
    {
        return match ($this) {
            self::ViewOwnRecord,
            self::ChangeOwnPassword       => Scope::Own,

            self::ViewRoster,
            self::LogContact,
            self::SetMetricProgress,
            self::AssignOfficers,
            self::ViewStatusDashboard,
            self::ViewCommitteeDashboard,
            self::DesignateAllowedUser,
            self::ExportRoster,
            self::CreateForms             => Scope::Scoped,

            self::ImportRoster,
            self::ImportContactHistory,
            self::ManageShowYear,
            self::DesignateAdmin,
            self::ManageTeams,
            self::ViewAuditLog            => Scope::Everywhere,
        };
    }
}
