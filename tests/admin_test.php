<?php

declare(strict_types=1);

/**
 * Phase 8 — the six Admin screens (spec 7.5): Designate Users, Flagged for
 * Purge, the export, Show Year and its rollover, the Audit Log, and Manage
 * Teams.
 *
 * What most of this file is about is the two rules the whole phase turns on,
 * and both of them are rules about what does NOT happen:
 *
 *   * **Nothing deletes a member or a contact.** A purge is `purged_at`, a
 *     revoke deactivates rather than removes, closing a show year freezes,
 *     and a rollover copies. Each of those is asserted twice — once by
 *     counting the rows that survive, and once by reading the source of the
 *     file that could have deleted them.
 *   * **An import owns what Rodeo Houston knows; we own the rest.** A grant
 *     survives an import, and the export writes `(No Division)` back as
 *     blank because that bucket is our bookkeeping and not theirs.
 *
 * The fixture is generated and the expectations are TRANSCRIBED beside it,
 * never computed by the code under test.
 *
 * Generated, never real: this repository is public. Member numbers here are
 * 'AD000001', addresses are @example.com and phones are the reserved
 * (555) 555-01xx fiction range.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\Admin\Designate;
use Rerm\Admin\DesignatePage;
use Rerm\Admin\ExportPage;
use Rerm\Admin\Purge;
use Rerm\Admin\PurgePage;
use Rerm\Admin\ShowYears;
use Rerm\Admin\TeamsPage;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Scope;
use Rerm\Auth\User;
use Rerm\Export\RosterExport;
use Rerm\Export\XlsxWriter;
use Rerm\Import\HeaderMap;
use Rerm\Roster\AssignPage;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
use Rerm\Roster\Spreadsheet;
use Rerm\Routes;

// ---------------------------------------------------------------------------
// The routes, the matrix and the rules that need no database
// ---------------------------------------------------------------------------

test('every Phase 8 route is guarded by the capability spec 7.5 gives it', function (): void {
    // TRANSCRIBED from spec 7.5, not read out of Routes.
    $expected = [
        'designate' => Capability::DesignateAllowedUser,
        // The second half of the import lifecycle (spec 6.5), so it carries
        // the import's capability rather than a seventh being invented.
        'purge'     => Capability::ImportRoster,
        'export'    => Capability::ExportRoster,
        'show-year' => Capability::ManageShowYear,
        'audit'     => Capability::ViewAuditLog,
        'teams'     => Capability::ManageTeams,
    ];

    foreach ($expected as $route => $capability) {
        assertSame($capability->value, Routes::guard($route), "route {$route}");
    }
});

test('export_roster is Officer / Scoped — the one capability Phase 8 moved', function (): void {
    // Phase 8 decided 3. Transcribed here as well as in access_test.php,
    // because this is the phase that moved it and this is where the reason
    // lives: ONE export, every row through ScopedQuery::forUser(), so
    // breadth is a consequence of who is asking.
    assertSame(Level::Officer, Capability::ExportRoster->minimumLevel());
    assertSame(Scope::Scoped, Capability::ExportRoster->scope());

    // The shape it now shares with view_roster, for the same reason.
    assertSame(
        Capability::ViewRoster->minimumLevel(),
        Capability::ExportRoster->minimumLevel(),
        'the export floor is view_roster\'s floor'
    );
    assertSame(Capability::ViewRoster->scope(), Capability::ExportRoster->scope());
});

test('the spec table says Officer / Scoped too', function (): void {
    // The document and the code have to agree: a spec that contradicts the
    // application is worse than no spec, and this repository's specs have
    // been authoritative through eight phases.
    $spec = (string) file_get_contents(__DIR__ . '/../docs/spec-v1.md');
    assertTrue($spec !== '', 'docs/spec-v1.md is readable');

    assertTrue(
        str_contains($spec, '| `export_roster` | Officer | Scoped |'),
        'spec 4.5 lists export_roster as Officer / Scoped'
    );
    assertSame(
        0,
        substr_count($spec, '| `export_roster` | Admin | Everywhere |'),
        'the old row is gone, not merely joined by a new one'
    );
});

test('every Phase 8 menu tile points at its screen', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../app/views/menu.php');
    assertTrue($source !== '', 'app/views/menu.php is readable');

    // No tile anywhere still promises Phase 8: this is Phase 8.
    assertSame(0, substr_count($source, "'phase' => 'Phase 8'"), 'nothing still says "Arrives with Phase 8"');

    foreach (['designate', 'purge', 'export', 'show-year', 'audit', 'teams'] as $route) {
        assertTrue(
            str_contains($source, "'route' => '{$route}'"),
            "the menu links to /{$route}"
        );
    }
});

test('team.area appears nowhere in Access, ScopedQuery or the eligibility rule', function (): void {
    // Phase 8 makes the column Admin-editable, which is exactly when this
    // assertion earns its keep: a permission that read a column somebody can
    // rename would move with a cosmetic edit.
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

test('nothing in Phase 8 can delete a member, a contact or an assignment', function (): void {
    // The single most important rule in the application (CLAUDE.md, spec 5.5,
    // 6.5), asserted by reading the source of every file that writes. A purge
    // is purged_at, a revoke deactivates, closing freezes and a rollover
    // copies — so a DELETE anywhere in here is a bug by definition.
    foreach ([
        'Admin/Purge.php',
        'Admin/PurgePage.php',
        'Admin/Designate.php',
        'Admin/DesignatePage.php',
        'Admin/ShowYears.php',
        'Admin/TeamsPage.php',
        'Admin/AuditPage.php',
        'Admin/ExportPage.php',
        'Export/RosterExport.php',
    ] as $file) {
        $source = (string) file_get_contents(__DIR__ . '/../app/src/' . $file);
        assertTrue($source !== '', $file . ' is readable');
        assertSame(
            0,
            preg_match('/\bDELETE\s+FROM\b/i', $source),
            $file . ' must contain no DELETE'
        );
        assertSame(
            0,
            preg_match('/\bTRUNCATE\b|\bDROP\s+TABLE\b/i', $source),
            $file . ' must contain no TRUNCATE or DROP'
        );
    }
});

test('the Audit Log screen has no write path at all', function (): void {
    // Spec 7.5 read-only. The absence is asserted rather than assumed: an
    // audit row is append-only and outlives what it describes, and a log
    // somebody can edit answers no question worth asking.
    $source = (string) file_get_contents(__DIR__ . '/../app/views/audit.php');
    assertTrue($source !== '', 'app/views/audit.php is readable');

    assertSame(0, preg_match('/method=["\']post/i', $source), 'no POST');
    assertSame(0, preg_match('/\bCsrf\b/', $source), 'nothing to protect, so nothing to check');
});

test('every Phase 8 form that writes carries a CSRF field', function (): void {
    // Reaching a route proves nothing (CLAUDE.md). audit.php is excluded
    // above BECAUSE it has no form; every other Phase 8 view has one.
    foreach (['designate', 'purge', 'export', 'show-year', 'teams'] as $view) {
        $source = (string) file_get_contents(__DIR__ . '/../app/views/' . $view . '.php');
        assertTrue($source !== '', $view . '.php is readable');

        $posts = preg_match_all('/method="post"/i', $source);
        $fields = substr_count($source, 'Csrf::field()');

        assertTrue($posts > 0, $view . '.php has at least one POST form');
        assertSame($posts, $fields, $view . '.php has one Csrf::field() per POST form');
    }
});

test('the audit vocabulary is a type, and every writer uses it', function (): void {
    // Phase 8, open 2. The Audit Log filters by action, so the vocabulary has
    // to BE something — a free-text filter silently matches nothing the first
    // time somebody writes password_change for password_changed.
    //
    // Transcribed: every action string the application writes, as the column
    // holds it. A new one has to be added here on purpose.
    $expected = [
        'set_master_password', 'password_changed', 'password_reset_requested',
        'password_reset_completed', 'auth_token_refused',
        'import_applied', 'import_failed', 'import_reset_progress',
        'assign_officer', 'remove_assignment',
        'grant_level', 'revoke_level', 'set_scope_override',
        'purge_member', 'restore_member',
        'create_show_year', 'set_active_show_year', 'open_show_year',
        'close_show_year', 'carry_assignments',
        'set_team_area', 'export_roster',
    ];

    $actual = array_map(static fn (Action $a): string => $a->value, Action::cases());
    sort($expected);
    sort($actual);
    assertSame($expected, $actual, 'the enum holds exactly the transcribed vocabulary');

    // Every case has a label; none falls through a match.
    foreach (Action::cases() as $action) {
        assertTrue($action->label() !== '', $action->value . ' has a label');
    }

    // Reading is tolerant, writing is not: a string from before the enum
    // existed still renders, as itself. An audit log that throws on its own
    // history is an audit log nobody can open.
    assertSame('Level granted', Action::describe('grant_level'));
    assertSame('something_a_migration_wrote', Action::describe('something_a_migration_wrote'));

    // No bare action literal is left in a writer.
    foreach ([
        'app/src/Auth/Auth.php',
        'app/src/Import/Importer.php',
        'app/src/Roster/AssignOfficers.php',
        'public/index.php',
        'bin/set-admin-password.php',
    ] as $file) {
        $source = (string) file_get_contents(__DIR__ . '/../' . $file);
        foreach (["':action'     => '", "':action'      => '", "'action'    => '"] as $needle) {
            assertSame(0, substr_count($source, $needle), $file . ' writes actions through the enum');
        }
    }
});

test('the search term travels back through a whitelist that cannot inject a header', function (): void {
    // Phase 8 added one rule to return_query(): ['text' => n], for the
    // Designate Users search term. Every other rule is a list of permitted
    // VALUES, which a search box cannot be — its whole purpose is to be
    // anything somebody types.
    //
    // What the whitelist protects against is a crafted `return` smuggling an
    // extra PARAMETER into the redirect, and what makes the free-text rule
    // safe is that the value never reaches a Location header unencoded:
    // http_build_query() percent-encodes &, = and — the one that matters —
    // CR and LF.
    $hostile = "smith\r\nLocation: https://evil.example.com\r\n\r\n";
    $query   = http_build_query(['q' => $hostile]);

    assertSame(0, substr_count($query, "\r"), 'no carriage return survives');
    assertSame(0, substr_count($query, "\n"), 'no line feed survives');
    assertSame(1, substr_count($query, '='), 'and no second parameter appears');

    // And the rule is bounded, so a megabyte pasted into the box does not
    // become a megabyte of Location header.
    $source = (string) file_get_contents(__DIR__ . '/../public/index.php');
    assertTrue(
        str_contains($source, "array_key_exists('text', \$rule)"),
        'the text rule exists'
    );
    assertTrue(
        str_contains($source, "mb_substr(trim(\$value), 0, (int) \$rule['text'])"),
        'and it truncates rather than passing the value through whole'
    );
    assertTrue(
        str_contains($source, "'q'    => ['text' => 120],"),
        'the designate search term is the only thing that uses it, at 120 characters'
    );

    // Everything else in the input is still dropped, key included: the text
    // rule is one more entry in the table, never a bypass of it.
    assertSame(
        1,
        substr_count($source, "array_key_exists('text', \$rule)"),
        'one branch, in the one whitelist'
    );
});

test('the deployment manifest creates var/exports, private and outside the document root', function (): void {
    // The export writes two temp files there. The directory has to exist and
    // be writable BEFORE somebody presses the button, and it must never be
    // web-reachable: the file is ~1,950 people's home addresses.
    $manifest = (string) file_get_contents(__DIR__ . '/../.cpanel.yml');
    assertTrue($manifest !== '', '.cpanel.yml is readable');

    assertTrue(str_contains($manifest, '$APP_DIR/var/exports'), 'var/exports is created');
    assertTrue(
        preg_match('/chmod 0700 [^\n]*\$APP_DIR\/var\/exports/', $manifest) === 1,
        'var/exports is 0700'
    );
    assertSame(
        0,
        substr_count($manifest, '$WEB_DIR/var'),
        'nothing puts var/ under the web directory'
    );
});

// ---------------------------------------------------------------------------
// The .xlsx writer, which needs no database (Phase 8, open 1)
// ---------------------------------------------------------------------------

/** A writable scratch directory for the writer tests. */
function ad_export_dir(): string
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];
    $dir = $app->path('var/exports');

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    if (!is_dir($dir) || !is_writable($dir)) {
        skip("var/exports is not writable — it is created by .cpanel.yml on the server");
    }

    return $dir;
}

test('a column index becomes a spreadsheet column name past Z', function (): void {
    // The export is 55 columns wide, so BC is reachable and this is not
    // theoretical arithmetic.
    assertSame('A', XlsxWriter::columnName(0));
    assertSame('Z', XlsxWriter::columnName(25));
    assertSame('AA', XlsxWriter::columnName(26));
    assertSame('AZ', XlsxWriter::columnName(51));
    assertSame('BA', XlsxWriter::columnName(52));
    assertSame('BC', XlsxWriter::columnName(54));
});

test('a sheet name Excel would refuse is made into one it accepts', function (): void {
    assertSame('Roster 2027', XlsxWriter::sheetName('Roster 2027'));
    assertSame('Roster 2026 2027', XlsxWriter::sheetName('Roster 2026/2027'));
    assertSame('Sheet1', XlsxWriter::sheetName('   '));
    assertSame(31, mb_strlen(XlsxWriter::sheetName(str_repeat('long ', 20))));
});

test('a control character is stripped rather than escaped', function (): void {
    // Below 0x20, only tab, newline and carriage return are legal in XML 1.0
    // AT ALL — not even escaped — and a contact note pasted out of a mail
    // client is exactly where one arrives. A workbook Excel refuses to open
    // is worse than a note missing a character nobody can see.
    assertSame('ab', XlsxWriter::xmlEscape("a\x00\x07b"));
    assertSame("a\tb\nc", XlsxWriter::xmlEscape("a\tb\nc"));
    assertSame('&lt;b&gt; &amp; &quot;q&quot;', XlsxWriter::xmlEscape('<b> & "q"'));
});

test('a cell longer than the format allows is truncated, not left to break the file', function (): void {
    $long = str_repeat('x', XlsxWriter::MAX_CELL_CHARS + 500);
    $out  = XlsxWriter::xmlEscape($long);

    assertSame(XlsxWriter::MAX_CELL_CHARS, mb_strlen($out));
    assertTrue(str_ends_with($out, '…'), 'the truncation is visible');
});

test('the writer round-trips through our own reader, strings intact', function (): void {
    // The one assertion the whole export rests on: leading zeros survive, and
    // no cell becomes a float. Customer Number 0012345 must come back as
    // '0012345' and not as 12345.
    $writer = XlsxWriter::create(ad_export_dir(), 'Roster 2027');
    $writer->addRow(['Customer Number', 'Name', 'Note', 'Blank', 'Last']);
    $writer->addRow(['0012345', "O'Brien & Sons <b>", "line one\nline two", '', 'Ω ünïcode']);
    $writer->addRow(['1234567', 'Plain', '', '', '']);

    $path = $writer->finish();

    try {
        assertTrue(is_file($path), 'the archive exists');

        $reader = Spreadsheet::open($path);
        assertSame(['Roster 2027'], $reader->sheets());

        $rows = [];
        foreach ($reader->rows() as $row) {
            $rows[] = $row;
        }

        assertSame(3, count($rows));
        assertSame(['Customer Number', 'Name', 'Note', 'Blank', 'Last'], $rows[0]);

        // The leading zero, and every character a naive writer would break on.
        assertSame('0012345', $rows[1][0]);
        assertSame("O'Brien & Sons <b>", $rows[1][1]);
        assertSame("line one\nline two", $rows[1][2]);
        assertSame('', $rows[1][3], 'an omitted cell reads back as blank, in position');
        assertSame('Ω ünïcode', $rows[1][4]);

        assertSame('1234567', $rows[2][0], 'a seven-digit number is a STRING, not 1234567.0');
    } finally {
        $writer->close($path);
    }

    assertTrue(!is_file($path), 'the archive is unlinked — an export must not survive on disk');
});

test('the writer cleans up its temp files even when nobody calls finish()', function (): void {
    $dir    = ad_export_dir();
    $before = array_diff((array) scandir($dir), ['.', '..']);

    (static function () use ($dir): void {
        $writer = XlsxWriter::create($dir, 'Abandoned');
        $writer->addRow(['a']);
        // $writer falls out of scope here; the destructor unlinks the sheet.
    })();

    $after = array_diff((array) scandir($dir), ['.', '..']);
    assertSame(array_values($before), array_values($after), 'no temp file was left behind');
});

test('the export header row is HeaderMap plus what the app generated', function (): void {
    $headers = RosterExport::headers();

    // Rodeo Houston's columns come first, in HeaderMap's order and spelling,
    // and are never retyped anywhere.
    assertSame(
        HeaderMap::EXPORTED,
        array_slice($headers, 0, count(HeaderMap::EXPORTED)),
        'the imported columns lead, in HeaderMap order'
    );

    // Two of the observed export's 33 columns are not written back, and each
    // omission is a decision rather than an oversight.
    assertTrue(
        !in_array(HeaderMap::SUBCOMMITTEE_2, HeaderMap::EXPORTED, true),
        'Subcommittee 2 is junk and is not imported, so there is nothing to write back'
    );
    assertTrue(
        !in_array(HeaderMap::NAME, HeaderMap::EXPORTED, true),
        'Name is not imported; reconstructing it would send Rodeo Houston a value we invented'
    );

    // Subcommittee 2 was already absent from KNOWN — the import never read it
    // — so relative to KNOWN the export drops exactly one more column, Name.
    assertSame(
        count(HeaderMap::KNOWN) - 1,
        count(HeaderMap::EXPORTED),
        'EXPORTED is KNOWN minus Name, and nothing else'
    );
    assertSame(31, count(HeaderMap::EXPORTED), 'thirty-one of the export\'s thirty-three columns');

    // Every exported header is a HeaderMap constant, never a string typed
    // twice.
    $constants = (new ReflectionClass(HeaderMap::class))->getConstants();
    foreach (HeaderMap::EXPORTED as $column) {
        assertTrue(in_array($column, $constants, true), "{$column} is a HeaderMap constant");
    }

    // The four SCORED metrics get four columns each; harassment training gets
    // one. It is not one of the four and has no progress workflow (spec 5.4),
    // so an empty "Progress By" column for it would suggest one exists.
    foreach (Metric::scored() as $metric) {
        foreach ([' Status', ' Progress', ' Progress By', ' Progress At'] as $suffix) {
            assertTrue(
                in_array($metric->label() . $suffix, $headers, true),
                $metric->label() . $suffix . ' is a column'
            );
        }
    }
    assertTrue(in_array('Harassment Training Status', $headers, true));
    assertTrue(
        !in_array('Harassment Training Progress', $headers, true),
        'the fifth metric is shown, never scored'
    );

    assertSame(55, count($headers), 'the export is 55 columns');
    assertSame(count($headers), count(array_unique($headers)), 'no column name appears twice');
});

// ---------------------------------------------------------------------------
// The fixture
// ---------------------------------------------------------------------------

function ad_pdo(): PDO
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
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'"
    )->fetchColumn();

    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/**
 * The show year that was active before this suite touched anything.
 *
 * This file is the only one that ACTIVATES a show year, and every other suite
 * reads the seeded active row. Captured on first call — which ad_fixture()
 * makes before it creates anything — and put back by ad_teardown(), so a
 * failure here cannot take the rest of the directory down with it.
 */
function ad_seeded_active_year(PDO $pdo): ?int
{
    static $id = null;
    static $read = false;

    if (!$read) {
        $read  = true;
        $value = $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();
        $id    = $value === false ? null : (int) $value;
    }

    return $id;
}

function ad_teardown(PDO $pdo): void
{
    // Before anything is deleted: whatever this suite made active has to stop
    // being active, or the delete below leaves the database with no active
    // show year at all — which every screen in the application reads.
    $seeded = ad_seeded_active_year($pdo);
    if ($seeded !== null) {
        $pdo->exec('UPDATE show_year SET is_active = 0 WHERE is_active = 1');
        $pdo->prepare('UPDATE show_year SET is_active = 1 WHERE id = :id')->execute([':id' => $seeded]);
    }

    $members = "SELECT id FROM member WHERE member_number LIKE 'AD%'";
    $users   = "SELECT id FROM app_user WHERE member_id IN ({$members})";
    $years   = "SELECT id FROM show_year WHERE label LIKE 'AD-%'";

    // RESTRICT-safe order, outside in: audit rows point at app_user,
    // assignments at both member and app_user, member_metric at member and
    // show_year, and the show years this fixture made are last.
    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
    $pdo->exec("DELETE FROM audit_log WHERE entity_id LIKE 'AD%'");
    $pdo->exec("DELETE FROM contact_log WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM assignment WHERE member_id IN ({$members}) OR officer_member_id IN ({$members})");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    // app_user.granted_by references app_user, so one fixture account
    // granting another makes the table its own parent. Cleared first, or the
    // delete below trips fk_app_user_granted_by.
    $pdo->exec("UPDATE app_user SET granted_by = NULL WHERE granted_by IN ({$users}) OR member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'AD%'");
    $pdo->exec("DELETE FROM import_warning WHERE import_batch_id IN (SELECT id FROM import_batch WHERE filename LIKE 'AD-%')");
    $pdo->exec("DELETE FROM import_batch WHERE filename LIKE 'AD-%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE '% AD'");
    $pdo->exec("DELETE FROM division WHERE name LIKE 'AD %'");
    $pdo->exec("DELETE FROM member_metric WHERE show_year_id IN ({$years})");
    $pdo->exec("DELETE FROM assignment WHERE show_year_id IN ({$years})");
    $pdo->exec("DELETE FROM contact_log WHERE show_year_id IN ({$years})");
    $pdo->exec("DELETE FROM show_year WHERE label LIKE 'AD-%'");
}

/**
 * Two divisions, three teams and eleven members, shaped so every Phase 8
 * question has somebody to ask it about.
 *
 *   AD Alpha Division      Alpha Team AD      adm  exec  senior  cap  m1 m2 m3
 *                          Alpha Bare AD      (empty — for the area editor)
 *   AD (No Division)       Beta Team AD       n1   n2                 <- the
 *                                             placeholder, exported as BLANK
 *
 *   flagged   absent_since_import_id set, purged_at NULL
 *   purged    purged_at set already, so Restore has something to restore
 *
 * `cap` is a Captain on Alpha Team and the only eligible officer; `m1` is
 * assigned to them. `senior` is a Coordinator scoped to Alpha Division.
 *
 * @return array<string, mixed>
 */
function ad_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = ad_pdo();
    ad_seeded_active_year($pdo);
    ad_teardown($pdo);

    // The fixture's OWN show years. Every suite here shares one database and
    // runs in one process, so acting on the seeded active year would be
    // acting on whatever else is loaded at the time.
    $pdo->exec("INSERT INTO show_year (label, is_open, is_active) VALUES ('AD-2027', 1, 0)");
    $thisYear = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO show_year (label, is_open, is_active) VALUES ('AD-2028', 1, 0)");
    $nextYear = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO division (name, is_placeholder) VALUES ('AD Alpha Division', 0)");
    $alpha = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO division (name, is_placeholder) VALUES ('AD (No Division)', 1)");
    $none = (int) $pdo->lastInsertId();

    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id, area) VALUES (:n, :d, :a)');
    $insertTeam->execute([':n' => 'Alpha Team AD', ':d' => $alpha, ':a' => 'Reed Road']);
    $alphaTeam = (int) $pdo->lastInsertId();
    $insertTeam->execute([':n' => 'Alpha Bare AD', ':d' => $alpha, ':a' => null]);
    $bareTeam = (int) $pdo->lastInsertId();
    $insertTeam->execute([':n' => 'Beta Team AD', ':d' => $none, ':a' => 'Chuckwagon']);
    $betaTeam = (int) $pdo->lastInsertId();

    // An import batch, so the flagged members have a batch to name.
    $pdo->prepare(
        'INSERT INTO import_batch (show_year_id, mode, filename, sha256, rows_read, dry_run, applied_at)'
        . " VALUES (:y, 'complete', 'AD-roster.xls', :sha, 11, 0, UTC_TIMESTAMP())"
    )->execute([':y' => $thisYear, ':sha' => str_repeat('a', 64)]);
    $batch = (int) $pdo->lastInsertId();

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, full_name, prefix,'
        . ' address, city, state, zip, phone, phone_e164, phone_type, email,'
        . ' title, title_level, division_id, team_id, is_rookie, legal_name_verified,'
        . ' absent_since_import_id, purged_at, last_seen_import_id)'
        . ' VALUES (:n, :f, :l, :p, :fn, :px, :a, :c, :s, :z, :ph, :pe, :pt, :em,'
        . '  :t, :tl, :d, :tm, :rk, :lv, :absent, :purged, :seen)'
    );

    /**
     * key => [number, first, last, title, level, division, team, absent, purged]
     */
    $people = [
        'adm'    => ['AD000001', 'Ada',   'Adminson', 'Chairman',           'executive_officer', $alpha, $alphaTeam, false, false],
        'exec'   => ['AD000002', 'Evan',  'Exec',     'Division Chairman',  'executive_officer', $alpha, $alphaTeam, false, false],
        'senior' => ['AD000003', 'Sara',  'Senior',   'Coordinator',        'senior_officer',    $alpha, $alphaTeam, false, false],
        'cap'    => ['AD000004', 'Cal',   'Captain',  'Captain',            'officer',           $alpha, $alphaTeam, false, false],
        'm1'     => ['AD000005', 'Mina',  'Member',   'Committee Member',   'member',            $alpha, $alphaTeam, false, false],
        'm2'     => ['AD000006', 'Milo',  'Member',   'Committee Member',   'member',            $alpha, $alphaTeam, false, false],
        'm3'     => ['AD000007', 'Mabel', 'Member',   'Committee Member',   'member',            $alpha, $bareTeam,  false, false],
        'n1'     => ['AD000008', 'Nate',  'Nodiv',    'Committee Member',   'member',            $none,  $betaTeam,  false, false],
        'n2'     => ['AD000009', 'Nora',  'Nodiv',    'Committee Member',   'member',            $none,  $betaTeam,  false, false],
        'flagged'=> ['AD000010', 'Fern',  'Flagged',  'Committee Member',   'member',            $alpha, $alphaTeam, true,  false],
        'purged' => ['AD000011', 'Percy', 'Purged',   'Committee Member',   'member',            $alpha, $alphaTeam, false, true],
    ];

    $ids = [];
    foreach ($people as $key => $row) {
        [$number, $first, $last, $title, $level, $division, $team, $absent, $purged] = $row;

        $insertMember->execute([
            ':n' => $number, ':f' => $first, ':l' => $last,
            ':p' => '', ':fn' => $first . ' ' . $last, ':px' => '',
            ':a' => '1 Example Way', ':c' => 'Houston', ':s' => 'TX', ':z' => '77001',
            ':ph' => '(555) 555-0100', ':pe' => '+15555550100', ':pt' => 'CELL PHONE',
            ':em' => strtolower($key) . '@example.com',
            ':t' => $title, ':tl' => $level, ':d' => $division, ':tm' => $team,
            ':rk' => 0, ':lv' => 1,
            ':absent' => $absent ? $batch : null,
            ':purged' => $purged ? '2026-08-01 12:00:00' : null,
            ':seen'   => $absent ? null : $batch,
        ]);
        $ids[$key] = (int) $pdo->lastInsertId();
    }

    // Accounts for everybody whose title grants a login.
    $insertUser = $pdo->prepare(
        'INSERT INTO app_user (member_id, level, password_hash, must_change_password, is_active)'
        . " VALUES (:m, :l, '*', 0, 1)"
    );
    $users = [];
    foreach (['adm' => 'admin', 'exec' => 'executive_officer', 'senior' => 'senior_officer', 'cap' => 'officer'] as $key => $level) {
        $insertUser->execute([':m' => $ids[$key], ':l' => $level]);
        $users[$key] = (int) $pdo->lastInsertId();
    }

    // Metrics for this year: m1 complete on dues, m2 outstanding, and a
    // harassment-training row left 'unknown' so the tri-state is exercised.
    $insertMetric = $pdo->prepare(
        'INSERT INTO member_metric (member_id, show_year_id, metric, imported_value, progress,'
        . ' progress_by, progress_at, progress_note)'
        . ' VALUES (:m, :y, :me, :iv, :pr, :by, :at, :note)'
    );
    foreach (Metric::cases() as $metric) {
        foreach (['m1' => 'Y', 'm2' => 'N', 'n1' => 'N'] as $key => $value) {
            $harassment = $metric === Metric::HarassmentTraining;

            $insertMetric->execute([
                ':m'  => $ids[$key],
                ':y'  => $thisYear,
                ':me' => $metric->value,
                ':iv' => $harassment ? 'unknown' : $value,
                ':pr' => ($key === 'm2' && $metric === Metric::CommitteeDues) ? 'in_progress' : 'not_started',
                ':by' => ($key === 'm2' && $metric === Metric::CommitteeDues) ? $users['cap'] : null,
                ':at' => ($key === 'm2' && $metric === Metric::CommitteeDues) ? '2026-08-02 09:00:00' : null,
                ':note' => '',
            ]);
        }
    }

    // One contact and one assignment, so the export's generated columns and
    // the rollover both have something real to carry.
    $pdo->prepare(
        'INSERT INTO contact_log (member_id, show_year_id, contacted_by, contact_type, occurred_at, notes)'
        . " VALUES (:m, :y, :u, 'call', '2026-08-03 15:00:00', 'Said they would pay this week.')"
    )->execute([':m' => $ids['m2'], ':y' => $thisYear, ':u' => $users['cap']]);

    $insertAssignment = $pdo->prepare(
        'INSERT INTO assignment (member_id, officer_member_id, show_year_id, assigned_by) VALUES (:m, :o, :y, :by)'
    );
    // m1 -> cap: eligible, same team, Officer level. Carries.
    $insertAssignment->execute([':m' => $ids['m1'], ':o' => $ids['cap'], ':y' => $thisYear, ':by' => $users['adm']]);
    // m2 -> m3: m3 is a Committee Member on ANOTHER team. Broken twice over,
    // and the assignment the rollover has to DROP (Phase 8 decided 5).
    $insertAssignment->execute([':m' => $ids['m2'], ':o' => $ids['m3'], ':y' => $thisYear, ':by' => $users['adm']]);

    return $fixture = [
        'this_year' => $thisYear,
        'next_year' => $nextYear,
        'alpha'     => $alpha,
        'none'      => $none,
        'alpha_team' => $alphaTeam,
        'bare_team' => $bareTeam,
        'beta_team' => $betaTeam,
        'batch'     => $batch,
        'ids'       => $ids,
        'users'     => $users,
    ];
}

/** The signed-in User for one of the fixture's accounts. */
function ad_user(string $key): User
{
    $f   = ad_fixture();
    $pdo = ad_pdo();

    $read = $pdo->prepare(
        'SELECT u.id, u.member_id, u.effective_level, u.scope_division_id, u.scope_team_id,'
        . ' u.must_change_password, m.member_number, m.division_id, m.team_id,'
        . ' m.preferred_name, m.first_name, m.last_name'
        . ' FROM app_user u INNER JOIN member m ON m.id = u.member_id WHERE u.id = :id'
    );
    $read->execute([':id' => $f['users'][$key]]);
    $row = $read->fetch();

    assertTrue(is_array($row), "fixture account {$key} exists");

    return User::fromRow($row);
}

/** Fresh state for one member, straight out of the database. */
function ad_account(string $key): ?array
{
    $f    = ad_fixture();
    $read = ad_pdo()->prepare(
        'SELECT level, granted_level, effective_level, granted_by, granted_at, is_active,'
        . ' must_change_password, scope_division_id, scope_team_id'
        . ' FROM app_user WHERE member_id = :m'
    );
    $read->execute([':m' => $f['ids'][$key]]);
    $row = $read->fetch();

    return is_array($row) ? $row : null;
}

// ---------------------------------------------------------------------------
// Designate Users (spec 7.5, 4.4)
// ---------------------------------------------------------------------------

test('the search finds members regardless of title, including those with no account', function (): void {
    $page = DesignatePage::fromApp($GLOBALS['rerm_app'])->page(ad_user('adm'), ['q' => 'Member']);

    $found = [];
    foreach ($page['rows'] as $row) {
        $found[$row['member_number']] = $row;
    }

    // Three Committee Members, none of whom has an account. That is the whole
    // point of the screen: 1,758 of 1,954 are exactly like this.
    assertTrue(isset($found['AD000005']), 'a Committee Member with no account is findable');
    assertSame(false, $found['AD000005']['has_account']);
    assertSame(null, $found['AD000005']['effective_level'], 'no account means NO level, not Member');
    assertSame('none', $found['AD000005']['source']);
});

test('a purged or flagged member cannot be designated', function (): void {
    $page = DesignatePage::fromApp($GLOBALS['rerm_app'])->page(ad_user('adm'), ['q' => 'AD0000']);

    $numbers = array_map(static fn (array $r): string => $r['member_number'], $page['rows']);

    assertTrue(!in_array('AD000010', $numbers, true), 'the flagged member is not listed');
    assertTrue(!in_array('AD000011', $numbers, true), 'the purged member is not listed');
});

test('the list is scoped: a Senior Officer sees their division and no further', function (): void {
    // Phase 8, OI-18. designate_allowed_user is Scoped (spec 4.5), so the
    // list is the rows the actor may actually act on — never a name the write
    // path would then refuse.
    $senior = DesignatePage::fromApp($GLOBALS['rerm_app'])->page(ad_user('senior'), ['q' => 'AD0000']);
    $admin  = DesignatePage::fromApp($GLOBALS['rerm_app'])->page(ad_user('adm'), ['q' => 'AD0000']);

    $seniorNumbers = array_map(static fn (array $r): string => $r['member_number'], $senior['rows']);
    $adminNumbers  = array_map(static fn (array $r): string => $r['member_number'], $admin['rows']);

    // The two (No Division) members are in the Admin's list and not the
    // Senior Officer's.
    assertTrue(in_array('AD000008', $adminNumbers, true), 'the Admin sees the placeholder division');
    assertTrue(!in_array('AD000008', $seniorNumbers, true), 'the Senior Officer does not');

    // And every row a Senior Officer IS shown is one they may act on.
    foreach ($senior['rows'] as $row) {
        assertTrue($row['may_designate'], $row['member_number'] . ' is actionable');
    }
});

test('the grant select offers exactly what Access::mayGrant permits', function (): void {
    $adminLevels  = array_map(
        static fn (Level $l): string => $l->value,
        DesignatePage::grantableBy(ad_user('adm'))
    );
    $seniorLevels = array_map(
        static fn (Level $l): string => $l->value,
        DesignatePage::grantableBy(ad_user('senior'))
    );

    // Spec 4.4, transcribed: at or below your own.
    assertSame(['member', 'officer', 'senior_officer', 'executive_officer', 'admin'], $adminLevels);
    assertSame(['member', 'officer', 'senior_officer'], $seniorLevels);

    // An Officer holds designate_allowed_user at all: no.
    assertSame([], DesignatePage::grantableBy(ad_user('cap')));
});

test('granting a level to somebody with no account creates one, forced to change', function (): void {
    $f      = ad_fixture();
    $result = Designate::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action'    => 'grant',
        'member_id' => (string) $f['ids']['m1'],
        'level'     => 'senior_officer',
    ]);

    assertSame('granted', $result['outcome']);
    assertSame(true, $result['created']);

    $row = ad_account('m1');
    assertTrue($row !== null, 'an account now exists');

    // `level` is seeded from the member's OWN title level, never the grant:
    // it is the title-derived half, and a revoke has to leave the right thing
    // standing behind it.
    assertSame('member', $row['level']);
    assertSame('senior_officer', $row['granted_level']);
    assertSame('senior_officer', $row['effective_level'], 'the VIRTUAL column, not a re-derivation');
    assertSame(1, (int) $row['must_change_password'], 'spec 3.1 route 3');
    assertSame(1, (int) $row['is_active']);
    assertSame($f['users']['adm'], (int) $row['granted_by']);
});

test('a grant is durable: an import that demotes the title leaves it standing', function (): void {
    $f   = ad_fixture();
    $pdo = ad_pdo();

    // What an import does (spec 6.6): it rewrites member.title,
    // member.title_level and app_user.level, and never granted_level.
    $pdo->prepare("UPDATE member SET title = 'Committee Member', title_level = 'member' WHERE id = :id")
        ->execute([':id' => $f['ids']['m1']]);
    $pdo->prepare("UPDATE app_user SET level = 'member' WHERE member_id = :id")
        ->execute([':id' => $f['ids']['m1']]);

    $row = ad_account('m1');
    assertSame('senior_officer', $row['granted_level'], 'the grant survived');
    assertSame(
        'senior_officer',
        $row['effective_level'],
        'granted_level ?? level — the whole point of designation'
    );
    assertSame(1, (int) $row['is_active'], 'the grant holds the account open through a demotion');
});

test('revoking is capped by the GRANTED level, not the target\'s', function (): void {
    $f = ad_fixture();

    // Phase 8 decided 2. A Senior Officer may not revoke an Executive-level
    // grant; the same Senior Officer may revoke an Officer-level one.
    $designate = Designate::fromApp($GLOBALS['rerm_app']);

    $designate->apply(ad_user('adm'), [
        'action' => 'grant', 'member_id' => (string) $f['ids']['m2'], 'level' => 'executive_officer',
    ]);

    $refused = $designate->apply(ad_user('senior'), [
        'action' => 'revoke', 'member_id' => (string) $f['ids']['m2'],
    ]);
    assertSame('refused', $refused['outcome'], 'a Senior Officer cannot revoke an Executive grant');
    assertSame('executive_officer', ad_account('m2')['granted_level'], 'nothing changed');

    // Down to Officer, and now the same Senior Officer can.
    $designate->apply(ad_user('adm'), [
        'action' => 'grant', 'member_id' => (string) $f['ids']['m2'], 'level' => 'officer',
    ]);
    $allowed = $designate->apply(ad_user('senior'), [
        'action' => 'revoke', 'member_id' => (string) $f['ids']['m2'],
    ]);
    assertSame('revoked', $allowed['outcome'], 'and may revoke an Officer grant an Admin made');
});

test('a revoke deactivates the account, never deletes it', function (): void {
    // Spec 6.6: the audit trail outlives the account, and a later grant
    // reactivates the same row rather than creating a second one.
    $row = ad_account('m2');
    assertTrue($row !== null, 'the row is still there after the revoke');
    assertSame(null, $row['granted_level']);
    assertSame('member', $row['effective_level'], 'the title-derived level stands again');
    assertSame(0, (int) $row['is_active'], 'Member level grants no login');

    // And re-granting reopens the SAME row.
    $f = ad_fixture();
    Designate::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action' => 'grant', 'member_id' => (string) $f['ids']['m2'], 'level' => 'officer',
    ]);

    $count = ad_pdo()->prepare('SELECT COUNT(*) FROM app_user WHERE member_id = :m');
    $count->execute([':m' => $f['ids']['m2']]);
    assertSame(1, (int) $count->fetchColumn(), 'one row, reactivated — never a second');
    assertSame(1, (int) ad_account('m2')['is_active']);
});

test('a Senior Officer cannot grant above their own rank, or outside their scope', function (): void {
    $f         = ad_fixture();
    $designate = Designate::fromApp($GLOBALS['rerm_app']);

    $tooHigh = $designate->apply(ad_user('senior'), [
        'action' => 'grant', 'member_id' => (string) $f['ids']['m3'], 'level' => 'admin',
    ]);
    assertSame('bad_level', $tooHigh['outcome']);

    // n1 is in the placeholder division, outside a Senior Officer's scope.
    $outOfScope = $designate->apply(ad_user('senior'), [
        'action' => 'grant', 'member_id' => (string) $f['ids']['n1'], 'level' => 'officer',
    ]);
    assertSame('refused', $outOfScope['outcome']);
    assertSame(null, ad_account('n1'), 'no account was created for somebody out of scope');
});

test('the scope override is Admin-only, and it is what gives (No Division) an owner', function (): void {
    $f         = ad_fixture();
    $designate = Designate::fromApp($GLOBALS['rerm_app']);

    // An Executive Officer is not an Admin, and spec 4.4 says only an Admin
    // sets an override.
    $refused = $designate->apply(ad_user('exec'), [
        'action' => 'scope', 'member_id' => (string) $f['ids']['senior'],
        'scope_division_id' => (string) $f['none'], 'scope_team_id' => '',
    ]);
    assertSame('refused', $refused['outcome']);
    assertSame(null, ad_account('senior')['scope_division_id']);

    // The Admin can, and the Senior Officer's scope moves to the placeholder
    // division — which is the ONLY way those members come to have an owner
    // (spec 5.1a), since nobody's own Subcommittee 3 puts them there.
    $set = $designate->apply(ad_user('adm'), [
        'action' => 'scope', 'member_id' => (string) $f['ids']['senior'],
        'scope_division_id' => (string) $f['none'], 'scope_team_id' => '',
    ]);
    assertSame('scope_set', $set['outcome']);
    assertSame($f['none'], (int) ad_account('senior')['scope_division_id']);

    // And the override is honoured everywhere, not merely stored: the same
    // Senior Officer now sees the placeholder division's members.
    $page = DesignatePage::fromApp($GLOBALS['rerm_app'])->page(ad_user('senior'), ['q' => 'Nodiv']);
    $numbers = array_map(static fn (array $r): string => $r['member_number'], $page['rows']);
    assertTrue(in_array('AD000008', $numbers, true), 'the override moved their scope');

    // Cleared again, so the rest of this file sees the fixture it expects.
    $cleared = $designate->apply(ad_user('adm'), [
        'action' => 'scope', 'member_id' => (string) $f['ids']['senior'],
        'scope_division_id' => '', 'scope_team_id' => '',
    ]);
    assertSame('scope_cleared', $cleared['outcome']);
    assertSame(null, ad_account('senior')['scope_division_id']);
});

test('every grant, revoke and override is in the audit log with real JSON', function (): void {
    $read = ad_pdo()->query(
        "SELECT action, entity, entity_id, before_json, after_json FROM audit_log"
        . " WHERE entity_id LIKE 'AD%' ORDER BY id"
    );
    $rows = $read->fetchAll();

    $actions = array_map(static fn (array $r): string => (string) $r['action'], $rows);

    assertTrue(in_array(Action::GrantLevel->value, $actions, true), 'grants are logged');
    assertTrue(in_array(Action::RevokeLevel->value, $actions, true), 'revocations are logged');
    assertTrue(in_array(Action::SetScopeOverride->value, $actions, true), 'overrides are logged');

    foreach ($rows as $row) {
        assertSame('app_user', (string) $row['entity']);

        // MariaDB implements JSON as LONGTEXT with a json_valid CHECK, so a
        // bare string that MySQL accepts is rejected there. Real JSON or NULL.
        foreach (['before_json', 'after_json'] as $column) {
            if ($row[$column] === null) {
                continue;
            }
            assertTrue(
                json_decode((string) $row[$column], true) !== null,
                $column . ' is real JSON: ' . (string) $row[$column]
            );
        }
    }
});

// ---------------------------------------------------------------------------
// Flagged for Purge (spec 6.5, Phase 8 decided 4)
// ---------------------------------------------------------------------------

test('the flagged list shows the batch that flagged each member', function (): void {
    $f    = ad_fixture();
    $page = PurgePage::fromApp($GLOBALS['rerm_app'])->page([]);

    $found = null;
    foreach ($page['rows'] as $row) {
        if ($row['member_number'] === 'AD000010') {
            $found = $row;
        }
    }

    assertTrue($found !== null, 'the flagged member is listed');
    assertSame($f['batch'], (int) $found['batch_id']);
    assertSame('AD-roster.xls', $found['batch_filename']);
    assertSame('complete', $found['batch_mode']);
});

test('the master administrator is never offered for purge', function (): void {
    // Without the is_system guard, the first complete import would flag the
    // only account that can sign in and invite an Admin to purge it.
    foreach (['flagged', 'purged'] as $list) {
        $page = PurgePage::fromApp($GLOBALS['rerm_app'])->page(['list' => $list]);
        foreach ($page['rows'] as $row) {
            assertTrue(
                $row['member_number'] !== App::MASTER_ADMIN_NUMBER,
                'the system row is not on the ' . $list . ' list'
            );
        }
    }
});

test('a purge without the typed word changes nothing', function (): void {
    $f      = ad_fixture();
    $result = Purge::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action'    => 'purge',
        'member_id' => [(string) $f['ids']['flagged']],
        'confirm'   => 'confirm',
    ]);

    // Compared case-sensitively on purpose: a confirmation that accepts
    // "confirm" is a confirmation somebody types without reading.
    assertSame('not_confirmed', $result['outcome']);

    $read = ad_pdo()->prepare('SELECT purged_at FROM member WHERE id = :id');
    $read->execute([':id' => $f['ids']['flagged']]);
    assertSame(null, $read->fetchColumn(), 'nothing was purged');
});

test('a purge is a soft delete and takes nothing with it', function (): void {
    $f   = ad_fixture();
    $pdo = ad_pdo();

    // Give the flagged member history first, so "nothing cascades" is a claim
    // about real rows rather than about none.
    $pdo->prepare(
        'INSERT INTO contact_log (member_id, show_year_id, contacted_by, contact_type, notes)'
        . " VALUES (:m, :y, :u, 'call', 'Left a message.')"
    )->execute([':m' => $f['ids']['flagged'], ':y' => $f['this_year'], ':u' => $f['users']['cap']]);

    $before = [
        'members'  => (int) $pdo->query('SELECT COUNT(*) FROM member')->fetchColumn(),
        'contacts' => (int) $pdo->query('SELECT COUNT(*) FROM contact_log')->fetchColumn(),
    ];

    $result = Purge::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action'    => 'purge',
        'member_id' => [(string) $f['ids']['flagged']],
        'confirm'   => Purge::CONFIRM_WORD,
    ]);

    assertSame('purged', $result['outcome']);
    assertSame(1, (int) $result['affected']);

    $after = [
        'members'  => (int) $pdo->query('SELECT COUNT(*) FROM member')->fetchColumn(),
        'contacts' => (int) $pdo->query('SELECT COUNT(*) FROM contact_log')->fetchColumn(),
    ];

    assertSame($before['members'], $after['members'], 'no member row was deleted');
    assertSame($before['contacts'], $after['contacts'], 'no contact row was deleted');

    $read = $pdo->prepare('SELECT purged_at FROM member WHERE id = :id');
    $read->execute([':id' => $f['ids']['flagged']]);
    assertTrue($read->fetchColumn() !== null, 'purged_at is stamped — that IS the purge');
});

test('a purged member vanishes from every scoped read', function (): void {
    $page = DesignatePage::fromApp($GLOBALS['rerm_app'])->page(ad_user('adm'), ['q' => 'Flagged']);
    $numbers = array_map(static fn (array $r): string => $r['member_number'], $page['rows']);

    assertTrue(!in_array('AD000010', $numbers, true), 'ScopedQuery::visible() hides them');
});

test('Restore brings a purged member back with everything they had', function (): void {
    $f = ad_fixture();

    // Restore exists because an import does NOT clear purged_at (Phase 8
    // decided 4): without it a mistaken purge is invisible forever.
    $result = Purge::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action'    => 'restore',
        'member_id' => [(string) $f['ids']['flagged']],
    ]);

    assertSame('restored', $result['outcome']);
    assertSame(1, (int) $result['affected']);

    $read = ad_pdo()->prepare(
        'SELECT m.purged_at, (SELECT COUNT(*) FROM contact_log c WHERE c.member_id = m.id) AS contacts'
        . ' FROM member m WHERE m.id = :id'
    );
    $read->execute([':id' => $f['ids']['flagged']]);
    $row = $read->fetch();

    assertSame(null, $row['purged_at'], 'purged_at is cleared and nothing else changed');
    assertSame(1, (int) $row['contacts'], 'their history was never anywhere else');
});

test('restore does not need the typed word — it is the reversible half', function (): void {
    $f = ad_fixture();

    // The purged fixture member, restored with no confirmation at all.
    $result = Purge::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action'    => 'restore',
        'member_id' => [(string) $f['ids']['purged']],
    ]);

    assertSame('restored', $result['outcome']);
});

test('a selection past the ceiling is refused, never silently trimmed', function (): void {
    // max_input_vars is 1000 on this host with SILENT truncation, and once
    // was enough (docs/hosting.md).
    $ids = [];
    for ($i = 0; $i <= Purge::MAX_SELECTION; $i++) {
        $ids[] = (string) (100000 + $i);
    }

    $result = Purge::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action'    => 'purge',
        'member_id' => $ids,
        'confirm'   => Purge::CONFIRM_WORD,
    ]);

    assertSame('too_many', $result['outcome']);
});

// ---------------------------------------------------------------------------
// The export (spec 7.5, Phase 8 decided 3)
// ---------------------------------------------------------------------------

/**
 * The whole export, read back through our own reader, as
 * [header => value] per row keyed by Customer Number.
 *
 * @param array<int, int> $teamIds
 * @return array<string, array<string, string>>
 */
function ad_export(User $user, array $teamIds = []): array
{
    $f      = ad_fixture();
    $export = RosterExport::fromApp($GLOBALS['rerm_app']);
    $built  = $export->build($user, $f['this_year'], 'AD-2027', $teamIds);

    try {
        $reader  = Spreadsheet::open($built['path']);
        $rows    = [];
        $headers = [];

        foreach ($reader->rows() as $i => $row) {
            if ($i === 0) {
                $headers = $row;
                continue;
            }

            // The reader trims trailing empty cells, so a short row is padded
            // back out rather than losing its last columns.
            $row = array_pad($row, count($headers), '');
            $rows[$row[array_search(HeaderMap::CUSTOMER_NUMBER, $headers, true)]]
                = array_combine($headers, array_slice($row, 0, count($headers)));
        }

        return $rows;
    } finally {
        $export->discard($built['writer'], $built['path']);
    }
}

test('an Admin exports the whole committee; an Officer exports their team', function (): void {
    // ONE export, one code path. Breadth is a consequence of scope, not of a
    // different button (Phase 8 decided 3).
    $admin   = ad_export(ad_user('adm'));
    $senior  = ad_export(ad_user('senior'));
    $officer = ad_export(ad_user('cap'));

    // The Admin sees the placeholder division's members; the Senior Officer
    // does not; the Officer sees only Alpha Team.
    assertTrue(isset($admin['AD000008']), 'the Admin gets (No Division)');
    assertTrue(!isset($senior['AD000008']), 'the Senior Officer gets their division only');
    assertTrue(isset($senior['AD000005']), 'and does get their own division');

    assertTrue(isset($officer['AD000005']), 'the Officer gets their own team');
    assertTrue(!isset($officer['AD000007']), 'and not the team next door');
    assertTrue(!isset($officer['AD000008']), 'and certainly not another division');
});

test('the team filter can only narrow, never widen', function (): void {
    $f = ad_fixture();

    // A Senior Officer asking for a team OUTSIDE their division gets nothing
    // from it: the filter INTERSECTS the scope predicate.
    $rows = ad_export(ad_user('senior'), [$f['beta_team']]);
    assertSame([], $rows, 'an out-of-scope team id yields nothing rather than something');

    // And in-scope it does what it says.
    $rows = ad_export(ad_user('senior'), [$f['bare_team']]);
    assertSame(['AD000007'], array_keys($rows));
});

test('(No Division) writes back as BLANK, never as the literal text', function (): void {
    // Spec 5.1a rule 2. It is our bookkeeping, not Rodeo Houston's data, and
    // it must not travel back to them as though it were theirs.
    $rows = ad_export(ad_user('adm'));

    assertSame('', $rows['AD000008'][HeaderMap::SUBCOMMITTEE_3], 'the HLSR column is blank');
    assertSame('AD (No Division)', $rows['AD000008']['Division'], 'our own column is honest');

    // A real division still writes its name.
    assertSame('AD Alpha Division', $rows['AD000005'][HeaderMap::SUBCOMMITTEE_3]);
});

test('the master administrator is never exported', function (): void {
    $rows = ad_export(ad_user('adm'));

    assertTrue(
        !isset($rows[App::MASTER_ADMIN_NUMBER]),
        'is_system rows are an account, not a committee member'
    );
});

test('a member number keeps its leading zeros and never becomes a float', function (): void {
    $rows = ad_export(ad_user('adm'));

    foreach ($rows as $number => $row) {
        assertSame(
            $number,
            $row[HeaderMap::CUSTOMER_NUMBER],
            'the key round-trips as the string it is'
        );
        assertTrue(
            !str_contains($number, '.'),
            "Customer Number {$number} is an identifier, never arithmetic"
        );
    }
});

test('the effective status column is MetricStatus::derive() and nothing else', function (): void {
    $rows = ad_export(ad_user('adm'));

    // m1: imported Y everywhere -> Complete.
    assertSame(
        MetricStatus::Complete->label(),
        $rows['AD000005']['HLSR Dues Status']
    );

    // m2: imported N, progress in_progress, and contacted this year.
    assertSame(
        MetricStatus::InProgress->label(),
        $rows['AD000006']['Committee Dues Status']
    );
    assertSame('In progress', $rows['AD000006']['Committee Dues Progress']);
    assertSame('Cal Captain', $rows['AD000006']['Committee Dues Progress By']);

    // m2's OTHER metrics: imported N, no progress, but contacted -> Contacted.
    assertSame(
        MetricStatus::Contacted->label(),
        $rows['AD000006']['Indemnity Status']
    );

    // n1: imported N, never contacted -> Open/No Contact.
    assertSame(
        MetricStatus::Outstanding->label(),
        $rows['AD000008']['Indemnity Status']
    );
});

test('a blank harassment training exports as blank, never as N', function (): void {
    // Tri-state: 1,716 of 1,954 rows are blank, which is not the same as N and
    // is never a failure (spec 5.4).
    $rows = ad_export(ad_user('adm'));

    assertSame('', $rows['AD000005'][HeaderMap::HARASSMENT_TRAINING], 'unknown exports blank');
    assertSame(
        MetricStatus::NotReported->label(),
        $rows['AD000005']['Harassment Training Status']
    );
});

test('the generated columns carry the assignment and the last contact', function (): void {
    $rows = ad_export(ad_user('adm'));

    assertSame('Cal Captain', $rows['AD000005']['Assigned Officers']);
    assertSame('1', $rows['AD000006']['Contacts This Year']);
    assertSame('Call', $rows['AD000006']['Last Contact Type']);
    assertSame('Cal Captain', $rows['AD000006']['Last Contact Officer']);

    assertSame('0', $rows['AD000008']['Contacts This Year']);
    assertSame('', $rows['AD000008']['Last Contact Date']);
});

test('the export screen states the row count before anything is built', function (): void {
    $f    = ad_fixture();
    $page = ExportPage::fromApp($GLOBALS['rerm_app'])->page(ad_user('cap'), ['year' => (string) $f['this_year']]);

    // Alpha Team, visible: adm, exec, senior, cap, m1, m2 — the flagged and
    // purged ones are out (both restored above, so they are back in: 8).
    $rows = ad_export(ad_user('cap'));
    assertSame(count($rows), (int) $page['rows'], 'the count is the file');

    assertSame('your team', $page['scope_word']);
    assertSame(false, $page['can_filter_teams'], 'an Officer\'s team IS their scope');
    assertSame(RosterExport::headers(), $page['columns'], 'the promise is the header row itself');
});

test('an export is logged with the actor, the scope and the row count', function (): void {
    $f      = ad_fixture();
    $pdo    = ad_pdo();
    $export = RosterExport::fromApp($GLOBALS['rerm_app']);

    $export->audit(ad_user('senior'), $f['this_year'], 'AD-2027', [$f['alpha_team']], 42);

    $read = $pdo->prepare(
        'SELECT after_json FROM audit_log WHERE action = :a AND actor_user_id = :u'
        . ' ORDER BY id DESC LIMIT 1'
    );
    $read->execute([':a' => Action::ExportRoster->value, ':u' => $f['users']['senior']]);
    $after = json_decode((string) $read->fetchColumn(), true);

    assertTrue(is_array($after), 'the payload is real JSON');
    assertSame(42, $after['rows']);
    assertSame('AD-2027', $after['show_year']);
    assertSame('senior_officer', $after['scope_level'], 'how much they could take');
    assertSame([$f['alpha_team']], $after['team_filter']);
});

// ---------------------------------------------------------------------------
// Show Year and the rollover (spec 5.1, Phase 8 decided 1 and 5)
// ---------------------------------------------------------------------------

test('creating a show year makes it open and NOT active', function (): void {
    $result = ShowYears::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action' => 'create', 'label' => 'AD-2029', 'starts_on' => '', 'ends_on' => '',
    ]);

    assertSame('created', $result['outcome']);

    $read = ad_pdo()->prepare('SELECT is_open, is_active FROM show_year WHERE label = :l');
    $read->execute([':l' => 'AD-2029']);
    $row = $read->fetch();

    // Activating is a second, deliberate act: it is what every officer's
    // dashboard switches to, and it must not be a side effect of typing a name.
    assertSame(1, (int) $row['is_open']);
    assertSame(0, (int) $row['is_active']);
});

test('a duplicate label and a backwards date range are both refused', function (): void {
    $years = ShowYears::fromApp($GLOBALS['rerm_app']);

    assertSame('duplicate_label', $years->apply(ad_user('adm'), [
        'action' => 'create', 'label' => 'AD-2029',
    ])['outcome']);

    assertSame('bad_dates', $years->apply(ad_user('adm'), [
        'action' => 'create', 'label' => 'AD-2030',
        'starts_on' => '2029-03-01', 'ends_on' => '2029-01-01',
    ])['outcome']);

    assertSame('bad_label', $years->apply(ad_user('adm'), [
        'action' => 'create', 'label' => '   ',
    ])['outcome']);
});

test('closing warns with the count, and closes anyway', function (): void {
    // Phase 8 decided 1. A metric stuck mid-chase is the normal end-of-year
    // state — people say they will pay and then do not — so refusing would
    // mean faking edits in order to be allowed to close.
    $f     = ad_fixture();
    $years = ShowYears::fromApp($GLOBALS['rerm_app']);

    $mid = 0;
    foreach ($years->years() as $year) {
        if ($year['label'] === 'AD-2027') {
            $mid = $year['in_progress'];
        }
    }
    assertTrue($mid > 0, 'the fixture has a metric mid-chase to freeze');

    $result = $years->apply(ad_user('adm'), [
        'action' => 'close', 'year_id' => (string) $f['this_year'], 'confirm' => ShowYears::CONFIRM_WORD,
    ]);

    assertSame('closed', $result['outcome'], 'it closes, warning rather than refusing');
    assertSame($mid, (int) $result['in_progress'], 'and says exactly how many it froze');

    // The count is in the record, so "why is this still In Progress in the
    // 2027 file" has an answer that is not a guess.
    $read = ad_pdo()->prepare(
        'SELECT after_json FROM audit_log WHERE action = :a AND entity_id = :e ORDER BY id DESC LIMIT 1'
    );
    $read->execute([':a' => Action::CloseShowYear->value, ':e' => (string) $f['this_year']]);
    $after = json_decode((string) $read->fetchColumn(), true);
    assertSame($mid, $after['progress_rows_frozen']);
});

test('closing freezes and never clears', function (): void {
    // Spec 5.5 is absolute. Producing a member's history back to 2026 in 2029
    // is the v2 feature this constraint exists to keep possible.
    $f   = ad_fixture();
    $pdo = ad_pdo();

    foreach ([
        'contact_log'   => 'SELECT COUNT(*) FROM contact_log WHERE show_year_id = :y',
        'assignment'    => 'SELECT COUNT(*) FROM assignment WHERE show_year_id = :y',
        'member_metric' => 'SELECT COUNT(*) FROM member_metric WHERE show_year_id = :y',
    ] as $table => $sql) {
        $read = $pdo->prepare($sql);
        $read->execute([':y' => $f['this_year']]);
        assertTrue((int) $read->fetchColumn() > 0, $table . ' survived the close');
    }

    // And the progress that was mid-chase is still exactly mid-chase.
    $read = $pdo->prepare(
        "SELECT progress FROM member_metric WHERE member_id = :m AND show_year_id = :y AND metric = 'committee_dues'"
    );
    $read->execute([':m' => $f['ids']['m2'], ':y' => $f['this_year']]);
    assertSame('in_progress', (string) $read->fetchColumn(), 'frozen as it was, not reset');
});

test('a closed year re-opens, and carrying INTO a closed year is refused', function (): void {
    $f     = ad_fixture();
    $years = ShowYears::fromApp($GLOBALS['rerm_app']);

    $refused = $years->apply(ad_user('adm'), [
        'action' => 'carry', 'from_year' => (string) $f['next_year'],
        'to_year' => (string) $f['this_year'], 'confirm' => ShowYears::CONFIRM_WORD,
    ]);
    assertSame('target_closed', $refused['outcome'], 'a frozen record is not written to');

    assertSame('opened', $years->apply(ad_user('adm'), [
        'action' => 'open', 'year_id' => (string) $f['this_year'],
    ])['outcome']);

    assertSame('unchanged', $years->apply(ad_user('adm'), [
        'action' => 'open', 'year_id' => (string) $f['this_year'],
    ])['outcome']);
});

test('the rollover previews both numbers before it runs', function (): void {
    // Phase 8 decided 5: "It is never silent."
    $f       = ad_fixture();
    $preview = ShowYears::fromApp($GLOBALS['rerm_app'])
        ->rolloverPreview($f['this_year'], $f['next_year']);

    // TRANSCRIBED from the fixture, not computed by the code under test:
    //   m1 -> cap   eligible (same team, Officer level, visible)   CARRIES
    //   m2 -> m3    a Committee Member on another team             DROPPED
    assertSame(1, $preview['carry']);
    assertSame(1, $preview['drop']);
});

test('the rollover carries only eligible assignments, and copies them', function (): void {
    $f      = ad_fixture();
    $result = ShowYears::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action' => 'carry', 'from_year' => (string) $f['this_year'],
        'to_year' => (string) $f['next_year'], 'confirm' => ShowYears::CONFIRM_WORD,
    ]);

    assertSame('carried', $result['outcome']);
    assertSame(1, (int) $result['carried']);
    assertSame(1, (int) $result['dropped']);

    $pdo = ad_pdo();

    // COPIED, not shared: last year's row is untouched and this year's is new.
    $read = $pdo->prepare(
        'SELECT member_id, officer_member_id FROM assignment'
        . ' WHERE show_year_id = :y AND removed_at IS NULL ORDER BY member_id'
    );
    $read->execute([':y' => $f['next_year']]);
    $carried = $read->fetchAll();

    assertSame(1, count($carried), 'exactly the eligible one');
    assertSame($f['ids']['m1'], (int) $carried[0]['member_id']);
    assertSame($f['ids']['cap'], (int) $carried[0]['officer_member_id']);

    $read->execute([':y' => $f['this_year']]);
    assertSame(2, count($read->fetchAll()), 'last year still has both — nothing moved');
});

test('a member whose only officer no longer qualifies arrives UNASSIGNED', function (): void {
    // The consequence is deliberate (decided 5): bucket 1 on the Assign
    // screen, where somebody is already working, rather than bucket 2 as
    // invisible cleanup nobody asked for.
    $f    = ad_fixture();
    $page = AssignPage::fromApp($GLOBALS['rerm_app'])->page(ad_user('adm'), $f['next_year'], [
        'team' => (string) $f['alpha_team'], 'bucket' => 'unassigned',
    ]);

    $numbers = array_map(static fn (array $r): string => (string) $r['member_number'], $page['rows']);

    assertTrue(in_array('AD000006', $numbers, true), 'm2 is unassigned in the new year');
    assertTrue(!in_array('AD000005', $numbers, true), 'm1 kept their eligible officer');

    // And bucket 2 is empty rather than pre-loaded.
    $broken = AssignPage::fromApp($GLOBALS['rerm_app'])->page(ad_user('adm'), $f['next_year'], [
        'team' => (string) $f['alpha_team'], 'bucket' => 'ineligible',
    ]);
    assertSame(0, count($broken['rows']), 'nothing was carried already broken');
});

test('the rollover logs both numbers, and running it twice carries nothing more', function (): void {
    $f    = ad_fixture();
    $read = ad_pdo()->prepare(
        'SELECT after_json FROM audit_log WHERE action = :a AND entity_id = :e ORDER BY id DESC LIMIT 1'
    );
    $read->execute([':a' => Action::CarryAssignments->value, ':e' => (string) $f['next_year']]);
    $after = json_decode((string) $read->fetchColumn(), true);

    assertSame(1, $after['carried']);
    assertSame(1, $after['dropped_ineligible']);
    assertSame('AD-2028', $after['to_show_year']);

    // Idempotent: the second run finds the carried one already there.
    $again = ShowYears::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action' => 'carry', 'from_year' => (string) $f['this_year'],
        'to_year' => (string) $f['next_year'], 'confirm' => ShowYears::CONFIRM_WORD,
    ]);

    assertSame(0, (int) $again['carried'], 'nothing is copied twice');
});

test('the rollover carries no metric and no contact', function (): void {
    // Spec 5.1: last year's dues and last year's phone calls say nothing
    // about this year.
    $f   = ad_fixture();
    $pdo = ad_pdo();

    foreach (['member_metric', 'contact_log'] as $table) {
        $read = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE show_year_id = :y");
        $read->execute([':y' => $f['next_year']]);
        assertSame(0, (int) $read->fetchColumn(), $table . ' does not carry');
    }
});

test('a rollover without the typed word changes nothing', function (): void {
    $f      = ad_fixture();
    $result = ShowYears::fromApp($GLOBALS['rerm_app'])->apply(ad_user('adm'), [
        'action' => 'carry', 'from_year' => (string) $f['this_year'], 'to_year' => (string) $f['next_year'],
    ]);

    assertSame('not_confirmed', $result['outcome']);
});

test('exactly one show year is active, whatever is activated', function (): void {
    $f     = ad_fixture();
    $years = ShowYears::fromApp($GLOBALS['rerm_app']);

    assertSame('activated', $years->apply(ad_user('adm'), [
        'action' => 'activate', 'year_id' => (string) $f['next_year'],
    ])['outcome']);

    // The schema enforces it with a unique key over a generated column; the
    // transaction is what stops a failure between the two statements leaving
    // NO active year, which every screen in the application reads.
    assertSame(
        1,
        (int) ad_pdo()->query('SELECT COUNT(*) FROM show_year WHERE is_active = 1')->fetchColumn()
    );

    $read = ad_pdo()->prepare('SELECT is_active FROM show_year WHERE id = :id');
    $read->execute([':id' => $f['next_year']]);
    assertSame(1, (int) $read->fetchColumn());

    // Put back immediately. Every other suite in this directory reads the
    // seeded active row, and they share one database in one process.
    $seeded = ad_seeded_active_year(ad_pdo());
    if ($seeded !== null) {
        ad_pdo()->exec('UPDATE show_year SET is_active = 0 WHERE is_active = 1');
        ad_pdo()->prepare('UPDATE show_year SET is_active = 1 WHERE id = :id')->execute([':id' => $seeded]);
    }
});

// ---------------------------------------------------------------------------
// Manage Teams (spec 7.3) and the Audit Log (spec 7.5)
// ---------------------------------------------------------------------------

test('a team with no area groups under (No area), the honest-placeholder pattern', function (): void {
    $page = TeamsPage::fromApp($GLOBALS['rerm_app'])->page([]);

    $bare = null;
    foreach ($page['teams'] as $team) {
        if ($team['name'] === 'Alpha Bare AD') {
            $bare = $team;
        }
    }

    assertTrue($bare !== null, 'the bare team is listed');
    assertSame('', $bare['area'], 'no area, and no invented one');
    assertTrue($page['no_area_count'] > 0, 'the screen says how many are like that');
});

test('editing an area changes grouping and nothing else', function (): void {
    $f     = ad_fixture();
    $teams = TeamsPage::fromApp($GLOBALS['rerm_app']);

    $result = $teams->save(ad_user('adm'), [
        'team_id' => (string) $f['bare_team'], 'area' => '  Reed   Road  ',
    ]);

    // Trimmed and whitespace-collapsed, so "Reed  Road" and "Reed Road" do not
    // become two areas on the dashboard.
    assertSame('saved', $result['outcome']);
    assertSame('Reed Road', $result['area']);

    $read = ad_pdo()->prepare('SELECT area FROM team WHERE id = :id');
    $read->execute([':id' => $f['bare_team']]);
    assertSame('Reed Road', (string) $read->fetchColumn());

    // Nobody's access moved. The Officer still sees exactly their own team,
    // and the two teams now sharing an area are still two teams.
    $rows = ad_export(ad_user('cap'));
    assertTrue(!isset($rows['AD000007']), 'a shared area is not a shared scope');

    // Cleared again, back to (No area).
    assertSame('cleared', $teams->save(ad_user('adm'), [
        'team_id' => (string) $f['bare_team'], 'area' => '',
    ])['outcome']);

    $read->execute([':id' => $f['bare_team']]);
    assertSame(null, $read->fetchColumn(), 'blank clears it to NULL, not to an empty string');
});

test('an area longer than the column is refused rather than truncated', function (): void {
    $f      = ad_fixture();
    $result = TeamsPage::fromApp($GLOBALS['rerm_app'])->save(ad_user('adm'), [
        'team_id' => (string) $f['bare_team'], 'area' => str_repeat('x', 65),
    ]);

    assertSame('too_long', $result['outcome']);
});

test('the audit log filters by actor, action and date', function (): void {
    $f    = ad_fixture();
    $page = new Rerm\Admin\AuditPage(ad_pdo(), 50, 100);

    // By action.
    $granted = $page->page(['action' => Action::GrantLevel->value]);
    assertTrue($granted['total'] > 0, 'grants are findable');
    foreach ($granted['rows'] as $row) {
        assertSame(Action::GrantLevel->value, $row['action']);
        assertSame('Level granted', $row['action_word']);
    }

    // By actor.
    $byAdmin = $page->page(['actor' => (string) $f['users']['adm']]);
    assertTrue($byAdmin['total'] > 0, 'the Admin has acted');

    // By an impossible date window.
    $none = $page->page(['from' => '1990-01-01', 'to' => '1990-01-02']);
    assertSame(0, $none['total']);

    // A garbage action is dropped rather than applied, so the screen shows
    // everything rather than nothing.
    $unfiltered = $page->page(['action' => 'no_such_action_exists']);
    assertSame('', $unfiltered['action']);
    assertTrue($unfiltered['total'] > 0);
});

test('the action filter offers only actions the table actually holds', function (): void {
    $page    = (new Rerm\Admin\AuditPage(ad_pdo(), 50, 100))->page([]);
    $offered = array_keys($page['actions']);

    $inUse = ad_pdo()->query('SELECT DISTINCT action FROM audit_log ORDER BY action')
        ->fetchAll(PDO::FETCH_COLUMN);

    sort($offered);
    sort($inUse);

    // Never an option that matches nothing, and never a row nobody can find.
    assertSame(array_map('strval', $inUse), $offered);
});

test('an audit payload a future migration wrote badly still renders', function (): void {
    // An audit log that throws on its own history is an audit log nobody can
    // open. MariaDB's json_valid CHECK would refuse a bare string here, so
    // this is asserted through the decoder rather than by writing one.
    $page = (new Rerm\Admin\AuditPage(ad_pdo(), 50, 100))->page([]);

    foreach ($page['rows'] as $row) {
        assertTrue(is_string($row['before']), 'before is always a string');
        assertTrue(is_string($row['after']), 'after is always a string');
    }

    assertSame('a_string_nobody_declared', Action::describe('a_string_nobody_declared'));
});

test('AuditLog writes real JSON or NULL, never a fragment', function (): void {
    $log = new AuditLog(ad_pdo());

    assertSame(null, AuditLog::json(null), 'no payload is NULL, not "null"');
    assertSame(null, AuditLog::json([]), 'an empty payload is NULL too');
    assertSame('{"a":1}', AuditLog::json(['a' => 1]));

    // A payload that cannot be encoded becomes NULL rather than a fragment: a
    // row with an empty payload still records who did what and when, and a row
    // the CHECK rejects records nothing at all.
    assertSame(null, AuditLog::json(['bad' => NAN]));

    $f = ad_fixture();
    $log->record(ad_user('adm'), Action::SetTeamArea, 'team', 'AD-json-check', null, ['ok' => true]);

    $read = ad_pdo()->prepare('SELECT before_json, after_json FROM audit_log WHERE entity_id = :e');
    $read->execute([':e' => 'AD-json-check']);
    $row = $read->fetch();

    assertSame(null, $row['before_json']);
    assertSame(['ok' => true], json_decode((string) $row['after_json'], true));
});

// ---------------------------------------------------------------------------
// The views, rendered through the real templates
// ---------------------------------------------------------------------------

/**
 * Does the rendered page say this, whatever the view's line wrapping?
 *
 * Prose in a template wraps where it wraps, and an assertion that breaks when
 * somebody re-flows a paragraph is an assertion that gets deleted rather than
 * fixed.
 */
function ad_says(string $html, string $phrase): bool
{
    $flat = (string) preg_replace('/\s+/u', ' ', $html);

    return str_contains($flat, (string) preg_replace('/\s+/u', ' ', $phrase));
}

/**
 * One Phase 8 view inside the real page shell, as index.php renders it.
 *
 * Every screen in this phase touches PII — names, member numbers, an area
 * somebody typed, an audit payload — so the thing worth proving is not that
 * the markup is pretty but that it renders at all, that every value went
 * through e(), and that the byte weight is nowhere near spec 10's budget.
 *
 * @param array<string, mixed> $data
 */
function ad_render(string $view, string $title, array $data): string
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    $_SESSION ??= [];

    // render() extracts with EXTR_SKIP, so its own variables win a collision.
    // Named exactly as index.php names them, for that reason.
    $wide = true;
    extract($data, EXTR_SKIP);

    ob_start();
    require $app->path('app/views/' . $view . '.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

test('Designate Users renders, with one form and one link per row', function (): void {
    $f    = ad_fixture();
    $page = DesignatePage::fromApp($GLOBALS['rerm_app'])->page(ad_user('adm'), [
        'q' => 'Member', 'member' => (string) $f['ids']['m1'],
    ]);

    $html = ad_render('designate', 'Designate Users', [
        'user' => ad_user('adm'), 'notices' => [], 'designate' => $page,
    ]);

    assertTrue(str_contains($html, 'Designate Users'), 'the screen rendered');
    assertTrue(str_contains($html, 'Mina Member'), 'the searched member is on it');

    // The Phase 5 budget lesson, applied a third time: ONE member's controls,
    // however many rows are listed. That row carries three forms — grant,
    // revoke and the Admin-only scope override — so the thing to count is the
    // opened row, not the hidden field each form repeats.
    assertSame(
        1,
        preg_match_all('/<tr class="detail">/', $html),
        'exactly one row is opened'
    );
    assertTrue(
        count($page['rows']) > 1,
        'and there is more than one row, so "one" means something'
    );
    assertTrue(str_contains($html, 'name="csrf"'), 'the form is protected');

    // Well inside spec 10's 100KB first paint.
    assertTrue(
        strlen($html) < 100000,
        'the page is ' . number_format(strlen($html)) . ' bytes, against a 100KB budget'
    );
});

test('Flagged for Purge renders both lists, with the typed confirmation', function (): void {
    $f     = ad_fixture();
    $pdo   = ad_pdo();
    $purge = Purge::fromApp($GLOBALS['rerm_app']);

    // Both lists have to have something in them for this to prove anything,
    // and by now the fixture's own flagged member has been purged and
    // restored. Re-flag one, so the flagged list is not empty.
    $pdo->prepare('UPDATE member SET absent_since_import_id = :b WHERE id = :id')
        ->execute([':b' => $f['batch'], ':id' => $f['ids']['flagged']]);

    try {
        $flagged = PurgePage::fromApp($GLOBALS['rerm_app'])->page([]);
        assertTrue($flagged['total'] > 0, 'the flagged list has somebody on it');

        $html = ad_render('purge', 'Flagged for Purge', [
            'user' => ad_user('adm'), 'notices' => [], 'purge' => $flagged,
        ]);

        assertTrue(str_contains($html, 'Flagged for Purge'), 'the screen rendered');
        assertTrue(str_contains($html, 'name="confirm"'), 'a purge asks for the word');
        assertTrue(str_contains($html, Purge::CONFIRM_WORD), 'and says which word');
        assertTrue(str_contains($html, 'name="member_id[]"'), 'per-member checkboxes, never a bulk sweep');
        assertTrue(ad_says($html, 'Purge selected'), 'and a button that does it');

        // Now purge them, and render the other half.
        $purge->apply(ad_user('adm'), [
            'action' => 'purge', 'member_id' => [(string) $f['ids']['flagged']],
            'confirm' => Purge::CONFIRM_WORD,
        ]);

        $purged = PurgePage::fromApp($GLOBALS['rerm_app'])->page(['list' => 'purged']);
        assertTrue($purged['total'] > 0, 'the purged list has somebody on it');

        $html = ad_render('purge', 'Flagged for Purge', [
            'user' => ad_user('adm'), 'notices' => [], 'purge' => $purged,
        ]);

        // Restoring is the reversible half, so it does not ask for the word.
        assertTrue(!str_contains($html, 'name="confirm"'), 'restoring does not ask');
        assertTrue(ad_says($html, 'Restore selected'), 'it offers Restore instead');
    } finally {
        $purge->apply(ad_user('adm'), [
            'action' => 'restore', 'member_id' => [(string) $f['ids']['flagged']],
        ]);
        $pdo->prepare('UPDATE member SET absent_since_import_id = NULL WHERE id = :id')
            ->execute([':id' => $f['ids']['flagged']]);
    }
});

test('Export renders the count and the columns before anything is built', function (): void {
    $f    = ad_fixture();
    $page = ExportPage::fromApp($GLOBALS['rerm_app'])->page(ad_user('adm'), ['year' => (string) $f['this_year']]);
    $html = ad_render('export', 'Export Roster', [
        'user' => ad_user('adm'), 'notices' => [], 'export' => $page,
    ]);

    assertTrue(ad_says($html, 'This file is personal data'), 'it says so first');
    assertTrue(ad_says($html, 'the whole committee'), 'and how wide the caller\'s scope is');
    assertTrue(str_contains($html, 'Customer Number'), 'the promise is the header row itself');
    assertTrue(str_contains($html, 'method="post"'), 'the download is a POST, not a link');
    assertTrue(str_contains($html, 'name="csrf"'));
});

test('Show Year renders the in-progress warning before the close confirm', function (): void {
    $years = ShowYears::fromApp($GLOBALS['rerm_app'])->years();
    $html  = ad_render('show-year', 'Show Year', [
        'user' => ad_user('adm'), 'notices' => [],
        'showYear' => ['years' => $years, 'from_year' => 0, 'to_year' => 0, 'preview' => null],
    ]);

    assertTrue(str_contains($html, 'Show Year'));
    assertTrue(ad_says($html, 'mid-chase'), 'the warning, before the confirm (decided 1)');
    assertTrue(ad_says($html, 'Nothing is deleted'), 'and what closing does not do');

    // The rollover preview, when one was asked for.
    $f    = ad_fixture();
    $html = ad_render('show-year', 'Show Year', [
        'user' => ad_user('adm'), 'notices' => [],
        'showYear' => [
            'years' => $years, 'from_year' => $f['this_year'], 'to_year' => $f['next_year'],
            'preview' => ['carry' => 7, 'drop' => 3],
        ],
    ]);
    assertTrue(ad_says($html, 'would carry'), 'both numbers, before it runs');
    assertTrue(ad_says($html, 'no longer qualifies'), 'and why the rest are dropped');
});

test('the Audit Log renders, escaped, with payloads behind a details', function (): void {
    $page = (new Rerm\Admin\AuditPage(ad_pdo(), 50, 100))->page([]);
    $html = ad_render('audit', 'Audit Log', ['user' => ad_user('adm'), 'audit' => $page]);

    assertTrue(str_contains($html, 'Audit Log'));
    assertTrue(ad_says($html, 'Times are UTC'), 'the column is UTC and the screen says so');

    // Read-only: nothing on the rendered page can write.
    assertSame(0, preg_match('/method="post"/i', $html), 'no POST on a read-only screen');

    // The payloads are JSON, and JSON is full of quotes and braces. If one
    // reached the page unescaped it would break out of the <pre>.
    assertSame(0, preg_match('/<pre class="mono">[^<]*"[^&]/', $html), 'payloads are escaped');
});

test('Manage Teams renders one editor at a time, with the areas as a datalist', function (): void {
    $f     = ad_fixture();
    $teams = TeamsPage::fromApp($GLOBALS['rerm_app'])->page(['team' => (string) $f['bare_team']]);
    $html  = ad_render('teams', 'Manage Teams', [
        'user' => ad_user('adm'), 'notices' => [], 'teams' => $teams,
    ]);

    assertTrue(str_contains($html, 'Manage Teams'));
    assertTrue(ad_says($html, 'It is grouping and nothing else'), 'the screen says what area is');
    assertTrue(str_contains($html, '<datalist id="areas">'), 'existing areas are offered');
    assertTrue(str_contains($html, '(No area)'), 'the honest placeholder');

    // ONE editor open, however many teams are listed.
    assertSame(1, preg_match_all('/name="area"/', $html), 'one team\'s editor, not ninety-six');
});

test('a member name with markup in it is escaped everywhere it is rendered', function (): void {
    // e() on every rendered value, with no exceptions (CLAUDE.md). Asserted
    // with a name that would be visible in the source if one were missed.
    $f   = ad_fixture();
    $pdo = ad_pdo();

    $pdo->prepare('UPDATE member SET first_name = :n WHERE id = :id')
        ->execute([':n' => '<script>x</script>', ':id' => $f['ids']['m3']]);

    try {
        // By member NUMBER, not by name — the name is the thing that just
        // changed, and searching for the old one would find nobody.
        $page = DesignatePage::fromApp($GLOBALS['rerm_app'])->page(ad_user('adm'), ['q' => 'AD000007']);
        assertSame(1, count($page['rows']), 'the member with the hostile name is on the page');
        $html = ad_render('designate', 'Designate Users', [
            'user' => ad_user('adm'), 'notices' => [], 'designate' => $page,
        ]);

        assertTrue(str_contains($html, '&lt;script&gt;'), 'the name is escaped');
        assertSame(0, substr_count($html, '<script>x</script>'), 'and never raw');
    } finally {
        $pdo->prepare("UPDATE member SET first_name = 'Mabel' WHERE id = :id")
            ->execute([':id' => $f['ids']['m3']]);
    }
});

test('the fixture cleans up after itself, and leaves the seeded year active', function (): void {
    $pdo = ad_pdo();
    ad_fixture();
    ad_teardown($pdo);

    // The rest of this directory depends on it.
    $active = $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();
    assertSame(
        ad_seeded_active_year($pdo),
        $active === false ? null : (int) $active,
        'the seeded show year is active again'
    );

    foreach ([
        "SELECT COUNT(*) FROM member WHERE member_number LIKE 'AD%'",
        "SELECT COUNT(*) FROM show_year WHERE label LIKE 'AD-%'",
        "SELECT COUNT(*) FROM team WHERE name LIKE '% AD'",
        "SELECT COUNT(*) FROM division WHERE name LIKE 'AD %'",
    ] as $sql) {
        assertSame(0, (int) $pdo->query($sql)->fetchColumn(), $sql);
    }

    // And nothing was left in var/exports.
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];
    $dir = $app->path('var/exports');
    if (is_dir($dir)) {
        assertSame(
            [],
            array_values(array_diff((array) scandir($dir), ['.', '..'])),
            'no export file survived — every one is ~1,950 home addresses'
        );
    }
});
