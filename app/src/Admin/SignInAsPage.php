<?php

declare(strict_types=1);

namespace Rerm\Admin;

use PDO;
use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Forms\ApprovalFlow;
use Rerm\Roster\RosterPage;

/**
 * The Sign In As read (Phase 14.1, spec-v2 §16): every account an Admin may
 * run their session as, with enough beside each name to pick the right one.
 *
 * The list is ACCOUNTS, not members — app_user INNER JOIN member — because
 * only an account has a view to see, and only an active one: a deactivated
 * account is refused by Rerm\Auth\Auth::signInAs() anyway, and offering a
 * button the write path refuses is a screen lying. Admins are left out for
 * the reason signInAs() refuses them, and so is the caller.
 *
 * Beside each row: the level in force and where it came from, the team and
 * division, and — because the screen exists first of all to walk an approval
 * flow — where that account's RCF entries go, read from the same
 * ApprovalFlow table Create Approval Flow draws. "Approved by Bob", "top of
 * the chain" or "not set" is what tells an Admin which three accounts to
 * step through.
 *
 * The search is RosterPage::searchClause(), the one spelling, with the same
 * three-character floor. There is no paging: ~196 accounts is one list, and
 * a ceiling of 300 rows with a sentence above it is the honest answer if a
 * roster ever grows past that.
 */
final class SignInAsPage
{
    private const SEARCH_MIN_CHARS = 3;

    /** Rows drawn at most; past it the screen says so and asks for a search. */
    public const CEILING = 300;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db());
    }

    /**
     * The list, and everything the screen needs to describe itself.
     *
     * @param array<string, mixed> $input the raw query string, untrusted
     * @return array<string, mixed>
     */
    public function page(User $admin, array $input): array
    {
        $searchRaw = trim((string) ($input['q'] ?? ''));
        $tooShort  = $searchRaw !== '' && mb_strlen($searchRaw) < self::SEARCH_MIN_CHARS;
        $search    = $tooShort ? '' : $searchRaw;

        $where = 'u.is_active = 1 AND m.purged_at IS NULL'
            . ' AND u.effective_level <> :admin_level AND u.id <> :self';
        $bind  = [':admin_level' => Level::Admin->value, ':self' => $admin->id];

        if ($search !== '') {
            [$clause, $searchBind] = RosterPage::searchClause($search);
            $where .= ' AND ' . $clause;
            $bind  += $searchBind;
        }

        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM app_user u INNER JOIN member m ON m.id = u.member_id WHERE ' . $where
        );
        $count->execute($bind);
        $total = (int) $count->fetchColumn();

        // Effective level from the schema's own VIRTUAL column, never
        // re-derived (Phase 8 inherits); broadest first, so the Executive
        // Officers an approval chain ends with are at the top of the list,
        // then by name within a level.
        $read = $this->pdo->prepare(
            'SELECT u.id, u.effective_level, u.granted_level, u.must_change_password,'
            . ' m.member_number, m.first_name, m.last_name, m.preferred_name, m.title,'
            . ' t.name AS team_name, d.name AS division_name, d.is_placeholder'
            . ' FROM app_user u'
            . ' INNER JOIN member m ON m.id = u.member_id'
            . ' LEFT JOIN team t ON t.id = m.team_id'
            . ' INNER JOIN division d ON d.id = m.division_id'
            . ' WHERE ' . $where
            . " ORDER BY FIELD(u.effective_level, 'executive_officer', 'senior_officer', 'officer', 'member'),"
            . ' m.last_name, m.first_name, m.member_number'
            . ' LIMIT ' . self::CEILING
        );
        $read->execute($bind);

        $flow  = $this->flow();
        $names = $flow['names'];
        $table = $flow['table'];

        $rows = [];
        foreach ($read->fetchAll() as $row) {
            $id    = (int) $row['id'];
            $level = Level::from((string) $row['effective_level']);
            $drawn = $table[$id] ?? null;

            // The same three words Create Approval Flow spells, from the
            // same table (spec-v2 §15.3). Below the flow's floor the
            // question does not arise and the cell says so instead.
            if (!$level->atLeast(ApprovalFlow::FLOOR)) {
                $route = ['state' => 'below', 'approver' => ''];
            } elseif ($drawn === null || (!$drawn['top'] && $drawn['approver'] === null)) {
                $route = ['state' => 'unset', 'approver' => ''];
            } elseif ($drawn['top']) {
                $route = ['state' => 'top', 'approver' => ''];
            } else {
                $route = ['state' => 'approver', 'approver' => $names[$drawn['approver']] ?? ('account #' . $drawn['approver'])];
            }

            $rows[] = [
                'id'            => $id,
                'name'          => RosterPage::displayName(
                    (string) $row['preferred_name'],
                    (string) $row['first_name'],
                    (string) $row['last_name'],
                    (string) $row['member_number']
                ),
                'member_number' => (string) $row['member_number'],
                'title'         => (string) $row['title'],
                'level'         => $level,
                'source'        => $row['granted_level'] !== null ? 'grant' : 'title',
                'must_change'   => (int) $row['must_change_password'] === 1,
                'team_name'     => (string) ($row['team_name'] ?? ''),
                'division_name' => (int) ($row['is_placeholder'] ?? 0) === 1 ? '' : (string) ($row['division_name'] ?? ''),
                'flow'          => $route,
            ];
        }

        return [
            'rows'             => $rows,
            'total'            => $total,
            'ceiling'          => self::CEILING,
            'search'           => $searchRaw,
            'search_applied'   => $search !== '',
            'search_too_short' => $tooShort,
            'search_min_chars' => self::SEARCH_MIN_CHARS,
        ];
    }

    /**
     * The approval table and a name for every account it can point at.
     *
     * Tolerant of the table not existing yet: migration 013 may not have
     * been applied, and this screen is useful before it is — the flow cell
     * then reads "not set" everywhere, which is also true.
     *
     * @return array{table: array<int, array{approver: ?int, top: bool}>, names: array<int, string>}
     */
    private function flow(): array
    {
        try {
            $table = (new ApprovalFlow($this->pdo))->table();
        } catch (\PDOException) {
            return ['table' => [], 'names' => []];
        }

        $wanted = [];
        foreach ($table as $drawn) {
            if ($drawn['approver'] !== null) {
                $wanted[$drawn['approver']] = $drawn['approver'];
            }
        }
        if ($wanted === []) {
            return ['table' => $table, 'names' => []];
        }

        $places = [];
        $bind   = [];
        foreach (array_values($wanted) as $i => $id) {
            $places[]           = ":ap_{$i}";
            $bind[":ap_{$i}"]   = $id;
        }
        $read = $this->pdo->prepare(
            'SELECT u.id, m.member_number, m.first_name, m.last_name, m.preferred_name'
            . ' FROM app_user u INNER JOIN member m ON m.id = u.member_id'
            . ' WHERE u.id IN (' . implode(', ', $places) . ')'
        );
        $read->execute($bind);

        $names = [];
        foreach ($read->fetchAll() as $row) {
            $names[(int) $row['id']] = RosterPage::displayName(
                (string) $row['preferred_name'],
                (string) $row['first_name'],
                (string) $row['last_name'],
                (string) $row['member_number']
            );
        }

        return ['table' => $table, 'names' => $names];
    }
}
