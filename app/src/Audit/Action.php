<?php

declare(strict_types=1);

namespace Rerm\Audit;

/**
 * Every action `audit_log.action` may hold, as a type (Phase 8, open 2).
 *
 * Before this file the vocabulary was twelve string literals typed into five
 * PHP files and a migration, which was survivable while nothing READ them.
 * The Audit Log screen reads them: it filters by action, and a filter over a
 * free-text column is a filter that silently matches nothing the first time
 * somebody writes `password_change` where the rest of the application writes
 * `password_changed`. `Rerm\Auth\Capability` and `Rerm\Roster\MetricStatus`
 * are the precedent — a vocabulary that crosses a boundary is a type — and
 * this is the same shape of thing for the same reason.
 *
 * The backing values are EXACTLY the strings already in the column, so no
 * migration is needed and no history is rewritten: existing rows keep
 * matching, and the filter's list is now the same list the writers use.
 *
 * **Reading is deliberately tolerant, writing is not.** `label()` and the
 * filter go through the enum, so a screen can only offer an action that
 * exists; but a row already in the table carrying a string this enum does not
 * know — written by a version of this application that predates the enum, or
 * by a migration a future phase adds — still renders, as itself, through
 * `describe()`. An audit log that throws on its own history is an audit log
 * nobody can open, and the one thing this table must never do is become
 * unreadable.
 */
enum Action: string
{
    // Identity (spec 3). Written by public/index.php, Rerm\Auth\Auth and
    // bin/set-admin-password.php.
    case SetMasterPassword       = 'set_master_password';
    case PasswordChanged         = 'password_changed';
    case PasswordResetRequested  = 'password_reset_requested';
    case PasswordResetCompleted  = 'password_reset_completed';
    case AuthTokenRefused        = 'auth_token_refused';

    // The import (spec 6). Written by Rerm\Import\Importer.
    case ImportApplied           = 'import_applied';
    case ImportFailed            = 'import_failed';
    case ImportResetProgress     = 'import_reset_progress';

    // Assignment (spec 7.4). Written by Rerm\Roster\AssignOfficers.
    case AssignOfficer           = 'assign_officer';
    case RemoveAssignment        = 'remove_assignment';

    // Designation (spec 4.4). `grant_level` existed from Phase 1 but only
    // migration 003 had ever written it; Phase 8's Designate Users is the
    // first code to, and the first to revoke or to set a scope override.
    case GrantLevel              = 'grant_level';
    case RevokeLevel             = 'revoke_level';
    case SetScopeOverride        = 'set_scope_override';

    // Purge and restore (spec 6.5). Both soft: purge stamps `purged_at`,
    // restore clears it, and neither deletes anything.
    case PurgeMember             = 'purge_member';
    case RestoreMember           = 'restore_member';

    // Show years (spec 5.1). Closing FREEZES — there is no action here that
    // clears a metric, a contact or an assignment, and that absence is the
    // rule rather than an omission.
    case CreateShowYear          = 'create_show_year';
    case SetActiveShowYear       = 'set_active_show_year';
    case OpenShowYear            = 'open_show_year';
    case CloseShowYear           = 'close_show_year';
    case CarryAssignments        = 'carry_assignments';

    // Admin edits and the one read worth recording.
    case SetTeamArea             = 'set_team_area';

    /**
     * An export is PII leaving the building, so it is logged like a write
     * even though it changes nothing: the actor, the scope and the row count
     * (spec 10, Phase 8 "encode these"). It is the only READ in this
     * vocabulary, and it is here on purpose.
     */
    case ExportRoster            = 'export_roster';

    /** What the filter and the log's own rows call it. */
    public function label(): string
    {
        return match ($this) {
            self::SetMasterPassword      => 'Master password set',
            self::PasswordChanged        => 'Password changed',
            self::PasswordResetRequested => 'Password reset requested',
            self::PasswordResetCompleted => 'Password reset completed',
            self::AuthTokenRefused       => 'Session token refused',

            self::ImportApplied          => 'Import applied',
            self::ImportFailed           => 'Import failed',
            self::ImportResetProgress    => 'Progress reset by import',

            self::AssignOfficer          => 'Officer assigned',
            self::RemoveAssignment       => 'Assignment removed',

            self::GrantLevel             => 'Level granted',
            self::RevokeLevel            => 'Level revoked',
            self::SetScopeOverride       => 'Scope override set',

            self::PurgeMember            => 'Member purged',
            self::RestoreMember          => 'Member restored',

            self::CreateShowYear         => 'Show year created',
            self::SetActiveShowYear      => 'Show year made active',
            self::OpenShowYear           => 'Show year opened',
            self::CloseShowYear          => 'Show year closed',
            self::CarryAssignments       => 'Assignments carried forward',

            self::SetTeamArea            => 'Team area changed',

            self::ExportRoster           => 'Roster exported',
        };
    }

    /**
     * The word for a row already in the table, whether or not this enum knows
     * it. An unknown string comes back as itself rather than throwing — see
     * the class comment: the log has to stay readable.
     */
    public static function describe(string $stored): string
    {
        return self::tryFrom($stored)?->label() ?? $stored;
    }

    /**
     * The filter's options, alphabetical by label so a list of twenty-two is
     * scannable. The screen adds whatever ELSE the table actually holds, from
     * a DISTINCT read, so a historical string is filterable too.
     *
     * @return array<int, self>
     */
    public static function forFilter(): array
    {
        $cases = self::cases();
        usort($cases, static fn (self $a, self $b): int => strcmp($a->label(), $b->label()));

        return $cases;
    }
}
