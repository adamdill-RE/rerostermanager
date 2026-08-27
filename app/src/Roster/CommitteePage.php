<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;
use Rerm\App;
use Rerm\Auth\User;

/**
 * The Committee Dashboard read (spec 7.3): the 7.1 dashboard computed per
 * group instead of for one roster, rolled up by division, then area, then
 * team.
 *
 * THE SHAPE, WHICH IS StatusPage's SHAPE
 *
 * One derivation pass in PHP over the scope's members feeds every level at
 * once: each member is counted into its team's tally, its area's tally and
 * its division's tally in the same iteration. So a division's figure is not
 * a second query that happens to agree with its teams' — it is the same
 * additions, and "every figure equals the list filtered to it" (spec 7.1's
 * rule since Phase 5) holds at three levels because there is only ever one
 * set of per-member facts.
 *
 * Seven reads of a few columns each at the largest scope — ~2,000 member
 * rows, ~9,800 metric rows, the contacted and the assigned id lists, the
 * division and team lookups, and the per-team officer counts — and never a
 * query per group. The database is on another machine (docs/hosting.md) and
 * the roll-up budget is 500ms (spec 10); measured at the real roster's shape
 * it runs in under 50ms.
 *
 * MetricStatus::derive() is the only place spec 5.4 exists. A SQL
 * `CASE WHEN imported_value = 'Y'` here would be a second copy of that table
 * and is forbidden; the four proportion bars are counted from the same
 * derive() call every chip on every other screen goes through.
 *
 * WHY THE COVERAGE COLUMNS CANNOT DRIFT FROM THE ASSIGN SCREEN'S
 *
 * `unassigned` and `no officer on this team` are the Assign screen's numbers
 * (spec 7.3, decided 2), and this class does NOT re-spell them. It reads
 * EligibleOfficers::memberHasAssignment() — the same fragment
 * AssignPage::teamsInScope() aggregates — and
 * EligibleOfficers::countsByTeam() — the same eligible-officer count bucket 3
 * is built from. What is shared is the PREDICATE, not an aggregate, which is
 * why AssignPage::teamsInScope() was left private rather than lifted: it
 * INNER JOINs `team` and groups by team alone, so it drops the members who
 * have no team at all and it collapses the seven teams that span two
 * divisions (docs/data-findings.md 4b) into one row each. This roll-up needs
 * both facts kept — division is a property of the MEMBER, so the group here
 * is the (division, team) pair. tests/committee_test.php holds the two
 * screens' numbers to each other.
 *
 * THREE PLACEHOLDER GROUPS, FOR ONE REASON
 *
 * `(No Division)` is a real division row (spec 5.1a) and sorts, groups and
 * drills down here like any other. `(No area)` holds the teams whose names
 * match none of the seven area prefixes, and `(No team)` holds members with
 * no team at all. All three exist so that no roll-up can quietly omit
 * somebody: a bucket that is visibly a placeholder beats a total that does
 * not add up.
 *
 * Every row comes through ScopedQuery::forUser(); this class writes no
 * visibility or scope condition of its own. It has no write path at all —
 * nothing here touches contact_log, assignment or member_metric.
 */
final class CommitteePage
{
    /**
     * The sort keys that are not metrics, as the URL spells them. The
     * metrics add one key each (their Metric->value), and sortKeys() is the
     * whole whitelist.
     *
     * The user's value chooses FROM this list and is never used to build
     * anything — the roll-up is sorted in PHP over rows already derived, so
     * no sort key reaches a SQL string at all, which is a stronger version
     * of the RosterPage::SORTS rule rather than an exception to it.
     */
    public const SORTS = ['contact', 'unassigned', 'no_officer', 'members', 'name'];

    /**
     * Never contacted, descending (spec 7.3, decided 1). At 50–65% of the
     * committee outstanding on every metric (docs/data-findings.md 8), 96
     * teams all render between roughly 35% and 50% complete and sorting by
     * compliance returns a list whose top and bottom differ by noise.
     * Compliance describes the committee; contact and coverage distinguish
     * the teams.
     */
    public const DEFAULT_SORT = 'contact';
    public const DEFAULT_DIR  = 'desc';

    /** The name a group with no value of its own is rendered under. */
    public const NO_AREA = '(No area)';
    public const NO_TEAM = '(No team)';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db());
    }

    /**
     * Every sort key the URL may spell, fixed columns then the four metrics.
     *
     * @return array<int, string>
     */
    public static function sortKeys(): array
    {
        $keys = self::SORTS;
        foreach (Metric::scored() as $metric) {
            $keys[] = $metric->value;
        }

        return $keys;
    }

    /**
     * The whole screen: the group rows in render order, and the state of
     * every control as it was actually applied. $input is the raw query
     * string ($_GET), untrusted and normalised here so the view renders only
     * decided values.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function page(User $user, int $showYearId, array $input): array
    {
        $scoped = ScopedQuery::forUser($user);
        $where  = $scoped->predicate();
        $bind   = $scoped->bindings();

        // ------------------------------------------------------------------
        // The reads. Every one of them appends the same scoped predicate, so
        // every level of the roll-up describes exactly the people this user
        // may see.
        // ------------------------------------------------------------------

        $read = $this->pdo->prepare(
            "SELECT m.id, m.division_id, m.team_id FROM member m WHERE {$where}"
        );
        $read->execute($bind);
        $members = $read->fetchAll();

        $read = $this->pdo->prepare(
            'SELECT mm.member_id, mm.metric, mm.imported_value, mm.progress'
            . ' FROM member_metric mm INNER JOIN member m ON m.id = mm.member_id'
            . " WHERE {$where} AND mm.show_year_id = :metric_year"
        );
        $read->execute($bind + [':metric_year' => $showYearId]);
        $metrics = [];
        foreach ($read->fetchAll() as $row) {
            $metrics[(int) $row['member_id']][(string) $row['metric']] = [
                'imported_value' => (string) $row['imported_value'],
                'progress'       => (string) $row['progress'],
            ];
        }

        // Contacted THIS SHOW YEAR, which is what spec 5.4 means by it and
        // what the never-contacted column counts the absence of. Existence,
        // not the timestamp: this screen shows no dates.
        $read = $this->pdo->prepare(
            'SELECT DISTINCT c.member_id FROM contact_log c INNER JOIN member m ON m.id = c.member_id'
            . " WHERE {$where} AND c.show_year_id = :contact_year"
        );
        $read->execute($bind + [':contact_year' => $showYearId]);
        $contacted = [];
        foreach ($read->fetchAll() as $row) {
            $contacted[(int) $row['member_id']] = true;
        }

        // Holds at least one current assignment — EligibleOfficers' fragment,
        // the one the Assign screen counts with, so `unassigned` here is the
        // same number that screen shows.
        [$has, $bindHas] = EligibleOfficers::memberHasAssignment('m', 'cdha', $showYearId);
        $read = $this->pdo->prepare(
            "SELECT m.id FROM member m WHERE {$where} AND {$has}"
        );
        $read->execute($bind + $bindHas);
        $assigned = [];
        foreach ($read->fetchAll() as $row) {
            $assigned[(int) $row['id']] = true;
        }

        $divisions = [];
        foreach ($this->pdo->query('SELECT id, name, is_placeholder FROM division')->fetchAll() as $row) {
            $divisions[(int) $row['id']] = [
                'name'        => (string) $row['name'],
                'placeholder' => (int) $row['is_placeholder'] === 1,
            ];
        }

        $teams = [];
        foreach ($this->pdo->query('SELECT id, name, area FROM team')->fetchAll() as $row) {
            $teams[(int) $row['id']] = [
                'name' => (string) $row['name'],
                // NULL is the honest answer for a team whose name matched no
                // area prefix (migration 006). It groups under (No area).
                'area' => $row['area'] === null ? '' : (string) $row['area'],
            ];
        }

        // Eligible officers per team, every team and not only this user's:
        // a team's officers are a fact about the team, and the write path on
        // the Assign screen enforces the same fact whoever is looking.
        $officerCounts = (new EligibleOfficers($this->pdo))->countsByTeam();

        // ------------------------------------------------------------------
        // The one derivation pass. Each member lands in three tallies.
        // ------------------------------------------------------------------

        /** @var array<int, array<string, mixed>> $divisionTally */
        $divisionTally = [];
        /** @var array<string, array<string, mixed>> $areaTally keyed div\0area */
        $areaTally = [];
        /** @var array<string, array<string, mixed>> $teamTally keyed div\0area\0team */
        $teamTally = [];
        /**
         * The areas present under each division, keyed AND valued by the area
         * name. The value carries it because PHP turns a numeric string array
         * key into an int, and `610` is one of the seven real area names — as
         * a key alone it would come back as the integer 610 and never match
         * the string the URL spells.
         *
         * @var array<int, array<string, string>> $areasOf
         */
        $areasOf = [];
        /** @var array<string, array<int, true>> $teamsOf */
        $teamsOf = [];

        $scoredCount = count(Metric::scored());

        foreach ($members as $member) {
            $id         = (int) $member['id'];
            $divisionId = (int) $member['division_id'];
            $teamId     = $member['team_id'] === null ? 0 : (int) $member['team_id'];
            $areaKey    = $teamId === 0 ? '' : (string) ($teams[$teamId]['area'] ?? '');

            $areaPath = $divisionId . "\0" . $areaKey;
            $teamPath = $areaPath . "\0" . $teamId;

            $areasOf[$divisionId][$areaKey] = $areaKey;
            $teamsOf[$areaPath][$teamId]    = true;

            $divisionTally[$divisionId] ??= self::emptyTally();
            $areaTally[$areaPath]       ??= self::emptyTally();
            $teamTally[$teamPath]       ??= self::emptyTally();

            $isContacted = isset($contacted[$id]);

            $statuses = [];
            foreach (Metric::scored() as $metric) {
                $values = $metrics[$id][$metric->value] ?? null;
                // No metric row means no import has covered this member for
                // this item: 'unknown', which derives to Not reported — never
                // a failure, and never silently a pass either.
                $statuses[$metric->value] = MetricStatus::derive(
                    $values['imported_value'] ?? 'unknown',
                    $values['progress'] ?? 'not_started',
                    $isContacted
                );
            }

            // "No officer on this team" is a TEAM-level fact applied to the
            // member (spec 7.4 bucket 3): the team exists and has nobody who
            // could ever be assigned them. A member with no team at all is a
            // different fact and is not counted here — they are their own
            // (No team) group, which says so in words.
            $noOfficer = $teamId !== 0 && ($officerCounts[$teamId] ?? 0) === 0;

            $facts = [
                'assigned'  => isset($assigned[$id]),
                'contacted' => $isContacted,
                'noOfficer' => $noOfficer,
                'statuses'  => $statuses,
            ];

            self::apply($divisionTally[$divisionId], $facts, $scoredCount);
            self::apply($areaTally[$areaPath], $facts, $scoredCount);
            self::apply($teamTally[$teamPath], $facts, $scoredCount);
        }

        // ------------------------------------------------------------------
        // The controls, all chosen FROM what exists rather than validated
        // against it: a division that is not in scope is simply not one of
        // the choices, so it lands on the unexpanded screen rather than on an
        // error.
        // ------------------------------------------------------------------

        $sort = is_string($input['sort'] ?? null) && in_array($input['sort'], self::sortKeys(), true)
            ? $input['sort']
            : self::DEFAULT_SORT;
        $dir = in_array($input['dir'] ?? null, ['asc', 'desc'], true)
            ? (string) $input['dir']
            : self::DEFAULT_DIR;

        $divisionIds = self::ordered(
            'division',
            array_keys($divisionTally),
            $divisionTally,
            $divisions,
            $teams,
            $sort,
            $dir
        );

        $requestedDivision = (int) ($input['division'] ?? 0);
        $openDivision      = in_array($requestedDivision, $divisionIds, true) ? $requestedDivision : null;
        // One division in scope is a Senior Officer's whole world. There is
        // nothing to collapse it to, so it is always open and its name is not
        // a toggle.
        $soleDivision = count($divisionIds) === 1;
        if ($soleDivision) {
            $openDivision = $divisionIds[0];
        }

        $openAreas = [];
        if ($openDivision !== null) {
            $openAreas = self::ordered(
                'area',
                array_values($areasOf[$openDivision] ?? []),
                $areaTally,
                $divisions,
                $teams,
                $sort,
                $dir,
                $openDivision . "\0"
            );
        }

        // NULL is "no area asked for" and '' is the (No area) group asking to
        // be opened — two different things that a single empty string would
        // conflate, and conflating them opens (No area) on every plain load.
        $requestedArea = is_string($input['area'] ?? null) ? $input['area'] : null;
        $openArea      = $requestedArea !== null && in_array($requestedArea, $openAreas, true)
            ? $requestedArea
            : null;
        $soleArea      = count($openAreas) === 1;
        if ($soleArea) {
            $openArea = $openAreas[0];
        }

        // ------------------------------------------------------------------
        // The rows, in render order: every division, the open division's
        // areas, and the open area's teams. One level at a time is the byte
        // budget deciding (spec 10) — see app/views/committee.php.
        // ------------------------------------------------------------------

        $rows = [];
        foreach ($divisionIds as $divisionId) {
            $isOpen = $divisionId === $openDivision;
            $areas  = $isOpen ? $openAreas : array_values($areasOf[$divisionId] ?? []);

            $rows[] = self::row(
                'division',
                (string) $divisionId,
                $divisions[$divisionId]['name'] ?? '(Unknown division)',
                $divisionTally[$divisionId],
                $divisionId,
                [],
                true,
                $divisions[$divisionId]['placeholder'] ?? false,
                $isOpen,
                count($areas),
                $soleDivision
            );

            if (!$isOpen) {
                continue;
            }

            foreach ($areas as $areaKey) {
                $areaPath  = $divisionId . "\0" . $areaKey;
                $areaTeams = array_keys($teamsOf[$areaPath] ?? []);
                $areaIsOpen = $areaKey === $openArea;

                // The real teams under this area. The 0 key is the members
                // who have no team at all, and it is neither a team[] value
                // nor a team to be counted as one.
                $realTeams = array_values(array_filter($areaTeams, static fn (int $t): bool => $t !== 0));

                $rows[] = self::row(
                    'area',
                    $areaKey,
                    $areaKey === '' ? self::NO_AREA : $areaKey,
                    $areaTally[$areaPath],
                    $divisionId,
                    $realTeams,
                    // An area holding members with no team cannot be spelled
                    // as team[], so it counts them and does not link.
                    !in_array(0, $areaTeams, true),
                    $areaKey === '',
                    $areaIsOpen,
                    count($realTeams),
                    $soleArea
                );

                if (!$areaIsOpen) {
                    continue;
                }

                $teamIds = self::ordered(
                    'team',
                    $areaTeams,
                    $teamTally,
                    $divisions,
                    $teams,
                    $sort,
                    $dir,
                    $areaPath . "\0"
                );

                foreach ($teamIds as $teamId) {
                    $rows[] = self::row(
                        'team',
                        (string) $teamId,
                        $teamId === 0 ? self::NO_TEAM : ($teams[$teamId]['name'] ?? '(Unknown team)'),
                        $teamTally[$areaPath . "\0" . $teamId],
                        $divisionId,
                        $teamId === 0 ? [] : [$teamId],
                        $teamId !== 0,
                        $teamId === 0,
                        false,
                        0,
                        false
                    );
                }
            }
        }

        return [
            'rows'          => $rows,
            'sort'          => $sort,
            'dir'           => $dir,
            'open_division' => $openDivision,
            'open_area'     => $openArea,
            'sole_division' => $soleDivision,
            'sole_area'     => $soleArea,
            'total'         => count($members),
            'divisions'     => count($divisionIds),
            'teams'         => count($teamTally),
        ];
    }

    /**
     * One rendered group row.
     *
     * `team_ids` is what the drill-down link spells as team[] — spec 7.2's
     * existing filter shape, never a second one, which is also why an AREA
     * drills down as the list of its own teams: `area` is display grouping
     * and must never become a query filter.
     *
     * A DIVISION carries no team[] at all: `division=` alone is the exact
     * filter for it, and naming its teams instead would silently drop the
     * members who have no team — the figure would stop equalling the list.
     *
     * `drillable` is where that rule is enforced rather than assumed. A group
     * whose membership spec 7.1 cannot express — (No team), and the area
     * holding it — makes no drill-down promise and so is not a link. Adding a
     * fourth filter spelling to §7.1 to cover them would be inventing a
     * decision the owner did not take.
     *
     * @param array<string, mixed> $tally
     * @param array<int, int>      $teamIds
     * @return array<string, mixed>
     */
    private static function row(
        string $level,
        string $key,
        string $name,
        array $tally,
        int $divisionId,
        array $teamIds,
        bool $drillable,
        bool $placeholder,
        bool $open,
        int $children,
        bool $sole
    ): array {
        return $tally + [
            'level'       => $level,
            'key'         => $key,
            'name'        => $name,
            'division_id' => $divisionId,
            'team_ids'    => $teamIds,
            // A placeholder group is bookkeeping rather than a fact from the
            // export: (No Division), (No area), (No team). It renders like
            // any other group and is never hidden for being untidy (spec
            // 5.1a) — the flag is there so the view can say what it is.
            'placeholder' => $placeholder,
            'open'        => $open,
            'children'    => $children,
            // The only choice at its level: there is nothing to collapse to,
            // so the view offers no toggle.
            'sole'        => $sole,
            'drillable'   => $drillable,
        ];
    }

    /** A zero tally: the four metrics with every status at nought. */
    private static function emptyTally(): array
    {
        $metrics = [];
        foreach (Metric::scored() as $metric) {
            $statuses = [];
            foreach (MetricStatus::cases() as $status) {
                $statuses[$status->value] = 0;
            }
            $metrics[$metric->value] = ['statuses' => $statuses, 'complete' => 0, 'outstanding' => 0];
        }

        return [
            'members'         => 0,
            'unassigned'      => 0,
            'no_officer'      => 0,
            'never_contacted' => 0,
            'fully_complete'  => 0,
            'metrics'         => $metrics,
        ];
    }

    /**
     * One member into one tally. Called three times per member — team, area
     * and division — which is what makes a parent's figure the sum of its
     * children's rather than a second query that agrees with them.
     *
     * @param array<string, mixed>                $tally
     * @param array<string, mixed>                $facts
     */
    private static function apply(array &$tally, array $facts, int $scoredCount): void
    {
        $tally['members']++;
        if (!$facts['assigned']) {
            $tally['unassigned']++;
        }
        if (!$facts['contacted']) {
            $tally['never_contacted']++;
        }
        if ($facts['noOfficer']) {
            $tally['no_officer']++;
        }

        $complete = 0;
        foreach ($facts['statuses'] as $metricKey => $status) {
            $tally['metrics'][$metricKey]['statuses'][$status->value]++;
            if ($status === MetricStatus::Complete) {
                $tally['metrics'][$metricKey]['complete']++;
                $complete++;
            } else {
                // Outstanding is EVERY effective status except Complete, as
                // on spec 7.1's cards: Reported Complete and Member Handling
                // still count until Rodeo Houston's roster confirms Y, and
                // Not reported counts so nobody vanishes.
                $tally['metrics'][$metricKey]['outstanding']++;
            }
        }

        if ($complete === $scoredCount) {
            $tally['fully_complete']++;
        }
    }

    /**
     * The keys at one level, in the chosen order. Sorting happens here, over
     * rows already derived, so the sort key never reaches a query.
     *
     * The tiebreak is always the group's NAME ascending and then its own key,
     * so a screen full of equal figures — which at 50–65% outstanding is the
     * ordinary case for the metric columns — still reads alphabetically
     * rather than in whatever order the rows were accumulated.
     *
     * @param 'division'|'area'|'team'            $level
     * @param array<int, int|string>              $keys
     * @param array<string|int, array<string, mixed>> $tallies
     * @param array<int, array<string, mixed>>    $divisions
     * @param array<int, array<string, mixed>>    $teams
     * @return array<int, mixed>
     */
    private static function ordered(
        string $level,
        array $keys,
        array $tallies,
        array $divisions,
        array $teams,
        string $sort,
        string $dir,
        string $prefix = ''
    ): array {
        $rows = [];
        foreach ($keys as $key) {
            $tally = $tallies[$prefix === '' ? $key : $prefix . $key] ?? null;
            if ($tally === null) {
                continue;
            }

            // The name a tiebreak reads, and what sort=name sorts on. The
            // level is passed rather than inferred from the key's shape: an
            // area name is a string an Admin edits from Phase 8, and a rule
            // that read its contents to decide what it was would be one
            // rename away from sorting the wrong column.
            if ($level === 'division') {
                $name = (string) ($divisions[(int) $key]['name'] ?? '');
            } elseif ($level === 'area') {
                $name = $key === '' ? self::NO_AREA : (string) $key;
            } else {
                $name = (int) $key === 0 ? self::NO_TEAM : (string) ($teams[(int) $key]['name'] ?? '');
            }

            $rows[] = ['key' => $key, 'name' => $name, 'value' => self::sortValue($tally, $sort, $name)];
        }

        $descending = $dir === 'desc';

        usort($rows, static function (array $a, array $b) use ($descending): int {
            $primary = $a['value'] <=> $b['value'];
            if ($descending) {
                $primary = -$primary;
            }

            return $primary !== 0
                ? $primary
                : [$a['name'], (string) $a['key']] <=> [$b['name'], (string) $b['key']];
        });

        return array_map(static fn (array $r) => $r['key'], $rows);
    }

    /**
     * What one sort key reads off a tally. A metric sorts by its OUTSTANDING
     * count rather than by its completion rate: every other sortable figure
     * on the row is a count of people, and a rate mixed in among them would
     * make "descending" mean two different things on one screen. The bar
     * still shows the proportion.
     *
     * @param array<string, mixed> $tally
     */
    private static function sortValue(array $tally, string $sort, string $name): int|string
    {
        if ($sort === 'name') {
            return $name;
        }
        if ($sort === 'contact') {
            return (int) $tally['never_contacted'];
        }
        if (isset($tally['metrics'][$sort])) {
            return (int) $tally['metrics'][$sort]['outstanding'];
        }

        return (int) ($tally[$sort] ?? 0);
    }
}
