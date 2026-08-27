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
 *   * absent_since IS NULL — flagged members are out of rosters and
 *                            dashboards by default (spec 6.5)
 *
 * And the scope itself, from the signed-in user (spec 4.3):
 *
 *   Admin, Executive Officer   every member
 *   Senior Officer             division_id = theirs
 *   Officer                    team_id = theirs
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
        // The alias reaches the SQL string, so it is held to identifier
        // characters however unlikely a dynamic value is. Everything the USER
        // controls travels as a binding.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException("'{$alias}' is not a table alias.");
        }

        $visible = "{$alias}.is_system = 0 AND {$alias}.purged_at IS NULL "
            . "AND {$alias}.absent_since_import_id IS NULL";

        if ($user->level->atLeast(Level::ExecutiveOfficer)) {
            return new self($visible, []);
        }

        if ($user->level === Level::SeniorOfficer) {
            return $user->scopeDivisionId === null
                ? self::nobody($visible)
                : new self(
                    "{$visible} AND {$alias}.division_id = :scoped_division_id",
                    [':scoped_division_id' => $user->scopeDivisionId]
                );
        }

        if ($user->level === Level::Officer) {
            return $user->scopeTeamId === null
                ? self::nobody($visible)
                : new self(
                    "{$visible} AND {$alias}.team_id = :scoped_team_id",
                    [':scoped_team_id' => $user->scopeTeamId]
                );
        }

        // Member level holds no roster capability; a query built for one
        // anyway returns no rows rather than throwing, because the guard that
        // should have refused the screen is Access's job, not this one's.
        return self::nobody($visible);
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
