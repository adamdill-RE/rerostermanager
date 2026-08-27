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

    // Senior Officer and above, inside their scope.
    case ViewCommitteeDashboard = 'view_committee_dashboard';
    case DesignateAllowedUser   = 'designate_allowed_user';

    // Admin, everywhere.
    case ImportRoster   = 'import_roster';
    case ExportRoster   = 'export_roster';
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
            self::ViewStatusDashboard     => Level::Officer,

            self::ViewCommitteeDashboard,
            self::DesignateAllowedUser    => Level::SeniorOfficer,

            self::ImportRoster,
            self::ExportRoster,
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
            self::DesignateAllowedUser    => Scope::Scoped,

            self::ImportRoster,
            self::ExportRoster,
            self::ManageShowYear,
            self::DesignateAdmin,
            self::ManageTeams,
            self::ViewAuditLog            => Scope::Everywhere,
        };
    }
}
