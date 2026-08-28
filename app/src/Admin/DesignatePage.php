<?php

declare(strict_types=1);

namespace Rerm\Admin;

use PDO;
use Rerm\App;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Subject;
use Rerm\Auth\User;
use Rerm\Roster\RosterPage;
use Rerm\Roster\ScopedQuery;

/**
 * The Designate Users read (spec 7.5, 4.4).
 *
 * "Search the whole roster, regardless of title, and set a level." The
 * regardless-of-title half is the point and is what this query does
 * differently from every other roster read: no filter on `title_level`, and a
 * LEFT JOIN to `app_user` rather than an INNER one, because 1,758 of 1,954
 * members have no account at all and every one of them is a legitimate target
 * for a grant. Designating a Committee Member is the whole feature —
 * docs/data-findings.md 7 counts 7 teams with no eligible officer and 432
 * members on teams with fewer than two, and this screen is what brings that
 * number down.
 *
 * **It still reads through ScopedQuery::forUser(), and that is a decision.**
 * The Phase 8 handover described this search as "the roster search WITHOUT
 * ScopedQuery". Spec 4.5 puts `designate_allowed_user` at "Scoped, capped at
 * own level", and CLAUDE.md requires role AND scope on every request, so an
 * unscoped list here would show a Senior Officer 1,954 names — including the
 * ~1,500 their own View My Roster refuses them — and then offer a grant
 * control that the write path is obliged to refuse. Every row this screen
 * lists is a row the actor may actually act on. For an Admin or an Executive
 * Officer, ScopedQuery::forUser() IS the whole committee, so "search the
 * whole roster" is literally true for the people who run this screen; for a
 * Senior Officer it is their division, which is exactly what
 * Access::allows(..., DesignateAllowedUser, subject) will permit.
 *
 * Everything else is RosterPage's search, deliberately: the same
 * three-character floor, the same escapeLike(), the same two page sizes. A
 * second search with its own rules would be a second place for the LIKE
 * escaping to go wrong.
 *
 * Effective level is read from `app_user.effective_level`, the schema's own
 * VIRTUAL column, and never re-derived here (Phase 8 inherits). A member with
 * no account has no effective level at all — not Member, NULL — and the
 * screen says "No account" rather than inventing one.
 */
final class DesignatePage
{
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
     * One page of candidates, plus everything the screen needs to describe
     * itself honestly.
     *
     * @param array<string, mixed> $input the raw query string, untrusted
     * @return array<string, mixed>
     */
    public function page(User $user, array $input): array
    {
        $scoped = ScopedQuery::forUser($user);
        $where  = $scoped->predicate();
        $bind   = $scoped->bindings();

        $searchRaw = trim((string) ($input['q'] ?? ''));
        $tooShort  = $searchRaw !== '' && mb_strlen($searchRaw) < self::SEARCH_MIN_CHARS;
        $search    = $tooShort ? '' : $searchRaw;

        if ($search !== '') {
            // RosterPage::escapeLike(), so %, _ and \ typed by a person are
            // literals. Four columns means four placeholders — a named
            // placeholder cannot be reused within one statement here.
            $like = '%' . RosterPage::escapeLike($search) . '%';

            $where .= " AND (m.preferred_name LIKE :search_preferred ESCAPE '\\\\'"
                . " OR m.first_name LIKE :search_first ESCAPE '\\\\'"
                . " OR m.last_name LIKE :search_last ESCAPE '\\\\'"
                . " OR m.member_number LIKE :search_number ESCAPE '\\\\')";

            $bind[':search_preferred'] = $like;
            $bind[':search_first']     = $like;
            $bind[':search_last']      = $like;
            $bind[':search_number']    = $like;
        }

        // "Show me only the people who already hold something." Not a scope
        // and not a permission — a way to find the twelve rows worth reviewing
        // in a division of 675, which is otherwise a page-by-page hunt.
        $only = ($input['only'] ?? '') === 'granted' ? 'granted' : '';
        if ($only === 'granted') {
            $where .= ' AND u.granted_level IS NOT NULL';
        }

        $from = ' FROM member m'
            . ' LEFT JOIN app_user u ON u.member_id = m.id'
            . ' LEFT JOIN team t ON t.id = m.team_id'
            . ' INNER JOIN division d ON d.id = m.division_id';

        $count = $this->pdo->prepare("SELECT COUNT(*){$from} WHERE {$where}");
        $count->execute($bind);
        $total = (int) $count->fetchColumn();

        $size = (int) ($input['size'] ?? $this->pageSizeDefault);
        if ($size !== $this->pageSizeDefault && $size !== $this->pageSizeLarge) {
            $size = $this->pageSizeDefault;
        }

        $pages  = max(1, (int) ceil($total / $size));
        $page   = min(max(1, (int) ($input['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $size;

        // Everything the row shows, in one query: the member, their account
        // if they have one, who granted the grant if there is one, and the
        // names behind a scope override if one is set. LIMIT and OFFSET are
        // integers cast above — a string-bound LIMIT fails on the native
        // protocol — and the sort is fixed rather than user-chosen, because
        // this screen is reached by searching for a person, not by browsing.
        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.title, m.title_level, m.division_id, m.team_id,'
            . ' t.name AS team_name, d.name AS division_name,'
            . ' u.id AS user_id, u.granted_level, u.granted_at, u.is_active,'
            . ' u.must_change_password, u.effective_level,'
            . ' u.scope_division_id, u.scope_team_id,'
            . ' gm.member_number AS granted_by_number, gm.first_name AS granted_by_first,'
            . ' gm.last_name AS granted_by_last, gm.preferred_name AS granted_by_preferred,'
            . ' sd.name AS scope_division_name, st.name AS scope_team_name'
            . $from
            . ' LEFT JOIN app_user gu ON gu.id = u.granted_by'
            . ' LEFT JOIN member gm ON gm.id = gu.member_id'
            . ' LEFT JOIN division sd ON sd.id = u.scope_division_id'
            . ' LEFT JOIN team st ON st.id = u.scope_team_id'
            . " WHERE {$where}"
            . " ORDER BY m.last_name ASC, m.first_name ASC, m.id ASC LIMIT {$size} OFFSET {$offset}"
        );
        $read->execute($bind);

        $members = $read->fetchAll();

        // The team scopes for this whole page in one query rather than one
        // per row — the same batched-read discipline every roster screen
        // uses, for the same reason: the database is on another machine.
        $teamScopes = $this->teamScopesFor(array_map(
            static fn (array $r): ?int => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            $members
        ));

        $rows = [];
        foreach ($members as $row) {
            $rows[] = $this->row($user, $row, $teamScopes);
        }

        // ONE form on the page, not fifty (the Phase 5 budget lesson): the
        // list renders a link per row and the grant / revoke / scope controls
        // only for the member named by ?member=. A per-row form here would be
        // a level select, a scope select pair and three buttons x 100 rows
        // against a 100KB first-paint budget (spec 10).
        $selected = (int) ($input['member'] ?? 0);

        return [
            'rows'             => $rows,
            'selected'         => $selected > 0 ? $selected : null,
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
            'only'             => $only,

            // The levels THIS actor may hand out, decided once here rather
            // than in the view: Access::mayGrant() is the cap and the select
            // offers exactly what it permits.
            'grantable'        => self::grantableBy($user),

            // The Admin-only half of the screen (spec 4.4: "unless an Admin
            // sets an explicit scope override"). Everything it needs is here
            // so the view asks no permission questions of its own.
            'may_override'     => Access::mayUse($user, Capability::DesignateAdmin),
            'divisions'        => $this->divisions(),
            'teams'            => $this->teams(),
        ];
    }

    /**
     * The levels a user may grant or revoke, spec 4.4's cap, through
     * Access::mayGrant() and nothing else.
     *
     * Member is included on purpose: granting Member level to somebody with
     * no account creates one, which is how a Committee Member who does roster
     * work gets a login without being made an Officer. It is a real level,
     * not a way of spelling "none" — revoke is what spells that.
     *
     * @return array<int, Level>
     */
    public static function grantableBy(User $user): array
    {
        $levels = [];
        foreach (Level::cases() as $level) {
            if (Access::mayGrant($user, $level)) {
                $levels[] = $level;
            }
        }

        return $levels;
    }

    /**
     * One candidate row, with every question the view would otherwise have to
     * ask answered here.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<int, array{id: int, name: string}>> $teamScopes
     * @return array<string, mixed>
     */
    private function row(User $user, array $row, array $teamScopes = []): array
    {
        $titleLevel = Level::from((string) $row['title_level']);
        $granted    = $row['granted_level'] !== null
            ? Level::from((string) $row['granted_level'])
            : null;

        // The schema's VIRTUAL column, read and never re-derived. NULL means
        // no account at all — which is not the same as Member level, and the
        // screen must not render it as though it were.
        $effective = $row['effective_level'] !== null
            ? Level::from((string) $row['effective_level'])
            : null;

        $subject = Subject::fromMemberRow($row);

        return [
            'id'            => (int) $row['id'],
            'member_number' => (string) $row['member_number'],
            'name'          => RosterPage::displayName(
                (string) $row['preferred_name'],
                (string) $row['first_name'],
                (string) $row['last_name'],
                (string) $row['member_number']
            ),
            'title'         => (string) $row['title'],
            'team_name'     => (string) ($row['team_name'] ?? ''),
            'division_name' => (string) $row['division_name'],

            'title_level'     => $titleLevel,
            'granted_level'   => $granted,
            'effective_level' => $effective,

            // Where the level came from, in one word, so the view renders a
            // decided value instead of inferring one from two nulls.
            'source'    => $granted !== null ? 'grant' : ($effective !== null ? 'title' : 'none'),

            'has_account'  => $row['user_id'] !== null,
            'is_active'    => $row['user_id'] !== null && (int) $row['is_active'] === 1,
            'must_change'  => $row['user_id'] !== null && (int) $row['must_change_password'] === 1,
            'granted_at'   => $row['granted_at'] !== null ? (string) $row['granted_at'] : null,
            'granted_by'   => $row['granted_by_number'] !== null
                ? RosterPage::displayName(
                    (string) $row['granted_by_preferred'],
                    (string) $row['granted_by_first'],
                    (string) $row['granted_by_last'],
                    (string) $row['granted_by_number']
                )
                : null,

            // The teams this account is narrowed to, and whether the shape
            // applies to them at all. An Officer or a Senior Officer may hold
            // one (Phase 8.6) — an Officer to cover their own team plus one
            // they help with; anyone above already sees everything.
            'team_scope'      => $row['user_id'] !== null
                ? ($teamScopes[(int) $row['user_id']] ?? [])
                : [],
            'may_team_scope'  => $effective === Level::SeniorOfficer
                || $effective === Level::Officer,

            // The DIVISION override is read by nothing below Senior Officer:
            // ScopedQuery's and Access's Officer branches consult the team
            // and never the division. Offering the control anyway let an
            // Admin set it, be told it saved, and see no change — so the view
            // asks this before rendering it (Phase 8.6).
            'may_division_scope' => $effective !== null
                && $effective->atLeast(Level::SeniorOfficer),

            'scope_division_id'   => $row['scope_division_id'] !== null ? (int) $row['scope_division_id'] : null,
            'scope_team_id'       => $row['scope_team_id'] !== null ? (int) $row['scope_team_id'] : null,
            'scope_division_name' => (string) ($row['scope_division_name'] ?? ''),
            'scope_team_name'     => (string) ($row['scope_team_name'] ?? ''),

            // The two permission questions, asked HERE with a real Subject so
            // the view offers only controls the write path will honour. The
            // write asks them again — this is presentation, and hiding a
            // control hides nothing (CLAUDE.md).
            'may_designate' => Access::allows($user, Capability::DesignateAllowedUser, $subject),

            // Revocable by anyone who could have granted it (Phase 8
            // decided 2): the cap is on the GRANTED level, so a Senior
            // Officer may revoke an Officer-level grant an Admin made and may
            // not touch an Executive one.
            'may_revoke'    => $granted !== null
                && Access::allows($user, Capability::DesignateAllowedUser, $subject)
                && Access::mayGrant($user, $granted),

            // Resetting a password is equivalent to taking the account, so it
            // is capped against what the target can currently DO — their
            // EFFECTIVE level, not the granted half. Otherwise a Senior
            // Officer could seize an Admin who holds their level by title.
            'may_reset'     => $effective !== null
                && Access::allows($user, Capability::DesignateAllowedUser, $subject)
                && Access::mayGrant($user, $effective),
        ];
    }

    /**
     * The team scopes for a page of accounts, keyed by app_user id. One
     * query, never one per row.
     *
     * @param array<int, ?int> $userIds
     * @return array<int, array<int, array{id: int, name: string}>>
     */
    private function teamScopesFor(array $userIds): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            if ($id !== null && $id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $places = [];
        $bind   = [];
        foreach (array_values($ids) as $i => $id) {
            $places[]                  = ":scope_user_{$i}";
            $bind[":scope_user_{$i}"]  = $id;
        }

        $read = $this->pdo->prepare(
            'SELECT ut.app_user_id, t.id, t.name FROM app_user_team ut'
            . ' INNER JOIN team t ON t.id = ut.team_id'
            . ' WHERE ut.app_user_id IN (' . implode(', ', $places) . ')'
            . ' ORDER BY t.name'
        );
        $read->execute($bind);

        $byUser = [];
        foreach ($read->fetchAll() as $row) {
            $byUser[(int) $row['app_user_id']][] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $byUser;
    }

    /**
     * Every division, for the scope-override select. Not scoped: only an
     * Admin sees this control and an Admin's scope is the whole committee.
     *
     * @return array<int, array<string, mixed>>
     */
    private function divisions(): array
    {
        return $this->pdo
            ->query('SELECT id, name, is_placeholder FROM division ORDER BY is_placeholder, name')
            ->fetchAll();
    }

    /**
     * Every team, likewise.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teams(): array
    {
        return $this->pdo->query('SELECT id, name FROM team ORDER BY name')->fetchAll();
    }
}
