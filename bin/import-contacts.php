<?php

declare(strict_types=1);

/**
 * Load a contact history that predates this application (spec 6.7).
 *
 *   php bin/import-contacts.php --officer=1234567 --team="Bus Ops Team A" history.xlsx
 *   php bin/import-contacts.php --apply=3                 write staged batch 3
 *   php bin/import-contacts.php --dry-run --officer=… f.csv   parse, report, keep nothing
 *   php bin/import-contacts.php --list                    what is staged
 *   php bin/import-contacts.php --discard=3               throw a staged batch away
 *   php bin/import-contacts.php --template > history.csv  a file with the right headers
 *
 * TWO STEPS, like the roster import and for the same reason: this one writes
 * rows into `contact_log`, which is the longest-lived table in the database
 * and is never edited or deleted afterwards. Eighty permanent rows is worth
 * reading a preview for.
 *
 * THE SERVER CANNOT RUN THIS. Ahosting gives this account no SSH and no cPanel
 * Terminal (docs/hosting.md), so the real load goes through /import-contacts.
 * This exists for local work and for the tests.
 *
 * Exit codes: 0 nothing wrong, 1 something is.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Rerm\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

use Rerm\Import\ContactHeaderMap;
use Rerm\Import\ContactImporter;
use Rerm\Import\ImportException;

const CONTACTS_USAGE = <<<TEXT
    Usage: php bin/import-contacts.php [options] <file>
           php bin/import-contacts.php --apply=<batch id>

      --officer=<number>  member number of the officer unattributed rows belong
                          to. Required to stage.
      --team=<name|id>    the team names are resolved within. Required unless
                          every row carries a Customer Number.
      --dry-run           parse and report, then discard the batch
      --apply=<id>        write a staged batch. This is the step that writes.
      --list              staged batches waiting to be applied
      --discard=<id>      throw a staged batch away
      --rows=<kind>       list the rows behind one outcome kind
      --limit=<n>         how many rows to show in a list (default 20)
      --template          print a CSV header row this import understands

    .xls, .xlsx and .csv are all read natively, chosen by looking inside the
    file rather than at its name.

    TEXT;

/** @return array{options: array<string, string>, files: array<int, string>} */
function contacts_arguments(array $argv): array
{
    $options = [];
    $files   = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            $files[] = $argument;
            continue;
        }

        $body = substr($argument, 2);
        $at   = strpos($body, '=');

        if ($at === false) {
            $options[$body] = '';
            continue;
        }

        $options[substr($body, 0, $at)] = substr($body, $at + 1);
    }

    return ['options' => $options, 'files' => $files];
}

function contacts_out(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

function contacts_fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * The officer's app_user.id from their member number.
 *
 * By member number rather than by id because that is the identifier a person
 * has in front of them — it is on every screen in the application and on the
 * roster export, and an `app_user.id` is not written down anywhere a human
 * reads.
 */
function contacts_officer_id(PDO $pdo, string $memberNumber): int
{
    $read = $pdo->prepare(
        'SELECT u.id FROM app_user u JOIN member m ON m.id = u.member_id'
        . ' WHERE m.member_number = :number AND u.is_active = 1'
    );
    $read->execute([':number' => $memberNumber]);
    $id = $read->fetchColumn();

    if ($id === false) {
        contacts_fail(
            "No active account belongs to member number {$memberNumber}.\n\n"
            . 'Every contact belongs to the officer who made it, so the load needs one to '
            . 'attribute unlabelled rows to.'
        );
    }

    return (int) $id;
}

/** A team id from a name or an id. */
function contacts_team_id(PDO $pdo, string $team): int
{
    if (ctype_digit($team)) {
        $read = $pdo->prepare('SELECT id FROM team WHERE id = :id');
        $read->execute([':id' => (int) $team]);
    } else {
        $read = $pdo->prepare('SELECT id FROM team WHERE name = :name');
        $read->execute([':name' => $team]);
    }

    $id = $read->fetchColumn();
    if ($id === false) {
        contacts_fail("There is no team \"{$team}\".");
    }

    return (int) $id;
}

/** @param array<string, mixed> $row */
function contacts_member_label(array $row): string
{
    $first = trim((string) ($row['preferred_name'] ?? '')) !== ''
        ? trim((string) $row['preferred_name'])
        : trim((string) ($row['first_name'] ?? ''));

    $name = trim($first . ' ' . trim((string) ($row['last_name'] ?? '')));

    return $name !== '' ? $name : (string) ($row['raw_member'] ?? '?');
}

// ---------------------------------------------------------------------------

$parsed   = contacts_arguments($argv);
$options  = $parsed['options'];
$files    = $parsed['files'];
$importer = ContactImporter::fromApp($app);
$pdo      = $app->db();

if (isset($options['help']) || ($options === [] && $files === [])) {
    contacts_out(CONTACTS_USAGE);
    exit(0);
}

if (isset($options['template'])) {
    // The first spelling of each field — the one the screen documents.
    $headers = [];
    foreach ([
        ContactHeaderMap::MEMBER_NUMBER,
        ContactHeaderMap::MEMBER_NAME,
        ContactHeaderMap::OCCURRED_AT,
        ContactHeaderMap::CONTACT_TYPE,
        ContactHeaderMap::OFFICER,
        ContactHeaderMap::NOTES,
    ] as $field) {
        $headers[] = ContactHeaderMap::ALIASES[$field][0];
    }

    contacts_out(implode(',', $headers));
    exit(0);
}

try {
    if (isset($options['list'])) {
        $limit   = (int) ($options['limit'] ?? 20);
        $staged  = $importer->stagedBatches($limit > 0 ? $limit : 20);
        $applied = $importer->appliedBatches($limit > 0 ? $limit : 20);

        contacts_out('Staged, waiting for an apply:');
        if ($staged === []) {
            contacts_out('  (none)');
        }
        foreach ($staged as $batch) {
            contacts_out(sprintf(
                '  %-5s %-32s %s read, %s ready, %s duplicate, %s skipped — staged %s UTC',
                (string) $batch['id'],
                (string) $batch['filename'],
                (string) $batch['rows_read'],
                (string) $batch['rows_ready'],
                (string) $batch['rows_duplicate'],
                (string) $batch['rows_skipped'],
                (string) $batch['started_at']
            ));
        }

        contacts_out();
        contacts_out('Applied:');
        if ($applied === []) {
            contacts_out('  (none)');
        }
        foreach ($applied as $batch) {
            contacts_out(sprintf(
                '  %-5s %-32s %s written — applied %s UTC',
                (string) $batch['id'],
                (string) $batch['filename'],
                (string) $batch['rows_inserted'],
                (string) $batch['applied_at']
            ));
        }

        exit(0);
    }

    if (isset($options['discard'])) {
        $batchId = (int) $options['discard'];
        $importer->discard($batchId);
        contacts_out("Staged batch {$batchId} discarded. Nothing was written.");
        exit(0);
    }

    if (isset($options['rows'])) {
        $batchId = (int) ($options['batch'] ?? 0);
        if ($batchId <= 0) {
            contacts_fail('--rows needs --batch=<id> to say which batch.');
        }

        $limit = max(1, (int) ($options['limit'] ?? 20));
        $read  = $pdo->prepare(
            'SELECT r.*, m.first_name, m.last_name, m.preferred_name FROM contact_import_row r'
            . ' LEFT JOIN member m ON m.id = r.member_id'
            . " WHERE r.batch_id = :id AND r.outcome_kind = :kind ORDER BY r.`row_number` LIMIT {$limit}"
        );
        $read->execute([':id' => $batchId, ':kind' => (string) $options['rows']]);

        foreach ($read->fetchAll() as $row) {
            contacts_out(sprintf(
                '  row %-5s %-28s %s',
                (string) $row['row_number'],
                contacts_member_label($row),
                (string) $row['detail']
            ));
        }

        exit(0);
    }

    if (isset($options['apply'])) {
        $batchId = (int) $options['apply'];
        $result  = $importer->apply($batchId);

        contacts_out(sprintf(
            'Applied. %s contact(s) written, %s already logged, %s skipped.',
            number_format($result['inserted']),
            number_format($result['duplicate']),
            number_format($result['skipped'])
        ));

        exit(0);
    }

    // ---- staging, which is everything else -------------------------------

    if ($files === []) {
        contacts_fail("No file given.\n\n" . CONTACTS_USAGE);
    }
    if (!isset($options['officer'])) {
        contacts_fail(
            "--officer=<member number> is required.\n\n"
            . 'Every contact belongs to the officer who made it. Rows carrying their own '
            . '"Contacted By" override it; the rest land against this one.'
        );
    }

    $officerId = contacts_officer_id($pdo, trim((string) $options['officer']));
    $teamId    = isset($options['team']) && trim((string) $options['team']) !== ''
        ? contacts_team_id($pdo, trim((string) $options['team']))
        : null;

    $path = $files[0];
    if (!is_file($path)) {
        contacts_fail("No such file: {$path}");
    }

    $duplicate = $importer->appliedWithSameContents($path);
    if ($duplicate !== null) {
        contacts_out(sprintf(
            'NOTE: this exact file was already applied as batch %s on %s UTC, writing %s '
            . 'contact(s). Staging it again is safe — every row it already wrote will be '
            . "recognised as a duplicate — but it will probably do nothing.\n",
            (string) $duplicate['id'],
            (string) $duplicate['applied_at'],
            (string) $duplicate['rows_inserted']
        ));
    }

    $batchId = $importer->stage($path, basename($path), $officerId, $teamId, $officerId);
    $preview = $importer->preview($batchId);
    $counts  = $preview['counts'];

    contacts_out(sprintf('Batch %d — %s', $batchId, basename($path)));
    contacts_out(str_repeat('-', 68));
    contacts_out(sprintf('  %6s rows read', number_format($counts['read'])));
    contacts_out(sprintf('  %6s would be written', number_format($counts['insert'])));
    contacts_out(sprintf('  %6s already logged', number_format($counts['duplicate'])));
    contacts_out(sprintf('  %6s cannot be landed', number_format($counts['skip'])));

    if ($preview['kinds'] !== []) {
        contacts_out();
        contacts_out('By outcome:');
        foreach (ContactImporter::KINDS as $kind) {
            if (!isset($preview['kinds'][$kind])) {
                continue;
            }
            contacts_out(sprintf(
                '  %-20s %5s%s',
                $kind,
                number_format($preview['kinds'][$kind]),
                in_array($kind, ContactImporter::KEPT_KINDS, true) ? '   (row still lands)' : ''
            ));
        }
        contacts_out();
        contacts_out("  php bin/import-contacts.php --batch={$batchId} --rows=<kind>   to see them");
    }

    if (isset($options['dry-run'])) {
        $importer->discard($batchId);
        contacts_out();
        contacts_out('Dry run — the batch was discarded and nothing was written.');
        exit(0);
    }

    contacts_out();
    contacts_out('NOTHING has been written to contact_log. To write it:');
    contacts_out("  php bin/import-contacts.php --apply={$batchId}");
    exit(0);
} catch (ImportException $e) {
    contacts_fail($e->getMessage());
} catch (Throwable $e) {
    contacts_fail('The import failed: ' . $e->getMessage());
}
