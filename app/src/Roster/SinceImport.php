<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;

/**
 * What the last applied import did for the people on a screen (Phase 10.5,
 * spec-v2 §10.1): how many of them Rodeo Houston's file moved to Y on each
 * requirement. An officer chasing people for weeks never saw whether it was
 * working; `import_change` (Phase 10) records every metric flip per member
 * per import, so "how many of mine moved to Complete in the last file" is a
 * count and not a migration.
 *
 * The count is read through whatever predicate the SCREEN is using — the
 * scope, the toggle, a drill-down, a search — so the number under a card
 * describes exactly the people the card counts, the same rule every other
 * figure on My Roster Status obeys. The roll-up groups the same rows by
 * member and tallies them per division, area and team.
 *
 * Read-only, and it reads only what an import wrote: never a contact, a
 * progress value or anything else that is ours.
 */
final class SinceImport
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The last applied import, or null before the first.
     *
     * @return ?array{id: int, applied_at: string, mode: string}
     */
    public function latest(): ?array
    {
        $row = $this->pdo->query(
            'SELECT id, applied_at, mode FROM import_batch'
            . ' WHERE applied_at IS NOT NULL ORDER BY applied_at DESC, id DESC LIMIT 1'
        )->fetch();

        return is_array($row)
            ? ['id' => (int) $row['id'], 'applied_at' => (string) $row['applied_at'], 'mode' => (string) $row['mode']]
            : null;
    }

    /**
     * Per scored metric, how many members matching the caller's predicate
     * over `m` were moved to Y by that import.
     *
     * @param array<string, mixed> $bind the predicate's bindings
     * @return array<string, int> Metric->value => members
     */
    public function flipsToY(int $batchId, string $where, array $bind): array
    {
        $counts = [];
        foreach (Metric::scored() as $metric) {
            $counts[$metric->value] = 0;
        }

        $read = $this->pdo->prepare(
            'SELECT c.field, COUNT(DISTINCT c.member_id) AS members'
            . ' FROM import_change c INNER JOIN member m ON m.id = c.member_id'
            . " WHERE {$where} AND c.import_batch_id = :since_batch"
            . " AND c.kind = 'updated' AND c.after_value = 'Y' AND c.field LIKE 'metric:%'"
            . ' GROUP BY c.field'
        );
        $read->execute($bind + [':since_batch' => $batchId]);

        foreach ($read->fetchAll() as $row) {
            $metric = substr((string) $row['field'], strlen('metric:'));
            if (isset($counts[$metric])) {
                $counts[$metric] = (int) $row['members'];
            }
        }

        return $counts;
    }

    /**
     * Per member, how many requirements that import moved to Y — for the
     * roll-up, which tallies members into groups of its own.
     *
     * @param array<string, mixed> $bind
     * @return array<int, int> member id => flips
     */
    public function flipsByMember(int $batchId, string $where, array $bind): array
    {
        $read = $this->pdo->prepare(
            'SELECT c.member_id, COUNT(*) AS flips'
            . ' FROM import_change c INNER JOIN member m ON m.id = c.member_id'
            . " WHERE {$where} AND c.import_batch_id = :since_batch"
            . " AND c.kind = 'updated' AND c.after_value = 'Y' AND c.field LIKE 'metric:%'"
            . ' GROUP BY c.member_id'
        );
        $read->execute($bind + [':since_batch' => $batchId]);

        $flips = [];
        foreach ($read->fetchAll() as $row) {
            $flips[(int) $row['member_id']] = (int) $row['flips'];
        }

        return $flips;
    }
}
