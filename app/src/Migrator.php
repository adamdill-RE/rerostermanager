<?php

declare(strict_types=1);

namespace Rerm;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Applies numbered .sql files once each, in order, and remembers what it did.
 *
 * The remembering is the point. A migration is applied to a database nobody
 * can roll back — production is a shared MySQL host with cPanel's nightly
 * backup and no staging copy — so the only defence against "this file used to
 * say something else" is a checksum recorded at the moment it ran. A changed
 * file is refused, loudly, naming the file. The fix is always a new migration,
 * never an edit.
 *
 * Two constraints from this host shape the rest of it:
 *
 *   * MySQL commits implicitly on DDL. A CREATE TABLE cannot be rolled back,
 *     so a schema migration that fails halfway leaves the tables it already
 *     made. Schema migrations are therefore written with IF NOT EXISTS so the
 *     next attempt can start again from the top, and they are NOT wrapped in
 *     a transaction — one that appeared to protect them would be a lie.
 *   * A migration whose statements are pure data may opt into a transaction
 *     by carrying a `-- rerm:atomic` line. Mixing the two is refused: an
 *     atomic file containing DDL would commit half of itself and report a
 *     rollback.
 *
 * There is no down migration, deliberately. A down migration is written when
 * the schema is fresh in mind and run months later against data that did not
 * exist when it was written; what it usually does is delete a column somebody
 * has since filled. Going backwards here means restoring a backup.
 */
final class Migrator
{
    public const REGISTRY = 'schema_migration';

    /** A migration may ask for a transaction with this, if it is pure data. */
    private const ATOMIC_DIRECTIVE = '-- rerm:atomic';

    /** Statement verbs that commit implicitly under MySQL. */
    private const DDL = ['CREATE', 'ALTER', 'DROP', 'RENAME', 'TRUNCATE'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /**
     * The registry itself, created on first use.
     *
     * It is not migration 000: something has to exist before the first
     * migration can be recorded, and a migration that records itself is a
     * chicken-and-egg problem with a worse failure mode. It follows the same
     * conventions as every other table because tests/schema_test.php checks
     * every table in the database, this one included.
     */
    public function ensureRegistry(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::REGISTRY . '` ('
            . '`filename` VARCHAR(191) NOT NULL,'
            . '`checksum` CHAR(64) NOT NULL,'
            . '`applied_at` DATETIME NOT NULL,'
            . '`execution_ms` INT UNSIGNED NOT NULL,'
            . '`statements` SMALLINT UNSIGNED NOT NULL,'
            . '`applied_by` VARCHAR(64) NOT NULL,'
            . 'PRIMARY KEY (`filename`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** Migration files on disk, in the order they will be applied. */
    public function files(): array
    {
        $found = glob($this->directory . '/*.sql') ?: [];
        sort($found, SORT_STRING);

        return array_map('basename', $found);
    }

    /** @return array<string, array{checksum: string, applied_at: string}> */
    public function applied(): array
    {
        $this->ensureRegistry();

        $rows = $this->pdo
            ->query('SELECT filename, checksum, applied_at FROM `' . self::REGISTRY . '` ORDER BY filename')
            ->fetchAll();

        $applied = [];
        foreach ($rows as $row) {
            $applied[(string) $row['filename']] = [
                'checksum'   => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }

        return $applied;
    }

    /**
     * Every migration with its state.
     *
     * @return array<int, array{filename: string, state: string, applied_at: ?string, checksum: string}>
     *         state: pending | applied | CHANGED | MISSING
     */
    public function status(): array
    {
        $applied = $this->applied();
        $status  = [];

        foreach ($this->files() as $filename) {
            $checksum = $this->checksum($filename);
            $record   = $applied[$filename] ?? null;
            unset($applied[$filename]);

            if ($record === null) {
                $state = 'pending';
            } elseif ($record['checksum'] !== $checksum) {
                $state = 'CHANGED';
            } else {
                $state = 'applied';
            }

            $status[] = [
                'filename'   => $filename,
                'state'      => $state,
                'applied_at' => $record['applied_at'] ?? null,
                'checksum'   => $checksum,
            ];
        }

        // Applied, but no longer on disk. Usually a bad merge or a file
        // renamed to "fix" its number; either way the database contains
        // changes this checkout cannot account for.
        foreach ($applied as $filename => $record) {
            $status[] = [
                'filename'   => $filename,
                'state'      => 'MISSING',
                'applied_at' => $record['applied_at'],
                'checksum'   => $record['checksum'],
            ];
        }

        return $status;
    }

    /** @return array<int, string> filenames not yet applied */
    public function pending(): array
    {
        $pending = [];
        foreach ($this->status() as $row) {
            if ($row['state'] === 'pending') {
                $pending[] = $row['filename'];
            }
        }

        return $pending;
    }

    /**
     * Refuses to go on if the record and the disk disagree.
     *
     * Called before anything is applied, so a mismatch in 001 stops 004 from
     * running against a schema that is not the one it was written for.
     */
    public function assertRegistryIntact(): void
    {
        $problems = [];
        foreach ($this->status() as $row) {
            if ($row['state'] === 'CHANGED') {
                $problems[] = sprintf(
                    '%s was applied on %s and has changed since. Migrations are immutable: '
                    . 'add a new file rather than editing this one.',
                    $row['filename'],
                    (string) $row['applied_at']
                );
            }
            if ($row['state'] === 'MISSING') {
                $problems[] = sprintf(
                    '%s is recorded as applied on %s but is not in db/migrations. '
                    . 'This database contains changes this checkout cannot account for.',
                    $row['filename'],
                    (string) $row['applied_at']
                );
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(implode("\n", $problems));
        }
    }

    /**
     * Applies everything pending.
     *
     * @param callable(string):void|null $report progress, one line at a time
     * @return array<int, string> the filenames applied (or that would be)
     */
    public function migrate(bool $dryRun = false, ?callable $report = null): array
    {
        $this->assertRegistryIntact();

        $report ??= static function (string $line): void {
        };

        $done = [];
        foreach ($this->pending() as $filename) {
            $sql        = $this->read($filename);
            $atomic     = $this->isAtomic($sql);
            $statements = self::split($sql);

            $this->assertRunnable($filename, $statements, $atomic);

            if ($dryRun) {
                $report(sprintf(
                    '  would apply %s — %d statement%s%s',
                    $filename,
                    count($statements),
                    count($statements) === 1 ? '' : 's',
                    $atomic ? ', in a transaction' : ''
                ));
                $done[] = $filename;
                continue;
            }

            $started = microtime(true);
            $this->run($filename, $statements, $atomic);
            $elapsed = (int) round((microtime(true) - $started) * 1000);

            $this->record($filename, $statements, $elapsed);

            $report(sprintf(
                '  applied %s — %d statement%s in %dms',
                $filename,
                count($statements),
                count($statements) === 1 ? '' : 's',
                $elapsed
            ));
            $done[] = $filename;
        }

        return $done;
    }

    public function checksum(string $filename): string
    {
        return hash('sha256', $this->read($filename));
    }

    /**
     * Splits a migration into executable statements.
     *
     * Comments are stripped rather than passed through: a `;` inside one would
     * otherwise end a statement early, and stripping them is also what lets
     * assertRunnable() look for DDL and for RETURNING without matching the
     * prose that explains why they are forbidden.
     *
     * @return array<int, string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current    = '';
        $length     = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // -- comment, but only when followed by whitespace. "--" without
            // one is an operator in MySQL, not a comment.
            if ($char === '-' && $next === '-' && ($i + 2 >= $length || preg_match('/\s/', $sql[$i + 2]) === 1)) {
                $end = strpos($sql, "\n", $i);
                $i   = $end === false ? $length : $end;
                $current .= "\n";
                continue;
            }

            if ($char === '#') {
                $end = strpos($sql, "\n", $i);
                $i   = $end === false ? $length : $end;
                $current .= "\n";
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new RuntimeException('Unterminated /* block comment');
                }
                $i = $end + 1;
                $current .= ' ';
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $literal = self::readQuoted($sql, $i, $char);
                $current .= $literal;
                continue;
            }

            if ($char === ';') {
                $statements[] = $current;
                $current      = '';
                continue;
            }

            $current .= $char;
        }

        $statements[] = $current;

        $clean = [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $clean[] = $statement;
            }
        }

        return $clean;
    }

    /** Consumes a quoted run starting at $i, advancing $i past its close. */
    private static function readQuoted(string $sql, int &$i, string $quote): string
    {
        $length  = strlen($sql);
        $literal = $quote;

        for ($i++; $i < $length; $i++) {
            $char = $sql[$i];

            // Backslash escapes are on by default in MySQL, so '\'' is one
            // quote inside a string and not the end of it.
            if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                $literal .= $char . $sql[$i + 1];
                $i++;
                continue;
            }

            if ($char === $quote) {
                // A doubled quote is an escaped quote, not a close.
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $literal .= $quote . $quote;
                    $i++;
                    continue;
                }

                return $literal . $quote;
            }

            $literal .= $char;
        }

        throw new RuntimeException("Unterminated {$quote} literal in migration");
    }

    public function isAtomic(string $sql): bool
    {
        foreach (explode("\n", $sql) as $line) {
            if (rtrim($line) === self::ATOMIC_DIRECTIVE) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $statements */
    private function assertRunnable(string $filename, array $statements, bool $atomic): void
    {
        if ($statements === []) {
            throw new RuntimeException("{$filename} contains no statements");
        }

        foreach ($statements as $index => $statement) {
            $verb = strtoupper((string) preg_replace('/^(\w+).*$/s', '$1', $statement));

            if ($atomic && in_array($verb, self::DDL, true)) {
                throw new RuntimeException(sprintf(
                    '%s asks for a transaction with "%s" but statement %d is %s. '
                    . 'MySQL commits implicitly on DDL, so the transaction could not roll it '
                    . 'back and would report a rollback that did not happen. Only pure-data '
                    . 'migrations may be atomic.',
                    $filename,
                    self::ATOMIC_DIRECTIVE,
                    $index + 1,
                    $verb
                ));
            }

            // MariaDB has RETURNING; MySQL 8.0 does not, and production is
            // MySQL. A migration carrying one passes CI's MariaDB job, passes
            // review, and fails on the only server that matters.
            if (preg_match('/\bRETURNING\b/i', $statement) === 1) {
                throw new RuntimeException(sprintf(
                    '%s statement %d uses RETURNING, which is a MariaDB extension. '
                    . 'Production is MySQL 8.0 — take a second statement instead.',
                    $filename,
                    $index + 1
                ));
            }
        }
    }

    /** @param array<int, string> $statements */
    private function run(string $filename, array $statements, bool $atomic): void
    {
        if ($atomic) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($statements as $index => $statement) {
                $this->pdo->exec($statement);
            }
        } catch (Throwable $e) {
            if ($atomic && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException(sprintf(
                "%s failed at statement %d:\n  %s\n%s%s",
                $filename,
                ($index ?? 0) + 1,
                self::firstLine($statements[$index ?? 0] ?? ''),
                $e->getMessage(),
                $atomic
                    ? "\nThe transaction was rolled back."
                    : "\nStatements before it are COMMITTED — MySQL commits implicitly on DDL. "
                        . 'Fix the file and run again; it is written to be safe to re-run.'
            ), 0, $e);
        }

        if ($atomic) {
            $this->pdo->commit();
        }
    }

    /** @param array<int, string> $statements */
    private function record(string $filename, array $statements, int $elapsedMs): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO `' . self::REGISTRY . '` '
            . '(filename, checksum, applied_at, execution_ms, statements, applied_by) '
            . 'VALUES (:filename, :checksum, UTC_TIMESTAMP(), :execution_ms, :statements, :applied_by)'
        );

        $insert->execute([
            ':filename'     => $filename,
            ':checksum'     => $this->checksum($filename),
            ':execution_ms' => $elapsedMs,
            ':statements'   => count($statements),
            ':applied_by'   => substr(self::whoami(), 0, 64),
        ]);
    }

    private function read(string $filename): string
    {
        // Migration names come from glob() over one directory, but this class
        // is public and a caller could pass anything.
        if (basename($filename) !== $filename) {
            throw new RuntimeException("Not a migration filename: {$filename}");
        }

        $path     = $this->directory . '/' . $filename;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("Cannot read migration {$path}");
        }

        // Checksums are over normalised line endings, so a checkout on a
        // machine with autocrlf does not read as a modified migration.
        return str_replace("\r\n", "\n", $contents);
    }

    private static function firstLine(string $statement): string
    {
        $line = trim((string) strtok($statement, "\n"));

        return strlen($line) > 120 ? substr($line, 0, 117) . '...' : $line;
    }

    private static function whoami(): string
    {
        $user = getenv('USER') ?: getenv('USERNAME') ?: (function_exists('get_current_user') ? get_current_user() : '');
        $host = gethostname();

        return ($user === '' ? 'unknown' : $user) . '@' . ($host === false ? 'unknown' : $host);
    }
}
