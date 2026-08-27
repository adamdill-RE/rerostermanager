<?php

declare(strict_types=1);

/**
 * The Committee Dashboard (spec 7.3, Phase 7): the area heuristic the
 * migration seeds, the three-level roll-up, and the promise the whole screen
 * rests on — every figure equals the list spec 7.1 shows when filtered to it.
 *
 * That promise is what most of this file is. A roll-up nobody can check is a
 * roll-up nobody should act on, so each group's members, unassigned and
 * never-contacted figures are put back through StatusPage using the exact
 * URL the drill-down link carries, and the two have to agree — not
 * approximately, and not only in total, but in WHICH members.
 *
 * The fixture is generated and the expectations are TRANSCRIBED beside it,
 * never computed by the code under test: every group's four figures are
 * written out as literals, so a change that quietly reclassifies somebody
 * fails here rather than being confirmed by its own arithmetic.
 *
 * The fixture builds its OWN divisions rather than borrowing the seeded ones.
 * Every suite in this directory shares one database and they run in one
 * process, so a roll-up over a real division would be counting whatever else
 * is loaded at the time. Its placeholder division is flagged
 * `is_placeholder = 1` like the real `(No Division)`, because "it sorts,
 * groups and drills down like any other" (spec 5.1a) is exactly what this
 * screen has to prove.
 *
 * Generated, never real: this repository is public. Member numbers here are
 * 'CD000001', phones are the reserved (555) 555-01xx fiction range, and
 * addresses are @example.com.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Scope;
use Rerm\Auth\User;
use Rerm\Migrator;
use Rerm\Roster\AssignPage;
use Rerm\Roster\CommitteePage;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
use Rerm\Roster\StatusPage;
use Rerm\Routes;

// ---------------------------------------------------------------------------
// The route, the matrix and the rules that need no database
// ---------------------------------------------------------------------------

test('the committee route is guarded by the capability, at the Senior Officer floor', function (): void {
    assertSame(Capability::ViewCommitteeDashboard->value, Routes::guard('committee'));
    assertSame(Level::SeniorOfficer, Capability::ViewCommitteeDashboard->minimumLevel());
    assertSame(Scope::Scoped, Capability::ViewCommitteeDashboard->scope());
});

test('the menu tile points at the screen now that it exists', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../app/views/menu.php');
    assertTrue($source !== '', 'app/views/menu.php is readable');

    $line = '';
    foreach (explode("\n", $source) as $candidate) {
        if (str_contains($candidate, 'Capability::ViewCommitteeDashboard')) {
            $line = $candidate;
        }
    }

    assertTrue($line !== '', 'the menu names Capability::ViewCommitteeDashboard');
    assertTrue(str_contains($line, "'route' => 'committee'"), "the tile links to /committee, got: {$line}");
});

test('the screen has no write path at all — no POST, no CSRF, no form', function (): void {
    // Spec 7.3 is read-only. The absence is asserted rather than assumed:
    // a form added here later would need its own CSRF check and its own
    // per-member Access::allows(), and this is where that gets noticed.
    $source = (string) file_get_contents(__DIR__ . '/../app/views/committee.php');
    assertTrue($source !== '', 'app/views/committee.php is readable');

    assertSame(0, preg_match('/<form\b/i', $source), 'no form on a read-only screen');
    assertSame(0, preg_match('/\bCsrf\b/', $source), 'nothing to protect, so nothing to check');
    assertSame(0, preg_match('/method=["\']post/i', $source), 'no POST');
});

test('every sort key the URL may spell is a whitelist entry, and the default is triage', function (): void {
    // Transcribed a second time. The roll-up sorts in PHP over rows already
    // derived, so no sort key reaches a query — but the key still chooses
    // FROM a list, because "it cannot reach SQL today" is not a rule, it is
    // a coincidence of this implementation.
    assertSame(
        ['contact', 'unassigned', 'no_officer', 'members', 'name',
            'hlsr_dues', 'committee_dues', 'indemnity', 'background_check'],
        CommitteePage::sortKeys()
    );

    // Spec 7.3 decided 1: at 50-65% outstanding the compliance columns cannot
    // distinguish 96 teams, so the screen opens on where nobody is working.
    assertSame('contact', CommitteePage::DEFAULT_SORT);
    assertSame('desc', CommitteePage::DEFAULT_DIR);
});

test('team.area appears nowhere in Access, ScopedQuery or the eligibility rule', function (): void {
    // access_test.php owns this assertion; it is repeated here because Phase 7
    // is the phase that makes the column real. It is display grouping, seeded
    // by a prefix heuristic and Admin-editable from Phase 8, so a permission
    // that read it would move with a cosmetic edit.
    foreach ([
        'Auth/Access.php',
        'Roster/ScopedQuery.php',
        'Roster/EligibleOfficers.php',
        'Roster/AssignOfficers.php',
    ] as $file) {
        $source = (string) file_get_contents(__DIR__ . '/../app/src/' . $file);
        assertTrue($source !== '', $file . ' is readable');
        assertSame(0, preg_match('/\barea\b/i', $source), $file . ' must never mention team.area');
    }
});

// ---------------------------------------------------------------------------
// The migration's own SQL, read off disk and run
// ---------------------------------------------------------------------------

/** The `team` UPDATE out of migration 006, as the migrator would split it. */
function cd_area_statement(): string
{
    $sql = (string) file_get_contents(__DIR__ . '/../db/migrations/006_seed_team_area.sql');
    assertTrue($sql !== '', 'db/migrations/006_seed_team_area.sql is readable');

    foreach (Migrator::split($sql) as $statement) {
        if (str_starts_with(ltrim($statement), 'UPDATE `team`')) {
            return $statement;
        }
    }

    throw new RuntimeException('migration 006 has no UPDATE of `team`');
}

test('the area migration is pure data, atomic, and touches only what it seeds', function (): void {
    $sql = (string) file_get_contents(__DIR__ . '/../db/migrations/006_seed_team_area.sql');

    assertTrue(
        (new Migrator($GLOBALS['rerm_app']->db(), __DIR__ . '/../db/migrations'))->isAtomic($sql),
        'a pure-data migration takes a transaction'
    );

    $statements = Migrator::split($sql);
    assertSame(2, count($statements), 'the team seed and the one-line master administrator fix');

    foreach ($statements as $statement) {
        assertTrue(str_starts_with(ltrim($statement), 'UPDATE '), 'no DDL in an atomic migration');
    }

    // The seed fills in what nobody has decided; it never overwrites an area
    // an Admin will be able to edit from Phase 8.
    assertTrue(str_contains(cd_area_statement(), 'WHERE `area` IS NULL'), 'it seeds, it does not rewrite');
});

test('the seven areas are matched longest first, so the longest prefix always wins', function (): void {
    // The rule is "every other team takes the LONGEST of the seven its name
    // starts with". A CASE returns its first match, so the rule holds exactly
    // as long as the branches run longest name first — which is a property of
    // the file, and therefore checkable in the file.
    preg_match_all("/WHEN `name` LIKE '([^%]+)%'/", cd_area_statement(), $matches);
    $areas = $matches[1];

    $expected = ['Reed Road', '610', 'Emlr', 'Bus Ops', 'Ost-Smith Lands', 'Chuckwagon', 'Administration'];
    sort($expected);
    $sorted = $areas;
    sort($sorted);
    assertSame($expected, $sorted, 'the seven bare-area team names, and only those');

    $previous = PHP_INT_MAX;
    foreach ($areas as $area) {
        assertTrue(
            strlen($area) <= $previous,
            "'{$area}' is longer than the branch before it — the longest match would be skipped"
        );
        $previous = strlen($area);
    }
});

// ---------------------------------------------------------------------------
// The database under test — the same accessor pattern as the other suites
// ---------------------------------------------------------------------------

function cd_pdo(): PDO
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
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'team' AND COLUMN_NAME = 'area'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

function cd_teardown(PDO $pdo): void
{
    $members = "SELECT id FROM member WHERE member_number LIKE 'CD%'";
    $users   = "SELECT id FROM app_user WHERE member_id IN ({$members})";

    // RESTRICT-safe order: audit rows point at app_user, assignments point at
    // both member and app_user, member is last, and the divisions this
    // fixture made are last of all.
    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
    $pdo->exec("DELETE FROM contact_log WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM assignment WHERE member_id IN ({$members}) OR officer_member_id IN ({$members})");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'CD%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE '% CD'");
    $pdo->exec("DELETE FROM division WHERE name LIKE 'CD %'");
}

/**
 * The team names, shaped exactly like the export's — the seven area prefixes
 * plus one that matches nothing — with the fixture marker at the END, because
 * the heuristic reads the START of the name and a prefix marker would stop
 * every one of them matching.
 *
 * @var array<string, string> key => name
 */
const CD_TEAMS = [
    'rr_park' => 'Reed Road Parking Team A CD',
    'rr_bare' => 'Reed Road CD',
    'six'     => '610 Parking Team J CD',
    'emlr'    => 'Emlr Team B CD',
    'busops'  => 'Bus Ops-Early Bird Team 1 CD',
    'ost'     => 'Ost-Smith Lands Team 1 CD',
    'chuck'   => 'Chuckwagon CD',
    'admin'   => 'Administration-Support CD',
    'life'    => 'Lifetime CD',
];

/** What migration 006 must make of each of them. Transcribed, not derived. */
const CD_AREAS = [
    'rr_park' => 'Reed Road',
    'rr_bare' => 'Reed Road',
    'six'     => '610',
    'emlr'    => 'Emlr',
    'busops'  => 'Bus Ops',
    'ost'     => 'Ost-Smith Lands',
    'chuck'   => 'Chuckwagon',
    'admin'   => 'Administration',
    'life'    => null,
];

/**
 * FIVE divisions of this fixture's own, mirroring the export's shape — four
 * real ones and a placeholder — so "an Executive sees all five" is a thing
 * this file can assert without depending on what else is loaded.
 *
 *   CD Satellites Division        rr_park  a1 a2 a3 off   Reed Road
 *                                 rr_bare  a4 a5          Reed Road
 *                                 six      a6             610
 *                                 life     a7             (No area)
 *                                 -        a8             (No area)/(No team)
 *   CD Bus Ops Division           emlr     b1 b2          Emlr
 *                                 rr_park  b3             Reed Road  <- the
 *                                                    team that spans divisions
 *   CD Logistics Division         busops   c1             Bus Ops
 *   CD Member Services Division   ost      d1             Ost-Smith Lands
 *   CD (No Division)              chuck    n1 n2          Chuckwagon
 *
 * `off` is a Captain on rr_park and the ONLY eligible officer anywhere in the
 * fixture, so every member on another team counts under "no officer on this
 * team" and rr_park's members do not. a1 and a2 are assigned to them.
 *
 * admin holds nobody: it exists for the area heuristic alone, and a group
 * with no members in scope is not a row.
 *
 * @return array<string, mixed>
 */
function cd_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = cd_pdo();
    cd_teardown($pdo);

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();
    assertTrue($year > 0, 'the seeded active show year exists');

    $insertDivision = $pdo->prepare('INSERT INTO division (name, is_placeholder) VALUES (:name, :placeholder)');
    $divisions      = [];
    foreach ([
        'A'    => ['CD Satellites Division', 0],
        'B'    => ['CD Bus Ops Division', 0],
        'C'    => ['CD Logistics Division', 0],
        'D'    => ['CD Member Services Division', 0],
        'none' => ['CD (No Division)', 1],
    ] as $key => [$name, $placeholder]) {
        $insertDivision->execute([':name' => $name, ':placeholder' => $placeholder]);
        $divisions[$key] = (int) $pdo->lastInsertId();
    }

    // Teams go in with area NULL and are seeded by the MIGRATION'S OWN
    // statement, read off disk. So the grouping every assertion below relies
    // on is the grouping production will have, and the heuristic is under
    // test rather than restated.
    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id, area) VALUES (:name, :division, NULL)');
    $teams      = [];
    foreach (CD_TEAMS as $key => $name) {
        $insertTeam->execute([':name' => $name, ':division' => $divisions['A']]);
        $teams[$key] = (int) $pdo->lastInsertId();
    }
    $pdo->exec(cd_area_statement());

    $specs = [
        // key      division team       contacted assigned  metrics (hlsr, committee, indemnity, bg)
        'a1'  => ['A',    'rr_park', true,  true,  'YYYY', 'Committee Member', 'member'],
        'a2'  => ['A',    'rr_park', false, true,  'NYYY', 'Committee Member', 'member'],
        'a3'  => ['A',    'rr_park', false, false, 'NNNN', 'Committee Member', 'member'],
        'off' => ['A',    'rr_park', false, false, 'YYYY', 'Captain',          'officer'],
        'a4'  => ['A',    'rr_bare', true,  false, 'NNNN', 'Committee Member', 'member'],
        'a5'  => ['A',    'rr_bare', false, false, 'NNNN', 'Committee Member', 'member'],
        'a6'  => ['A',    'six',     false, false, 'NNNN', 'Committee Member', 'member'],
        'a7'  => ['A',    'life',    false, false, 'NNNN', 'Committee Member', 'member'],
        'a8'  => ['A',    null,      false, false, 'NNNN', 'Committee Member', 'member'],
        'b1'  => ['B',    'emlr',    true,  false, 'YYYY', 'Committee Member', 'member'],
        'b2'  => ['B',    'emlr',    false, false, 'NNNN', 'Committee Member', 'member'],
        'b3'  => ['B',    'rr_park', false, false, 'NNNN', 'Committee Member', 'member'],
        'c1'  => ['C',    'busops',  false, false, 'NNNN', 'Committee Member', 'member'],
        'd1'  => ['D',    'ost',     true,  false, 'NNNN', 'Committee Member', 'member'],
        'n1'  => ['none', 'chuck',   false, false, 'NNNN', 'Committee Member', 'member'],
        'n2'  => ['none', 'chuck',   true,  false, 'NNNN', 'Committee Member', 'member'],
    ];

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, division_id, team_id,'
        . ' phone, phone_e164, phone_type, email, title, title_level)'
        . " VALUES (:number, 'Fixture', :last, '', :division, :team,"
        . " '(555) 555-0117', '+15555550117', 'CELL PHONE', :email, :title, :level)"
    );
    $insertMetric = $pdo->prepare(
        'INSERT INTO member_metric (member_id, show_year_id, metric, imported_value, progress)'
        . " VALUES (:member, :year, :metric, :value, 'not_started')"
    );

    $members = [];
    $n       = 0;
    foreach ($specs as $key => [$division, $team, , , $metrics, $title, $level]) {
        $n++;
        $number = sprintf('CD%06d', $n);
        $insertMember->execute([
            ':number'   => $number,
            ':last'     => ucfirst($key),
            ':division' => $divisions[$division],
            ':team'     => $team === null ? null : $teams[$team],
            ':email'    => strtolower($number) . '@example.com',
            ':title'    => $title,
            ':level'    => $level,
        ]);
        $id = (int) $pdo->lastInsertId();

        foreach (Metric::scored() as $i => $metric) {
            $insertMetric->execute([
                ':member' => $id,
                ':year'   => $year,
                ':metric' => $metric->value,
                ':value'  => $metrics[$i],
            ]);
        }

        $members[$key] = ['id' => $id, 'number' => $number];
    }

    // One account, so contacts and assignments have an author. The officer's
    // ELIGIBILITY comes from title_level, not from this row (spec 5.2:
    // assignments reference member rows because they outlive logins).
    $pdo->prepare(
        'INSERT INTO app_user (member_id, level, password_hash, must_change_password, is_active)'
        . " VALUES (:member, 'officer', '*', 0, 1)"
    )->execute([':member' => $members['off']['id']]);
    $userId = (int) $pdo->lastInsertId();

    $insertContact = $pdo->prepare(
        'INSERT INTO contact_log (member_id, show_year_id, contacted_by, contact_type, occurred_at, notes)'
        . " VALUES (:member, :year, :by, 'call', UTC_TIMESTAMP(), 'Fixture call.')"
    );
    $insertAssign = $pdo->prepare(
        'INSERT INTO assignment (member_id, officer_member_id, show_year_id, assigned_by)'
        . ' VALUES (:member, :officer, :year, :by)'
    );

    foreach ($specs as $key => [, , $contacted, $assigned]) {
        if ($contacted) {
            $insertContact->execute([':member' => $members[$key]['id'], ':year' => $year, ':by' => $userId]);
        }
        if ($assigned) {
            $insertAssign->execute([
                ':member'  => $members[$key]['id'],
                ':officer' => $members['off']['id'],
                ':year'    => $year,
                ':by'      => $userId,
            ]);
        }
    }

    register_shutdown_function(static fn () => cd_teardown(cd_pdo()));

    $fixture = [
        'year'      => $year,
        'divisions' => $divisions,
        'teams'     => $teams,
        'members'   => $members,
        'user'      => $userId,
    ];

    return $fixture;
}

/** A Senior Officer over one of the fixture's divisions — an isolated scope. */
function cd_senior(string $division = 'A'): User
{
    $f = cd_fixture();

    return new User(
        $f['user'],
        $f['members']['off']['id'],
        $f['members']['off']['number'],
        Level::SeniorOfficer,
        $f['divisions'][$division],
        null,
        false,
        'Fixture Senior'
    );
}

/** An Executive Officer — the whole committee, every division. */
function cd_executive(): User
{
    $f = cd_fixture();

    return new User(
        $f['user'],
        $f['members']['off']['id'],
        $f['members']['off']['number'],
        Level::ExecutiveOfficer,
        null,
        null,
        false,
        'Fixture Executive'
    );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function cd_page(User $user, array $input = []): array
{
    return CommitteePage::fromApp($GLOBALS['rerm_app'])->page($user, cd_fixture()['year'], $input);
}

/**
 * One row out of a page, by level and name — the shape every assertion below
 * reads, so a missing group fails with its own name rather than as an
 * undefined index somewhere further down.
 *
 * @param array<string, mixed> $page
 * @return array<string, mixed>
 */
function cd_row(array $page, string $level, string $name): array
{
    foreach ($page['rows'] as $row) {
        if ($row['level'] === $level && $row['name'] === $name) {
            return $row;
        }
    }

    $seen = [];
    foreach ($page['rows'] as $row) {
        $seen[] = $row['level'] . ':' . $row['name'];
    }

    throw new RuntimeException("no {$level} row named '{$name}'. Rows: " . implode(', ', $seen));
}

/**
 * The four figures of one row, in one array, so an expectation is one
 * transcribed line rather than four assertions.
 *
 * @param array<string, mixed> $row
 * @return array<string, int>
 */
function cd_figures(array $row): array
{
    return [
        'members'         => (int) $row['members'],
        'unassigned'      => (int) $row['unassigned'],
        'no_officer'      => (int) $row['no_officer'],
        'never_contacted' => (int) $row['never_contacted'],
    ];
}

// ---------------------------------------------------------------------------
// The migration's heuristic, over names shaped like the real ones
// ---------------------------------------------------------------------------

test('every team takes the area its name starts with, and one takes none', function (): void {
    $f = cd_fixture();

    $read = cd_pdo()->prepare('SELECT name, area FROM team WHERE id = :team');

    foreach (CD_AREAS as $key => $expected) {
        $read->execute([':team' => $f['teams'][$key]]);
        $row = $read->fetch();

        assertSame(
            $expected,
            $row['area'] === null ? null : (string) $row['area'],
            CD_TEAMS[$key] . ' takes its area from its name'
        );
    }
});

// ---------------------------------------------------------------------------
// Scope: who sees which groups
// ---------------------------------------------------------------------------

test('a Senior Officer sees exactly their own division, and an Executive sees every one', function (): void {
    $senior = cd_page(cd_senior('A'));

    $divisions = [];
    foreach ($senior['rows'] as $row) {
        if ($row['level'] === 'division') {
            $divisions[] = $row['name'];
        }
    }
    assertSame(['CD Satellites Division'], $divisions, 'one division, and it is theirs');
    assertSame(1, (int) $senior['divisions']);

    // The only division at its level is simply open — there is nothing to
    // collapse it to — so their areas are on the first screen.
    assertTrue($senior['sole_division'], 'one division in scope is always open');
    assertSame($divisions !== [] ? cd_fixture()['divisions']['A'] : null, $senior['open_division']);

    $seen = [];
    foreach (cd_page(cd_executive())['rows'] as $row) {
        if ($row['level'] === 'division' && str_starts_with($row['name'], 'CD ')) {
            $seen[] = $row['name'];
        }
    }
    sort($seen);

    // Four divisions and the placeholder: an Executive sees all five, and
    // (No Division) is never hidden for being untidy (spec 5.1a).
    assertSame([
        'CD (No Division)',
        'CD Bus Ops Division',
        'CD Logistics Division',
        'CD Member Services Division',
        'CD Satellites Division',
    ], $seen, 'an Executive sees every division that holds anybody');
});

test('a placeholder division rolls up and drills down like any other', function (): void {
    // Spec 5.1a rule 3 in screen form. It is bookkeeping, not a fact from the
    // export — and it still sorts, counts and links.
    $page = cd_page(cd_senior('none'));
    $row  = cd_row($page, 'division', 'CD (No Division)');

    assertTrue((bool) $row['placeholder'], 'the row knows it is a placeholder');
    assertTrue((bool) $row['drillable'], 'and it drills down anyway');
    assertSame(['members' => 2, 'unassigned' => 2, 'no_officer' => 2, 'never_contacted' => 1], cd_figures($row));
});

test('a member with no team is counted in their own group, never dropped', function (): void {
    // Assignment is same-team, so a member with no team can never be assigned
    // (spec 7.4 bucket 3's reason). They are a visible bucket rather than a
    // silent absence — and they carry no drill-down, because spec 7.1 has no
    // filter that spells "no team".
    // The (No area) group's URL key is the EMPTY STRING — it has no area to
    // name. An absent `area` parameter is a different thing entirely and
    // opens nothing, which is why the class distinguishes '' from null.
    $page = cd_page(cd_senior('A'), ['area' => '']);
    $row  = cd_row($page, 'team', '(No team)');

    assertSame(['members' => 1, 'unassigned' => 1, 'no_officer' => 0, 'never_contacted' => 1], cd_figures($row));
    assertTrue((bool) $row['placeholder']);
    assertSame(false, $row['drillable'], 'no team[] can name them, so the figure makes no promise');
    assertSame([], $row['team_ids']);
});

test('a team with no members in scope is not a row', function (): void {
    // Administration-Support CD holds nobody at all. A roll-up is of members;
    // an empty team is a fact about the team table, not a group.
    foreach (['A', 'B', 'C', 'D', 'none'] as $key) {
        $page = cd_page(cd_executive(), ['division' => cd_fixture()['divisions'][$key], 'area' => 'Administration']);

        foreach ($page['rows'] as $row) {
            assertTrue(
                $row['name'] !== 'Administration-Support CD' && $row['name'] !== 'Administration',
                $row['name'] . ' holds nobody and must not be a group'
            );
        }
    }
});

// ---------------------------------------------------------------------------
// The roll-up itself, transcribed
// ---------------------------------------------------------------------------

test('every division figure is the one transcribed from the fixture', function (): void {
    $a = cd_row(cd_page(cd_senior('A')), 'division', 'CD Satellites Division');
    assertSame(['members' => 9, 'unassigned' => 7, 'no_officer' => 4, 'never_contacted' => 7], cd_figures($a));

    $b = cd_row(cd_page(cd_senior('B')), 'division', 'CD Bus Ops Division');
    assertSame(['members' => 3, 'unassigned' => 3, 'no_officer' => 2, 'never_contacted' => 2], cd_figures($b));
});

test('every area figure is the one transcribed, and the placeholder area is one of them', function (): void {
    $page = cd_page(cd_senior('A'));

    assertSame(
        ['members' => 6, 'unassigned' => 4, 'no_officer' => 2, 'never_contacted' => 4],
        cd_figures(cd_row($page, 'area', 'Reed Road'))
    );
    assertSame(
        ['members' => 1, 'unassigned' => 1, 'no_officer' => 1, 'never_contacted' => 1],
        cd_figures(cd_row($page, 'area', '610'))
    );
    // Lifetime CD matches none of the seven prefixes, and the member with no
    // team has no area at all: both land here.
    assertSame(
        ['members' => 2, 'unassigned' => 2, 'no_officer' => 1, 'never_contacted' => 2],
        cd_figures(cd_row($page, 'area', '(No area)'))
    );
});

test('every team figure is the one transcribed, on both sides of a split team', function (): void {
    $f = cd_fixture();

    $a = cd_page(cd_senior('A'), ['area' => 'Reed Road']);
    assertSame(
        ['members' => 4, 'unassigned' => 2, 'no_officer' => 0, 'never_contacted' => 3],
        cd_figures(cd_row($a, 'team', 'Reed Road Parking Team A CD'))
    );
    assertSame(
        ['members' => 2, 'unassigned' => 2, 'no_officer' => 2, 'never_contacted' => 1],
        cd_figures(cd_row($a, 'team', 'Reed Road CD'))
    );

    // The same team under the OTHER division. Seven real teams span two
    // divisions (docs/data-findings.md 4b) and division is a property of the
    // member, so the group is the (division, team) pair and the two rows are
    // different people.
    $b   = cd_page(cd_senior('B'), ['area' => 'Reed Road']);
    $row = cd_row($b, 'team', 'Reed Road Parking Team A CD');
    assertSame(['members' => 1, 'unassigned' => 1, 'no_officer' => 0, 'never_contacted' => 1], cd_figures($row));
    assertSame($f['divisions']['B'], (int) $row['division_id'], 'and it drills into ITS division');
});

test('a parent figure is exactly the sum of its children, at both levels', function (): void {
    // Not a coincidence to be checked, a property to be relied on: the same
    // per-member facts are added into all three tallies in one pass, so a
    // division that did not equal its areas would mean a member had been
    // counted in one place and not another.
    $page   = cd_page(cd_senior('A'));
    $parent = cd_row($page, 'division', 'CD Satellites Division');

    $sum = ['members' => 0, 'unassigned' => 0, 'no_officer' => 0, 'never_contacted' => 0];
    foreach ($page['rows'] as $row) {
        if ($row['level'] !== 'area') {
            continue;
        }
        foreach (cd_figures($row) as $key => $value) {
            $sum[$key] += $value;
        }
    }
    assertSame(cd_figures($parent), $sum, 'the division equals its areas');

    foreach (['Reed Road' => 'Reed Road', '610' => '610', '' => '(No area)'] as $area => $areaName) {
        $areaPage = cd_page(cd_senior('A'), ['area' => (string) $area]);
        $areaRow  = cd_row($areaPage, 'area', $areaName);

        $teams = ['members' => 0, 'unassigned' => 0, 'no_officer' => 0, 'never_contacted' => 0];
        foreach ($areaPage['rows'] as $row) {
            if ($row['level'] !== 'team') {
                continue;
            }
            foreach (cd_figures($row) as $key => $value) {
                $teams[$key] += $value;
            }
        }
        assertSame(cd_figures($areaRow), $teams, "the {$areaName} area equals its teams");
    }
});

test('the four metric bars count the spec 5.4 statuses, never a second copy of the table', function (): void {
    $row = cd_row(cd_page(cd_senior('A')), 'division', 'CD Satellites Division');

    // Transcribed from the fixture: a1 and off are Y on everything; a2 is Y
    // on three; a4 is N and CONTACTED, which is spec 5.4's amber outline and
    // not the red one; everybody else is N and never contacted.
    $hlsr = $row['metrics'][Metric::HlsrDues->value];
    assertSame(2, (int) $hlsr['complete'], 'a1 and off');
    assertSame(7, (int) $hlsr['outstanding'], 'everything that is not Complete is outstanding');
    assertSame(2, (int) $hlsr['statuses'][MetricStatus::Complete->value]);
    assertSame(1, (int) $hlsr['statuses'][MetricStatus::Contacted->value], 'a4 — reached, nothing promised');
    assertSame(6, (int) $hlsr['statuses'][MetricStatus::Outstanding->value]);
    assertSame(0, (int) $hlsr['statuses'][MetricStatus::NotReported->value]);

    $committee = $row['metrics'][Metric::CommitteeDues->value];
    assertSame(3, (int) $committee['complete'], 'a1, a2 and off');
    assertSame(6, (int) $committee['outstanding']);
    assertSame(5, (int) $committee['statuses'][MetricStatus::Outstanding->value]);

    // The four scored metrics, and only those: harassment training is
    // imported and displayed, never scored (spec 5.4, OI-3).
    assertSame(
        ['hlsr_dues', 'committee_dues', 'indemnity', 'background_check'],
        array_keys($row['metrics'])
    );

    // Every status adds up to the group's members, always.
    foreach ($row['metrics'] as $metric => $card) {
        assertSame(9, array_sum($card['statuses']), "{$metric} accounts for every member");
    }
});

// ---------------------------------------------------------------------------
// The promise: every figure equals the list filtered to it
// ---------------------------------------------------------------------------

/**
 * The spec 7.1 input the drill-down link carries for one row — the same
 * parameters app/views/committee.php builds, so this proves what the link
 * actually does rather than what it was meant to.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cd_drill(array $row, array $extra = []): array
{
    $input = ['mode' => 'team', 'division' => (int) $row['division_id']];

    if ($row['level'] !== 'division') {
        $input['team'] = $row['team_ids'];
    }

    return $input + $extra;
}

test('every figure on every group equals what spec 7.1 shows when filtered to it', function (): void {
    $user = cd_executive();
    $rows = [];

    // Every group this fixture makes: the three divisions, every area of each
    // and every team of every area.
    foreach (['A', 'B', 'none'] as $key) {
        $division = cd_fixture()['divisions'][$key];
        $page     = cd_page($user, ['division' => $division]);

        foreach ($page['rows'] as $row) {
            if ($row['level'] === 'division' && (int) $row['division_id'] !== $division) {
                continue;
            }
            if ($row['level'] === 'area') {
                foreach (cd_page($user, ['division' => $division, 'area' => $row['key']])['rows'] as $inner) {
                    if ($inner['level'] === 'team') {
                        $rows[] = $inner;
                    }
                }
            }
            if ($row['level'] !== 'team') {
                $rows[] = $row;
            }
        }
    }

    assertTrue(count($rows) >= 12, 'the whole fixture tree is under test, got ' . count($rows));

    $status = StatusPage::fromApp($GLOBALS['rerm_app']);
    $year   = cd_fixture()['year'];

    foreach ($rows as $row) {
        if (!$row['drillable']) {
            continue;
        }

        $label = $row['level'] . ' ' . $row['name'];

        // Members: the group, everybody in it.
        $page = $status->page($user, $year, cd_drill($row, ['show' => 'all']));
        assertSame((int) $row['members'], (int) $page['dashboard']['total'], "{$label}: members");
        assertSame((int) $row['members'], (int) $page['total'], "{$label}: the list shows them all");

        // Never contacted — one of the two figures this phase gave spec 7.1
        // a filter for, and the screen's default sort.
        $page = $status->page($user, $year, cd_drill($row, ['show' => 'all', 'contact' => 'never']));
        assertSame((int) $row['never_contacted'], (int) $page['total'], "{$label}: never contacted");

        // Unassigned — the other.
        $page = $status->page($user, $year, cd_drill($row, ['show' => 'all', 'assigned' => 'none']));
        assertSame((int) $row['unassigned'], (int) $page['total'], "{$label}: unassigned");
    }
});

test('the two new filters return exactly the members the figure counted, by name', function (): void {
    // Counts agreeing is not the whole promise: the officer has to land on
    // THOSE people. Transcribed from the fixture, member by member.
    $f      = cd_fixture();
    $user   = cd_executive();
    $status = StatusPage::fromApp($GLOBALS['rerm_app']);

    $numbers = static function (array $page): array {
        $seen = [];
        foreach ($page['rows'] as $row) {
            $seen[] = (string) $row['member_number'];
        }
        sort($seen);

        return $seen;
    };

    $expect = static function (array $keys) use ($f): array {
        $numbers = [];
        foreach ($keys as $key) {
            $numbers[] = $f['members'][$key]['number'];
        }
        sort($numbers);

        return $numbers;
    };

    $division = ['mode' => 'team', 'division' => $f['divisions']['A'], 'show' => 'all'];

    assertSame(
        $expect(['a2', 'a3', 'off', 'a5', 'a6', 'a7', 'a8']),
        $numbers($status->page($user, $f['year'], $division + ['contact' => 'never'])),
        'never contacted in CD Satellites Division — a1 and a4 were reached, nobody else was'
    );

    assertSame(
        $expect(['a3', 'off', 'a4', 'a5', 'a6', 'a7', 'a8']),
        $numbers($status->page($user, $f['year'], $division + ['assigned' => 'none'])),
        'unassigned in CD Satellites Division — a1 and a2 are held by off, nobody else is'
    );

    // And on one team, which is where an officer actually lands.
    $team = ['mode' => 'team', 'division' => $f['divisions']['A'],
        'team' => [$f['teams']['rr_park']], 'show' => 'all'];

    assertSame(
        $expect(['a2', 'a3', 'off']),
        $numbers($status->page($user, $f['year'], $team + ['contact' => 'never'])),
        'never contacted on Reed Road Parking Team A'
    );
    assertSame(
        $expect(['a3', 'off']),
        $numbers($status->page($user, $f['year'], $team + ['assigned' => 'none'])),
        'unassigned on Reed Road Parking Team A'
    );
});

test('a drill-down filter can only ever narrow a scope, never widen it', function (): void {
    // The filters are applied for every level because each is ANDed onto
    // ScopedQuery's own predicate. A crafted URL naming another division must
    // therefore return nothing, not somebody else's roster.
    $f      = cd_fixture();
    $status = StatusPage::fromApp($GLOBALS['rerm_app']);

    $page = $status->page(
        cd_senior('A'),
        $f['year'],
        ['mode' => 'team', 'division' => $f['divisions']['B'], 'show' => 'all']
    );
    assertSame(0, (int) $page['dashboard']['total'], 'a division they are not scoped to yields nobody');

    $page = $status->page(
        cd_senior('A'),
        $f['year'],
        ['mode' => 'team', 'team' => [$f['teams']['emlr']], 'show' => 'all']
    );
    assertSame(0, (int) $page['dashboard']['total'], 'nor does a team outside their division');
});

test('the filters narrow the dashboard cards as well as the list', function (): void {
    // Half a filter is worse than none: cards that described the whole scope
    // beside a list that described a team would be two answers on one screen.
    $f      = cd_fixture();
    $page   = StatusPage::fromApp($GLOBALS['rerm_app'])->page(
        cd_executive(),
        $f['year'],
        ['mode' => 'team', 'division' => $f['divisions']['A'],
            'team' => [$f['teams']['rr_park']], 'show' => 'all']
    );

    assertSame(4, (int) $page['dashboard']['total'], 'the four members of that team');
    assertSame(2, (int) $page['dashboard']['cards']['hlsr_dues']['complete'], 'a1 and off');
    assertSame(2, (int) $page['dashboard']['fully_complete']);

    // And it says what it was narrowed to, or the officer reads a roster that
    // is quietly missing people.
    assertTrue((bool) $page['filters']['active']);
    assertSame('CD Satellites Division', $page['filters']['division_name']);
    assertSame(
        [$f['teams']['rr_park'] => 'Reed Road Parking Team A CD'],
        $page['filters']['team_names']
    );
});

test('a filter names a group only when the user can see somebody in it', function (): void {
    // A crafted ?division= naming somebody else\'s division returns an empty
    // roster either way. It must not also return that division\'s NAME:
    // this application does not discuss what exists with people who cannot
    // see it, and the banner is a place that could have.
    $f      = cd_fixture();
    $status = StatusPage::fromApp($GLOBALS['rerm_app']);

    $own = $status->page(cd_senior('A'), $f['year'], [
        'mode' => 'team', 'division' => $f['divisions']['A'],
        'team' => [$f['teams']['rr_park']], 'show' => 'all',
    ]);
    assertSame('CD Satellites Division', $own['filters']['division_name'], 'their own division is named');
    assertSame(
        [$f['teams']['rr_park'] => 'Reed Road Parking Team A CD'],
        $own['filters']['team_names']
    );

    $other = $status->page(cd_senior('A'), $f['year'], [
        'mode' => 'team', 'division' => $f['divisions']['B'],
        'team' => [$f['teams']['emlr']], 'show' => 'all',
    ]);
    assertSame(0, (int) $other['dashboard']['total'], 'and they see nobody in it');
    assertSame('', $other['filters']['division_name'], 'nor what it is called');
    assertSame([], $other['filters']['team_names']);
    assertTrue((bool) $other['filters']['active'], 'the screen still says it is filtered');
});

test('the toggle default is decided before the filters, not after', function (): void {
    // has_assignments answers "does this officer hold anybody at all". If the
    // filters narrowed it, an officer drilling into a team where they hold
    // nobody would silently change their own default mode.
    $f    = cd_fixture();
    $off  = new User(
        $f['user'],
        $f['members']['off']['id'],
        $f['members']['off']['number'],
        Level::Officer,
        $f['divisions']['A'],
        $f['teams']['rr_park'],
        false,
        'Fixture Officer'
    );

    $page = StatusPage::fromApp($GLOBALS['rerm_app'])->page($off, $f['year'], []);
    assertTrue((bool) $page['has_assignments'], 'off holds a1 and a2');
    assertSame('mine', $page['mode'], 'and so defaults to My members');

    // The same officer, drilled into a team on which they hold nobody.
    $page = StatusPage::fromApp($GLOBALS['rerm_app'])->page($off, $f['year'], [
        'mode' => 'team', 'division' => $f['divisions']['A'], 'team' => [$f['teams']['rr_bare']],
    ]);
    assertTrue((bool) $page['has_assignments'], 'they still hold two people');
    assertSame('team', $page['mode'], 'and the explicit mode=team is what the link carried');
});

// ---------------------------------------------------------------------------
// Coverage: the same numbers the Assign screen shows
// ---------------------------------------------------------------------------

test('unassigned and no-officer are the Assign screen\'s numbers, not a second opinion', function (): void {
    // The shared thing is the PREDICATE — EligibleOfficers::memberHasAssignment()
    // and ::countsByTeam() — rather than an aggregate, which is why
    // AssignPage::teamsInScope() stayed private. This is what makes that
    // choice safe rather than merely defensible.
    $f      = cd_fixture();
    $senior = cd_senior('A');

    $assign = AssignPage::fromApp($GLOBALS['rerm_app']);
    $page   = cd_page($senior, ['area' => 'Reed Road']);

    foreach (['rr_park' => 'Reed Road Parking Team A CD', 'rr_bare' => 'Reed Road CD'] as $key => $name) {
        $row     = cd_row($page, 'team', $name);
        $counts  = $assign->page($senior, $f['year'], ['team' => $f['teams'][$key]])['counts'];

        assertSame((int) $counts['unassigned'], (int) $row['unassigned'], "{$name}: unassigned");
        assertSame((int) $counts['total'], (int) $row['members'], "{$name}: members");
    }

    // Bucket 3, across the whole scope: the teams that cannot cover their own
    // members, and how many members are on them.
    $bucketThree = $assign->page($senior, $f['year'], []);
    $division    = cd_row(cd_page($senior), 'division', 'CD Satellites Division');

    assertSame((int) $bucketThree['thin_members'], (int) $division['no_officer'],
        'the division total is bucket 3 counted the same way');
    assertSame(1, (int) $bucketThree['no_team_members'], 'and a8 is unassignable for a different reason');
});

// ---------------------------------------------------------------------------
// Sorting
// ---------------------------------------------------------------------------

/**
 * The fixture's three divisions in the order one sort puts them, ignoring
 * every other suite's rows — the Executive scope is shared, the fixture's
 * relative order is not.
 *
 * @return array<int, string>
 */
function cd_division_order(string $sort, string $dir): array
{
    $order = [];
    foreach (cd_page(cd_executive(), ['sort' => $sort, 'dir' => $dir])['rows'] as $row) {
        if ($row['level'] === 'division' && str_starts_with($row['name'], 'CD ')) {
            $order[] = $row['name'];
        }
    }

    return $order;
}

test('every column sorts, in both directions', function (): void {
    // Transcribed from the fixture, division by division:
    //
    //                       members  unassigned  no officer  never  hlsr out
    //   CD Satellites             9           7           4      7         7
    //   CD Bus Ops                3           3           2      2         2
    //   CD (No Division)          2           2           2      1         2
    //   CD Logistics              1           1           1      1         1
    //   CD Member Services        1           1           1      0         1
    //
    // Ties break on the NAME ascending in both directions, so a screen of
    // equal figures — which at 50-65% outstanding is the ordinary case for
    // the metric columns — still reads alphabetically instead of in whatever
    // order the rows happened to accumulate.
    $a = 'CD Satellites Division';
    $b = 'CD Bus Ops Division';
    $c = 'CD Logistics Division';
    $d = 'CD Member Services Division';
    $n = 'CD (No Division)';

    assertSame([$a, $b, $n, $c, $d], cd_division_order('contact', 'desc'), 'never contacted, the default');
    assertSame([$d, $n, $c, $b, $a], cd_division_order('contact', 'asc'));

    assertSame([$a, $b, $n, $c, $d], cd_division_order('members', 'desc'));
    assertSame([$c, $d, $n, $b, $a], cd_division_order('members', 'asc'));

    assertSame([$a, $b, $n, $c, $d], cd_division_order('unassigned', 'desc'));
    assertSame([$c, $d, $n, $b, $a], cd_division_order('unassigned', 'asc'));

    assertSame([$a, $n, $b, $c, $d], cd_division_order('no_officer', 'desc'));
    assertSame([$c, $d, $n, $b, $a], cd_division_order('no_officer', 'asc'));

    assertSame([$n, $b, $c, $d, $a], cd_division_order('name', 'asc'));
    assertSame([$a, $d, $c, $b, $n], cd_division_order('name', 'desc'));

    // Each metric sorts by its OUTSTANDING count — a count of people, like
    // every other sortable figure on the row, rather than a rate.
    assertSame([$a, $n, $b, $c, $d], cd_division_order('hlsr_dues', 'desc'));
    assertSame([$c, $d, $n, $b, $a], cd_division_order('hlsr_dues', 'asc'));
    foreach (['committee_dues', 'indemnity', 'background_check'] as $metric) {
        assertSame([$a, $n, $b, $c, $d], cd_division_order($metric, 'desc'), $metric);
    }
});

test('an unknown sort or direction is the default, never an error and never the input', function (): void {
    $page = cd_page(cd_senior('A'), ['sort' => 'm.last_name; DROP TABLE member', 'dir' => 'sideways']);

    assertSame(CommitteePage::DEFAULT_SORT, $page['sort']);
    assertSame(CommitteePage::DEFAULT_DIR, $page['dir']);
    assertTrue($page['rows'] !== [], 'and the screen still renders');
});

test('teams sort within their area, and areas within their division', function (): void {
    // The sort applies at every level rather than only the top one — a
    // dashboard that ordered divisions by triage and then listed teams
    // alphabetically would bury the worst team inside the worst division.
    $page  = cd_page(cd_senior('A'), ['area' => 'Reed Road', 'sort' => 'contact', 'dir' => 'desc']);
    $teams = [];
    foreach ($page['rows'] as $row) {
        if ($row['level'] === 'team') {
            $teams[] = $row['name'];
        }
    }
    // never contacted: rr_park 3, rr_bare 1
    assertSame(['Reed Road Parking Team A CD', 'Reed Road CD'], $teams);

    $areas = [];
    foreach ($page['rows'] as $row) {
        if ($row['level'] === 'area') {
            $areas[] = $row['name'];
        }
    }
    // never contacted: Reed Road 4, (No area) 2, 610 1
    assertSame(['Reed Road', '(No area)', '610'], $areas);
});

// ---------------------------------------------------------------------------
// One level at a time — the property the byte budget rests on
// ---------------------------------------------------------------------------

test('a closed group contributes no child rows, whatever else is open', function (): void {
    // <details> would collapse the pixels and ship the bytes anyway. The page
    // is every division, ONE division's areas and ONE area's teams, and
    // nothing else — which is what keeps a 96-team roll-up inside the spec 10
    // first-paint budget.
    $f    = cd_fixture();
    $page = cd_page(cd_executive(), ['division' => $f['divisions']['A'], 'area' => 'Reed Road']);

    $areas = [];
    $teams = [];
    foreach ($page['rows'] as $row) {
        if ($row['level'] === 'area') {
            $areas[] = (int) $row['division_id'];
        }
        if ($row['level'] === 'team') {
            $teams[] = $row['name'];
        }
    }

    foreach ($areas as $divisionId) {
        assertSame($f['divisions']['A'], $divisionId, 'only the open division lists its areas');
    }
    assertSame(['Reed Road Parking Team A CD', 'Reed Road CD'], $teams, 'only the open area lists its teams');

    assertSame('Reed Road', $page['open_area']);
    assertSame($f['divisions']['A'], $page['open_division']);
});

test('an area name that is a number survives the round trip', function (): void {
    // PHP turns a numeric-string array key into an int, and `610` is one of
    // the seven real area names. An area that could not be reopened from its
    // own link would be a group nobody could drill into.
    $f    = cd_fixture();
    $page = cd_page(cd_executive(), ['division' => $f['divisions']['A'], 'area' => '610']);

    assertSame('610', $page['open_area']);
    assertSame('610', cd_row($page, 'area', '610')['key']);

    $teams = [];
    foreach ($page['rows'] as $row) {
        if ($row['level'] === 'team') {
            $teams[] = $row['name'];
        }
    }
    assertSame(['610 Parking Team J CD'], $teams);
});

test('an out-of-scope division or a made-up area simply does not open', function (): void {
    $f = cd_fixture();

    // Chosen FROM the scoped list rather than validated against it: an id
    // that is not in scope is not one of the choices.
    $page = cd_page(cd_senior('A'), ['division' => $f['divisions']['B']]);
    assertSame($f['divisions']['A'], $page['open_division'], 'their own division, which is the only one');

    $page = cd_page(cd_senior('A'), ['area' => 'Reed Road Somewhere Else']);
    assertSame(null, $page['open_area'], 'an area nobody has is nobody\'s area');
});

test('an empty scope renders an empty roll-up, never an error', function (): void {
    $f    = cd_fixture();
    $page = cd_page(new User(
        $f['user'],
        $f['members']['off']['id'],
        $f['members']['off']['number'],
        Level::SeniorOfficer,
        null,
        null,
        false,
        'Scopeless Senior'
    ));

    assertSame([], $page['rows']);
    assertSame(0, (int) $page['total']);
    assertSame(0, (int) $page['divisions']);
    assertSame(null, $page['open_division']);
});

// ---------------------------------------------------------------------------
// The rendered screen — the links are the promise, so the links are tested
// ---------------------------------------------------------------------------

/**
 * The view, rendered through the same two requires public/index.php uses.
 *
 * @param array<string, mixed> $committee
 */
function cd_render(array $committee): string
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    $_SESSION ??= [];
    $user      = cd_executive();
    $year      = ['id' => cd_fixture()['year'], 'label' => '2027', 'is_open' => true];
    $wide      = true;
    $title     = 'Committee Dashboard';

    ob_start();
    require $app->path('app/views/committee.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

/** @return array<int, string> every href in the rendered page, unescaped. */
function cd_links(string $html): array
{
    preg_match_all('/href="([^"]*)"/', $html, $matches);

    // Both layers come off: html entities because the attribute was escaped
    // with e(), and percent-encoding because http_build_query wrote team[0].
    return array_map(
        static fn (string $href): string => urldecode(html_entity_decode($href, ENT_QUOTES)),
        $matches[1]
    );
}

test('every drill-down link carries mode=team, in the URL where it is visible', function (): void {
    // Spec 7.3 decided 4, and the trap Phase 6 created: spec 7.1 defaults to
    // My members the moment an officer holds an assignment, so a Senior
    // Officer drilling into "40 never contacted" would otherwise land on the
    // three of them assigned to them personally.
    $f    = cd_fixture();
    $html = cd_render(cd_page(cd_executive(), ['division' => $f['divisions']['A'], 'area' => 'Reed Road']));

    $drills = 0;
    foreach (cd_links($html) as $href) {
        if (!str_contains($href, 'dashboard?')) {
            continue;
        }
        $drills++;
        assertTrue(str_contains($href, 'mode=team'), "a drill-down without mode=team: {$href}");
        assertTrue(str_contains($href, 'division='), "a drill-down without its division: {$href}");
    }

    assertTrue($drills >= 12, 'every group row drills down, got ' . $drills);
});

test('a figure links only where spec 7.1 can reproduce it', function (): void {
    $f    = cd_fixture();
    $html = cd_render(cd_page(cd_executive(), ['division' => $f['divisions']['A'], 'area' => 'Reed Road']));
    $team = $f['teams']['rr_park'];

    $links = cd_links($html);
    $has   = static function (array $links, string $needle): bool {
        foreach ($links as $href) {
            if (str_contains($href, $needle)) {
                return true;
            }
        }

        return false;
    };

    assertTrue($has($links, "team[0]={$team}&show=all&contact=never"), 'never contacted drills to contact=never');
    assertTrue($has($links, "team[0]={$team}&show=all&assigned=none"), 'unassigned drills to assigned=none');

    // show=all on both, because spec 7.1's list defaults to outstanding-only
    // and a fully complete member who has never been contacted would be
    // counted by the figure and missing from the list it landed on.
    foreach ($links as $href) {
        if (str_contains($href, 'contact=never') || str_contains($href, 'assigned=none')) {
            assertTrue(str_contains($href, 'show=all'), "a triage drill-down that hides members: {$href}");
        }
    }

    // No officer on this team is NOT a link: spec 7.1 has no filter for it,
    // and a fourth filter spelling is not this phase's to invent.
    assertSame(0, preg_match('/officer[^<]*<a /i', $html), 'the no-officer figure makes no promise it cannot keep');
    foreach ($links as $href) {
        assertSame(0, preg_match('/\bofficer=/', $href), 'no filter spelling this screen invented');
    }
});

/**
 * My Roster Status, rendered — the far end of every drill-down link.
 *
 * @param array<string, mixed> $input
 */
function cd_render_dashboard(array $input): string
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    $_SESSION ??= [];
    $user       = cd_executive();
    $year       = ['id' => cd_fixture()['year'], 'label' => '2027', 'is_open' => true];
    $wide       = true;
    $title      = 'My Roster Status';
    $notices    = [];
    $statusPage = StatusPage::fromApp($app)->page($user, cd_fixture()['year'], $input);

    ob_start();
    require $app->path('app/views/dashboard.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

test('a filtered My Roster Status says so, and every link on it keeps the filter', function (): void {
    // Losing a filter is losing the forty people the officer came to work.
    // Turning a page, flipping the toggle or logging a contact must all land
    // back on the same group.
    $f    = cd_fixture();
    $html = cd_render_dashboard([
        'mode'    => 'team',
        'division' => $f['divisions']['A'],
        'team'    => [$f['teams']['rr_park']],
        'show'    => 'all',
        'contact' => 'never',
        'log'     => $f['members']['a3']['id'],
    ]);

    assertTrue(str_contains($html, 'Filtered'), 'the screen says it is showing a group');
    assertTrue(str_contains($html, 'CD Satellites Division'), 'and names the division');
    assertTrue(str_contains($html, 'Reed Road Parking Team A CD'), 'and the team');
    assertTrue(str_contains($html, 'never contacted'), 'and which triage filter');

    $kept    = 0;
    $cleared = 0;
    foreach (cd_links($html) as $href) {
        if (!str_contains($href, 'dashboard?') && !str_contains($href, 'dashboard&')) {
            continue;
        }
        // The one link that deliberately drops everything is the way out.
        if (!str_contains($href, 'division=')) {
            $cleared++;
            continue;
        }
        $kept++;
        assertTrue(str_contains($href, 'team[0]=' . $f['teams']['rr_park']), "a link that lost the team: {$href}");
        assertTrue(str_contains($href, 'contact=never'), "a link that lost the filter: {$href}");
    }

    assertTrue($kept > 0, 'there are links to keep the filter on');
    assertSame(1, $cleared, 'exactly one way out: Show my whole roster');

    // And the log-contact POST carries the same state, so its 303 comes back
    // here rather than to the whole roster.
    assertSame(1, preg_match('/name="return" value="([^"]*)"/', $html, $m), 'the sheet carries its return state');
    $state = urldecode(html_entity_decode($m[1], ENT_QUOTES));
    foreach (['mode=team', 'show=all', 'division=', 'team[0]=', 'contact=never'] as $needle) {
        assertTrue(str_contains($state, $needle), "the return state lost {$needle}: {$state}");
    }
});

test('the return whitelist names every filter that has to travel', function (): void {
    // Transcribed a second time, in the direction that matters: a filter the
    // whitelist does not name is silently dropped from the redirect, and the
    // officer lands somewhere they did not ask for with no error at all.
    $source = (string) file_get_contents(__DIR__ . '/../public/index.php');
    assertTrue($source !== '', 'public/index.php is readable');

    assertSame(1, preg_match(
        '/function dashboard_return_query\(array \$input\): string\s*\{(.*?)\n\}/s',
        $source,
        $matches
    ), 'the dashboard return whitelist is where it was');

    foreach ([
        "'mode'", "'show'", "'division'", "'team'", "'contact'", "'assigned'", "'page'", "'size'",
    ] as $key) {
        assertTrue(str_contains($matches[1], $key), "the whitelist does not carry {$key}");
    }

    // team[] is a LIST of ints, which needed one new rule shape rather than
    // one new helper.
    assertTrue(str_contains($matches[1], "'ints' => 0"), 'team[] travels as a list of ints');
});

test('a filtered screen that matches nobody says so, and is not an empty roster', function (): void {
    // The two empty states are different facts: "your scope is empty" is an
    // account problem, "nobody matches this filter" is a figure that has been
    // worked since the dashboard drew it.
    $f    = cd_fixture();
    $html = cd_render_dashboard([
        'mode'     => 'team',
        'division' => $f['divisions']['A'],
        'team'     => [$f['teams']['rr_park']],
        'show'     => 'all',
        'contact'  => 'never',
        'assigned' => 'none',
    ]);
    // a3 and off are never contacted AND unassigned on that team, so this one
    // is not empty — the assertion below is that the wording tracks the case.
    assertTrue(!str_contains($html, 'Nobody matches this filter'), 'three members match');

    // Administration-Support CD holds nobody at all.
    $html = cd_render_dashboard([
        'mode'     => 'team',
        'division' => $f['divisions']['A'],
        'team'     => [$f['teams']['admin']],
        'show'     => 'all',
    ]);

    assertTrue(str_contains($html, 'Nobody matches this filter'), 'a team with nobody on it');
    assertTrue(!str_contains($html, 'Your roster is empty'), 'their scope is not the problem');
});

test('the rendered page escapes every group name and stays inside the byte budget', function (): void {
    $f    = cd_fixture();
    $html = cd_render(cd_page(cd_executive(), ['division' => $f['divisions']['A'], 'area' => '']));

    assertTrue(str_contains($html, 'Lifetime CD'), 'the team that matched no area is on the page');
    assertTrue(str_contains($html, '(No area)'), 'and so is the group it landed in');
    assertTrue(str_contains($html, '(No team)'), 'and the members with no team at all');

    // Spec 10: under 100KB on first paint. This fixture is small; the shape
    // that decides the budget is measured in the commit message. What is
    // asserted here is that the shell plus a full expansion has not somehow
    // become enormous.
    assertTrue(strlen($html) < 100 * 1024, 'first paint is ' . strlen($html) . ' bytes');
});
