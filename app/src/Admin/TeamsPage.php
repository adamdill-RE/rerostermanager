<?php

declare(strict_types=1);

namespace Rerm\Admin;

use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\User;
use Rerm\Roster\ScopedQuery;

/**
 * Manage Teams (spec 7.3) — the half of the area column Phase 7 deferred.
 *
 * `team.area` is **display grouping and nothing else**. It is not in the
 * export's data contract, it is seeded by a prefix heuristic
 * (006_seed_team_area.sql), and from here it is editable by an Admin. Because
 * it can move with a cosmetic edit, it must NEVER appear in
 * `Rerm\Auth\Access`, `Rerm\Roster\ScopedQuery`,
 * `Rerm\Roster\EligibleOfficers` or `Rerm\Roster\AssignOfficers` — a
 * permission that read it would move with a rename, and a test asserts all
 * four files are clean of the word, comments included.
 *
 * That is why this class is in `Rerm\Admin` and not in `Rerm\Roster`: nothing
 * about it decides who may see whom.
 *
 * The screen is ONE form and N links (the Phase 5 budget lesson): 96 teams,
 * each a row with a link that opens its own small form. A page carrying 96
 * text inputs would be 96 values in one POST — inside `max_input_vars` at
 * 1000, but a bulk edit of every area at once is a great deal of accidental
 * change surface for a column nobody edits twice a year.
 */
final class TeamsPage
{
    /** Every team, with its division, its area and how many members it holds. */
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db());
    }

    /**
     * @param array<string, mixed> $input the raw query string, untrusted
     * @return array<string, mixed>
     */
    public function page(array $input): array
    {
        // Member counts use ScopedQuery::visible() rather than a bare COUNT:
        // a team whose members are all purged holds nobody, and saying "27
        // members" of twenty-seven purged rows would be a number no other
        // screen agrees with.
        $read = $this->pdo->query(
            'SELECT t.id, t.name, t.area, t.is_active, d.name AS division_name,'
            . ' (SELECT COUNT(*) FROM member m'
            . '   WHERE m.team_id = t.id AND ' . ScopedQuery::visible('m') . ') AS members'
            . ' FROM team t'
            . ' LEFT JOIN division d ON d.id = t.division_id'
            . ' ORDER BY (t.area IS NULL), t.area, t.name'
        );

        $teams = [];
        $areas = [];
        foreach ($read->fetchAll() as $row) {
            $area = $row['area'] !== null ? (string) $row['area'] : '';

            $teams[] = [
                'id'            => (int) $row['id'],
                'name'          => (string) $row['name'],
                'area'          => $area,
                'division_name' => (string) ($row['division_name'] ?? ''),
                'members'       => (int) $row['members'],
                'is_active'     => (int) $row['is_active'] === 1,
            ];

            if ($area !== '') {
                $areas[$area] = true;
            }
        }

        ksort($areas);

        // ONE form on the page, not 96: the row named by ?team= opens its
        // editor and every other row is a link.
        $selected = (int) ($input['team'] ?? 0);

        return [
            'teams'    => $teams,
            'selected' => $selected > 0 ? $selected : null,

            // The areas already in use, offered as a datalist so an Admin
            // types "Reed Road" once and picks it thereafter. Free text is
            // still allowed — a new area has to be creatable, and a select
            // alone could never make the first one.
            'areas'    => array_keys($areas),

            // How the roll-up will read once this is saved: a team with no
            // area groups under (No area), the same honest-placeholder
            // pattern as (No Division).
            'no_area_count' => count(array_filter(
                $teams,
                static fn (array $t): bool => $t['area'] === ''
            )),
        ];
    }

    /**
     * Set or clear one team's area.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(User $user, array $input): array
    {
        $result = ['outcome' => 'not_found', 'team' => '', 'area' => ''];

        $teamId = (int) ($input['team_id'] ?? 0);
        if ($teamId <= 0) {
            return $result;
        }

        $read = $this->pdo->prepare('SELECT id, name, area FROM team WHERE id = :id');
        $read->execute([':id' => $teamId]);
        $team = $read->fetch();

        if (!is_array($team)) {
            return $result;
        }

        $result['team'] = (string) $team['name'];

        // Trimmed, whitespace-collapsed, and held to the column's 64
        // characters. Blank clears it — an area nobody set is NULL, which is
        // what groups the team under (No area).
        $area = trim((string) ($input['area'] ?? ''));
        $area = trim((string) preg_replace('/\s+/u', ' ', $area));

        if (mb_strlen($area) > 64) {
            $result['outcome'] = 'too_long';

            return $result;
        }

        $result['area'] = $area;
        $before         = $team['area'] !== null ? (string) $team['area'] : '';

        if ($before === $area) {
            $result['outcome'] = 'unchanged';

            return $result;
        }

        $this->pdo->prepare('UPDATE team SET area = :area WHERE id = :id')
            ->execute([':area' => $area === '' ? null : $area, ':id' => $teamId]);

        (new AuditLog($this->pdo))->record(
            $user,
            Action::SetTeamArea,
            'team',
            (string) $team['name'],
            ['area' => $before === '' ? null : $before],
            ['area' => $area === '' ? null : $area]
        );

        $result['outcome'] = $area === '' ? 'cleared' : 'saved';

        return $result;
    }
}
