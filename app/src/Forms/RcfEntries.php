<?php

declare(strict_types=1);

namespace Rerm\Forms;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Roster\RosterPage;

/**
 * RCF entries (Phase 14, spec-v2 §15) — one requested change at a time, up a
 * chain of approvers, onto a consolidated Roster Change Form.
 *
 * An ENTRY is one line of a future form: the ten cells `rcf_row` holds, the
 * sub-committee the line is about, who asked and when, and one of five
 * states. It is submitted by a Vice Chairman (or anyone at Senior Officer
 * and above), waits on the approver the flow names for them
 * (`ApprovalFlow`), moves up to that approver's approver when approved, and
 * is APPROVED when the chain is exhausted — at which point the account whose
 * approval ended it may GENERATE: take every entry that ended with them and
 * cut it into as many forms as twenty-five lines each need, kept through
 * `RcfStore::store()` with `source = 'consolidated'` and tracked exactly as
 * every other kept form is.
 *
 *   pending ──approve──► pending (next) ──approve──► approved ──generate──► generated
 *      ├──return (with a note)──► returned
 *      └──withdraw (the requester)──► withdrawn
 *
 * WHO SEES AN ENTRY: its requester; the account it is waiting on; every
 * account that has acted on it; its final approver; and every holder of
 * `view_all_forms`. mayView() is the whole rule, and the lists below are the
 * same rule as SQL (visibleClause()), so a screen and the member card cannot
 * disagree.
 *
 * WHAT IT NEVER DOES: write the roster — an entry approved, generated or
 * not is a request, and the next import is the answer; delete an entry, a
 * step or a form — a returned entry is the record of what was asked and
 * what was said; or route an entry anywhere the flow did not say.
 *
 * AFTER THE FORM (Phase 14.2, spec-v2 §17). A generated entry is not done
 * when it is on a form; it is done when Rodeo Houston's next roster shows
 * the change. So shape() asks `RcfTracking::landed()` about the line each
 * generated entry became — the same derivation the form's own page and
 * Track RCFs use, read out of `import_change` and never typed — and every
 * list says "in the roster" or "not yet in the roster" beside "on form #".
 *
 * And a consolidated form can be VOIDED by the account that generated it,
 * or an Admin: the form and every entry it was cut from are stamped
 * `voided_at` in one transaction, with a note, and every read here and in
 * RcfTracking filters the stamp, so a test run of the flow disappears from
 * view for everybody in the chain. Nothing is deleted: the rows stay, each
 * entry gains a `voided` step, and one audit row names the form, the
 * entries and the note. That row is the only place a voided form is still
 * visible.
 *
 * Every state change is one `rcf_entry_step` row and one audit row, in the
 * same transaction as the change.
 */
final class RcfEntries
{
    /** A return note: bounded, free text. */
    public const NOTE_MAX = 500;

    /** The two ways a generation cuts entries into forms (spec-v2 §15.5). */
    public const GROUP_BY = ['division' => 'One form per division', 'team' => 'One form per team'];

    /** The states, as the column spells them and the screen labels them. */
    public const STATES = [
        'pending'   => 'Waiting for approval',
        'approved'  => 'Approved — awaiting a form',
        'generated' => 'On a form',
        'returned'  => 'Returned',
        'withdrawn' => 'Withdrawn',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ApprovalFlow $flow,
        private readonly DateTimeZone $zone = new DateTimeZone('UTC'),
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db(), ApprovalFlow::fromApp($app), $app->displayTimezone());
    }

    // -----------------------------------------------------------------------
    // Who may see what
    // -----------------------------------------------------------------------

    /**
     * May this user see this entry? The requester, the account it waits on,
     * anyone who has acted on it, the final approver, and a holder of
     * view_all_forms. The same rule visibleClause() spells as SQL.
     *
     * @param array<string, mixed> $entry a row carrying the four ids and `actors`
     */
    public static function mayView(User $user, array $entry): bool
    {
        if (Access::mayUse($user, Capability::ViewAllForms)) {
            return true;
        }

        foreach (['requested_by', 'current_approver_id', 'final_approver_id'] as $column) {
            if (isset($entry[$column]) && (int) $entry[$column] === $user->id) {
                return true;
            }
        }

        return in_array($user->id, array_map('intval', (array) ($entry['actors'] ?? [])), true);
    }

    /**
     * mayView() as a WHERE fragment over alias `e`, with its bindings.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function visibleClause(User $user, string $prefix): array
    {
        $all = Access::mayUse($user, Capability::ViewAllForms) ? 1 : 0;

        return [
            "(:{$prefix}_all = 1 OR e.requested_by = :{$prefix}_me1 OR e.current_approver_id = :{$prefix}_me2"
            . " OR e.final_approver_id = :{$prefix}_me3"
            . " OR EXISTS (SELECT 1 FROM rcf_entry_step vs WHERE vs.entry_id = e.id AND vs.actor_user_id = :{$prefix}_me4))",
            [
                ":{$prefix}_all" => $all,
                ":{$prefix}_me1" => $user->id,
                ":{$prefix}_me2" => $user->id,
                ":{$prefix}_me3" => $user->id,
                ":{$prefix}_me4" => $user->id,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // The counts the sub-navigation carries
    // -----------------------------------------------------------------------

    /**
     * How many entries are the caller's and still in flight, how many wait
     * on them, and how many are theirs to generate — one query.
     *
     * @return array{mine: int, waiting: int, ready: int}
     */
    public function counts(User $user): array
    {
        $read = $this->pdo->prepare(
            "SELECT SUM(CASE WHEN requested_by = :me1 AND status IN ('pending', 'approved') THEN 1 ELSE 0 END) AS mine,"
            . " SUM(CASE WHEN current_approver_id = :me2 AND status = 'pending' THEN 1 ELSE 0 END) AS waiting,"
            . " SUM(CASE WHEN status = 'approved' AND (:admin = 1 OR final_approver_id = :me3) THEN 1 ELSE 0 END) AS ready"
            . ' FROM rcf_entry WHERE voided_at IS NULL'
        );
        $read->execute([':me1' => $user->id, ':me2' => $user->id, ':me3' => $user->id, ':admin' => self::generatesAll($user) ? 1 : 0]);
        $row = $read->fetch();

        return [
            'mine'    => (int) ($row['mine'] ?? 0),
            'waiting' => (int) ($row['waiting'] ?? 0),
            'ready'   => (int) ($row['ready'] ?? 0),
        ];
    }

    /** An Admin generates every approved entry; everyone else, the ones that ended with them. */
    private static function generatesAll(User $user): bool
    {
        return $user->level === Level::Admin;
    }

    /**
     * Where this caller's entries go, with the approver's name — the sentence
     * at the top of Create RCF Entries. `ApprovalFlow::routeFor()` with the
     * id turned into a person.
     *
     * @return array{state: string, approver: ?array{id: int, name: string}}
     */
    public function routeFor(User $requester): array
    {
        $route = $this->flow->routeFor($requester);

        return [
            'state'    => $route['state'],
            'approver' => $route['approver'] === null ? null : $this->account($route['approver']),
        ];
    }

    // -----------------------------------------------------------------------
    // Submitting
    // -----------------------------------------------------------------------

    /**
     * Every filled row of a submitted grid becomes one entry, routed where
     * the flow says the requester's entries go.
     *
     * `$form` is exactly what `RcfPage::formFromInput()` returned — the same
     * resolution a line of a form gets (spec-v2 §2.6) — so nothing typed
     * reaches the record unresolved. The sub-committee it names is written
     * on every entry, because the heading is what says which team a removal
     * or a title change is about.
     *
     * Refused, writing nothing, when the requester's approver is NOT SET
     * (spec-v2 §15.3): the sentence names the screen that fixes it. An
     * account at the top of the chain has its entries approved on
     * submission, with themselves as final approver, and a step saying so.
     *
     * @param array<string, mixed> $form
     * @return array{created: array<int, int>, state: string, approver: ?array{id: int, name: string}, refused: ?string}
     */
    public function submit(User $requester, int $showYearId, array $form): array
    {
        $filled = [];
        foreach (array_values((array) ($form['entries'] ?? [])) as $entry) {
            if (!RosterChangeForm::entryIsBlank($entry)) {
                $filled[] = $entry;
            }
        }

        if ((string) ($form['subcommittee'] ?? '') === '') {
            return ['created' => [], 'state' => '', 'approver' => null,
                'refused' => 'Choose the sub-committee these requests are about before submitting them.'];
        }
        if ($filled === []) {
            return ['created' => [], 'state' => '', 'approver' => null,
                'refused' => 'Nothing is filled in yet, so there is no change to ask for. Add at least one row.'];
        }

        $route = $this->flow->routeFor($requester);
        if ($route['state'] === 'unset') {
            return ['created' => [], 'state' => 'unset', 'approver' => null,
                'refused' => 'Nobody is set as your approver yet, so your requests cannot be submitted. '
                    . 'An Admin draws who approves whom on Create Approval Flow.'];
        }

        $approver = $route['approver'] === null ? null : $this->account($route['approver']);
        if ($route['state'] === 'approver' && $approver === null) {
            return ['created' => [], 'state' => 'unset', 'approver' => null,
                'refused' => 'The account set as your approver no longer exists or is inactive, so your requests '
                    . 'cannot be submitted. An Admin redraws it on Create Approval Flow.'];
        }

        $members = $this->memberIds(array_map(
            static fn (array $e): string => (string) ($e['member_number'] ?? ''),
            $filled
        ));

        $created = [];
        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO rcf_entry (show_year_id, requested_by, requested_at, division_id, team_id, subcommittee,'
                . ' member_id, member_number, member_name, type, rookie, new_title, previous_title, wait_list,'
                . ' remove_reason, new_subcommittee, sponsor, status, current_approver_id, final_approver_id, approved_at)'
                . ' VALUES (:year, :by, UTC_TIMESTAMP(), :division, :team, :sub,'
                . ' :mid, :num, :name, :type, :rookie, :newt, :prevt, :wait, :reason, :newsub, :sponsor,'
                . ' :status, :current, :final, :approved)'
            );
            $step = $this->pdo->prepare(
                'INSERT INTO rcf_entry_step (entry_id, step, actor_user_id, action, note, acted_at)'
                . ' VALUES (:entry, :step, :actor, :action, :note, UTC_TIMESTAMP())'
            );

            $top   = $route['state'] === 'top';
            $audit = [];
            foreach ($filled as $entry) {
                $number = (string) ($entry['member_number'] ?? '');
                $insert->execute([
                    ':year'     => $showYearId,
                    ':by'       => $requester->id,
                    ':division' => $form['division_id'] ?? null,
                    ':team'     => $form['team_id'] ?? null,
                    ':sub'      => mb_substr((string) $form['subcommittee'], 0, 255),
                    ':mid'      => $members[$number] ?? null,
                    ':num'      => mb_substr($number, 0, 32),
                    ':name'     => mb_substr((string) ($entry['member_name'] ?? ''), 0, 160),
                    ':type'     => mb_substr((string) ($entry['type'] ?? ''), 0, 8),
                    ':rookie'   => (string) ($entry['rookie'] ?? '') === RosterChangeForm::TICKED ? 1 : 0,
                    ':newt'     => mb_substr((string) ($entry['new_title'] ?? ''), 0, 160),
                    ':prevt'    => mb_substr((string) ($entry['previous_title'] ?? ''), 0, 160),
                    ':wait'     => (string) ($entry['wait_list'] ?? '') === RosterChangeForm::TICKED ? 1 : 0,
                    ':reason'   => mb_substr((string) ($entry['remove_reason'] ?? ''), 0, 8),
                    ':newsub'   => mb_substr((string) ($entry['new_subcommittee'] ?? ''), 0, 160),
                    ':sponsor'  => mb_substr((string) ($entry['sponsor'] ?? ''), 0, 160),
                    ':status'   => $top ? 'approved' : 'pending',
                    ':current'  => $top ? null : $route['approver'],
                    ':final'    => $top ? $requester->id : null,
                    ':approved' => $top ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s') : null,
                ]);
                $id        = (int) $this->pdo->lastInsertId();
                $created[] = $id;

                $step->execute([':entry' => $id, ':step' => 1, ':actor' => $requester->id, ':action' => 'submitted', ':note' => '']);
                if ($top) {
                    $step->execute([':entry' => $id, ':step' => 2, ':actor' => $requester->id, ':action' => 'approved',
                        ':note' => 'Top of the chain: approved on submission.']);
                }

                $audit[] = [
                    'action'    => Action::SubmitRcfEntry,
                    'entity'    => 'rcf_entry',
                    'entity_id' => (string) $id,
                    'before'    => null,
                    'after'     => [
                        'sub_committee' => (string) $form['subcommittee'],
                        'member_number' => $number,
                        'member_name'   => (string) ($entry['member_name'] ?? ''),
                        'type'          => (string) ($entry['type'] ?? ''),
                        'status'        => $top ? 'approved' : 'pending',
                        'waiting_on'    => $top ? null : $route['approver'],
                    ],
                ];
            }

            (new AuditLog($this->pdo))->recordMany($requester, $audit);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return ['created' => $created, 'state' => $route['state'], 'approver' => $approver, 'refused' => null];
    }

    // -----------------------------------------------------------------------
    // The lists
    // -----------------------------------------------------------------------

    /**
     * The caller's own entries, newest first, in flight and finished alike:
     * a Vice Chairman reads what is waiting where, what came back and why,
     * and which form the rest landed on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mine(User $user): array
    {
        $read = $this->pdo->prepare($this->select() . ' WHERE e.requested_by = :me AND e.voided_at IS NULL ORDER BY e.id DESC LIMIT 500');
        $read->execute([':me' => $user->id]);

        return $this->shape($read->fetchAll(), $user);
    }

    /**
     * Entries waiting on the caller, oldest first — the order to work them
     * in — each saying where it goes next if approved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function waitingFor(User $user): array
    {
        $read = $this->pdo->prepare(
            $this->select() . " WHERE e.current_approver_id = :me AND e.status = 'pending' AND e.voided_at IS NULL ORDER BY e.id ASC"
        );
        $read->execute([':me' => $user->id]);

        $rows = $this->shape($read->fetchAll(), $user);
        foreach ($rows as &$row) {
            $next = $this->flow->nextAfter($user->id, [$row['requested_by'], ...$row['actors']]);
            $row['next_approver'] = $next === null ? null : $this->account($next);
        }
        unset($row);

        return $rows;
    }

    /**
     * Entries the caller has acted on as an approver, newest decision first
     * — "what did I approve, and where did it get to" — fifty at most.
     *
     * @return array<int, array<string, mixed>>
     */
    public function decidedBy(User $user): array
    {
        $read = $this->pdo->prepare(
            $this->select()
            . " WHERE e.requested_by <> :me1 AND e.voided_at IS NULL AND EXISTS (SELECT 1 FROM rcf_entry_step ds WHERE ds.entry_id = e.id"
            . " AND ds.actor_user_id = :me2 AND ds.action IN ('approved', 'returned'))"
            . ' ORDER BY e.id DESC LIMIT 50'
        );
        $read->execute([':me1' => $user->id, ':me2' => $user->id]);

        return $this->shape($read->fetchAll(), $user);
    }

    /**
     * Approved entries not yet on a form that ended with the caller's
     * approval — every approved entry, for an Admin — oldest first, in the
     * order a form would print them: division, team, member name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readyFor(User $user): array
    {
        $read = $this->pdo->prepare(
            $this->select()
            . " WHERE e.status = 'approved' AND e.voided_at IS NULL AND (:admin = 1 OR e.final_approver_id = :me)"
            . ' ORDER BY COALESCE(d.name, e.subcommittee), e.subcommittee, e.member_name, e.id'
        );
        $read->execute([':admin' => self::generatesAll($user) ? 1 : 0, ':me' => $user->id]);

        return $this->shape($read->fetchAll(), $user);
    }

    /**
     * Every entry not yet on a form that the caller may see — pending or
     * approved — newest first, for Track RCFs' "where did it stop" (spec-v2
     * §15.4). Capped at 300 for the same reason every list on that screen is.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingFor(User $user): array
    {
        [$visible, $bind] = self::visibleClause($user, 'p');
        $read = $this->pdo->prepare(
            $this->select() . " WHERE e.status IN ('pending', 'approved') AND e.voided_at IS NULL AND {$visible} ORDER BY e.id DESC LIMIT 300"
        );
        $read->execute($bind);

        return $this->shape($read->fetchAll(), $user);
    }

    /**
     * The entries a kept form was made from, in line order — for the form's
     * own page. Empty for a form that was not consolidated.
     *
     * @return array<int, array<string, mixed>>
     */
    public function onForm(User $user, int $rcfId): array
    {
        $read = $this->pdo->prepare($this->select() . ' WHERE e.rcf_id = :rcf AND e.voided_at IS NULL ORDER BY e.rcf_row_id');
        $read->execute([':rcf' => $rcfId]);

        return $this->shape($read->fetchAll(), $user);
    }

    /**
     * The consolidated forms the caller generated, newest first, each with
     * how many entries it was cut from — the list under "Forms you have
     * generated". Reads `rcf` for the header and counts the entries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generatedForms(User $user): array
    {
        $read = $this->pdo->prepare(
            'SELECT f.id, f.generated_at, f.subcommittee, f.row_count, f.regenerated_count,'
            . ' (SELECT COUNT(*) FROM rcf_entry e WHERE e.rcf_id = f.id) AS entries'
            . " FROM rcf f WHERE f.source = 'consolidated' AND f.voided_at IS NULL AND f.generated_by = :me"
            . ' ORDER BY f.id DESC LIMIT 100'
        );
        $read->execute([':me' => $user->id]);

        $forms = [];
        foreach ($read->fetchAll() as $row) {
            $forms[] = [
                'id'                => (int) $row['id'],
                'generated_at'      => (string) $row['generated_at'],
                'subcommittee'      => (string) $row['subcommittee'],
                'rows'              => (int) $row['row_count'],
                'entries'           => (int) $row['entries'],
                'regenerated_count' => (int) $row['regenerated_count'],
            ];
        }

        return $forms;
    }

    // -----------------------------------------------------------------------
    // Approving, returning, withdrawing
    // -----------------------------------------------------------------------

    /**
     * Approves the entries named that are pending WITH THIS CALLER — any
     * other id writes nothing — and moves each to the next approver, or
     * marks it approved with the caller as final approver when the chain
     * ends here. Returns how many moved on and how many were approved
     * outright.
     *
     * @param array<int, mixed> $ids
     * @return array{advanced: int, approved: int}
     */
    public function approve(User $approver, array $ids): array
    {
        $rows = $this->forWrite($ids, "e.current_approver_id = :me AND e.status = 'pending'", [':me' => $approver->id]);
        if ($rows === []) {
            return ['advanced' => 0, 'approved' => 0];
        }

        $advanced = 0;
        $approved = 0;
        $this->pdo->beginTransaction();

        try {
            $onward = $this->pdo->prepare(
                'UPDATE rcf_entry SET current_approver_id = :next WHERE id = :id AND current_approver_id = :me'
            );
            $final = $this->pdo->prepare(
                "UPDATE rcf_entry SET status = 'approved', current_approver_id = NULL, final_approver_id = :me1,"
                . ' approved_at = UTC_TIMESTAMP() WHERE id = :id AND current_approver_id = :me2'
            );

            $audit = [];
            foreach ($rows as $row) {
                $id   = (int) $row['id'];
                $next = $this->flow->nextAfter($approver->id, [(int) $row['requested_by'], ...$row['actors']]);

                if ($next === null) {
                    $final->execute([':me1' => $approver->id, ':id' => $id, ':me2' => $approver->id]);
                    $approved++;
                } else {
                    $onward->execute([':next' => $next, ':id' => $id, ':me' => $approver->id]);
                    $advanced++;
                }

                $this->step($id, (int) $row['steps'] + 1, $approver->id, 'approved', '');

                $audit[] = [
                    'action'    => Action::ApproveRcfEntry,
                    'entity'    => 'rcf_entry',
                    'entity_id' => (string) $id,
                    'before'    => ['status' => 'pending', 'waiting_on' => $approver->id],
                    'after'     => $next === null
                        ? ['status' => 'approved', 'final_approver' => $approver->id]
                        : ['status' => 'pending', 'waiting_on' => $next],
                ];
            }

            (new AuditLog($this->pdo))->recordMany($approver, $audit);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return ['advanced' => $advanced, 'approved' => $approved];
    }

    /**
     * Returns the entries named that are pending with this caller, with one
     * note for all of them. A return without a note is refused: "no" without
     * a reason is a request the requester has to ring about. Returns how
     * many were returned, or null for a missing note.
     *
     * @param array<int, mixed> $ids
     */
    public function sendBack(User $approver, array $ids, string $note): ?int
    {
        $note = self::note($note);
        if ($note === '') {
            return null;
        }

        $rows = $this->forWrite($ids, "e.current_approver_id = :me AND e.status = 'pending'", [':me' => $approver->id]);
        if ($rows === []) {
            return 0;
        }

        $this->pdo->beginTransaction();

        try {
            $update = $this->pdo->prepare(
                "UPDATE rcf_entry SET status = 'returned', current_approver_id = NULL, returned_by = :by1,"
                . ' returned_at = UTC_TIMESTAMP(), return_note = :note WHERE id = :id AND current_approver_id = :by2'
            );

            $audit = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $update->execute([':by1' => $approver->id, ':note' => $note, ':id' => $id, ':by2' => $approver->id]);
                $this->step($id, (int) $row['steps'] + 1, $approver->id, 'returned', $note);

                $audit[] = [
                    'action'    => Action::ReturnRcfEntry,
                    'entity'    => 'rcf_entry',
                    'entity_id' => (string) $id,
                    'before'    => ['status' => 'pending', 'waiting_on' => $approver->id],
                    'after'     => ['status' => 'returned', 'note' => $note],
                ];
            }

            (new AuditLog($this->pdo))->recordMany($approver, $audit);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return count($rows);
    }

    /**
     * The requester takes back one of their own entries while it is still
     * pending. Anything else — somebody else's, or one already decided —
     * writes nothing. Returns whether it was withdrawn.
     */
    public function withdraw(User $requester, int $id): bool
    {
        $rows = $this->forWrite([$id], "e.requested_by = :me AND e.status = 'pending'", [':me' => $requester->id]);
        if ($rows === []) {
            return false;
        }
        $row = $rows[0];

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                "UPDATE rcf_entry SET status = 'withdrawn', current_approver_id = NULL, withdrawn_at = UTC_TIMESTAMP()"
                . ' WHERE id = :id AND requested_by = :me'
            )->execute([':id' => $id, ':me' => $requester->id]);
            $this->step($id, (int) $row['steps'] + 1, $requester->id, 'withdrawn', '');

            (new AuditLog($this->pdo))->record(
                $requester,
                Action::WithdrawRcfEntry,
                'rcf_entry',
                (string) $id,
                ['status' => 'pending', 'waiting_on' => $row['current_approver_id'] === null ? null : (int) $row['current_approver_id']],
                ['status' => 'withdrawn']
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Generating
    // -----------------------------------------------------------------------

    /**
     * Cuts entries into the forms they will print on (spec-v2 §15.5): one
     * group per division (the Division Chairman's bundle) or per team, and
     * a group of more than twenty-five lines into as many forms as it needs.
     * Pure, so the test hands it fifty-one entries.
     *
     * An entry under the placeholder division has no division name and
     * groups under its team, unprefixed: `(No Division)` is never printed.
     *
     * @param array<int, array<string, mixed>> $entries shape() rows
     * @return array<int, array{label: string, division_id: ?int, team_id: ?int, entries: array<int, array<string, mixed>>}>
     */
    public static function cut(array $entries, string $by): array
    {
        $by     = isset(self::GROUP_BY[$by]) ? $by : 'division';
        $groups = [];

        foreach ($entries as $entry) {
            $division = trim((string) ($entry['division_name'] ?? ''));
            $team     = trim((string) ($entry['team_name'] ?? ''));

            if ($by === 'division' && $division !== '') {
                $key   = 'd:' . (int) $entry['division_id'];
                $label = $division;
                $ids   = ['division_id' => (int) $entry['division_id'], 'team_id' => null];
            } elseif ($entry['team_id'] !== null && $team !== '') {
                $key   = 't:' . (int) $entry['team_id'];
                $label = $division !== '' ? $division . ' - ' . $team : $team;
                $ids   = ['division_id' => null, 'team_id' => (int) $entry['team_id']];
            } else {
                // Neither resolved — the label as the entry carries it.
                $key   = 'l:' . (string) $entry['subcommittee'];
                $label = (string) $entry['subcommittee'];
                $ids   = ['division_id' => $entry['division_id'] === null ? null : (int) $entry['division_id'],
                    'team_id' => $entry['team_id'] === null ? null : (int) $entry['team_id']];
            }

            $groups[$key] ??= ['label' => $label, 'entries' => []] + $ids;
            $groups[$key]['entries'][] = $entry;
        }

        $forms = [];
        foreach ($groups as $group) {
            // Team, then name, then id: the order the form reads in.
            usort($group['entries'], static fn (array $a, array $b): int =>
                [(string) $a['team_name'], (string) $a['member_name'], (int) $a['id']]
                <=> [(string) $b['team_name'], (string) $b['member_name'], (int) $b['id']]);

            foreach (array_chunk($group['entries'], RosterChangeForm::ROWS) as $chunk) {
                $forms[] = ['entries' => $chunk] + array_diff_key($group, ['entries' => true]);
            }
        }

        return $forms;
    }

    /**
     * Generates the forms: the ticked entries among those ready for the
     * caller, cut by `$by`, each kept through RcfStore with source
     * 'consolidated', the caller as submitter and today in Houston as the
     * date; every entry marked generated with its form and its line; one
     * audit row per form naming the entries. All in one transaction — a
     * form half kept is no form. Nothing is downloaded here: the form's own
     * page is where every kept form is downloaded from.
     *
     * @param array<int, mixed> $ids
     * @return array{forms: array<int, int>, entries: int}
     */
    public function generate(User $generator, int $showYearId, array $ids, string $by, RcfStore $store): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $wanted[(int) $id] = true;
            }
        }

        $ready = array_values(array_filter(
            $this->readyFor($generator),
            static fn (array $e): bool => isset($wanted[(int) $e['id']])
        ));
        if ($ready === []) {
            return ['forms' => [], 'entries' => 0];
        }

        $me = $this->submitterOf($generator);

        $year = $this->pdo->prepare('SELECT label FROM show_year WHERE id = :id');
        $year->execute([':id' => $showYearId]);
        $yearLabel = (string) ($year->fetchColumn() ?: '');

        $today = new DateTimeImmutable('today', $this->zone);

        $forms = [];
        $count = 0;
        $this->pdo->beginTransaction();

        try {
            $mark = $this->pdo->prepare(
                "UPDATE rcf_entry SET status = 'generated', rcf_id = :rcf, rcf_row_id = :row, generated_at = UTC_TIMESTAMP()"
                . " WHERE id = :id AND status = 'approved'"
            );
            $lines = $this->pdo->prepare('SELECT id, position FROM rcf_row WHERE rcf_id = :rcf');

            foreach (self::cut($ready, $by) as $form) {
                $entries = [];
                for ($i = 0; $i < RosterChangeForm::ROWS; $i++) {
                    $entries[$i] = array_replace(RosterChangeForm::emptyEntry(), [
                        'rookie'    => RosterChangeForm::UNTICKED,
                        'wait_list' => RosterChangeForm::UNTICKED,
                    ]);
                }
                $byPosition = [];
                foreach (array_values($form['entries']) as $i => $entry) {
                    $entries[$i] = [
                        'type'             => (string) $entry['type'],
                        'rookie'           => $entry['rookie'] ? RosterChangeForm::TICKED : RosterChangeForm::UNTICKED,
                        'member_name'      => (string) $entry['member_name'],
                        'member_number'    => (string) $entry['member_number'],
                        'new_title'        => (string) $entry['new_title'],
                        'previous_title'   => (string) $entry['previous_title'],
                        'wait_list'        => $entry['wait_list'] ? RosterChangeForm::TICKED : RosterChangeForm::UNTICKED,
                        'remove_reason'    => (string) $entry['remove_reason'],
                        'new_subcommittee' => (string) $entry['new_subcommittee'],
                        'sponsor'          => (string) $entry['sponsor'],
                    ];
                    $byPosition[$i + 1] = (int) $entry['id'];
                }

                $rcfId = $store->store($generator, $showYearId, [
                    'year'             => $yearLabel,
                    'submitter'        => $me['form_name'],
                    'submitter_number' => $me['member_number'],
                    'date'             => $today->format('n/j/Y'),
                    'form_date'        => $today->format('Y-m-d'),
                    'subcommittee'     => (string) $form['label'],
                    'division_id'      => $form['division_id'],
                    'team_id'          => $form['team_id'],
                    'entries'          => $entries,
                ], ['source' => 'consolidated']);

                $lines->execute([':rcf' => $rcfId]);
                $rowIds = [];
                foreach ($lines->fetchAll() as $line) {
                    $rowIds[(int) $line['position']] = (int) $line['id'];
                }

                $taken = [];
                foreach ($byPosition as $position => $entryId) {
                    $mark->execute([':rcf' => $rcfId, ':row' => $rowIds[$position] ?? null, ':id' => $entryId]);
                    $this->step($entryId, $this->stepCount($entryId) + 1, $generator->id, 'generated', 'Form #' . $rcfId);
                    $taken[] = $entryId;
                }

                (new AuditLog($this->pdo))->record(
                    $generator,
                    Action::GenerateConsolidatedRcf,
                    'rcf',
                    (string) $rcfId,
                    null,
                    [
                        'form'          => 'roster_change_form',
                        'show_year'     => $yearLabel,
                        'sub_committee' => (string) $form['label'],
                        'grouped_by'    => isset(self::GROUP_BY[$by]) ? $by : 'division',
                        'rows'          => count($taken),
                        'entries'       => $taken,
                        'rcf_id'        => $rcfId,
                    ]
                );

                $forms[] = $rcfId;
                $count  += count($taken);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return ['forms' => $forms, 'entries' => $count];
    }

    // -----------------------------------------------------------------------
    // Voiding a consolidated form (Phase 14.2, spec-v2 §17)
    // -----------------------------------------------------------------------

    /**
     * May this user void this form? Only a CONSOLIDATED form, not yet
     * voided, and only by the account that generated it — the one whose
     * signature it would have gone out under — or an Admin; and only while
     * holding the capability that generated it. A form made on Create Forms
     * or uploaded from a file is never voidable here: it was sent, or it
     * arrived, and the record of that stands.
     *
     * The whole rule, in one place, so the screen and the write agree.
     *
     * @param array<string, mixed> $form a row carrying source, generated_by and voided_at
     */
    public static function mayVoid(User $user, array $form): bool
    {
        if ((string) ($form['source'] ?? '') !== 'consolidated' || ($form['voided_at'] ?? null) !== null) {
            return false;
        }
        if (!Access::mayUse($user, Capability::GenerateConsolidatedRcf)) {
            return false;
        }

        return $user->level === Level::Admin || (int) ($form['generated_by'] ?? 0) === $user->id;
    }

    /**
     * Voids a consolidated form: the form and every entry it was cut from
     * are stamped voided, with the note, in one transaction; each entry
     * gains a `voided` step; one audit row names the form, the entries and
     * the note. From then on nothing lists the form or the entries — not
     * Track RCFs, not the requester's list, not any approver's, not the
     * member card — for anybody.
     *
     * A note is required, exactly as a return's is: a form taken out of the
     * record without a reason is a hole in it. Returns how many entries
     * were voided with the form; null when the caller may not void it, or
     * there is no such form, which is the same answer on purpose; and -1
     * when the note is missing, so the screen can say which.
     *
     * Nothing is deleted. The entries keep status 'generated' and their
     * line; the stamp is what hides them. If the same change is still
     * wanted, it is asked for again through the grid.
     */
    public function void(User $user, int $rcfId, string $note): ?int
    {
        if ($rcfId <= 0) {
            return null;
        }

        $read = $this->pdo->prepare('SELECT id, source, generated_by, voided_at, subcommittee, row_count FROM rcf WHERE id = :id');
        $read->execute([':id' => $rcfId]);
        $form = $read->fetch();
        if (!is_array($form) || !self::mayVoid($user, $form)) {
            return null;
        }

        $note = self::note($note);
        if ($note === '') {
            return -1;
        }

        $entries = $this->pdo->prepare(
            'SELECT e.id, (SELECT COUNT(*) FROM rcf_entry_step c WHERE c.entry_id = e.id) AS steps'
            . ' FROM rcf_entry e WHERE e.rcf_id = :rcf AND e.voided_at IS NULL ORDER BY e.id'
        );
        $entries->execute([':rcf' => $rcfId]);
        $rows = $entries->fetchAll();

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                'UPDATE rcf SET voided_at = UTC_TIMESTAMP(), voided_by = :by, void_note = :note'
                . ' WHERE id = :id AND voided_at IS NULL'
            )->execute([':by' => $user->id, ':note' => $note, ':id' => $rcfId]);

            $stamp = $this->pdo->prepare(
                'UPDATE rcf_entry SET voided_at = UTC_TIMESTAMP() WHERE id = :id AND rcf_id = :rcf AND voided_at IS NULL'
            );
            $ids = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $stamp->execute([':id' => $id, ':rcf' => $rcfId]);
                $this->step($id, (int) $row['steps'] + 1, $user->id, 'voided', $note);
                $ids[] = $id;
            }

            (new AuditLog($this->pdo))->record(
                $user,
                Action::VoidConsolidatedRcf,
                'rcf',
                (string) $rcfId,
                [
                    'source'        => 'consolidated',
                    'sub_committee' => (string) $form['subcommittee'],
                    'rows'          => (int) $form['row_count'],
                    'generated_by'  => (int) $form['generated_by'],
                    'entries'       => $ids,
                ],
                [
                    'voided'  => true,
                    'note'    => $note,
                    'entries' => $ids,
                ]
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return count($ids);
    }

    // -----------------------------------------------------------------------
    // Shapes
    // -----------------------------------------------------------------------

    /**
     * Where an entry is, in one sentence the lists print. PLAIN text; the
     * view escapes.
     *
     * A generated entry says whether the roster shows it yet (Phase 14.2):
     * `landed` is what shape() read out of the imports for the line the
     * entry became, and "on a form" without it is a request still sitting
     * with Rodeo Houston.
     *
     * @param array<string, mixed> $entry a shape() row
     */
    public static function whereabouts(array $entry): string
    {
        return match ((string) $entry['status']) {
            'pending'   => 'Waiting on ' . ((string) $entry['current_approver_name'] !== '' ? $entry['current_approver_name'] : 'nobody'),
            'approved'  => 'Approved by ' . $entry['final_approver_name'] . ' — awaiting a form',
            'generated' => 'On form #' . (int) $entry['rcf_id']
                . (($entry['landed'] ?? null) !== null ? ' — in the roster' : ' — not yet in the roster'),
            'returned'  => 'Returned by ' . $entry['returned_by_name']
                . ((string) $entry['return_note'] !== '' ? ': ' . $entry['return_note'] : ''),
            'withdrawn' => 'Withdrawn',
            default     => (string) $entry['status'],
        };
    }

    /** The entry columns every list reads, with the names behind the ids. */
    private function select(): string
    {
        // The division is the entry's own, or its team's: a team option on the
        // grid names the team alone (RcfPage::subcommittees()), and the cut
        // by division has to know which division that team is in. The
        // placeholder is never a division here, so its teams group alone.
        return 'SELECT e.*, d.id AS resolved_division_id, d.name AS division_name, t.name AS team_name,'
            . ' rm.preferred_name AS r_preferred, rm.first_name AS r_first, rm.last_name AS r_last, rm.member_number AS r_number,'
            . ' cm.preferred_name AS c_preferred, cm.first_name AS c_first, cm.last_name AS c_last, cm.member_number AS c_number,'
            . ' fm.preferred_name AS f_preferred, fm.first_name AS f_first, fm.last_name AS f_last, fm.member_number AS f_number,'
            . ' bm.preferred_name AS b_preferred, bm.first_name AS b_first, bm.last_name AS b_last, bm.member_number AS b_number'
            . ' FROM rcf_entry e'
            . ' LEFT JOIN team t ON t.id = e.team_id'
            . ' LEFT JOIN division d ON d.id = COALESCE(e.division_id, t.division_id) AND d.is_placeholder = 0'
            . ' INNER JOIN app_user ru ON ru.id = e.requested_by INNER JOIN member rm ON rm.id = ru.member_id'
            . ' LEFT JOIN app_user cu ON cu.id = e.current_approver_id LEFT JOIN member cm ON cm.id = cu.member_id'
            . ' LEFT JOIN app_user fu ON fu.id = e.final_approver_id LEFT JOIN member fm ON fm.id = fu.member_id'
            . ' LEFT JOIN app_user bu ON bu.id = e.returned_by LEFT JOIN member bm ON bm.id = bu.member_id';
    }

    /**
     * Rows as the screens read them: the cells, the codes spelled out, the
     * change in one clause (RcfTracking's words), the names behind every
     * id, the steps in order, and whether THIS caller may open it.
     *
     * @param array<int, array<string, mixed>> $rows raw select() rows
     * @return array<int, array<string, mixed>>
     */
    private function shape(array $rows, User $user): array
    {
        if ($rows === []) {
            return [];
        }

        $steps = $this->stepsFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        // What the roster has since shown, for the entries that are on a
        // form: the same question, asked of the same table, as the form's
        // own page asks of its lines (RcfTracking::landed()).
        $landed = $this->landedFor($rows);

        $name  = static fn (array $r, string $p): string => $r[$p . '_number'] === null ? '' : RosterPage::displayName(
            (string) $r[$p . '_preferred'],
            (string) $r[$p . '_first'],
            (string) $r[$p . '_last'],
            (string) $r[$p . '_number']
        );

        $out = [];
        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $type = (string) $row['type'];
            $own  = $steps[$id] ?? [];

            $entry = [
                'id'                    => $id,
                'show_year_id'          => (int) $row['show_year_id'],
                'requested_by'          => (int) $row['requested_by'],
                'requested_at'          => (string) $row['requested_at'],
                'requester_name'        => $name($row, 'r'),
                'division_id'           => $row['resolved_division_id'] === null ? null : (int) $row['resolved_division_id'],
                'team_id'               => $row['team_id'] === null ? null : (int) $row['team_id'],
                'division_name'         => (string) ($row['division_name'] ?? ''),
                'team_name'             => (string) ($row['team_name'] ?? ''),
                'subcommittee'          => (string) $row['subcommittee'],
                'member_id'             => $row['member_id'] === null ? null : (int) $row['member_id'],
                'member_number'         => (string) $row['member_number'],
                'member_name'           => (string) $row['member_name'],
                'type'                  => $type,
                'type_label'            => RosterChangeForm::TYPES[$type] ?? $type,
                'rookie'                => (int) $row['rookie'] === 1,
                'new_title'             => (string) $row['new_title'],
                'previous_title'        => (string) $row['previous_title'],
                'wait_list'             => (int) $row['wait_list'] === 1,
                'remove_reason'         => (string) $row['remove_reason'],
                'new_subcommittee'      => (string) $row['new_subcommittee'],
                'sponsor'               => (string) $row['sponsor'],
                'change'                => RcfTracking::changeSummary($row),
                'status'                => (string) $row['status'],
                'status_label'          => self::STATES[(string) $row['status']] ?? (string) $row['status'],
                'current_approver_id'   => $row['current_approver_id'] === null ? null : (int) $row['current_approver_id'],
                'current_approver_name' => $name($row, 'c'),
                'final_approver_id'     => $row['final_approver_id'] === null ? null : (int) $row['final_approver_id'],
                'final_approver_name'   => $name($row, 'f'),
                'approved_at'           => $row['approved_at'] === null ? null : (string) $row['approved_at'],
                'returned_by_name'      => $name($row, 'b'),
                'returned_at'           => $row['returned_at'] === null ? null : (string) $row['returned_at'],
                'return_note'           => (string) $row['return_note'],
                'withdrawn_at'          => $row['withdrawn_at'] === null ? null : (string) $row['withdrawn_at'],
                'rcf_id'                => $row['rcf_id'] === null ? null : (int) $row['rcf_id'],
                'rcf_row_id'            => $row['rcf_row_id'] === null ? null : (int) $row['rcf_row_id'],
                'generated_at'          => $row['generated_at'] === null ? null : (string) $row['generated_at'],
                'voided_at'             => ($row['voided_at'] ?? null) === null ? null : (string) $row['voided_at'],
                // The import that fulfilled the line this entry became, or
                // null: not on a form yet, or on one Rodeo Houston has not
                // processed. Never typed (spec-v2 §12.5).
                'landed'                => $row['rcf_row_id'] === null ? null : ($landed[(int) $row['rcf_row_id']] ?? null),
                'steps'                 => $own,
                'actors'                => array_values(array_unique(array_map(static fn (array $s): int => (int) $s['actor_id'], $own))),
            ];

            [$was, $becomes]   = RcfTracking::wasBecomes($entry);
            $entry['was']      = $was;
            $entry['becomes']  = $becomes;
            $entry['where']    = self::whereabouts($entry);
            $entry['trail']    = self::trail($entry);
            $entry['viewable'] = self::mayView($user, $entry);

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Every hand the entry passed through, as one short clause each, ending
     * with what the roster has said — the line a list prints under the chip
     * so the whole chain reads in one place (Phase 14.2). PLAIN text; the
     * view escapes. The steps carry their moments; the view adds the
     * import's when it wants it.
     *
     * @param array<string, mixed> $entry a shape() row
     * @return array<int, string>
     */
    public static function trail(array $entry): array
    {
        $trail = [];
        foreach ((array) ($entry['steps'] ?? []) as $step) {
            $who     = (string) ($step['actor_name'] ?? '');
            $trail[] = match ((string) ($step['action'] ?? '')) {
                'submitted' => 'Asked by ' . $who,
                'approved'  => 'Approved by ' . $who,
                'returned'  => 'Returned by ' . $who,
                'withdrawn' => 'Withdrawn by ' . $who,
                'generated' => 'On form #' . (int) ($entry['rcf_id'] ?? 0) . ' by ' . $who,
                'voided'    => 'Voided by ' . $who,
                default     => (string) ($step['action'] ?? ''),
            };
        }

        if ((string) ($entry['status'] ?? '') === 'generated') {
            $trail[] = ($entry['landed'] ?? null) !== null
                ? 'In the roster — HLSR processed it'
                : 'Not yet in the roster';
        }

        return $trail;
    }

    /**
     * RcfTracking::landed() for the lines these entries became: row id =>
     * the import that fulfilled it. One query, only when something is on a
     * form.
     *
     * @param array<int, array<string, mixed>> $rows raw select() rows
     * @return array<int, array<string, mixed>>
     */
    private function landedFor(array $rows): array
    {
        $rowIds = [];
        foreach ($rows as $row) {
            if ($row['rcf_row_id'] !== null) {
                $rowIds[] = (int) $row['rcf_row_id'];
            }
        }

        return $rowIds === [] ? [] : (new RcfTracking($this->pdo, $this->zone))->landed($rowIds);
    }

    /**
     * Every step of several entries, in order, with the actor's name — one
     * query for the whole list.
     *
     * @param array<int, int> $ids
     * @return array<int, array<int, array{step: int, action: string, actor_id: int, actor_name: string, note: string, at: string}>>
     */
    private function stepsFor(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $places = [];
        $bind   = [];
        foreach ($ids as $i => $id) {
            $places[]        = ":s{$i}";
            $bind[":s{$i}"] = $id;
        }

        $read = $this->pdo->prepare(
            'SELECT s.entry_id, s.step, s.action, s.actor_user_id, s.note, s.acted_at,'
            . ' m.preferred_name, m.first_name, m.last_name, m.member_number'
            . ' FROM rcf_entry_step s INNER JOIN app_user u ON u.id = s.actor_user_id INNER JOIN member m ON m.id = u.member_id'
            . ' WHERE s.entry_id IN (' . implode(', ', $places) . ') ORDER BY s.entry_id, s.step'
        );
        $read->execute($bind);

        $steps = [];
        foreach ($read->fetchAll() as $row) {
            $steps[(int) $row['entry_id']][] = [
                'step'       => (int) $row['step'],
                'action'     => (string) $row['action'],
                'actor_id'   => (int) $row['actor_user_id'],
                'actor_name' => RosterPage::displayName(
                    (string) $row['preferred_name'],
                    (string) $row['first_name'],
                    (string) $row['last_name'],
                    (string) $row['member_number']
                ),
                'note'       => (string) $row['note'],
                'at'         => (string) $row['acted_at'],
            ];
        }

        return $steps;
    }

    /**
     * The entries named, among those matching a predicate, each with its
     * step count and the accounts that have acted on it — the read before a
     * write. Ids that are not the caller's to act on are simply absent.
     *
     * @param array<int, mixed>    $ids
     * @param array<string, mixed> $bind
     * @return array<int, array<string, mixed>>
     */
    private function forWrite(array $ids, string $predicate, array $bind): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }
        if ($clean === []) {
            return [];
        }

        $places = [];
        foreach (array_values($clean) as $i => $id) {
            $places[]        = ":w{$i}";
            $bind[":w{$i}"] = $id;
        }

        $read = $this->pdo->prepare(
            'SELECT e.id, e.requested_by, e.current_approver_id,'
            . ' (SELECT COUNT(*) FROM rcf_entry_step c WHERE c.entry_id = e.id) AS steps'
            . " FROM rcf_entry e WHERE e.voided_at IS NULL AND {$predicate} AND e.id IN (" . implode(', ', $places) . ') ORDER BY e.id'
        );
        $read->execute($bind);
        $rows = $read->fetchAll();

        $steps = $this->stepsFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        foreach ($rows as &$row) {
            $row['actors'] = array_values(array_unique(array_map(
                static fn (array $s): int => (int) $s['actor_id'],
                $steps[(int) $row['id']] ?? []
            )));
        }
        unset($row);

        return $rows;
    }

    private function step(int $entryId, int $step, int $actorId, string $action, string $note): void
    {
        $this->pdo->prepare(
            'INSERT INTO rcf_entry_step (entry_id, step, actor_user_id, action, note, acted_at)'
            . ' VALUES (:entry, :step, :actor, :action, :note, UTC_TIMESTAMP())'
        )->execute([':entry' => $entryId, ':step' => $step, ':actor' => $actorId, ':action' => $action,
            ':note' => mb_substr($note, 0, self::NOTE_MAX)]);
    }

    private function stepCount(int $entryId): int
    {
        $read = $this->pdo->prepare('SELECT COUNT(*) FROM rcf_entry_step WHERE entry_id = :id');
        $read->execute([':id' => $entryId]);

        return (int) $read->fetchColumn();
    }

    /**
     * An account by id, as a name — for "waiting on Bob". Null when it does
     * not exist or is inactive.
     *
     * @return ?array{id: int, name: string}
     */
    private function account(int $userId): ?array
    {
        $read = $this->pdo->prepare(
            'SELECT u.id, m.preferred_name, m.first_name, m.last_name, m.member_number'
            . ' FROM app_user u INNER JOIN member m ON m.id = u.member_id WHERE u.id = :id AND u.is_active = 1'
        );
        $read->execute([':id' => $userId]);
        $row = $read->fetch();
        if (!is_array($row)) {
            return null;
        }

        return ['id' => (int) $row['id'], 'name' => RosterPage::displayName(
            (string) $row['preferred_name'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            (string) $row['member_number']
        )];
    }

    /**
     * The generator as the form's `G4` wants them — `Name, Title`, the name
     * as Rodeo Houston spells it — and their member number for the record.
     *
     * @return array{form_name: string, member_number: string}
     */
    private function submitterOf(User $user): array
    {
        $read = $this->pdo->prepare(
            'SELECT m.member_number, m.first_name, m.last_name, m.full_name, m.title'
            . ' FROM app_user u INNER JOIN member m ON m.id = u.member_id WHERE u.id = :id'
        );
        $read->execute([':id' => $user->id]);
        $row = $read->fetch();
        if (!is_array($row)) {
            return ['form_name' => $user->displayName, 'member_number' => $user->memberNumber];
        }

        return [
            'form_name'     => trim(RcfPage::formalName($row) . ', ' . (string) $row['title'], ', '),
            'member_number' => (string) $row['member_number'],
        ];
    }

    /**
     * member_number => member.id, unscoped: it is a link for the member
     * card, and the card re-checks scope (RcfStore's rule).
     *
     * @param array<int, string> $numbers
     * @return array<string, int>
     */
    private function memberIds(array $numbers): array
    {
        $numbers = array_values(array_unique(array_filter($numbers, static fn (string $n): bool => $n !== '')));
        if ($numbers === []) {
            return [];
        }
        $places = [];
        $bind   = [];
        foreach ($numbers as $i => $number) {
            $places[]        = ":n{$i}";
            $bind[":n{$i}"] = $number;
        }
        $read = $this->pdo->prepare(
            'SELECT id, member_number FROM member WHERE member_number IN (' . implode(', ', $places) . ')'
        );
        $read->execute($bind);

        $ids = [];
        foreach ($read->fetchAll() as $row) {
            $ids[(string) $row['member_number']] = (int) $row['id'];
        }

        return $ids;
    }

    /** A return note: collapsed, trimmed, bounded. */
    private static function note(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($value) > self::NOTE_MAX ? mb_substr($value, 0, self::NOTE_MAX) : $value;
    }
}
