<?php

declare(strict_types=1);

/**
 * ScopedQuery::forUser() (spec 4.3) against the real roster's SHAPE:
 * 1,954 generated members over 96 teams and the four divisions plus the
 * placeholder, with teams that span divisions, members with no team, one
 * purged, one flagged absent, and the seeded system row.
 *
 * GENERATED fixtures, never a real roster: this repository is public, and a
 * member number here is 'SQ000001'. The expected answer for every scope is
 * counted in PHP while the fixture is built, then compared with what the
 * predicate returns — so the assertion is "exactly their team", not "roughly
 * the right size".
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Roster\ScopedQuery;

function sq_pdo(): PDO
{
    static $pdo = null;
    static $failure = null;

    if ($failure !== null) {
        skip($failure);
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    try {
        $pdo = $app->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    $ready = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/**
 * Builds the fixture once and returns everything the tests need to know
 * about it: ids, and the per-scope counts computed while generating.
 *
 * @return array<string, mixed>
 */
function sq_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = sq_pdo();
    sq_teardown($pdo);

    $divisions = [];
    foreach ($pdo->query('SELECT id, name, is_placeholder FROM division ORDER BY id')->fetchAll() as $row) {
        if ((int) $row['is_placeholder'] === 1) {
            $divisions['placeholder'] = (int) $row['id'];
        } else {
            $divisions['real'][] = (int) $row['id'];
        }
    }
    assertSame(4, count($divisions['real']), 'the four seeded divisions');
    $real        = $divisions['real'];
    $placeholder = $divisions['placeholder'];

    // A batch row for the one flagged-absent member to point at.
    $show = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO import_batch (show_year_id, mode, filename, sha256, dry_run) '
        . "VALUES (:year, 'complete', 'sq-fixture', :sha, 0)"
    )->execute([':year' => $show, ':sha' => str_repeat('0', 64)]);
    $batchId = (int) $pdo->lastInsertId();

    // 96 teams. A team's OWN division_id is the modal, display-only value;
    // scope must never read it, which the spanning teams below prove.
    $teams = [];
    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)');
    for ($t = 1; $t <= 96; $t++) {
        $insertTeam->execute([
            ':name'     => sprintf('SQ Team %02d', $t),
            ':division' => $real[($t - 1) % 4],
        ]);
        $teams[$t] = (int) $pdo->lastInsertId();
    }

    // 1,954 members, and the expected counts alongside.
    $perTeam     = array_fill_keys($teams, 0);
    $perDivision = [];
    $rows        = [];
    $visible     = 0;

    $add = function (?int $teamId, int $divisionId, ?string $purgedAt, ?int $absentBatch) use (
        &$rows,
        &$perTeam,
        &$perDivision,
        &$visible
    ): void {
        $n      = count($rows) + 1;
        $rows[] = [
            'member_number' => sprintf('SQ%06d', $n),
            'first_name'    => 'Member',
            'last_name'     => sprintf('SQ%06d', $n),
            'division_id'   => $divisionId,
            'team_id'       => $teamId,
            'purged_at'     => $purgedAt,
            'absent_since'  => $absentBatch,
        ];

        if ($purgedAt === null && $absentBatch === null) {
            $visible++;
            $perDivision[$divisionId] = ($perDivision[$divisionId] ?? 0) + 1;
            if ($teamId !== null) {
                $perTeam[$teamId]++;
            }
        }
    };

    // 72 with a blank Subcommittee 3, in the placeholder: 57 honorary with
    // no team, 15 ordinary members on real teams (docs/data-findings.md 4).
    for ($i = 0; $i < 57; $i++) {
        $add(null, $placeholder, null, null);
    }
    for ($i = 0; $i < 15; $i++) {
        $add($teams[($i % 96) + 1], $placeholder, null, null);
    }

    // One purged and one flagged absent, both on team 2 in its own division,
    // so every scope that could see them must be shown not to.
    $add($teams[2], $real[1], gmdate('Y-m-d H:i:s'), null);
    $add($teams[2], $real[1], null, $batchId);

    // The remaining 1,880: round-robin over the 96 teams. Teams 1–7 SPAN two
    // divisions — every fifth of their members carries the neighbouring
    // division, because division is a property of the member, not the team.
    for ($i = 0; $i < 1880; $i++) {
        $t        = ($i % 96) + 1;
        $division = $real[($t - 1) % 4];
        if ($t <= 7 && $i % 5 === 0) {
            $division = $real[$t % 4];
        }
        $add($teams[$t], $division, null, null);
    }

    assertSame(1954, count($rows), 'the shape under test is the real roster\'s');

    foreach (array_chunk($rows, 500) as $chunk) {
        $placeholders = [];
        $bind         = [];
        foreach ($chunk as $i => $row) {
            $placeholders[] = "(:n{$i}, :f{$i}, :l{$i}, :d{$i}, :t{$i}, :p{$i}, :a{$i})";
            $bind[":n{$i}"] = $row['member_number'];
            $bind[":f{$i}"] = $row['first_name'];
            $bind[":l{$i}"] = $row['last_name'];
            $bind[":d{$i}"] = $row['division_id'];
            $bind[":t{$i}"] = $row['team_id'];
            $bind[":p{$i}"] = $row['purged_at'];
            $bind[":a{$i}"] = $row['absent_since'];
        }

        $pdo->prepare(
            'INSERT INTO member (member_number, first_name, last_name, division_id, team_id, purged_at, absent_since_import_id) '
            . 'VALUES ' . implode(', ', $placeholders)
        )->execute($bind);
    }

    register_shutdown_function(static fn () => sq_teardown(sq_pdo()));

    return $fixture = [
        'teams'       => $teams,
        'real'        => $real,
        'placeholder' => $placeholder,
        'per_team'    => $perTeam,
        'per_division' => $perDivision,
        'visible'     => $visible,
    ];
}

function sq_teardown(PDO $pdo): void
{
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'SQ%'");
    $pdo->exec("DELETE FROM import_batch WHERE filename = 'sq-fixture'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'SQ Team %'");
}

function sq_user(Level $level, ?int $divisionId, ?int $teamId): User
{
    return new User(1, 999999, 'SQUSER', $level, $divisionId, $teamId, false, 'Scope Fixture');
}

/**
 * Every fixture row the predicate lets this user see.
 *
 * @return array<int, array<string, mixed>>
 */
function sq_rows(User $user): array
{
    $scoped = ScopedQuery::forUser($user);

    // The fixture filter is bound like everything else, so the only literal
    // SQL in this test is the predicate under test.
    $statement = sq_pdo()->prepare(
        'SELECT m.member_number, m.division_id, m.team_id, m.is_system '
        . 'FROM member m WHERE ' . $scoped->predicate() . ' AND m.member_number LIKE :fixture_only'
    );
    $statement->execute($scoped->bindings() + [':fixture_only' => 'SQ%']);

    return $statement->fetchAll();
}

// ---------------------------------------------------------------------------

test('an Officer sees exactly their team — every row, and no other row', function (): void {
    $fixture = sq_fixture();
    $teamId  = $fixture['teams'][10];

    $rows = sq_rows(sq_user(Level::Officer, $fixture['real'][1], $teamId));

    assertSame($fixture['per_team'][$teamId], count($rows), 'exactly the team, member for member');
    foreach ($rows as $row) {
        assertSame($teamId, (int) $row['team_id'], 'nothing from any other team');
    }
});

test('an Officer on a division-spanning team sees the WHOLE team, both divisions of it', function (): void {
    // Seven real teams span two divisions. Scope is the team, so the officer
    // sees every teammate — including the ones whose division differs from
    // the team's own display value. A scope read from team.division_id would
    // fail exactly here.
    $fixture = sq_fixture();
    $teamId  = $fixture['teams'][1];

    $rows = sq_rows(sq_user(Level::Officer, $fixture['real'][0], $teamId));

    assertSame($fixture['per_team'][$teamId], count($rows));

    $divisions = array_unique(array_map(static fn (array $r): int => (int) $r['division_id'], $rows));
    assertTrue(count($divisions) >= 2, 'the spanning team really does span, and all of it is visible');
});

test('a Senior Officer sees exactly their division, spanning teams included', function (): void {
    $fixture    = sq_fixture();
    $divisionId = $fixture['real'][0];

    $rows = sq_rows(sq_user(Level::SeniorOfficer, $divisionId, null));

    assertSame($fixture['per_division'][$divisionId], count($rows), 'the whole division and nothing else');
    foreach ($rows as $row) {
        assertSame($divisionId, (int) $row['division_id']);
    }

    // And a neighbouring division's count is its own — no overlap, no gap.
    $other = sq_rows(sq_user(Level::SeniorOfficer, $fixture['real'][2], null));
    assertSame($fixture['per_division'][$fixture['real'][2]], count($other));
});

test('a Senior Officer can be scoped to (No Division), and it holds all 72', function (): void {
    $fixture = sq_fixture();

    $rows = sq_rows(sq_user(Level::SeniorOfficer, $fixture['placeholder'], null));

    assertSame(72, count($rows), '57 honorary plus 15 ordinary members — reachable, owned');
});

test('Executives and Admins see the whole committee, minus purged, absent and system rows', function (): void {
    $fixture = sq_fixture();

    foreach ([Level::ExecutiveOfficer, Level::Admin] as $level) {
        $rows = sq_rows(sq_user($level, null, null));

        assertSame($fixture['visible'], count($rows), $level->value . ' sees every visible member');
        assertSame(1952, count($rows), '1,954 minus one purged and one flagged absent');

        foreach ($rows as $row) {
            assertSame(0, (int) $row['is_system'], 'the seeded administrator is never a roster row');
        }
    }
});

test('a scope that resolves to nothing sees nothing, never everything', function (): void {
    $fixture = sq_fixture();

    assertSame([], sq_rows(sq_user(Level::Officer, $fixture['real'][0], null)),
        'an Officer with no team');
    assertSame([], sq_rows(sq_user(Level::SeniorOfficer, null, null)),
        'a Senior Officer with no division');
    assertSame([], sq_rows(sq_user(Level::Member, $fixture['real'][0], $fixture['teams'][1])),
        'a Member-level login holds no roster capability at all');
});

test('the predicate itself excludes purged and absent members from every scope', function (): void {
    $fixture = sq_fixture();

    // Both landed on team 2. The officer of team 2 must not see either.
    $teamId = $fixture['teams'][2];
    $rows   = sq_rows(sq_user(Level::Officer, $fixture['real'][1], $teamId));

    $seen = sq_pdo()->prepare(
        'SELECT member_number FROM member WHERE team_id = :team AND (purged_at IS NOT NULL OR absent_since_import_id IS NOT NULL)'
    );
    $seen->execute([':team' => $teamId]);
    $hidden = $seen->fetchAll(PDO::FETCH_COLUMN);

    assertSame(2, count($hidden), 'the fixture put exactly two hidden members here');

    $numbers = array_column($rows, 'member_number');
    foreach ($hidden as $number) {
        assertTrue(!in_array($number, $numbers, true), "{$number} is hidden from the roster");
    }
});

test('scoped query fixtures are cleaned up', function (): void {
    sq_teardown(sq_pdo());

    assertSame(
        0,
        (int) sq_pdo()->query("SELECT COUNT(*) FROM member WHERE member_number LIKE 'SQ%'")->fetchColumn()
    );
    assertSame(
        0,
        (int) sq_pdo()->query("SELECT COUNT(*) FROM team WHERE name LIKE 'SQ Team %'")->fetchColumn()
    );
});
