<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;
use Rerm\App;
use Rerm\Auth\User;
use Rerm\Forms\RcfTracking;

/**
 * One member, on one screen (Phase 10.4, spec-v2 §9.1) — the single-member
 * screen spec-v1 §8.2 named among those that keep the narrow column, and
 * which never existed: every mention of a person was a row in a list, and a
 * name on Assign Officers, Dropped Members or Import History had nowhere to
 * link.
 *
 * It is the roster row's shape, for one person, plus the two things a list
 * cannot afford to carry fifty times: the whole contact history across EVERY
 * show year (OI-12's deferred report, which spec-v1 §5.5 kept the data for),
 * and the log-contact form open rather than one row at a time. A page a
 * tenth the size of the list loads in a parking lot when the list will not.
 *
 * SCOPE. The member is read through ScopedQuery::forUser() — the same
 * predicate every list applies — and, failing that, through
 * ScopedQuery::droppedForUser(), so the screen serves Dropped Members too
 * and says so. Out of scope, purged or missing is null, and the route
 * answers the same 404 a typed URL would: this application does not discuss
 * what exists with people who cannot see it.
 *
 * Every derived value — the four statuses, the Result word, the contact
 * flags — comes from the same functions the lists use (MetricStatus::derive,
 * ContactOutcome::summarise, the CELL PHONE rule), so nothing here can
 * disagree with the row the person was opened from.
 */
final class MemberPage
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db());
    }

    /**
     * The member, or null when the caller may not see them.
     *
     * @return ?array<string, mixed>
     */
    public function page(User $user, int $showYearId, int $memberId): ?array
    {
        if ($memberId <= 0) {
            return null;
        }

        $member  = null;
        $dropped = false;
        foreach ([ScopedQuery::forUser($user), ScopedQuery::droppedForUser($user)] as $i => $scoped) {
            $read = $this->pdo->prepare(
                'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name, m.title,'
                . ' m.phone, m.phone_e164, m.phone_type, m.email, m.division_id, m.team_id,'
                . ' m.dropped_since_import_id, t.name AS team_name, d.name AS division_name'
                . ' FROM member m'
                . ' LEFT JOIN team t ON t.id = m.team_id'
                . ' INNER JOIN division d ON d.id = m.division_id'
                . ' WHERE m.id = :id AND ' . $scoped->predicate()
            );
            $read->execute($scoped->bindings() + [':id' => $memberId]);
            $row = $read->fetch();
            if (is_array($row)) {
                $member  = $row;
                $dropped = $i === 1;
                break;
            }
        }

        if ($member === null) {
            return null;
        }

        $id          = (int) $member['id'];
        $reads       = new MemberReads($this->pdo);
        $metrics     = $reads->metricsFor([$id], $showYearId)[$id] ?? [];
        $contacts    = $reads->contactsFor([$id], $showYearId)[$id] ?? [];
        $assignments = $reads->assignmentsFor([$id], $showYearId)[$id] ?? [];
        $contacted   = $contacts !== [];

        $statuses = [];
        $complete = 0;
        foreach (Metric::cases() as $metric) {
            $values = $metrics[$metric->value] ?? null;
            $statuses[$metric->value] = MetricStatus::derive(
                $values['imported_value'] ?? 'unknown',
                $values['progress'] ?? 'not_started',
                $contacted
            );
            if (in_array($metric, Metric::scored(), true) && $statuses[$metric->value] === MetricStatus::Complete) {
                $complete++;
            }
        }

        return [
            'id'            => $id,
            'member_number' => (string) $member['member_number'],
            'display_name'  => RosterPage::displayName(
                (string) $member['preferred_name'],
                (string) $member['first_name'],
                (string) $member['last_name'],
                (string) $member['member_number']
            ),
            'title'         => (string) $member['title'],
            'team_name'     => (string) ($member['team_name'] ?? ''),
            'division_name' => (string) $member['division_name'],
            'phone'         => (string) $member['phone'],
            'phone_e164'    => (string) ($member['phone_e164'] ?? ''),
            'phone_type'    => (string) $member['phone_type'],
            'email'         => trim((string) ($member['email'] ?? '')),
            'statuses'      => $statuses,
            'fully'         => $complete === count(Metric::scored()),
            'can_call'      => (string) ($member['phone_e164'] ?? '') !== '',
            'can_text'      => (string) ($member['phone_e164'] ?? '') !== ''
                && (string) $member['phone_type'] === 'CELL PHONE',
            'can_email'     => trim((string) ($member['email'] ?? '')) !== '',
            'contacts'      => $contacts,
            'last_contact'  => $contacts[0] ?? null,
            'officers'      => $assignments,
            'outcome'       => ContactOutcome::summarise($statuses, $contacted),
            'dropped'       => $dropped,
            'dropped_batch' => $member['dropped_since_import_id'] === null
                ? null
                : (int) $member['dropped_since_import_id'],
            'other_years'   => $this->otherYears($id, $showYearId),

            // Every Roster Change Form this member is on (Phase 11, spec-v2
            // §12): the question "was one ever submitted for them" is asked
            // about a person, and this is the person's page. Shown to anyone
            // who can see the member; each line says whether the caller may
            // open the form it is on.
            'rcfs'          => (new RcfTracking($this->pdo))->forMember($user, $id, (string) $member['member_number']),
        ];
    }

    /**
     * The contact history from every OTHER show year, newest year first,
     * each with its label (OI-12). contact_log is retained across every
     * show year precisely so that this is a query and not a migration
     * (spec-v1 §5.5). One query, however many years there are.
     *
     * @return array<int, array{label: string, contacts: array<int, array<string, mixed>>}>
     */
    private function otherYears(int $memberId, int $showYearId): array
    {
        $read = $this->pdo->prepare(
            'SELECT y.id AS year_id, y.label AS year_label,'
            . ' c.contact_type, c.occurred_at, c.notes, c.contact_import_batch_id,'
            . ' om.preferred_name AS officer_preferred, om.first_name AS officer_first,'
            . ' om.last_name AS officer_last, om.member_number AS officer_number'
            . ' FROM contact_log c'
            . ' INNER JOIN show_year y ON y.id = c.show_year_id'
            . ' INNER JOIN app_user au ON au.id = c.contacted_by'
            . ' INNER JOIN member om ON om.id = au.member_id'
            . ' WHERE c.member_id = :member AND c.show_year_id <> :year'
            . ' ORDER BY y.starts_on DESC, y.id DESC, c.occurred_at DESC, c.id DESC'
        );
        $read->execute([':member' => $memberId, ':year' => $showYearId]);

        $years = [];
        foreach ($read->fetchAll() as $row) {
            $yearId = (int) $row['year_id'];
            $years[$yearId] ??= ['label' => (string) $row['year_label'], 'contacts' => []];
            $years[$yearId]['contacts'][] = [
                'contact_type' => (string) $row['contact_type'],
                'occurred_at'  => (string) $row['occurred_at'],
                'notes'        => (string) $row['notes'],
                'from_history' => $row['contact_import_batch_id'] !== null,
                'officer_name' => RosterPage::displayName(
                    (string) $row['officer_preferred'],
                    (string) $row['officer_first'],
                    (string) $row['officer_last'],
                    (string) $row['officer_number']
                ),
            ];
        }

        return array_values($years);
    }
}
