<?php

declare(strict_types=1);

namespace Rerm\Admin;

use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\User;
use Rerm\Roster\EligibleOfficers;
use Throwable;

/**
 * Show years: create, set active, open, close — and the rollover (spec 5.1,
 * Phase 8 decided 1 and 5).
 *
 * **Closing warns; it does not refuse (decided 1).** The screen says how many
 * metric progress rows are still `in_progress` or `claimed_complete` and
 * closes anyway, freezing them as they are. A metric stuck mid-chase is the
 * normal end-of-year state — people say they will pay and then do not — so
 * refusing would mean faking edits in order to be allowed to close. The count
 * is shown before the confirm and recorded in the audit row afterwards.
 *
 * **Closing freezes. It never clears.** Not `member_metric`, not
 * `assignment`, and above all not `contact_log`: spec 5.5 is absolute, and
 * producing a member's history back to 2026 in 2029 is the v2 feature this
 * constraint exists to keep possible. There is no DELETE anywhere in this
 * file and a test asserts that by reading its source.
 *
 * **A rollover carries forward only ELIGIBLE assignments (decided 5).** This
 * supersedes spec 5.1 as written, which carried an ineligible assignment
 * anyway and flagged it — a decision made before Phase 6 turned "officer no
 * longer eligible" into a real, visible bucket somebody works. A member whose
 * only officer no longer qualifies now arrives in the new year UNASSIGNED,
 * in bucket 1 where the work already happens, rather than pre-loaded into
 * bucket 2 as cleanup nobody asked for.
 *
 * Eligible means what `EligibleOfficers` already means by it and nothing new:
 * a visible member (not system, not purged, not absent-flagged), still on
 * that member's team, still at Officer level or above by effective level.
 * Rank comparison in PHP, never a SQL `>=` on the ENUM.
 *
 * Carried rows are **copied, not shared** — new rows against the new show
 * year, so editing this year's cannot rewrite last year's record. Metrics and
 * contacts do NOT carry: last year's dues and last year's phone calls say
 * nothing about this year (spec 5.1).
 */
final class ShowYears
{
    /** The actions a request may name. Anything else is refused unread. */
    public const ACTIONS = ['create', 'activate', 'open', 'close', 'carry'];

    /**
     * Every outcome apply() can return. The handler turns each into a
     * sentence; the test suite transcribes this list and holds the handler
     * to it.
     */
    public const OUTCOMES = [
        'created', 'activated', 'opened', 'closed', 'carried',
        'bad_label', 'duplicate_label', 'bad_dates', 'not_found', 'unchanged',
        'bad_action', 'same_year', 'target_closed', 'nothing_to_carry', 'not_confirmed',
    ];

    /** Typed before a close or a rollover, exactly as on the purge screen. */
    public const CONFIRM_WORD = 'CONFIRM';

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
     *   action     one of ACTIONS
     *   label      the new year's name (create)
     *   starts_on  / ends_on   optional dates (create)
     *   year_id    the year to act on (activate, open, close)
     *   from_year  / to_year   the rollover's source and target (carry)
     *   confirm    CONFIRM_WORD, exactly (close, carry)
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function apply(User $user, array $input): array
    {
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';

        $result = [
            'action'  => $action,
            'outcome' => 'bad_action',
            'label'   => '',
            'carried' => 0,
            'dropped' => 0,
            'in_progress' => 0,
        ];

        if (!in_array($action, self::ACTIONS, true)) {
            return $result;
        }

        return match ($action) {
            'create'   => $this->create($user, $input, $result),
            'activate' => $this->setState($user, $input, $result, 'activate'),
            'open'     => $this->setState($user, $input, $result, 'open'),
            'close'    => $this->setState($user, $input, $result, 'close'),
            default    => $this->carry($user, $input, $result),
        };
    }

    /**
     * Every show year with the counts the screen needs to describe it: how
     * many members carry metric rows against it, how many assignments stand,
     * how many contacts were logged, and how much progress is still mid-chase.
     *
     * @return array<int, array<string, mixed>>
     */
    public function years(): array
    {
        $read = $this->pdo->query(
            'SELECT y.id, y.label, y.starts_on, y.ends_on, y.is_open, y.is_active, y.created_at,'
            . ' (SELECT COUNT(DISTINCT mm.member_id) FROM member_metric mm'
            . '   WHERE mm.show_year_id = y.id) AS members,'
            . ' (SELECT COUNT(*) FROM assignment a'
            . '   WHERE a.show_year_id = y.id AND a.removed_at IS NULL) AS assignments,'
            . ' (SELECT COUNT(*) FROM contact_log c WHERE c.show_year_id = y.id) AS contacts,'
            . ' (SELECT COUNT(*) FROM member_metric mm2'
            . "   WHERE mm2.show_year_id = y.id AND mm2.progress <> 'not_started') AS in_progress"
            . ' FROM show_year y ORDER BY y.is_active DESC, y.label DESC'
        );

        $years = [];
        foreach ($read->fetchAll() as $row) {
            $years[] = [
                'id'          => (int) $row['id'],
                'label'       => (string) $row['label'],
                'starts_on'   => $row['starts_on'] !== null ? (string) $row['starts_on'] : null,
                'ends_on'     => $row['ends_on'] !== null ? (string) $row['ends_on'] : null,
                'is_open'     => (int) $row['is_open'] === 1,
                'is_active'   => (int) $row['is_active'] === 1,
                'members'     => (int) $row['members'],
                'assignments' => (int) $row['assignments'],
                'contacts'    => (int) $row['contacts'],
                'in_progress' => (int) $row['in_progress'],
            ];
        }

        return $years;
    }

    /**
     * What a rollover from one year to another WOULD do — both numbers,
     * before anything is written (decided 5: "It is never silent. The
     * rollover reports both numbers before it runs and logs them after").
     *
     * @return array{carry: int, drop: int}
     */
    public function rolloverPreview(int $fromYearId, int $toYearId): array
    {
        // The eligibility predicate appears TWICE in this statement, once per
        // SUM, so it is built twice with different placeholder prefixes: a
        // named placeholder cannot be reused within one statement here,
        // emulated prepares being off. Same fragment, same rule, two names —
        // and the fragment itself is EligibleOfficers', so the counts cannot
        // drift from what the copy below actually does.
        [$carryable, $carryBind] = EligibleOfficers::assignmentIsBroken('a', 'm', 'rollcarry');
        [$droppable, $dropBind]  = EligibleOfficers::assignmentIsBroken('a', 'm', 'rolldrop');

        $read = $this->pdo->prepare(
            'SELECT'
            // NOT broken = the officer is still a visible member, still on
            // this member's team, still at Officer level or above.
            . " SUM(CASE WHEN {$carryable} THEN 0 ELSE 1 END) AS carry,"
            . " SUM(CASE WHEN {$droppable} THEN 1 ELSE 0 END) AS drop_count"
            . ' FROM assignment a'
            . ' INNER JOIN member m ON m.id = a.member_id'
            . ' WHERE a.show_year_id = :from_year AND a.removed_at IS NULL'
            // Already carried: a second run reports nothing left to do
            // rather than re-counting what it already copied.
            . ' AND NOT EXISTS (SELECT 1 FROM assignment existing'
            . '   WHERE existing.member_id = a.member_id'
            . '     AND existing.officer_member_id = a.officer_member_id'
            . '     AND existing.show_year_id = :to_year'
            . '     AND existing.removed_at IS NULL)'
        );

        $read->execute(
            $carryBind + $dropBind + [':from_year' => $fromYearId, ':to_year' => $toYearId]
        );
        $row = $read->fetch();

        return [
            'carry' => (int) ($row['carry'] ?? 0),
            'drop'  => (int) ($row['drop_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function create(User $user, array $input, array $result): array
    {
        $label = trim((string) ($input['label'] ?? ''));
        $result['label'] = $label;

        if ($label === '' || mb_strlen($label) > 32) {
            $result['outcome'] = 'bad_label';

            return $result;
        }

        $starts = self::optionalDate($input['starts_on'] ?? '');
        $ends   = self::optionalDate($input['ends_on'] ?? '');

        if ($starts === false || $ends === false
            || ($starts !== null && $ends !== null && $starts > $ends)
        ) {
            $result['outcome'] = 'bad_dates';

            return $result;
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM show_year WHERE label = :label');
        $exists->execute([':label' => $label]);
        if ($exists->fetchColumn() !== false) {
            $result['outcome'] = 'duplicate_label';

            return $result;
        }

        // Created OPEN and NOT active. Making it active is a second,
        // deliberate act: activating a year is what every officer's dashboard
        // switches to, and it must not happen as a side effect of typing a
        // name into a box.
        $this->pdo->prepare(
            'INSERT INTO show_year (label, starts_on, ends_on, is_open, is_active)'
            . ' VALUES (:label, :starts, :ends, 1, 0)'
        )->execute([':label' => $label, ':starts' => $starts, ':ends' => $ends]);

        $id = (int) $this->pdo->lastInsertId();

        (new AuditLog($this->pdo))->record(
            $user,
            Action::CreateShowYear,
            'show_year',
            (string) $id,
            null,
            ['label' => $label, 'starts_on' => $starts, 'ends_on' => $ends, 'is_open' => true, 'is_active' => false]
        );

        $result['outcome'] = 'created';

        return $result;
    }

    /**
     * Activate, open or close one year.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function setState(User $user, array $input, array $result, string $what): array
    {
        $year = $this->year((int) ($input['year_id'] ?? 0));

        if ($year === null) {
            $result['outcome'] = 'not_found';

            return $result;
        }

        $result['label']       = $year['label'];
        $result['in_progress'] = $year['in_progress'];

        // Closing is the irreversible-feeling one, so it asks for the word.
        // Activating and re-opening are not: both are one click to undo.
        if ($what === 'close'
            && (string) ($input['confirm'] ?? '') !== self::CONFIRM_WORD
        ) {
            $result['outcome'] = 'not_confirmed';

            return $result;
        }

        if (($what === 'activate' && $year['is_active'])
            || ($what === 'open' && $year['is_open'])
            || ($what === 'close' && !$year['is_open'])
        ) {
            $result['outcome'] = 'unchanged';

            return $result;
        }

        $before = ['is_open' => $year['is_open'], 'is_active' => $year['is_active']];

        if ($what === 'activate') {
            // Exactly one active row, enforced by uq_show_year_active over a
            // generated column. The old one has to be cleared BEFORE the new
            // one is set or the unique key refuses, so both statements are one
            // transaction: a failure between them would leave no active year
            // at all, and every screen in the application reads that row.
            $this->pdo->beginTransaction();

            try {
                $this->pdo->exec('UPDATE show_year SET is_active = 0 WHERE is_active = 1');
                $this->pdo->prepare('UPDATE show_year SET is_active = 1 WHERE id = :id')
                    ->execute([':id' => $year['id']]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();

                throw $e;
            }

            $action = Action::SetActiveShowYear;
            $after  = ['is_active' => true];
            $result['outcome'] = 'activated';
        } else {
            $open = $what === 'open' ? 1 : 0;

            $this->pdo->prepare('UPDATE show_year SET is_open = :open WHERE id = :id')
                ->execute([':open' => $open, ':id' => $year['id']]);

            $action = $what === 'open' ? Action::OpenShowYear : Action::CloseShowYear;

            // Decided 1: the count of metrics frozen mid-chase goes into the
            // record, so "why is Johnson still In Progress in the 2027 file"
            // has an answer that is not a guess.
            $after = $what === 'open'
                ? ['is_open' => true]
                : ['is_open' => false, 'progress_rows_frozen' => $year['in_progress']];

            $result['outcome'] = $what === 'open' ? 'opened' : 'closed';
        }

        (new AuditLog($this->pdo))->record(
            $user,
            $action,
            'show_year',
            (string) $year['id'],
            $before,
            $after
        );

        return $result;
    }

    /**
     * The rollover (decided 5): copy the still-eligible assignments from one
     * year into another, and report what was dropped.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function carry(User $user, array $input, array $result): array
    {
        $from = $this->year((int) ($input['from_year'] ?? 0));
        $to   = $this->year((int) ($input['to_year'] ?? 0));

        if ($from === null || $to === null) {
            $result['outcome'] = 'not_found';

            return $result;
        }

        if ($from['id'] === $to['id']) {
            $result['outcome'] = 'same_year';

            return $result;
        }

        // Carrying INTO a closed year would be writing to a frozen record.
        // The source may be closed — that is the ordinary case, and the whole
        // point of a rollover.
        if (!$to['is_open']) {
            $result['outcome']  = 'target_closed';
            $result['label']    = $to['label'];

            return $result;
        }

        if ((string) ($input['confirm'] ?? '') !== self::CONFIRM_WORD) {
            $result['outcome'] = 'not_confirmed';

            return $result;
        }

        // Counted BEFORE the write, from the same predicate the write uses,
        // so the number reported is the number that happened.
        $preview = $this->rolloverPreview($from['id'], $to['id']);

        $result['label']   = $to['label'];
        $result['dropped'] = $preview['drop'];

        if ($preview['carry'] === 0) {
            $result['outcome'] = $preview['drop'] > 0 ? 'carried' : 'nothing_to_carry';
            $result['carried'] = 0;

            if ($result['outcome'] === 'carried') {
                $this->auditCarry($user, $from, $to, 0, $preview['drop']);
            }

            return $result;
        }

        [$broken, $bind] = EligibleOfficers::assignmentIsBroken('a', 'm', 'roll');

        // COPIED, never shared: new rows against the new year, so editing
        // this year's assignment cannot rewrite last year's record. assigned_by
        // is the person who ran the rollover, which is the truth — nobody
        // re-made these decisions, somebody carried them.
        $copy = $this->pdo->prepare(
            'INSERT INTO assignment (member_id, officer_member_id, show_year_id, assigned_by)'
            . ' SELECT a.member_id, a.officer_member_id, :to_year, :actor'
            . ' FROM assignment a'
            . ' INNER JOIN member m ON m.id = a.member_id'
            . ' WHERE a.show_year_id = :from_year AND a.removed_at IS NULL'
            . "   AND NOT ({$broken})"
            . '   AND NOT EXISTS (SELECT 1 FROM assignment existing'
            . '     WHERE existing.member_id = a.member_id'
            . '       AND existing.officer_member_id = a.officer_member_id'
            . '       AND existing.show_year_id = :to_year_check'
            . '       AND existing.removed_at IS NULL)'
        );
        $copy->execute($bind + [
            ':to_year'       => $to['id'],
            ':to_year_check' => $to['id'],
            ':from_year'     => $from['id'],
            ':actor'         => $user->id,
        ]);

        $result['carried'] = $copy->rowCount();
        $result['outcome'] = 'carried';

        $this->auditCarry($user, $from, $to, $result['carried'], $preview['drop']);

        return $result;
    }

    /**
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     */
    private function auditCarry(User $user, array $from, array $to, int $carried, int $dropped): void
    {
        (new AuditLog($this->pdo))->record(
            $user,
            Action::CarryAssignments,
            'show_year',
            (string) $to['id'],
            ['from_show_year' => $from['label'], 'from_show_year_id' => $from['id']],
            [
                'to_show_year' => $to['label'],
                'carried'      => $carried,
                // Decided 5: the drop is recorded, never silent. These are
                // members who arrive in the new year unassigned, and this row
                // is why.
                'dropped_ineligible' => $dropped,
            ]
        );
    }

    /**
     * One show year with the counts a decision about it needs.
     *
     * @return ?array<string, mixed>
     */
    private function year(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $read = $this->pdo->prepare(
            'SELECT y.id, y.label, y.is_open, y.is_active,'
            . ' (SELECT COUNT(*) FROM member_metric mm'
            . "   WHERE mm.show_year_id = y.id AND mm.progress <> 'not_started') AS in_progress"
            . ' FROM show_year y WHERE y.id = :id'
        );
        $read->execute([':id' => $id]);
        $row = $read->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'id'          => (int) $row['id'],
            'label'       => (string) $row['label'],
            'is_open'     => (int) $row['is_open'] === 1,
            'is_active'   => (int) $row['is_active'] === 1,
            'in_progress' => (int) $row['in_progress'],
        ];
    }

    /**
     * A YYYY-MM-DD from a date input, null for blank, or false for anything
     * else. Parsed with DateTimeImmutable because `intl` is absent from this
     * host (docs/hosting.md) and there is no IntlDateFormatter to reach for.
     */
    private static function optionalDate(mixed $value): string|null|false
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date === false ? false : $date->format('Y-m-d');
    }
}
