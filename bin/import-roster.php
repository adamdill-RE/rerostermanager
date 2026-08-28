<?php

declare(strict_types=1);

/**
 * Import a Rodeo Houston roster (spec 6).
 *
 *   php bin/import-roster.php --dry-run roster.xls        parse, show the diff, keep nothing
 *   php bin/import-roster.php roster.xls                  parse, show the diff, leave it staged
 *   php bin/import-roster.php --apply=17                  commit staged batch 17
 *   php bin/import-roster.php --mode=team --team="Bus Ops Team A" roster.xls
 *   php bin/import-roster.php --list                      what is staged, and until when
 *   php bin/import-roster.php --discard=17                throw a staged batch away
 *
 * IT IS ALWAYS TWO STEPS, and that is the point. One command can rewrite 1,954
 * rows, so nothing here writes to `member` until a second invocation names a
 * batch id that somebody has read a diff for. --dry-run is the same first step
 * followed by a discard, for when the answer is "just tell me what is in this
 * file".
 *
 * THE SERVER CANNOT RUN THIS. Ahosting gives this account no SSH and no cPanel
 * Terminal (docs/hosting.md), so a production import goes through the /import
 * screen. This exists for local work against a real file — where 1,954 rows,
 * a 30-second ceiling and a diff are much easier to look at in a terminal.
 *
 * Exit codes: 0 nothing wrong, 1 something is.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Rerm\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

use Rerm\Import\Importer;
use Rerm\Import\ImportException;
use Rerm\Import\Warnings;

const USAGE = <<<TEXT
    Usage: php bin/import-roster.php [options] <file>
           php bin/import-roster.php --apply=<batch id>

      --dry-run           parse and show the diff, then discard the batch
      --mode=complete     every field, every metric; creates; drops the missing (default)
      --mode=update       metrics, phone and email only; creates nobody; flags nobody
      --mode=team         one team, verified against every row's Subcommittee 1
      --team=<name|id>    which team, for --mode=team
      --apply=<id>        commit a staged batch. This is the step that writes.
      --list              staged batches waiting to be applied
      --discard=<id>      throw a staged batch away
      --warnings=<kind>   list the rows behind one warning kind
      --limit=<n>         how many rows to show in each list (default 20)

    .xls, .xlsx and .csv are all read natively, chosen by looking inside the
    file rather than at its name.

    TEXT;

/** @return array{options: array<string, string>, files: array<int, string>} */
function parse_arguments(array $argv): array
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

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

/** A number with a thousands separator, right-aligned in $width. */
function num(int $value, int $width = 6): string
{
    return str_pad(number_format($value), $width, ' ', STR_PAD_LEFT);
}

/** @param array<string, mixed> $preview */
function print_preview(Rerm\App $app, array $preview): void
{
    $batch  = $preview['batch'];
    $counts = $preview['counts'];

    out();
    out(sprintf(
        'Batch %d · %s · show year %s%s',
        (int) $batch['id'],
        (string) $batch['mode'],
        (string) $batch['show_year_label'],
        $batch['team_name'] === null ? '' : ' · team ' . (string) $batch['team_name']
    ));
    out(sprintf('  %s · sha256 %s', (string) $batch['filename'], substr((string) $batch['sha256'], 0, 16)));
    out();

    out('  rows read      ' . num($counts['read']));
    out('  would create   ' . num($counts['create']));
    out('  would update   ' . num($counts['update']));
    out('  unchanged      ' . num($counts['unchanged']));
    out('  would flag     ' . num($counts['dropped']) . '  dropped — flagged, never deleted');
    out('  skipped        ' . num($counts['skipped']) . '  see the warnings below');

    if ($preview['metric_flips'] !== []) {
        out();
        out('Metric changes');
        foreach ($preview['metric_flips'] as $flip) {
            out(sprintf(
                '  %s  %-20s %s -> %s',
                num((int) $flip['members'], 6),
                (string) $flip['metric'],
                (string) $flip['from'],
                (string) $flip['to']
            ));
        }
    }

    if ($preview['new_teams'] !== []) {
        out();
        out(sprintf('Teams that would be created (%d)', count($preview['new_teams'])));
        foreach (array_slice($preview['new_teams'], 0, 20) as $team) {
            out('  ' . $team);
        }
        if (count($preview['new_teams']) > 20) {
            out(sprintf('  ... and %d more', count($preview['new_teams']) - 20));
        }
    }

    if ($preview['warnings'] !== []) {
        out();
        out('Warnings, grouped so a 72-row list cannot bury a single serious one');
        foreach ($preview['warnings'] as $kind => $count) {
            out(sprintf('  %s  %-24s %s', num($count, 6), $kind, Warnings::headline($kind)));
        }
        out();
        out('  php bin/import-roster.php --warnings=<kind> --apply=' . (int) $batch['id'] . '   to list the rows');
    }

    if ($preview['largest'] !== []) {
        out();
        out('The largest changes, row by row');
        foreach ($preview['largest'] as $row) {
            out(sprintf(
                '  row %-6d %-10s %-28s %d field(s)',
                $row['row_number'],
                $row['member_number'],
                mb_substr($row['name'], 0, 28),
                count($row['changes'])
            ));
            foreach ($row['changes'] as $field => $change) {
                out(sprintf(
                    '                                        %-22s %s -> %s',
                    $field,
                    short((string) $change[0]),
                    short((string) $change[1])
                ));
            }
        }
    }

    if ($preview['sample_creates'] !== []) {
        out();
        out('New members (first ' . count($preview['sample_creates']) . ')');
        foreach ($preview['sample_creates'] as $row) {
            out(sprintf('  %-10s %-28s %-22s %s', $row['member_number'], mb_substr($row['name'], 0, 28), mb_substr($row['team'], 0, 22), $row['title']));
        }
    }

    if ($preview['sample_dropped'] !== []) {
        out();
        out('Would be dropped (first ' . count($preview['sample_dropped']) . ')');
        foreach ($preview['sample_dropped'] as $row) {
            out(sprintf('  %-10s %s', $row['member_number'], $row['name']));
        }
        out('  Flagged only. Purging is a separate, confirmed, logged action.');
    }

    out();
    out('  staged until ' . $preview['expires_at'] . ' UTC ('
        . $app->toDisplay($preview['expires_at'])->format('D j M, H:i T') . ')');
}

function short(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '(blank)';
    }

    return mb_strlen($value) > 24 ? mb_substr($value, 0, 23) . '…' : $value;
}

$parsed  = parse_arguments($argv);
$options = $parsed['options'];
$files   = $parsed['files'];

if (isset($options['help']) || isset($options['h']) || ($options === [] && $files === [])) {
    out(USAGE);
    exit($options === [] && $files === [] ? 1 : 0);
}

try {
    $importer = Importer::fromApp($app);
    $limit    = isset($options['limit']) ? max(1, (int) $options['limit']) : 20;

    // Expired previews are discarded here as well as on the screen: a stale
    // diff was computed against a roster that has since changed.
    $importer->discardExpired();

    // ---------------------------------------------------------------- --list
    if (isset($options['list'])) {
        $staged = $importer->stagedBatches($limit);

        if ($staged === []) {
            out('Nothing is staged.');
        } else {
            out(sprintf('%-6s %-9s %-22s %8s %8s %8s %8s  %s', 'id', 'mode', 'file', 'read', 'create', 'update', 'dropped', 'staged at'));
            foreach ($staged as $batch) {
                out(sprintf(
                    '%-6d %-9s %-22s %8d %8d %8d %8d  %s UTC',
                    (int) $batch['id'],
                    (string) $batch['mode'],
                    mb_substr((string) $batch['filename'], 0, 22),
                    (int) $batch['rows_read'],
                    (int) $batch['rows_created'],
                    (int) $batch['rows_updated'],
                    (int) $batch['rows_dropped'],
                    (string) $batch['started_at']
                ));
            }
        }

        $applied = $importer->appliedBatches(5);
        if ($applied !== []) {
            out();
            out('Recently applied');
            foreach ($applied as $batch) {
                out(sprintf(
                    '%-6d %-9s %-22s applied %s UTC',
                    (int) $batch['id'],
                    (string) $batch['mode'],
                    mb_substr((string) $batch['filename'], 0, 22),
                    (string) $batch['applied_at']
                ));
            }
        }

        exit(0);
    }

    // ------------------------------------------------------------- --discard
    if (isset($options['discard'])) {
        $batchId = (int) $options['discard'];
        $importer->discard($batchId);
        out("Discarded batch {$batchId}.");
        exit(0);
    }

    // ------------------------------------------------------------ --warnings
    if (isset($options['warnings']) && $options['warnings'] !== '') {
        $batchId = (int) ($options['apply'] ?? $options['batch'] ?? 0);
        if ($batchId === 0) {
            fwrite(STDERR, "--warnings needs a batch: add --apply=<id>.\n");
            exit(1);
        }

        $kind = $options['warnings'];
        $rows = Warnings::rowsFor($app->db(), $batchId, $kind, $limit);

        out(Warnings::headline($kind));
        out();
        foreach ($rows as $row) {
            out(sprintf('  row %-6d %-10s %s', $row['row_number'], (string) $row['member_number'], $row['detail']));
        }
        if ($rows === []) {
            out('  (none)');
        }

        exit(0);
    }

    // --------------------------------------------------------------- --apply
    if (isset($options['apply']) && $options['apply'] !== '') {
        $batchId = (int) $options['apply'];

        // Shown again before it is committed. Applying something nobody has
        // looked at is the failure this whole two-step exists to prevent, and
        // reprinting it costs one query.
        print_preview($app, $importer->preview($batchId, $limit));

        out();
        out('Applying…');

        $started = microtime(true);
        $result  = $importer->apply($batchId);
        $elapsed = microtime(true) - $started;

        out();
        out('  created         ' . num($result['created']));
        out('  updated         ' . num($result['updated']));
        out('  unchanged       ' . num($result['unchanged']));
        out('  dropped         ' . num($result['dropped']));
        out('  accounts        ' . num($result['accounts']) . '  created, activated or deactivated');
        out('  progress reset  ' . num($result['progress_reset']) . '  metrics that moved N -> Y (logged to audit_log)');
        out();
        out(sprintf('Applied batch %d in %.2fs.', $batchId, $elapsed));

        exit(0);
    }

    // ------------------------------------------------------- stage from a file
    if ($files === []) {
        fwrite(STDERR, "Name a roster file, or use --apply=<id>.\n\n" . USAGE);
        exit(1);
    }
    if (count($files) > 1) {
        fwrite(STDERR, "One file at a time.\n");
        exit(1);
    }

    $path = $files[0];
    $mode = $options['mode'] ?? Importer::MODE_COMPLETE;

    if (!in_array($mode, Importer::MODES, true)) {
        fwrite(STDERR, "Unknown mode '{$mode}'. One of: " . implode(', ', Importer::MODES) . "\n");
        exit(1);
    }

    $teamId = null;
    if (isset($options['team']) && $options['team'] !== '') {
        $team = $options['team'];
        $read = $app->db()->prepare('SELECT id, name FROM team WHERE id = :id OR name = :name');
        $read->execute([':id' => ctype_digit($team) ? (int) $team : 0, ':name' => $team]);
        $found = $read->fetch();

        if (!is_array($found)) {
            fwrite(STDERR, "There is no team '{$team}'. Teams are created by a complete import.\n");
            exit(1);
        }

        $teamId = (int) $found['id'];
        out('Team: ' . (string) $found['name']);
    }

    out(Importer::modeDescription($mode));

    $started = microtime(true);
    $batchId = $importer->stage($path, basename($path), $mode, $teamId);
    $elapsed = microtime(true) - $started;

    print_preview($app, $importer->preview($batchId, $limit));

    out();
    out(sprintf('Parsed and staged in %.2fs. NOTHING has been written to the roster.', $elapsed));

    if (isset($options['dry-run'])) {
        $importer->discard($batchId);
        out('--dry-run: batch discarded. Run again without it to keep the diff and apply it.');
        exit(0);
    }

    out();
    out("  php bin/import-roster.php --apply={$batchId}     to commit this diff");
    out("  php bin/import-roster.php --discard={$batchId}   to throw it away");

    exit(0);
} catch (ImportException $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "\nThe import failed.\n\n" . $e->getMessage() . "\n");

    if ($app->debug()) {
        fwrite(STDERR, "\n" . $e->getTraceAsString() . "\n");
    }

    exit(1);
}
