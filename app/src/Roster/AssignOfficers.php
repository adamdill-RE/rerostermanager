<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;
use PDOException;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Subject;
use Rerm\Auth\User;

/**
 * The Assign Officers writes (spec 7.4, Phase 6 decided 1–4) — shaped like
 * Rerm\Roster\LogContact, because it is the same shape of question asked of
 * more people at once: route-shaped untrusted input, a fresh show-year read
 * before anything writes, Access::allows() with a Subject built from EACH
 * member's own row, and a small outcome vocabulary the handler turns into a
 * flash and a 303.
 *
 * Three actions, one entry point:
 *
 *   assign                 the selected members get the chosen officer
 *   assign_all_unassigned  the server resolves the set — every unassigned
 *                          member on the officer's own team, in the actor's
 *                          scope — so max_input_vars is not involved at all
 *   remove                 the selected members lose one officer, or all of
 *                          them; removed_at is stamped, nothing is deleted
 *
 * What the bulk shape does NOT change (this is the whole point of the file):
 *
 *   * **Every member is checked individually.** Fifty ids in one POST are
 *     fifty Access::allows() calls against fifty Subjects. A selection is a
 *     convenience for the officer, never a way to act on somebody outside
 *     their scope, and out-of-scope ids are counted and skipped rather than
 *     failing the whole action — the other forty-nine were legitimate.
 *   * **The target officer is verified here, server-side** (decided 1 and 4):
 *     eligible by EligibleOfficers, and on the MEMBER'S OWN TEAM. Assignment
 *     is same-team only, and a cross-team target is refused however the
 *     request arrived — the picker offering it is not what makes it legal.
 *   * **The 1–3 cap is hard** (decided 2, OI-11). A fourth assignment is
 *     refused per member with the existing three named, and in a bulk action
 *     the members at cap are skipped BY NAME while the rest still land.
 *     Never a silent trim.
 *   * **Re-pointing is one action** (decided 3): assigning a replacement to a
 *     member whose officer is no longer eligible stamps removed_at on THAT
 *     assignment in the same transaction. Only the broken row is touched — a
 *     member whose other officer is still valid keeps that officer.
 *
 * `contact_log` is not read, written or considered by anything here.
 */
final class AssignOfficers
{
    /** The actions a request may name. Anything else is refused unread. */
    public const ACTIONS = ['assign', 'assign_all_unassigned', 'remove'];

    /**
     * Every outcome apply() can return. Declared rather than implied, because
     * the handler turns each one into a sentence and an outcome it does not
     * know about would reach the officer as the wrong sentence — a refusal
     * read as "nobody was selected" is a bug report nobody files.
     * tests/assign_test.php transcribes this list and holds the handler to it.
     */
    public const OUTCOMES = [
        'assigned', 'removed', 'nothing_selected', 'nothing_to_do', 'refused_all',
        'bad_officer', 'bad_action', 'too_many', 'no_year', 'year_closed',
    ];

    /** The remove picker's "every current officer" choice. */
    public const REMOVE_ALL = 'all';

    /**
     * The most member ids one POST may carry. The screen offers 50 or 100, so
     * this is generous — and it REFUSES rather than truncating, because
     * silent truncation is exactly what max_input_vars already does to this
     * host and once is enough.
     */
    public const MAX_SELECTION = 200;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxOfficers,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db(), (int) $app->config()->get('roster.max_officers_per_member', 3));
    }

    /**
     * $input is the POST body, route-shaped and untrusted:
     *
     *   action             one of ACTIONS
     *   officer_member_id  the officer to assign to
     *   remove_officer_id  the officer to remove from — REMOVE_ALL removes
     *                      every current officer. A SECOND name rather than
     *                      the same one: with no JavaScript both selects sit
     *                      in the same form (they act on the same selection),
     *                      and two controls sharing a name overwrite each
     *                      other on submit
     *   member_id[]        the selected members (not read by
     *                      assign_all_unassigned, which resolves its own set)
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> outcome: assigned | removed | nothing_selected
     *         | nothing_to_do | refused_all | bad_officer | bad_action
     *         | too_many | no_year | year_closed
     */
    public function apply(User $user, array $input): array
    {
        $none = [
            'action'        => '',
            'officer_name'  => '',
            'officer_load'  => 0,
            'assigned'      => 0,
            'already'       => 0,
            'repointed'     => 0,
            'removed'       => 0,
            'refused'       => 0,
            'cross_team'    => 0,
            'at_cap'        => [],
        ];

        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        if (!in_array($action, self::ACTIONS, true)) {
            return ['outcome' => 'bad_action'] + $none;
        }
        $none['action'] = $action;

        // The active show year, read fresh on every write: an Admin closing
        // the year makes it read-only from that moment, not from the next
        // deploy (spec 5.1). The schema does not enforce it; this does.
        $year = $this->pdo->query('SELECT id, is_open FROM show_year WHERE is_active = 1')->fetch();
        if (!is_array($year)) {
            return ['outcome' => 'no_year'] + $none;
        }
        if ((int) $year['is_open'] !== 1) {
            return ['outcome' => 'year_closed'] + $none;
        }
        $showYearId = (int) $year['id'];

        if ($action === 'remove') {
            return $this->remove($user, $input, $showYearId, $none);
        }

        return $this->assign($user, $input, $showYearId, $action === 'assign_all_unassigned', $none);
    }

    /**
     * Assign one officer to many members — additive, so a member accumulates
     * one to three officers and a team's convention (a Captain and an
     * Assistant Captain each, say) is repeated passes of this one action
     * rather than a mode of it. Nothing about the convention is stored.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $none
     * @return array<string, mixed>
     */
    private function assign(User $user, array $input, int $showYearId, bool $everyone, array $none): array
    {
        $officers = new EligibleOfficers($this->pdo);

        $officer = $officers->officer((int) ($input['officer_member_id'] ?? 0));
        if ($officer === null || !$officer['eligible'] || $officer['team_id'] === null) {
            return ['outcome' => 'bad_officer'] + $none;
        }
        $none['officer_name'] = $officer['name'];

        if ($everyone) {
            // The team is the OFFICER'S team, never a team named by the
            // request: same-team is the rule (decided 4), so there is exactly
            // one team this action can mean and no mismatch to get wrong.
            $ids = $this->unassignedOnTeam($user, $officer['team_id'], $showYearId);
        } else {
            $ids = self::memberIds($input['member_id'] ?? []);
            if (count($ids) > self::MAX_SELECTION) {
                return ['outcome' => 'too_many'] + $none;
            }
        }

        if ($ids === []) {
            return ['outcome' => $everyone ? 'nothing_to_do' : 'nothing_selected'] + $none;
        }

        // Each id, individually: the member must exist, be visible, be inside
        // this user's scope, and be on the officer's team.
        $members    = $this->membersById($ids);
        $allowed    = [];
        $refused    = 0;
        $crossTeam  = 0;

        foreach ($ids as $id) {
            $row = $members[$id] ?? null;
            if ($row === null
                || !Access::allows($user, Capability::AssignOfficers, Subject::fromMemberRow($row))
            ) {
                $refused++;
                continue;
            }
            if ($row['team_id'] === null || (int) $row['team_id'] !== $officer['team_id']) {
                $crossTeam++;
                continue;
            }
            $allowed[$id] = $row;
        }

        $none['refused']    = $refused;
        $none['cross_team'] = $crossTeam;

        if ($allowed === []) {
            return ['outcome' => 'refused_all'] + $none;
        }

        // Everything is DECIDED before anything is written, so a half-valid
        // request never half-applies — the same rule LogContact follows for
        // its progress changes, and here it is what keeps a member at cap
        // from losing their broken assignment without gaining a replacement.
        $current  = $officers->currentAssignments(array_keys($allowed), $showYearId);
        $toRemove = [];
        $toInsert = [];
        $already  = 0;
        $atCap    = [];

        foreach ($allowed as $id => $row) {
            $held   = $current[$id] ?? [];
            $broken = [];
            $keep   = [];
            $hasTarget = false;

            foreach ($held as $assignment) {
                if ($assignment['eligible']) {
                    $keep[] = $assignment;
                    if ($assignment['officer_member_id'] === $officer['id']) {
                        $hasTarget = true;
                    }
                    continue;
                }
                $broken[] = $assignment;
            }

            // Decided 2: the cap counts the assignments that would REMAIN.
            // A member already holding the target officer is never at cap —
            // nothing is being added — and one whose broken row is about to
            // be replaced nets the same count they started with.
            if (!$hasTarget && count($keep) >= $this->maxOfficers) {
                $atCap[] = [
                    'name'     => RosterPage::displayName(
                        (string) $row['preferred_name'],
                        (string) $row['first_name'],
                        (string) $row['last_name'],
                        (string) $row['member_number']
                    ),
                    'officers' => array_map(
                        static fn (array $a): string => (string) $a['name'],
                        $keep
                    ),
                ];
                // Nothing at all is touched for this member — not even their
                // broken row. A re-point that cannot complete is not a
                // re-point, and half of one is a member with fewer officers
                // than they started with.
                continue;
            }

            foreach ($broken as $assignment) {
                $toRemove[] = [
                    'assignment_id'     => (int) $assignment['assignment_id'],
                    'member_id'         => $id,
                    'officer_member_id' => (int) $assignment['officer_member_id'],
                    'reason'            => 'repointed',
                ];
            }

            if ($hasTarget) {
                $already++;
                continue;
            }
            $toInsert[] = $id;
        }

        $assigned  = 0;
        $repointed = 0;
        $audit     = [];

        $this->pdo->beginTransaction();
        try {
            $repointed = $this->stampRemoved($toRemove, $showYearId, $audit);

            $insert = $this->pdo->prepare(
                'INSERT INTO assignment (member_id, officer_member_id, show_year_id, assigned_by, assigned_at)'
                . ' VALUES (:member, :officer, :year, :by, UTC_TIMESTAMP())'
            );

            foreach ($toInsert as $id) {
                try {
                    $insert->execute([
                        ':member'  => $id,
                        ':officer' => $officer['id'],
                        ':year'    => $showYearId,
                        ':by'      => $user->id,
                    ]);
                } catch (PDOException $e) {
                    // uq_assignment_current over the is_current virtual
                    // column: this member already has this officer, live.
                    // Somebody else got there first, which is the outcome
                    // this request wanted — a no-op, never an error.
                    if (!self::isDuplicate($e)) {
                        throw $e;
                    }
                    $already++;
                    continue;
                }

                $assigned++;
                $audit[] = [
                    'action'    => Action::AssignOfficer,
                    'entity_id' => (string) $this->pdo->lastInsertId(),
                    'before'    => null,
                    'after'     => [
                        'member_id'         => $id,
                        'officer_member_id' => $officer['id'],
                        'show_year_id'      => $showYearId,
                    ],
                ];
            }

            $this->writeAudit($user, $audit);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'outcome'      => 'assigned',
            'action'       => $none['action'],
            'officer_name' => $officer['name'],
            'officer_load' => $this->loadFor($officer['id'], $showYearId),
            'assigned'     => $assigned,
            'already'      => $already,
            'repointed'    => $repointed,
            'removed'      => 0,
            'refused'      => $refused,
            'cross_team'   => $crossTeam,
            'at_cap'       => $atCap,
        ];
    }

    /**
     * Remove the selected members from one officer, or from every officer
     * they currently hold. `removed_at` is stamped and the row stays: "who
     * was supposed to be calling this member in February" has to stay
     * answerable, and there is no removed_by column because the audit row IS
     * the attribution.
     *
     * The officer being removed FROM is not required to be eligible — the
     * ineligible ones are the likeliest thing anybody removes.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $none
     * @return array<string, mixed>
     */
    private function remove(User $user, array $input, int $showYearId, array $none): array
    {
        $target = is_scalar($input['remove_officer_id'] ?? null)
            ? (string) $input['remove_officer_id']
            : '';
        $everyOfficer = $target === self::REMOVE_ALL;
        $officerId    = $everyOfficer ? 0 : (int) $target;

        if (!$everyOfficer && $officerId <= 0) {
            return ['outcome' => 'bad_officer'] + $none;
        }

        $ids = self::memberIds($input['member_id'] ?? []);
        if (count($ids) > self::MAX_SELECTION) {
            return ['outcome' => 'too_many'] + $none;
        }
        if ($ids === []) {
            return ['outcome' => 'nothing_selected'] + $none;
        }

        $members = $this->membersById($ids);
        $allowed = [];
        $refused = 0;

        foreach ($ids as $id) {
            $row = $members[$id] ?? null;
            if ($row === null
                || !Access::allows($user, Capability::AssignOfficers, Subject::fromMemberRow($row))
            ) {
                $refused++;
                continue;
            }
            $allowed[$id] = $row;
        }

        $none['refused'] = $refused;

        if ($allowed === []) {
            return ['outcome' => 'refused_all'] + $none;
        }

        $current  = (new EligibleOfficers($this->pdo))
            ->currentAssignments(array_keys($allowed), $showYearId);
        $toRemove = [];
        $name     = '';

        foreach ($current as $memberId => $held) {
            foreach ($held as $assignment) {
                if (!$everyOfficer && (int) $assignment['officer_member_id'] !== $officerId) {
                    continue;
                }
                $name       = $everyOfficer ? '' : (string) $assignment['name'];
                $toRemove[] = [
                    'assignment_id'     => (int) $assignment['assignment_id'],
                    'member_id'         => (int) $memberId,
                    'officer_member_id' => (int) $assignment['officer_member_id'],
                    'reason'            => 'removed',
                ];
            }
        }

        if ($toRemove === []) {
            return ['outcome' => 'nothing_to_do'] + $none;
        }

        $removed = 0;
        $audit   = [];

        $this->pdo->beginTransaction();
        try {
            $removed = $this->stampRemoved($toRemove, $showYearId, $audit);
            $this->writeAudit($user, $audit);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'outcome'      => 'removed',
            'action'       => $none['action'],
            'officer_name' => $name,
            'officer_load' => $everyOfficer ? 0 : $this->loadFor($officerId, $showYearId),
            'assigned'     => 0,
            'already'      => 0,
            'repointed'    => 0,
            'removed'      => $removed,
            'refused'      => $refused,
            'cross_team'   => 0,
            'at_cap'       => [],
        ];
    }

    /**
     * Stamp removed_at on assignments that are still current, collecting an
     * audit row for each one that actually moved. The WHERE re-checks
     * removed_at IS NULL, so a row somebody else removed between the read and
     * the write is counted as already done rather than stamped twice.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $audit collected by reference
     */
    private function stampRemoved(array $rows, int $showYearId, array &$audit): int
    {
        if ($rows === []) {
            return 0;
        }

        $update = $this->pdo->prepare(
            'UPDATE assignment SET removed_at = UTC_TIMESTAMP()'
            . ' WHERE id = :id AND removed_at IS NULL'
        );

        $stamped = 0;
        foreach ($rows as $row) {
            $update->execute([':id' => $row['assignment_id']]);
            if ($update->rowCount() < 1) {
                continue;
            }

            $stamped++;
            $audit[] = [
                'action'    => Action::RemoveAssignment,
                'entity_id' => (string) $row['assignment_id'],
                'before'    => [
                    'member_id'         => $row['member_id'],
                    'officer_member_id' => $row['officer_member_id'],
                    'show_year_id'      => $showYearId,
                ],
                'after'     => ['removed' => true, 'reason' => $row['reason']],
            ];
        }

        return $stamped;
    }

    /**
     * The paper trail. There is no removed_by column on assignment by design
     * (Phase 6): the audit row carries the actor, the member, the officer and
     * the time, and it is append-only, which a column on a row that can be
     * superseded is not.
     *
     * Since Phase 8 the INSERT itself lives in Rerm\Audit\AuditLog — one
     * spelling of the JSON encoding and the actor, shared with the six
     * screens that phase added.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeAudit(User $user, array $rows): void
    {
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'action'    => $row['action'],
                'entity'    => 'assignment',
                'entity_id' => (string) $row['entity_id'],
                'before'    => $row['before'],
                'after'     => $row['after'],
            ];
        }

        (new AuditLog($this->pdo))->recordMany($user, $entries);
    }

    /** How many members this officer currently holds this show year. */
    private function loadFor(int $officerMemberId, int $showYearId): int
    {
        $read = $this->pdo->prepare(
            'SELECT COUNT(*) FROM assignment WHERE officer_member_id = :officer'
            . ' AND show_year_id = :year AND removed_at IS NULL'
        );
        $read->execute([':officer' => $officerMemberId, ':year' => $showYearId]);

        return (int) $read->fetchColumn();
    }

    /**
     * The member rows for a set of ids — visible ones only, and deliberately
     * WITHOUT a scope condition. Scope is Access's question, asked next
     * against each row, so the refusal is decided by the matrix rather than
     * by a query this class wrote.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> keyed by member id
     */
    private function membersById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$places, $bind] = MemberReads::idList($ids, 'pick_member');

        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.division_id, m.team_id FROM member m'
            . " WHERE m.id IN ({$places}) AND " . ScopedQuery::visible('m')
        );
        $read->execute($bind);

        $members = [];
        foreach ($read->fetchAll() as $row) {
            $members[(int) $row['id']] = $row;
        }

        return $members;
    }

    /**
     * Every member on this team, in this user's scope, with no current
     * officer — the quick action's set, resolved by the SERVER so that
     * "assign everyone unassigned" never becomes an eighty-five-input form
     * against max_input_vars 1000.
     *
     * @return array<int, int>
     */
    private function unassignedOnTeam(User $user, int $teamId, int $showYearId): array
    {
        $scoped = ScopedQuery::forUser($user);
        [$has, $bindHas] = EligibleOfficers::memberHasAssignment('m', 'ua', $showYearId);

        $read = $this->pdo->prepare(
            'SELECT m.id FROM member m WHERE ' . $scoped->predicate()
            . " AND m.team_id = :ua_team AND NOT {$has}"
            . ' ORDER BY m.last_name, m.first_name, m.id'
        );
        $read->execute($scoped->bindings() + $bindHas + [':ua_team' => $teamId]);

        return array_map(static fn (array $row): int => (int) $row['id'], $read->fetchAll());
    }

    /**
     * The member_id[] input as ints: digits only, de-duplicated, order kept.
     * NOT capped here — the caller refuses an oversized selection outright,
     * because a bulk action that quietly drops half a team is the failure
     * mode this whole screen was designed around.
     *
     * @return array<int, int>
     */
    private static function memberIds(mixed $input): array
    {
        $ids = [];
        foreach ((array) $input as $value) {
            if (!is_string($value) && !is_int($value)) {
                continue;
            }
            $value = (string) $value;
            if ($value !== '' && ctype_digit($value)) {
                $ids[(int) $value] = (int) $value;
            }
        }

        return array_values($ids);
    }

    /** A unique-key violation, as MySQL and MariaDB both spell it. */
    private static function isDuplicate(PDOException $e): bool
    {
        return $e->getCode() === '23000'
            && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
