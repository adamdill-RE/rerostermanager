<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;
use Rerm\Auth\Level;

/**
 * The ONE place "is this member an officer who may hold assignments on this
 * team" is answered (Phase 6 decided 1 and 3).
 *
 * Two screens' worth of code asks it in three shapes and every one of them
 * has to agree, or the Assign screen offers an officer the write path then
 * refuses:
 *
 *   * the picker — who may be assigned TO, on this team, with their load
 *   * bucket 2 — an existing assignment whose officer stopped qualifying,
 *     detected from CURRENT data on every render, never from a stored flag,
 *     because the thing that broke it was an import nobody watched (spec 6.6)
 *   * the write — the target officer verified again, server-side, however
 *     the request arrived
 *
 * Eligible means, exactly: a member row that is visible (not the system
 * account, not purged, not absent-flagged — ScopedQuery::visible(), the same
 * three columns every roster read uses), who is ON the member's team, and
 * whose EFFECTIVE level is Officer or above.
 *
 * Effective level is `granted_level ?? title_level` — the schema's own rule
 * (app_user.effective_level), read here off the member row so that a member
 * with no account at all still has an answer. So an Allowed User grant keeps
 * a demoted Captain assignable, exactly as it keeps their login, and a
 * demotion with no grant behind it takes them out of the picker on the next
 * render.
 *
 * Two things are deliberately NOT part of it:
 *
 *   * The team table's display-grouping column. It is seeded by a prefix
 *     heuristic and editable by an Admin, so an eligibility that read it
 *     would move with a cosmetic change. A test holds this file's SOURCE
 *     clean of it, comments included, exactly as one does for Access.
 *   * `app_user.is_active`. Assignments reference MEMBER rows because they
 *     outlive logins (spec 5.2): whether somebody can sign in is a different
 *     question from whether they are the Captain of this team.
 *
 * **Rank comparison lives in PHP, never in SQL.** The level column is an
 * ENUM declared low to high, so `>= 'officer'` in a WHERE clause compares
 * strings — where 'admin' sorts below 'officer' and the Chairman stops being
 * an officer. Level::atLeast() decides which values qualify, in PHP, and SQL
 * only ever gets the resulting IN list.
 */
final class EligibleOfficers
{
    /** The floor. Everything at or above it may hold assignments. */
    public const FLOOR = Level::Officer;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The level values that qualify, decided by Level::atLeast() and never by
     * a string comparison in SQL.
     *
     * @return array<int, string>
     */
    public static function levelValues(): array
    {
        $values = [];
        foreach (Level::cases() as $level) {
            if ($level->atLeast(self::FLOOR)) {
                $values[] = $level->value;
            }
        }

        return $values;
    }

    /**
     * `granted_level ?? title_level`, as SQL — the schema's rule, spelled
     * once here for queries that read a member who may have no account.
     */
    public static function effectiveLevel(string $memberAlias, string $userAlias): string
    {
        return 'COALESCE(' . self::identifier($userAlias) . '.granted_level, '
            . self::identifier($memberAlias) . '.title_level)';
    }

    /**
     * The IN list for the qualifying levels, as uniquely named placeholders —
     * a named placeholder cannot be reused within one statement here, so
     * every use of this fragment in a query needs its own prefix.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function levelIn(string $prefix): array
    {
        $places = [];
        $bind   = [];
        foreach (self::levelValues() as $i => $value) {
            $name          = ':' . self::identifier($prefix) . "_lvl_{$i}";
            $places[]      = $name;
            $bind[$name]   = $value;
        }

        return ['(' . implode(', ', $places) . ')', $bind];
    }

    /**
     * "This assignment's officer is no longer eligible for the member it
     * points at" — decided 3, as one SQL predicate.
     *
     * Written as NOT EXISTS over the officer's own row rather than as three
     * separate tests, so demoted, moved-team, purged and absent are one
     * question with one answer: can this officer still be found, on this
     * member's team, at Officer level or above?
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function assignmentIsBroken(
        string $assignAlias,
        string $memberAlias,
        string $prefix
    ): array {
        $p  = self::identifier($prefix);
        $om = "{$p}_om";
        $au = "{$p}_au";

        [$in, $bind] = self::levelIn($prefix);

        $sql = "NOT EXISTS (SELECT 1 FROM member {$om}"
            . " LEFT JOIN app_user {$au} ON {$au}.member_id = {$om}.id"
            . " WHERE {$om}.id = " . self::identifier($assignAlias) . '.officer_member_id'
            . ' AND ' . ScopedQuery::visible($om)
            . " AND {$om}.team_id = " . self::identifier($memberAlias) . '.team_id'
            . ' AND ' . self::effectiveLevel($om, $au) . " IN {$in})";

        return [$sql, $bind];
    }

    /**
     * "This member holds at least one current assignment whose officer is no
     * longer eligible" — bucket 2's membership test.
     *
     * @return array{0: string, 1: array<string, string|int>}
     */
    public static function memberHasBrokenAssignment(
        string $memberAlias,
        string $prefix,
        int $showYearId
    ): array {
        $p = self::identifier($prefix);
        $a = "{$p}_a";

        [$broken, $bind] = self::assignmentIsBroken($a, $memberAlias, $prefix);

        $sql = "EXISTS (SELECT 1 FROM assignment {$a}"
            . " WHERE {$a}.member_id = " . self::identifier($memberAlias) . '.id'
            . " AND {$a}.show_year_id = :{$p}_year AND {$a}.removed_at IS NULL"
            . " AND {$broken})";

        return [$sql, $bind + [":{$p}_year" => $showYearId]];
    }

    /**
     * "This member holds at least one current assignment", eligible or not.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public static function memberHasAssignment(
        string $memberAlias,
        string $prefix,
        int $showYearId
    ): array {
        $p = self::identifier($prefix);
        $a = "{$p}_a";

        $sql = "EXISTS (SELECT 1 FROM assignment {$a}"
            . " WHERE {$a}.member_id = " . self::identifier($memberAlias) . '.id'
            . " AND {$a}.show_year_id = :{$p}_year AND {$a}.removed_at IS NULL)";

        return [$sql, [":{$p}_year" => $showYearId]];
    }

    /**
     * The picker for one team: every eligible officer, with the load the
     * humans balance by hand (spec 7.4 — "A. Rivera — 14 assigned"). No
     * auto-balancing; the number is the whole mechanism.
     *
     * The load counts ALL of the officer's current assignments this show
     * year, not just the ones the viewer can see: an officer already holding
     * forty people is holding forty people whoever is looking.
     *
     * A team spanning two divisions (7 of the 96 do) may list an officer the
     * viewer's own scope would not show them. That is deliberate and it is
     * the reason the list is built from the TEAM rather than from the
     * viewer's scope — otherwise a member on a split team could be visible,
     * assignable by capability, and have nobody to assign.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forTeam(int $teamId, int $showYearId): array
    {
        [$in, $bind] = self::levelIn('pick');

        $read = $this->pdo->prepare(
            'SELECT om.id, om.member_number, om.first_name, om.last_name, om.preferred_name,'
            . ' om.title, ' . self::effectiveLevel('om', 'au') . ' AS effective_level,'
            . ' (SELECT COUNT(*) FROM assignment la WHERE la.officer_member_id = om.id'
            . '   AND la.show_year_id = :pick_year AND la.removed_at IS NULL) AS assigned_count'
            . ' FROM member om LEFT JOIN app_user au ON au.member_id = om.id'
            . ' WHERE om.team_id = :pick_team AND ' . ScopedQuery::visible('om')
            . ' AND ' . self::effectiveLevel('om', 'au') . " IN {$in}"
            . ' ORDER BY om.last_name, om.first_name, om.id'
        );
        $read->execute($bind + [':pick_team' => $teamId, ':pick_year' => $showYearId]);

        $officers = [];
        foreach ($read->fetchAll() as $row) {
            $officers[] = [
                'id'              => (int) $row['id'],
                'member_number'   => (string) $row['member_number'],
                'name'            => RosterPage::displayName(
                    (string) $row['preferred_name'],
                    (string) $row['first_name'],
                    (string) $row['last_name'],
                    (string) $row['member_number']
                ),
                'title'           => (string) $row['title'],
                'level'           => Level::from((string) $row['effective_level']),
                'assigned_count'  => (int) $row['assigned_count'],
            ];
        }

        return $officers;
    }

    /**
     * How many eligible officers each team has, keyed by team id — bucket 3's
     * numerator, and the reason a team appears in it at all.
     *
     * Every team, not only the viewer's: the count is a fact about the team,
     * and the write path enforces the same fact.
     *
     * @return array<int, int>
     */
    public function countsByTeam(): array
    {
        [$in, $bind] = self::levelIn('cnt');

        $read = $this->pdo->prepare(
            'SELECT om.team_id, COUNT(*) AS officers'
            . ' FROM member om LEFT JOIN app_user au ON au.member_id = om.id'
            . ' WHERE om.team_id IS NOT NULL AND ' . ScopedQuery::visible('om')
            . ' AND ' . self::effectiveLevel('om', 'au') . " IN {$in}"
            . ' GROUP BY om.team_id'
        );
        $read->execute($bind);

        $counts = [];
        foreach ($read->fetchAll() as $row) {
            $counts[(int) $row['team_id']] = (int) $row['officers'];
        }

        return $counts;
    }

    /**
     * The current assignments for a set of members, each carrying whether its
     * officer is STILL eligible for that member — the one query bucket 2, the
     * row display and the re-point write all read.
     *
     * The officer's row is joined WITHOUT the visibility filter on purpose: a
     * purged or absent officer still holds these people, and hiding the row is
     * how twenty members stop being chased with nobody noticing (spec 6.6).
     * The flag says the assignment is broken; the name is still there so
     * somebody can re-point it.
     *
     * @param array<int, int> $memberIds
     * @return array<int, array<int, array<string, mixed>>> keyed by member id
     */
    public function currentAssignments(array $memberIds, int $showYearId): array
    {
        if ($memberIds === []) {
            return [];
        }

        [$places, $bind] = MemberReads::idList($memberIds, 'held_member');
        [$in, $bindIn]   = self::levelIn('held');

        $read = $this->pdo->prepare(
            'SELECT a.id, a.member_id, a.officer_member_id,'
            . ' om.member_number AS officer_number, om.first_name AS officer_first,'
            . ' om.last_name AS officer_last, om.preferred_name AS officer_preferred,'
            . ' om.title AS officer_title,'
            . ' (CASE WHEN ' . ScopedQuery::visible('om')
            . '   AND om.team_id = m.team_id'
            . '   AND ' . self::effectiveLevel('om', 'au') . " IN {$in}"
            . '   THEN 1 ELSE 0 END) AS officer_eligible'
            . ' FROM assignment a'
            . ' INNER JOIN member m ON m.id = a.member_id'
            . ' INNER JOIN member om ON om.id = a.officer_member_id'
            . ' LEFT JOIN app_user au ON au.member_id = om.id'
            . ' WHERE a.show_year_id = :held_year AND a.removed_at IS NULL'
            . "   AND a.member_id IN ({$places})"
            . ' ORDER BY om.last_name, om.first_name, om.id'
        );
        $read->execute($bind + $bindIn + [':held_year' => $showYearId]);

        $byMember = [];
        foreach ($read->fetchAll() as $row) {
            $byMember[(int) $row['member_id']][] = [
                'assignment_id'     => (int) $row['id'],
                'officer_member_id' => (int) $row['officer_member_id'],
                'name'              => RosterPage::displayName(
                    (string) $row['officer_preferred'],
                    (string) $row['officer_first'],
                    (string) $row['officer_last'],
                    (string) $row['officer_number']
                ),
                'title'    => (string) $row['officer_title'],
                'eligible' => (int) $row['officer_eligible'] === 1,
            ];
        }

        return $byMember;
    }

    /**
     * One officer, verified — the write path's question, asked of the
     * database rather than of the form that offered the name.
     *
     * Returns the officer's row with their team and effective level, or null
     * when they are not a visible member at all. Whether that team is the
     * right one is the caller's check, per member, because the same officer
     * is eligible for their own team and refused for every other.
     *
     * @return ?array{id: int, team_id: ?int, name: string, level: Level, eligible: bool}
     */
    public function officer(int $officerMemberId): ?array
    {
        $read = $this->pdo->prepare(
            'SELECT om.id, om.team_id, om.member_number, om.first_name, om.last_name,'
            . ' om.preferred_name, ' . self::effectiveLevel('om', 'au') . ' AS effective_level'
            . ' FROM member om LEFT JOIN app_user au ON au.member_id = om.id'
            . ' WHERE om.id = :officer AND ' . ScopedQuery::visible('om')
        );
        $read->execute([':officer' => $officerMemberId]);
        $row = $read->fetch();

        if (!is_array($row)) {
            return null;
        }

        $level = Level::from((string) $row['effective_level']);

        return [
            'id'      => (int) $row['id'],
            'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
            'name'    => RosterPage::displayName(
                (string) $row['preferred_name'],
                (string) $row['first_name'],
                (string) $row['last_name'],
                (string) $row['member_number']
            ),
            'level'    => $level,
            // Level::atLeast(), in PHP, exactly as levelValues() decides the
            // IN list the queries above use.
            'eligible' => $level->atLeast(self::FLOOR),
        ];
    }

    /** A table alias or placeholder prefix, held to identifier characters. */
    private static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not an identifier.");
        }

        return $name;
    }
}
