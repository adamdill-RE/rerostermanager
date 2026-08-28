<?php

declare(strict_types=1);

/**
 * The contact history import (spec 6.7).
 *
 * **Every value in this file is invented.** Not one name, member number or
 * note comes from anybody real, and none ever may: this repository is public
 * and a fixture is readable by anyone who can clone it. Member numbers are in
 * the 900xxxx block, which the observed export does not use, and CI fails the
 * build on a real address, phone number or email.
 *
 * What is asserted here, in order of how much it would cost to get wrong:
 *
 *   1. It BACK-DATES. A contact dated in October lands in October. The whole
 *      feature is worthless otherwise — My Roster Status sorts by last
 *      contact, so eighty rows stamped "today" would be worse than nothing.
 *   2. It writes contact_log AND NOTHING ELSE. Not a metric, not a progress
 *      status, not an assignment, not a member row.
 *   3. It is idempotent. The same file applied twice writes its rows once,
 *      because contact_log is append-only and a duplicate cannot be deleted
 *      back out.
 *   4. It refuses rather than guesses: an ambiguous name, an unknown officer,
 *      an unreadable date, a closed show year. Every outcome kind fires.
 *   5. The two parsers — date and contact type — are exercised as pure
 *      functions over a table of cases, because "is 3/4/26 March or April"
 *      is the question this feature is most likely to be quietly wrong about.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Scope;
use Rerm\Import\ContactHeaderMap;
use Rerm\Import\ContactImporter;
use Rerm\Import\ContactRow;
use Rerm\Routes;

// ---------------------------------------------------------------------------
// The two parsers. No database, no fixtures — a table of cases.
// ---------------------------------------------------------------------------

test('a date cell becomes the day it says, in US order, at local noon', function (): void {
    // Local noon rather than midnight, and this is the assertion that pins it:
    // midnight UTC is the previous EVENING in Chicago, so a contact logged as
    // the 14th would display as the 13th on every screen. Noon is far enough
    // from both boundaries that no daylight-saving shift can move the date.
    $cases = [
        // ISO, which is what both spreadsheet readers hand back for a real
        // date cell (XlsReader::serialToDate, XlsxReader::numericOrDate).
        '2026-03-04'       => '2026-03-04 18:00:00',   // noon CST
        '2026-07-04'       => '2026-07-04 17:00:00',   // noon CDT
        '2026-03-04 09:30' => '2026-03-04 15:30:00',   // a time is kept as given
        '2026-03-04T09:30' => '2026-03-04 15:30:00',
        // Typed by a person. US order: 3/4 is the fourth of March.
        '3/4/2026'         => '2026-03-04 18:00:00',
        '03/04/2026'       => '2026-03-04 18:00:00',   // zero-padded, same day
        '12/31/2025'       => '2025-12-31 18:00:00',
        // Two-digit years. `n/j/Y` matches these FIRST and yields year 26,
        // which round-trips perfectly and is a real date in the reign of
        // Tiberius; the plausibility floor is what rejects that reading.
        '3/4/26'           => '2026-03-04 18:00:00',
        '10/14/25'         => '2025-10-14 17:00:00',
        // Spelled months, either order.
        '4 Mar 2026'       => '2026-03-04 18:00:00',
        'Mar 4, 2026'      => '2026-03-04 18:00:00',
        '04-Mar-2026'      => '2026-03-04 18:00:00',
    ];

    foreach ($cases as $raw => $expected) {
        assertSame($expected, ContactRow::parseDate((string) $raw), "parsing {$raw}");
    }
});

test('a cell that is not a date is null, never a date near it', function (): void {
    // Null is what makes the row a listed skip instead of a contact filed on
    // a day nobody named. createFromFormat is forgiving enough to turn
    // 13/45/2026 into a date in 2027 without complaining, which is exactly
    // the failure the round-trip check exists to catch.
    foreach ([
        '', '   ', 'not a date', 'last Tuesday', 'sometime in October',
        '13/45/2026', '0/0/2026', '2026-13-45',
        // Before the plausibility floor: a four-digit year this low is a
        // two-digit one that has been misread.
        '1985-01-01', '1/1/1901',
    ] as $raw) {
        assertSame(null, ContactRow::parseDate($raw), "refusing {$raw}");
    }
});

test('the type column reads the words officers actually write', function (): void {
    $cases = [
        'call' => 'call', 'Call' => 'call', 'CALL' => 'call',
        'called' => 'call', 'phone' => 'call', 'phone call' => 'call',
        // A voicemail is a call that was not answered. The officer did the
        // work, and landing eleven of them as Other reads as though nobody
        // knows what happened.
        'vm' => 'call', 'VM' => 'call', 'voicemail' => 'call',
        'left voicemail' => 'call', 'lvm' => 'call', 'left message' => 'call',
        // Punctuation people put in a type column.
        'call - no answer' => 'call', 'email (bounced)' => 'email',
        'text' => 'text', 'texted' => 'text', 'SMS' => 'text', 'txt' => 'text',
        'email' => 'email', 'e-mail' => 'email', 'emailed' => 'email',
        'in person' => 'in_person', 'in-person' => 'in_person',
        'f2f' => 'in_person', 'met' => 'in_person', 'meeting' => 'in_person',
        'other' => 'other',
    ];

    foreach ($cases as $raw => $expected) {
        assertSame($expected, ContactRow::parseType((string) $raw), "reading {$raw}");
    }

    // A word this application does not model is NULL rather than a guess.
    // Recording "Facebook" as a phone call would put a fact in the permanent
    // record that nobody asserted; the importer's answer is Other, and it
    // says so on the preview.
    foreach (['', 'Facebook', 'carrier pigeon', 'never reached'] as $raw) {
        assertSame(null, ContactRow::parseType($raw), "refusing to guess at {$raw}");
    }
});

// ---------------------------------------------------------------------------
// The header map
// ---------------------------------------------------------------------------

test('headers are matched by alias, in any case and any order', function (): void {
    $headers = ContactHeaderMap::fromHeaderRow([
        'NOTES', '  when  ', 'Member Name', 'Method', 'Logged By', 'Unrelated',
    ]);

    assertSame(true, $headers->has(ContactHeaderMap::MEMBER_NAME));
    assertSame(true, $headers->has(ContactHeaderMap::OCCURRED_AT));
    assertSame(true, $headers->has(ContactHeaderMap::CONTACT_TYPE));
    assertSame(true, $headers->has(ContactHeaderMap::OFFICER));
    assertSame(true, $headers->has(ContactHeaderMap::NOTES));
    assertSame(false, $headers->has(ContactHeaderMap::MEMBER_NUMBER));

    $row = ['a note', '3/4/2026', 'Given Surname', 'call', 'Other Surname', 'ignored'];
    assertSame('a note', $headers->value($row, ContactHeaderMap::NOTES));
    assertSame('3/4/2026', $headers->value($row, ContactHeaderMap::OCCURRED_AT));
    assertSame('Given Surname', $headers->memberName($row));

    // A column this import does not read is reported, never an error: a file
    // may carry anything and the import has no opinion about it.
    assertSame(['Unrelated'], $headers->unused());
});

test('first and last name columns are joined when there is no single name column', function (): void {
    $headers = ContactHeaderMap::fromHeaderRow(['First Name', 'Last Name', 'Date']);

    assertSame('Given Surname', $headers->memberName(['Given', 'Surname', '3/4/2026']));
});

test('a file that names no member at all is refused, listing what it does have', function (): void {
    // The one required column. Everything else has a defensible default; this
    // has none, and guessing is worse than refusing.
    assertThrows(
        static fn () => ContactHeaderMap::fromHeaderRow(['Date', 'Type', 'Notes']),
        'No column in this file names the member'
    );

    assertThrows(
        static fn () => ContactHeaderMap::fromHeaderRow(['Date', 'Type']),
        "The headers it does contain are:\n  Date\n  Type",
        'the refusal lists the headers the file does have'
    );
});

// ---------------------------------------------------------------------------
// The database under test
// ---------------------------------------------------------------------------

function contacts_pdo(): PDO
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
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_import_row'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/**
 * Back to the seeded state, and in the order RESTRICT demands.
 *
 * contact_log cites contact_import_batch, which cites app_user, which cites
 * member — so the record comes out before the batch, the batch before the
 * account, and the account before the person. Every one of those keys is
 * RESTRICT rather than CASCADE precisely so that getting this wrong fails
 * loudly instead of quietly taking contact history with it.
 */
function contacts_reset(): void
{
    $pdo = contacts_pdo();

    $systemMembers = array_map('intval', $pdo->query(
        'SELECT id FROM member WHERE is_system = 1'
    )->fetchAll(PDO::FETCH_COLUMN));
    $members = $systemMembers === [] ? '0' : implode(',', $systemMembers);

    $pdo->exec('UPDATE app_user SET granted_by = NULL');
    $pdo->prepare('DELETE FROM audit_log WHERE action LIKE :like')
        ->execute([':like' => 'import_contact%']);

    $pdo->exec('DELETE FROM contact_log');
    $pdo->exec('DELETE FROM contact_import_row');
    $pdo->exec('DELETE FROM contact_import_batch');
    $pdo->exec('DELETE FROM assignment');
    $pdo->exec('DELETE FROM member_metric');
    $pdo->exec("DELETE FROM app_user WHERE member_id NOT IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE id NOT IN ({$members})");
    $pdo->exec('DELETE FROM team');

    // One open, active show year with no dates on it — the shape migration
    // 002 seeds, and the shape every year has until an Admin sets one.
    $pdo->exec('UPDATE show_year SET is_open = 1');
}

/**
 * A team, five members and a captain with an account.
 *
 * Two of the five share a name exactly, which is the fixture the ambiguity
 * refusal needs and is not far-fetched: the real roster has 1,951 distinct
 * names across 1,954 members.
 *
 * @return array{team: int, officer_user: int, members: array<string, int>}
 */
function contacts_fixture(): array
{
    $pdo = contacts_pdo();
    contacts_reset();

    $divisionId = (int) $pdo->query(
        "SELECT id FROM division WHERE name = 'Bus Ops Division'"
    )->fetchColumn();

    $pdo->prepare('INSERT INTO team (name, division_id, area) VALUES (:n, :d, :a)')
        ->execute([':n' => 'Bus Ops Team A', ':d' => $divisionId, ':a' => 'Bus Ops']);
    $teamId = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, full_name, preferred_name,'
        . ' title, title_level, team_id, division_id)'
        . ' VALUES (:n, :f, :l, :fu, :p, :t, :lvl, :team, :div)'
    );

    $people = [
        // number, first, last, preferred, title
        ['9000001', 'Given', 'Alpha',   '',      'Captain'],
        ['9000002', 'Given', 'Bravo',   'Gee',   'Committee Member'],
        ['9000003', 'Given', 'Charlie', '',      'Committee Member'],
        // The same spelling as 9000003, on the same team, on purpose.
        ['9000004', 'Given', 'Charlie', '',      'Committee Member'],
        ['9000005', 'Given', 'Delta',   '',      'Committee Member'],
    ];

    $ids = [];
    foreach ($people as [$number, $first, $last, $preferred, $title]) {
        $insert->execute([
            ':n'    => $number,
            ':f'    => $first,
            ':l'    => $last,
            ':fu'   => $first . ' ' . $last,
            ':p'    => $preferred,
            ':t'    => $title,
            ':lvl'  => $title === 'Captain' ? 'officer' : 'member',
            ':team' => $teamId,
            ':div'  => $divisionId,
        ]);
        $ids[$number] = (int) $pdo->lastInsertId();
    }

    $pdo->prepare(
        "INSERT INTO app_user (member_id, level, is_active, password_hash) VALUES (:m, 'officer', 1, '*')"
    )->execute([':m' => $ids['9000001']]);

    return [
        'team'         => $teamId,
        'officer_user' => (int) $pdo->lastInsertId(),
        'members'      => $ids,
    ];
}

/**
 * A CSV of contact rows, written to a temporary file.
 *
 * @param array<int, array<int, string>> $rows
 * @param array<int, string>             $headers
 */
function contacts_csv(array $rows, array $headers = ['Member', 'Date', 'Type', 'Contacted By', 'Notes']): string
{
    $path   = tempnam(sys_get_temp_dir(), 'rerm-contacts-');
    $handle = fopen((string) $path, 'wb');

    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return (string) $path;
}

function contacts_importer(): ContactImporter
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    // A deliberately small chunk so the batching path runs on a ten-row
    // fixture rather than only on a real file.
    return new ContactImporter($app->db(), 'America/Chicago', 3, 24);
}

/** @return array<int, array<string, mixed>> */
function contacts_log(): array
{
    return contacts_pdo()->query(
        'SELECT c.*, m.member_number FROM contact_log c JOIN member m ON m.id = c.member_id'
        . ' ORDER BY c.occurred_at, c.id'
    )->fetchAll();
}

/** Outcome kind => count, for one batch. @return array<string, int> */
function contacts_kinds(int $batchId): array
{
    $read = contacts_pdo()->prepare(
        "SELECT outcome_kind, COUNT(*) n FROM contact_import_row"
        . " WHERE batch_id = :id AND outcome_kind <> '' GROUP BY outcome_kind"
    );
    $read->execute([':id' => $batchId]);

    $kinds = [];
    foreach ($read->fetchAll() as $row) {
        $kinds[(string) $row['outcome_kind']] = (int) $row['n'];
    }

    return $kinds;
}

// ---------------------------------------------------------------------------
// The point of the whole thing
// ---------------------------------------------------------------------------

test('a contact lands on the day it happened, not the day it was loaded', function (): void {
    // THE assertion. My Roster Status orders by last contact — never
    // contacted first, then oldest first — so a load that stamped every row
    // with today would put eighty people at the BOTTOM of the call list and
    // claim they had all been rung this morning.
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path  = contacts_csv([
        ['9000002', '10/14/2025', 'call', '', 'Left a voicemail about dues'],
        ['9000005', '2025-12-03 09:30', 'email', '', 'Sent the indemnity form'],
    ]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    $result = $importer->apply($batch, $fixture['officer_user']);
    unlink($path);

    assertSame(2, $result['inserted']);

    $log = contacts_log();
    assertSame(2, count($log));

    // Noon Chicago on the day named, expressed in UTC: October is CDT (-5),
    // December is CST (-6).
    assertSame('2025-10-14 17:00:00', (string) $log[0]['occurred_at']);
    assertSame('9000002', (string) $log[0]['member_number']);
    assertSame('call', (string) $log[0]['contact_type']);
    assertSame('Left a voicemail about dues', (string) $log[0]['notes']);

    // A time given in the file is kept as given, not rounded to noon.
    assertSame('2025-12-03 15:30:00', (string) $log[1]['occurred_at']);
    assertSame('email', (string) $log[1]['contact_type']);

    // Every row cites the batch that wrote it. This is what answers "where
    // did these eighty come from" without a single row being deletable.
    foreach ($log as $row) {
        assertSame($batch, (int) $row['contact_import_batch_id']);
    }
});

test('rows are attributed to the batch officer, and to a named one where the file says', function (): void {
    $fixture = contacts_fixture();
    $pdo     = contacts_pdo();

    // A second officer, so "who made this contact" has more than one answer.
    $pdo->prepare(
        "INSERT INTO app_user (member_id, level, is_active, password_hash) VALUES (:m, 'officer', 1, '*')"
    )->execute([':m' => $fixture['members']['9000005']]);
    $second = (int) $pdo->lastInsertId();

    $importer = contacts_importer();
    $path     = contacts_csv([
        ['9000002', '10/14/2025', 'call', '', 'no officer named — the default'],
        ['9000003', '10/15/2025', 'call', '9000005', 'named by member number'],
        ['9000004', '10/16/2025', 'call', 'Given Delta', 'named by name'],
    ]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    $importer->apply($batch, $fixture['officer_user']);
    unlink($path);

    $log = contacts_log();
    assertSame(3, count($log));
    assertSame($fixture['officer_user'], (int) $log[0]['contacted_by'], 'the batch default');
    assertSame($second, (int) $log[1]['contacted_by'], 'named by member number');
    assertSame($second, (int) $log[2]['contacted_by'], 'named by name');
});

test('the same file applied twice writes its rows once', function (): void {
    // contact_log is append-only forever, so a duplicate cannot be deleted
    // back out afterwards. Recognising it is the ONLY protection there is.
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $rows = [
        ['9000002', '10/14/2025', 'call', '', 'one'],
        ['9000005', '10/15/2025', 'text', '', 'two'],
    ];

    $first = contacts_csv($rows);
    $batch = $importer->stage($first, 'history.csv', $fixture['officer_user'], $fixture['team']);
    assertSame(2, $importer->apply($batch, $fixture['officer_user'])['inserted']);

    // The identical file, staged and applied again.
    $again  = $importer->stage($first, 'history.csv', $fixture['officer_user'], $fixture['team']);
    $result = $importer->apply($again, $fixture['officer_user']);
    unlink($first);

    assertSame(0, $result['inserted'], 'nothing is written a second time');
    assertSame(2, $result['duplicate']);
    assertSame(2, count(contacts_log()), 'the log still holds exactly two rows');

    // And the file itself is recognised before a row is read, so the screen
    // can say so rather than showing a preview of nothing.
    $sameAgain = contacts_csv($rows);
    $known     = $importer->appliedWithSameContents($sameAgain);
    unlink($sameAgain);
    assertTrue($known !== null, 'an identical file is recognised by its hash');
});

test('a contact repeated inside one file is one contact', function (): void {
    // The rows the apply is about to insert are not in contact_log yet, so
    // they cannot catch each other there. Caught at stage time instead.
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path  = contacts_csv([
        ['9000002', '10/14/2025', 'call', '', 'first'],
        ['9000002', '10/14/2025', 'call', '', 'the same contact, written twice'],
    ]);
    $batch  = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    $result = $importer->apply($batch, $fixture['officer_user']);
    unlink($path);

    assertSame(1, $result['inserted']);
    assertSame(1, count(contacts_log()));
});

test('two real contacts with the same member on the same day both land', function (): void {
    // The duplicate check is member + MOMENT + type, not member + day. Two
    // calls to one member on one afternoon is ordinary, and collapsing them
    // would lose a real contact to protect against a hypothetical one.
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path  = contacts_csv([
        ['9000002', '2025-10-14 09:00', 'call', '', 'morning'],
        ['9000002', '2025-10-14 16:30', 'call', '', 'afternoon'],
        ['9000002', '2025-10-14 09:00', 'text', '', 'same moment, different type'],
    ]);
    $batch  = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    $result = $importer->apply($batch, $fixture['officer_user']);
    unlink($path);

    assertSame(3, $result['inserted']);
});

// ---------------------------------------------------------------------------
// What it must never touch
// ---------------------------------------------------------------------------

test('a history load writes contact_log and nothing else', function (): void {
    // A history load says a conversation happened. It does NOT say what the
    // member promised, and inferring "in progress" from a months-old note
    // would put a status nobody set in front of an officer.
    $fixture  = contacts_fixture();
    $pdo      = contacts_pdo();
    $importer = contacts_importer();

    $before = [
        'member'        => (string) $pdo->query('SELECT COUNT(*) FROM member')->fetchColumn(),
        'app_user'      => (string) $pdo->query('SELECT COUNT(*) FROM app_user')->fetchColumn(),
        'member_metric' => (string) $pdo->query('SELECT COUNT(*) FROM member_metric')->fetchColumn(),
        'assignment'    => (string) $pdo->query('SELECT COUNT(*) FROM assignment')->fetchColumn(),
        'team'          => (string) $pdo->query('SELECT COUNT(*) FROM team')->fetchColumn(),
    ];

    $path  = contacts_csv([
        ['9000002', '10/14/2025', 'call', '', 'they said the cheque is in the post'],
    ]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    $importer->apply($batch, $fixture['officer_user']);
    unlink($path);

    foreach ($before as $table => $count) {
        assertSame(
            $count,
            (string) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),
            $table . ' is untouched by a contact history load'
        );
    }

    assertSame(1, count(contacts_log()));
});

test('staging writes nothing to the contact log, and the apply is a separate act', function (): void {
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path  = contacts_csv([['9000002', '10/14/2025', 'call', '', 'staged only']]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    unlink($path);

    assertSame(0, count(contacts_log()), 'staging writes no contact at all');

    $preview = $importer->preview($batch);
    assertSame(1, $preview['counts']['insert']);
    assertSame(false, $preview['applied']);

    $importer->apply($batch, $fixture['officer_user']);
    assertSame(1, count(contacts_log()));
});

test('an applied batch cannot be applied again, or discarded', function (): void {
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path  = contacts_csv([['9000002', '10/14/2025', 'call', '', 'once']]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    unlink($path);
    $importer->apply($batch, $fixture['officer_user']);

    assertThrows(
        static fn () => $importer->apply($batch, $fixture['officer_user']),
        'already been applied'
    );

    // The contacts cite the batch; it is the record of where they came from
    // and it stays. The foreign key would refuse anyway — this refuses first,
    // with a sentence rather than an SQLSTATE.
    assertThrows(static fn () => $importer->discard($batch), 'stays');

    assertSame(1, count(contacts_log()), 'and neither attempt changed the log');
});

test('a discarded preview leaves nothing behind', function (): void {
    $fixture  = contacts_fixture();
    $pdo      = contacts_pdo();
    $importer = contacts_importer();

    $path  = contacts_csv([['9000002', '10/14/2025', 'call', '', 'abandoned']]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    unlink($path);

    $importer->discard($batch);

    assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM contact_import_batch')->fetchColumn());
    assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM contact_import_row')->fetchColumn());
    assertSame(0, count(contacts_log()));
});

// ---------------------------------------------------------------------------
// Every outcome kind fires
// ---------------------------------------------------------------------------

test('every reason a row can fail to land is reported, by name and by row', function (): void {
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path = contacts_csv([
        ['',           '10/14/2025', 'call',     '',            'names nobody'],
        ['9999999',    '10/14/2025', 'call',     '',            'no such number'],
        ['Given Charlie', '10/15/2025', 'call',  '',            'two members are called this'],
        ['9000002',    'last Tuesday', 'call',   '',            'unreadable date'],
        ['9000002',    '2099-01-01', 'call',     '',            'in the future'],
        ['9000002',    '10/16/2025', 'call',     '8888888',     'officer with no account'],
        ['9000005',    '10/17/2025', 'Facebook', '',            'a channel we do not model'],
    ]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    unlink($path);

    $kinds = contacts_kinds($batch);

    assertSame(1, $kinds[ContactImporter::NO_MEMBER] ?? 0);
    assertSame(1, $kinds[ContactImporter::MEMBER_NOT_FOUND] ?? 0);
    assertSame(1, $kinds[ContactImporter::AMBIGUOUS_NAME] ?? 0);
    assertSame(1, $kinds[ContactImporter::BAD_DATE] ?? 0);
    assertSame(1, $kinds[ContactImporter::FUTURE_DATE] ?? 0);
    assertSame(1, $kinds[ContactImporter::OFFICER_NOT_FOUND] ?? 0);
    assertSame(1, $kinds[ContactImporter::UNKNOWN_TYPE] ?? 0);

    // The unknown type is the only one of the seven that still lands, as
    // Other, with its note intact — the row is evidence of a conversation and
    // the word for the channel is not worth losing it over.
    $preview = $importer->preview($batch);
    assertSame(1, $preview['counts']['insert']);
    assertSame(6, $preview['counts']['skip']);

    $other = null;
    foreach ($preview['rows'] as $row) {
        if ((string) $row['outcome_kind'] === ContactImporter::UNKNOWN_TYPE) {
            $other = $row;
        }
    }
    assertTrue($other !== null, 'the unknown-type row is in the preview');
    assertSame('other', (string) $other['contact_type']);
    assertSame('insert', (string) $other['action']);
});

test('an ambiguous name is refused and names the people it matched', function (): void {
    // "Never key on a name" (CLAUDE.md). The name is resolved to an id once,
    // inside one team, and where that is not decisive the row is refused —
    // a contact filed against the wrong Charlie is worse than one filed
    // against nobody, because nobody re-reads it.
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path  = contacts_csv([['Given Charlie', '10/14/2025', 'call', '', 'which one?']]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    unlink($path);

    $preview = $importer->preview($batch);
    assertSame(0, $preview['counts']['insert']);
    assertSame(ContactImporter::AMBIGUOUS_NAME, (string) $preview['rows'][0]['outcome_kind']);
    assertTrue(
        str_contains((string) $preview['rows'][0]['detail'], 'Customer Number'),
        'and says what to do about it'
    );
});

test('a name resolves through every spelling of it, and only within the team', function (): void {
    $fixture  = contacts_fixture();
    $pdo      = contacts_pdo();
    $importer = contacts_importer();

    // Somebody on another team, with a name the file also uses.
    $divisionId = (int) $pdo->query(
        "SELECT id FROM division WHERE name = 'Satellites Division'"
    )->fetchColumn();
    $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:n, :d)')
        ->execute([':n' => 'Reed Road Team B', ':d' => $divisionId]);
    $otherTeam = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, full_name, title, title_level,'
        . ' team_id, division_id) VALUES (:n, :f, :l, :fu, :t, :lvl, :team, :div)'
    )->execute([
        ':n' => '9000009', ':f' => 'Given', ':l' => 'Echo', ':fu' => 'Given Echo',
        ':t' => 'Committee Member', ':lvl' => 'member', ':team' => $otherTeam, ':div' => $divisionId,
    ]);

    $path  = contacts_csv([
        ['Given Alpha',   '10/14/2025', 'call', '', 'First Last'],
        ['Bravo, Gee',    '10/15/2025', 'call', '', 'Last, Preferred'],
        ['Given Bravo',   '10/16/2025', 'call', '', 'First Last, where a preferred name also exists'],
        ['Given Echo',    '10/17/2025', 'call', '', 'a real member — of a DIFFERENT team'],
    ]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    unlink($path);

    $preview = $importer->preview($batch);
    assertSame(3, $preview['counts']['insert'], 'three spellings, three matches');
    assertSame(
        ContactImporter::MEMBER_NOT_FOUND,
        (string) $preview['rows'][3]['outcome_kind'],
        'a member of another team is not reachable through this file'
    );
});

test('a closed show year takes no history, exactly as it takes no live contact', function (): void {
    // Closing FREEZES (spec 5.1). Rerm\Roster\LogContact refuses a contact
    // into a closed year and this import gets no exemption from that.
    $fixture  = contacts_fixture();
    $pdo      = contacts_pdo();
    $importer = contacts_importer();

    $pdo->exec('UPDATE show_year SET is_open = 0 WHERE is_active = 1');

    $path  = contacts_csv([['9000002', '10/14/2025', 'call', '', 'into a frozen year']]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    unlink($path);

    $preview = $importer->preview($batch);
    assertSame(0, $preview['counts']['insert']);
    assertSame(ContactImporter::YEAR_CLOSED, (string) $preview['rows'][0]['outcome_kind']);

    assertSame(0, $importer->apply($batch, $fixture['officer_user'])['inserted']);
    assertSame(0, count(contacts_log()));

    $pdo->exec('UPDATE show_year SET is_open = 1');
});

test('a contact goes to the show year whose dates contain it', function (): void {
    $fixture  = contacts_fixture();
    $pdo      = contacts_pdo();
    $importer = contacts_importer();

    // Two dated years, and an active one that is neither.
    $pdo->exec("UPDATE show_year SET starts_on = '2026-06-01', ends_on = '2027-05-31'");
    $activeId = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();

    $pdo->exec(
        "INSERT INTO show_year (label, starts_on, ends_on, is_open, is_active)"
        . " VALUES ('AD-2026', '2025-06-01', '2026-05-31', 1, 0)"
    );
    $earlier = (int) $pdo->lastInsertId();

    $path  = contacts_csv([
        ['9000002', '10/14/2025', 'call', '', 'inside the earlier year'],
        ['9000005', '10/14/2020', 'call', '', 'before every year on record'],
    ]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    unlink($path);

    $preview = $importer->preview($batch);
    assertSame($earlier, (int) $preview['rows'][0]['show_year_id'], 'the year that contains it');
    assertSame('', (string) $preview['rows'][0]['outcome_kind'], 'and nothing was assumed');

    // No year covers 2020, so it goes to the active one AND SAYS SO. A
    // contact has to be keyed to a year for "contacted this year" to be
    // answerable at all, so the fallback exists — it is just never silent.
    assertSame($activeId, (int) $preview['rows'][1]['show_year_id']);
    assertSame(ContactImporter::YEAR_ASSUMED, (string) $preview['rows'][1]['outcome_kind']);

    $pdo->exec('DELETE FROM contact_import_row');
    $pdo->exec('DELETE FROM contact_import_batch');
    $pdo->prepare('DELETE FROM show_year WHERE id = :id')->execute([':id' => $earlier]);
    $pdo->exec('UPDATE show_year SET starts_on = NULL, ends_on = NULL');
});

test('a file with member numbers needs no team at all', function (): void {
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path  = contacts_csv(
        [['9000002', '10/14/2025', 'call', '', 'by number, committee-wide']],
        ['Customer Number', 'Date', 'Type', 'Contacted By', 'Notes']
    );
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], null);
    unlink($path);

    assertSame(1, $importer->apply($batch, $fixture['officer_user'])['inserted']);
});

test('a member number that a spreadsheet turned into a float still matches', function (): void {
    // 9000002.0 is what Excel writes when it decides a seven-digit identifier
    // is a number. Leading zeros are NOT stripped — 0012345 is not 12345, and
    // CLAUDE.md requires a leading zero to survive a round trip.
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path  = contacts_csv([['9000002.0', '10/14/2025', 'call', '', 'a float']]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    unlink($path);

    assertSame(1, $importer->preview($batch)['counts']['insert']);
});

test('a load without an active show year, or without an officer, is refused outright', function (): void {
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path = contacts_csv([['9000002', '10/14/2025', 'call', '', 'nowhere to go']]);

    assertThrows(
        static fn () => $importer->stage($path, 'h.csv', 999999, $fixture['team']),
        'needs an account to attribute'
    );

    contacts_pdo()->exec('UPDATE show_year SET is_active = 0');
    assertThrows(
        static fn () => $importer->stage($path, 'h.csv', $fixture['officer_user'], $fixture['team']),
        'No show year is active'
    );
    contacts_pdo()->exec('UPDATE show_year SET is_active = 1 ORDER BY id LIMIT 1');

    unlink($path);
});

test('a load is written to the audit log with its counts and its file', function (): void {
    // Eighty rows appearing in a member's history at once, months after the
    // fact, is exactly the thing somebody questions later. This is the answer.
    $fixture  = contacts_fixture();
    $importer = contacts_importer();

    $path  = contacts_csv([
        ['9000002', '10/14/2025', 'call', '', 'one'],
        ['9999999', '10/15/2025', 'call', '', 'not a member'],
    ]);
    $batch = $importer->stage($path, 'history.csv', $fixture['officer_user'], $fixture['team']);
    $importer->apply($batch, $fixture['officer_user']);
    unlink($path);

    $row = contacts_pdo()->query(
        "SELECT * FROM audit_log WHERE action = 'import_contact_history' ORDER BY id DESC LIMIT 1"
    )->fetch();

    assertTrue(is_array($row), 'the load is in the audit log');
    assertSame('contact_import_batch', (string) $row['entity']);
    assertSame((string) $batch, (string) $row['entity_id']);
    assertSame($fixture['officer_user'], (int) $row['actor_user_id']);

    $after = json_decode((string) $row['after_json'], true);
    assertSame('history.csv', $after['filename']);
    assertSame(1, $after['inserted']);
    assertSame(1, $after['skipped']);
});

// ---------------------------------------------------------------------------
// The rules held in place by reading the source
// ---------------------------------------------------------------------------

test('the contact importer can never update or delete a contact', function (): void {
    // contact_log is append-only forever (spec 5.5). A history load can be
    // wrong the same way a typed contact can be wrong, and it is corrected
    // the same way — by logging a correcting contact — because "what did
    // somebody believe in October" has to stay answerable in March.
    //
    // The staging tables are exempt and deliberately so: nothing in them has
    // ever been in contact_log, and an abandoned preview that stayed forever
    // would offer an apply button for a file somebody decided against.
    $source = (string) file_get_contents(__DIR__ . '/../app/src/Import/ContactImporter.php');
    assertTrue($source !== '', 'ContactImporter.php is readable');

    foreach (['contact_log', 'member', 'member_metric', 'assignment', 'audit_log'] as $table) {
        assertSame(
            0,
            preg_match('/\bDELETE\s+FROM\s+`?' . $table . '`?\b/i', $source),
            'ContactImporter must never DELETE FROM ' . $table
        );
        assertSame(
            0,
            preg_match('/\bUPDATE\s+`?' . $table . '`?\s+SET\b/i', $source),
            'ContactImporter must never UPDATE ' . $table
        );
    }

    assertSame(
        0,
        preg_match('/\bTRUNCATE\b|\bDROP\s+TABLE\b/i', $source),
        'and contains no TRUNCATE or DROP'
    );

    // The two DELETEs it does have are the staging tables, and only those.
    assertSame(
        2,
        preg_match_all('/\bDELETE\s+FROM\s+contact_import_(row|batch)\b/i', $source),
        'exactly two DELETEs, both against staging'
    );
    assertSame(
        2,
        preg_match_all('/\bDELETE\s+FROM\b/i', $source),
        'and no others'
    );
});

test('a loaded contact is marked as loaded wherever its history is read', function (): void {
    // A reader is entitled to know how sure to be. The date on a loaded
    // contact is the officer's recollection of when it was, not this
    // application's record of when it was typed — the same distinction it
    // already draws between an imported metric value and a progress note
    // somebody set.
    $reads = (string) file_get_contents(__DIR__ . '/../app/src/Roster/MemberReads.php');
    assertTrue(
        str_contains($reads, 'c.contact_import_batch_id'),
        'the contact history read carries the provenance column'
    );
    assertTrue(
        str_contains($reads, "'from_history'"),
        'and hands it to the view as a flag rather than an id'
    );

    $view = (string) file_get_contents(__DIR__ . '/../app/views/roster.php');
    assertTrue(
        str_contains($view, 'loaded from history'),
        'and the member history says so on screen'
    );
});

test('the route is guarded by its own Admin capability, not by the roster import', function (): void {
    // Spec 6.7. import_roster means "may refresh what Rodeo Houston knows";
    // this means "may write rows into the permanent contact record, and
    // attribute them to other people". Two powers, two names, so either can
    // be held without the other.
    assertSame(
        Capability::ImportContactHistory->value,
        Routes::guard('import-contacts'),
        'the route names the capability'
    );
    assertSame(Level::Admin, Capability::ImportContactHistory->minimumLevel());
    assertSame(Scope::Everywhere, Capability::ImportContactHistory->scope());
    assertTrue(
        Capability::ImportContactHistory !== Capability::ImportRoster,
        'and it is not the roster import wearing a second hat'
    );
});

test('contact history fixtures are cleaned up', function (): void {
    // schema_test.php runs after this file and asserts that exactly five
    // divisions exist and that (No Division) is the only placeholder, so this
    // has to hand the database back the way it found it.
    contacts_reset();

    $pdo = contacts_pdo();
    assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM contact_log')->fetchColumn());
    assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM contact_import_batch')->fetchColumn());
    assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM team')->fetchColumn());
});
