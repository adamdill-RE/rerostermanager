<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;
use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\User;

/**
 * The View My Roster read (spec 7.2): everyone in scope, searched, filtered,
 * sorted and paginated — one count, one page, and one batched query each for
 * metrics, contacts and assignments, whatever the page size. Never a query
 * per row: the database is on another machine (docs/hosting.md), and an N+1
 * at 100 rows is 200 round trips against a 500ms budget.
 *
 * Every row comes through ScopedQuery::forUser() — this class never writes
 * its own visibility or scope conditions, so it cannot get them wrong, and
 * tests/roster_test.php holds the whole path (scope + search + filter +
 * pagination together) to "exactly their team / exactly their division"
 * against a fixture at the real roster's 1,954-row shape.
 *
 * Everything user-supplied travels as a binding; the three things that reach
 * the SQL string — sort column, direction, LIMIT/OFFSET — are a whitelist, a
 * whitelist, and integers cast in PHP, because ORDER BY cannot take a bound
 * parameter and a string-bound LIMIT fails on the native protocol.
 */
final class RosterPage
{
    /**
     * Sort keys as the URL spells them -> the column each one means. The
     * user's value chooses FROM this table and never reaches the SQL string
     * itself, not even validated. Every sort gets a stable name-and-id
     * tiebreak appended so pagination never shows a row twice.
     *
     * last_contact ascending puts NULL — never contacted — first, which is
     * the spec 7.1 ordering: the top of the list is the next call to make.
     */
    private const SORTS = [
        'name'    => 'm.last_name',
        'team'    => 't.name',
        'contact' => 'lc.last_contact_at',
        'number'  => 'm.member_number',
    ];

    private const SEARCH_MIN_CHARS = 3;

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
     * One page of the roster, plus everything the screen needs to describe
     * it honestly: the exact total, the from/to of this page, and the state
     * of every filter as it was actually applied — not as it was requested.
     *
     * $input is the raw query string ($_GET); everything in it is untrusted
     * and normalised here, in one place, so the view renders only decided
     * values.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function page(User $user, int $showYearId, array $input): array
    {
        $scoped = ScopedQuery::forUser($user);
        $where  = $scoped->predicate();
        $bind   = $scoped->bindings();

        // Search: a 3-character floor, enforced server-side (decided 2 — a
        // GET form, no keystroke endpoint). Shorter input is not an error; it
        // gets the unfiltered scoped list and a sentence saying so.
        $searchRaw = trim((string) ($input['q'] ?? ''));
        $tooShort  = $searchRaw !== '' && mb_strlen($searchRaw) < self::SEARCH_MIN_CHARS;
        $search    = $tooShort ? '' : $searchRaw;

        if ($search !== '') {
            // %, _ and \ typed by a person are literals, not operators: a
            // member named 100% must be findable and %%% must match nobody.
            // Four columns means four placeholders — a named placeholder
            // cannot be reused within one statement here.
            $like = '%' . self::escapeLike($search) . '%';

            $where .= " AND (m.preferred_name LIKE :search_preferred ESCAPE '\\\\'"
                . " OR m.first_name LIKE :search_first ESCAPE '\\\\'"
                . " OR m.last_name LIKE :search_last ESCAPE '\\\\'"
                . " OR m.member_number LIKE :search_number ESCAPE '\\\\')";

            $bind[':search_preferred'] = $like;
            $bind[':search_first']     = $like;
            $bind[':search_last']      = $like;
            $bind[':search_number']    = $like;
        }

        // The team filter is for Senior Officer and above only — an Officer's
        // team IS their scope, so for them the input is dropped, not applied.
        // The ids are cast to int and still intersect the scope predicate, so
        // an out-of-scope id yields nothing rather than something.
        $canFilterTeams = $user->level->atLeast(Level::SeniorOfficer);
        $selectedTeams  = $canFilterTeams ? self::teamIds($input['team'] ?? []) : [];

        if ($selectedTeams !== []) {
            $places = [];
            foreach (array_values($selectedTeams) as $i => $teamId) {
                $places[]              = ":filter_team_{$i}";
                $bind[":filter_team_{$i}"] = $teamId;
            }
            $where .= ' AND m.team_id IN (' . implode(', ', $places) . ')';
        }

        // The count first: the page number clamps to what actually exists,
        // and the "Showing X–Y of Z" line is exact, always (spec 7.2).
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM member m WHERE {$where}");
        $count->execute($bind);
        $total = (int) $count->fetchColumn();

        // Sort and direction both map through whitelists. An unknown key is
        // the default, never an error and never the input.
        $sortKey = is_string($input['sort'] ?? null) && isset(self::SORTS[$input['sort']])
            ? $input['sort']
            : 'name';
        $dir     = ($input['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
        $orderBy = self::SORTS[$sortKey] . ' ' . strtoupper($dir)
            . ', m.last_name ASC, m.first_name ASC, m.id ASC';

        // Page size is one of exactly two configured values — 50 by choice,
        // 100 by request, never by device sniffing (decided 3).
        $size = (int) ($input['size'] ?? $this->pageSizeDefault);
        if ($size !== $this->pageSizeDefault && $size !== $this->pageSizeLarge) {
            $size = $this->pageSizeDefault;
        }

        $pages  = max(1, (int) ceil($total / $size));
        $page   = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $size;

        // The one page. LIMIT and OFFSET are integers interpolated after the
        // casts above — a string-bound LIMIT fails on the native protocol.
        // The last-contact join exists for the sort and costs one aggregate
        // over this year's contact_log; the rows' own history comes from the
        // batch below.
        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.phone, m.phone_e164, m.phone_type, m.email, m.division_id, m.team_id,'
            . ' t.name AS team_name, d.name AS division_name, lc.last_contact_at'
            . ' FROM member m'
            . ' LEFT JOIN team t ON t.id = m.team_id'
            . ' INNER JOIN division d ON d.id = m.division_id'
            . ' LEFT JOIN (SELECT member_id, MAX(occurred_at) AS last_contact_at'
            . '   FROM contact_log WHERE show_year_id = :contact_year GROUP BY member_id) lc'
            . '   ON lc.member_id = m.id'
            . " WHERE {$where} ORDER BY {$orderBy} LIMIT {$size} OFFSET {$offset}"
        );
        $read->execute($bind + [':contact_year' => $showYearId]);
        $members = $read->fetchAll();

        $ids         = array_map(static fn (array $m): int => (int) $m['id'], $members);
        $metrics     = $this->metricsFor($ids, $showYearId);
        $contacts    = $this->contactsFor($ids, $showYearId);
        $assignments = $this->assignmentsFor($ids, $showYearId);

        $rows = [];
        foreach ($members as $member) {
            $id        = (int) $member['id'];
            $history   = $contacts[$id] ?? [];
            $contacted = $history !== [];

            $statuses = [];
            foreach (Metric::cases() as $metric) {
                $values = $metrics[$id][$metric->value] ?? null;
                // A member with no metric row for this show year has never
                // been covered by an import here: 'unknown', not a failure.
                $statuses[$metric->value] = MetricStatus::derive(
                    $values['imported_value'] ?? 'unknown',
                    $values['progress'] ?? 'not_started',
                    $contacted
                );
            }

            $rows[] = [
                'id'            => $id,
                'member_number' => (string) $member['member_number'],
                'display_name'  => self::displayName(
                    (string) $member['preferred_name'],
                    (string) $member['first_name'],
                    (string) $member['last_name'],
                    (string) $member['member_number']
                ),
                'team_name'     => (string) ($member['team_name'] ?? ''),
                'division_name' => (string) $member['division_name'],
                'phone'         => (string) $member['phone'],
                'phone_e164'    => (string) ($member['phone_e164'] ?? ''),
                'phone_type'    => (string) $member['phone_type'],
                'email'         => trim((string) ($member['email'] ?? '')),
                'statuses'      => $statuses,

                // The contact actions, decided HERE so the rule is testable
                // without rendering: sms: only for CELL PHONE — 116 members
                // hold numbers a text silently fails against — and mailto:
                // only when an address exists. Absent, never disabled.
                'can_call'  => (string) ($member['phone_e164'] ?? '') !== '',
                'can_text'  => (string) ($member['phone_e164'] ?? '') !== ''
                    && (string) $member['phone_type'] === 'CELL PHONE',
                'can_email' => trim((string) ($member['email'] ?? '')) !== '',

                'contacts'     => $history,
                'last_contact' => $history[0] ?? null,
                'officers'     => $assignments[$id] ?? [],
            ];
        }

        return [
            'rows'             => $rows,
            'total'            => $total,
            'page'             => $page,
            'pages'            => $pages,
            'size'             => $size,
            'size_default'     => $this->pageSizeDefault,
            'size_large'       => $this->pageSizeLarge,
            'from'             => $total === 0 ? 0 : $offset + 1,
            'to'               => $offset + count($rows),
            'search'           => $searchRaw,
            'search_applied'   => $search !== '',
            'search_too_short' => $tooShort,
            'search_min_chars' => self::SEARCH_MIN_CHARS,
            'sort'             => $sortKey,
            'dir'              => $dir,
            'can_filter_teams' => $canFilterTeams,
            'selected_teams'   => $selectedTeams,
            'teams'            => $canFilterTeams ? $this->teamsInScope($user) : [],
        ];
    }

    /**
     * What a list calls a member: preferred name, else first name, else the
     * member number — never a blank. preferred_name was sentinel-scrubbed on
     * import (N/A, None -> ''), so the fallback chain is safe to trust.
     */
    public static function displayName(
        string $preferred,
        string $first,
        string $last,
        string $memberNumber
    ): string {
        $given = trim($preferred) !== '' ? trim($preferred) : trim($first);
        $name  = trim($given . ' ' . trim($last));

        return $name !== '' ? $name : $memberNumber;
    }

    /**
     * A user-typed search term as a LIKE literal: %, _ and \ escaped, so the
     * pattern the database sees contains no operator the user wrote.
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * The team[] input as ints: digits only, de-duplicated, and capped well
     * past the 96 real teams — a thousand-value query string must not become
     * a thousand-placeholder statement.
     *
     * @return array<int, int>
     */
    private static function teamIds(mixed $input): array
    {
        $ids = [];
        foreach ((array) $input as $value) {
            if (is_string($value) || is_int($value)) {
                $value = (string) $value;
                if ($value !== '' && ctype_digit($value)) {
                    $ids[(int) $value] = (int) $value;
                }
            }
        }

        return array_slice(array_values($ids), 0, 200);
    }

    /**
     * The teams the filter can offer: those that actually hold members in
     * this user's scope, through the same predicate as the roster itself.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamsInScope(User $user): array
    {
        $scoped = ScopedQuery::forUser($user);

        $read = $this->pdo->prepare(
            'SELECT DISTINCT t.id, t.name FROM member m'
            . ' INNER JOIN team t ON t.id = m.team_id'
            . ' WHERE ' . $scoped->predicate()
            . ' ORDER BY t.name'
        );
        $read->execute($scoped->bindings());

        return $read->fetchAll();
    }

    /**
     * Every metric row for the page's members, one query.
     *
     * @param array<int, int> $memberIds
     * @return array<int, array<string, array{imported_value: string, progress: string}>>
     */
    private function metricsFor(array $memberIds, int $showYearId): array
    {
        if ($memberIds === []) {
            return [];
        }

        [$places, $bind] = self::idList($memberIds, 'metric_member');

        $read = $this->pdo->prepare(
            'SELECT member_id, metric, imported_value, progress FROM member_metric'
            . " WHERE show_year_id = :year AND member_id IN ({$places})"
        );
        $read->execute($bind + [':year' => $showYearId]);

        $byMember = [];
        foreach ($read->fetchAll() as $row) {
            $byMember[(int) $row['member_id']][(string) $row['metric']] = [
                'imported_value' => (string) $row['imported_value'],
                'progress'       => (string) $row['progress'],
            ];
        }

        return $byMember;
    }

    /**
     * The full contact history for the page's members and this show year —
     * the expansion needs every entry, so the newest-first list serves both
     * it and the row's "last contact" cell. One query.
     *
     * @param array<int, int> $memberIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function contactsFor(array $memberIds, int $showYearId): array
    {
        if ($memberIds === []) {
            return [];
        }

        [$places, $bind] = self::idList($memberIds, 'contact_member');

        $read = $this->pdo->prepare(
            'SELECT c.member_id, c.contact_type, c.occurred_at, c.notes,'
            . ' om.preferred_name AS officer_preferred, om.first_name AS officer_first,'
            . ' om.last_name AS officer_last, om.member_number AS officer_number'
            . ' FROM contact_log c'
            . ' INNER JOIN app_user au ON au.id = c.contacted_by'
            . ' INNER JOIN member om ON om.id = au.member_id'
            . " WHERE c.show_year_id = :year AND c.member_id IN ({$places})"
            . ' ORDER BY c.occurred_at DESC, c.id DESC'
        );
        $read->execute($bind + [':year' => $showYearId]);

        $byMember = [];
        foreach ($read->fetchAll() as $row) {
            $byMember[(int) $row['member_id']][] = [
                'contact_type' => (string) $row['contact_type'],
                'occurred_at'  => (string) $row['occurred_at'],
                'notes'        => (string) $row['notes'],
                'officer_name' => self::displayName(
                    (string) $row['officer_preferred'],
                    (string) $row['officer_first'],
                    (string) $row['officer_last'],
                    (string) $row['officer_number']
                ),
            ];
        }

        return $byMember;
    }

    /**
     * The current assigned officers for the page's members, one query.
     * Officers reference member rows, not accounts: an officer demoted since
     * assignment still has to show up here rather than vanish (spec 6.6).
     *
     * @param array<int, int> $memberIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function assignmentsFor(array $memberIds, int $showYearId): array
    {
        if ($memberIds === []) {
            return [];
        }

        [$places, $bind] = self::idList($memberIds, 'assign_member');

        $read = $this->pdo->prepare(
            'SELECT a.member_id,'
            . ' om.preferred_name AS officer_preferred, om.first_name AS officer_first,'
            . ' om.last_name AS officer_last, om.member_number AS officer_number, om.title AS officer_title'
            . ' FROM assignment a'
            . ' INNER JOIN member om ON om.id = a.officer_member_id'
            . " WHERE a.show_year_id = :year AND a.removed_at IS NULL AND a.member_id IN ({$places})"
            . ' ORDER BY om.last_name, om.first_name, om.id'
        );
        $read->execute($bind + [':year' => $showYearId]);

        $byMember = [];
        foreach ($read->fetchAll() as $row) {
            $byMember[(int) $row['member_id']][] = [
                'officer_name'  => self::displayName(
                    (string) $row['officer_preferred'],
                    (string) $row['officer_first'],
                    (string) $row['officer_last'],
                    (string) $row['officer_number']
                ),
                'officer_title' => (string) $row['officer_title'],
            ];
        }

        return $byMember;
    }

    /**
     * An IN () list of already-cast ints as uniquely named placeholders.
     *
     * @param array<int, int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private static function idList(array $ids, string $prefix): array
    {
        $places = [];
        $bind   = [];
        foreach (array_values($ids) as $i => $id) {
            $places[]              = ":{$prefix}_{$i}";
            $bind[":{$prefix}_{$i}"] = $id;
        }

        return [implode(', ', $places), $bind];
    }
}
