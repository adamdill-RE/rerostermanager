<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;
use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\TitleMap;
use Rerm\Auth\User;

/**
 * The Assign Officers read (spec 7.4): one team at a time, four buckets, and
 * the officer picker that carries each officer's current load.
 *
 * The four buckets are disjoint and cover the team, which is the property
 * that makes "every assignable member has 1–3 officers or a named reason"
 * checkable rather than asserted:
 *
 *   1. Unassigned          no current assignment at all
 *   2. Officer no longer   at least one current assignment whose officer was
 *      eligible            demoted, moved team, purged or dropped by an
 *                          import (spec 6.6). Above bucket 3 because it is
 *                          invisible work an import created
 *   3. No officer on this  a TEAM-level fact, not a member-level one: teams in
 *      team                scope with zero eligible officers, counted with
 *                          their members. Information, not a workflow — the
 *                          remedy is an Allowed User designation (decided 4)
 *   4. Assigned            has officers, none of them broken. Collapsed
 *
 * Bucket membership is computed from CURRENT data on every render and never
 * from a stored flag: the import that demoted the officer did not know it was
 * breaking anything, so there was nobody to set a flag.
 *
 * One bucket lists its rows at a time, with all four counts always shown in
 * bucket order. That is the byte budget doing the deciding (spec 10): a
 * checkbox row is ~30 bytes but 85 members across four open buckets with
 * their chips is not a 100KB page, and each bucket's action is different
 * anyway — assign, re-point, nothing, remove.
 *
 * Every row comes through ScopedQuery::forUser(); this class writes no
 * visibility or scope condition of its own. Eligibility comes through
 * EligibleOfficers, so the picker and the write path cannot disagree.
 */
final class AssignPage
{
    /** The buckets that list members. Bucket 3 is a team roll-up, not a list. */
    public const BUCKETS = ['unassigned', 'ineligible', 'assigned'];

    /** Pre-check states the URL may spell — a link cannot tick a checkbox. */
    private const SELECTIONS = ['all', 'outstanding'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $pageSizeDefault,
        private readonly int $pageSizeLarge,
        private readonly int $maxOfficers,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self(
            $app->db(),
            (int) $app->config()->get('roster.page_size_mobile', 50),
            (int) $app->config()->get('roster.page_size_desktop', 100),
            (int) $app->config()->get('roster.max_officers_per_member', 3),
        );
    }

    /**
     * Everything the screen needs. $input is the raw query string ($_GET),
     * untrusted and normalised here so the view renders only decided values.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function page(User $user, int $showYearId, array $input): array
    {
        $scoped = ScopedQuery::forUser($user);

        // teamsInScope() reads through ScopedQuery, so this is already every
        // team the caller can see — including the extra ones an Admin ticked
        // for an Officer (Phase 8.6).
        $teams = $this->teamsInScope($user, $showYearId);

        // Senior Officer and above always choose a team. An Officer normally
        // IS a team and gets no picker, because one they cannot use is a
        // control that lies about what the screen does — but an Officer
        // covering two teams has a real choice to make, and without this the
        // roster would show them Team E while Assign silently stayed on
        // Team A.
        $canChooseTeam = $user->level->atLeast(Level::SeniorOfficer) || count($teams) > 1;

        $teamId = null;
        if ($canChooseTeam) {
            $requested = (int) ($input['team'] ?? 0);
            // Chosen FROM the scoped list rather than validated against it: an
            // id that is not in scope is simply not one of the choices, so it
            // lands on the picker rather than on an error.
            foreach ($teams as $team) {
                if ((int) $team['id'] === $requested) {
                    $teamId = $requested;
                }
            }
        } elseif ($teams !== []) {
            // The one team they can see, which is their own for an ordinary
            // Officer and the single ticked one for a narrowed Officer. Taken
            // from the scoped list rather than from scopeTeamId so a set of
            // one wins over the member row, exactly as ScopedQuery has it.
            $teamId = (int) $teams[0]['id'];
        } elseif ($user->scopeTeamId !== null) {
            $teamId = $user->scopeTeamId;
        }

        // Bucket 3, across the whole scope: the teams that cannot cover their
        // own members, and the members on them. 41 of 96 teams in the real
        // roster have fewer than two officers and 7 have none — a quarter of
        // the teams, 432 members (docs/data-findings.md 7), so this is the
        // ordinary case and not an edge one.
        $thinTeams    = [];
        $thinMembers  = 0;
        foreach ($teams as $team) {
            if ((int) $team['officers'] === 0) {
                $thinTeams[]  = $team;
                $thinMembers += (int) $team['members'];
            }
        }

        $common = [
            'can_choose_team' => $canChooseTeam,
            'teams'           => $teams,
            'thin_teams'      => $thinTeams,
            'thin_members'    => $thinMembers,
            // A member with no team at all can never be assigned, because
            // assignment is same-team (decided 4). Counted rather than
            // silently absent: an unassignable member nobody can see is
            // exactly what bucket 3 exists to prevent.
            'no_team_members' => $this->membersWithoutTeam($user),
            'max_officers'    => $this->maxOfficers,
            'size_default'    => $this->pageSizeDefault,
            'size_large'      => $this->pageSizeLarge,
        ];

        if ($teamId === null) {
            // No team in view: the picker for Senior and above, an honest
            // empty state for an Officer with no team (an unanswerable "which
            // team?" reaches nobody rather than everybody — ScopedQuery's
            // rule, restated as a screen).
            return $common + [
                'team_id'    => null,
                'team_name'  => '',
                'bucket'     => 'unassigned',
                'counts'     => ['unassigned' => 0, 'ineligible' => 0, 'assigned' => 0, 'total' => 0],
                'rows'       => [],
                'officers'   => [],
                'holders'    => [],
                'total'      => 0,
                'page'       => 1,
                'pages'      => 1,
                'size'       => $this->pageSizeDefault,
                'from'       => 0,
                'to'         => 0,
                'sel'        => '',
            ];
        }

        $where = $scoped->predicate() . ' AND m.team_id = :assign_team';
        $bind  = $scoped->bindings() + [':assign_team' => $teamId];

        $bucket = is_string($input['bucket'] ?? null) && in_array($input['bucket'], self::BUCKETS, true)
            ? $input['bucket']
            : 'unassigned';

        $counts = $this->counts($where, $bind, $showYearId);

        // Page size is one of exactly two configured values, as everywhere.
        // Both are far below max_input_vars 1000: the form posts one checkbox
        // per row plus five fixed fields, so 100 rows is 105 inputs.
        $size = (int) ($input['size'] ?? $this->pageSizeDefault);
        if ($size !== $this->pageSizeDefault && $size !== $this->pageSizeLarge) {
            $size = $this->pageSizeDefault;
        }

        $total  = (int) $counts[$bucket];
        $pages  = max(1, (int) ceil($total / $size));
        $page   = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $size;

        $rows = $this->rows($where, $bind, $bucket, $showYearId, $size, $offset);

        $sel = is_string($input['sel'] ?? null) && in_array($input['sel'], self::SELECTIONS, true)
            ? $input['sel']
            : '';

        return $common + [
            'team_id'   => $teamId,
            'team_name' => $this->teamName($teams, $teamId),
            'bucket'    => $bucket,
            'counts'    => $counts,
            'rows'      => $rows,
            'officers'  => (new EligibleOfficers($this->pdo))->forTeam($teamId, $showYearId),
            // Who to remove FROM, in the buckets that have anybody to remove:
            // the officers actually holding members on this team, ineligible
            // ones included — removing them is the point.
            'holders'   => $bucket === 'unassigned' ? [] : $this->holders($where, $bind, $showYearId),
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'size'      => $size,
            'from'      => $total === 0 ? 0 : $offset + 1,
            'to'        => $offset + count($rows),
            'sel'       => $sel,
        ];
    }

    /**
     * The three list buckets, counted in ONE round trip. The database is on
     * another machine (docs/hosting.md) and three COUNTs would be three of
     * them for numbers that are read together and shown together.
     *
     * Every fragment carries its own placeholder prefix: a named placeholder
     * cannot be reused within one statement here, and this statement uses the
     * assignment tests four times.
     *
     * @param array<string, string|int> $bind
     * @return array{unassigned: int, ineligible: int, assigned: int, total: int}
     */
    private function counts(string $where, array $bind, int $showYearId): array
    {
        [$hasA, $bindA] = EligibleOfficers::memberHasAssignment('m', 'ca', $showYearId);
        [$brokenB, $bindB] = EligibleOfficers::memberHasBrokenAssignment('m', 'cb', $showYearId);
        [$hasC, $bindC] = EligibleOfficers::memberHasAssignment('m', 'cc', $showYearId);
        [$brokenD, $bindD] = EligibleOfficers::memberHasBrokenAssignment('m', 'cd', $showYearId);

        $read = $this->pdo->prepare(
            'SELECT COUNT(*) AS total,'
            . " SUM(CASE WHEN {$hasA} THEN 0 ELSE 1 END) AS unassigned,"
            . " SUM(CASE WHEN {$brokenB} THEN 1 ELSE 0 END) AS ineligible,"
            . " SUM(CASE WHEN {$hasC} AND NOT {$brokenD} THEN 1 ELSE 0 END) AS assigned"
            . " FROM member m WHERE {$where}"
        );
        $read->execute($bind + $bindA + $bindB + $bindC + $bindD);
        $row = $read->fetch();

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
            'ineligible' => (int) ($row['ineligible'] ?? 0),
            'assigned'   => (int) ($row['assigned'] ?? 0),
        ];
    }

    /**
     * One page of one bucket, with the chips, the last contact and the
     * current officers each row needs.
     *
     * Ordering is BY TITLE, then by name, in every bucket (owner decision at
     * Phase 6 close). A team reads as its own hierarchy — the Captain and
     * the Assistant Captains at the top, the committee members under them —
     * which is how the people using this screen already think about the
     * people on it, and it makes a 85-row page scannable by role.
     *
     * Two sort keys carry it, and the split is deliberate:
     *
     *   title_level DESC   the coarse rank, from the column an import wrote
     *                      through TitleMap, so a title spelled with a stray
     *                      space still lands in the right group. Level's own
     *                      rule allows exactly this: the ENUM is declared low
     *                      to high so ORDER BY sorts correctly, and it is
     *                      comparison in a WHERE clause that is forbidden.
     *   title position     the fine order WITHIN a level, from
     *                      TitleMap::titles(), because Vice Chairman, Captain
     *                      and Assistant Captain are all 'officer' and a team
     *                      that lists them alphabetically is not a hierarchy.
     *
     * **This supersedes decided 5 for the Unassigned bucket.** That ordering
     * — never contacted first, then oldest contact — now lives only on My
     * Roster Status (spec 7.1), which is the screen for deciding who to call.
     * This one is for deciding who is responsible, and the owner asked for
     * the roles to lead. The last-contact column stays, so the triage signal
     * is still readable; it just no longer sorts.
     *
     * @param array<string, string|int> $bind
     * @return array<int, array<string, mixed>>
     */
    private function rows(
        string $where,
        array $bind,
        string $bucket,
        int $showYearId,
        int $size,
        int $offset
    ): array {
        [$has, $bindHas]       = EligibleOfficers::memberHasAssignment('m', 'pa', $showYearId);
        [$broken, $bindBroken] = EligibleOfficers::memberHasBrokenAssignment('m', 'pb', $showYearId);

        if ($bucket === 'ineligible') {
            $where .= " AND {$broken}";
            $bind  += $bindBroken;
        } elseif ($bucket === 'assigned') {
            $where .= " AND {$has} AND NOT {$broken}";
            $bind  += $bindHas + $bindBroken;
        } else {
            $where .= " AND NOT {$has}";
            $bind  += $bindHas;
        }

        [$titleRank, $bindTitle] = self::titleRank('m', 'ord');
        $bind += $bindTitle;

        $orderBy = "m.title_level DESC, {$titleRank} ASC,"
            . ' m.last_name ASC, m.first_name ASC, m.id ASC';

        // LIMIT and OFFSET are integers cast in PHP and interpolated: a
        // string-bound LIMIT fails on the native protocol.
        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.title, m.division_id, m.team_id, lc.last_contact_at'
            . ' FROM member m'
            . ' LEFT JOIN (SELECT member_id, MAX(occurred_at) AS last_contact_at'
            . '   FROM contact_log WHERE show_year_id = :row_contact_year GROUP BY member_id) lc'
            . '   ON lc.member_id = m.id'
            . " WHERE {$where} ORDER BY {$orderBy} LIMIT {$size} OFFSET {$offset}"
        );
        $read->execute($bind + [':row_contact_year' => $showYearId]);
        $members = $read->fetchAll();

        if ($members === []) {
            return [];
        }

        $ids     = array_map(static fn (array $m): int => (int) $m['id'], $members);
        $metrics = (new MemberReads($this->pdo))->metricsFor($ids, $showYearId);
        $held    = (new EligibleOfficers($this->pdo))->currentAssignments($ids, $showYearId);

        $rows = [];
        foreach ($members as $member) {
            $id        = (int) $member['id'];
            $contacted = $member['last_contact_at'] !== null;

            $statuses = [];
            $complete = 0;
            foreach (Metric::scored() as $metric) {
                $values = $metrics[$id][$metric->value] ?? null;
                // MetricStatus::derive() is the only place spec 5.4 exists;
                // no status is re-derived here or in the view.
                $status = MetricStatus::derive(
                    $values['imported_value'] ?? 'unknown',
                    $values['progress'] ?? 'not_started',
                    $contacted
                );
                $statuses[$metric->value] = $status;
                if ($status === MetricStatus::Complete) {
                    $complete++;
                }
            }

            $rows[] = [
                'id'            => $id,
                'member_number' => (string) $member['member_number'],
                'display_name'  => RosterPage::displayName(
                    (string) $member['preferred_name'],
                    (string) $member['first_name'],
                    (string) $member['last_name'],
                    (string) $member['member_number']
                ),
                // What the export calls them. Shown as its own column and
                // sorted on, so a team reads as its own hierarchy.
                'title'        => (string) $member['title'],
                'statuses'     => $statuses,
                // What "Select all outstanding" means, decided here rather
                // than in the view: outstanding on at least one of the four.
                'outstanding'  => $complete !== count(Metric::scored()),
                'last_contact' => $member['last_contact_at'] !== null
                    ? (string) $member['last_contact_at']
                    : null,
                'officers'     => $held[$id] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * A member's position within TitleMap's seniority order, as SQL — the
     * fine sort key that separates the three titles which all map to Officer.
     *
     * FIELD() answers 0 for a title the map does not know, which would sort
     * it FIRST; NULLIF turns that 0 into NULL and COALESCE sends it to the
     * end instead. An unrecognised title imports as Member with a warning
     * (spec 6.4) and it sorts last among Members — never silently above a
     * Captain, which is the same direction the title map itself errs in.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function titleRank(string $alias, string $prefix): array
    {
        $places = [];
        $bind   = [];
        foreach (TitleMap::titles() as $i => $title) {
            $places[]              = ":{$prefix}_title_{$i}";
            $bind[":{$prefix}_title_{$i}"] = $title;
        }

        return [
            'COALESCE(NULLIF(FIELD(' . $alias . '.title, ' . implode(', ', $places) . '), 0), 999)',
            $bind,
        ];
    }

    /**
     * Every officer currently holding somebody on this team, with how many —
     * the Remove picker's list. Ineligible officers are in it deliberately:
     * they are the ones most likely to be removed.
     *
     * @param array<string, string|int> $bind
     * @return array<int, array<string, mixed>>
     */
    private function holders(string $where, array $bind, int $showYearId): array
    {
        [$in, $bindIn] = EligibleOfficers::levelIn('hold');

        $read = $this->pdo->prepare(
            'SELECT a.officer_member_id, COUNT(*) AS held,'
            . ' om.member_number AS officer_number, om.first_name AS officer_first,'
            . ' om.last_name AS officer_last, om.preferred_name AS officer_preferred,'
            . ' MIN(CASE WHEN ' . ScopedQuery::visible('om')
            . '   AND om.team_id = m.team_id'
            . '   AND ' . EligibleOfficers::effectiveLevel('om', 'au') . " IN {$in}"
            . '   THEN 1 ELSE 0 END) AS officer_eligible'
            . ' FROM assignment a'
            . ' INNER JOIN member m ON m.id = a.member_id'
            . ' INNER JOIN member om ON om.id = a.officer_member_id'
            . ' LEFT JOIN app_user au ON au.member_id = om.id'
            . " WHERE {$where} AND a.show_year_id = :hold_year AND a.removed_at IS NULL"
            . ' GROUP BY a.officer_member_id, om.member_number, om.first_name,'
            . '   om.last_name, om.preferred_name'
            . ' ORDER BY om.last_name, om.first_name, a.officer_member_id'
        );
        $read->execute($bind + $bindIn + [':hold_year' => $showYearId]);

        $holders = [];
        foreach ($read->fetchAll() as $row) {
            $holders[] = [
                'id'       => (int) $row['officer_member_id'],
                'name'     => RosterPage::displayName(
                    (string) $row['officer_preferred'],
                    (string) $row['officer_first'],
                    (string) $row['officer_last'],
                    (string) $row['officer_number']
                ),
                'held'     => (int) $row['held'],
                'eligible' => (int) $row['officer_eligible'] === 1,
            ];
        }

        return $holders;
    }

    /**
     * The teams holding members in this user's scope, each with the two
     * numbers that decide where the work is: how many of its members have
     * nobody, and how many have somebody who no longer counts.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamsInScope(User $user, int $showYearId): array
    {
        $scoped = ScopedQuery::forUser($user);

        [$has, $bindHas]       = EligibleOfficers::memberHasAssignment('m', 'ta', $showYearId);
        [$broken, $bindBroken] = EligibleOfficers::memberHasBrokenAssignment('m', 'tb', $showYearId);

        $read = $this->pdo->prepare(
            'SELECT t.id, t.name, COUNT(*) AS members,'
            . " SUM(CASE WHEN {$has} THEN 0 ELSE 1 END) AS unassigned,"
            . " SUM(CASE WHEN {$broken} THEN 1 ELSE 0 END) AS ineligible"
            . ' FROM member m INNER JOIN team t ON t.id = m.team_id'
            . ' WHERE ' . $scoped->predicate()
            . ' GROUP BY t.id, t.name ORDER BY t.name'
        );
        $read->execute($scoped->bindings() + $bindHas + $bindBroken);

        // Officer counts come from EligibleOfficers, per team, unscoped: a
        // team's officers are a fact about the team, and the write path
        // enforces the same fact whoever is looking.
        $officerCounts = (new EligibleOfficers($this->pdo))->countsByTeam();

        $teams = [];
        foreach ($read->fetchAll() as $row) {
            $id      = (int) $row['id'];
            $teams[] = [
                'id'         => $id,
                'name'       => (string) $row['name'],
                'members'    => (int) $row['members'],
                'unassigned' => (int) $row['unassigned'],
                'ineligible' => (int) $row['ineligible'],
                'officers'   => $officerCounts[$id] ?? 0,
            ];
        }

        return $teams;
    }

    /** Members in scope with no team at all — unassignable, so counted. */
    private function membersWithoutTeam(User $user): int
    {
        $scoped = ScopedQuery::forUser($user);

        $read = $this->pdo->prepare(
            'SELECT COUNT(*) FROM member m WHERE ' . $scoped->predicate() . ' AND m.team_id IS NULL'
        );
        $read->execute($scoped->bindings());

        return (int) $read->fetchColumn();
    }

    /** @param array<int, array<string, mixed>> $teams */
    private function teamName(array $teams, int $teamId): string
    {
        foreach ($teams as $team) {
            if ((int) $team['id'] === $teamId) {
                return (string) $team['name'];
            }
        }

        // An Officer scoped to a team holding nobody they may see: the team
        // exists, their roster is empty, and the screen says so rather than
        // pretending the team does not exist.
        $read = $this->pdo->prepare('SELECT name FROM team WHERE id = :team');
        $read->execute([':team' => $teamId]);
        $name = $read->fetchColumn();

        return is_string($name) ? $name : '';
    }
}
