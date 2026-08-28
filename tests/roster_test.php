<?php

declare(strict_types=1);

/**
 * View My Roster (spec 7.2): the 5.4 effective-status function proven over
 * all 18 combinations, and the whole read path — scope, search with its
 * 3-character floor, LIKE-wildcard escaping, the Senior-only team filter,
 * whitelisted sort, pagination arithmetic, the batched contact and
 * assignment reads, and the sms:/mailto: suppression rules — through
 * RosterPage, the same class the route serves, against a GENERATED fixture
 * at the real roster's 1,954-row shape.
 *
 * Generated, never real: this repository is public. Member numbers here are
 * 'RT000001', phones are the reserved (555) 555-01xx fiction range, and
 * addresses are @example.com.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
use Rerm\Roster\RosterPage;
use Rerm\Routes;

// ---------------------------------------------------------------------------
// The 5.4 function — pure, so the proof is an enumeration
// ---------------------------------------------------------------------------

test('the effective-status table is exact for all 18 combinations', function (): void {
    // Spec 5.4, transcribed a second time so a change to the function has to
    // be made twice on purpose. 3 imported values x 3 progress states x
    // contacted-or-not = 18 rows, none derived, every one written out.
    $expected = [
        // imported, progress, contacted            => status
        ['Y', 'not_started', false,      MetricStatus::Complete],
        ['Y', 'not_started', true,       MetricStatus::Complete],
        ['Y', 'in_progress', false,      MetricStatus::Complete],
        ['Y', 'in_progress', true,       MetricStatus::Complete],
        ['Y', 'claimed_complete', false, MetricStatus::Complete],
        ['Y', 'claimed_complete', true,  MetricStatus::Complete],

        ['N', 'claimed_complete', false, MetricStatus::Reported],
        ['N', 'claimed_complete', true,  MetricStatus::Reported],
        ['N', 'in_progress', false,      MetricStatus::InProgress],
        ['N', 'in_progress', true,       MetricStatus::InProgress],
        ['N', 'not_started', true,       MetricStatus::Contacted],
        ['N', 'not_started', false,      MetricStatus::Outstanding],

        ['unknown', 'not_started', false,      MetricStatus::NotReported],
        ['unknown', 'not_started', true,       MetricStatus::NotReported],
        ['unknown', 'in_progress', false,      MetricStatus::NotReported],
        ['unknown', 'in_progress', true,       MetricStatus::NotReported],
        ['unknown', 'claimed_complete', false, MetricStatus::NotReported],
        ['unknown', 'claimed_complete', true,  MetricStatus::NotReported],
    ];

    assertSame(18, count($expected), 'the full table, no row skipped');

    foreach ($expected as [$imported, $progress, $contacted, $status]) {
        assertSame(
            $status,
            MetricStatus::derive($imported, $progress, $contacted),
            "imported={$imported} progress={$progress} contacted=" . var_export($contacted, true)
        );
    }
});

test('every status carries its word and a colour class — never a hue alone', function (): void {
    $labels = [];
    foreach (MetricStatus::cases() as $status) {
        assertTrue($status->label() !== '', $status->value . ' has a word');
        assertTrue($status->chipClass() !== '', $status->value . ' has a colour class');
        $labels[] = $status->label();
    }

    sort($labels);
    assertSame(
        ['Complete', 'Contacted', 'Member Handling', 'Not reported', 'Open/No Contact', 'Reported Complete'],
        $labels,
        'the six words of spec 8.3 as renamed by the owner at Phase 4 close (Phase 5 decided 5), each spelled once'
    );

    // The two amber states stay tellable apart: Member Handling is the
    // filled chip, Contacted the outline.
    assertTrue(str_contains(MetricStatus::InProgress->chipClass(), 'chip-fill'));
    assertTrue(!str_contains(MetricStatus::Contacted->chipClass(), 'chip-fill'));
});

test('the four scored metrics are exactly four, and harassment training is not among them', function (): void {
    $scored = array_map(static fn (Metric $m): string => $m->value, Metric::scored());

    assertSame(['hlsr_dues', 'committee_dues', 'indemnity', 'background_check'], $scored);
    assertTrue(!in_array(Metric::HarassmentTraining->value, $scored, true),
        'shown, tri-state, never scored (OI-3)');
});

test('display name prefers preferred, falls back to first, and never renders blank', function (): void {
    assertSame('Bobby Smith', RosterPage::displayName('Bobby', 'Robert', 'Smith', 'RT1'));
    assertSame('Robert Smith', RosterPage::displayName('', 'Robert', 'Smith', 'RT1'));
    assertSame('Smith', RosterPage::displayName('', '', 'Smith', 'RT1'));
    assertSame('Robert', RosterPage::displayName('', 'Robert', '', 'RT1'));
    assertSame('RT1', RosterPage::displayName('', '', '', 'RT1'), 'the member number, never a blank');
    assertSame('RT1', RosterPage::displayName('  ', ' ', '  ', 'RT1'), 'whitespace is blank');
});

test('LIKE wildcards typed by a user become literals', function (): void {
    assertSame('100\\%', RosterPage::escapeLike('100%'));
    assertSame('\\_\\_\\_', RosterPage::escapeLike('___'));
    assertSame('a\\\\b', RosterPage::escapeLike('a\\b'), 'the escape character itself is escaped first');
    assertSame('\\%\\%\\%', RosterPage::escapeLike('%%%'));
    assertSame('plain', RosterPage::escapeLike('plain'));
});

test('the roster route is guarded by view_roster', function (): void {
    assertSame(Capability::ViewRoster->value, Routes::guard('roster'));
});

// ---------------------------------------------------------------------------
// The database under test — the same accessor pattern as scoped_query_test
// ---------------------------------------------------------------------------

function rt_pdo(): PDO
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
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_metric'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/**
 * The 1,954-row fixture, built once: the scoped_query_test shape (96 teams,
 * four real divisions plus the placeholder, spanning teams 1–7, one purged,
 * one flagged absent) plus what THIS screen needs on top — phones, emails,
 * metric rows, contact history and an assignment, each hung on a named
 * member so the assertions read as sentences.
 *
 * @return array<string, mixed>
 */
function rt_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = rt_pdo();
    rt_teardown($pdo);

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();
    assertTrue($year > 0, 'the seeded active show year exists');

    $divisions = ['real' => []];
    foreach ($pdo->query('SELECT id, is_placeholder FROM division ORDER BY id')->fetchAll() as $row) {
        if ((int) $row['is_placeholder'] === 1) {
            $divisions['placeholder'] = (int) $row['id'];
        } else {
            $divisions['real'][] = (int) $row['id'];
        }
    }
    $real        = $divisions['real'];
    $placeholder = $divisions['placeholder'];
    assertSame(4, count($real), 'the four seeded divisions');

    $pdo->prepare(
        'INSERT INTO import_batch (show_year_id, mode, filename, sha256, dry_run) '
        . "VALUES (:year, 'complete', 'rt-fixture', :sha, 0)"
    )->execute([':year' => $year, ':sha' => str_repeat('1', 64)]);
    $batchId = (int) $pdo->lastInsertId();

    $teams      = [];
    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)');
    for ($t = 1; $t <= 96; $t++) {
        $insertTeam->execute([
            ':name'     => sprintf('RT Team %02d', $t),
            ':division' => $real[($t - 1) % 4],
        ]);
        $teams[$t] = (int) $pdo->lastInsertId();
    }

    // 1,954 rows in the real distribution; $visible tracks only the ones a
    // roster may show, and is what every expected answer is computed from.
    $rows    = [];
    $visible = [];

    $add = function (?int $teamId, int $divisionId, ?string $purgedAt, ?int $absentBatch) use (
        &$rows,
        &$visible
    ): void {
        $n      = count($rows) + 1;
        $number = sprintf('RT%06d', $n);
        $rows[] = [
            'member_number'  => $number,
            'first_name'     => 'Member',
            'last_name'      => sprintf('Rt%06d', $n),
            'preferred_name' => '',
            'division_id'    => $divisionId,
            'team_id'        => $teamId,
            'purged_at'      => $purgedAt,
            'absent_since'   => $absentBatch,
            'phone'          => '(555) 555-0100',
            'phone_e164'     => '+15555550100',
            'phone_type'     => 'CELL PHONE',
            'email'          => strtolower($number) . '@example.com',
        ];

        if ($purgedAt === null && $absentBatch === null) {
            $visible[] = ['number' => $number, 'division' => $divisionId, 'team' => $teamId];
        }
    };

    // 72 with a blank Subcommittee 3: 57 honorary with no team, 15 ordinary
    // members on real teams (docs/data-findings.md 4).
    for ($i = 0; $i < 57; $i++) {
        $add(null, $placeholder, null, null);
    }
    for ($i = 0; $i < 15; $i++) {
        $add($teams[($i % 96) + 1], $placeholder, null, null);
    }

    // One purged and one flagged absent, both on team 2.
    $add($teams[2], $real[1], gmdate('Y-m-d H:i:s'), null);
    $add($teams[2], $real[1], null, $batchId);

    // The remaining 1,880 round-robin over the 96 teams; teams 1–7 span two
    // divisions, because division is a property of the member.
    for ($i = 0; $i < 1880; $i++) {
        $t        = ($i % 96) + 1;
        $division = $real[($t - 1) % 4];
        if ($t <= 7 && $i % 5 === 0) {
            $division = $real[$t % 4];
        }
        $add($teams[$t], $division, null, null);
    }

    assertSame(1954, count($rows), 'the shape under test is the real roster\'s');

    foreach (array_chunk($rows, 250) as $chunk) {
        $places = [];
        $bind   = [];
        foreach ($chunk as $i => $row) {
            $places[] = "(:n{$i}, :f{$i}, :l{$i}, :pn{$i}, :d{$i}, :t{$i}, :p{$i}, :a{$i}, :ph{$i}, :pe{$i}, :pt{$i}, :e{$i})";
            $bind[":n{$i}"]  = $row['member_number'];
            $bind[":f{$i}"]  = $row['first_name'];
            $bind[":l{$i}"]  = $row['last_name'];
            $bind[":pn{$i}"] = $row['preferred_name'];
            $bind[":d{$i}"]  = $row['division_id'];
            $bind[":t{$i}"]  = $row['team_id'];
            $bind[":p{$i}"]  = $row['purged_at'];
            $bind[":a{$i}"]  = $row['absent_since'];
            $bind[":ph{$i}"] = $row['phone'];
            $bind[":pe{$i}"] = $row['phone_e164'];
            $bind[":pt{$i}"] = $row['phone_type'];
            $bind[":e{$i}"]  = $row['email'];
        }

        $pdo->prepare(
            'INSERT INTO member (member_number, first_name, last_name, preferred_name, division_id, team_id, '
            . 'purged_at, dropped_since_import_id, phone, phone_e164, phone_type, email) '
            . 'VALUES ' . implode(', ', $places)
        )->execute($bind);
    }

    // ------------------------------------------------------------------
    // The named members every assertion hangs on: eight visible members of
    // team 10, all in its own division (team 10 does not span).
    // ------------------------------------------------------------------

    $team10     = $teams[10];
    $division10 = $real[(10 - 1) % 4];

    $onTeam10 = array_values(array_filter(
        $visible,
        static fn (array $m): bool => $m['team'] === $team10 && $m['division'] === $division10
    ));
    assertTrue(count($onTeam10) >= 9, 'team 10 holds enough members to name');

    $special = [
        'search'  => $onTeam10[0]['number'],
        'like'    => $onTeam10[1]['number'],
        'noemail' => $onTeam10[2]['number'],
        'home'    => $onTeam10[3]['number'],
        'metricY' => $onTeam10[4]['number'],
        'metricP' => $onTeam10[5]['number'],
        'called'  => $onTeam10[6]['number'],
        'owned'   => $onTeam10[7]['number'],
        'plain'   => $onTeam10[8]['number'],
    ];

    $update = static function (string $sql, array $bind) use ($pdo): void {
        $pdo->prepare($sql)->execute($bind);
    };

    $update(
        "UPDATE member SET preferred_name = 'Zebulon', first_name = 'Robert', last_name = 'Findme' "
        . 'WHERE member_number = :n',
        [':n' => $special['search']]
    );
    $update(
        "UPDATE member SET last_name = '100% Sure' WHERE member_number = :n",
        [':n' => $special['like']]
    );
    $update(
        'UPDATE member SET email = NULL WHERE member_number = :n',
        [':n' => $special['noemail']]
    );
    $update(
        "UPDATE member SET phone_type = 'HOME' WHERE member_number = :n",
        [':n' => $special['home']]
    );

    $memberId = static function (string $number) use ($pdo): int {
        $read = $pdo->prepare('SELECT id FROM member WHERE member_number = :n');
        $read->execute([':n' => $number]);

        return (int) $read->fetchColumn();
    };

    // A fixture officer with an account, for contacted_by and assigned_by.
    $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, division_id, team_id, '
        . "phone, phone_e164, phone_type, email, title, title_level) "
        . "VALUES ('RTOFF0001', 'Adair', 'Officerly', 'Ada', :division, :team, "
        . "'(555) 555-0101', '+15555550101', 'CELL PHONE', 'rtoff0001@example.com', 'Captain', 'officer')"
    )->execute([':division' => $division10, ':team' => $team10]);
    $officerMemberId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO app_user (member_id, level, password_hash, must_change_password, is_active) "
        . "VALUES (:member, 'officer', '*', 0, 1)"
    )->execute([':member' => $officerMemberId]);
    $officerUserId = (int) $pdo->lastInsertId();

    // The officer is a member of team 10 like any Captain is, so every
    // expected answer that covers their team or division includes them.
    $visible[] = ['number' => 'RTOFF0001', 'division' => $division10, 'team' => $team10];

    // Metrics. metricY: HLSR imported Y. metricP: Committee N + in
    // progress, Background N + claimed complete. called: Indemnity N, not
    // started — with two contacts, so it derives Contacted.
    $metric = $pdo->prepare(
        'INSERT INTO member_metric (member_id, show_year_id, metric, imported_value, progress) '
        . 'VALUES (:member, :year, :metric, :imported, :progress)'
    );
    $metric->execute([':member' => $memberId($special['metricY']), ':year' => $year,
        ':metric' => 'hlsr_dues', ':imported' => 'Y', ':progress' => 'not_started']);
    $metric->execute([':member' => $memberId($special['metricP']), ':year' => $year,
        ':metric' => 'committee_dues', ':imported' => 'N', ':progress' => 'in_progress']);
    $metric->execute([':member' => $memberId($special['metricP']), ':year' => $year,
        ':metric' => 'background_check', ':imported' => 'N', ':progress' => 'claimed_complete']);
    $metric->execute([':member' => $memberId($special['called']), ':year' => $year,
        ':metric' => 'indemnity', ':imported' => 'N', ':progress' => 'not_started']);

    // Two contacts, nine and two days ago, so "newest first" is checkable.
    $contact = $pdo->prepare(
        'INSERT INTO contact_log (member_id, show_year_id, contacted_by, contact_type, occurred_at, notes) '
        . 'VALUES (:member, :year, :by, :type, :at, :notes)'
    );
    $contact->execute([':member' => $memberId($special['called']), ':year' => $year,
        ':by' => $officerUserId, ':type' => 'call',
        ':at' => gmdate('Y-m-d H:i:s', time() - 9 * 86400), ':notes' => 'Left a voicemail.']);
    $contact->execute([':member' => $memberId($special['called']), ':year' => $year,
        ':by' => $officerUserId, ':type' => 'text',
        ':at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400), ':notes' => 'Says the cheque is in the post.']);

    $pdo->prepare(
        'INSERT INTO assignment (member_id, officer_member_id, show_year_id, assigned_by) '
        . 'VALUES (:member, :officer, :year, :by)'
    )->execute([':member' => $memberId($special['owned']), ':officer' => $officerMemberId,
        ':year' => $year, ':by' => $officerUserId]);

    register_shutdown_function(static fn () => rt_teardown(rt_pdo()));

    return $fixture = [
        'year'        => $year,
        'teams'       => $teams,
        'real'        => $real,
        'placeholder' => $placeholder,
        'visible'     => $visible,
        'team10'      => $team10,
        'division10'  => $division10,
        'special'     => $special,
    ];
}

function rt_teardown(PDO $pdo): void
{
    // RESTRICT-safe order: everything hanging off RT members first, then the
    // members, then what the members referenced.
    $pdo->exec("DELETE FROM contact_log WHERE member_id IN (SELECT id FROM member WHERE member_number LIKE 'RT%')");
    $pdo->exec("DELETE FROM assignment WHERE member_id IN (SELECT id FROM member WHERE member_number LIKE 'RT%')");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN (SELECT id FROM member WHERE member_number LIKE 'RT%')");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN (SELECT id FROM member WHERE member_number LIKE 'RT%')");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'RT%'");
    $pdo->exec("DELETE FROM import_change WHERE import_batch_id IN (SELECT id FROM import_batch WHERE filename = 'rt-fixture')");
    $pdo->exec("DELETE FROM import_batch WHERE filename = 'rt-fixture'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'RT Team %'");
}

function rt_user(Level $level, ?int $divisionId, ?int $teamId): User
{
    return new User(1, 999999, 'RTUSER', $level, $divisionId, $teamId, false, 'Roster Fixture');
}

function rt_pager(): RosterPage
{
    return new RosterPage(rt_pdo(), 50, 100);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function rt_page(User $user, array $input = []): array
{
    return rt_pager()->page($user, (int) rt_fixture()['year'], $input);
}

/**
 * Every member number the user's roster shows, walking every page — so
 * "exactly their team" compares whole sets, not first pages.
 *
 * @return array<int, string>
 */
function rt_all_numbers(User $user): array
{
    $numbers = [];
    $page    = 1;
    do {
        $result = rt_page($user, ['size' => 100, 'page' => $page]);
        foreach ($result['rows'] as $row) {
            $numbers[] = $row['member_number'];
        }
        $page++;
    } while ($page <= $result['pages']);

    sort($numbers);

    return $numbers;
}

/**
 * The expected member numbers for a filter over the generated fixture.
 *
 * @param callable(array{number:string,division:int,team:?int}): bool $keep
 * @return array<int, string>
 */
function rt_expected(callable $keep): array
{
    $numbers = array_map(
        static fn (array $m): string => $m['number'],
        array_values(array_filter(rt_fixture()['visible'], $keep))
    );
    sort($numbers);

    return $numbers;
}

// ---------------------------------------------------------------------------
// Scope, through the real query path
// ---------------------------------------------------------------------------

test('an Officer\'s pages hold exactly their team — every member, and no other', function (): void {
    $fixture = rt_fixture();

    $got      = rt_all_numbers(rt_user(Level::Officer, $fixture['division10'], $fixture['team10']));
    $expected = rt_expected(static fn (array $m): bool => $m['team'] === $fixture['team10']);

    assertSame($expected, $got, 'member for member, including the 15-per-96 placeholder-division teammates');
});

test('an Officer on a spanning team sees the whole team, both divisions of it', function (): void {
    $fixture = rt_fixture();
    $teamId  = $fixture['teams'][1];

    $got      = rt_all_numbers(rt_user(Level::Officer, $fixture['real'][0], $teamId));
    $expected = rt_expected(static fn (array $m): bool => $m['team'] === $teamId);

    assertSame($expected, $got);

    // And the team genuinely spans: its members carry more than one division.
    $divisions = [];
    foreach (rt_fixture()['visible'] as $member) {
        if ($member['team'] === $teamId) {
            $divisions[$member['division']] = true;
        }
    }
    assertTrue(count($divisions) >= 2, 'a scope read from team.division_id would fail exactly here');
});

test('a Senior Officer\'s pages hold exactly their division, spanning teams included', function (): void {
    $fixture    = rt_fixture();
    $divisionId = $fixture['real'][0];

    $got      = rt_all_numbers(rt_user(Level::SeniorOfficer, $divisionId, null));
    $expected = rt_expected(static fn (array $m): bool => $m['division'] === $divisionId);

    assertSame($expected, $got, 'the whole division and nothing else');
    assertTrue(count($got) > 100, 'big enough that this walked multiple pages');
});

test('a Senior Officer scoped to (No Division) sees its 72 members', function (): void {
    $fixture = rt_fixture();

    $got = rt_all_numbers(rt_user(Level::SeniorOfficer, $fixture['placeholder'], null));

    assertSame(72, count($got), '57 honorary plus 15 ordinary members — a real division row');
});

test('an empty scope is an empty roster, never everything and never an error', function (): void {
    $result = rt_page(rt_user(Level::Officer, rt_fixture()['real'][0], null));

    assertSame(0, $result['total']);
    assertSame([], $result['rows']);
    assertSame(0, $result['from'], 'the count line has nothing to overstate');
    assertSame(0, $result['to']);
});

// ---------------------------------------------------------------------------
// Search: the floor, the columns, the escaping — always inside scope
// ---------------------------------------------------------------------------

test('search below three characters filters nothing and says so', function (): void {
    $fixture = rt_fixture();
    $officer = rt_user(Level::Officer, $fixture['division10'], $fixture['team10']);

    $teamCount = count(rt_expected(static fn (array $m): bool => $m['team'] === $fixture['team10']));

    $result = rt_page($officer, ['q' => 'Ze']);
    assertSame(true, $result['search_too_short']);
    assertSame(false, $result['search_applied']);
    assertSame($teamCount, $result['total'], 'the unfiltered scoped list, not an error and not a guess');
});

test('search matches preferred name, last name and member number from three characters', function (): void {
    $fixture = rt_fixture();
    $officer = rt_user(Level::Officer, $fixture['division10'], $fixture['team10']);

    $byPreferred = rt_page($officer, ['q' => 'Zeb']);
    assertSame(1, $byPreferred['total'], 'preferred name, from the third character');
    assertSame($fixture['special']['search'], $byPreferred['rows'][0]['member_number']);
    assertSame('Zebulon Findme', $byPreferred['rows'][0]['display_name'],
        'and the list calls them by their preferred name');

    $byLast = rt_page($officer, ['q' => 'Findme']);
    assertSame(1, $byLast['total'], 'last name');

    $byNumber = rt_page($officer, ['q' => $fixture['special']['search']]);
    assertSame(1, $byNumber['total'], 'member number');
});

test('search stays inside scope: a name in another division finds nothing', function (): void {
    $fixture = rt_fixture();

    // Zebulon is on team 10, whose division is real[1]. A Senior Officer of
    // a different division searches the name and gets nobody — a search
    // endpoint that forgot scope would leak names three characters at a time.
    $otherDivision = $fixture['real'][2];
    assertTrue($otherDivision !== $fixture['division10']);

    $result = rt_page(rt_user(Level::SeniorOfficer, $otherDivision, null), ['q' => 'Zebulon']);
    assertSame(0, $result['total']);
});

test('a member named 100% is findable, and %%% matches nobody', function (): void {
    $fixture = rt_fixture();
    $officer = rt_user(Level::Officer, $fixture['division10'], $fixture['team10']);

    $literal = rt_page($officer, ['q' => '100%']);
    assertSame(1, $literal['total'], 'the % in the name is a character, not an operator');
    assertSame($fixture['special']['like'], $literal['rows'][0]['member_number']);

    assertSame(0, rt_page($officer, ['q' => '%%%'])['total'], 'a typed wildcard matches nothing');
    assertSame(0, rt_page($officer, ['q' => '___'])['total'], 'and so does a typed underscore');
});

// ---------------------------------------------------------------------------
// The team filter — Senior Officer and above only
// ---------------------------------------------------------------------------

test('the team filter is dropped for an Officer: their team IS their scope', function (): void {
    $fixture = rt_fixture();
    $officer = rt_user(Level::Officer, $fixture['division10'], $fixture['team10']);

    $teamCount = count(rt_expected(static fn (array $m): bool => $m['team'] === $fixture['team10']));

    $result = rt_page($officer, ['team' => [(string) $fixture['teams'][11]]]);
    assertSame(false, $result['can_filter_teams']);
    assertSame([], $result['selected_teams'], 'the input was dropped, not applied');
    assertSame($teamCount, $result['total'], 'still exactly their own team');
    assertSame([], $result['teams'], 'and no filter options are offered');
});

test('a Senior Officer\'s team filter narrows within their division', function (): void {
    $fixture = rt_fixture();
    $senior  = rt_user(Level::SeniorOfficer, $fixture['division10'], null);

    $result   = rt_page($senior, ['team' => [(string) $fixture['team10']], 'size' => 100]);
    $expected = rt_expected(static fn (array $m): bool => $m['team'] === $fixture['team10']
        && $m['division'] === $fixture['division10']);

    assertSame(count($expected), $result['total'],
        'team 10, minus its placeholder-division members, who are outside this scope');

    $got = array_map(static fn (array $r): string => $r['member_number'], $result['rows']);
    sort($got);
    assertSame($expected, $got);

    // The offered options come through the same scope.
    assertTrue($result['can_filter_teams']);
    assertTrue($result['teams'] !== [], 'a Senior Officer is offered the filter');
});

test('filtering by a team outside the division yields nothing, never something', function (): void {
    $fixture = rt_fixture();

    // Team 12 belongs to a different division than team 10's.
    $result = rt_page(
        rt_user(Level::SeniorOfficer, $fixture['division10'], null),
        ['team' => [(string) $fixture['teams'][12]]]
    );

    assertSame(0, $result['total'], 'the filter intersects the scope, it never widens it');
});

// ---------------------------------------------------------------------------
// Pagination: the count line is exact on first, middle and last pages
// ---------------------------------------------------------------------------

test('pagination arithmetic is exact on the first, a middle and the last page', function (): void {
    $fixture = rt_fixture();
    $senior  = rt_user(Level::SeniorOfficer, $fixture['real'][0], null);

    $total = count(rt_expected(static fn (array $m): bool => $m['division'] === $fixture['real'][0]));
    $pages = (int) ceil($total / 50);
    assertTrue($pages >= 3, 'the fixture division is big enough for a middle page');

    $first = rt_page($senior, ['size' => 50]);
    assertSame($total, $first['total']);
    assertSame($pages, $first['pages']);
    assertSame(1, $first['from']);
    assertSame(50, $first['to']);
    assertSame(50, count($first['rows']));

    $middle = rt_page($senior, ['size' => 50, 'page' => 2]);
    assertSame(51, $middle['from']);
    assertSame(100, $middle['to']);

    $last     = rt_page($senior, ['size' => 50, 'page' => $pages]);
    $expected = $total - (($pages - 1) * 50);
    assertSame(($pages - 1) * 50 + 1, $last['from']);
    assertSame($total, $last['to'], 'the last, partial page ends exactly at the total');
    assertSame($expected, count($last['rows']));

    $beyond = rt_page($senior, ['size' => 50, 'page' => 999]);
    assertSame($pages, $beyond['page'], 'a page past the end clamps to the last page, not an empty screen');
});

test('the page size is one of exactly two configured values — never a third', function (): void {
    $fixture = rt_fixture();
    $senior  = rt_user(Level::SeniorOfficer, $fixture['real'][0], null);

    assertSame(50, rt_page($senior)['size'], '50 by choice');
    assertSame(100, rt_page($senior, ['size' => '100'])['size'], '100 by request');
    assertSame(50, rt_page($senior, ['size' => '37'])['size'], 'anything else is the default');
    assertSame(50, rt_page($senior, ['size' => '100000'])['size']);
});

// ---------------------------------------------------------------------------
// Sort: whitelisted, and the contact ordering the product turns on
// ---------------------------------------------------------------------------

test('the sort key maps through a whitelist — user input never reaches the SQL', function (): void {
    $fixture = rt_fixture();
    $officer = rt_user(Level::Officer, $fixture['division10'], $fixture['team10']);

    $result = rt_page($officer, ['sort' => 'm.id; DROP TABLE member --']);
    assertSame('name', $result['sort'], 'an unknown key is the default, not the input');

    $result = rt_page($officer, ['dir' => 'sideways']);
    assertSame('asc', $result['dir']);

    foreach (['name', 'team', 'contact', 'number'] as $key) {
        foreach (['asc', 'desc'] as $dir) {
            $sorted = rt_page($officer, ['sort' => $key, 'dir' => $dir]);
            assertSame($key, $sorted['sort']);
            assertSame($dir, $sorted['dir']);
        }
    }
});

test('sorting by contact puts never-contacted first, ascending', function (): void {
    $fixture = rt_fixture();
    $officer = rt_user(Level::Officer, $fixture['division10'], $fixture['team10']);

    // Exactly one member of team 10 has been contacted. Ascending, the NULLs
    // — never contacted, the next calls to make — come first and they come
    // last; descending, the freshest contact leads.
    $asc  = rt_page($officer, ['sort' => 'contact', 'size' => 100]);
    $rows = $asc['rows'];
    assertSame($fixture['special']['called'], $rows[count($rows) - 1]['member_number']);

    $desc = rt_page($officer, ['sort' => 'contact', 'dir' => 'desc', 'size' => 100]);
    assertSame($fixture['special']['called'], $desc['rows'][0]['member_number']);
});

// ---------------------------------------------------------------------------
// The chips, the batches, and the honest empty states
// ---------------------------------------------------------------------------

/** @return array<string, array<string, mixed>> the officer's page keyed by member number */
function rt_team10_rows(): array
{
    static $byNumber = null;

    if ($byNumber !== null) {
        return $byNumber;
    }

    $fixture = rt_fixture();
    $result  = rt_page(
        rt_user(Level::Officer, $fixture['division10'], $fixture['team10']),
        ['size' => 100]
    );

    $byNumber = [];
    foreach ($result['rows'] as $row) {
        $byNumber[$row['member_number']] = $row;
    }

    return $byNumber;
}

test('the chips on a row derive from the database through the 5.4 function', function (): void {
    $special = rt_fixture()['special'];
    $rows    = rt_team10_rows();

    // Imported Y: Complete, whatever else is true.
    assertSame(MetricStatus::Complete, $rows[$special['metricY']]['statuses']['hlsr_dues']);

    // N + in_progress and N + claimed_complete, side by side on one member.
    assertSame(MetricStatus::InProgress, $rows[$special['metricP']]['statuses']['committee_dues']);
    assertSame(MetricStatus::Reported, $rows[$special['metricP']]['statuses']['background_check']);

    // N + not_started + contacted this year: Contacted, the amber outline.
    assertSame(MetricStatus::Contacted, $rows[$special['called']]['statuses']['indemnity']);

    // No metric row at all is 'unknown' — Not reported, never a failure.
    foreach (Metric::cases() as $metric) {
        assertSame(MetricStatus::NotReported, $rows[$special['plain']]['statuses'][$metric->value],
            'a member no import has covered reads Not reported on ' . $metric->value);
    }
});

test('contact history is batched, newest first, with the officer named', function (): void {
    $special = rt_fixture()['special'];
    $rows    = rt_team10_rows();

    $called = $rows[$special['called']];
    assertSame(2, count($called['contacts']));
    assertSame('text', $called['contacts'][0]['contact_type'], 'newest first');
    assertSame('call', $called['contacts'][1]['contact_type']);
    assertSame('Ada Officerly', $called['contacts'][0]['officer_name'],
        'the contacting officer, by preferred name');
    assertTrue($called['last_contact'] !== null);
    assertSame('Says the cheque is in the post.', $called['last_contact']['notes']);

    // And the launch state: everyone else has never been contacted, which
    // renders as an honest empty state rather than a broken cell.
    $plain = $rows[$special['plain']];
    assertSame([], $plain['contacts']);
    assertSame(null, $plain['last_contact']);
});

test('assigned officers appear on the row; everyone else honestly has none', function (): void {
    $special = rt_fixture()['special'];
    $rows    = rt_team10_rows();

    $owned = $rows[$special['owned']];
    assertSame(1, count($owned['officers']));
    assertSame('Ada Officerly', $owned['officers'][0]['officer_name']);
    assertSame('Captain', $owned['officers'][0]['officer_title']);

    assertSame([], $rows[$special['plain']]['officers']);
});

test('sms: is offered only for CELL PHONE, and mailto: only when an address exists', function (): void {
    $special = rt_fixture()['special'];
    $rows    = rt_team10_rows();

    $cell = $rows[$special['plain']];
    assertSame(true, $cell['can_call']);
    assertSame(true, $cell['can_text'], 'a cell phone gets the Text action');
    assertSame(true, $cell['can_email']);

    $home = $rows[$special['home']];
    assertSame(true, $home['can_call'], 'a HOME number still takes a call');
    assertSame(false, $home['can_text'], 'but never a text that silently fails');

    $noEmail = $rows[$special['noemail']];
    assertSame(false, $noEmail['can_email'], 'no address, no mailto: — absent, not disabled');
    assertSame(true, $noEmail['can_text']);
});

// ---------------------------------------------------------------------------
// Cleanup — always last in this file
// ---------------------------------------------------------------------------

test('roster fixtures are cleaned up', function (): void {
    rt_teardown(rt_pdo());

    assertSame(
        0,
        (int) rt_pdo()->query("SELECT COUNT(*) FROM member WHERE member_number LIKE 'RT%'")->fetchColumn()
    );
    assertSame(
        0,
        (int) rt_pdo()->query("SELECT COUNT(*) FROM team WHERE name LIKE 'RT Team %'")->fetchColumn()
    );
});
