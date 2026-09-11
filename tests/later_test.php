<?php

declare(strict_types=1);

/**
 * Phase 10.5 — the rest of the review (spec-v2 §10): what the last import
 * did for the people on a screen; the import forms tidied; Manage Teams
 * grouped and findable; Import History paged and the Audit Log asked about
 * one member; the Roster Change Form's Enter key and its legend; and the
 * Status page's words.
 *
 * The fixture is generated and the expectations are TRANSCRIBED beside it.
 * Generated, never real: this repository is public. Member numbers here are
 * 'LT000001', addresses are @example.com and phones are the reserved
 * (555) 555-01xx fiction range.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\Admin\AuditPage;
use Rerm\Admin\TeamsPage;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Import\HeaderMap;
use Rerm\Import\Importer;
use Rerm\Import\ImportHistory;
use Rerm\Roster\CommitteePage;
use Rerm\Roster\Metric;
use Rerm\Roster\SinceImport;

function lt_app(): App
{
    return $GLOBALS['rerm_app'];
}

function lt_source(string $relative): string
{
    return (string) file_get_contents(__DIR__ . '/../' . $relative);
}

function lt_render(string $view, string $title, array $data): string
{
    $app = lt_app();
    $_SESSION ??= [];
    $wide = false;
    extract($data, EXTR_SKIP);

    ob_start();
    require $app->path('app/views/' . $view . '.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------
// What needs no database: the shape of the screens
// ---------------------------------------------------------------------------

test('the import\'s three modes are one fieldset, and the team chooser sits under the Team option', function (): void {
    $view = lt_source('app/views/import.php');

    assertTrue(str_contains($view, '<fieldset class="modes">'), 'one question, one fieldset');
    assertTrue(str_contains($view, '<legend>What this import is</legend>'), 'the question is its legend');

    // The team select is drawn inside the loop, only for the Team option,
    // and inside the fieldset — never below all three as though it applied
    // to each of them.
    $team   = strpos($view, 'id="team_id"');
    $option = strpos($view, 'Importer::MODE_TEAM');
    $close  = strpos($view, '</fieldset>');
    assertTrue($team !== false && $option !== false && $close !== false);
    assertTrue($option < $team && $team < $close, 'the chooser is under the Team option');
    assertTrue(!str_contains($view, '(not a team import)'), 'and no longer needs to say what it is not');
});

test('Enter on the Roster Change Form downloads: the first submit in the form is download, off the tab order', function (): void {
    $view = lt_source('app/views/form-rcf.php');

    $form  = strpos($view, '<form method="post"');
    $first = strpos($view, 'type="submit"', $form);
    assertTrue($first !== false);
    $tag = substr($view, $first, 200);
    assertTrue(str_contains($tag, 'value="download"'), 'the first submit is download, not load');
    assertTrue(str_contains($tag, 'class="vh"') && str_contains($tag, 'tabindex="-1"'), 'hidden, and a keyboard never lands on it');

    // The visible Download button is still there for the eye and the tab.
    assertSame(2, substr_count($view, 'value="download"'), 'one hidden, one seen');

    // The legend: open on a desktop, a fold on a phone — the pattern My
    // Roster Status has used since Phase 10.3, not a <details> that is shut
    // on both.
    assertTrue(str_contains($view, '<section class="codes">'));
    assertTrue(str_contains($view, 'id="fold-codes" class="fold vh"'));
    assertTrue(str_contains($view, '<label for="fold-codes" class="fold-label">What the codes mean</label>'));
    assertTrue(!str_contains($view, '<summary>What the codes mean</summary>'));
});

test('the contact import puts the form first, keeps the officer and team, and links the manual', function (): void {
    $view = lt_source('app/views/import-contacts.php');

    $form   = strpos($view, '<h2>Choose the file, the officer and the team</h2>');
    $manual = strpos($view, 'id="needs"');
    assertTrue($form !== false && $manual !== false);
    assertTrue($form < $manual, 'the form comes first; the manual is below it');
    assertTrue(str_contains($view, 'href="#needs"'), 'and the file field links to it');

    // The chosen officer and team come back after a discard, and after a
    // file that would not stage: the next file is nearly always for the
    // same pair.
    assertTrue(str_contains($view, "\$preselect['officer']"), 'the officer select reads the preselect');
    assertTrue(str_contains($view, "\$preselect['team']"), 'so does the team select');
    assertTrue(str_contains($view, 'name="officer" value="<?= e((string) ($batch[\'default_officer_user_id\']'), 'the discard form carries the officer');
    assertTrue(str_contains($view, 'name="team" value="<?= e((string) ($batch[\'team_id\']'), 'and the team');

    $route = lt_source('public/index.php');
    assertTrue(str_contains($route, "'preselect' => \$preselect"), 'the route hands the view the pair');
    assertTrue(str_contains($route, "['officer' => 'officer_id', 'team' => 'team_id']"), 'read from either form\'s field names');
});

test('Newly met is a Committee Dashboard sort, in the whitelist and on the screen', function (): void {
    assertTrue(in_array('improved', CommitteePage::SORTS, true));
    $view = lt_source('app/views/committee.php');
    assertTrue(str_contains($view, "\$sortHeader('improved', 'Newly met')"));
    assertTrue(str_contains($view, "\$row['improved']"));
});

test('the Status page: mail disabled is fine, the migration hint names /setup first, and it links the app', function (): void {
    $app    = lt_app();
    $checks = [
        'generated_at'           => App::now(),
        'app_version'            => $app->version(),
        'php_matches_production' => true,
        'document_root'          => '/example/public_html',
        'db_host'                => 'db.example.com',
        'db_name'                => 'example_rerm',
        'db_connected'           => true,
        'db_version'             => '8.0.41',
        'db_time_zone'           => 'SYSTEM',
        'db_error'               => '',
        'migrations_applied'     => 10,
        'migrations_pending'     => ['999_example.sql'],
        'migrations_broken'      => [],
        'mail_can_deliver'       => false,
        'mail_transport'         => 'file',
        'mail_allowlist'         => 0,
        'writable'               => ['var' => true],
    ];

    $html = lt_render('status', 'Status', ['checks' => $checks]);

    // Mail off is the shipped, intended state (CLAUDE.md): it is green, not
    // a warning the operator learns to ignore.
    assertTrue(str_contains($html, 'chip-ok">Disabled</span> nothing can leave this machine'));
    assertTrue(!str_contains($html, 'chip-warn">Disabled'));

    // The host has no shell, so the way that works on it is named first.
    $hint  = strpos($html, 'Migrations are never applied by a deploy');
    $setup = strpos($html, $app->url('setup'), $hint);
    $shell = strpos($html, 'php bin/migrate.php', $hint);
    assertTrue($hint !== false && $setup !== false && $shell !== false);
    assertTrue($setup < $shell, '/setup before the shell command');

    // And a way out: the page used to end with nowhere to go.
    assertTrue(str_contains($html, '>Open the application</a>'));
    assertTrue(str_contains($html, 'href="' . e($app->url('setup')) . '">Setup</a>'));

    // Mail on renders the other word — still green, still a word.
    $checks['mail_can_deliver'] = true;
    $html = lt_render('status', 'Status', ['checks' => $checks]);
    assertTrue(str_contains($html, 'chip-ok">Enabled</span> this installation can send email'));
});

// ---------------------------------------------------------------------------
// The fixture: a roster imported three times
// ---------------------------------------------------------------------------

function lt_pdo(): PDO
{
    static $pdo = null;
    static $failure = null;

    if ($failure !== null) {
        skip($failure);
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = lt_app()->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    $ready = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_change'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/** RESTRICT-safe order, the same shape import_history_test.php uses. */
function lt_teardown(): void
{
    $pdo = lt_pdo();

    $batches = "SELECT id FROM (SELECT id FROM import_batch WHERE filename LIKE 'LT-%') b";
    $members = "SELECT id FROM (SELECT id FROM member WHERE member_number LIKE 'LT%') m";

    $pdo->exec("DELETE FROM import_change WHERE import_batch_id IN ({$batches})");
    $pdo->exec("DELETE FROM import_change WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM import_staged_row WHERE import_batch_id IN ({$batches})");
    $pdo->exec("DELETE FROM import_warning WHERE import_batch_id IN ({$batches})");
    $pdo->exec('DELETE FROM audit_log WHERE actor_user_id IN (SELECT id FROM ('
        . 'SELECT u.id FROM app_user u INNER JOIN member m ON m.id = u.member_id'
        . " WHERE m.member_number LIKE 'LT%') x)");
    // The rows the audit-filter test writes about LT members, by entity.
    $ids = $pdo->query("SELECT id FROM member WHERE member_number LIKE 'LT%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids !== []) {
        $gone = $pdo->prepare("DELETE FROM audit_log WHERE entity = 'member' AND entity_id = :id");
        foreach ($ids as $id) {
            $gone->execute([':id' => (string) $id]);
        }
    }
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("UPDATE member SET last_seen_import_id = NULL, dropped_since_import_id = NULL"
        . " WHERE member_number LIKE 'LT%'");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'LT%'");
    $pdo->exec("UPDATE member SET dropped_since_import_id = NULL WHERE dropped_since_import_id IN ({$batches})");
    $pdo->exec("UPDATE member SET last_seen_import_id = NULL WHERE last_seen_import_id IN ({$batches})");
    $pdo->exec("DELETE FROM import_batch WHERE filename LIKE 'LT-%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'LT Team %'");
}

function lt_headers(): array
{
    return [
        HeaderMap::TITLE, HeaderMap::CUSTOMER_NUMBER, HeaderMap::NAME, HeaderMap::FULL_NAME,
        HeaderMap::PREFIX, HeaderMap::FIRST_NAME, HeaderMap::LAST_NAME, HeaderMap::PREFERRED_NAME,
        HeaderMap::LEGAL_NAME_VERIFIED, HeaderMap::SUBCOMMITTEE_1, HeaderMap::SUBCOMMITTEE_2,
        HeaderMap::SUBCOMMITTEE_3, HeaderMap::ADDRESS, HeaderMap::CITY, HeaderMap::STATE,
        HeaderMap::ZIP, HeaderMap::PHONE, HeaderMap::PHONE_TYPE, HeaderMap::EMAIL,
        HeaderMap::SHOW_DUES, HeaderMap::COMMITTEE_DUES, HeaderMap::INDEMNITY,
        HeaderMap::BACKGROUND_CHECK, HeaderMap::HARASSMENT_TRAINING, HeaderMap::ROOKIE,
        HeaderMap::BADGE_RELEASED, HeaderMap::BADGE_RELEASED_DATE, HeaderMap::BADGE_ISSUE_DATE,
        HeaderMap::BADGE_PICKUP_PERSON, HeaderMap::ELIGIBLE_SERVICE, HeaderMap::ELIGIBILITY_UPDATED,
        HeaderMap::LTC_APPLIED, HeaderMap::IN_OTHER_COMMITTEES,
    ];
}

/**
 * @param array<string, string> $overrides
 * @return array<string, string>
 */
function lt_member(string $number, array $overrides = []): array
{
    return $overrides + [
        HeaderMap::TITLE               => 'Committee Member',
        HeaderMap::CUSTOMER_NUMBER     => $number,
        HeaderMap::NAME                => 'Later, Given',
        HeaderMap::FULL_NAME           => 'Given Later',
        HeaderMap::PREFIX              => '',
        HeaderMap::FIRST_NAME          => 'Given',
        HeaderMap::LAST_NAME           => 'Later' . $number,
        HeaderMap::PREFERRED_NAME      => '',
        HeaderMap::LEGAL_NAME_VERIFIED => 'Y',
        HeaderMap::SUBCOMMITTEE_1      => 'LT Team A',
        HeaderMap::SUBCOMMITTEE_2      => 'Tba 9',
        HeaderMap::SUBCOMMITTEE_3      => 'Bus Ops Division',
        HeaderMap::ADDRESS             => '100 Example Way',
        HeaderMap::CITY                => 'Houston',
        HeaderMap::STATE               => 'TX',
        HeaderMap::ZIP                 => '77001',
        HeaderMap::PHONE               => '(555) 555-0101',
        HeaderMap::PHONE_TYPE          => 'CELL PHONE',
        HeaderMap::EMAIL               => 'lt' . strtolower($number) . '@example.com',
        HeaderMap::SHOW_DUES           => 'N',
        HeaderMap::COMMITTEE_DUES      => 'N',
        HeaderMap::INDEMNITY           => 'N',
        HeaderMap::BACKGROUND_CHECK    => 'N',
        HeaderMap::HARASSMENT_TRAINING => '',
        HeaderMap::ROOKIE              => 'N',
        HeaderMap::BADGE_RELEASED      => 'N',
        HeaderMap::BADGE_RELEASED_DATE => '',
        HeaderMap::BADGE_ISSUE_DATE    => '',
        HeaderMap::BADGE_PICKUP_PERSON => '',
        HeaderMap::ELIGIBLE_SERVICE    => '',
        HeaderMap::ELIGIBILITY_UPDATED => '',
        HeaderMap::LTC_APPLIED         => 'N',
        HeaderMap::IN_OTHER_COMMITTEES => 'N',
    ];
}

/** @param array<int, array<string, string>> $members */
function lt_csv(array $members): string
{
    static $dir = null;

    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/rerm-later-' . getmypid();
        @mkdir($dir, 0700, true);
        register_shutdown_function(static function () use (&$dir): void {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        });
    }

    $headers = lt_headers();
    $path    = $dir . '/LT-' . substr(sha1(serialize($members)), 0, 12) . '.csv';

    $handle = fopen($path, 'wb');
    fputcsv($handle, $headers);
    foreach ($members as $member) {
        $row = [];
        foreach ($headers as $header) {
            $row[] = $member[$header] ?? '';
        }
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

/** @param array<int, array<string, string>> $members */
function lt_apply(array $members): int
{
    $importer = new Importer(lt_pdo(), 3, 24);
    $batch    = $importer->stage(lt_csv($members), 'LT-roster.csv');
    $importer->apply($batch);

    return $batch;
}

function lt_id(string $number): int
{
    $read = lt_pdo()->prepare('SELECT id FROM member WHERE member_number = :n');
    $read->execute([':n' => $number]);

    return (int) $read->fetchColumn();
}

/**
 * Three imports: everybody outstanding; then two people's requirements met;
 * then one of them undone again. Returns the three batch ids.
 *
 * @return array{first: int, second: int, third: int}
 */
function lt_fixture(): array
{
    static $fixture = null;
    if ($fixture !== null) {
        return $fixture;
    }

    lt_teardown();

    $first = lt_apply([lt_member('LT000001'), lt_member('LT000002'), lt_member('LT000003')]);

    $second = lt_apply([
        lt_member('LT000001', [HeaderMap::SHOW_DUES => 'Y', HeaderMap::COMMITTEE_DUES => 'Y']),
        lt_member('LT000002', [HeaderMap::INDEMNITY => 'Y']),
        lt_member('LT000003'),
    ]);

    return $fixture = ['first' => $first, 'second' => $second, 'third' => 0];
}

const LT_WHERE = "m.member_number LIKE 'LT%'";

// ---------------------------------------------------------------------------
// What the last import did (R32)
// ---------------------------------------------------------------------------

test('the last applied import is the one the screens count from', function (): void {
    $f = lt_fixture();

    $since  = new SinceImport(lt_pdo());
    $latest = $since->latest();
    assertTrue($latest !== null);
    assertSame($f['second'], $latest['id'], 'the newest applied batch');
    assertSame('complete', $latest['mode']);
});

test('per requirement, the count is the people the file moved to Y — transcribed', function (): void {
    $f = lt_fixture();

    $counts = (new SinceImport(lt_pdo()))->flipsToY($f['second'], LT_WHERE, []);

    // TRANSCRIBED from the fixture: LT000001 met two, LT000002 met one,
    // LT000003 met none. Background check moved for nobody.
    assertSame([
        Metric::HlsrDues->value        => 1,
        Metric::CommitteeDues->value   => 1,
        Metric::Indemnity->value       => 1,
        Metric::BackgroundCheck->value => 0,
    ], $counts);

    // The first import created everybody and moved nobody: a person who
    // appears with a Y was never chased, so it is not a requirement met.
    $none = (new SinceImport(lt_pdo()))->flipsToY($f['first'], LT_WHERE, []);
    assertSame(0, array_sum($none), 'a first appearance is not a flip');
});

test('the count reads through the screen\'s own predicate, so it describes exactly the people the card counts', function (): void {
    $f = lt_fixture();

    $one = (new SinceImport(lt_pdo()))->flipsToY(
        $f['second'],
        'm.member_number = :lt_number',
        [':lt_number' => 'LT000002'],
    );
    assertSame(1, $one[Metric::Indemnity->value]);
    assertSame(0, $one[Metric::HlsrDues->value]);
    assertSame(0, $one[Metric::CommitteeDues->value]);
    assertSame(1, array_sum($one));
});

test('per member, the roll-up tallies how many requirements each person met', function (): void {
    $f = lt_fixture();

    $flips = (new SinceImport(lt_pdo()))->flipsByMember($f['second'], LT_WHERE, []);
    assertSame(2, $flips[lt_id('LT000001')] ?? 0);
    assertSame(1, $flips[lt_id('LT000002')] ?? 0);
    assertTrue(!isset($flips[lt_id('LT000003')]), 'nobody is listed with zero');
});

test('a requirement moving back to N is never counted as met, and an unchanged roster meets nothing', function (): void {
    $f = lt_fixture();

    // The third import: LT000001's committee dues are withdrawn again, and
    // nothing else changes.
    $third = lt_apply([
        lt_member('LT000001', [HeaderMap::SHOW_DUES => 'Y', HeaderMap::COMMITTEE_DUES => 'N']),
        lt_member('LT000002', [HeaderMap::INDEMNITY => 'Y']),
        lt_member('LT000003'),
    ]);

    $since = new SinceImport(lt_pdo());
    assertSame($third, $since->latest()['id'] ?? 0, 'the newest import is now the third');

    $counts = $since->flipsToY($third, LT_WHERE, []);
    assertSame(0, array_sum($counts), 'a flip to N is a loss, not a requirement met');

    // And the roster imported once more, unchanged, moves nobody.
    $fourth = lt_apply([
        lt_member('LT000001', [HeaderMap::SHOW_DUES => 'Y', HeaderMap::COMMITTEE_DUES => 'N']),
        lt_member('LT000002', [HeaderMap::INDEMNITY => 'Y']),
        lt_member('LT000003'),
    ]);
    assertSame(0, array_sum($since->flipsToY($fourth, LT_WHERE, [])));
    assertSame([], $since->flipsByMember($fourth, LT_WHERE, []));
});

test('SinceImport reads only what an import wrote — never a contact, a progress value or an assignment', function (): void {
    $source = lt_source('app/src/Roster/SinceImport.php');

    foreach (['contact_log', 'member_metric', 'assignment', 'app_user'] as $ours) {
        assertTrue(!str_contains($source, $ours), "never reads {$ours}");
    }
    assertTrue(str_contains($source, 'FROM import_change'));
    assertTrue(!preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $source), 'and writes nothing');
});

// ---------------------------------------------------------------------------
// Import History paged (R36)
// ---------------------------------------------------------------------------

test('the import list is counted and paged, and the page number is clamped both ways', function (): void {
    lt_fixture();

    // Four LT imports are on the list now, whatever else the database holds.
    $history = new ImportHistory(lt_pdo(), 2);

    $first = $history->page([]);
    assertSame('batches', $first['view']);
    $total = $first['batches_total'];
    assertTrue($total >= 4, 'at least this fixture\'s imports');
    assertSame((int) ceil($total / 2), $first['batches_pages']);
    assertSame(1, $first['batches_page']);
    assertSame(2, count($first['batches']), 'a page is the page size');
    assertSame(1, $first['batches_from']);
    assertSame(2, $first['batches_to']);

    $last = $history->page(['bpage' => '999']);
    assertSame($first['batches_pages'], $last['batches_page'], 'past the end lands on the last page');
    assertTrue(count($last['batches']) >= 1 && count($last['batches']) <= 2);
    assertSame($total, $last['batches_to'], 'the last page ends on the count');

    $under = $history->page(['bpage' => '0']);
    assertSame(1, $under['batches_page'], 'below the start lands on the first');

    // Newest first, and the second page continues where the first stopped:
    // no import is shown twice and none is skipped.
    $second = $history->page(['bpage' => '2']);
    $ids    = array_merge(array_column($first['batches'], 'id'), array_column($second['batches'], 'id'));
    assertSame(count($ids), count(array_unique($ids)), 'no import on two pages');
    $sorted = $ids;
    rsort($sorted);
    assertSame($sorted, $ids, 'newest first across the pages');
});

test('the Import History screen draws the count and the Newer/Older controls', function (): void {
    lt_fixture();

    $page = (new ImportHistory(lt_pdo(), 2))->page([]);
    $html = lt_render('import-history', 'Import History', [
        'user'    => new User(1, 1, 'LT0000000', Level::Admin, null, null, false, 'Later Admin'),
        'notices' => [],
        'history' => $page,
    ]);

    assertTrue(str_contains($html, 'aria-label="Imports"'), 'the paging nav');
    assertTrue(str_contains($html, 'bpage=2'), 'and a way to the older page');
    assertTrue(str_contains($html, ' of ' . number_format($page['batches_total'])), 'the count line');
});

// ---------------------------------------------------------------------------
// The Audit Log asked about one member (R36)
// ---------------------------------------------------------------------------

test('the Audit Log answers a member number with the rows about that member, and nothing else', function (): void {
    lt_fixture();
    $pdo = lt_pdo();
    $id  = lt_id('LT000001');

    (new AuditLog($pdo))->record(null, Action::PurgeMember, 'member', (string) $id, null, ['purged' => true]);
    (new AuditLog($pdo))->record(null, Action::RestoreMember, 'member', (string) $id, null, ['purged' => false]);

    $page = (new AuditPage($pdo, 50, 100))->page(['member' => 'LT000001']);
    assertSame('LT000001', $page['member'], 'the filter is echoed back to the form');
    assertSame(2, $page['total']);
    foreach ($page['rows'] as $row) {
        assertSame('member', (string) $row['entity']);
        assertSame((string) $id, (string) $row['entity_id']);
    }

    // A number nobody holds matches nothing, rather than everything.
    $none = (new AuditPage($pdo, 50, 100))->page(['member' => 'LT-nobody']);
    assertSame(0, $none['total']);
    assertSame([], $none['rows']);

    // And the filter narrows the actor filter rather than replacing it.
    $both = (new AuditPage($pdo, 50, 100))->page(['member' => 'LT000001', 'action' => Action::PurgeMember->value]);
    assertSame(1, $both['total']);
});

// ---------------------------------------------------------------------------
// Manage Teams grouped and findable (R35)
// ---------------------------------------------------------------------------

test('Manage Teams groups teams under their area, with the unplaced under (No area)', function (): void {
    lt_fixture();
    $pdo = lt_pdo();

    $page = (new TeamsPage($pdo))->page([]);
    assertTrue(isset($page['groups']['(No area)']), 'an imported team has no area yet');
    $names = array_column($page['groups']['(No area)'], 'name');
    assertTrue(in_array('LT Team A', $names, true));
    assertSame(count($page['teams']), $page['all'], 'unfiltered, every team is shown');

    // An Admin places it, and it moves group.
    $pdo->prepare("UPDATE team SET area = 'Bus Ops' WHERE name = 'LT Team A'")->execute();
    $page = (new TeamsPage($pdo))->page([]);
    assertTrue(in_array('LT Team A', array_column($page['groups']['Bus Ops'] ?? [], 'name'), true));
    assertTrue(!in_array('LT Team A', array_column($page['groups']['(No area)'] ?? [], 'name'), true));

    // Groups come in the query's order: named areas first, (No area) last.
    $keys = array_keys($page['groups']);
    if (in_array('(No area)', $keys, true)) {
        assertSame('(No area)', end($keys), 'the placeholder group is last');
    }
});

test('the team find box is the roster\'s word rule, over the name, the area and the division', function (): void {
    lt_fixture();
    $pdo = lt_pdo();
    $pdo->prepare("UPDATE team SET area = 'Bus Ops' WHERE name = 'LT Team A'")->execute();

    $teams = new TeamsPage($pdo);

    $byName = $teams->page(['q' => 'LT Team']);
    assertSame(['LT Team A'], array_column($byName['teams'], 'name'));
    assertSame('LT Team', $byName['q']);
    assertTrue($byName['all'] >= count($byName['teams']), 'the count of everything is kept for the sentence');

    $byArea = $teams->page(['q' => 'Bus Ops LT']);
    assertSame(['LT Team A'], array_column($byArea['teams'], 'name'), 'every word must land, on any of the three');

    $byDivision = $teams->page(['q' => 'Bus Ops Division LT']);
    assertSame(['LT Team A'], array_column($byDivision['teams'], 'name'));

    $nothing = $teams->page(['q' => 'zzqx-no-such-team']);
    assertSame([], $nothing['teams']);
    assertSame([], $nothing['groups']);
});

test('the Manage Teams screen draws one heading row per group and one find box', function (): void {
    lt_fixture();
    $pdo = lt_pdo();

    $page = (new TeamsPage($pdo))->page([]);
    $html = lt_render('teams', 'Manage Teams', [
        'user'    => new User(1, 1, 'LT0000000', Level::Admin, null, null, false, 'Later Admin'),
        'notices' => [],
        'teams'   => $page,
    ]);

    assertSame(count($page['groups']), substr_count($html, '<tr class="area">'), 'one heading per group');
    assertSame(1, substr_count($html, 'id="q" name="q"'), 'one find box');

    $empty = lt_render('teams', 'Manage Teams', [
        'user'    => new User(1, 1, 'LT0000000', Level::Admin, null, null, false, 'Later Admin'),
        'notices' => [],
        'teams'   => (new TeamsPage($pdo))->page(['q' => 'zzqx-no-such-team']),
    ]);
    assertSame(0, substr_count($empty, '<tr class="area">'));
    assertTrue(str_contains($empty, 'zzqx-no-such-team'), 'the empty state says what was looked for');

    lt_teardown();
});
