<?php

declare(strict_types=1);

namespace Rerm\Auth;

/**
 * The member an action is about.
 *
 * A scoped capability requires one (spec 4.5): "may this officer log a
 * contact?" without naming the member is not a question with an answer, and
 * Access::allows() denies when handed null. This is the shape it wants —
 * where the member sits, read from THEIR row, never from the team table.
 */
final class Subject
{
    public function __construct(
        public readonly int $memberId,
        public readonly ?int $divisionId,
        public readonly ?int $teamId,
    ) {
    }

    /** @param array<string, mixed> $row a member row, or any join carrying its columns */
    public static function fromMemberRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['division_id'] !== null ? (int) $row['division_id'] : null,
            $row['team_id'] !== null ? (int) $row['team_id'] : null,
        );
    }
}
