<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;
use Rerm\App;
use Rerm\Auth\User;

/**
 * The My Roster Status read (spec 7.1, as revised at Phase 4 close): the
 * dashboard roll-up and the working list, computed together from ONE
 * derivation pass so they cannot disagree.
 *
 * The roll-up derives in PHP, never in SQL (Phase 5 decided 3): the 5.4
 * table lives in MetricStatus::derive() and nowhere else, and a SQL
 * `CASE WHEN imported = 'Y' …` would be a second copy of it. So this class
 * fetches the scope's members, metric rows and last-contact times — three
 * queries of a few columns each, ~2,000 + ~9,800 + ~2,000 rows at the
 * biggest scope — runs the one function over them, and gets the banner, the
 * four cards AND the list's membership and order out of the same pass. Every
 * card figure provably equals the list filtered to that status, because both
 * are the same array.
 *
 * Every row comes through ScopedQuery::forUser() — this class never writes
 * its own visibility or scope conditions. The My members / My team toggle
 * NARROWS the scope (an assignment can only subtract, never add a member the
 * scope predicate would refuse), and the mode is resolved here, server-side:
 * default is My members if the officer holds any assignments this show year,
 * else My team (spec 7.1) — which at launch, before Phase 6 writes the first
 * assignment, lands everyone on My team.
 *
 * PHASE 7 ADDED FOUR FILTERS, AND THEY ARE WHAT MAKES THE DASHBOARD HONEST
 *
 * The Committee Dashboard drills down to this screen, and spec 7.1's own rule
 * — every figure equals the list filtered to it — has to survive the trip. So
 * this class reads `division=`, `team[]=` (spec 7.2's shape, never a second
 * one), `contact=never` and `assigned=none`, applies them to the SAME `$where`
 * the cards and the list share, and reports back exactly what it applied.
 *
 * `has_assignments` is deliberately computed BEFORE any of them, on the
 * unfiltered scope: it answers "does this officer hold anybody at all", which
 * is what the toggle's default rule means. Computing it after would make an
 * officer's default mode depend on which team they happened to drill into.
 *
 * WHAT A ROW CARRIES (spec-v2 §6)
 *
 * Beside the four chips each row now carries the member's IMPORTED TITLE, the
 * RESULT of the last contact, and the whole show year's contact history for
 * the expansion. The first two are the ones worth naming here: the title is
 * Rodeo Houston's, rewritten by every import and never the level this
 * application derived from it; and the result is `ContactOutcome::summarise()`
 * over the statuses THIS class already derived, never a second read — a word
 * for what the conversation produced cannot be allowed to disagree with the
 * chips printed beside it.
 */
final class StatusPage
{
    /** The list filter values the URL may spell. Anything else is the default. */
    private const SHOWS = ['outstanding', 'all'];

    /** The toggle values the URL may spell. Anything else is the default. */
    private const MODES = ['mine', 'team'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $pageSizeDefault,
        private readonly int $pageSizeLarge,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self(
            $app->db(),
            (int) $app->config()->get('roster.page_size_mobile', 50),
            (int) $app->config()->get('roster.page_size_desktop', 100),
        );
    }

    /**
     * Everything the screen needs: the dashboard tallies, one page of the
     * working list, and the state of every control as it was actually
     * applied. $input is the raw query string ($_GET), untrusted and
     * normalised here.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function page(User $user, int $showYearId, array $input): array
    {
        $scoped = ScopedQuery::forUser($user);
        $where  = $scoped->predicate();
        $bind   = $scoped->bindings();

        // The toggle (spec 7.1). "Assigned to me" keys on the user's MEMBER
        // row — assignments outlive accounts — and only counts members the
        // scope predicate would show anyway.
        $assigned = $this->pdo->prepare(
            'SELECT COUNT(*) FROM assignment a INNER JOIN member m ON m.id = a.member_id'
            . " WHERE {$where} AND a.officer_member_id = :mine_officer"
            . ' AND a.show_year_id = :mine_year AND a.removed_at IS NULL'
        );
        $assigned->execute($bind + [':mine_officer' => $user->memberId, ':mine_year' => $showYearId]);
        $hasAssignments = (int) $assigned->fetchColumn() > 0;

        $requestedMode = is_string($input['mode'] ?? null) && in_array($input['mode'], self::MODES, true)
            ? $input['mode']
            : null;
        $mode = $requestedMode ?? ($hasAssignments ? 'mine' : 'team');

        // ------------------------------------------------------------------
        // The drill-down filters (spec 7.3, decided 4). Every one of them
        // NARROWS the scoped predicate — none can widen it, because each is
        // ANDed onto ScopedQuery's own — so they are applied whatever the
        // signed-in level is. There is no picker for them on this screen:
        // they arrive in the URL from the Committee Dashboard, where the
        // figure that made the link is the figure they have to reproduce.
        //
        // They are applied BEFORE the three reads and after nothing, so the
        // dashboard cards and the list are both filtered. That is what makes
        // "clicking 40 never contacted lands on those 40" true rather than
        // approximately true.
        // ------------------------------------------------------------------

        $divisionFilter = (int) ($input['division'] ?? 0);
        $contactFilter  = ($input['contact'] ?? '') === 'never';
        $assignedFilter = ($input['assigned'] ?? '') === 'none';

        // ------------------------------------------------------------------
        // The team picker (Phase 10), for a caller whose scope holds more
        // than one team — Senior Officer and above, and an Officer an Admin
        // has given a team set.
        //
        // It shares `team[]` with the drill-down above rather than inventing
        // a second parameter, because it IS the same narrowing: a Division
        // Chairman arriving from the Committee Dashboard and one choosing a
        // team from the select are asking for the same rows, and one filter
        // that two controls write is one filter that cannot disagree with
        // itself.
        //
        // What is new is the DEFAULT. A caller who has said nothing starts on
        // their own team rather than on their whole division, because the
        // roster somebody opens this screen to work is almost always the one
        // they are on — and because a Division Chairman's 400-row list is a
        // worse first screen than a 25-row one with a visible way out.
        //
        // A DRILL-DOWN SUPPRESSES IT, and that is not a detail. Spec 7.3's
        // rule is that every figure on the Committee Dashboard equals this
        // list filtered to it; a default silently ANDed onto a link that
        // already said `division=` or `contact=never` would break that for
        // exactly the people the figure counted, and it would break it
        // invisibly.
        // ------------------------------------------------------------------

        // A drill-down is any of the three filters this screen has no control
        // for. While one is in force the screen is reproducing a figure from
        // the Committee Dashboard, so the team picker is neither defaulted
        // nor offered: a control that could quietly alter the group is the
        // one thing spec 7.3's rule cannot survive. The banner's "Show my
        // whole roster" is the way out, and the picker is there afterwards.
        $drilled = $divisionFilter > 0 || $contactFilter || $assignedFilter;

        $teamOptions = TeamFilter::inScope($this->pdo, $user);
        $teamChoice  = TeamFilter::choose(
            $input['team'] ?? null,
            $teamOptions,
            $user->scopeTeamId,
            !$drilled
        );
        $teamFilter = $teamChoice['selected'];

        if ($divisionFilter > 0) {
            $where .= ' AND m.division_id = :filter_division';
            $bind[':filter_division'] = $divisionFilter;
        }

        if ($teamFilter !== []) {
            // team[] is spec 7.2's existing filter shape, not a second one.
            // The ids are already cast to int and they still intersect the
            // scope predicate, so an out-of-scope id yields nothing rather
            // than something.
            $places = [];
            foreach (array_values($teamFilter) as $i => $teamId) {
                $places[]                  = ":filter_team_{$i}";
                $bind[":filter_team_{$i}"] = $teamId;
            }
            $where .= ' AND m.team_id IN (' . implode(', ', $places) . ')';
        }

        if ($contactFilter) {
            // Never contacted THIS show year — the absence spec 5.4 reads as
            // Outstanding rather than Contacted, and the Committee
            // Dashboard's default sort column.
            $where .= ' AND NOT EXISTS (SELECT 1 FROM contact_log cn'
                . ' WHERE cn.member_id = m.id AND cn.show_year_id = :filter_contact_year)';
            $bind[':filter_contact_year'] = $showYearId;
        }

        if ($assignedFilter) {
            // EligibleOfficers' fragment, so "unassigned" here is the same
            // question the Assign screen's bucket 1 and the Committee
            // Dashboard's unassigned column both ask.
            [$hasAny, $bindHasAny] = EligibleOfficers::memberHasAssignment('m', 'flt', $showYearId);
            $where .= " AND NOT {$hasAny}";
            $bind  += $bindHasAny;
        }

        // My members narrows the scoped WHERE; every read below shares it so
        // the dashboard and the list describe the same people.
        if ($mode === 'mine') {
            $where .= ' AND EXISTS (SELECT 1 FROM assignment ax WHERE ax.member_id = m.id'
                . ' AND ax.officer_member_id = :toggle_officer'
                . ' AND ax.show_year_id = :toggle_year AND ax.removed_at IS NULL)';
            $bind[':toggle_officer'] = $user->memberId;
            $bind[':toggle_year']    = $showYearId;
        }

        // The three reads of decided 3. Names come along so the list can be
        // ordered here without a second trip.
        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name'
            . " FROM member m WHERE {$where}"
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

        $read = $this->pdo->prepare(
            'SELECT c.member_id, MAX(c.occurred_at) AS last_contact_at'
            . ' FROM contact_log c INNER JOIN member m ON m.id = c.member_id'
            . " WHERE {$where} AND c.show_year_id = :contact_year GROUP BY c.member_id"
        );
        $read->execute($bind + [':contact_year' => $showYearId]);
        $lastContact = [];
        foreach ($read->fetchAll() as $row) {
            $lastContact[(int) $row['member_id']] = (string) $row['last_contact_at'];
        }

        // ------------------------------------------------------------------
        // The one derivation pass. Cards and list both come out of it.
        // ------------------------------------------------------------------

        $cards = [];
        foreach (Metric::scored() as $metric) {
            $counts = [];
            foreach (MetricStatus::cases() as $status) {
                $counts[$status->value] = 0;
            }
            $cards[$metric->value] = ['label' => $metric->label(), 'statuses' => $counts];
        }

        $total         = count($members);
        $fullyComplete = 0;
        $candidates    = [];

        foreach ($members as $member) {
            $id        = (int) $member['id'];
            $contacted = isset($lastContact[$id]);

            $statuses = [];
            $complete = 0;
            foreach (Metric::scored() as $metric) {
                $values = $metrics[$id][$metric->value] ?? null;
                // No metric row means no import has covered this member here:
                // 'unknown', not a failure — and outstanding, so nobody
                // vanishes from the working set (decided 4).
                $status = MetricStatus::derive(
                    $values['imported_value'] ?? 'unknown',
                    $values['progress'] ?? 'not_started',
                    $contacted
                );
                $statuses[$metric->value] = $status;
                $cards[$metric->value]['statuses'][$status->value]++;
                if ($status === MetricStatus::Complete) {
                    $complete++;
                }
            }

            $isFully = $complete === count(Metric::scored());
            if ($isFully) {
                $fullyComplete++;
            }

            $candidates[] = [
                'id'            => $id,
                'member'        => $member,
                'statuses'      => $statuses,
                'fully'         => $isFully,
                'last_contact'  => $lastContact[$id] ?? null,
            ];
        }

        foreach ($cards as $metricKey => $card) {
            $completeCount = $card['statuses'][MetricStatus::Complete->value];
            $cards[$metricKey]['complete']    = $completeCount;
            // Outstanding is EVERY effective status except Complete
            // (decided 4): Reported Complete and Member Handling still count
            // until Rodeo Houston's roster confirms Y, and Not reported
            // counts so nobody vanishes.
            $cards[$metricKey]['outstanding'] = $total - $completeCount;
        }

        // ------------------------------------------------------------------
        // The list: default filter outstanding-on-any, ordered so the top is
        // always the next call to make — never contacted first (their sort
        // key is the empty string, before every datetime), then oldest
        // contact first, with a stable name-and-id tiebreak.
        // ------------------------------------------------------------------

        $show = is_string($input['show'] ?? null) && in_array($input['show'], self::SHOWS, true)
            ? $input['show']
            : 'outstanding';

        if ($show === 'outstanding') {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $c): bool => !$c['fully']
            ));
        }

        usort($candidates, static function (array $a, array $b): int {
            return [
                $a['last_contact'] ?? '',
                (string) $a['member']['last_name'],
                (string) $a['member']['first_name'],
                $a['id'],
            ] <=> [
                $b['last_contact'] ?? '',
                (string) $b['member']['last_name'],
                (string) $b['member']['first_name'],
                $b['id'],
            ];
        });

        $listTotal = count($candidates);

        // Page size is one of exactly two configured values, as everywhere.
        $size = (int) ($input['size'] ?? $this->pageSizeDefault);
        if ($size !== $this->pageSizeDefault && $size !== $this->pageSizeLarge) {
            $size = $this->pageSizeDefault;
        }

        $pages  = max(1, (int) ceil($listTotal / $size));
        $page   = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $size;

        $pageCandidates = array_slice($candidates, $offset, $size);

        return [
            'mode'            => $mode,
            'has_assignments' => $hasAssignments,
            'show'            => $show,
            'dashboard'       => [
                'total'          => $total,
                'fully_complete' => $fullyComplete,
                'cards'          => $cards,
            ],
            'rows'         => $this->detailRows($pageCandidates, $showYearId),
            'total'        => $listTotal,
            'page'         => $page,
            'pages'        => $pages,
            'size'         => $size,
            'size_default' => $this->pageSizeDefault,
            'size_large'   => $this->pageSizeLarge,
            'from'         => $listTotal === 0 ? 0 : $offset + 1,
            'to'           => $offset + count($pageCandidates),

            // Which row's log-contact sheet is open with its per-metric
            // progress selects rendered (?log=id). One row per page: the
            // selects are ~1KB of repeated <option> text, and fifty copies
            // is half the spec 10 first-paint budget by themselves.
            'log_open'     => (int) ($input['log'] ?? 0),

            // The team picker's own state: the options, the caller's own team,
            // what is selected and whether that was chosen or defaulted. The
            // view needs all four to draw the control AND to say in words
            // which roster is on the screen.
            'team_choice'  => $teamChoice,

            // The drill-down filters as they were actually applied, with the
            // names to say them in. A screen that has been narrowed by a URL
            // has to show what it was narrowed to, or the officer reads a
            // roster that is quietly missing people.
            'filters'      => [
                'division'      => $divisionFilter > 0 ? $divisionFilter : null,
                'teams'         => $teamFilter,
                'contact'       => $contactFilter ? 'never' : null,
                'assigned'      => $assignedFilter ? 'none' : null,
                // A TEAM ALONE NO LONGER LIGHTS THE BANNER (Phase 10). The
                // banner says "you are seeing a slice somebody linked you to"
                // and offers the one way out; a team is now a choice this
                // screen itself offers, described by the picker and undone by
                // it. Two controls saying the same thing, one of them in the
                // language of a link nobody followed, is worse than one.
                //
                // A team arriving WITH a drill-down still appears in the
                // banner's list of words — it is part of the group that was
                // linked to — which is what the line below and $filterWords
                // together produce.
                'active'        => $drilled,
                'drilled'       => $drilled,
                'division_name' => $divisionFilter > 0
                    ? $this->divisionName($scoped, $divisionFilter)
                    : '',
                'team_names'    => $teamFilter !== []
                    ? $this->teamNames($scoped, $teamFilter)
                    : [],
            ],
        ];
    }

    /**
     * The name of a filtered division, for the sentence that says what this
     * screen was narrowed to.
     *
     * Read THROUGH THE SCOPE, not off the division table: a crafted
     * `?division=` naming somebody else's division returns an empty roster
     * either way, and this stops it also returning that division's NAME. The
     * scope predicate alone is the right filter here rather than the whole
     * `$where` — the question is "does this user see anybody in it", not
     * "does anybody in it match every triage filter as well".
     */
    private function divisionName(ScopedQuery $scoped, int $divisionId): string
    {
        $read = $this->pdo->prepare(
            'SELECT d.name FROM division d INNER JOIN member m ON m.division_id = d.id'
            . ' WHERE ' . $scoped->predicate() . ' AND d.id = :name_division LIMIT 1'
        );
        $read->execute($scoped->bindings() + [':name_division' => $divisionId]);
        $name = $read->fetchColumn();

        return is_string($name) ? $name : '';
    }

    /**
     * The names of the filtered teams, one query, through the same scope and
     * in the order the URL named them so the sentence reads the way the link
     * was built.
     *
     * @param array<int, int> $teamIds
     * @return array<int, string>
     */
    private function teamNames(ScopedQuery $scoped, array $teamIds): array
    {
        [$places, $bind] = MemberReads::idList($teamIds, 'filter_team_name');

        $read = $this->pdo->prepare(
            'SELECT DISTINCT t.id, t.name FROM team t INNER JOIN member m ON m.team_id = t.id'
            . ' WHERE ' . $scoped->predicate() . " AND t.id IN ({$places})"
        );
        $read->execute($scoped->bindings() + $bind);

        $names = [];
        foreach ($read->fetchAll() as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        $ordered = [];
        foreach ($teamIds as $teamId) {
            if (isset($names[$teamId])) {
                $ordered[$teamId] = $names[$teamId];
            }
        }

        return $ordered;
    }

    /**
     * The full rows for the one page being shown: title, contact details,
     * history, assigned officers and the contact outcome, batched — the
     * derivation pass above carried only ids and names, so the heavy columns
     * are fetched for these members alone.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function detailRows(array $candidates, int $showYearId): array
    {
        if ($candidates === []) {
            return [];
        }

        $ids = array_map(static fn (array $c): int => (int) $c['id'], $candidates);

        [$places, $bind] = MemberReads::idList($ids, 'detail_member');

        // m.title is Rodeo Houston's word for what this member is, rewritten
        // by every import (spec 6.6) — the roster's title, never the level
        // this application derived from it. The two differ often enough to
        // matter: an officer working a list of calls needs to know which of
        // these people already hold a job.
        $read = $this->pdo->prepare(
            'SELECT m.id, m.title, m.phone, m.phone_e164, m.phone_type, m.email, t.name AS team_name'
            . ' FROM member m LEFT JOIN team t ON t.id = m.team_id'
            . " WHERE m.id IN ({$places})"
        );
        $read->execute($bind);
        $details = [];
        foreach ($read->fetchAll() as $row) {
            $details[(int) $row['id']] = $row;
        }

        $reads    = new MemberReads($this->pdo);
        $contacts = $reads->contactsFor($ids, $showYearId);
        $officers = $reads->assignmentsFor($ids, $showYearId);

        $rows = [];
        foreach ($candidates as $candidate) {
            $id     = (int) $candidate['id'];
            $member = $candidate['member'];
            $detail = $details[$id] ?? [];

            $history = $contacts[$id] ?? [];

            $rows[] = [
                'id'            => $id,
                'member_number' => (string) $member['member_number'],
                'display_name'  => RosterPage::displayName(
                    (string) $member['preferred_name'],
                    (string) $member['first_name'],
                    (string) $member['last_name'],
                    (string) $member['member_number']
                ),
                // The imported title, as Rodeo Houston spells it.
                'title'         => (string) ($detail['title'] ?? ''),
                'team_name'     => (string) ($detail['team_name'] ?? ''),
                'statuses'      => $candidate['statuses'],
                'fully'         => $candidate['fully'],
                'phone'         => (string) ($detail['phone'] ?? ''),
                'phone_e164'    => (string) ($detail['phone_e164'] ?? ''),
                'email'         => trim((string) ($detail['email'] ?? '')),

                // The suppression flags, decided in PHP so they are testable
                // without rendering — the same rules as View My Roster: sms:
                // only for CELL PHONE, mailto: only when an address exists.
                'can_call'  => (string) ($detail['phone_e164'] ?? '') !== '',
                'can_text'  => (string) ($detail['phone_e164'] ?? '') !== ''
                    && (string) ($detail['phone_type'] ?? '') === 'CELL PHONE',
                'can_email' => trim((string) ($detail['email'] ?? '')) !== '',

                // The WHOLE history for the show year, not just the newest:
                // the row expansion lists every entry, the same shape View My
                // Roster reads, and it is already in hand from the one
                // batched read above.
                'contacts'     => $history,
                'last_contact' => $history[0] ?? null,
                'officers'     => $officers[$id] ?? [],

                // What the last contact PRODUCED (spec-v2 §6), derived from
                // the statuses immediately above and nothing else — so the
                // word cannot disagree with the chips beside it.
                'outcome'      => ContactOutcome::summarise(
                    $candidate['statuses'],
                    $history !== []
                ),
            ];
        }

        return $rows;
    }
}
