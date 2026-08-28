<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;
use Rerm\App;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Subject;
use Rerm\Auth\User;

/**
 * The log-a-contact write (spec 7.1 + 8.4, Phase 5 decided 2) — the
 * application's first per-member mutation, so the pattern is set here:
 * Access::allows() with a Subject built from the member's OWN row, on every
 * write, however the request reached the route. The route guard proved the
 * user's level; only the subject check proves this member is theirs.
 *
 * Two writes, both refused when the show year is not open (spec 5.1 — the
 * schema does not enforce that; this does):
 *
 *   * contact_log INSERT — against the signed-in user, the active show year
 *     and UTC_TIMESTAMP(). Never back-dated, never edited: contact_log is
 *     append-only forever, and a mistake is corrected by logging a
 *     correcting contact, because "who said this member was paying" must
 *     stay answerable.
 *   * member_metric progress — optional, per scored metric: progress,
 *     progress_by, progress_at, progress_note. Ours, never the import's
 *     (spec 6.6); an upsert, because a member no import has covered yet has
 *     no metric row to update and the officer's record still deserves one.
 *
 * The outcome vocabulary is deliberately small and the out-of-scope answer
 * is 'not_found', indistinguishable from a member that does not exist: this
 * application does not discuss what exists with people who cannot see it.
 */
final class LogContact
{
    public const TYPES = ['call', 'text', 'email', 'in_person', 'other'];

    public const PROGRESS = ['not_started', 'in_progress', 'claimed_complete'];

    /** contact_log.notes is VARCHAR(1000); member_metric.progress_note is 500. */
    private const NOTE_MAX          = 1000;
    private const PROGRESS_NOTE_MAX = 500;

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
     *   member_id      the member the contact was with
     *   contact_type   one of TYPES
     *   note           optional free text
     *   progress       optional map of scored-metric value => '' (no change)
     *                  or one of PROGRESS
     *
     * @param array<string, mixed> $input
     * @return array{outcome: string, member_name: string, progress_changes: int}
     *         outcome: logged | no_year | year_closed | not_found | bad_type
     */
    public function log(User $user, array $input): array
    {
        $none = ['member_name' => '', 'progress_changes' => 0];

        // The active show year, read fresh on every write: an Admin closing
        // the year makes it read-only from that moment, not from the next
        // deploy (spec 5.1).
        $year = $this->pdo->query(
            'SELECT id, is_open FROM show_year WHERE is_active = 1'
        )->fetch();
        if (!is_array($year)) {
            return ['outcome' => 'no_year'] + $none;
        }
        if ((int) $year['is_open'] !== 1) {
            return ['outcome' => 'year_closed'] + $none;
        }
        $showYearId = (int) $year['id'];

        // The member, by the same visibility rules as every roster read: a
        // purged, dropped or system row takes no contact. Scope is NOT in
        // this WHERE — it is Access's question, asked next, so the refusal
        // is decided by the matrix and not by a query this class wrote.
        $memberId = is_scalar($input['member_id'] ?? null) ? (int) $input['member_id'] : 0;

        $read = $this->pdo->prepare(
            'SELECT id, member_number, first_name, last_name, preferred_name, division_id, team_id'
            . ' FROM member WHERE id = :id AND ' . ScopedQuery::visible('member')
        );
        $read->execute([':id' => $memberId]);
        $member = $read->fetch();

        if (!is_array($member)
            || !Access::allows($user, Capability::LogContact, Subject::fromMemberRow($member))
        ) {
            return ['outcome' => 'not_found'] + $none;
        }

        $type = is_string($input['contact_type'] ?? null) ? $input['contact_type'] : '';
        if (!in_array($type, self::TYPES, true)) {
            return ['outcome' => 'bad_type'] + $none;
        }

        $note = trim(is_string($input['note'] ?? null) ? $input['note'] : '');
        $note = mb_substr($note, 0, self::NOTE_MAX);

        // Which progress changes were asked for, validated BEFORE anything
        // writes, so a half-valid request never half-applies.
        $changes = [];
        foreach ((array) ($input['progress'] ?? []) as $metricValue => $progressValue) {
            if (!is_string($metricValue) || !is_string($progressValue) || $progressValue === '') {
                continue;
            }
            $metric = Metric::tryFrom($metricValue);
            if ($metric === null || !in_array($metric, Metric::scored(), true)) {
                continue;
            }
            if (!in_array($progressValue, self::PROGRESS, true)) {
                continue;
            }
            // Progress is a separate capability from the contact itself; the
            // floors are the same today, but the matrix decides, not this file.
            if (!Access::allows($user, Capability::SetMetricProgress, Subject::fromMemberRow($member))) {
                continue;
            }
            $changes[$metric->value] = $progressValue;
        }

        // One transaction: the contact and its progress notes land together
        // or not at all — a progress row citing a contact that was never
        // written is a record that lies.
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO contact_log (member_id, show_year_id, contacted_by, contact_type, occurred_at, notes)'
                . ' VALUES (:member, :year, :by, :type, UTC_TIMESTAMP(), :notes)'
            )->execute([
                ':member' => (int) $member['id'],
                ':year'   => $showYearId,
                ':by'     => $user->id,
                ':type'   => $type,
                ':notes'  => $note,
            ]);

            // Upsert, with distinct placeholder names for the UPDATE half —
            // a named placeholder cannot be reused within one statement, and
            // VALUES() in ON DUPLICATE KEY is deprecated on MySQL 8.0.
            $progressNote = mb_substr($note, 0, self::PROGRESS_NOTE_MAX);
            $write        = $this->pdo->prepare(
                'INSERT INTO member_metric'
                . ' (member_id, show_year_id, metric, progress, progress_by, progress_at, progress_note)'
                . ' VALUES (:member, :year, :metric, :progress, :by, UTC_TIMESTAMP(), :note)'
                . ' ON DUPLICATE KEY UPDATE progress = :progress2, progress_by = :by2,'
                . ' progress_at = UTC_TIMESTAMP(), progress_note = :note2'
            );
            foreach ($changes as $metricValue => $progressValue) {
                $write->execute([
                    ':member'    => (int) $member['id'],
                    ':year'      => $showYearId,
                    ':metric'    => $metricValue,
                    ':progress'  => $progressValue,
                    ':by'        => $user->id,
                    ':note'      => $progressNote,
                    ':progress2' => $progressValue,
                    ':by2'       => $user->id,
                    ':note2'     => $progressNote,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'outcome'          => 'logged',
            'member_name'      => RosterPage::displayName(
                (string) $member['preferred_name'],
                (string) $member['first_name'],
                (string) $member['last_name'],
                (string) $member['member_number']
            ),
            'progress_changes' => count($changes),
        ];
    }
}
