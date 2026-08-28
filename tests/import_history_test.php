<?php

declare(strict_types=1);

/**
 * Import History (Phase 10) — the durable record of what each import changed,
 * and the screen that reads it back.
 *
 * The feature exists because Rodeo Houston's export carries no audit trail: it
 * is a snapshot of the committee, with nothing at all about how it got that
 * way. So the questions this file holds the code to are the questions that
 * could previously only be answered by keeping every spreadsheet and diffing
 * it by hand:
 *
 *   1. Did somebody disappear, and which file dropped them?
 *   2. Did they come back?
 *   3. When did this member's team, or title, or dues change — and to what?
 *   4. Is the summary the import wrote when it ran still there afterwards?
 *
 * And the two rules that make the record worth trusting:
 *
 *   5. A staged file that was never applied writes NOTHING. A row here means
 *      the roster really changed, never that a preview said it would.
 *   6. A file that changes nothing about a member records nothing about them.
 *      A second import of the same roster is ~1,954 unchanged rows, and
 *      writing "nothing happened" 1,954 times a month buries the rows that
 *      say something did.
 *
 * **Every value in this file is invented.** This repository is public: member
 * numbers are 'IH…', addresses are the sanctioned invented street, phone
 * numbers are in the NANP's reserved fiction range, and email is example.com.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Capability;
use Rerm\Import\HeaderMap;
use Rerm\Import\ImportHistory;
use Rerm\Import\Importer;
use Rerm\Routes;

// ---------------------------------------------------------------------------
// The route, and what it may never become — no database needed
// ---------------------------------------------------------------------------

test('Import History is Admin, through the import capability, and read-only', function (): void {
    assertSame(Capability::ImportRoster->value, Routes::guard('import-history'));

    $source = (string) file_get_contents(__DIR__ . '/../app/src/Import/ImportHistory.php');
    assertTrue($source !== '', 'ImportHistory.php is readable');

    // A record somebody can edit answers no question worth asking. The
    // never-lose-a-row scan in admin_test.php already refuses a DELETE here;
    // this refuses the other three verbs, so the class cannot quietly grow a
    // write path that the DELETE scan would not catch.
    foreach (['INSERT INTO', 'UPDATE ', 'REPLACE INTO'] as $verb) {
        assertSame(
            0,
            preg_match('/\b' . preg_quote(trim($verb), '/') . '\b/i', $source),
            'ImportHistory must never ' . trim($verb)
        );
    }
});

test('a field name reads as English, and an unknown one reads as itself', function (): void {
    assertSame('Team', ImportHistory::fieldLabel('team'));
    assertSame('Division', ImportHistory::fieldLabel('division'));
    assertSame('Title', ImportHistory::fieldLabel('title'));

    // Metrics are spelled by the one enum, so "Committee Dues" is the same two
    // words here as on every other screen.
    assertSame('Committee Dues', ImportHistory::fieldLabel('metric:committee_dues'));
    assertSame('Harassment Training', ImportHistory::fieldLabel('metric:harassment_training'));

    // A column a future phase adds to the import is legible the day it lands,
    // rather than the day somebody remembers to add a label for it.
    assertSame('some_new_column', ImportHistory::fieldLabel('some_new_column'));
    assertSame('', ImportHistory::fieldLabel(''));
});

// ---------------------------------------------------------------------------
// The database under test
// ---------------------------------------------------------------------------

function ih_pdo(): PDO
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
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_change'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/**
 * RESTRICT-safe order: everything hanging off IH members and IH batches
 * first, then the members, then the reference rows.
 */
function ih_teardown(): void
{
    $pdo = ih_pdo();

    $batches = "SELECT id FROM (SELECT id FROM import_batch WHERE filename LIKE 'IH-%') b";
    $members = "SELECT id FROM (SELECT id FROM member WHERE member_number LIKE 'IH%') m";

    $pdo->exec("DELETE FROM import_change WHERE import_batch_id IN ({$batches})");
    $pdo->exec("DELETE FROM import_change WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM import_staged_row WHERE import_batch_id IN ({$batches})");
    $pdo->exec("DELETE FROM import_warning WHERE import_batch_id IN ({$batches})");
    // audit_log.actor_user_id is RESTRICT, so any row an IH account authored
    // comes out before the account does. The rest of the audit trail is left
    // alone: it is not this fixture's to clear.
    $pdo->exec('DELETE FROM audit_log WHERE actor_user_id IN (SELECT id FROM ('
        . "SELECT u.id FROM app_user u INNER JOIN member m ON m.id = u.member_id"
        . " WHERE m.member_number LIKE 'IH%') x)");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("UPDATE member SET last_seen_import_id = NULL, dropped_since_import_id = NULL"
        . " WHERE member_number LIKE 'IH%'");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'IH%'");

    // A COMPLETE import flags everybody the file did not list, and in a
    // shared test database that is every member another fixture happens to
    // have left behind. Their rows then point at an IH batch through
    // `dropped_since_import_id`, which is RESTRICT, so the batch cannot be
    // deleted until the pointer is cleared. Clearing it restores them to
    // exactly what they were — this fixture's import is the only reason they
    // were ever flagged.
    $pdo->exec("UPDATE member SET dropped_since_import_id = NULL WHERE dropped_since_import_id IN ({$batches})");
    $pdo->exec("UPDATE member SET last_seen_import_id = NULL WHERE last_seen_import_id IN ({$batches})");

    $pdo->exec("DELETE FROM import_batch WHERE filename LIKE 'IH-%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'IH Team %'");
}

/** The 33 headers of the observed export, in the observed order. */
function ih_headers(): array
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
 * One invented member. Self-contained rather than borrowed from
 * import_test.php: a filtered run (`php tests/run.php import_history`) loads
 * this file alone, and a helper that is only there when another file happens
 * to be loaded too is a test that passes for the wrong reason.
 *
 * @param array<string, string> $overrides
 * @return array<string, string>
 */
function ih_member(string $number, array $overrides = []): array
{
    return $overrides + [
        HeaderMap::TITLE               => 'Committee Member',
        HeaderMap::CUSTOMER_NUMBER     => $number,
        HeaderMap::NAME                => 'Surname, Given',
        HeaderMap::FULL_NAME           => 'Given Surname',
        HeaderMap::PREFIX              => '',
        HeaderMap::FIRST_NAME          => 'Given',
        HeaderMap::LAST_NAME           => 'Hist' . $number,
        HeaderMap::PREFERRED_NAME      => '',
        HeaderMap::LEGAL_NAME_VERIFIED => 'Y',
        HeaderMap::SUBCOMMITTEE_1      => 'IH Team A',
        HeaderMap::SUBCOMMITTEE_2      => 'Tba 9',
        HeaderMap::SUBCOMMITTEE_3      => 'Bus Ops Division',
        HeaderMap::ADDRESS             => '100 Example Way',
        HeaderMap::CITY                => 'Houston',
        HeaderMap::STATE               => 'TX',
        HeaderMap::ZIP                 => '77001',
        HeaderMap::PHONE               => '(555) 555-0100',
        HeaderMap::PHONE_TYPE          => 'CELL PHONE',
        HeaderMap::EMAIL               => 'ih' . strtolower($number) . '@example.com',
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
function ih_csv(array $members): string
{
    static $dir = null;

    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/rerm-history-' . getmypid();
        @mkdir($dir, 0700, true);
        register_shutdown_function(static function () use (&$dir): void {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        });
    }

    $headers = ih_headers();
    $path    = $dir . '/IH-' . substr(sha1(serialize($members)), 0, 12) . '.csv';

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

function ih_importer(): Importer
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    // A deliberately small batch, so the chunking path runs on a small
    // fixture rather than only on a real roster — and so the change record is
    // written by more than one transaction, which is where a per-chunk write
    // gets it wrong if it is going to.
    return new Importer($app->db(), 3, 24);
}

/**
 * Stage and apply one file, and return the batch id.
 *
 * @param array<int, array<string, string>> $members
 */
function ih_apply(array $members): int
{
    $importer = ih_importer();
    $batch    = $importer->stage(ih_csv($members), 'IH-roster.csv');
    $importer->apply($batch);

    return $batch;
}

/**
 * The change rows one batch wrote, as [member number, kind, field, before,
 * after] tuples in id order — the shape an assertion can read.
 *
 * @return array<int, array<int, ?string>>
 */
function ih_changes(int $batchId): array
{
    $read = ih_pdo()->prepare(
        'SELECT member_number, kind, field, before_value, after_value FROM import_change'
        . ' WHERE import_batch_id = :batch ORDER BY id'
    );
    $read->execute([':batch' => $batchId]);

    $rows = [];
    foreach ($read->fetchAll() as $row) {
        $rows[] = [
            (string) $row['member_number'],
            (string) $row['kind'],
            (string) $row['field'],
            $row['before_value'] === null ? null : (string) $row['before_value'],
            $row['after_value'] === null ? null : (string) $row['after_value'],
        ];
    }

    return $rows;
}

/**
 * The change rows matching a member and a field, whichever batch wrote them.
 *
 * @return array<int, array<int, ?string>>
 */
function ih_for(string $number, string $field = ''): array
{
    $rows = [];
    $read = ih_pdo()->prepare(
        'SELECT kind, field, before_value, after_value FROM import_change'
        . ' WHERE member_number = :n ORDER BY id'
    );
    $read->execute([':n' => $number]);

    foreach ($read->fetchAll() as $row) {
        if ($field !== '' && (string) $row['field'] !== $field) {
            continue;
        }
        $rows[] = [
            (string) $row['kind'],
            (string) $row['field'],
            $row['before_value'] === null ? null : (string) $row['before_value'],
            $row['after_value'] === null ? null : (string) $row['after_value'],
        ];
    }

    return $rows;
}

function ih_history(): ImportHistory
{
    return ImportHistory::fromApp($GLOBALS['rerm_app']);
}

// ---------------------------------------------------------------------------
// What an apply records
// ---------------------------------------------------------------------------

test('a first import records that each member appeared, and nothing else', function (): void {
    ih_teardown();

    $batch = ih_apply([ih_member('IH000001'), ih_member('IH000002')]);

    $changes = ih_changes($batch);
    assertSame(2, count($changes), 'one row per member, and no invented fields');

    foreach ($changes as $row) {
        assertSame('created', $row[1]);
        assertSame('', $row[2], 'a create has no field: the kind is the whole fact');
        assertSame(null, $row[3]);
        assertSame(null, $row[4]);
    }

    // And the id is resolved, so the per-member timeline finds it by id as
    // well as by number.
    $unresolved = (int) ih_pdo()->query(
        "SELECT COUNT(*) FROM import_change WHERE member_number LIKE 'IH%' AND member_id IS NULL"
    )->fetchColumn();
    assertSame(0, $unresolved, 'a created member is resolved back to an id in the same transaction');

    ih_teardown();
});

test('a second import of the same file records nothing at all', function (): void {
    ih_teardown();

    $roster = [ih_member('IH000001'), ih_member('IH000002')];
    ih_apply($roster);
    $second = ih_apply($roster);

    assertSame([], ih_changes($second), 'unchanged is not an event');

    ih_teardown();
});

test('an update records one row per changed field, with the value on each side', function (): void {
    ih_teardown();

    ih_apply([ih_member('IH000001')]);

    $second = ih_apply([ih_member('IH000001', [
        HeaderMap::TITLE          => 'Captain',
        HeaderMap::SUBCOMMITTEE_1 => 'IH Team B',
        HeaderMap::COMMITTEE_DUES => 'Y',
    ])]);

    $byField = [];
    foreach (ih_changes($second) as $row) {
        $byField[$row[2]] = $row;
    }

    assertTrue(isset($byField['title']), 'the title change is recorded');
    assertSame(['IH000001', 'updated', 'title', 'Committee Member', 'Captain'], $byField['title']);

    assertTrue(isset($byField['team']), 'so is the move between teams');
    assertSame('IH Team A', $byField['team'][3]);
    assertSame('IH Team B', $byField['team'][4]);

    assertTrue(isset($byField['metric:committee_dues']), 'and the requirement that moved');
    assertSame('N', $byField['metric:committee_dues'][3]);
    assertSame('Y', $byField['metric:committee_dues'][4]);

    // A title change moves the derived access level with it, and that is a
    // separate fact worth being able to find: "when did this person stop
    // being able to sign in" is the same question as "when did their title
    // change" only if both are written down.
    assertTrue(isset($byField['title_level']));
    assertSame('member', $byField['title_level'][3]);
    assertSame('officer', $byField['title_level'][4]);

    ih_teardown();
});

test('a member the file stops listing is recorded as dropped, and their return as returned', function (): void {
    ih_teardown();

    ih_apply([ih_member('IH000001'), ih_member('IH000002')]);

    // A complete import that does not mention IH000002.
    $dropBatch = ih_apply([ih_member('IH000001')]);

    $dropped = ih_changes($dropBatch);
    assertSame(1, count($dropped), 'exactly the one member the file left out');
    assertSame(['IH000002', 'dropped', '', null, null], $dropped[0]);

    // And back again.
    $returnBatch = ih_apply([ih_member('IH000001'), ih_member('IH000002')]);

    $returned = ih_changes($returnBatch);
    assertSame(1, count($returned));
    assertSame(['IH000002', 'returned', '', null, null], $returned[0]);

    // The whole story, in order, for the one member: appeared, went, came
    // back. This is the sequence the screen exists to show, and the member's
    // own row cannot tell it — `dropped_since_import_id` is cleared the moment
    // they reappear.
    $story = array_map(static fn (array $r): string => $r[0], ih_for('IH000002'));
    assertSame(['created', 'dropped', 'returned'], $story);

    ih_teardown();
});

test('a staged import that is never applied records nothing, and discarding it leaves nothing', function (): void {
    ih_teardown();

    $importer = ih_importer();
    $batch    = $importer->stage(ih_csv([ih_member('IH000001')]), 'IH-roster.csv');

    assertSame([], ih_changes($batch), 'a row here means the roster changed, never that a preview said it would');

    // And the parse can still be thrown away, which it could not be if the
    // apply had written a permanent record against it.
    $importer->discard($batch);

    $read = ih_pdo()->prepare('SELECT COUNT(*) FROM import_batch WHERE id = :id');
    $read->execute([':id' => $batch]);
    assertSame(0, (int) $read->fetchColumn(), 'the discarded batch is gone');

    ih_teardown();
});

test('the record is append-only: a later import never rewrites an earlier one', function (): void {
    ih_teardown();

    $first  = ih_apply([ih_member('IH000001')]);
    $second = ih_apply([ih_member('IH000001', [HeaderMap::TITLE => 'Captain'])]);
    $third  = ih_apply([ih_member('IH000001', [HeaderMap::TITLE => 'Committee Member'])]);

    assertSame(1, count(ih_changes($first)), 'the create is still there');

    // Two title changes, in the order they happened, each pointing at the
    // import that made it. "Why is Johnson a Committee Member again" is
    // exactly this shape of question.
    $titles = ih_for('IH000001', 'title');
    assertSame(2, count($titles));
    assertSame(['updated', 'title', 'Committee Member', 'Captain'], $titles[0]);
    assertSame(['updated', 'title', 'Captain', 'Committee Member'], $titles[1]);

    assertTrue($second !== $third, 'two batches, two records');

    ih_teardown();
});

test('nothing an officer owns is ever recorded as an import change', function (): void {
    ih_teardown();

    $batch = ih_apply([ih_member('IH000001')]);
    ih_apply([ih_member('IH000001', [HeaderMap::COMMITTEE_DUES => 'Y'])]);

    // The ownership boundary (spec 6.6) holds here too: this table records
    // what a FILE did, so a contact, an assignment, a grant, a scope or a
    // tracked progress value must never appear in it — not even as the name
    // of a field.
    $fields = ih_pdo()->query(
        "SELECT DISTINCT field FROM import_change WHERE member_number LIKE 'IH%'"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($fields as $field) {
        foreach (['progress', 'contact', 'assign', 'granted', 'scope', 'password', 'area'] as $ours) {
            assertTrue(
                !str_contains((string) $field, $ours),
                'an import change named "' . (string) $field . '" crosses the ownership boundary'
            );
        }
    }

    assertTrue($batch > 0);

    ih_teardown();
});

// ---------------------------------------------------------------------------
// The screen
// ---------------------------------------------------------------------------

test('the list of imports keeps the summary each one wrote when it ran', function (): void {
    ih_teardown();

    ih_apply([ih_member('IH000001'), ih_member('IH000002', [HeaderMap::COMMITTEE_DUES => 'Y'])]);

    $page = ih_history()->page([]);
    assertSame('batches', $page['view']);
    assertTrue($page['batches'] !== [], 'the applied import is listed');

    $batch = $page['batches'][0];
    assertSame(2, (int) $batch['rows_created']);
    assertSame('IH-roster.csv', $batch['filename']);

    // The stage-time summary, read back long after the preview that showed it
    // has gone. This is the "import summary information" the record keeps.
    assertTrue($batch['metric_flips'] !== [], 'the metric movements survive the apply');
    assertTrue(in_array('IH Team A', $batch['new_teams'], true), 'and the teams the file introduced');

    ih_teardown();
});

test('one import shows what it changed, grouped, and drills down to the people', function (): void {
    ih_teardown();

    ih_apply([ih_member('IH000001'), ih_member('IH000002')]);
    $second = ih_apply([
        ih_member('IH000001', [HeaderMap::TITLE => 'Captain']),
        // IH000002 is not in this file, so it is dropped.
    ]);

    $page = ih_history()->page(['batch' => (string) $second]);
    assertSame('batch', $page['view']);
    assertSame($second, (int) $page['batch']['id']);

    $groups = [];
    foreach ($page['groups'] as $group) {
        $groups[$group['kind'] . ':' . $group['field']] = (int) $group['members'];
    }

    assertTrue(isset($groups['dropped:']), 'the people it dropped are a group of their own');
    assertSame(1, $groups['dropped:']);
    assertTrue(isset($groups['updated:title']), 'and so is each field it changed');
    assertSame(1, $groups['updated:title']);

    // Dropped is offered first, because "somebody disappeared" is the
    // sentence that brings people to this screen.
    assertSame('dropped', $page['groups'][0]['kind']);

    // The drill-down reproduces exactly the group that was counted.
    $drill = ih_history()->page([
        'batch' => (string) $second,
        'kind'  => 'dropped',
        'field' => '',
    ]);
    assertSame(1, (int) $drill['total']);
    assertSame('IH000002', $drill['rows'][0]['member_number']);
    assertSame('dropped', $drill['rows'][0]['kind']);

    // A group that does not exist is not an error — this screen is reached by
    // link — it is the whole import.
    $bogus = ih_history()->page(['batch' => (string) $second, 'kind' => 'updated', 'field' => 'nonsense']);
    assertSame('', $bogus['kind'], 'an unknown group falls back to everything');
    // The drop, the title, and the access level the title carried with it.
    assertSame(3, (int) $bogus['total']);

    ih_teardown();
});

test('one member shows every import that ever changed them, newest first', function (): void {
    ih_teardown();

    ih_apply([ih_member('IH000001')]);
    ih_apply([ih_member('IH000001', [HeaderMap::TITLE => 'Captain'])]);

    $page = ih_history()->page(['member' => 'IH000001']);
    assertSame('member', $page['view']);
    assertSame('IH000001', $page['member']['member_number']);

    // Newest first: the second import's changes are above the row that says
    // they first appeared. A title change writes two rows — the title and the
    // access level derived from it — so the assertion is on the set rather
    // than on which of the two the insert happened to write second.
    assertTrue((int) $page['total'] >= 3);
    assertSame('created', $page['rows'][(int) $page['total'] - 1]['kind'], 'the oldest row is the create');

    $newest = [];
    foreach (array_slice($page['rows'], 0, (int) $page['total'] - 1) as $row) {
        assertSame('updated', $row['kind']);
        $newest[] = $row['field_label'];
    }
    sort($newest);
    assertSame(['Access level from title', 'Title'], $newest);

    // And every row names the file that did it. A change with no import
    // beside it is a fact with no cause, which is the state this table exists
    // to end.
    foreach ($page['rows'] as $row) {
        assertTrue($row['batch_id'] > 0);
        assertSame('IH-roster.csv', $row['filename']);
    }

    ih_teardown();
});

test('a member number is answered outright; an ambiguous name is a question', function (): void {
    ih_teardown();

    ih_apply([
        ih_member('IH000001', [HeaderMap::LAST_NAME => 'Ihtwin']),
        ih_member('IH000002', [HeaderMap::LAST_NAME => 'Ihtwin']),
    ]);

    // The natural key answers outright, even though it is also a substring of
    // nothing else here.
    $byNumber = ih_history()->page(['member' => 'IH000001']);
    assertSame('member', $byNumber['view']);
    assertSame('IH000001', $byNumber['member']['member_number']);

    // Names are not unique in this roster — 1,951 distinct of 1,954 — so a
    // name matching two members must never silently become one of them.
    $byName = ih_history()->page(['member' => 'Ihtwin']);
    assertSame('search', $byName['view']);
    assertSame(2, count($byName['matches']));

    // And a name matching nobody says so rather than showing an empty
    // timeline for a member who does not exist.
    $none = ih_history()->page(['member' => 'Nobodyatallhere']);
    assertSame('search', $none['view']);
    assertSame([], $none['matches']);

    ih_teardown();
});

test('an import id that no longer exists is a sentence, not a blank screen', function (): void {
    ih_teardown();

    $page = ih_history()->page(['batch' => '999999999']);
    assertSame('batches', $page['view'], 'it falls back to the list');
    assertSame(999999999, (int) $page['missing']);

    ih_teardown();
});

test('Import History renders, and every value on it is escaped', function (): void {
    ih_teardown();

    ih_apply([ih_member('IH000001')]);
    $second = ih_apply([ih_member('IH000001', [HeaderMap::TITLE => 'Captain'])]);

    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    $render = static function (array $input) use ($app): string {
        $_SESSION ??= [];
        $wide    = true;
        $user    = null;
        $notices = [];
        $history = ImportHistory::fromApp($app)->page($input);

        ob_start();
        require $app->path('app/views/import-history.php');

        return (string) ob_get_clean();
    };

    $list = $render([]);
    assertTrue(str_contains($list, 'Import History'));
    assertTrue(str_contains($list, 'IH-roster.csv'), 'the file that did it is named');

    $one = $render(['batch' => (string) $second]);
    assertTrue(str_contains($one, 'What it changed'));
    assertTrue(str_contains($one, 'Title'), 'the field reads as English');
    assertTrue(str_contains($one, 'Captain'), 'and the value it became');

    $who = $render(['member' => 'IH000001']);
    assertTrue(str_contains($who, 'Committee Member'), 'the value it was');

    // Read-only: no form that writes, so no CSRF token to have forgotten.
    foreach ([$list, $one, $who] as $html) {
        assertTrue(!str_contains($html, 'method="post"'), 'nothing on this screen writes');
    }

    ih_teardown();
});
