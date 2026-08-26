<?php

declare(strict_types=1);

/**
 * The schema (docs/spec-v1.md 5.2), and the conventions that make it work on
 * the server it is going to run on.
 *
 * Every assertion here exists because getting it wrong is silent. A table that
 * defaults to the wrong collation sorts and compares names differently from
 * every other table and nobody notices until two members merge. A foreign key
 * that says CASCADE instead of RESTRICT looks identical in a diff and destroys
 * years of contact history the first time somebody deletes a member. A STORED
 * generated column passes CI on MariaDB and cannot be created at all on the
 * MySQL server this deploys to.
 *
 * The tests that need a database skip without one — and CI runs the suite with
 * --strict, where a skip is a failure, so they cannot quietly stop running.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\Migrator;

/** The connection under test, or a skip. */
function schema_pdo(): PDO
{
    static $pdo = null;
    static $failure = null;

    if ($failure !== null) {
        skip($failure);
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    /** @var Rerm\App $app */
    $app = $GLOBALS['rerm_app'];

    try {
        $pdo = $app->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    $migrated = (int) $pdo
        ->query("SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member'")
        ->fetchColumn();

    if ($migrated === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/** @return array<int, array<string, mixed>> */
function schema_rows(string $sql): array
{
    return schema_pdo()->query($sql)->fetchAll();
}

// ---------------------------------------------------------------------------
// Conventions
// ---------------------------------------------------------------------------

test('every table is InnoDB', function (): void {
    $wrong = [];
    foreach (schema_rows(
        'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    ) as $row) {
        if ($row['ENGINE'] !== 'InnoDB') {
            $wrong[] = $row['TABLE_NAME'] . ' is ' . var_export($row['ENGINE'], true);
        }
    }

    assertSame([], $wrong, 'tables not on InnoDB (foreign keys and transactions need it)');
});

test('every table names utf8mb4_unicode_ci', function (): void {
    $tables = schema_rows(
        'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    );

    // A schema that created nothing would pass every loop below vacuously.
    assertTrue(count($tables) >= 14, 'expected the full schema, found ' . count($tables) . ' tables');

    $wrong = [];
    foreach ($tables as $row) {
        if ($row['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') {
            $wrong[] = $row['TABLE_NAME'] . ' is ' . var_export($row['TABLE_COLLATION'], true);
        }
    }

    // MySQL 8.0 defaults to utf8mb4_0900_ai_ci and MariaDB does not. A table
    // that inherits the server default sorts and compares differently from
    // every table beside it, and a join between the two raises
    // "Illegal mix of collations" at the moment a real roster is loaded.
    assertSame([], $wrong, 'tables not on utf8mb4_unicode_ci');
});

test('the connection pins time_zone to +00:00', function (): void {
    // Every DATETIME in this schema is UTC, and so is every CURRENT_TIMESTAMP
    // default, because of this. An offset rather than the name 'UTC' because
    // named zones need the mysql.time_zone_* tables loaded, which is not ours
    // to arrange on shared hosting. Display converts to America/Chicago
    // through a real timezone, never a fixed offset.
    assertSame('+00:00', (string) schema_pdo()->query('SELECT @@session.time_zone')->fetchColumn());
});

test('the connection stores what it was given, or refuses', function (): void {
    $mode = (string) schema_pdo()->query('SELECT @@session.sql_mode')->fetchColumn();

    // Not decoration: without strict mode a member number too long for its
    // column is silently truncated, and a truncated member number is a
    // different member. MySQL 8.0 and MariaDB 10.11 ship different defaults,
    // so it is pinned rather than inherited.
    assertTrue(
        str_contains($mode, 'STRICT_ALL_TABLES') || str_contains($mode, 'STRICT_TRANS_TABLES'),
        "sql_mode is not strict: {$mode}"
    );
});

test('every uniqueness key over a generated column is VIRTUAL', function (): void {
    // The trap that cost the sibling application a production deploy. Under
    // MySQL, a column that a STORED generated column reads cannot carry
    // ON DELETE CASCADE — error 1215, and the table simply will not create.
    // MariaDB accepts the identical shape, so a MariaDB-only pipeline passes
    // it straight through to a server that cannot build it.
    $rows = schema_rows(
        'SELECT s.TABLE_NAME, s.INDEX_NAME, s.COLUMN_NAME, c.EXTRA '
        . 'FROM information_schema.STATISTICS s '
        . 'INNER JOIN information_schema.COLUMNS c '
        . '  ON c.TABLE_SCHEMA = s.TABLE_SCHEMA '
        . ' AND c.TABLE_NAME = s.TABLE_NAME '
        . ' AND c.COLUMN_NAME = s.COLUMN_NAME '
        . 'WHERE s.TABLE_SCHEMA = DATABASE() '
        . '  AND s.NON_UNIQUE = 0 '
        . "  AND c.GENERATION_EXPRESSION <> '' "
        . 'ORDER BY s.TABLE_NAME, s.INDEX_NAME'
    );

    // show_year.is_active_key and assignment.is_current are both in unique
    // keys. If this ever finds none, the test has stopped testing anything.
    assertTrue($rows !== [], 'no uniqueness key over a generated column — has the schema changed?');

    $stored = [];
    foreach ($rows as $row) {
        if (!str_contains((string) $row['EXTRA'], 'VIRTUAL')) {
            $stored[] = sprintf(
                '%s.%s in %s is %s',
                $row['TABLE_NAME'],
                $row['COLUMN_NAME'],
                $row['INDEX_NAME'],
                var_export($row['EXTRA'], true)
            );
        }
    }

    assertSame([], $stored, 'generated columns in uniqueness keys must be VIRTUAL, never STORED');
});

test('every foreign key referencing member is RESTRICT', function (): void {
    // Contact history has to outlive the roster (spec 5.5). A purge is a soft
    // delete — member.purged_at — and nothing cascades from it, which is only
    // enforceable because the member row cannot be deleted at all while
    // history points at it. ON DELETE CASCADE is what a future migration will
    // reach for without thinking, so this asserts against it by name.
    $rows = schema_rows(
        'SELECT CONSTRAINT_NAME, TABLE_NAME, DELETE_RULE, UPDATE_RULE '
        . 'FROM information_schema.REFERENTIAL_CONSTRAINTS '
        . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'member' "
        . 'ORDER BY TABLE_NAME, CONSTRAINT_NAME'
    );

    assertTrue(count($rows) >= 5, 'expected every table that points at member; found ' . count($rows));

    $wrong = [];
    foreach ($rows as $row) {
        if ($row['DELETE_RULE'] !== 'RESTRICT' || $row['UPDATE_RULE'] !== 'RESTRICT') {
            $wrong[] = sprintf(
                '%s on %s is ON DELETE %s ON UPDATE %s',
                $row['CONSTRAINT_NAME'],
                $row['TABLE_NAME'],
                $row['DELETE_RULE'],
                $row['UPDATE_RULE']
            );
        }
    }

    assertSame([], $wrong, 'foreign keys to member must RESTRICT');
});

test('member.division_id is NOT NULL', function (): void {
    // 72 members arrive with a blank Subcommittee 3 and land in the seeded
    // (No Division) row instead of a NULL, so no query carries a null branch
    // and no roll-up can quietly omit a bucket.
    $rows = schema_rows(
        'SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member' "
        . "AND COLUMN_NAME IN ('division_id', 'team_id')"
    );

    $nullable = [];
    foreach ($rows as $row) {
        $nullable[(string) $row['COLUMN_NAME']] = $row['IS_NULLABLE'];
    }

    assertSame('NO', $nullable['division_id'] ?? null, 'member.division_id must be NOT NULL');
    // team_id stays nullable on purpose: a member can exist before their team
    // row does, and Subcommittee 1 is not guaranteed for ever.
    assertSame('YES', $nullable['team_id'] ?? null, 'member.team_id is nullable by design');
});

// ---------------------------------------------------------------------------
// Reference data
// ---------------------------------------------------------------------------

test('the (No Division) row exists and is flagged is_placeholder', function (): void {
    $rows = schema_rows(
        "SELECT id, name, is_placeholder, is_active FROM division WHERE name = '(No Division)'"
    );

    assertSame(1, count($rows), 'the seeded (No Division) row is missing');
    assertSame(1, (int) $rows[0]['is_placeholder'], '(No Division) must be flagged is_placeholder');
    assertSame(1, (int) $rows[0]['is_active'], '(No Division) is scopeable, so it must be active');
});

test('(No Division) is the only placeholder division', function (): void {
    // It is ours, not Rodeo Houston's. The flag is what tells the export to
    // write it back as BLANK rather than as the literal text, and a second
    // flagged row would make that rule ambiguous.
    $names = schema_pdo()
        ->query('SELECT name FROM division WHERE is_placeholder = 1 ORDER BY name')
        ->fetchAll(PDO::FETCH_COLUMN);

    assertSame(['(No Division)'], $names);
});

test('the four export divisions are seeded alongside it', function (): void {
    $names = schema_pdo()
        ->query('SELECT name FROM division WHERE is_placeholder = 0 ORDER BY name')
        ->fetchAll(PDO::FETCH_COLUMN);

    assertSame(
        ['Bus Ops Division', 'Logistics Division', 'Member Services Division', 'Satellites Division'],
        $names
    );
});

test('exactly one show year is active, and a second is refused', function (): void {
    $pdo = schema_pdo();

    $active = (int) $pdo->query('SELECT COUNT(*) FROM show_year WHERE is_active = 1')->fetchColumn();
    assertSame(1, $active, 'exactly one show year is active at a time');

    // Enforced by the schema, not by hope: is_active_key is 1 when active and
    // NULL otherwise, and NULL does not collide in a unique index. Two active
    // show years would mean metrics and contacts split across both.
    assertThrows(
        static function () use ($pdo): void {
            $pdo->exec("INSERT INTO show_year (label, is_open, is_active) VALUES ('probe-second-active', 1, 1)");
        },
        '',
        'the database allowed a second active show year'
    );

    // The failed INSERT must not have left anything behind.
    $after = (int) $pdo->query('SELECT COUNT(*) FROM show_year WHERE is_active = 1')->fetchColumn();
    assertSame(1, $after);
});

// ---------------------------------------------------------------------------
// The master admin
// ---------------------------------------------------------------------------

test('the master admin exists, as an Admin, on a member row', function (): void {
    $rows = schema_rows(
        'SELECT m.member_number, m.is_system, m.title_level, m.email, '
        . '       u.level, u.granted_level, u.effective_level, u.is_active, u.must_change_password '
        . 'FROM member m INNER JOIN app_user u ON u.member_id = m.id '
        . "WHERE m.member_number = '987654321'"
    );

    assertSame(1, count($rows), 'the seeded master admin is missing');
    $admin = $rows[0];

    assertSame('admin', $admin['effective_level'], 'the master admin must be an Admin');
    // Through granted_level, by the same Allowed User mechanism every later
    // designation uses — which is what makes it durable: no import writes it.
    assertSame('admin', $admin['granted_level']);
    assertSame('member', $admin['level'], 'the title-derived level is what an import would compute');
    assertSame('member', $admin['title_level']);
    assertSame(1, (int) $admin['is_active']);
    assertSame(1, (int) $admin['must_change_password']);
    // Not on the committee: an import must never absent, purge or export it.
    assertSame(1, (int) $admin['is_system']);
    // No address on file, so there is no emailed route into the account.
    assertSame(null, $admin['email']);
});

test('the master admin cannot be authenticated against', function (): void {
    $hash = (string) schema_pdo()->query(
        'SELECT u.password_hash FROM app_user u '
        . 'INNER JOIN member m ON m.id = u.member_id '
        . "WHERE m.member_number = '987654321'"
    )->fetchColumn();

    assertTrue($hash !== '', 'the master admin has no password_hash column value at all');

    // This repository is public. The account ships locked and is unlocked once,
    // deliberately, on the machine running the app.
    $candidates = [
        '', ' ', '*', '**', '*0', '*1', $hash,
        '1234', 'password', 'admin', 'rerm', '987654321',
        'Password1', 'changeme', 'letmein', bin2hex(random_bytes(8)),
    ];

    foreach ($candidates as $candidate) {
        assertTrue(
            password_verify($candidate, $hash) === false,
            'password_verify() accepted ' . var_export($candidate, true) . ' against the shipped hash'
        );
    }

    // And it is not a hash at all — so nobody can attack it offline either.
    $info = password_get_info($hash);
    assertSame(null, $info['algo'], 'the shipped value is a real password hash; it must not be');
});

test('no migration carries a password hash', function (): void {
    // The three crypt prefixes password_hash() can produce. A bcrypt hash of a
    // weak password is a weak password, published — and git history does not
    // forget what was pushed to a public repository.
    $problems = [];
    foreach (glob(dirname(__DIR__) . '/db/migrations/*.sql') ?: [] as $file) {
        $sql = (string) file_get_contents($file);
        if (preg_match('/\$2[aby]?\$|\$argon2i\$|\$argon2id\$/', $sql) === 1) {
            $problems[] = basename($file);
        }
    }

    assertSame([], $problems, 'a migration contains a password hash');
});

// ---------------------------------------------------------------------------
// Behaviour the schema is supposed to guarantee
// ---------------------------------------------------------------------------

test('a member cannot be deleted while contact history points at it', function (): void {
    $pdo = schema_pdo();

    // RESTRICT read back as behaviour rather than as metadata. The purge is a
    // soft delete precisely because this is true (spec 5.5, 6.5).
    $id = (int) $pdo->query(
        "SELECT id FROM member WHERE member_number = '987654321'"
    )->fetchColumn();

    $year = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();
    $user = (int) $pdo->query("SELECT id FROM app_user WHERE member_id = {$id}")->fetchColumn();

    $pdo->beginTransaction();
    try {
        $pdo->exec(
            'INSERT INTO contact_log (member_id, show_year_id, contacted_by, contact_type, notes) '
            . "VALUES ({$id}, {$year}, {$user}, 'call', 'schema test probe')"
        );

        assertThrows(
            static function () use ($pdo, $id): void {
                $pdo->exec("DELETE FROM member WHERE id = {$id}");
            },
            '',
            'a member with contact history was deletable'
        );
    } finally {
        // Nothing this test wrote survives it, whatever happened above.
        $pdo->rollBack();
    }
});

test('one live assignment per member, officer and show year', function (): void {
    $pdo = schema_pdo();

    $member = (int) $pdo->query("SELECT id FROM member WHERE member_number = '987654321'")->fetchColumn();
    $year   = (int) $pdo->query('SELECT id FROM show_year WHERE is_active = 1')->fetchColumn();

    $insert = "INSERT INTO assignment (member_id, officer_member_id, show_year_id) "
        . "VALUES ({$member}, {$member}, {$year})";

    $pdo->beginTransaction();
    try {
        $pdo->exec($insert);

        assertThrows(
            static function () use ($pdo, $insert): void {
                $pdo->exec($insert);
            },
            '',
            'a second live assignment for the same member, officer and year was allowed'
        );

        // Removed rows are superseded, never deleted, and any number of them
        // may sit behind the live one: is_current is NULL once removed, and
        // NULL does not collide.
        $pdo->exec("UPDATE assignment SET removed_at = UTC_TIMESTAMP() WHERE member_id = {$member}");
        $pdo->exec($insert);
        $pdo->exec("UPDATE assignment SET removed_at = UTC_TIMESTAMP() WHERE member_id = {$member}");
        $pdo->exec($insert);

        $live = (int) $pdo->query(
            "SELECT COUNT(*) FROM assignment WHERE member_id = {$member} AND removed_at IS NULL"
        )->fetchColumn();
        assertSame(1, $live);
    } finally {
        $pdo->rollBack();
    }
});

test('effective level is granted_level, falling back to the title level', function (): void {
    $pdo = schema_pdo();
    $id  = (int) $pdo->query(
        "SELECT u.id FROM app_user u INNER JOIN member m ON m.id = u.member_id "
        . "WHERE m.member_number = '987654321'"
    )->fetchColumn();

    $pdo->beginTransaction();
    try {
        // An import rewrites the title-derived level. It never touches
        // granted_level, and that is the whole reason designation exists.
        $pdo->exec("UPDATE app_user SET level = 'member' WHERE id = {$id}");
        assertSame(
            'admin',
            $pdo->query("SELECT effective_level FROM app_user WHERE id = {$id}")->fetchColumn(),
            'a grant must survive a demotion by import'
        );

        $pdo->exec("UPDATE app_user SET granted_level = NULL WHERE id = {$id}");
        assertSame(
            'member',
            $pdo->query("SELECT effective_level FROM app_user WHERE id = {$id}")->fetchColumn(),
            'with no grant, the effective level is the title level'
        );

        $pdo->exec("UPDATE app_user SET level = 'officer' WHERE id = {$id}");
        assertSame(
            'officer',
            $pdo->query("SELECT effective_level FROM app_user WHERE id = {$id}")->fetchColumn()
        );
    } finally {
        $pdo->rollBack();
    }
});

test('the metric enum carries the four scored metrics and harassment training', function (): void {
    $type = (string) schema_pdo()->query(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_metric' AND COLUMN_NAME = 'metric'"
    )->fetchColumn();

    foreach (['hlsr_dues', 'committee_dues', 'indemnity', 'background_check', 'harassment_training'] as $metric) {
        assertTrue(str_contains($type, "'{$metric}'"), "member_metric.metric has no {$metric}");
    }

    // Blank is not N. 1,716 of 1,954 rows have no harassment training value at
    // all, and it renders as "Not reported" rather than as a failure — which
    // needs a third state to be representable in the first place.
    $imported = (string) schema_pdo()->query(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_metric' AND COLUMN_NAME = 'imported_value'"
    )->fetchColumn();

    foreach (["'Y'", "'N'", "'unknown'"] as $value) {
        assertTrue(str_contains($imported, $value), "member_metric.imported_value has no {$value}");
    }
});

// ---------------------------------------------------------------------------
// The migrator. No database needed for the first three.
// ---------------------------------------------------------------------------

test('the splitter ignores semicolons inside strings, comments and identifiers', function (): void {
    $sql = <<<'SQL'
        -- a comment; with a semicolon
        INSERT INTO `t;odd` (`a`) VALUES ('one; two');
        /* another; comment */
        INSERT INTO `t` (`a`) VALUES ('it\'s here; still one row');
        # hash comment; too
        SELECT 1
        SQL;

    $statements = Migrator::split($sql);

    assertSame(3, count($statements), 'expected three statements, got: ' . var_export($statements, true));
    assertTrue(str_contains($statements[0], "'one; two'"));
    assertTrue(str_contains($statements[1], "it\\'s here; still one row"));
    assertSame('SELECT 1', $statements[2]);
    // Comments are stripped, so nothing downstream has to parse around them.
    assertTrue(!str_contains(implode(' ', $statements), 'comment'));
});

test('a doubled quote is an escaped quote, not the end of a string', function (): void {
    $statements = Migrator::split("SELECT 'O''Brien; and co'; SELECT 2");

    assertSame(2, count($statements));
    assertSame("SELECT 'O''Brien; and co'", $statements[0]);
});

test('-- is only a comment when whitespace follows it', function (): void {
    // "5--3" is 5 minus negative 3 in SQL, not the start of a comment.
    $statements = Migrator::split('SELECT 5--3');

    assertSame(1, count($statements));
    assertSame('SELECT 5--3', $statements[0]);
});

test('the committed migrations parse, and none uses RETURNING', function (): void {
    // RETURNING is a MariaDB extension. Production is MySQL 8.0, so a
    // migration carrying one passes CI's MariaDB job and fails on the only
    // server that matters. The check runs over stripped statements, so the
    // prose above cannot trip it.
    $seen = 0;
    foreach (glob(dirname(__DIR__) . '/db/migrations/*.sql') ?: [] as $file) {
        foreach (Migrator::split((string) file_get_contents($file)) as $index => $statement) {
            $seen++;
            assertTrue(
                preg_match('/\bRETURNING\b/i', $statement) !== 1,
                basename($file) . " statement {$index} uses RETURNING"
            );
        }
    }

    assertTrue($seen > 0, 'no migrations found to parse');
});

test('an applied migration that changes is refused', function (): void {
    $pdo   = schema_pdo();
    $probe = '999_schema_test_probe.sql';
    $dir   = sys_get_temp_dir() . '/rerm-migrator-' . bin2hex(random_bytes(6));

    mkdir($dir, 0700, true);

    // The real migrations are copied in so the registry stays consistent:
    // without them every applied row would read as MISSING and the migrator
    // would refuse before reaching the probe.
    foreach (glob(dirname(__DIR__) . '/db/migrations/*.sql') ?: [] as $file) {
        copy($file, $dir . '/' . basename($file));
    }

    // DO is a no-op statement on both engines, so applying the probe changes
    // nothing but the registry — which is cleaned up below whatever happens.
    file_put_contents($dir . '/' . $probe, "-- rerm:atomic\nDO 0;\n");

    try {
        $migrator = new Migrator($pdo, $dir);
        assertSame([$probe], $migrator->migrate());

        file_put_contents($dir . '/' . $probe, "-- rerm:atomic\nDO 1;\n");
        assertThrows(
            static fn () => $migrator->assertRegistryIntact(),
            'immutable',
            'a changed migration was not refused'
        );

        unlink($dir . '/' . $probe);
        assertThrows(
            static fn () => $migrator->assertRegistryIntact(),
            'cannot account for',
            'a migration recorded but missing from disk was not refused'
        );
    } finally {
        $pdo->prepare('DELETE FROM `' . Migrator::REGISTRY . '` WHERE filename = ?')->execute([$probe]);
        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});

test('a migration cannot ask for a transaction around DDL', function (): void {
    $pdo = schema_pdo();
    $dir = sys_get_temp_dir() . '/rerm-migrator-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    foreach (glob(dirname(__DIR__) . '/db/migrations/*.sql') ?: [] as $file) {
        copy($file, $dir . '/' . basename($file));
    }

    // MySQL commits implicitly on DDL, so a transaction around a CREATE TABLE
    // would report a rollback that never happened — the worst possible answer
    // to "did that migration apply?". It is refused before anything runs, so
    // the table below is never created.
    file_put_contents(
        $dir . '/999_schema_test_atomic_ddl.sql',
        "-- rerm:atomic\nCREATE TABLE `rerm_should_never_exist` (`id` INT);\n"
    );

    try {
        assertThrows(
            static fn () => (new Migrator($pdo, $dir))->migrate(),
            'commits implicitly on DDL',
            'an atomic schema migration was allowed'
        );

        $created = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rerm_should_never_exist'"
        )->fetchColumn();
        assertSame(0, $created, 'the refused migration ran anyway');
    } finally {
        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});
