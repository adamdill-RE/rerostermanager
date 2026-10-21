<?php

declare(strict_types=1);

namespace Rerm\Forms;

use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Roster\RosterPage;

/**
 * Who approves whom (Phase 14, spec-v2 §15.3) — the table an Admin draws on
 * Create Approval Flow, and the walk up it that every entry makes.
 *
 * One row per account in `rcf_approver`, three states spelled by two
 * columns, and all three are shown in words rather than inferred:
 *
 *   approver set     their entries wait on that account
 *   top of the chain nobody is above them; their entries are approved the
 *                    moment they are submitted and they generate the forms
 *   not set          their entries are REFUSED with a sentence naming this
 *                    screen — never routed to a default, never approved by
 *                    accident
 *
 * The CHAIN for an entry is requester -> approver -> approver's approver ->
 * ... -> top. It is walked twice: once here when the Admin saves, over the
 * whole proposed table, so a loop is refused by name before it is written;
 * and once per approval, from the approving account with the accounts the
 * entry has already passed through, so a table redrawn while an entry is
 * in flight cannot send it round in a circle — an account seen twice ends
 * the chain there. walk() is a pure function over an array so the test can
 * hand it a loop without a database.
 *
 * The table is CURRENT STATE, not a record — the same reasoning
 * tests/admin_test.php gives for app_user_team. A redrawn approver replaces
 * the old one and audit_log carries before and after (`set_approver`).
 * Nothing deletes a row: "not set" is both values empty.
 *
 * Only accounts at Senior Officer and above, and active, are offered on
 * either side: the request said "Vice Chairmen and above", and a Vice
 * Chairman is a Senior Officer. Nothing here reads or writes the roster.
 */
final class ApprovalFlow
{
    /** The floor for both sides of the table (spec-v2 §15.2). */
    public const FLOOR = Level::SeniorOfficer;

    /** What a select's value spells for the two non-account states. */
    public const UNSET = '';
    public const TOP   = 'top';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db());
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /**
     * Every account the table may name, with what is drawn for each and the
     * chain it leads to: active accounts whose EFFECTIVE level reaches the
     * floor, ordered by name. One query plus the rows, so the screen is two
     * reads however many Vice Chairmen there are.
     *
     * @return array<int, array<string, mixed>>
     */
    public function accounts(): array
    {
        $levels = [];
        $bind   = [];
        foreach (Level::cases() as $i => $level) {
            if ($level->atLeast(self::FLOOR)) {
                $levels[]          = ":af_lvl_{$i}";
                $bind[":af_lvl_{$i}"] = $level->value;
            }
        }

        $read = $this->pdo->prepare(
            'SELECT u.id, u.effective_level, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.title, t.name AS team_name, d.name AS division_name, d.is_placeholder'
            . ' FROM app_user u'
            . ' INNER JOIN member m ON m.id = u.member_id'
            . ' LEFT JOIN team t ON t.id = m.team_id'
            . ' LEFT JOIN division d ON d.id = m.division_id'
            . ' WHERE u.is_active = 1 AND u.effective_level IN (' . implode(', ', $levels) . ')'
            . ' AND m.purged_at IS NULL'
            . ' ORDER BY m.last_name, m.first_name, m.member_number'
        );
        $read->execute($bind);

        $table    = $this->table();
        $approves = self::approvesCounts($table);
        $accounts = [];
        foreach ($read->fetchAll() as $row) {
            $id    = (int) $row['id'];
            $drawn = $table[$id] ?? ['approver' => null, 'top' => false];

            $accounts[$id] = [
                'id'            => $id,
                'name'          => RosterPage::displayName(
                    (string) $row['preferred_name'],
                    (string) $row['first_name'],
                    (string) $row['last_name'],
                    (string) $row['member_number']
                ),
                'member_number' => (string) $row['member_number'],
                'title'         => (string) $row['title'],
                'level'         => Level::from((string) $row['effective_level']),
                'team_name'     => (string) ($row['team_name'] ?? ''),
                'division_name' => (int) ($row['is_placeholder'] ?? 0) === 1 ? '' : (string) ($row['division_name'] ?? ''),
                'approver_id'   => $drawn['approver'],
                'is_top'        => $drawn['top'],
                'state'         => self::state($drawn),
                // How many accounts are drawn as approved by this one. An
                // account that approves others and is itself "not set" is
                // the trap Phase 14.5 names (spec-v2 §19.2): their chain
                // ends with this account, and this account's own requests
                // are refused. The screen says so beside the row.
                'approves'      => $approves[$id] ?? 0,
                // The select's value for what is drawn now.
                'value'         => $drawn['top'] ? self::TOP : ($drawn['approver'] === null ? self::UNSET : (string) $drawn['approver']),
            ];
        }

        // The chain each account's entries would take, in names, so the Admin
        // reads "→ Bob → Carl" beside the row rather than following arrows.
        foreach ($accounts as $id => $account) {
            $walk  = self::walk($table, $id);
            $names = [];
            foreach ($walk['chain'] as $step) {
                $names[] = $accounts[$step]['name'] ?? ('account #' . $step);
            }
            $accounts[$id]['chain']     = $names;
            $accounts[$id]['chain_end'] = $walk['end'];
        }

        return array_values($accounts);
    }

    /**
     * The table as drawn: app_user id => approver id (or null) and whether
     * they are the top. Rows with neither value are "not set" and are
     * returned too, so a redrawn row never reads as a missing one.
     *
     * @return array<int, array{approver: ?int, top: bool}>
     */
    public function table(): array
    {
        $table = [];
        foreach ($this->pdo->query('SELECT app_user_id, approver_user_id, is_top FROM rcf_approver')->fetchAll() as $row) {
            $table[(int) $row['app_user_id']] = [
                'approver' => $row['approver_user_id'] === null ? null : (int) $row['approver_user_id'],
                'top'      => (int) $row['is_top'] === 1,
            ];
        }

        return $table;
    }

    /**
     * How many accounts are drawn as approved by this one — directly, not
     * the whole chain below. Zero for an account nobody routes to.
     */
    public function approvesCount(int $userId): int
    {
        return self::approvesCounts($this->table())[$userId] ?? 0;
    }

    /**
     * approver id => how many accounts name them, over a table shaped like
     * table(). Pure, so accounts() counts once for the whole screen.
     *
     * @param array<int, array{approver: ?int, top: bool}> $table
     * @return array<int, int>
     */
    public static function approvesCounts(array $table): array
    {
        $counts = [];
        foreach ($table as $drawn) {
            if ($drawn['approver'] !== null) {
                $counts[$drawn['approver']] = ($counts[$drawn['approver']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Where this account's entries go when submitted (spec-v2 §15.3).
     *
     *   ['state' => 'approver', 'approver' => id]  wait on that account
     *   ['state' => 'top',      'approver' => null] approved on submission
     *   ['state' => 'unset',    'approver' => null] refused
     *
     * An Admin with no row is the top of the chain: the account that would
     * otherwise be drawing its own approver.
     *
     * @return array{state: string, approver: ?int}
     */
    public function routeFor(User $requester): array
    {
        $table = $this->table();
        $drawn = $table[$requester->id] ?? null;

        if ($drawn === null && $requester->level === Level::Admin) {
            return ['state' => 'top', 'approver' => null];
        }

        $drawn ??= ['approver' => null, 'top' => false];

        return ['state' => self::state($drawn), 'approver' => $drawn['approver']];
    }

    /**
     * Who an entry goes to after this approver approves it — the approver's
     * own approver — or null when the chain ends here: they are the top, or
     * nothing is drawn above them, or the next account has already handled
     * this entry (the table was redrawn into a loop while it was in flight).
     *
     * An approver with nothing drawn above them ENDS the chain rather than
     * refusing the approval: a half-drawn table is the Admin's to finish,
     * and an approver who cannot act on what is in front of them helps
     * nobody. The screen says so beside the row ("then ready to generate").
     *
     * @param array<int, int> $visited accounts the entry has already passed through
     */
    public function nextAfter(int $approverId, array $visited): ?int
    {
        $table = $this->table();
        $drawn = $table[$approverId] ?? null;
        if ($drawn === null || $drawn['top'] || $drawn['approver'] === null) {
            return null;
        }

        $next = $drawn['approver'];
        if ($next === $approverId || in_array($next, $visited, true)) {
            return null;
        }

        return $next;
    }

    /**
     * The chain from an account, as a pure function over a table shaped like
     * table(): the approvers in order, and how it ended.
     *
     *   'top'    the last account is the top of the chain (or an account
     *            with nothing drawn above it, which ends a walk the same way)
     *   'unset'  the FIRST account has nothing drawn — entries are refused
     *   'loop'   an account was reached twice; the chain is a circle
     *
     * @param array<int, array{approver: ?int, top: bool}> $table
     * @return array{chain: array<int, int>, end: string}
     */
    public static function walk(array $table, int $from): array
    {
        $drawn = $table[$from] ?? ['approver' => null, 'top' => false];
        if (!$drawn['top'] && $drawn['approver'] === null) {
            return ['chain' => [], 'end' => 'unset'];
        }

        $chain   = [];
        $visited = [$from => true];
        $current = $from;

        while (true) {
            $drawn = $table[$current] ?? ['approver' => null, 'top' => false];
            if ($drawn['top'] || $drawn['approver'] === null) {
                return ['chain' => $chain, 'end' => 'top'];
            }

            $next = $drawn['approver'];
            if (isset($visited[$next])) {
                $chain[] = $next;

                return ['chain' => $chain, 'end' => 'loop'];
            }

            $visited[$next] = true;
            $chain[]        = $next;
            $current        = $next;

            // A table cannot be longer than its rows; this is belt and braces
            // against a corrupt one rather than a path a test can reach.
            if (count($chain) > count($table) + 1) {
                return ['chain' => $chain, 'end' => 'loop'];
            }
        }
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * Saves what the Admin drew: `approver[<account id>]` is '' (not set),
     * 'top', or another listed account's id. Only listed accounts may appear
     * on either side, an account cannot approve itself, and a table that
     * loops is refused WHOLE, naming the accounts in the loop, with nothing
     * written. Unchanged rows write nothing, not even an audit row.
     *
     * @param array<string, mixed> $input the raw POST, untrusted
     * @return array{saved: int, refused: ?string}
     */
    public function save(User $admin, array $input): array
    {
        $accounts = [];
        foreach ($this->accounts() as $account) {
            $accounts[(int) $account['id']] = $account;
        }

        $typed    = is_array($input['approver'] ?? null) ? $input['approver'] : [];
        $current  = $this->table();
        $proposed = $current;
        $changes  = [];

        foreach ($typed as $key => $value) {
            $id = (int) $key;
            if (!isset($accounts[$id]) || !is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === self::TOP) {
                $drawn = ['approver' => null, 'top' => true];
            } elseif ($value === self::UNSET) {
                $drawn = ['approver' => null, 'top' => false];
            } else {
                $approver = (int) $value;
                if ($approver === $id || !isset($accounts[$approver])) {
                    // Not an offered choice: a POST naming an account that was
                    // not in the list produces nothing, not a choice of the
                    // caller's making.
                    continue;
                }
                $drawn = ['approver' => $approver, 'top' => false];
            }

            $was = $current[$id] ?? ['approver' => null, 'top' => false];
            if ($was === $drawn) {
                continue;
            }

            $proposed[$id] = $drawn;
            $changes[$id]  = ['before' => $was, 'after' => $drawn];
        }

        if ($changes === []) {
            return ['saved' => 0, 'refused' => null];
        }

        // Walk from every account the change could affect — every account in
        // the proposed table — and refuse the whole save on the first loop.
        foreach (array_keys($proposed) as $from) {
            $walk = self::walk($proposed, $from);
            if ($walk['end'] === 'loop') {
                $names = [];
                foreach ([$from, ...$walk['chain']] as $step) {
                    $names[] = $accounts[$step]['name'] ?? ('account #' . $step);
                }

                return [
                    'saved'   => 0,
                    'refused' => 'That would make a loop — ' . implode(' → ', $names)
                        . ' — and a request on a loop never reaches anybody. Nothing was saved.',
                ];
            }
        }

        $this->pdo->beginTransaction();

        try {
            $write = $this->pdo->prepare(
                'INSERT INTO rcf_approver (app_user_id, approver_user_id, is_top, set_by, set_at)'
                . ' VALUES (:id, :approver, :top, :by, UTC_TIMESTAMP())'
                . ' ON DUPLICATE KEY UPDATE approver_user_id = VALUES(approver_user_id),'
                . ' is_top = VALUES(is_top), set_by = VALUES(set_by), set_at = VALUES(set_at)'
            );

            $audit = [];
            foreach ($changes as $id => $change) {
                $write->execute([
                    ':id'       => $id,
                    ':approver' => $change['after']['approver'],
                    ':top'      => $change['after']['top'] ? 1 : 0,
                    ':by'       => $admin->id,
                ]);

                $audit[] = [
                    'action'    => Action::SetApprover,
                    'entity'    => 'app_user',
                    'entity_id' => (string) $id,
                    'before'    => self::described($change['before'], $accounts),
                    'after'     => self::described($change['after'], $accounts),
                ];
            }

            (new AuditLog($this->pdo))->recordMany($admin, $audit);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return ['saved' => count($changes), 'refused' => null];
    }

    /** The one-word state a drawn row is in. */
    private static function state(array $drawn): string
    {
        if ($drawn['top']) {
            return 'top';
        }

        return $drawn['approver'] === null ? 'unset' : 'approver';
    }

    /**
     * A drawn row as the audit log keeps it: the state, and the approver by
     * id AND name, so the row reads without a join years from now.
     *
     * @param array{approver: ?int, top: bool} $drawn
     * @param array<int, array<string, mixed>> $accounts
     * @return array<string, mixed>
     */
    private static function described(array $drawn, array $accounts): array
    {
        return [
            'state'         => self::state($drawn),
            'approver_id'   => $drawn['approver'],
            'approver_name' => $drawn['approver'] === null ? null : ($accounts[$drawn['approver']]['name'] ?? null),
        ];
    }
}
