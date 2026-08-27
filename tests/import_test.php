<?php

declare(strict_types=1);

/**
 * The roster import (spec 6).
 *
 * **Every value in this file is invented.** Not one name, member number,
 * address, phone number or email address comes from a real roster, and none
 * ever may: this repository is public, a fixture is readable by anyone who can
 * clone it, and copying a row out of the real export because a real example is
 * more convincing is exactly how 1,950 volunteers' home addresses end up on
 * the internet. Addresses are `example.com`; phone numbers are in the NANP's
 * reserved fiction range. CI fails the build on either.
 *
 * The fixtures are GENERATED — a CSV written here, and a genuine BIFF8
 * workbook written by BiffFixture — so the binary path Rodeo Houston actually
 * sends is exercised without a spreadsheet ever being committed.
 *
 * What is asserted here, in order of how much it would cost to get wrong:
 *
 *   1. An import never overwrites what we know (spec 6.6). Grants, contact
 *      history, assignments, progress and a team's area survive it.
 *   2. The one deliberate exception — N to Y clears progress — happens, is
 *      logged, and does NOT happen when the value stays N.
 *   3. Nothing is deleted. Absence is a flag; a member who returns is
 *      un-flagged; the master administrator is invisible to all of it.
 *   4. Every one of the ten warnings in spec 6.4 fires.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/BiffFixture.php';

use Rerm\App;
use Rerm\Import\HeaderMap;
use Rerm\Import\ImportException;
use Rerm\Import\Importer;
use Rerm\Import\RowNormaliser;
use Rerm\Import\Warnings;

// ---------------------------------------------------------------------------
// Fixtures — generated, never committed
// ---------------------------------------------------------------------------

/** The 33 headers of the observed export, in the observed order. */
function import_headers(): array
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
 * One invented member, keyed by header name. Override what a test cares about
 * and ignore the other thirty columns.
 *
 * @param array<string, string> $overrides
 *
 * @return array<string, string>
 */
function import_member(string $number, array $overrides = []): array
{
    return $overrides + [
        HeaderMap::TITLE               => 'Committee Member',
        HeaderMap::CUSTOMER_NUMBER     => $number,
        HeaderMap::NAME                => 'Surname, Given',
        HeaderMap::FULL_NAME           => 'Given Surname',
        HeaderMap::PREFIX              => '',
        HeaderMap::FIRST_NAME          => 'Given',
        HeaderMap::LAST_NAME           => 'Surname' . $number,
        HeaderMap::PREFERRED_NAME      => '',
        HeaderMap::LEGAL_NAME_VERIFIED => 'Y',
        HeaderMap::SUBCOMMITTEE_1      => 'Sample Team 1',
        HeaderMap::SUBCOMMITTEE_2      => 'Tba 9',
        HeaderMap::SUBCOMMITTEE_3      => 'Bus Ops Division',
        HeaderMap::ADDRESS             => '100 Example Way',
        HeaderMap::CITY                => 'Houston',
        HeaderMap::STATE               => 'TX',
        HeaderMap::ZIP                 => '77001',
        HeaderMap::PHONE               => '(555) 555-0100',
        HeaderMap::PHONE_TYPE          => 'CELL PHONE',
        HeaderMap::EMAIL               => 'member' . $number . '@example.com',
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

/**
 * Writes a roster CSV and returns its path.
 *
 * @param array<int, array<string, string>> $members
 * @param array<int, string>|null           $headers a deliberately odd header row
 */
function import_csv(array $members, ?array $headers = null): string
{
    $headers ??= import_headers();
    $path      = import_scratch('roster-' . substr(sha1(serialize([$members, $headers])), 0, 12) . '.csv');

    $handle = fopen($path, 'wb');
    fputcsv($handle, $headers);
    foreach ($members as $member) {
        $row = [];
        foreach ($headers as $header) {
            $row[] = $member[trim($header)] ?? '';
        }
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

/**
 * The same roster as a genuine .xls — OLE2 container, BIFF8 records — which is
 * what Rodeo Houston actually sends.
 *
 * @param array<int, array<string, string>> $members
 */
function import_xls(array $members): string
{
    $headers = import_headers();
    $fixture = new BiffFixture('Full Roster');

    foreach ($headers as $column => $header) {
        $fixture->label(0, $column, $header);
    }

    foreach ($members as $index => $member) {
        foreach ($headers as $column => $header) {
            $value = $member[$header] ?? '';
            if ($value === '') {
                $fixture->blank($index + 1, $column);
                continue;
            }
            $fixture->label($index + 1, $column, $value);
        }
    }

    return $fixture->write(import_scratch('roster-' . substr(sha1(serialize($members)), 0, 12) . '.xls'));
}

/** A scratch path that cleans itself up at the end of the run. */
function import_scratch(string $name): string
{
    static $dir = null;

    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/rerm-import-' . getmypid();
        @mkdir($dir, 0700, true);
        register_shutdown_function(static function () use (&$dir): void {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        });
    }

    return $dir . '/' . $name;
}

// ---------------------------------------------------------------------------
// The database under test
// ---------------------------------------------------------------------------

function import_pdo(): PDO
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
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_staged_row'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/**
 * Back to the state migration 003 leaves: the seeded reference data, the
 * master administrator, and no roster at all.
 *
 * Called at the start of every database test here, and once more at the end of
 * the file — tests/schema_test.php runs after this one and asserts that
 * exactly five divisions exist and that (No Division) is the only placeholder.
 *
 * Order matters, and it is the RESTRICT rules that decide it: nothing
 * referencing `member` may still exist when the members go, and every foreign
 * key pointing at one is RESTRICT rather than CASCADE precisely so that a
 * mistake here fails loudly instead of taking contact history with it.
 */
function import_reset(): void
{
    $pdo = import_pdo();

    // MySQL refuses a DELETE or UPDATE whose subquery reads the table it is
    // writing to, so every id list is read first and passed back in.
    $systemMembers = array_map('intval', $pdo->query('SELECT id FROM member WHERE is_system = 1')->fetchAll(PDO::FETCH_COLUMN));
    $systemUsers   = array_map('intval', $pdo->query(
        'SELECT u.id FROM app_user u INNER JOIN member m ON m.id = u.member_id WHERE m.is_system = 1'
    )->fetchAll(PDO::FETCH_COLUMN));

    $members = $systemMembers === [] ? '0' : implode(',', $systemMembers);
    $users   = $systemUsers === [] ? '0' : implode(',', $systemUsers);

    // app_user.granted_by points at app_user, so a designation made by an
    // account about to be deleted blocks the delete. The seeded master
    // administrator's is already NULL — migration 003 is explicit that there
    // was nobody to attribute the first grant to.
    $pdo->exec('UPDATE app_user SET granted_by = NULL');
    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IS NOT NULL AND actor_user_id NOT IN ({$users})");
    $pdo->prepare('DELETE FROM audit_log WHERE action LIKE :like')->execute([':like' => 'import%']);

    $pdo->exec('DELETE FROM contact_log');
    $pdo->exec('DELETE FROM assignment');
    $pdo->exec('DELETE FROM member_metric');
    $pdo->exec('DELETE FROM import_staged_row');
    $pdo->exec('DELETE FROM import_warning');
    $pdo->exec('UPDATE member SET last_seen_import_id = NULL, absent_since_import_id = NULL, purged_at = NULL');
    $pdo->exec('DELETE FROM import_batch');

    $pdo->exec("DELETE FROM app_user WHERE member_id NOT IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE id NOT IN ({$members})");
    $pdo->exec('DELETE FROM team');
    $pdo->exec("DELETE FROM division WHERE name NOT IN ("
        . "'(No Division)', 'Satellites Division', 'Bus Ops Division', "
        . "'Logistics Division', 'Member Services Division')");
}

function import_importer(): Importer
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    // A deliberately small batch so the chunking path runs on a ten-row
    // fixture rather than only on a real roster.
    return new Importer($app->db(), 3, 24);
}

/**
 * Stage and apply in one call, for the tests that are about the outcome
 * rather than about the two steps.
 *
 * @param array<int, array<string, string>> $members
 *
 * @return array<string, int>
 */
function import_apply(array $members, string $mode = Importer::MODE_COMPLETE, ?int $teamId = null): array
{
    $importer = import_importer();
    $batch    = $importer->stage(import_csv($members), 'roster.csv', $mode, $teamId);

    return $importer->apply($batch);
}

/** @return array<string, mixed>|null */
function import_member_row(string $number): ?array
{
    $read = import_pdo()->prepare(
        'SELECT m.*, d.name AS division_name, t.name AS team_name '
        . 'FROM member m INNER JOIN division d ON d.id = m.division_id '
        . 'LEFT JOIN team t ON t.id = m.team_id WHERE m.member_number = :n'
    );
    $read->execute([':n' => $number]);
    $row = $read->fetch();

    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function import_metric_row(string $number, string $metric): ?array
{
    $read = import_pdo()->prepare(
        'SELECT mm.* FROM member_metric mm INNER JOIN member m ON m.id = mm.member_id '
        . 'WHERE m.member_number = :n AND mm.metric = :metric'
    );
    $read->execute([':n' => $number, ':metric' => $metric]);
    $row = $read->fetch();

    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function import_account(string $number): ?array
{
    $read = import_pdo()->prepare(
        'SELECT u.* FROM app_user u INNER JOIN member m ON m.id = u.member_id WHERE m.member_number = :n'
    );
    $read->execute([':n' => $number]);
    $row = $read->fetch();

    return is_array($row) ? $row : null;
}

function import_active_year(): int
{
    return (int) import_pdo()->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();
}

// ===========================================================================
// HeaderMap — by name, never by position
// ===========================================================================

test('headers are matched by name, so a reordered file imports identically', function (): void {
    // The whole point. Rodeo Houston's export has 33 columns today; the day
    // one is inserted, a position-matched import reads Show Dues out of the
    // Committee Dues column and nothing throws.
    $reversed = array_reverse(import_headers());
    $map      = HeaderMap::fromHeaderRow($reversed);

    assertSame(0, $map->index(HeaderMap::IN_OTHER_COMMITTEES));
    assertSame(32, $map->index(HeaderMap::TITLE));

    $row = array_fill(0, 33, '');
    $row[(int) $map->index(HeaderMap::CUSTOMER_NUMBER)] = '1234567';
    assertSame('1234567', $map->value($row, HeaderMap::CUSTOMER_NUMBER));
});

test('header matching ignores case and surrounding whitespace', function (): void {
    $map = HeaderMap::fromHeaderRow([
        '  customer number ', "TITLE\n", 'SubCommittee   1', "\u{FEFF}Primary Email",
    ]);

    assertSame(0, $map->index(HeaderMap::CUSTOMER_NUMBER));
    assertSame(1, $map->index(HeaderMap::TITLE));
    assertSame(2, $map->index(HeaderMap::SUBCOMMITTEE_1));
    assertSame(3, $map->index(HeaderMap::EMAIL));
});

test('a file missing a required column is refused, and the message lists what it found', function (): void {
    assertThrows(
        static function (): void {
            HeaderMap::fromHeaderRow(['Member Number', 'Title', 'Subcommittee 1']);
        },
        'Customer Number',
        'the spec calls it Member Number; the column is Customer Number'
    );

    try {
        HeaderMap::fromHeaderRow(['Member Number', 'Nickname']);
        throw new RuntimeException('expected a refusal');
    } catch (ImportException $e) {
        // Without this list the message is "your file is wrong" and the Admin
        // has no way to see that they uploaded last year's export.
        assertTrue(str_contains($e->getMessage(), 'Member Number'), 'the found headers must be listed');
        assertTrue(str_contains($e->getMessage(), 'Nickname'), 'the found headers must be listed');
        assertTrue(str_contains($e->getMessage(), 'Title'), 'every missing column must be named');
    }
});

test('an optional column the file does not carry reads as blank, not as a failure', function (): void {
    // The six dead columns are exactly the ones a future export is likeliest
    // to drop, and losing Badge Issue Date must not lose the roster.
    $map = HeaderMap::fromHeaderRow([HeaderMap::CUSTOMER_NUMBER, HeaderMap::TITLE, HeaderMap::SUBCOMMITTEE_1]);

    assertSame(false, $map->has(HeaderMap::BADGE_ISSUE_DATE));
    assertSame('', $map->value(['1', '2', '3'], HeaderMap::BADGE_ISSUE_DATE));
    assertTrue(in_array(HeaderMap::BADGE_ISSUE_DATE, $map->absent(), true));
});

test('a duplicated header takes the first occurrence and says so', function (): void {
    $map = HeaderMap::fromHeaderRow([
        HeaderMap::CUSTOMER_NUMBER, HeaderMap::TITLE, HeaderMap::SUBCOMMITTEE_1, 'customer number',
    ]);

    assertSame(0, $map->index(HeaderMap::CUSTOMER_NUMBER));
    assertSame(['customer number'], $map->duplicates());
});

test('Subcommittee 2 is a known column and is not imported', function (): void {
    // Junk in the observed export (`Tba 9` x1898). Known, so it is not
    // reported as a surprise; absent from KNOWN, so nothing reads it.
    $map = HeaderMap::fromHeaderRow(import_headers());

    assertSame([], $map->unrecognised());
    assertTrue(!in_array(HeaderMap::SUBCOMMITTEE_2, HeaderMap::KNOWN, true));
});

// ===========================================================================
// RowNormaliser
// ===========================================================================

test('sentinel text becomes blank', function (): void {
    foreach (['N/A', 'n/a', 'NA', 'Na', 'NONE', 'None', 'none', 'NULL', '-', ' N/A '] as $sentinel) {
        assertSame('', RowNormaliser::sentinel($sentinel), var_export($sentinel, true));
    }

    // And nothing else does. "Nan" is a name; "Nathan" starts with Na.
    assertSame('Nan', RowNormaliser::sentinel('Nan'));
    assertSame('Nathan', RowNormaliser::sentinel(' Nathan '));
    assertSame('Dr.', RowNormaliser::sentinel('Dr.'));
});

test('sentinel normalisation reaches Prefix and Preferred Name, and nothing else', function (): void {
    assertSame(
        [HeaderMap::PREFIX, HeaderMap::PREFERRED_NAME],
        RowNormaliser::SENTINEL_COLUMNS
    );

    $map = HeaderMap::fromHeaderRow(import_headers());
    $row = [];
    foreach (import_headers() as $index => $header) {
        $row[$index] = ($header === HeaderMap::PREFIX
            || $header === HeaderMap::PREFERRED_NAME
            || $header === HeaderMap::LAST_NAME
            || $header === HeaderMap::SHOW_DUES) ? 'N/A' : '';
    }
    $row[(int) $map->index(HeaderMap::CUSTOMER_NUMBER)] = '1234567';

    $values = RowNormaliser::normalise($map, $row);

    // A member whose preferred name is N/A must not be greeted as "N/A Smith".
    assertSame('', $values['prefix']);
    assertSame('', $values['preferred_name']);
    // A surname is never guessed at.
    assertSame('N/A', $values['last_name']);
    // And a metric cell holding prose is a warning, not something to discard.
    assertSame('unknown', RowNormaliser::metric('N/A'));
    assertSame(true, RowNormaliser::metricIsUnexpected('N/A'));
});

test('a phone keeps its imported spelling and gains an E.164 form', function (): void {
    $parsed = RowNormaliser::phone('(555) 555-0100');

    // The display string is what the member recognises and what an officer
    // reads aloud; the E.164 form is what tel: and sms: dial.
    assertSame('(555) 555-0100', $parsed['display']);
    assertSame('+15555550100', $parsed['e164']);

    assertSame('+15555550142', RowNormaliser::phone('555-555-0142')['e164']);
    assertSame('+15555550142', RowNormaliser::phone('1 (555) 555-0142')['e164']);
    assertSame('+445555550142', RowNormaliser::phone('+44 555 555 0142')['e164']);
});

test('a number that cannot be normalised is kept as text rather than guessed at', function (): void {
    foreach (['ask his wife', '12345', '(555) 555-010'] as $unparseable) {
        $parsed = RowNormaliser::phone($unparseable);
        assertSame($unparseable, $parsed['display'], 'the imported string is never discarded');
        assertSame(null, $parsed['e164'], var_export($unparseable, true));
    }

    // A link that dials the wrong number is worse than no link.
    assertSame('', RowNormaliser::phone('')['display']);
    assertSame(null, RowNormaliser::phone('')['e164']);
});

test('only CELL PHONE can be texted', function (): void {
    assertSame(true, RowNormaliser::isCellPhone('CELL PHONE'));
    assertSame(true, RowNormaliser::isCellPhone(' cell phone '));
    // 116 of 1,954 in the sample. Offering them a text produces a message
    // that goes nowhere and an officer who believes they made contact.
    assertSame(false, RowNormaliser::isCellPhone('HOME'));
    assertSame(false, RowNormaliser::isCellPhone('BUSINESS'));
    assertSame(false, RowNormaliser::isCellPhone(''));
});

test('metrics are tri-state, and blank is not N', function (): void {
    assertSame('Y', RowNormaliser::metric('Y'));
    assertSame('Y', RowNormaliser::metric(' y '));
    assertSame('N', RowNormaliser::metric('N'));

    // 1,716 of 1,954 harassment-training cells are blank. Scoring blank as a
    // failure would show the committee at 7% compliance on something nobody
    // is tracking yet.
    assertSame('unknown', RowNormaliser::metric(''));
    assertSame(false, RowNormaliser::metricIsUnexpected(''));

    // Anything else is unknown AND a warning (spec 6.1).
    assertSame('unknown', RowNormaliser::metric('Yes'));
    assertSame(true, RowNormaliser::metricIsUnexpected('Yes'));
});

test('an over-long value is cut to its column rather than aborting the import', function (): void {
    // The connection runs STRICT_ALL_TABLES, so a value too long RAISES. That
    // is what stops a member number being silently shortened into a different
    // member; the price is that free text has to be cut here instead.
    $long = str_repeat('a', 400);

    assertSame(64, mb_strlen(RowNormaliser::text($long, RowNormaliser::WIDTHS['first_name'])));
    assertSame(255, mb_strlen(RowNormaliser::text($long, RowNormaliser::WIDTHS['email'])));

    // And the key is NOT in the width table, because shortening it would
    // match or create the wrong person.
    assertTrue(!isset(RowNormaliser::WIDTHS['member_number']));
});

test('the four scored metrics are the four, and harassment training is not one', function (): void {
    assertSame(
        ['hlsr_dues', 'committee_dues', 'indemnity', 'background_check'],
        RowNormaliser::SCORED_METRICS
    );
    assertTrue(isset(RowNormaliser::METRICS['harassment_training']), 'it is imported');
    assertTrue(
        !in_array('harassment_training', RowNormaliser::SCORED_METRICS, true),
        'and it is not scored — OI-3'
    );
});

// ===========================================================================
// The round trip
// ===========================================================================

test('a roster imports, and a second import of the same file changes nothing', function (): void {
    import_reset();

    $members = [];
    for ($i = 0; $i < 10; $i++) {
        $members[] = import_member((string) (1000000 + $i));
    }

    $first = import_apply($members);
    assertSame(10, $first['created']);
    assertSame(0, $first['updated']);

    // The done-when criterion: a second import of the same file reports
    // everything unchanged and creates nobody. Anything else means a value is
    // not round-tripping — an empty string stored where a NULL was parsed is
    // the usual culprit, and it reports 1,954 phantom changes forever.
    $second = import_apply($members);
    assertSame(0, $second['created']);
    assertSame(0, $second['updated']);
    assertSame(10, $second['unchanged']);

    assertSame(50, (int) import_pdo()->query('SELECT COUNT(*) FROM member_metric')->fetchColumn(),
        'five metrics per member, including the one that is not scored');
});

test('a real .xls imports exactly as the same rows in a .csv do', function (): void {
    import_reset();

    // Rodeo Houston sends a legacy .xls — OLE2 container, BIFF8 records — so
    // this path is the one that matters, and Spreadsheet::open() picks it by
    // reading the first eight bytes rather than by the file's name.
    $members  = [import_member('2000001'), import_member('2000002', [HeaderMap::TITLE => 'Captain'])];
    $importer = import_importer();

    $batch = $importer->stage(import_xls($members), 'roster.xls');
    $importer->apply($batch);

    assertSame('Captain', (string) import_member_row('2000002')['title']);
    assertSame('officer', (string) import_member_row('2000002')['title_level']);
    assertSame('+15555550100', (string) import_member_row('2000001')['phone_e164']);
});

test('staging writes nothing to the roster, and the apply is a separate act', function (): void {
    import_reset();

    $importer = import_importer();
    $batch    = $importer->stage(import_csv([import_member('3000001')]), 'roster.csv');

    // The entire safety property of this screen. One click can rewrite 1,954
    // rows, so the click that reads the file is not the click that writes it.
    assertSame(null, import_member_row('3000001'), 'staging must not touch member');

    $preview = $importer->preview($batch);
    assertSame(1, $preview['counts']['create']);
    assertSame(false, $preview['applied']);

    $importer->apply($batch);
    assertTrue(import_member_row('3000001') !== null);

    // And a batch cannot be applied twice: the diff was computed against a
    // roster that has since changed.
    assertThrows(
        static fn () => import_importer()->apply($batch),
        'already applied'
    );
});

test('a discarded batch leaves nothing behind', function (): void {
    import_reset();

    $importer = import_importer();
    $batch    = $importer->stage(import_csv([import_member('3100001')]), 'roster.csv');
    $importer->discard($batch);

    assertThrows(static fn () => import_importer()->preview($batch), 'no import batch');
    assertSame(0, (int) import_pdo()->query('SELECT COUNT(*) FROM import_staged_row')->fetchColumn());
    assertSame(0, (int) import_pdo()->query('SELECT COUNT(*) FROM import_warning')->fetchColumn());
});

// ===========================================================================
// What an import owns, and what it must never touch (spec 6.6)
// ===========================================================================

test('an import overwrites everything Rodeo Houston owns', function (): void {
    import_reset();

    import_apply([import_member('4000001')]);

    import_apply([import_member('4000001', [
        HeaderMap::TITLE          => 'Captain',
        HeaderMap::FIRST_NAME     => 'Changed',
        HeaderMap::ADDRESS        => '200 Example Way',
        HeaderMap::PHONE          => '(555) 555-0177',
        HeaderMap::EMAIL          => 'changed@example.com',
        HeaderMap::SUBCOMMITTEE_1 => 'Other Team 2',
        HeaderMap::SUBCOMMITTEE_3 => 'Logistics Division',
        HeaderMap::SHOW_DUES      => 'Y',
        HeaderMap::ROOKIE         => 'Y',
    ])]);

    $member = import_member_row('4000001');
    assertSame('Captain', (string) $member['title']);
    assertSame('officer', (string) $member['title_level']);
    assertSame('Changed', (string) $member['first_name']);
    assertSame('200 Example Way', (string) $member['address']);
    assertSame('+15555550177', (string) $member['phone_e164']);
    assertSame('changed@example.com', (string) $member['email']);
    assertSame('Other Team 2', (string) $member['team_name']);
    assertSame('Logistics Division', (string) $member['division_name']);
    assertSame(1, (int) $member['is_rookie']);
    assertSame('Y', (string) import_metric_row('4000001', 'hlsr_dues')['imported_value']);
});

test('an import never writes a grant, a contact, an assignment or a team area', function (): void {
    import_reset();

    $pdo  = import_pdo();
    $year = import_active_year();

    import_apply([
        import_member('5000001', [HeaderMap::TITLE => 'Captain']),
        import_member('5000002'),
    ]);

    $officer  = (int) import_member_row('5000001')['id'];
    $member   = (int) import_member_row('5000002')['id'];
    $account  = (int) import_account('5000001')['id'];

    // Everything in spec 6.6's "we own" table, made real.
    $pdo->prepare('UPDATE app_user SET granted_level = :level, granted_by = :by, granted_at = UTC_TIMESTAMP(), '
        . 'scope_division_id = :division WHERE id = :id')
        ->execute([':level' => 'senior_officer', ':by' => $account, ':division' => (int) import_member_row('5000001')['division_id'], ':id' => $account]);

    $pdo->prepare('INSERT INTO contact_log (member_id, show_year_id, contacted_by, contact_type, notes) '
        . 'VALUES (:m, :y, :by, :type, :notes)')
        ->execute([':m' => $member, ':y' => $year, ':by' => $account, ':type' => 'call', ':notes' => 'Left a message']);

    $pdo->prepare('INSERT INTO assignment (member_id, officer_member_id, show_year_id, assigned_by) '
        . 'VALUES (:m, :o, :y, :by)')
        ->execute([':m' => $member, ':o' => $officer, ':y' => $year, ':by' => $account]);

    $pdo->prepare('UPDATE team SET area = :area WHERE id = :id')
        ->execute([':area' => 'Reed Road', ':id' => (int) import_member_row('5000001')['team_id']]);

    // A later roster demotes the officer to an ordinary member.
    import_apply([
        import_member('5000001', [HeaderMap::TITLE => 'Committee Member']),
        import_member('5000002'),
    ]);

    $after = import_account('5000001');
    // The grant survives — that is the entire reason designation exists.
    assertSame('senior_officer', (string) $after['granted_level']);
    assertSame('senior_officer', (string) $after['effective_level'], 'granted_level ?? level');
    assertSame('member', (string) $after['level'], 'the title-derived level does follow the roster');
    assertSame(1, (int) $after['is_active'], 'a grant holds the account open through a demotion');
    assertTrue($after['scope_division_id'] !== null, 'a scope override is ours, not HLSR\'s');

    assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM contact_log')->fetchColumn(),
        'contact history survives every import unconditionally');
    assertSame('Left a message', (string) $pdo->query('SELECT notes FROM contact_log LIMIT 1')->fetchColumn());
    assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM assignment WHERE removed_at IS NULL')->fetchColumn(),
        'an assignment that silently empties is how twenty people stop being chased');
    assertSame('Reed Road', (string) $pdo->query("SELECT area FROM team WHERE area IS NOT NULL LIMIT 1")->fetchColumn(),
        'team.area is Admin-editable display grouping, and no import writes it');
});

test('a demotion deactivates the account; a re-promotion reactivates the same row', function (): void {
    import_reset();

    import_apply([import_member('5100001', [HeaderMap::TITLE => 'Captain'])]);
    $first = import_account('5100001');
    assertSame('officer', (string) $first['level']);
    assertSame(1, (int) $first['is_active']);
    assertSame(1, (int) $first['must_change_password'], 'the initial password 1234 is forced to change');

    import_apply([import_member('5100001', [HeaderMap::TITLE => 'Committee Member'])]);
    $demoted = import_account('5100001');
    // Deactivated, never deleted: the audit trail outlives the account.
    assertSame(0, (int) $demoted['is_active']);
    assertSame((int) $first['id'], (int) $demoted['id'], 'the same row, not a second one');

    import_apply([import_member('5100001', [HeaderMap::TITLE => 'Assistant Captain'])]);
    $repromoted = import_account('5100001');
    assertSame(1, (int) $repromoted['is_active']);
    assertSame((int) $first['id'], (int) $repromoted['id'], 'still the same row');
});

test('an ordinary member gets no account at all — not a disabled one', function (): void {
    import_reset();

    // 1,758 of the 1,954 in the sample. A member is data, not a user, and a
    // disabled account for every one of them would put a password hash beside
    // every home address in the database.
    import_apply([
        import_member('5200001'),
        import_member('5200002', [HeaderMap::TITLE => 'Lifetime Committeemen']),
        import_member('5200003', [HeaderMap::TITLE => 'Past Committee Chairman']),
        import_member('5200004', [HeaderMap::TITLE => 'Captain']),
    ]);

    assertSame(null, import_account('5200001'));
    assertSame(null, import_account('5200002'));
    assertSame(null, import_account('5200003'));
    assertTrue(import_account('5200004') !== null);
});

// ===========================================================================
// The one exception, and it is deliberate
// ===========================================================================

test('a metric moving N to Y clears its progress, and says so in the audit log', function (): void {
    import_reset();

    import_apply([import_member('6000001', [HeaderMap::COMMITTEE_DUES => 'N'])]);

    $pdo     = import_pdo();
    $member  = (int) import_member_row('6000001')['id'];
    $account = (int) $pdo->query("SELECT u.id FROM app_user u INNER JOIN member m ON m.id = u.member_id "
        . "WHERE m.is_system = 1")->fetchColumn();

    // An officer phoned them and was told the cheque is in the post.
    $pdo->prepare('UPDATE member_metric SET progress = :progress, progress_by = :by, '
        . 'progress_at = UTC_TIMESTAMP(), progress_note = :note '
        . "WHERE member_id = :m AND metric = 'committee_dues'")
        ->execute([':progress' => 'in_progress', ':by' => $account, ':note' => 'Paying on Friday', ':m' => $member]);

    import_apply([import_member('6000001', [HeaderMap::COMMITTEE_DUES => 'Y'])]);

    $metric = import_metric_row('6000001', 'committee_dues');
    // The thing being chased has happened, so "in progress" is now false.
    assertSame('Y', (string) $metric['imported_value']);
    assertSame('not_started', (string) $metric['progress']);
    assertSame(null, $metric['progress_by']);
    assertSame('', (string) $metric['progress_note']);

    // Recorded, never silent. This is what answers "why did this flip back".
    $audit = $pdo->query("SELECT * FROM audit_log WHERE action = 'import_reset_progress' ORDER BY id DESC LIMIT 1")->fetch();
    assertTrue(is_array($audit), 'the reset must be written to audit_log');
    assertTrue(str_contains((string) $audit['before_json'], 'Paying on Friday'), 'the prior note is kept');
    assertTrue(str_contains((string) $audit['before_json'], 'in_progress'), 'the prior status is kept');

    // And the contact history is untouched by any of it.
    assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM contact_log')->fetchColumn());
});

test('an import that leaves a metric at N preserves progress untouched', function (): void {
    import_reset();

    import_apply([import_member('6100001', [HeaderMap::INDEMNITY => 'N'])]);

    $pdo     = import_pdo();
    $member  = (int) import_member_row('6100001')['id'];
    $account = (int) $pdo->query('SELECT id FROM app_user LIMIT 1')->fetchColumn();

    $pdo->prepare('UPDATE member_metric SET progress = :progress, progress_by = :by, progress_note = :note '
        . "WHERE member_id = :m AND metric = 'indemnity'")
        ->execute([':progress' => 'claimed_complete', ':by' => $account, ':note' => 'Signed at the meeting', ':m' => $member]);

    // The roster lags reality: it still says N, and the officer's work is
    // still in flight. A refresh must not erase it.
    import_apply([import_member('6100001', [HeaderMap::INDEMNITY => 'N'])]);

    $metric = import_metric_row('6100001', 'indemnity');
    assertSame('N', (string) $metric['imported_value']);
    assertSame('claimed_complete', (string) $metric['progress']);
    assertSame('Signed at the meeting', (string) $metric['progress_note']);
});

test('a metric moving Y to N leaves progress alone', function (): void {
    import_reset();

    import_apply([import_member('6200001', [HeaderMap::SHOW_DUES => 'Y'])]);

    $pdo    = import_pdo();
    $member = (int) import_member_row('6200001')['id'];
    $pdo->prepare("UPDATE member_metric SET progress = 'in_progress' WHERE member_id = :m AND metric = 'hlsr_dues'")
        ->execute([':m' => $member]);

    // A correction back to N is the case the reset exists to keep honest —
    // and only the N-to-Y direction resets, so nothing is lost here either.
    import_apply([import_member('6200001', [HeaderMap::SHOW_DUES => 'N'])]);

    assertSame('N', (string) import_metric_row('6200001', 'hlsr_dues')['imported_value']);
    assertSame('in_progress', (string) import_metric_row('6200001', 'hlsr_dues')['progress']);
});

// ===========================================================================
// Divisions, and the one that is ours
// ===========================================================================

test('a blank division lands in (No Division), and every import re-evaluates it', function (): void {
    import_reset();

    import_apply([import_member('7000001', [HeaderMap::SUBCOMMITTEE_3 => ''])]);
    assertSame('(No Division)', (string) import_member_row('7000001')['division_name']);
    // NOT NULL, so no query anywhere carries a null branch.
    assertTrue(import_member_row('7000001')['division_id'] !== null);

    // A populated value moves the member out.
    import_apply([import_member('7000001', [HeaderMap::SUBCOMMITTEE_3 => 'Satellites Division'])]);
    assertSame('Satellites Division', (string) import_member_row('7000001')['division_name']);

    // And a blank one moves them back in. Never sticky.
    import_apply([import_member('7000001', [HeaderMap::SUBCOMMITTEE_3 => ''])]);
    assertSame('(No Division)', (string) import_member_row('7000001')['division_name']);
});

test('a team spanning two divisions files each member under their own', function (): void {
    import_reset();

    // Seven of the 96 teams in the sample do this. Division is a property of
    // the MEMBER; team.division_id is the modal value, for display only.
    import_apply([
        import_member('7100001', [HeaderMap::SUBCOMMITTEE_1 => 'Split Team', HeaderMap::SUBCOMMITTEE_3 => 'Bus Ops Division']),
        import_member('7100002', [HeaderMap::SUBCOMMITTEE_1 => 'Split Team', HeaderMap::SUBCOMMITTEE_3 => 'Logistics Division']),
    ]);

    assertSame('Bus Ops Division', (string) import_member_row('7100001')['division_name']);
    assertSame('Logistics Division', (string) import_member_row('7100002')['division_name']);
    assertSame(
        (int) import_member_row('7100001')['team_id'],
        (int) import_member_row('7100002')['team_id'],
        'one team, two divisions'
    );
});

// ===========================================================================
// Absence and purge — flag, never delete
// ===========================================================================

test('a complete import flags who it did not see, and un-flags them when they return', function (): void {
    import_reset();

    import_apply([import_member('8000001'), import_member('8000002')]);

    $second = import_apply([import_member('8000001')]);
    assertSame(1, $second['absent']);

    $gone = import_member_row('8000002');
    assertTrue($gone !== null, 'flagging is not deleting');
    assertTrue($gone['absent_since_import_id'] !== null, 'and the batch that noticed is recorded');

    // A member who reappears is un-flagged automatically. Lapsing for a
    // season and returning is the ordinary case, not the exception.
    import_apply([import_member('8000001'), import_member('8000002')]);
    assertSame(null, import_member_row('8000002')['absent_since_import_id']);
});

test('an update import flags nobody', function (): void {
    import_reset();

    import_apply([import_member('8100001'), import_member('8100002')]);

    // An update is a refresh of what is known about people already on the
    // roster, not a statement about who is on the committee.
    $result = import_apply([import_member('8100001')], Importer::MODE_UPDATE);

    assertSame(0, $result['absent']);
    assertSame(null, import_member_row('8100002')['absent_since_import_id']);
});

test('a purged member is not re-flagged, and their history survives', function (): void {
    import_reset();

    import_apply([import_member('8200001'), import_member('8200002')]);

    $pdo = import_pdo();
    $pdo->prepare('UPDATE member SET purged_at = UTC_TIMESTAMP() WHERE member_number = :n')
        ->execute([':n' => '8200002']);

    import_apply([import_member('8200001')]);

    $purged = import_member_row('8200002');
    assertTrue($purged !== null, 'a purge is a SOFT delete and this is not negotiable');
    assertSame(null, $purged['absent_since_import_id'], 'already gone; flagging again says nothing');
    assertSame(5, (int) $pdo->query(
        "SELECT COUNT(*) FROM member_metric mm INNER JOIN member m ON m.id = mm.member_id "
        . "WHERE m.member_number = '8200002'"
    )->fetchColumn(), 'nothing cascades from a purge');
});

test('the master administrator is invisible to the import', function (): void {
    import_reset();

    // Without is_system the FIRST complete import would flag the only account
    // that can sign in, put it on the Flagged for Purge screen, and invite an
    // Admin to purge it.
    import_apply([import_member('8300001')]);

    $master = import_member_row(App::MASTER_ADMIN_NUMBER);
    assertTrue($master !== null);
    assertSame(null, $master['absent_since_import_id'], 'never absented');
    assertSame(null, $master['last_seen_import_id'], 'never seen');
    assertSame('admin', (string) import_account(App::MASTER_ADMIN_NUMBER)['effective_level'], 'never demoted');

    // And a file claiming that member number cannot take the account over.
    $importer = import_importer();
    $batch    = $importer->stage(import_csv([import_member(App::MASTER_ADMIN_NUMBER, [
        HeaderMap::FIRST_NAME => 'Impostor',
    ])]), 'roster.csv');
    $importer->apply($batch);

    assertSame('Master', (string) import_member_row(App::MASTER_ADMIN_NUMBER)['first_name']);
    assertSame(
        1,
        Warnings::countsFor(import_pdo(), $batch)[Warnings::SYSTEM_MEMBER_NUMBER] ?? 0,
        'and it is reported rather than silently dropped'
    );
});

// ===========================================================================
// Modes
// ===========================================================================

test('update mode refreshes contact details and metrics, and nothing else', function (): void {
    import_reset();

    import_apply([import_member('9000001', [HeaderMap::TITLE => 'Captain'])]);

    import_apply([import_member('9000001', [
        HeaderMap::TITLE          => 'Committee Member',
        HeaderMap::FIRST_NAME     => 'Renamed',
        HeaderMap::SUBCOMMITTEE_1 => 'Somewhere Else',
        HeaderMap::PHONE          => '(555) 555-0188',
        HeaderMap::PHONE_TYPE     => 'HOME',
        HeaderMap::EMAIL          => 'new@example.com',
        HeaderMap::SHOW_DUES      => 'Y',
    ])], Importer::MODE_UPDATE);

    $member = import_member_row('9000001');
    // Phone and email are updated in EVERY mode — the brief asks for it, and
    // they are the two fields that go stale fastest.
    assertSame('(555) 555-0188', (string) $member['phone']);
    assertSame('HOME', (string) $member['phone_type']);
    assertSame('new@example.com', (string) $member['email']);
    assertSame('Y', (string) import_metric_row('9000001', 'hlsr_dues')['imported_value']);

    // And nothing else moves.
    assertSame('Captain', (string) $member['title']);
    assertSame('Given', (string) $member['first_name']);
    assertSame('Sample Team 1', (string) $member['team_name']);
});

test('update mode creates nobody, and reports the row it ignored', function (): void {
    import_reset();

    import_apply([import_member('9100001')]);

    $importer = import_importer();
    $batch    = $importer->stage(
        import_csv([import_member('9100001'), import_member('9100002')]),
        'roster.csv',
        Importer::MODE_UPDATE
    );
    $importer->apply($batch);

    assertSame(null, import_member_row('9100002'), 'an update import never creates a member');
    assertSame(1, Warnings::countsFor(import_pdo(), $batch)[Warnings::NOT_IN_ROSTER] ?? 0);
});

test('team mode skips a row belonging to another team rather than retargeting it', function (): void {
    import_reset();

    import_apply([
        import_member('9200001', [HeaderMap::SUBCOMMITTEE_1 => 'Chosen Team']),
        import_member('9200002', [HeaderMap::SUBCOMMITTEE_1 => 'Other Team']),
    ]);

    $teamId = (int) import_member_row('9200001')['team_id'];

    $importer = import_importer();
    $batch    = $importer->stage(
        import_csv([
            import_member('9200001', [HeaderMap::SUBCOMMITTEE_1 => 'Chosen Team', HeaderMap::SHOW_DUES => 'Y']),
            import_member('9200002', [HeaderMap::SUBCOMMITTEE_1 => 'Other Team', HeaderMap::SHOW_DUES => 'Y']),
        ]),
        'roster.csv',
        Importer::MODE_TEAM,
        $teamId
    );
    $importer->apply($batch);

    assertSame('Y', (string) import_metric_row('9200001', 'hlsr_dues')['imported_value']);
    assertSame('N', (string) import_metric_row('9200002', 'hlsr_dues')['imported_value'], 'skipped, not applied');
    assertSame('Other Team', (string) import_member_row('9200002')['team_name'], 'and never silently moved');
    assertSame(1, Warnings::countsFor(import_pdo(), $batch)[Warnings::WRONG_TEAM] ?? 0);
});

test('team mode flags absentees within its own team only', function (): void {
    import_reset();

    import_apply([
        import_member('9300001', [HeaderMap::SUBCOMMITTEE_1 => 'Chosen Team']),
        import_member('9300002', [HeaderMap::SUBCOMMITTEE_1 => 'Chosen Team']),
        import_member('9300003', [HeaderMap::SUBCOMMITTEE_1 => 'Other Team']),
    ]);

    $teamId = (int) import_member_row('9300001')['team_id'];

    $result = import_apply(
        [import_member('9300001', [HeaderMap::SUBCOMMITTEE_1 => 'Chosen Team'])],
        Importer::MODE_TEAM,
        $teamId
    );

    // Flagging the whole committee absent because they were not in a 40-row
    // team file is how a roster lands on the purge screen.
    assertSame(1, $result['absent']);
    assertTrue(import_member_row('9300002')['absent_since_import_id'] !== null);
    assertSame(null, import_member_row('9300003')['absent_since_import_id']);
});

test('team mode without a team, and the other modes with one, are refused', function (): void {
    import_reset();

    $path = import_csv([import_member('9400001')]);

    assertThrows(
        static fn () => import_importer()->stage($path, 'roster.csv', Importer::MODE_TEAM),
        'Team mode needs a team'
    );
    assertThrows(
        static fn () => import_importer()->stage($path, 'roster.csv', Importer::MODE_COMPLETE, 1),
        'Only a team import takes a team'
    );
    assertThrows(
        static fn () => import_importer()->stage($path, 'roster.csv', 'sideways'),
        'Unknown import mode'
    );
});

// ===========================================================================
// Warnings — every one of the ten in spec 6.4
// ===========================================================================

test('every one of the ten warnings in spec 6.4 fires', function (): void {
    import_reset();

    // A first import, so that the second one below has a roster to compare
    // against and a team to conflict with.
    import_apply([
        import_member('9500001', [HeaderMap::SUBCOMMITTEE_1 => 'Known Team', HeaderMap::SUBCOMMITTEE_3 => 'Bus Ops Division']),
        import_member('9500002', [HeaderMap::EMAIL => 'household@example.com']),
    ]);

    $importer = import_importer();
    $batch    = $importer->stage(import_csv([
        // unknown_title — imported as Member, never as an officer
        import_member('9500010', [HeaderMap::TITLE => 'Grand Marshal']),
        // no_division — 72 rows in the sample
        import_member('9500011', [HeaderMap::SUBCOMMITTEE_3 => '']),
        // no_email — 1 row in the sample; cannot recover a password
        import_member('9500012', [HeaderMap::EMAIL => '']),
        // non_cell_phone — 116 rows; the text link is suppressed
        import_member('9500013', [HeaderMap::PHONE_TYPE => 'HOME']),
        // shared_email — 2 addresses shared by 4 people
        import_member('9500014', [HeaderMap::EMAIL => 'household@example.com']),
        // team_division_conflict — 7 teams span two divisions
        import_member('9500015', [HeaderMap::SUBCOMMITTEE_1 => 'Known Team', HeaderMap::SUBCOMMITTEE_3 => 'Logistics Division']),
        // duplicate_member_number — the first row wins, the later one is skipped
        import_member('9500016'),
        import_member('9500016', [HeaderMap::FIRST_NAME => 'Twice']),
        // new_team — created by the apply
        import_member('9500017', [HeaderMap::SUBCOMMITTEE_1 => 'Brand New Team']),
        // unparseable_phone — imported as text, no tel: link
        import_member('9500018', [HeaderMap::PHONE => 'ask his wife']),
    ]), 'roster.csv');

    $counts = Warnings::countsFor(import_pdo(), $batch);

    foreach ([
        Warnings::UNKNOWN_TITLE,
        Warnings::NO_DIVISION,
        Warnings::NO_EMAIL,
        Warnings::NON_CELL_PHONE,
        Warnings::SHARED_EMAIL,
        Warnings::TEAM_DIVISION_CONFLICT,
        Warnings::DUPLICATE_MEMBER_NUMBER,
        Warnings::NEW_TEAM,
        Warnings::UNPARSEABLE_PHONE,
    ] as $kind) {
        assertTrue(($counts[$kind] ?? 0) > 0, "warning {$kind} did not fire");
    }

    // The tenth, wrong_team, is a team-mode warning and cannot occur in a
    // complete import at all — it is asserted by the team-mode test above.
    assertTrue(in_array(Warnings::WRONG_TEAM, Warnings::SPEC_KINDS, true));
    assertSame(10, count(Warnings::SPEC_KINDS), 'spec 6.4 lists exactly ten');

    // A duplicate is skipped, not merged: the first row wins.
    $importer->apply($batch);
    assertSame('Given', (string) import_member_row('9500016')['first_name']);

    // And an unrecognised title never silently becomes an officer.
    assertSame('member', (string) import_member_row('9500010')['title_level']);
    assertSame('Grand Marshal', (string) import_member_row('9500010')['title'], 'the title itself is kept verbatim');
    assertSame(null, import_account('9500010'));
});

test('warnings are grouped by kind so a long list cannot bury a short one', function (): void {
    import_reset();

    $members = [];
    for ($i = 0; $i < 12; $i++) {
        // Eleven rows of noise, and one that matters.
        $members[] = import_member((string) (9600000 + $i), [
            HeaderMap::SUBCOMMITTEE_3 => '',
            HeaderMap::TITLE          => $i === 7 ? 'Deputy Grand Marshal' : 'Committee Member',
        ]);
    }

    $batch  = import_importer()->stage(import_csv($members), 'roster.csv');
    $counts = Warnings::countsFor(import_pdo(), $batch);

    assertSame(12, $counts[Warnings::NO_DIVISION] ?? 0);
    assertSame(1, $counts[Warnings::UNKNOWN_TITLE] ?? 0);

    // Severity order: the kind that changed how somebody is treated comes
    // before the twelve that merely describe where they were filed.
    $kinds = array_keys($counts);
    assertTrue(
        array_search(Warnings::UNKNOWN_TITLE, $kinds, true) < array_search(Warnings::NO_DIVISION, $kinds, true),
        'a single unknown_title must sort above twelve no_division rows'
    );

    // And the rows behind a kind are retrievable, attributed to a row number
    // and a member number.
    $rows = Warnings::rowsFor(import_pdo(), $batch, Warnings::UNKNOWN_TITLE, 10);
    assertSame(1, count($rows));
    assertSame('9600007', (string) $rows[0]['member_number']);
    assertTrue(str_contains($rows[0]['detail'], 'Deputy Grand Marshal'), 'the warning names the title');
});

test('a row with no Customer Number is skipped rather than invented a key for', function (): void {
    import_reset();

    $importer = import_importer();
    $batch    = $importer->stage(import_csv([
        import_member('9700001'),
        import_member('', [HeaderMap::FIRST_NAME => 'Keyless']),
    ]), 'roster.csv');

    $importer->apply($batch);

    assertSame(1, Warnings::countsFor(import_pdo(), $batch)[Warnings::MISSING_MEMBER_NUMBER] ?? 0);
    assertSame(1, (int) import_pdo()->query('SELECT COUNT(*) FROM member WHERE is_system = 0')->fetchColumn());
});

test('two rows of one file sharing an address are reported as sharing it', function (): void {
    import_reset();

    // Two addresses in the sample are shared by four people, and both pairs
    // share a surname — a household inbox, not a data error. The pairs hold
    // DIFFERENT titles, so a recovery email that does not name its member
    // number hands the wrong account to whoever opens the mail first.
    //
    // This is the in-file case. The collision has to be seen against the rows
    // already read, not only against the roster as it stood before the import,
    // or a first import of a brand-new roster reports nothing at all.
    $batch = import_importer()->stage(import_csv([
        import_member('9750001', [HeaderMap::EMAIL => 'household@example.com']),
        import_member('9750002', [
            HeaderMap::EMAIL => 'household@example.com',
            HeaderMap::TITLE => 'Ambassador',
        ]),
    ]), 'roster.csv');

    $counts = Warnings::countsFor(import_pdo(), $batch);
    assertSame(1, $counts[Warnings::SHARED_EMAIL] ?? 0, 'the second row must collide with the first');

    $rows = Warnings::rowsFor(import_pdo(), $batch, Warnings::SHARED_EMAIL, 5);
    assertSame('9750002', (string) $rows[0]['member_number']);
    assertTrue(str_contains($rows[0]['detail'], '9750001'), 'the warning names the other member');
});

test('update mode creates no teams and promises none', function (): void {
    import_reset();

    import_apply([import_member('9760001', [HeaderMap::SUBCOMMITTEE_1 => 'Known Team'])]);
    $before = (int) import_pdo()->query('SELECT COUNT(*) FROM team')->fetchColumn();

    $importer = import_importer();
    $batch    = $importer->stage(
        import_csv([import_member('9760001', [HeaderMap::SUBCOMMITTEE_1 => 'Team That Does Not Exist'])]),
        'roster.csv',
        Importer::MODE_UPDATE
    );
    $importer->apply($batch);

    // Update mode writes neither team nor division, so a file naming an
    // unknown team must not add one — and must not warn that it will.
    assertSame($before, (int) import_pdo()->query('SELECT COUNT(*) FROM team')->fetchColumn());
    assertSame(0, Warnings::countsFor(import_pdo(), $batch)[Warnings::NEW_TEAM] ?? 0);
    assertSame('Known Team', (string) import_member_row('9760001')['team_name']);
});

test('update mode never moves an account level', function (): void {
    import_reset();

    import_apply([import_member('9770001', [HeaderMap::TITLE => 'Captain'])]);
    assertSame('officer', (string) import_account('9770001')['level']);

    // The title is not one of the fields update mode refreshes, so moving the
    // account on the strength of it would deactivate an officer's login using
    // a value this import declined to import.
    import_apply([import_member('9770001', [HeaderMap::TITLE => 'Committee Member'])], Importer::MODE_UPDATE);

    $account = import_account('9770001');
    assertSame('officer', (string) $account['level']);
    assertSame(1, (int) $account['is_active']);
    assertSame('Captain', (string) import_member_row('9770001')['title']);
});

test('a metric cell that is neither Y nor N nor blank is reported', function (): void {
    import_reset();

    // Spec 6.1: sentinel normalisation is deliberately NOT applied to the
    // metric columns, "where only Y and N are meaningful and anything else
    // deserves a warning".
    $batch = import_importer()->stage(
        import_csv([import_member('9800001', [HeaderMap::INDEMNITY => 'Pending'])]),
        'roster.csv'
    );

    assertSame(1, Warnings::countsFor(import_pdo(), $batch)[Warnings::UNEXPECTED_METRIC_VALUE] ?? 0);
});

// ===========================================================================
// Refusals
// ===========================================================================

test('an import into a closed show year is refused', function (): void {
    import_reset();

    $pdo = import_pdo();
    $pdo->exec('UPDATE show_year SET is_open = 0 WHERE is_active = 1');

    try {
        assertThrows(
            static fn () => import_importer()->stage(import_csv([import_member('9900001')]), 'roster.csv'),
            'closed'
        );
    } finally {
        $pdo->exec('UPDATE show_year SET is_open = 1 WHERE is_active = 1');
    }
});

test('an unreadable file is refused before a batch exists', function (): void {
    import_reset();

    assertThrows(
        static fn () => import_importer()->stage('/no/such/roster.csv', 'roster.csv'),
        'Cannot read'
    );

    // A file the import cannot read leaves no record of an import that never
    // started.
    $empty = import_scratch('empty.csv');
    file_put_contents($empty, "Nickname,Phone\n");
    assertThrows(static fn () => import_importer()->stage($empty, 'roster.csv'), 'Customer Number');

    assertSame(0, (int) import_pdo()->query('SELECT COUNT(*) FROM import_batch')->fetchColumn());
});

test('a staged batch older than its time to live is discarded', function (): void {
    import_reset();

    $importer = import_importer();
    $batch    = $importer->stage(import_csv([import_member('9910001')]), 'roster.csv');

    import_pdo()->prepare('UPDATE import_batch SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR) WHERE id = :id')
        ->execute([':id' => $batch]);

    // A stale preview was computed against a roster that has since changed,
    // so applying it would write a diff nobody has read.
    assertSame(1, $importer->discardExpired());
    assertThrows(static fn () => import_importer()->preview($batch), 'no import batch');
});

// ===========================================================================
// The measured limit
// ===========================================================================

test('a full-size roster stages and applies well inside the 30-second ceiling', function (): void {
    import_reset();

    // 1,954 rows is the real file's size. max_execution_time is 30s on this
    // host and it covers the whole request, so the budget below is a third of
    // it — a margin, not the limit.
    $members = [];
    for ($i = 0; $i < 1954; $i++) {
        $members[] = import_member((string) (1100000 + $i), [
            HeaderMap::TITLE          => $i % 10 === 0 ? 'Captain' : 'Committee Member',
            HeaderMap::SUBCOMMITTEE_1 => 'Team ' . ($i % 96),
            HeaderMap::SUBCOMMITTEE_3 => $i % 27 === 0 ? '' : 'Bus Ops Division',
            HeaderMap::SHOW_DUES      => $i % 3 === 0 ? 'Y' : 'N',
        ]);
    }

    $path     = import_csv($members);
    $importer = Importer::fromApp($GLOBALS['rerm_app']);

    $started = microtime(true);
    $batch   = $importer->stage($path, 'roster.csv');
    $staged  = microtime(true) - $started;

    $started = microtime(true);
    $result  = $importer->apply($batch);
    $applied = microtime(true) - $started;

    assertSame(1954, $result['created']);
    assertSame(9770, (int) import_pdo()->query('SELECT COUNT(*) FROM member_metric')->fetchColumn(),
        'five metric rows per member');

    assertTrue(
        $staged + $applied < 10.0,
        sprintf('1,954 rows took %.1fs to stage and %.1fs to apply; the ceiling is 30s', $staged, $applied)
    );

    // And the second import of the same file is the one that proves the diff
    // is honest: everything unchanged, nobody created.
    $second = $importer->apply($importer->stage($path, 'roster.csv'));
    assertSame(0, $second['created']);
    assertSame(0, $second['updated']);
    assertSame(1954, $second['unchanged']);
});

// ===========================================================================
// Leave the database as this file found it
// ===========================================================================

test('the import suite leaves no roster behind', function (): void {
    import_reset();

    // tests/schema_test.php runs after this file and asserts that exactly five
    // divisions exist and that (No Division) is the only placeholder. A test
    // suite that leaves 1,954 members and 96 teams behind breaks it, and the
    // failure reads as a schema problem.
    assertSame(0, (int) import_pdo()->query('SELECT COUNT(*) FROM member WHERE is_system = 0')->fetchColumn());
    assertSame(0, (int) import_pdo()->query('SELECT COUNT(*) FROM team')->fetchColumn());
    assertSame(5, (int) import_pdo()->query('SELECT COUNT(*) FROM division')->fetchColumn());
});
