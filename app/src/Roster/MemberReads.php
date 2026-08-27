<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;

/**
 * The batched per-page reads every roster-shaped screen shares: metric rows,
 * contact history and assigned officers for a page's members, one query each
 * whatever the page size. Extracted from RosterPage when Phase 5 became the
 * second screen to need them — View My Roster and My Roster Status read the
 * same shapes, and a second copy of an N+1-avoiding query is a second place
 * to reintroduce the N+1.
 *
 * Never a query per row: the database is on another machine
 * (docs/hosting.md), and an N+1 at 100 rows is 200 round trips against a
 * 500ms budget.
 */
final class MemberReads
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Every metric row for the given members, one query.
     *
     * @param array<int, int> $memberIds
     * @return array<int, array<string, array{imported_value: string, progress: string}>>
     */
    public function metricsFor(array $memberIds, int $showYearId): array
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
     * The full contact history for the given members and show year — the
     * expansion needs every entry, so the newest-first list serves both it
     * and a row's "last contact" cell. One query.
     *
     * @param array<int, int> $memberIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function contactsFor(array $memberIds, int $showYearId): array
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
                'officer_name' => RosterPage::displayName(
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
     * The current assigned officers for the given members, one query.
     * Officers reference member rows, not accounts: an officer demoted since
     * assignment still has to show up here rather than vanish (spec 6.6).
     *
     * @param array<int, int> $memberIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function assignmentsFor(array $memberIds, int $showYearId): array
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
                'officer_name'  => RosterPage::displayName(
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
     * An IN () list of already-cast ints as uniquely named placeholders — a
     * named placeholder cannot be reused within one statement here.
     *
     * @param array<int, int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    public static function idList(array $ids, string $prefix): array
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
