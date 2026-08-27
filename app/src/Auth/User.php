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
    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly string $memberNumber,
        public readonly Level $level,
        public readonly ?int $scopeDivisionId,
        public readonly ?int $scopeTeamId,
        public readonly bool $mustChangePassword,
        public readonly string $displayName,
    ) {
    }

    /**
     * From the app_user ⋈ member row Rerm\Auth\Auth selects.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
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

        return new self(
            (int) $row['id'],
            (int) $row['member_id'],
            (string) $row['member_number'],
            Level::from((string) $row['effective_level']),
            $division,
            $team,
            (int) $row['must_change_password'] === 1,
            $name !== '' ? $name : (string) $row['member_number'],
        );
    }
}
