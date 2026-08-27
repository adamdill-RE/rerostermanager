<?php

declare(strict_types=1);

/**
 * My Roster Status (spec 7.1, Phase 5): the dashboard roll-up proven EQUAL
 * to the list — both computed from one fixture — the My members / My team
 * toggle on both branches, the default outstanding-only filter and its
 * next-call-first ordering, and the log-a-contact write path: the happy
 * path, the per-metric progress writes, the out-of-scope refusal (through
 * the real route-shaped input, not the screen), the closed-show-year
 * refusal, the correction-is-a-new-row rule, and the full effective-status
 * lifecycle an officer walks a member through.
 *
 * Generated, never real: this repository is public. Member numbers here are
 * 'ST000001', phones are the reserved (555) 555-01xx fiction range, and
 * addresses are @example.com.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Roster\LogContact;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
use Rerm\Roster\StatusPage;
use Rerm\Routes;

// ---------------------------------------------------------------------------
// Routes and labels — no database needed
// ---------------------------------------------------------------------------

test('the dashboard route is "dashboard", never /status — that is the health check', function (): void {
    assertSame(Capability::ViewStatusDashboard->value, Routes::guard('dashboard'));
    assertSame(Routes::STATUS_KEY, Routes::guard('status'), 'the Phase 0 ops check is untouched');
});

test('the landing path stays SIGNED_IN, the menu has its own route, the write is guarded', function (): void {
    assertSame(Routes::SIGNED_IN, Routes::guard(''), 'the landing swap is the handler\'s, not the guard\'s');
    assertSame(Routes::SIGNED_IN, Routes::guard('menu'));
    assertSame(Capability::LogContact->value, Routes::guard('log-contact'));
});

test('the renamed labels and their definitions come from the one enum', function (): void {
    // Decided 5: three renames, three unchanged. The chips on View My Roster
    // and every dashboard legend spell these through the same label().
    assertSame('Reported Complete', MetricStatus::Reported->label());
    assertSame('Member Handling', MetricStatus::InProgress->label());
    assertSame('Open/No Contact', MetricStatus::Outstanding->label());
    assertSame('Complete', MetricStatus::Complete->label());
    assertSame('Contacted', MetricStatus::Contacted->label());
    assertSame('Not reported', MetricStatus::NotReported->label());

    // Decided 6: every status explains itself, and Not reported is never a
    // failure.
    foreach (MetricStatus::cases() as $status) {
        assertTrue($status->definition() !== '', $status->value . ' has a definition');
    }
    assertTrue(str_contains(MetricStatus::NotReported->definition(), 'never a failure'));
});

// ---------------------------------------------------------------------------
// The database under test — the same accessor pattern as roster_test
// ---------------------------------------------------------------------------

function st_pdo(): PDO
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

function st_teardown(PDO $pdo): void
{
    // RESTRICT-safe order: everything hanging off ST members first, then the
    // members, then the teams.
    $pdo->exec("DELETE FROM contact_log WHERE member_id IN (SELECT id FROM member WHERE member_number LIKE 'ST%')");
    $pdo->exec("DELETE FROM assignment WHERE member_id IN (SELECT id FROM member WHERE member_number LIKE 'ST%')");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN (SELECT id FROM member WHERE member_number LIKE 'ST%')");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN (SELECT id FROM member WHERE member_number LIKE 'ST%')");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'ST%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'ST Team %'");
}

/**
 * The fixture: three teams across two divisions, one member per effective
 * status the dashboard can show, and the EXPECTED tallies computed while
 * generating — by a transcription of the 5.4 table, not by the function
 * under test — so every card assertion is against numbers the code being
 * tested never touched.
 *
 * Team 1 (officer one's):
 *   fully       all four imported Y — the one Fully Complete member
 *   mixed       two Y, two N
 *   claimed     all N, committee_dues claimed_complete  -> Reported Complete
 *   handling    all N, indemnity in_progress            -> Member Handling
 *   contact5    all N, contacted 5 days ago             -> Contacted
 *   contact20   all N, contacted 20 days ago
 *   opena/openb all N, never contacted                  -> Open/No Contact
 *   norow       NO metric rows                          -> Not reported
 *   assigned    all N, contacted 1 day ago, ASSIGNED to officer one
 *   officer one themselves — a member of team 1, no metric rows
 *
 * Team 2, same division: t2open (all N), t2full (all Y), officer two (who
 * holds no assignments — the launch state).
 * Team 3, the other division: outsider (all N) — the out-of-scope target.
 *
 * @return array<string, mixed>
 */
function st_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = st_pdo();
    st_teardown($pdo);

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();
    assertTrue($year > 0, 'the seeded active show year exists');

    $real = [];
    foreach ($pdo->query('SELECT id FROM division WHERE is_placeholder = 0 ORDER BY id')->fetchAll() as $row) {
        $real[] = (int) $row['id'];
    }
    assertTrue(count($real) >= 2, 'two real divisions to span');
    [$divisionA, $divisionB] = $real;

    $teams      = [];
    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)');
    foreach ([1 => $divisionA, 2 => $divisionA, 3 => $divisionB] as $n => $division) {
        $insertTeam->execute([':name' => sprintf('ST Team %02d', $n), ':division' => $division]);
        $teams[$n] = (int) $pdo->lastInsertId();
    }

    // Per-member specs. metrics: metric => [imported, progress], or absent
    // from the map for no member_metric row at all. contacted_days: null
    // means never contacted this show year.
    $allN = [
        'hlsr_dues'        => ['N', 'not_started'],
        'committee_dues'   => ['N', 'not_started'],
        'indemnity'        => ['N', 'not_started'],
        'background_check' => ['N', 'not_started'],
    ];
    $allY = [
        'hlsr_dues'        => ['Y', 'not_started'],
        'committee_dues'   => ['Y', 'not_started'],
        'indemnity'        => ['Y', 'not_started'],
        'background_check' => ['Y', 'not_started'],
    ];

    $specs = [
        'fully'     => ['team' => 1, 'metrics' => $allY, 'contacted_days' => null],
        'mixed'     => ['team' => 1, 'metrics' => array_merge($allN, [
            'hlsr_dues'      => ['Y', 'not_started'],
            'committee_dues' => ['Y', 'not_started'],
        ]), 'contacted_days' => null],
        'claimed'   => ['team' => 1, 'metrics' => array_merge($allN, [
            'committee_dues' => ['N', 'claimed_complete'],
        ]), 'contacted_days' => null],
        'handling'  => ['team' => 1, 'metrics' => array_merge($allN, [
            'indemnity' => ['N', 'in_progress'],
        ]), 'contacted_days' => null],
        'contact5'  => ['team' => 1, 'metrics' => $allN, 'contacted_days' => 5],
        'contact20' => ['team' => 1, 'metrics' => $allN, 'contacted_days' => 20],
        'opena'     => ['team' => 1, 'metrics' => $allN, 'contacted_days' => null],
        'openb'     => ['team' => 1, 'metrics' => $allN, 'contacted_days' => null],
        'norow'     => ['team' => 1, 'metrics' => [], 'contacted_days' => null],
        'assigned'  => ['team' => 1, 'metrics' => $allN, 'contacted_days' => 1],

        't2open'    => ['team' => 2, 'metrics' => $allN, 'contacted_days' => null],
        't2full'    => ['team' => 2, 'metrics' => $allY, 'contacted_days' => null],

        'outsider'  => ['team' => 3, 'metrics' => $allN, 'contacted_days' => null],
    ];

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, division_id, team_id,'
        . ' phone, phone_e164, phone_type, email)'
        . " VALUES (:number, 'Member', :last, '', :division, :team,"
        . " '(555) 555-0102', '+15555550102', 'CELL PHONE', :email)"
    );

    $members = [];
    $n       = 0;
    foreach ($specs as $key => $spec) {
        $n++;
        $number   = sprintf('ST%06d', $n);
        $division = $spec['team'] === 3 ? $divisionB : $divisionA;
        $insertMember->execute([
            ':number'   => $number,
            ':last'     => sprintf('St%06d', $n),
            ':division' => $division,
            ':team'     => $teams[$spec['team']],
            ':email'    => strtolower($number) . '@example.com',
        ]);
        $members[$key] = [
            'id'       => (int) $pdo->lastInsertId(),
            'number'   => $number,
            'last'     => sprintf('St%06d', $n),
            'division' => $division,
            'team'     => $spec['team'],
        ];
    }

    // The two officers, members of their teams like any Captain, each with
    // an account — LogContact's contacted_by is an app_user id.
    $insertOfficer = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, division_id, team_id,'
        . " phone, phone_e164, phone_type, email, title, title_level)"
        . " VALUES (:number, :first, :last, '', :division, :team,"
        . " '(555) 555-0103', '+15555550103', 'CELL PHONE', :email, 'Captain', 'officer')"
    );
    $insertAccount = $pdo->prepare(
        "INSERT INTO app_user (member_id, level, password_hash, must_change_password, is_active)"
        . " VALUES (:member, 'officer', '*', 0, 1)"
    );

    $officers = [];
    foreach ([1 => ['STOFF01', 'Ada', 'Stofficer'], 2 => ['STOFF02', 'Bea', 'Stcaptain']] as $team => $who) {
        [$number, $first, $last] = $who;
        $insertOfficer->execute([
            ':number'   => $number,
            ':first'    => $first,
            ':last'     => $last,
            ':division' => $divisionA,
            ':team'     => $teams[$team],
            ':email'    => strtolower($number) . '@example.com',
        ]);
        $memberId = (int) $pdo->lastInsertId();
        $insertAccount->execute([':member' => $memberId]);
        $officers[$team] = [
            'member_id' => $memberId,
            'user_id'   => (int) $pdo->lastInsertId(),
            'number'    => $number,
        ];
    }

    // Metric rows, exactly as specified.
    $insertMetric = $pdo->prepare(
        'INSERT INTO member_metric (member_id, show_year_id, metric, imported_value, progress)'
        . ' VALUES (:member, :year, :metric, :imported, :progress)'
    );
    foreach ($specs as $key => $spec) {
        foreach ($spec['metrics'] as $metric => [$imported, $progress]) {
            $insertMetric->execute([
                ':member'   => $members[$key]['id'],
                ':year'     => $year,
                ':metric'   => $metric,
                ':imported' => $imported,
                ':progress' => $progress,
            ]);
        }
    }

    // Contacts, by officer one, at the specified ages.
    $insertContact = $pdo->prepare(
        'INSERT INTO contact_log (member_id, show_year_id, contacted_by, contact_type, occurred_at, notes)'
        . " VALUES (:member, :year, :by, 'call', :at, 'Fixture call.')"
    );
    foreach ($specs as $key => $spec) {
        if ($spec['contacted_days'] !== null) {
            $insertContact->execute([
                ':member' => $members[$key]['id'],
                ':year'   => $year,
                ':by'     => $officers[1]['user_id'],
                ':at'     => gmdate('Y-m-d H:i:s', time() - $spec['contacted_days'] * 86400),
            ]);
        }
    }

    // The one assignment: officer one owns 'assigned'. Officer two holds
    // none — the launch state, and the other toggle branch.
    $pdo->prepare(
        'INSERT INTO assignment (member_id, officer_member_id, show_year_id, assigned_by)'
        . ' VALUES (:member, :officer, :year, :by)'
    )->execute([
        ':member'  => $members['assigned']['id'],
        ':officer' => $officers[1]['member_id'],
        ':year'    => $year,
        ':by'      => $officers[1]['user_id'],
    ]);

    // ------------------------------------------------------------------
    // The expected team-1 tallies, from a TRANSCRIPTION of the 5.4 table —
    // the derivation under test never touches these numbers.
    // ------------------------------------------------------------------

    $statusOf = static function (?array $mp, bool $contacted): string {
        if ($mp === null) {
            return 'not_reported';
        }
        [$imported, $progress] = $mp;
        if ($imported === 'Y') {
            return 'complete';
        }
        if ($imported !== 'N') {
            return 'not_reported';
        }
        if ($progress === 'claimed_complete') {
            return 'reported';
        }
        if ($progress === 'in_progress') {
            return 'in_progress';
        }

        return $contacted ? 'contacted' : 'outstanding';
    };

    $scored   = ['hlsr_dues', 'committee_dues', 'indemnity', 'background_check'];
    $expected = ['total' => 0, 'fully' => 0, 'cards' => []];
    foreach ($scored as $metric) {
        $expected['cards'][$metric] = [
            'complete' => 0, 'reported' => 0, 'in_progress' => 0,
            'contacted' => 0, 'outstanding' => 0, 'not_reported' => 0,
        ];
    }

    // Everyone team 1 shows: the ten specs plus officer one (no metric rows,
    // never contacted).
    $team1Rows = [];
    foreach ($specs as $key => $spec) {
        if ($spec['team'] === 1) {
            $team1Rows[] = ['metrics' => $spec['metrics'], 'contacted' => $spec['contacted_days'] !== null];
        }
    }
    $team1Rows[] = ['metrics' => [], 'contacted' => false];

    foreach ($team1Rows as $row) {
        $expected['total']++;
        $complete = 0;
        foreach ($scored as $metric) {
            $status = $statusOf($row['metrics'][$metric] ?? null, $row['contacted']);
            $expected['cards'][$metric][$status]++;
            if ($status === 'complete') {
                $complete++;
            }
        }
        if ($complete === 4) {
            $expected['fully']++;
        }
    }

    register_shutdown_function(static fn () => st_teardown(st_pdo()));

    return $fixture = [
        'year'      => $year,
        'teams'     => $teams,
        'divisionA' => $divisionA,
        'divisionB' => $divisionB,
        'members'   => $members,
        'officers'  => $officers,
        'expected'  => $expected,
    ];
}

/** Officer one: team 1, holds the fixture's one assignment. */
function st_officer1(): User
{
    $f = st_fixture();

    return new User(
        $f['officers'][1]['user_id'],
        $f['officers'][1]['member_id'],
        'STOFF01',
        Level::Officer,
        $f['divisionA'],
        $f['teams'][1],
        false,
        'Ada Stofficer'
    );
}

/** Officer two: team 2, holds no assignments — the launch state. */
function st_officer2(): User
{
    $f = st_fixture();

    return new User(
        $f['officers'][2]['user_id'],
        $f['officers'][2]['member_id'],
        'STOFF02',
        Level::Officer,
        $f['divisionA'],
        $f['teams'][2],
        false,
        'Bea Stcaptain'
    );
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function st_page(User $user, array $input = []): array
{
    return (new StatusPage(st_pdo(), 50, 100))->page($user, (int) st_fixture()['year'], $input);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function st_log(User $user, array $input): array
{
    return (new LogContact(st_pdo()))->log($user, $input);
}

function st_contact_count(int $memberId): int
{
    $read = st_pdo()->prepare('SELECT COUNT(*) FROM contact_log WHERE member_id = :m');
    $read->execute([':m' => $memberId]);

    return (int) $read->fetchColumn();
}

// ---------------------------------------------------------------------------
// The toggle — both branches (spec 7.1)
// ---------------------------------------------------------------------------

test('an officer with no assignments lands on My team — the launch state', function (): void {
    $result = st_page(st_officer2());

    assertSame('team', $result['mode'], 'no assignments, so the default is the team');
    assertSame(false, $result['has_assignments']);

    // Team 2 outstanding: t2open and officer two themselves (no metric rows
    // reads Not reported, which counts as outstanding); t2full is complete.
    assertSame(2, $result['total']);
});

test('an officer with assignments lands on My members, holding exactly them', function (): void {
    $fixture = st_fixture();
    $result  = st_page(st_officer1());

    assertSame('mine', $result['mode'], 'assignments exist, so the default is My members');
    assertSame(true, $result['has_assignments']);
    assertSame(1, $result['total']);
    assertSame($fixture['members']['assigned']['number'], $result['rows'][0]['member_number']);
});

test('the toggle overrides the default in both directions', function (): void {
    $team = st_page(st_officer1(), ['mode' => 'team']);
    assertSame('team', $team['mode']);
    assertSame(10, $team['total'], 'eleven in scope, minus the one fully complete');

    // My members with nothing assigned is an honest empty state, not an
    // error and never everybody.
    $mine = st_page(st_officer2(), ['mode' => 'mine']);
    assertSame('mine', $mine['mode']);
    assertSame(0, $mine['total']);
    assertSame([], $mine['rows']);
    assertSame(0, $mine['dashboard']['total'], 'the dashboard describes the same empty set');
});

// ---------------------------------------------------------------------------
// The dashboard — decided 3 and 4: derived in PHP, proven against numbers
// the derivation never touched, and proven equal to the list
// ---------------------------------------------------------------------------

test('the banner and every card match the transcribed expectations exactly', function (): void {
    $expected = st_fixture()['expected'];
    $dash     = st_page(st_officer1(), ['mode' => 'team'])['dashboard'];

    assertSame($expected['total'], $dash['total']);
    assertSame($expected['fully'], $dash['fully_complete'], 'Fully Complete = Complete on all four');

    foreach ($expected['cards'] as $metric => $counts) {
        foreach ($counts as $status => $count) {
            assertSame($count, $dash['cards'][$metric]['statuses'][$status],
                "{$metric} {$status}");
        }
        assertSame($counts['complete'], $dash['cards'][$metric]['complete']);
        // Outstanding is EVERY effective status except Complete (decided 4):
        // Reported Complete and Member Handling still count, and so does
        // Not reported, so nobody vanishes from the working set.
        assertSame($expected['total'] - $counts['complete'], $dash['cards'][$metric]['outstanding']);
    }
});

test('the cards are exactly the four scored metrics — harassment training is not among them', function (): void {
    $dash = st_page(st_officer1(), ['mode' => 'team'])['dashboard'];

    assertSame(
        array_map(static fn (Metric $m): string => $m->value, Metric::scored()),
        array_keys($dash['cards'])
    );
});

/**
 * Walks every page of a view and returns the rows.
 *
 * @param array<string, mixed> $input
 * @return array<int, array<string, mixed>>
 */
function st_all_rows(User $user, array $input): array
{
    $rows = [];
    $page = 1;
    do {
        $result = st_page($user, $input + ['size' => 100, 'page' => $page]);
        foreach ($result['rows'] as $row) {
            $rows[] = $row;
        }
        $page++;
    } while ($page <= $result['pages']);

    return $rows;
}

test('every card figure equals the list filtered to that status — two scopes, one rule', function (): void {
    $fixture = st_fixture();

    $scopes = [
        'officer team' => [st_officer1(), ['mode' => 'team', 'show' => 'all']],
        'senior division' => [
            new User(1, 999999, 'STUSER', Level::SeniorOfficer, $fixture['divisionA'], null, false, 'Senior Fixture'),
            ['show' => 'all'],
        ],
    ];

    foreach ($scopes as $name => [$user, $input]) {
        $result = st_page($user, $input);
        $rows   = st_all_rows($user, $input);

        assertSame($result['dashboard']['total'], count($rows), "{$name}: show=all lists the whole scope");

        $fully = 0;
        $tally = [];
        foreach ($rows as $row) {
            $complete = 0;
            foreach (Metric::scored() as $metric) {
                $status = $row['statuses'][$metric->value];
                $tally[$metric->value][$status->value] = ($tally[$metric->value][$status->value] ?? 0) + 1;
                if ($status === MetricStatus::Complete) {
                    $complete++;
                }
            }
            if ($complete === 4) {
                $fully++;
                assertSame(true, $row['fully']);
            }
        }

        assertSame($result['dashboard']['fully_complete'], $fully, "{$name}: the banner equals the list");
        foreach ($result['dashboard']['cards'] as $metric => $card) {
            foreach ($card['statuses'] as $status => $count) {
                assertSame($count, $tally[$metric][$status] ?? 0, "{$name}: {$metric} {$status}");
            }
        }
    }
});

test('a Senior Officer\'s view holds exactly their division — the outsider is not in it', function (): void {
    $fixture = st_fixture();
    $senior  = new User(1, 999999, 'STUSER', Level::SeniorOfficer, $fixture['divisionA'], null, false, 'Senior Fixture');

    $numbers = array_map(
        static fn (array $r): string => (string) $r['member_number'],
        st_all_rows($senior, ['show' => 'all'])
    );
    sort($numbers);

    $expected = ['STOFF01', 'STOFF02'];
    foreach ($fixture['members'] as $key => $member) {
        if ($member['team'] !== 3) {
            $expected[] = $member['number'];
        }
    }
    sort($expected);

    assertSame($expected, $numbers, 'teams 1 and 2 whole, team 3 absent');
});

// ---------------------------------------------------------------------------
// The list — the default filter and the next-call-first ordering
// ---------------------------------------------------------------------------

test('the default filter is outstanding-on-any: the fully complete appear only on request', function (): void {
    $fixture = st_fixture();
    $officer = st_officer1();

    $default = st_page($officer, ['mode' => 'team']);
    assertSame('outstanding', $default['show']);
    assertSame(10, $default['total']);
    $numbers = array_map(static fn (array $r): string => (string) $r['member_number'], $default['rows']);
    assertTrue(!in_array($fixture['members']['fully']['number'], $numbers, true),
        'complete on all four means out of the working set');

    $all = st_page($officer, ['mode' => 'team', 'show' => 'all']);
    assertSame(11, $all['total']);
    $numbers = array_map(static fn (array $r): string => (string) $r['member_number'], $all['rows']);
    assertTrue(in_array($fixture['members']['fully']['number'], $numbers, true));

    // A member who merely CLAIMS completion is still in the working set:
    // outstanding until Rodeo Houston's roster confirms Y (decided 4).
    assertTrue(in_array($fixture['members']['claimed']['number'],
        array_map(static fn (array $r): string => (string) $r['member_number'], $default['rows']), true));
});

test('the top of the list is the next call to make: never contacted first, then oldest first', function (): void {
    $fixture = st_fixture();
    $rows    = st_page(st_officer1(), ['mode' => 'team'])['rows'];

    assertSame(10, count($rows));

    // The first seven have never been contacted; the contacted tail runs
    // oldest to newest, so a contact freshly logged sinks to the bottom.
    foreach (array_slice($rows, 0, 7) as $row) {
        assertSame(null, $row['last_contact'], $row['member_number'] . ' leads because nobody has called');
    }
    $tail = array_map(static fn (array $r): string => (string) $r['member_number'], array_slice($rows, 7));
    assertSame([
        $fixture['members']['contact20']['number'],
        $fixture['members']['contact5']['number'],
        $fixture['members']['assigned']['number'],
    ], $tail);
});

test('page size is one of exactly two configured values, and the count line is exact', function (): void {
    $officer = st_officer1();

    $result = st_page($officer, ['mode' => 'team', 'size' => '37']);
    assertSame(50, $result['size'], 'anything else is the default');
    assertSame(1, $result['from']);
    assertSame(10, $result['to']);
    assertSame(10, $result['total']);
});

// ---------------------------------------------------------------------------
// Log a contact — the first per-member mutation, through route-shaped input
// ---------------------------------------------------------------------------

test('the happy path: a call is logged and Open/No Contact becomes Contacted', function (): void {
    $fixture = st_fixture();
    $target  = $fixture['members']['opena'];

    $result = st_log(st_officer1(), [
        'member_id'    => (string) $target['id'],
        'contact_type' => 'call',
        'note'         => 'Left a voicemail about dues.',
    ]);

    assertSame('logged', $result['outcome']);
    assertSame('Member ' . $target['last'], $result['member_name']);
    assertSame(0, $result['progress_changes']);

    // The row, exactly as spec 7.1 requires it: this user, this show year,
    // the server's moment — the input carried no timestamp to trust.
    $read = st_pdo()->prepare(
        'SELECT contacted_by, show_year_id, contact_type, notes, occurred_at FROM contact_log'
        . ' WHERE member_id = :m ORDER BY id DESC'
    );
    $read->execute([':m' => $target['id']]);
    $row = $read->fetch();
    assertSame($fixture['officers'][1]['user_id'], (int) $row['contacted_by']);
    assertSame((int) $fixture['year'], (int) $row['show_year_id']);
    assertSame('call', $row['contact_type']);
    assertSame('Left a voicemail about dues.', $row['notes']);
    assertTrue(abs(time() - strtotime((string) $row['occurred_at'] . ' UTC')) < 300,
        'occurred_at is the server\'s now, never back-dated');

    // And the effective status moved, on the same screen that will show it.
    $rows = st_all_rows(st_officer1(), ['mode' => 'team']);
    foreach ($rows as $row) {
        if ($row['member_number'] === $target['number']) {
            foreach (Metric::scored() as $metric) {
                assertSame(MetricStatus::Contacted, $row['statuses'][$metric->value]);
            }

            return;
        }
    }
    assertTrue(false, 'the contacted member is still in the outstanding list');
});

test('the lifecycle: Contacted -> Member Handling -> Reported Complete, one write each', function (): void {
    $fixture = st_fixture();
    $target  = $fixture['members']['opena'];
    $officer = st_officer1();

    $statusNow = static function () use ($officer, $target): MetricStatus {
        foreach (st_all_rows($officer, ['mode' => 'team']) as $row) {
            if ($row['member_number'] === $target['number']) {
                return $row['statuses'][Metric::Indemnity->value];
            }
        }
        throw new RuntimeException('member fell out of the list');
    };

    // They said they are taking care of it.
    $result = st_log($officer, [
        'member_id'    => (string) $target['id'],
        'contact_type' => 'text',
        'note'         => 'Says the indemnity form is on their desk.',
        'progress'     => [Metric::Indemnity->value => 'in_progress'],
    ]);
    assertSame('logged', $result['outcome']);
    assertSame(1, $result['progress_changes']);
    assertSame(MetricStatus::InProgress, $statusNow(), 'Member Handling');

    // The write is attributed: ours, with author, moment and note (spec 6.6).
    $read = st_pdo()->prepare(
        'SELECT progress, progress_by, progress_at, progress_note FROM member_metric'
        . " WHERE member_id = :m AND show_year_id = :y AND metric = 'indemnity'"
    );
    $read->execute([':m' => $target['id'], ':y' => $fixture['year']]);
    $metric = $read->fetch();
    assertSame('in_progress', $metric['progress']);
    assertSame($fixture['officers'][1]['user_id'], (int) $metric['progress_by']);
    assertTrue($metric['progress_at'] !== null);
    assertSame('Says the indemnity form is on their desk.', $metric['progress_note']);

    // They say it is done.
    $result = st_log($officer, [
        'member_id'    => (string) $target['id'],
        'contact_type' => 'call',
        'note'         => 'Confirmed they signed and mailed it.',
        'progress'     => [Metric::Indemnity->value => 'claimed_complete'],
    ]);
    assertSame('logged', $result['outcome']);
    assertSame(MetricStatus::Reported, $statusNow(), 'Reported Complete, awaiting the next import');

    // (Import's Y -> Complete, and the progress reset that goes with it, is
    // Phase 2's, proven in import_test.)
});

test('progress on a member no import has covered creates the row and stays Not reported', function (): void {
    $fixture = st_fixture();
    $target  = $fixture['members']['norow'];

    $result = st_log(st_officer1(), [
        'member_id'    => (string) $target['id'],
        'contact_type' => 'in_person',
        'note'         => str_repeat('x', 600),
        'progress'     => [Metric::HlsrDues->value => 'in_progress'],
    ]);
    assertSame('logged', $result['outcome']);
    assertSame(1, $result['progress_changes']);

    $read = st_pdo()->prepare(
        'SELECT imported_value, progress, progress_note FROM member_metric'
        . " WHERE member_id = :m AND show_year_id = :y AND metric = 'hlsr_dues'"
    );
    $read->execute([':m' => $target['id'], ':y' => $fixture['year']]);
    $row = $read->fetch();
    assertTrue(is_array($row), 'the upsert created the missing row');
    assertSame('unknown', $row['imported_value'], 'the import\'s column is untouched — no import has spoken');
    assertSame('in_progress', $row['progress']);
    assertSame(500, mb_strlen((string) $row['progress_note']), 'progress_note is capped at its column length');

    // Effective status is still Not reported: unknown short-circuits the 5.4
    // function, and unknown is never a failure.
    foreach (st_all_rows(st_officer1(), ['mode' => 'team']) as $row) {
        if ($row['member_number'] === $target['number']) {
            assertSame(MetricStatus::NotReported, $row['statuses'][Metric::HlsrDues->value]);
        }
    }
});

test('a correction is a new row: contact_log only ever grows, and old notes survive', function (): void {
    $fixture = st_fixture();
    $target  = $fixture['members']['opena'];

    // Three contacts so far from the tests above.
    assertSame(3, st_contact_count($target['id']));

    $result = st_log(st_officer1(), [
        'member_id'    => (string) $target['id'],
        'contact_type' => 'other',
        'note'         => 'Correction: the earlier note was about the WRONG member.',
    ]);
    assertSame('logged', $result['outcome']);
    assertSame(4, st_contact_count($target['id']), 'a fourth row, not an edit');

    $read = st_pdo()->prepare(
        'SELECT notes FROM contact_log WHERE member_id = :m ORDER BY id'
    );
    $read->execute([':m' => $target['id']]);
    $notes = array_map(static fn (array $r): string => (string) $r['notes'], $read->fetchAll());
    assertSame('Left a voicemail about dues.', $notes[0],
        'the first note is untouched — who said what, and when, stays answerable');
});

// ---------------------------------------------------------------------------
// The refusals — server-side, whatever the screen showed
// ---------------------------------------------------------------------------

test('an out-of-scope member is refused server-side, indistinguishably from a missing one', function (): void {
    $fixture  = st_fixture();
    $outsider = $fixture['members']['outsider'];

    // Officer one holds team 1; the outsider is another division entirely.
    // The request is route-shaped — exactly what a forged POST carries.
    $result = st_log(st_officer1(), [
        'member_id'    => (string) $outsider['id'],
        'contact_type' => 'call',
        'note'         => 'should never land',
        'progress'     => [Metric::HlsrDues->value => 'claimed_complete'],
    ]);
    assertSame('not_found', $result['outcome'], 'out of scope reads as not found — nothing to discuss');
    assertSame(0, st_contact_count($outsider['id']), 'no contact row');

    $read = st_pdo()->prepare(
        "SELECT progress FROM member_metric WHERE member_id = :m AND metric = 'hlsr_dues'"
    );
    $read->execute([':m' => $outsider['id']]);
    assertSame('not_started', $read->fetchColumn(), 'no progress write either');

    // A member id that does not exist at all gets the same answer.
    $result = st_log(st_officer1(), [
        'member_id' => '999999999', 'contact_type' => 'call', 'note' => '',
    ]);
    assertSame('not_found', $result['outcome']);

    // And a Member-level user is refused by the level floor before scope is
    // even asked.
    $member = new User(
        $fixture['officers'][1]['user_id'],
        $fixture['officers'][1]['member_id'],
        'STOFF01',
        Level::Member,
        $fixture['divisionA'],
        $fixture['teams'][1],
        false,
        'Demoted Fixture'
    );
    $result = st_log($member, [
        'member_id'    => (string) $fixture['members']['openb']['id'],
        'contact_type' => 'call',
        'note'         => '',
    ]);
    assertSame('not_found', $result['outcome']);
});

test('an unknown contact type is refused and writes nothing', function (): void {
    $fixture = st_fixture();
    $target  = $fixture['members']['openb'];
    $before  = st_contact_count($target['id']);

    $result = st_log(st_officer1(), [
        'member_id'    => (string) $target['id'],
        'contact_type' => 'carrier_pigeon',
        'note'         => 'no',
    ]);
    assertSame('bad_type', $result['outcome']);
    assertSame($before, st_contact_count($target['id']));
});

test('a closed show year refuses the contact and the progress write alike', function (): void {
    $fixture = st_fixture();
    $target  = $fixture['members']['openb'];
    $pdo     = st_pdo();
    $before  = st_contact_count($target['id']);

    $pdo->prepare('UPDATE show_year SET is_open = 0 WHERE id = :y')
        ->execute([':y' => $fixture['year']]);

    try {
        $result = st_log(st_officer1(), [
            'member_id'    => (string) $target['id'],
            'contact_type' => 'call',
            'note'         => 'should be refused',
            'progress'     => [Metric::Indemnity->value => 'in_progress'],
        ]);
        assertSame('year_closed', $result['outcome'], 'closed means read-only (spec 5.1)');
        assertSame($before, st_contact_count($target['id']));

        $read = $pdo->prepare(
            "SELECT progress FROM member_metric WHERE member_id = :m AND metric = 'indemnity'"
        );
        $read->execute([':m' => $target['id']]);
        assertSame('not_started', $read->fetchColumn());
    } finally {
        $pdo->prepare('UPDATE show_year SET is_open = 1 WHERE id = :y')
            ->execute([':y' => $fixture['year']]);
    }
});

// ---------------------------------------------------------------------------
// Cleanup — always last in this file
// ---------------------------------------------------------------------------

test('status fixtures are cleaned up', function (): void {
    st_teardown(st_pdo());

    assertSame(
        0,
        (int) st_pdo()->query("SELECT COUNT(*) FROM member WHERE member_number LIKE 'ST%'")->fetchColumn()
    );
    assertSame(
        0,
        (int) st_pdo()->query("SELECT COUNT(*) FROM team WHERE name LIKE 'ST Team %'")->fetchColumn()
    );
});
