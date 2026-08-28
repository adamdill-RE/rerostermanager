<?php

declare(strict_types=1);

namespace Rerm\Auth;

/**
 * The one place a permission question is answered.
 *
 * Role AND scope, server-side, on every request (CLAUDE.md): hiding a menu
 * tile hides nothing, so every route and every per-member action asks here.
 * The matrix itself lives in Capability; this file is the semantics — what
 * Own, Scoped and Everywhere actually mean — and tests/access_test.php
 * transcribes both a second time.
 *
 * Two inputs only: the signed-in User and, for anything narrower than
 * Everywhere, the Subject member. Deliberately nothing else — in particular
 * NOTHING from the team table. Teams span divisions, so a member's placement
 * is read from their own row, and the team's display grouping column is
 * forbidden here outright: it is seeded by a prefix heuristic and editable by
 * an Admin, and a permission that read it would move with a cosmetic edit.
 * A test asserts this file never mentions it.
 */
final class Access
{
    /**
     * May this user do this, to this member?
     *
     * The per-member question, asked wherever an action names somebody: a
     * roster row, a contact log, a progress change, a designation.
     *
     * A scoped capability REQUIRES a subject (spec 4.5). Passing null denies,
     * because "may they log a contact, against nobody in particular" answered
     * yes is how an Officer edits another team's data.
     */
    public static function allows(User $user, Capability $capability, ?Subject $subject = null): bool
    {
        if (!$user->level->atLeast($capability->minimumLevel())) {
            return false;
        }

        return match ($capability->scope()) {
            // No subject involved: the level alone answers, and these floors
            // are all Admin. A subject passed anyway is not an error — an
            // Admin exporting one member's history is still exporting.
            Scope::Everywhere => true,

            // Themselves and nobody else. An Admin reading somebody else's
            // record does it through view_roster, not through this.
            Scope::Own => $subject !== null && $subject->memberId === $user->memberId,

            Scope::Scoped => $subject !== null && self::inScope($user, $subject),
        };
    }

    /**
     * Does this user's level reach this capability at all?
     *
     * The route and menu question — no subject exists yet when a screen is
     * being gated or a tile drawn. It is never sufficient for an action on a
     * member: that is allows(), and a screen that gets in through here still
     * reads its rows through ScopedQuery and checks allows() per mutation.
     */
    public static function mayUse(User $user, Capability $capability): bool
    {
        return $user->level->atLeast($capability->minimumLevel());
    }

    /**
     * May this user grant (or revoke) this level (spec 4.4)?
     *
     * At or below their own: a Senior Officer cannot create an Executive, an
     * Executive cannot create an Admin — only an Admin creates an Admin, and
     * that falls out of the rank comparison rather than being a special case.
     * Who the grant is FOR is a separate question, answered by
     * allows(..., Capability::DesignateAllowedUser, subject).
     */
    public static function mayGrant(User $user, Level $level): bool
    {
        return $user->level->atLeast(Level::SeniorOfficer)
            && $user->level->rank() >= $level->rank();
    }

    /**
     * Spec 4.3, exactly.
     *
     *   Admin, Executive Officer  ->  every member
     *   Senior Officer            ->  members in the user's division
     *   Officer                   ->  members on the user's team
     *
     * The user's division and team were resolved by User::fromRow — an
     * explicit Admin override on app_user, else their own member row. A user
     * whose scope resolves to nothing (an Officer with no team) reaches
     * nobody rather than everybody: null never matches.
     */
    private static function inScope(User $user, Subject $subject): bool
    {
        if ($user->level->atLeast(Level::ExecutiveOfficer)) {
            return true;
        }

        if ($user->level === Level::SeniorOfficer) {
            // The same narrowing ScopedQuery applies (Phase 8.5), read from
            // the same resolved field. These two answers must agree exactly:
            // a scope the query narrows but this does not is a member an
            // officer can act on and cannot see.
            if ($user->scopeTeamIds !== []) {
                return $subject->teamId !== null
                    && in_array($subject->teamId, $user->scopeTeamIds, true);
            }

            return $user->scopeDivisionId !== null
                && $subject->divisionId === $user->scopeDivisionId;
        }

        if ($user->level === Level::Officer) {
            return $user->scopeTeamId !== null
                && $subject->teamId === $user->scopeTeamId;
        }

        // Member level holds no scoped capability; minimumLevel() already
        // refused. Reaching here means a new level was added without deciding
        // its scope, and the safe answer to an undecided question is no.
        return false;
    }
}
