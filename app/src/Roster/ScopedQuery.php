<?php

declare(strict_types=1);

namespace Rerm\Roster;

use Rerm\Auth\Level;
use Rerm\Auth\User;

/**
 * The ONE place a roster scope predicate is built (spec 4.3).
 *
 * Every roster read appends what forUser() returns, so a screen cannot
 * forget to filter — it never writes the WHERE clause itself. Phase 4 is the
 * first screen to use it; it is built and tested here so that screen cannot
 * ship without it.
 *
 * What the predicate always includes, before any scope:
 *
 *   * is_system = 0        — the seeded master administrator is an account,
 *                            not a committee member; no roster counts one
 *   * purged_at IS NULL    — a purge hides the member everywhere
 *   * dropped_since IS NULL — dropped members are out of rosters and
 *                            dashboards by default (spec 6.5)
 *
 * And the scope itself, from the signed-in user (spec 4.3):
 *
 *   Admin, Executive Officer   every member
 *   Senior Officer             division_id = theirs
 *   Officer                    team_id = theirs
 *   either of those two        team_id IN (their set), when an Admin has
 *                              recorded one — it wins over both rows above
 *   anything else              nothing — a predicate that is never true
 *
 * The user's division and team ids were resolved by User::fromRow — an
 * explicit Admin override on app_user, else their OWN member row, never the
 * team table (teams span divisions; docs/data-findings.md 4b). An Officer
 * whose scope resolves to no team, or a Senior Officer to no division, sees
 * nothing rather than everything: an unanswerable "which team?" must not
 * widen into "every team".
 */
final class ScopedQuery
{
    /**
     * @param array<string, string|int> $bindings
     */
    private function __construct(
        private readonly string $predicate,
        private readonly array $bindings,
    ) {
    }

    /**
     * @param string $alias the member table's alias in the caller's FROM
     */
    public static function forUser(User $user, string $alias = 'm'): self
    {
        return self::scoped($user, $alias, self::visible($alias));
    }

    /**
     * The same scope, over the members every other read HIDES (Phase 8.5).
     *
     * A dropped member is excluded by visible(), which is what makes "who
     * fell off my team's roster" a question no screen could ask until now.
     * This answers it, with the scope predicate unchanged: an Officer sees
     * the people dropped from their own team and nobody else's.
     *
     * **A separate method rather than a flag on forUser(), deliberately.** A
     * boolean parameter is exactly how an ordinary roster read one day starts
     * including dropped members: somebody threads it through from a caller
     * two layers up, every test still passes, and the roster quietly grows
     * people who are not on it. A method with its own name cannot be reached
     * by accident, and `grep droppedForUser` lists every screen that sees
     * them.
     *
     * Purged members and the system row stay excluded. Purge is a different
     * state with its own screen (spec 6.5), and conflating the two is the one
     * thing the Phase 8.5 rename made easier to do by mistake.
     */
    public static function droppedForUser(User $user, string $alias = 'm'): self
    {
        return self::scoped($user, $alias, self::dropped($alias));
    }

    /**
     * The scope half (spec 4.3), applied to whichever base predicate the
     * caller is asking about. One implementation, so a screen looking at
     * dropped members can never be scoped differently from one looking at
     * present ones.
     */
    private static function scoped(User $user, string $alias, string $base): self
    {
        if ($user->level->atLeast(Level::ExecutiveOfficer)) {
            return new self($base, []);
        }

        // A team set NARROWS an Officer or a Senior Officer to those teams
        // (Phase 8.5, widened to Officers in 8.6). Resolved in User::fromRow
        // so this and Access::inScope() cannot disagree; empty here means
        // "not narrowed by a set", never "no teams".
        if ($user->scopeTeamIds !== []
            && ($user->level === Level::SeniorOfficer || $user->level === Level::Officer)
        ) {
            $places = [];
            $bind   = [];
            foreach (array_values($user->scopeTeamIds) as $i => $teamId) {
                $places[]                  = ":scoped_team_{$i}";
                $bind[":scoped_team_{$i}"] = $teamId;
            }

            return new self(
                "{$base} AND {$alias}.team_id IN (" . implode(', ', $places) . ')',
                $bind
            );
        }

        if ($user->level === Level::SeniorOfficer) {
            return $user->scopeDivisionId === null
                ? self::nobody($base)
                : new self(
                    "{$base} AND {$alias}.division_id = :scoped_division_id",
                    [':scoped_division_id' => $user->scopeDivisionId]
                );
        }

        if ($user->level === Level::Officer) {
            return $user->scopeTeamId === null
                ? self::nobody($base)
                : new self(
                    "{$base} AND {$alias}.team_id = :scoped_team_id",
                    [':scoped_team_id' => $user->scopeTeamId]
                );
        }

        // Member level holds no roster capability; a query built for one
        // anyway returns no rows rather than throwing, because the guard that
        // should have refused the screen is Access's job, not this one's.
        return self::nobody($base);
    }

    /**
     * The three columns that decide whether a member is DROPPED rather than
     * present: not the system row, not purged, and carrying the import that
     * stopped listing them.
     *
     * `purged_at IS NULL` is not redundant beside it. A member can be both —
     * dropped by an import and then purged by an Admin — and once purged they
     * belong on the purge screen, not here.
     *
     * @param string $alias the member table's alias in the caller's FROM
     */
    public static function dropped(string $alias = 'm'): string
    {
        self::assertAlias($alias);

        return "{$alias}.is_system = 0 AND {$alias}.purged_at IS NULL "
            . "AND {$alias}.dropped_since_import_id IS NOT NULL";
    }

    /**
     * The visibility half, alone: system rows, purges and absence flags, with
     * no scope at all.
     *
     * Public because the roster is not the only thing that has to respect it.
     * Phase 6 asks the same question of a member being considered as an
     * OFFICER — a purged or dropped one is no more assignable than they are
     * visible — and a second spelling of these three columns is a second
     * place for a purged member to keep holding twenty people.
     *
     * @param string $alias the member table's alias in the caller's FROM
     */
    public static function visible(string $alias = 'm'): string
    {
        self::assertAlias($alias);

        return "{$alias}.is_system = 0 AND {$alias}.purged_at IS NULL "
            . "AND {$alias}.dropped_since_import_id IS NULL";
    }

    /**
     * The alias reaches the SQL string, so it is held to identifier
     * characters however unlikely a dynamic value is. Everything the USER
     * controls travels as a binding.
     */
    private static function assertAlias(string $alias): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException("'{$alias}' is not a table alias.");
        }
    }

    private static function nobody(string $visible): self
    {
        return new self("{$visible} AND 1 = 0", []);
    }

    /** Append with AND to the caller's own conditions. */
    public function predicate(): string
    {
        return $this->predicate;
    }

    /**
     * Merge into execute()'s bindings. The names are prefixed `scoped_` so
     * they cannot collide with a caller's own, and each appears exactly once
     * — a named placeholder cannot be reused within one statement here.
     *
     * @return array<string, string|int>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }
}
