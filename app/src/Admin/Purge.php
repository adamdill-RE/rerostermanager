<?php

declare(strict_types=1);

namespace Rerm\Admin;

use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Subject;
use Rerm\Auth\User;
use Rerm\Roster\RosterPage;

/**
 * The purge and restore writes (spec 6.5, Phase 8 decided 4).
 *
 * **A purge is a soft delete and this is not negotiable.** It sets
 * `member.purged_at` and nothing else. No row is deleted, nothing cascades,
 * and `contact_log`, `assignment` and `member_metric` all survive intact —
 * every foreign key referencing `member` is RESTRICT for exactly this reason
 * (spec 5.5). There is no DELETE statement anywhere in this file and a test
 * asserts that by reading its source.
 *
 * **Restore exists because an import does not clear `purged_at`.** A member
 * who reappears in a later roster is un-dropped automatically, but
 * stays purged: without a control here, a mistaken purge is invisible forever
 * and needs somebody at the database. Restoring clears `purged_at` and
 * nothing else — the member returns to the roster with their history, their
 * metrics and their assignments exactly as they were, because none of them
 * ever went anywhere.
 *
 * Three things make the purge deliberate rather than a click (decided 4):
 *
 *   * **Per-member checkboxes, never a "purge everything flagged" button.**
 *     432 members sit on thin teams and 72 have no division; a bulk sweep
 *     over a list nobody read is how they leave.
 *   * **A typed confirmation.** The word, exactly, in a text field — the same
 *     level of deliberateness the import screen's two-step apply asks for.
 *   * **Fifty questions, not one.** Every selected member is checked
 *     individually with `Access::allows()` and their own Subject. The
 *     capability is Admin / Everywhere today, so the answer is always yes;
 *     the call is not decoration, it is what makes narrowing the capability
 *     later a one-line change rather than an audit of every write path.
 */
final class Purge
{
    /** The actions a request may name. Anything else is refused unread. */
    public const ACTIONS = ['purge', 'restore'];

    /**
     * Every outcome apply() can return. The handler turns each into a
     * sentence; tests/admin_test.php transcribes this list and holds the
     * handler to it.
     */
    public const OUTCOMES = [
        'purged', 'restored', 'nothing_selected', 'not_confirmed',
        'too_many', 'bad_action', 'nothing_to_do',
    ];

    /**
     * Typed, exactly, before a purge runs. Upper case because it is copied
     * from the screen rather than composed, and compared case-sensitively
     * for the same reason: a confirmation that accepts "confirm" is a
     * confirmation somebody types without reading.
     */
    public const CONFIRM_WORD = 'CONFIRM';

    /**
     * The most member ids one POST may carry. The screen offers 50 or 100, so
     * this is generous — and it REFUSES rather than truncating, because
     * silent truncation is what max_input_vars already does to this host.
     */
    public const MAX_SELECTION = 200;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db());
    }

    /**
     * $input is the POST body, route-shaped and untrusted:
     *
     *   action       one of ACTIONS
     *   member_id[]  the selected members
     *   confirm      CONFIRM_WORD, exactly (purge only)
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function apply(User $user, array $input): array
    {
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';

        $result = [
            'action'   => $action,
            'outcome'  => 'bad_action',
            'affected' => 0,
            'skipped'  => 0,
            'names'    => [],
        ];

        if (!in_array($action, self::ACTIONS, true)) {
            return $result;
        }

        $ids = self::memberIds($input['member_id'] ?? []);

        if ($ids === []) {
            $result['outcome'] = 'nothing_selected';

            return $result;
        }

        if (count($ids) > self::MAX_SELECTION) {
            $result['outcome'] = 'too_many';

            return $result;
        }

        // The typed word, before anything is read and long before anything is
        // written. Restoring does not ask for it: it is the reversible half.
        if ($action === 'purge'
            && (string) ($input['confirm'] ?? '') !== self::CONFIRM_WORD
        ) {
            $result['outcome'] = 'not_confirmed';

            return $result;
        }

        $rows = $this->membersFor($ids, $action);

        if ($rows === []) {
            $result['outcome'] = 'nothing_to_do';

            return $result;
        }

        $audit    = [];
        $affected = 0;
        $names    = [];

        $stamp = $action === 'purge'
            ? $this->pdo->prepare(
                'UPDATE member SET purged_at = UTC_TIMESTAMP()'
                . ' WHERE id = :id AND purged_at IS NULL AND is_system = 0'
            )
            : $this->pdo->prepare(
                'UPDATE member SET purged_at = NULL'
                . ' WHERE id = :id AND purged_at IS NOT NULL AND is_system = 0'
            );

        foreach ($rows as $row) {
            // Fifty ids in one POST are fifty questions. Out-of-scope ids are
            // counted and skipped rather than failing the whole action — the
            // other forty-nine were legitimate.
            if (!Access::allows($user, Capability::ImportRoster, $row['subject'])) {
                $result['skipped']++;
                continue;
            }

            $stamp->execute([':id' => $row['id']]);

            // Somebody else purged them between the render and the submit.
            // Not an error, and not counted: the outcome the Admin wanted is
            // the outcome they have.
            if ($stamp->rowCount() < 1) {
                continue;
            }

            $affected++;
            $names[] = $row['name'];

            $audit[] = [
                'action'    => $action === 'purge' ? Action::PurgeMember : Action::RestoreMember,
                'entity'    => 'member',
                'entity_id' => $row['member_number'],
                'before'    => ['purged_at' => $row['purged_at']],
                'after'     => [
                    'purged'  => $action === 'purge',
                    // Said in the audit row as well as on the screen: a purge
                    // takes nothing with it, and these are the counts that
                    // prove it if anybody asks in 2029.
                    'contact_log_rows_kept'  => $row['contact_count'],
                    'assignments_kept'       => $row['assignment_count'],
                ],
            ];
        }

        (new AuditLog($this->pdo))->recordMany($user, $audit);

        $result['affected'] = $affected;
        $result['names']    = array_slice($names, 0, 5);
        $result['outcome']  = $affected > 0
            ? ($action === 'purge' ? 'purged' : 'restored')
            : 'nothing_to_do';

        return $result;
    }

    /**
     * The selected member ids as ints: digits only, de-duplicated, and capped
     * a long way past any page this screen offers.
     *
     * @return array<int, int>
     */
    public static function memberIds(mixed $input): array
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

        // One past MAX_SELECTION, so a too-large selection is REFUSED by the
        // caller rather than silently trimmed here.
        return array_slice(array_values($ids), 0, self::MAX_SELECTION + 1);
    }

    /**
     * The member rows for a set of ids, restricted to the population the
     * action can legitimately touch: flagged-and-unpurged for a purge,
     * purged for a restore, and never a system row for either.
     *
     * Deliberately without a scope condition: scope is Access's question,
     * asked next against each row, so the refusal is decided by the matrix
     * rather than by the query.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function membersFor(array $ids, string $action): array
    {
        $places = [];
        $bind   = [];
        foreach (array_values($ids) as $i => $id) {
            $places[]                = ":purge_member_{$i}";
            $bind[":purge_member_{$i}"] = $id;
        }

        $population = $action === 'purge'
            ? 'm.purged_at IS NULL AND m.dropped_since_import_id IS NOT NULL'
            : 'm.purged_at IS NOT NULL';

        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.division_id, m.team_id, m.purged_at,'
            . ' (SELECT COUNT(*) FROM contact_log c WHERE c.member_id = m.id) AS contact_count,'
            . ' (SELECT COUNT(*) FROM assignment a WHERE a.member_id = m.id) AS assignment_count'
            . ' FROM member m'
            . ' WHERE m.id IN (' . implode(', ', $places) . ')'
            . " AND m.is_system = 0 AND {$population}"
        );
        $read->execute($bind);

        $rows = [];
        foreach ($read->fetchAll() as $row) {
            $rows[] = [
                'id'            => (int) $row['id'],
                'member_number' => (string) $row['member_number'],
                'name'          => RosterPage::displayName(
                    (string) $row['preferred_name'],
                    (string) $row['first_name'],
                    (string) $row['last_name'],
                    (string) $row['member_number']
                ),
                'purged_at'        => $row['purged_at'] !== null ? (string) $row['purged_at'] : null,
                'contact_count'    => (int) $row['contact_count'],
                'assignment_count' => (int) $row['assignment_count'],
                'subject'          => Subject::fromMemberRow($row),
            ];
        }

        return $rows;
    }
}
