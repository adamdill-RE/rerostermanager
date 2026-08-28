<?php

declare(strict_types=1);

/**
 * The team filter (Phase 10) — one resolver, two screens, and a default.
 *
 * My Roster Status and Export Roster both narrow a roster by team, and from
 * this phase both START narrowed: a caller who has said nothing sees the team
 * they are on, with everything they can see one click away. The rules that
 * makes safe are the ones held here.
 *
 *   1. A choice NARROWS. It can never widen — the scope predicate is ANDed
 *      onto whatever is chosen, so an id from outside the caller's scope
 *      yields an empty roster rather than somebody else's.
 *   2. The absence of a value can mean only one thing. Before the default it
 *      meant "everything"; now it means "I have not said", so wanting
 *      everything needs a token that survives a link, a form and a bookmark.
 *   3. A Committee Dashboard drill-down suppresses the default outright.
 *      Spec 7.3's rule is that every figure there equals this list filtered
 *      to it, and a default quietly ANDed onto such a link would break that
 *      for exactly the people the figure counted.
 *   4. The export's POST resolves to the SAME selection the screen counted.
 *      A page that promises 1,247 rows and downloads 82 is worse than one
 *      that offers no filter at all.
 *
 * Generated, never real: member numbers are 'TF…', phones are the reserved
 * (555) 555-01xx fiction range, addresses are @example.com.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\Admin\ExportPage;
use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Roster\StatusPage;
use Rerm\Roster\TeamFilter;

// ---------------------------------------------------------------------------
// The resolver itself — no database needed
// ---------------------------------------------------------------------------

/** @return array<int, array<string, mixed>> */
function tf_options(int $count = 3): array
{
    $options = [];
    for ($i = 1; $i <= $count; $i++) {
        $options[] = ['id' => $i * 10, 'name' => 'TF Team ' . $i, 'members' => $i];
    }

    return $options;
}

test('a caller who has said nothing starts on the team they are on', function (): void {
    $choice = TeamFilter::choose(null, tf_options(), 20);

    assertSame([20], $choice['selected']);
    assertSame(false, $choice['all']);
    assertSame(true, $choice['defaulted'], 'and the screen has to be able to say so');
    assertSame(20, $choice['own']);
    assertSame('TF Team 2', $choice['own_name']);
    assertSame(true, $choice['may_choose']);
});

test('the ALL token is how somebody asks for everything, in either shape', function (): void {
    // From the dashboard's <select name="team">.
    $scalar = TeamFilter::choose('all', tf_options(), 20);
    assertSame([], $scalar['selected']);
    assertSame(true, $scalar['all']);
    assertSame(false, $scalar['defaulted']);

    // From the export's <input type="checkbox" name="team[]" value="all">.
    $array = TeamFilter::choose(['all'], tf_options(), 20);
    assertSame([], $array['selected']);
    assertSame(true, $array['all']);

    // And it wins over anything ticked beside it, rather than silently
    // narrowing what somebody just asked to see in full.
    $both = TeamFilter::choose(['10', 'all'], tf_options(), 20);
    assertSame(true, $both['all']);
    assertSame([], $both['selected']);
});

test('a chosen team is the selection, and choosing several still works', function (): void {
    $one = TeamFilter::choose('30', tf_options(), 20);
    assertSame([30], $one['selected']);
    assertSame(false, $one['all']);
    assertSame(false, $one['defaulted']);

    $several = TeamFilter::choose(['10', '30'], tf_options(), 20);
    assertSame([10, 30], $several['selected']);
    assertSame(false, $several['all']);
});

test('an out-of-scope id narrows to nothing; it never widens to everything', function (): void {
    // The id is kept rather than filtered against the options, deliberately.
    // Dropping it would leave an empty selection, and an empty selection one
    // line later means "every team" — so a crafted URL naming somebody else's
    // team would return the caller's WHOLE scope instead of nothing.
    $choice = TeamFilter::choose('999', tf_options(), 20);

    assertSame([999], $choice['selected']);
    assertSame(false, $choice['all'], 'the scope predicate is what makes this empty, and it still applies');
});

test('one team in scope is not a choice, and gets no default', function (): void {
    $choice = TeamFilter::choose(null, tf_options(1), 10);

    assertSame(false, $choice['may_choose'], 'an Officer\'s team IS their scope');
    assertSame([], $choice['selected'], 'so nothing is narrowed, and nothing claims to be');
    assertSame(true, $choice['all']);
    assertSame(false, $choice['defaulted']);
});

test('a caller with no team in scope gets everything, not nothing', function (): void {
    // The seeded master administrator has no team at all, and an Admin whose
    // own team is outside what they were scoped to is the same case. Both see
    // their whole scope rather than an empty screen.
    foreach ([null, 999] as $ownTeam) {
        $choice = TeamFilter::choose(null, tf_options(), $ownTeam);

        assertSame(null, $choice['own']);
        assertSame([], $choice['selected']);
        assertSame(true, $choice['all']);
        assertSame(false, $choice['defaulted']);
    }
});

test('a drill-down suppresses the default outright', function (): void {
    $choice = TeamFilter::choose(null, tf_options(), 20, false);

    assertSame([], $choice['selected'], 'the linked group is reproduced exactly, with nothing added');
    assertSame(true, $choice['all']);
    assertSame(false, $choice['defaulted']);
});

test('an empty value reads as silence, not as a choice', function (): void {
    foreach ([null, '', []] as $nothing) {
        assertSame(false, TeamFilter::said($nothing));
    }

    assertSame(true, TeamFilter::said('all'));
    assertSame(true, TeamFilter::said(['10']));
    assertSame(true, TeamFilter::said('10'));
});

test('every state round-trips through a link', function (): void {
    // A link that leaves the selection out re-derives it at the other end,
    // and for somebody who asked for everything that is their roster silently
    // shrinking to twenty-five people on the next page turn.
    $all = TeamFilter::choose('all', tf_options(), 20);
    assertSame(TeamFilter::ALL, TeamFilter::param($all));
    assertSame(true, TeamFilter::choose(TeamFilter::param($all), tf_options(), 20)['all']);

    $one = TeamFilter::choose('30', tf_options(), 20);
    assertSame([30], TeamFilter::param($one));
    assertSame([30], TeamFilter::choose(TeamFilter::param($one), tf_options(), 20)['selected']);

    $default = TeamFilter::choose(null, tf_options(), 20);
    assertSame([20], TeamFilter::param($default));
    assertSame([20], TeamFilter::choose(TeamFilter::param($default), tf_options(), 20)['selected']);
});

// ---------------------------------------------------------------------------
// The two screens
// ---------------------------------------------------------------------------

function tf_pdo(): PDO
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

function tf_teardown(PDO $pdo): void
{
    $members = "SELECT id FROM (SELECT id FROM member WHERE member_number LIKE 'TF%') m";

    $pdo->exec("DELETE FROM contact_log WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM assignment WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user_team WHERE app_user_id IN (SELECT id FROM ("
        . "SELECT u.id FROM app_user u INNER JOIN member m ON m.id = u.member_id"
        . " WHERE m.member_number LIKE 'TF%') x)");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'TF%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'TF Team %'");
}

/**
 * Two divisions. Division A holds three teams; the Senior Officer under test
 * is on the first of them, so "their own team" is a real, and small, subset
 * of what they can see. Division B holds the team nobody in A may look at.
 *
 * @return array<string, mixed>
 */
function tf_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = tf_pdo();
    tf_teardown($pdo);

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();
    assertTrue($year > 0, 'the seeded active show year exists');

    $real = [];
    foreach ($pdo->query('SELECT id FROM division WHERE is_placeholder = 0 ORDER BY id')->fetchAll() as $row) {
        $real[] = (int) $row['id'];
    }
    assertTrue(count($real) >= 2, 'two real divisions');
    [$divisionA, $divisionB] = $real;

    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)');
    $teams      = [];
    foreach ([1 => $divisionA, 2 => $divisionA, 3 => $divisionA, 4 => $divisionB] as $n => $division) {
        $insertTeam->execute([':name' => sprintf('TF Team %02d', $n), ':division' => $division]);
        $teams[$n] = (int) $pdo->lastInsertId();
    }

    // Team 1 gets two members, team 2 three, team 3 four, team 4 five — all
    // different, so a count alone says which team is on the screen.
    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, division_id, team_id,'
        . " phone, phone_e164, phone_type, email, title, title_level)"
        . " VALUES (:number, 'Given', :last, '', :division, :team,"
        . " '(555) 555-0104', '+15555550104', 'CELL PHONE', :email, :title, :level)"
    );

    $n       = 0;
    $members = [];
    foreach ([1 => 2, 2 => 3, 3 => 4, 4 => 5] as $team => $howMany) {
        for ($i = 0; $i < $howMany; $i++) {
            $n++;
            $number = sprintf('TF%06d', $n);
            $insertMember->execute([
                ':number'   => $number,
                ':last'     => 'Tf' . $number,
                ':division' => $team === 4 ? $divisionB : $divisionA,
                ':team'     => $teams[$team],
                ':email'    => strtolower($number) . '@example.com',
                ':title'    => 'Committee Member',
                ':level'    => 'member',
            ]);
            $members[$number] = (int) $pdo->lastInsertId();
        }
    }

    // The Senior Officer, on team 1 — so their own team is 2 of the 9 members
    // their division holds, which is the difference the default makes.
    $insertMember->execute([
        ':number'   => 'TFSENIOR',
        ':last'     => 'Tfsenior',
        ':division' => $divisionA,
        ':team'     => $teams[1],
        ':email'    => 'tfsenior@example.com',
        ':title'    => 'Coordinator',
        ':level'    => 'senior_officer',
    ]);
    $seniorMember = (int) $pdo->lastInsertId();

    // And a Captain on team 2, whose team IS their scope.
    $insertMember->execute([
        ':number'   => 'TFCAPT',
        ':last'     => 'Tfcaptain',
        ':division' => $divisionA,
        ':team'     => $teams[2],
        ':email'    => 'tfcapt@example.com',
        ':title'    => 'Captain',
        ':level'    => 'officer',
    ]);
    $captainMember = (int) $pdo->lastInsertId();

    $insertUser = $pdo->prepare(
        'INSERT INTO app_user (member_id, level, password_hash, must_change_password, is_active)'
        . " VALUES (:member, :level, '*', 0, 1)"
    );
    $insertUser->execute([':member' => $seniorMember, ':level' => 'senior_officer']);
    $seniorUser = (int) $pdo->lastInsertId();
    $insertUser->execute([':member' => $captainMember, ':level' => 'officer']);
    $captainUser = (int) $pdo->lastInsertId();

    return $fixture = [
        'year'        => $year,
        'division_a'  => $divisionA,
        'division_b'  => $divisionB,
        'teams'       => $teams,
        'members'     => $members,
        'senior'      => new User(
            $seniorUser,
            $seniorMember,
            'TFSENIOR',
            Level::SeniorOfficer,
            $divisionA,
            $teams[1],
            false,
            'Given Tfsenior'
        ),
        'captain'     => new User(
            $captainUser,
            $captainMember,
            'TFCAPT',
            Level::Officer,
            $divisionA,
            $teams[2],
            false,
            'Given Tfcaptain'
        ),
    ];
}

// ---------------------------------------------------------------------------
// My Roster Status
// ---------------------------------------------------------------------------

test('My Roster Status starts on the officer\'s own team, and says which', function (): void {
    $f    = tf_fixture();
    $page = StatusPage::fromApp($GLOBALS['rerm_app'])->page($f['senior'], $f['year'], []);

    // Team 1 is two members plus the Senior Officer themselves.
    assertSame(3, (int) $page['dashboard']['total']);

    $teams = $page['team_choice'];
    assertSame(true, $teams['may_choose'], 'their division holds three teams');
    assertSame(true, $teams['defaulted']);
    assertSame([$f['teams'][1]], $teams['selected']);
    assertSame('TF Team 01', $teams['own_name']);

    // A default is not a drill-down. The "you followed a link from the
    // Committee Dashboard" banner must not fire for a screen nobody linked to.
    assertSame(false, $page['filters']['active']);
    assertSame(false, $page['filters']['drilled']);
});

test('All teams shows the whole division, and one team shows exactly that team', function (): void {
    $f    = tf_fixture();
    $page = StatusPage::fromApp($GLOBALS['rerm_app'])->page($f['senior'], $f['year'], ['team' => 'all']);

    // Division A: 2 + 3 + 4 members, plus the Senior Officer and the Captain.
    assertSame(11, (int) $page['dashboard']['total']);
    assertSame(true, $page['team_choice']['all']);
    assertSame(false, $page['team_choice']['defaulted']);

    $third = StatusPage::fromApp($GLOBALS['rerm_app'])
        ->page($f['senior'], $f['year'], ['team' => (string) $f['teams'][3]]);
    assertSame(4, (int) $third['dashboard']['total']);
    assertSame([$f['teams'][3]], $third['team_choice']['selected']);
});

test('a team in another division is refused by the scope, not widened by the filter', function (): void {
    $f    = tf_fixture();
    $page = StatusPage::fromApp($GLOBALS['rerm_app'])
        ->page($f['senior'], $f['year'], ['team' => (string) $f['teams'][4]]);

    assertSame(0, (int) $page['dashboard']['total'], 'nothing, rather than division B');
    assertSame([$f['teams'][4]], $page['team_choice']['selected']);
    assertSame(false, $page['team_choice']['all'], 'an out-of-scope id must never collapse to everything');
});

test('a Committee Dashboard drill-down is reproduced exactly, with no team default added', function (): void {
    $f    = tf_fixture();
    $page = StatusPage::fromApp($GLOBALS['rerm_app'])->page($f['senior'], $f['year'], [
        'division' => (string) $f['division_a'],
        'show'     => 'all',
    ]);

    // The whole division, exactly as the figure that made the link counted —
    // not the division AND the officer's own team.
    assertSame(11, (int) $page['dashboard']['total']);
    assertSame(false, $page['team_choice']['defaulted']);
    assertSame(true, $page['filters']['drilled']);
    assertSame(true, $page['filters']['active'], 'and the screen says it is showing a linked group');
});

test('an Officer is offered no team picker, because their team is their scope', function (): void {
    $f    = tf_fixture();
    $page = StatusPage::fromApp($GLOBALS['rerm_app'])->page($f['captain'], $f['year'], []);

    assertSame(false, $page['team_choice']['may_choose']);
    assertSame([], $page['team_choice']['selected']);
    // Team 2's three members plus the Captain.
    assertSame(4, (int) $page['dashboard']['total']);
});

test('the picker renders, and every link on the screen carries the team', function (): void {
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];
    $f   = tf_fixture();

    $render = static function (array $input) use ($app, $f): string {
        $_SESSION ??= [];
        $user       = $f['senior'];
        $year       = ['id' => $f['year'], 'label' => 'TF-2027', 'is_open' => true];
        $wide       = true;
        $title      = 'My Roster Status';
        $notices    = [];
        $statusPage = StatusPage::fromApp($app)->page($user, $f['year'], $input);

        ob_start();
        require $app->path('app/views/dashboard.php');
        $body = (string) ob_get_clean();

        ob_start();
        require $app->path('app/views/layout.php');

        return (string) ob_get_clean();
    };

    $html = $render([]);
    assertTrue(str_contains($html, 'Which team'), 'the control is on the screen');
    assertTrue(str_contains($html, 'TF Team 03'), 'and offers the other teams in scope');
    assertTrue(str_contains($html, '(your team)'), 'and marks the one they are on');
    assertTrue(str_contains($html, 'the team you are\n                on')
        || str_contains($html, 'the team you are'), 'and says the screen started there');
    assertTrue(!str_contains($html, 'TF Team 04'), 'never a team from another division');

    // Every dashboard link keeps the selection. Losing it on a page turn is
    // the officer's roster silently changing size under them.
    assertSame(1, preg_match_all('/href="([^"]*dashboard[^"]*)"/', $html, $m) > 0 ? 1 : 0);
    $kept = 0;
    foreach ($m[1] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES);
        if (!str_contains($href, '?')) {
            continue;
        }
        $kept++;
        assertTrue(
            str_contains($href, 'team%5B0%5D=' . $f['teams'][1]) || str_contains($href, 'team=all'),
            "a dashboard link lost the team: {$href}"
        );
    }
    assertTrue($kept > 0, 'there are links to check');

    // And once everything is showing, the links say so rather than falling
    // back to the default at the other end.
    $all = $render(['team' => 'all']);
    assertTrue(str_contains($all, 'team=all'), 'the ALL token travels');
    assertTrue(str_contains($all, 'Showing every team you can see'));
});

// ---------------------------------------------------------------------------
// Export Roster
// ---------------------------------------------------------------------------

test('the export starts on the officer\'s own team, and the count says so', function (): void {
    $f    = tf_fixture();
    $page = ExportPage::fromApp($GLOBALS['rerm_app'])->page($f['senior'], ['year' => (string) $f['year']]);

    assertSame(true, $page['can_filter_teams']);
    assertSame(true, $page['team_choice']['defaulted']);
    assertSame([$f['teams'][1]], $page['selected_teams']);
    assertSame(3, (int) $page['rows'], 'two members and the officer themselves');
});

test('ticking everything you can see exports the whole scope', function (): void {
    $f    = tf_fixture();
    $page = ExportPage::fromApp($GLOBALS['rerm_app'])->page($f['senior'], [
        'year' => (string) $f['year'],
        'team' => ['all'],
    ]);

    assertSame([], $page['selected_teams']);
    assertSame(true, $page['team_choice']['all']);
    assertSame(11, (int) $page['rows'], 'the whole division, and nothing from the other one');
});

test('the download resolves to exactly the selection the screen counted', function (): void {
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];
    $f   = tf_fixture();

    // This is the failure the ALL token exists to prevent: the screen says
    // eleven and the POST, carrying no team at all, would come back as "I
    // have not chosen" and hand over three.
    foreach ([['all'], [(string) $f['teams'][3]], null] as $input) {
        $get = ['year' => (string) $f['year']];
        if ($input !== null) {
            $get['team'] = $input;
        }

        $screen = ExportPage::fromApp($app)->page($f['senior'], $get);

        // Exactly what app/views/export.php puts in the POST form's hidden
        // fields, transcribed here so the two cannot drift apart quietly.
        $carry = $screen['team_choice']['all']
            ? [TeamFilter::ALL]
            : array_map(static fn (int $id): string => (string) $id, $screen['team_choice']['selected']);

        $download = ExportPage::fromApp($app)->page($f['senior'], [
            'year' => (string) $f['year'],
            'team' => $carry,
        ]);

        assertSame(
            (int) $screen['rows'],
            (int) $download['rows'],
            'the file must hold what the screen promised'
        );
        assertSame($screen['selected_teams'], $download['selected_teams']);
    }
});

test('the whole selection survives the redirect after a write', function (): void {
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];
    $f   = tf_fixture();

    // Logging a contact 303s back to where the officer was, through
    // dashboard_return_query()'s whitelist. A whitelist that took only
    // integers would drop `team=all` — and somebody who asked for every team
    // would come back to their own one, having logged one call, with no error
    // and no way to tell.
    $source = (string) file_get_contents(__DIR__ . '/../public/index.php');
    assertTrue(
        str_contains($source, "'token' => Rerm\\Roster\\TeamFilter::ALL"),
        'the return whitelist does not carry the ALL token'
    );

    $render = static function (array $input) use ($app, $f): string {
        $_SESSION ??= [];
        $user       = $f['senior'];
        $year       = ['id' => $f['year'], 'label' => 'TF-2027', 'is_open' => true];
        $wide       = true;
        $title      = 'My Roster Status';
        $notices    = [];
        $statusPage = StatusPage::fromApp($app)->page($user, $f['year'], $input);

        ob_start();
        require $app->path('app/views/dashboard.php');

        return (string) ob_get_clean();
    };

    $state = static function (string $html): string {
        assertSame(1, preg_match('/name="return" value="([^"]*)"/', $html, $m), 'the sheet carries a return state');

        return urldecode(html_entity_decode($m[1], ENT_QUOTES));
    };

    // The log-contact sheet renders for ONE row at a time (?log=id), so each
    // view is opened on a member it actually contains.
    $onTeamOne   = (int) $f['members']['TF000001'];
    $onTeamThree = (int) $f['members']['TF000006'];

    assertTrue(
        str_contains($state($render(['team' => 'all', 'log' => (string) $onTeamOne])), 'team=all'),
        'the ALL token must survive the write'
    );

    assertTrue(
        str_contains(
            $state($render(['team' => (string) $f['teams'][3], 'log' => (string) $onTeamThree])),
            'team[0]=' . $f['teams'][3]
        ),
        'and so must a chosen team'
    );

    assertTrue(
        str_contains($state($render(['log' => (string) $onTeamOne])), 'team[0]=' . $f['teams'][1]),
        'and the default, explicitly, rather than being re-derived at the other end'
    );
});

test('an Officer gets no export team filter, and exports their team', function (): void {
    $f    = tf_fixture();
    $page = ExportPage::fromApp($GLOBALS['rerm_app'])->page($f['captain'], ['year' => (string) $f['year']]);

    assertSame(false, $page['can_filter_teams']);
    assertSame([], $page['selected_teams']);
    assertSame(4, (int) $page['rows']);
});

test('team filter fixtures are cleaned up', function (): void {
    tf_teardown(tf_pdo());

    $left = (int) tf_pdo()->query("SELECT COUNT(*) FROM member WHERE member_number LIKE 'TF%'")->fetchColumn();
    assertSame(0, $left);
});
