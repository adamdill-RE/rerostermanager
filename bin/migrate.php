<?php

declare(strict_types=1);

/**
 * Apply database migrations.
 *
 *     php bin/migrate.php              apply everything pending
 *     php bin/migrate.php --status     what is applied, what is pending
 *     php bin/migrate.php --dry-run    what would run, without running it
 *
 * Migrations are NEVER applied by a deploy. cPanel's Deploy HEAD Commit copies
 * files; this is run afterwards, by hand, by somebody who has just read
 * --status. A deploy that migrated itself would apply a schema change to the
 * live roster at the moment a file landed, with nobody watching.
 *
 * Exit codes: 0 nothing wrong, 1 something is.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Rerm\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

$arguments = array_slice($argv, 1);
$status    = in_array('--status', $arguments, true);
$dryRun    = in_array('--dry-run', $arguments, true);
$help      = in_array('--help', $arguments, true) || in_array('-h', $arguments, true);

$unknown = array_values(array_filter(
    $arguments,
    static fn (string $a): bool => !in_array($a, ['--status', '--dry-run', '--help', '-h'], true)
));

if ($help || $unknown !== []) {
    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown option: ' . implode(' ', $unknown) . "\n\n");
    }
    fwrite($unknown === [] ? STDOUT : STDERR, <<<TEXT
        Usage: php bin/migrate.php [--status|--dry-run]

          (no options)  apply every pending migration, in order
          --status      list every migration and its state, apply nothing
          --dry-run     list what would be applied, apply nothing

        Migrations are immutable once applied: the file's SHA-256 is recorded
        and a changed file is refused. Add a new migration instead.

        TEXT);
    exit($unknown === [] ? 0 : 1);
}

try {
    $migrator = $app->migrator();

    $target = sprintf(
        '%s@%s',
        (string) $app->config()->get('db.name'),
        (string) $app->config()->get('db.host')
    );

    if ($status) {
        $rows          = $migrator->status();
        $broken        = false;
        $appliedCount  = 0;

        if ($rows === []) {
            printf("No migrations in db/migrations.\n");
            exit(0);
        }

        printf("%s\n\n", $target);
        foreach ($rows as $row) {
            printf(
                "  %-9s %-28s %s\n",
                $row['state'],
                $row['filename'],
                $row['applied_at'] === null ? '' : $row['applied_at'] . ' UTC'
            );
            $broken = $broken || in_array($row['state'], ['CHANGED', 'MISSING'], true);
            $appliedCount += $row['state'] === 'applied' ? 1 : 0;
        }

        $pending = $migrator->pending();
        printf("\n%d applied, %d pending.\n", $appliedCount, count($pending));

        if ($broken) {
            fwrite(STDERR, "\nA migration has changed since it was applied, or is recorded but "
                . "missing.\nMigrations are immutable — add a new file rather than editing one.\n");
            exit(1);
        }

        if ($pending === []) {
            printf("Database is up to date.\n");
        }

        exit(0);
    }

    $pending = $migrator->pending();

    if ($pending === []) {
        // CI greps this line to prove a second run is a no-op, so the wording
        // is load-bearing.
        printf("%s\nDatabase is up to date. Nothing to apply.\n", $target);
        exit(0);
    }

    printf(
        "%s\n%s %d migration%s:\n",
        $target,
        $dryRun ? 'Would apply' : 'Applying',
        count($pending),
        count($pending) === 1 ? '' : 's'
    );

    $applied = $migrator->migrate($dryRun, static function (string $line): void {
        printf("%s\n", $line);
    });

    printf(
        "\n%s %d migration%s.\n",
        $dryRun ? 'Nothing changed —' : 'Applied',
        count($applied),
        count($applied) === 1 ? '' : 's'
    );

    if ($dryRun) {
        printf("Run without --dry-run to apply.\n");
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nMigration failed.\n\n" . $e->getMessage() . "\n");

    if ($app->debug()) {
        fwrite(STDERR, "\n" . $e->getTraceAsString() . "\n");
    }

    exit(1);
}
