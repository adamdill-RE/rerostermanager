<?php

declare(strict_types=1);

namespace Rerm\Auth;

/**
 * The signed-in user, as Access and every screen read them.
 *
 * Built from one JOIN of app_user and member, resolved once per request and
 * then immutable. Two rules from the spec are baked in here rather than
 * re-derived by callers:
 *
 *   * `level` is the EFFECTIVE level — app_user.effective_level, the VIRTUAL
 *     column the schema computes as granted_level ?? level. No PHP re-derives
 *     that, and nothing here can see the two inputs separately, so nothing
 *     here can get the coalesce wrong.
 *
 *   * Scope comes from the MEMBER record of the signed-in user, not from the
 *     team table — seven teams span two divisions, so division is a property
 *     of the person (spec 4.3). An explicit override on app_user, set by an
 *     Admin only, wins when present. fromRow() resolves both once, so the
 *     rest of the application only ever sees the answer.
 */
final class User
{
    /**
     * @param array<int, int> $scopeTeamIds the teams a Senior Officer is
     *        narrowed to. EMPTY means "not narrowed" — see fromRow().
     */
    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly string $memberNumber,
        public readonly Level $level,
        public readonly ?int $scopeDivisionId,
        public readonly ?int $scopeTeamId,
        public readonly bool $mustChangePassword,
        public readonly string $displayName,
        public readonly array $scopeTeamIds = [],
    ) {
    }

    /**
     * The teams a SENIOR OFFICER is narrowed to, or an empty list when they
     * are not narrowed at all (Phase 8.5).
     *
     * Resolved ONCE, here, so that `ScopedQuery` (which rows) and `Access`
     * (may they act on this member) cannot disagree about it. A scope the
     * query narrows but the access check does not is a member an officer can
     * edit and cannot see.
     *
     * Explicit always beats implicit, in this order:
     *
     *   1. an explicit team set, recorded by an Admin on Designate Users;
     *   2. an explicit division override on app_user, likewise — which means
     *      the Admin has already said "this division", so a title's default
     *      must not second-guess them;
     *   3. the title's own default breadth (TitleMap::breadth). Only
     *      `Vice Chairman` resolves to a team today, which is what keeps the
     *      21 promoted by this phase seeing exactly what they saw before.
     *
     * Anything below Senior Officer gets an empty list whatever the table
     * holds: an Officer's scope is their single team and this shape is not
     * offered to them (settled with the owner, 28 August). Executive Officer
     * and Admin see everything, so a narrowing would be meaningless — and
     * returning it anyway would put a WHERE clause on a query that should
     * have none.
     *
     * @param array<string, mixed> $row
     * @param array<int, int>      $teamScope
     * @return array<int, int>
     */
    private static function resolveTeamScope(
        Level $level,
        array $row,
        array $teamScope,
        ?int $ownTeam
    ): array {
        if ($level !== Level::SeniorOfficer) {
            return [];
        }

        // 1. Explicit, and the only branch that can name more than one team.
        $explicit = [];
        foreach ($teamScope as $teamId) {
            $teamId = (int) $teamId;
            if ($teamId > 0) {
                $explicit[$teamId] = $teamId;
            }
        }
        if ($explicit !== []) {
            return array_values($explicit);
        }

        // 2. An Admin has named a division for them; that is an answer.
        if (($row['scope_division_id'] ?? null) !== null) {
            return [];
        }

        // 3. The title's default. A Vice Chairman with no team of their own
        //    reaches nobody rather than everybody — an unanswerable "which
        //    team?" must not widen into "every team in the division", which
        //    is the same rule ScopedQuery applies to an Officer with no team.
        if (TitleMap::breadth((string) ($row['title'] ?? '')) === TitleMap::BREADTH_TEAM) {
            return $ownTeam !== null ? [$ownTeam] : [0];
        }

        return [];
    }

    /**
     * From the app_user ⋈ member row Rerm\Auth\Auth selects, plus the team
     * set that row cannot carry because it is a list.
     *
     * @param array<string, mixed> $row
     * @param array<int, int>      $teamScope rows of app_user_team for this user
     */
    public static function fromRow(array $row, array $teamScope = []): self
    {
        // Override ?? own record, per field. Explicitly, not with ??, because
        // the member columns are meaningful when the override is NULL and a
        // chained coalesce reads as though they were fallbacks of each other.
        $division = $row['scope_division_id'] !== null
            ? (int) $row['scope_division_id']
            : ($row['division_id'] !== null ? (int) $row['division_id'] : null);

        $team = $row['scope_team_id'] !== null
            ? (int) $row['scope_team_id']
            : ($row['team_id'] !== null ? (int) $row['team_id'] : null);

        $preferred = trim((string) ($row['preferred_name'] ?? ''));
        $first     = $preferred !== '' ? $preferred : trim((string) ($row['first_name'] ?? ''));
        $name      = trim($first . ' ' . trim((string) ($row['last_name'] ?? '')));

        $level = Level::from((string) $row['effective_level']);

        return new self(
            (int) $row['id'],
            (int) $row['member_id'],
            (string) $row['member_number'],
            $level,
            $division,
            $team,
            (int) $row['must_change_password'] === 1,
            $name !== '' ? $name : (string) $row['member_number'],
            self::resolveTeamScope($level, $row, $teamScope, $team),
        );
    }
}
