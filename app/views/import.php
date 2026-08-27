<?php

declare(strict_types=1);

/**
 * Import Roster (spec 6) — the most dangerous screen in the application.
 *
 * It can rewrite 1,954 rows, so it is deliberately two screens in one file
 * with a diff between them: upload and read, then a SECOND explicit POST that
 * applies. Nothing on the first step writes to `member`, and the apply button
 * is the only control on the page that does.
 *
 * Three constraints from the host shape the markup (docs/hosting.md):
 *
 *   * max_input_vars is 1000 and PHP truncates past it IN SILENCE, so a
 *     preview of 1,954 rows is 1,954 table rows and never 1,954 form fields.
 *     The apply form carries three inputs: the token, the key and a batch id.
 *   * upload_max_filesize is 2M and the sample .xls is 1.2M, which is why the
 *     lede says CSV is smaller rather than waiting for somebody to hit it.
 *   * There is no JavaScript anywhere in this application. The warning lists
 *     expand with <details>, which needs none.
 *
 * @var Rerm\App                             $app
 * @var ?string                              $blocked  set when the schema is behind the code
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>|null            $preview
 * @var array<int, array<string, mixed>>     $staged
 * @var array<int, array<string, mixed>>     $applied
 * @var array<int, array<string, mixed>>     $failedBatches
 * @var array<int, array<string, mixed>>     $teams
 * @var string                               $key
 */

use Rerm\Csrf;
use Rerm\Import\Importer;
use Rerm\Import\Warnings;

$chip = static function (string $level, string $word): string {
    $class = match ($level) {
        'ok'    => 'chip-ok',
        'warn'  => 'chip-warn',
        default => 'chip-danger',
    };

    return '<span class="chip ' . $class . '">' . e($word) . '</span>';
};

/** A value as the diff shows it — blank is a word, not an empty cell. */
$shown = static function (mixed $value): string {
    $text = trim((string) $value);

    return $text === '' ? '(blank)' : $text;
};

$number = static fn (int $n): string => number_format($n);

$mode = (string) ($_POST['mode'] ?? Importer::MODE_COMPLETE);
?>
<h1>Import Roster</h1>
<p class="lede">
    Read the file Rodeo Houston sent, look at what it would change, and only
    then apply it. <code>.xls</code>, <code>.xlsx</code> and <code>.csv</code>
    are all read directly — whichever arrived. This server accepts uploads up
    to <?= e((string) ini_get('upload_max_filesize')) ?>; a full roster is
    about 1.2M as <code>.xls</code> and 0.4M as <code>.csv</code>.
</p>

<?php if (($blocked ?? null) !== null) { ?>
    <div class="card">
        <h2><?= $chip('danger', 'Schema is behind') ?> Nothing can be imported yet</h2>
        <?php foreach (explode("\n\n", $blocked) as $paragraph) { ?>
            <p><?= e($paragraph) ?></p>
        <?php } ?>
        <form method="get" action="<?= e($app->url('setup')) ?>">
            <input type="hidden" name="key" value="<?= e($key) ?>">
            <button type="submit">Go to Setup and apply them</button>
        </form>
    </div>
<?php return; } ?>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <?= $chip($level, $level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<?php if ($preview === null) { ?>
    <div class="card">
        <h2>1 &middot; Choose a file and a mode</h2>
        <?php
        // The key is in the action as well as in the body, and only on THIS
        // form. A roster over post_max_size has its whole request body
        // discarded by PHP before any of our code runs — the key with it — and
        // a route guarded on the body alone would answer 404 to the one person
        // who is allowed to be here. It costs nothing extra: reaching this
        // page at all means the key has already been in a URL.
        ?>
        <form method="post" action="<?= e($app->url('import') . '?key=' . rawurlencode($key)) ?>" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <input type="hidden" name="key" value="<?= e($key) ?>">
            <input type="hidden" name="action" value="stage">

            <p>
                <label for="roster">The roster file</label><br>
                <input type="file" id="roster" name="roster" accept=".xls,.xlsx,.csv" required>
            </p>

            <p>
                <label>What this import is</label>
            </p>
            <?php foreach (Importer::MODES as $option) { ?>
                <label class="choice">
                    <input type="radio" name="mode" value="<?= e($option) ?>"
                        <?= $option === $mode ? 'checked' : '' ?>>
                    <span>
                        <span class="what"><?= e(ucfirst($option)) ?></span>
                        <span class="why"><?= e(Importer::modeDescription($option)) ?></span>
                    </span>
                </label>
            <?php } ?>

            <p>
                <label for="team_id">Team &mdash; for a team import only</label><br>
                <select id="team_id" name="team_id">
                    <option value="">(not a team import)</option>
                    <?php foreach ($teams as $team) { ?>
                        <option value="<?= e((string) $team['id']) ?>">
                            <?= e((string) $team['name']) ?> &middot; <?= e($number((int) $team['members'])) ?> members
                        </option>
                    <?php } ?>
                </select>
            </p>
            <p class="hint">
                A team import verifies every row's <code>Subcommittee 1</code> against the team
                you choose. A row belonging elsewhere is reported and skipped, never quietly
                moved into this team.
            </p>

            <button type="submit">Read the file</button>
        </form>
    </div>

<?php } else {
    $batch  = $preview['batch'];
    $counts = $preview['counts'];
    $done   = $preview['applied'] === true;
    $failed = $preview['failed'] === true;
?>
    <?php if ($failed) {
        $partial = $preview['failure']['applied'] ?? [];
        $written = (int) ($partial['created'] ?? 0) + (int) ($partial['updated'] ?? 0);
    ?>
        <div class="card">
            <h2><?= $chip('danger', 'Failed part way') ?> The roster is partly updated</h2>
            <p>
                This import stopped at <?= e((string) $preview['failure']['at']) ?> UTC
                (<?= e($app->toDisplay((string) $preview['failure']['at'])->format('D j M, H:i T')) ?>)
                after writing <strong><?= e($number($written)) ?></strong> member row(s).
                Nothing can undo that: the apply commits in batches so that a 1,954-row import fits
                inside this server&rsquo;s 30-second limit, and the batches before the failure are
                committed.
            </p>
            <p>
                <strong>Upload the file again.</strong> A fresh read diffs against the roster as it
                now stands &mdash; including the rows this run managed to write &mdash; so the next
                preview shows exactly what is left, and shows it before writing anything. This batch
                cannot be applied again, because its diff was measured against the roster as it
                stood before its own partial work.
            </p>
            <dl class="facts">
                <dt>Created</dt>
                <dd><?= e($number((int) ($partial['created'] ?? 0))) ?></dd>
                <dt>Updated</dt>
                <dd><?= e($number((int) ($partial['updated'] ?? 0))) ?></dd>
                <dt>Unchanged</dt>
                <dd><?= e($number((int) ($partial['unchanged'] ?? 0))) ?></dd>
                <dt>What went wrong</dt>
                <dd class="mono"><?= e((string) $preview['failure']['reason']) ?></dd>
            </dl>
            <form method="get" action="<?= e($app->url('import')) ?>">
                <input type="hidden" name="key" value="<?= e($key) ?>">
                <button type="submit">Upload the file again</button>
            </form>
        </div>
    <?php } ?>

    <div class="card">
        <h2><?php
            echo $failed ? 'What this file was going to do' : ($done ? 'Applied' : '2 &middot; What this file would do');
        ?></h2>
        <dl class="facts">
            <dt>File</dt>
            <dd class="mono"><?= e((string) $batch['filename']) ?></dd>
            <dt>Mode</dt>
            <dd><?= e((string) $batch['mode']) ?><?= $batch['team_name'] === null ? '' : ' &middot; ' . e((string) $batch['team_name']) ?></dd>
            <dt>Show year</dt>
            <dd><?= e((string) $batch['show_year_label']) ?></dd>
            <dt>Batch</dt>
            <dd class="mono"><?= e((string) $batch['id']) ?></dd>
            <dt>Fingerprint</dt>
            <dd class="mono"><?= e(substr((string) $batch['sha256'], 0, 16)) ?>&hellip;</dd>
        </dl>

        <table>
            <caption>Rows</caption>
            <thead>
                <tr><th scope="col">What</th><th scope="col" class="num">Members</th><th scope="col">Meaning</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td data-label="What">Read</td>
                    <td data-label="Members" class="num"><?= e($number($counts['read'])) ?></td>
                    <td data-label="Meaning">Data rows in the file, excluding the header</td>
                </tr>
                <tr>
                    <td data-label="What"><?= $done ? 'Created' : 'Would create' ?></td>
                    <td data-label="Members" class="num"><?= e($number($counts['create'])) ?></td>
                    <td data-label="Meaning">Not in the roster yet</td>
                </tr>
                <tr>
                    <td data-label="What"><?= $done ? 'Updated' : 'Would update' ?></td>
                    <td data-label="Members" class="num"><?= e($number($counts['update'])) ?></td>
                    <td data-label="Meaning">At least one thing Rodeo Houston owns differs</td>
                </tr>
                <tr>
                    <td data-label="What">Unchanged</td>
                    <td data-label="Members" class="num"><?= e($number($counts['unchanged'])) ?></td>
                    <td data-label="Meaning">Nothing differs</td>
                </tr>
                <tr>
                    <td data-label="What"><?= $done ? 'Flagged absent' : 'Would flag absent' ?></td>
                    <td data-label="Members" class="num"><?= e($number($counts['absent'])) ?></td>
                    <td data-label="Meaning">Not in this file. Flagged for review &mdash; never deleted</td>
                </tr>
                <tr>
                    <td data-label="What">Skipped</td>
                    <td data-label="Members" class="num"><?= e($number($counts['skipped'])) ?></td>
                    <td data-label="Meaning">Not applied; the warnings below say why</td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if ($preview['metric_flips'] !== []) { ?>
        <div class="card">
            <h2>Metric changes</h2>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Metric</th>
                        <th scope="col">From</th>
                        <th scope="col">To</th>
                        <th scope="col" class="num">Members</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['metric_flips'] as $flip) { ?>
                    <tr>
                        <td data-label="Metric"><?= e(str_replace('_', ' ', (string) $flip['metric'])) ?></td>
                        <td data-label="From"><?= e((string) $flip['from']) ?></td>
                        <td data-label="To"><?= e((string) $flip['to']) ?></td>
                        <td data-label="Members" class="num"><?= e($number((int) $flip['members'])) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <p class="hint">
                A metric moving <code>N</code> to <code>Y</code> also clears that metric's tracked
                progress back to <em>Not started</em> &mdash; the thing being chased has happened.
                The previous status, who set it and their note go to the audit log with this batch.
                Contact history is never touched by an import.
            </p>
        </div>
    <?php } ?>

    <?php if ($preview['warnings'] !== []) { ?>
        <div class="card">
            <h2>Warnings</h2>
            <p class="hint">
                None of these stops the import. They are grouped by kind so a 72-row
                &ldquo;no division&rdquo; list cannot bury a single duplicate member number.
            </p>
            <?php foreach ($preview['warnings'] as $kind => $count) { ?>
                <details>
                    <summary>
                        <strong><?= e($number((int) $count)) ?></strong>
                        &middot; <?= e(Warnings::headline((string) $kind)) ?>
                        <span class="mono">(<?= e((string) $kind) ?>)</span>
                    </summary>
                    <ul class="rows">
                        <?php foreach (Warnings::rowsFor($app->db(), (int) $batch['id'], (string) $kind, 25) as $row) { ?>
                            <li>
                                row <?= e((string) $row['row_number']) ?>
                                <?php if ($row['member_number'] !== null && $row['member_number'] !== '') { ?>
                                    &middot; <span class="mono"><?= e($row['member_number']) ?></span>
                                <?php } ?>
                                &mdash; <?= e($row['detail']) ?>
                            </li>
                        <?php } ?>
                        <?php if ((int) $count > 25) { ?>
                            <li>&hellip; and <?= e($number((int) $count - 25)) ?> more</li>
                        <?php } ?>
                    </ul>
                </details>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if ($preview['largest'] !== []) { ?>
        <div class="card">
            <h2>The largest changes</h2>
            <p class="hint">
                The <?= e((string) count($preview['largest'])) ?> rows whose diff touches the most
                fields. A file with a column out of place shows up here first.
            </p>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Row</th>
                        <th scope="col">Member</th>
                        <th scope="col">Name</th>
                        <th scope="col">Changes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['largest'] as $row) { ?>
                    <tr>
                        <td data-label="Row" class="num"><?= e((string) $row['row_number']) ?></td>
                        <td data-label="Member" class="mono"><?= e($row['member_number']) ?></td>
                        <td data-label="Name"><?= e($row['name']) ?></td>
                        <td data-label="Changes">
                            <?php foreach ($row['changes'] as $field => $change) { ?>
                                <div>
                                    <span class="mono"><?= e(str_replace('metric:', '', (string) $field)) ?></span>
                                    <?= e($shown($change[0])) ?> &rarr; <strong><?= e($shown($change[1])) ?></strong>
                                </div>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <?php if ($preview['sample_creates'] !== []) { ?>
        <div class="card">
            <h2>New members</h2>
            <p class="hint">
                First <?= e((string) count($preview['sample_creates'])) ?> of
                <?= e($number($counts['create'])) ?>. An officer title creates a login with the
                password <code>1234</code> and a forced reset; every other title creates no
                account at all.
            </p>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Member</th>
                        <th scope="col">Name</th>
                        <th scope="col">Team</th>
                        <th scope="col">Title</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['sample_creates'] as $row) { ?>
                    <tr>
                        <td data-label="Member" class="mono"><?= e($row['member_number']) ?></td>
                        <td data-label="Name"><?= e($row['name']) ?></td>
                        <td data-label="Team"><?= e($shown($row['team'])) ?></td>
                        <td data-label="Title"><?= e($shown($row['title'])) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <?php if ($preview['sample_absent'] !== []) { ?>
        <div class="card">
            <h2><?= $done ? 'Flagged absent' : 'Would be flagged absent' ?></h2>
            <p class="hint">
                First <?= e((string) count($preview['sample_absent'])) ?> of
                <?= e($number($counts['absent'])) ?>. Flagging hides nobody's history: purging is a
                separate, confirmed, logged action, and a member who reappears in a later import is
                un-flagged automatically.
            </p>
            <table>
                <thead>
                    <tr><th scope="col">Member</th><th scope="col">Name</th></tr>
                </thead>
                <tbody>
                <?php foreach ($preview['sample_absent'] as $row) { ?>
                    <tr>
                        <td data-label="Member" class="mono"><?= e($row['member_number']) ?></td>
                        <td data-label="Name"><?= e($row['name']) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <?php if ($preview['new_teams'] !== []) { ?>
        <div class="card">
            <h2>Teams that would be created</h2>
            <p class="hint"><?= e($number(count($preview['new_teams']))) ?> team(s) named in this
                file that the roster does not have yet.</p>
            <p class="mono"><?= e(implode(' · ', array_slice($preview['new_teams'], 0, 40))) ?><?php
                if (count($preview['new_teams']) > 40) {
                    echo ' · … and ' . e($number(count($preview['new_teams']) - 40)) . ' more';
                }
            ?></p>
        </div>
    <?php } ?>

    <?php if (!$done && !$failed) { ?>
        <div class="card">
            <h2>3 &middot; Apply, or throw it away</h2>
            <p>
                <?= $chip('warn', 'Nothing written yet') ?>
                Applying writes every row above to the roster. It cannot be undone by pressing
                anything on this page &mdash; the way back is to import a correct file.
            </p>
            <p class="hint">
                This preview is kept until <?= e($preview['expires_at']) ?> UTC
                (<?= e($app->toDisplay($preview['expires_at'])->format('D j M, H:i T')) ?>), then
                discarded. A stale diff was computed against a roster that has since changed.
            </p>
            <form method="post" action="<?= e($app->url('import')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="key" value="<?= e($key) ?>">
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
                <button type="submit">Apply <?= e($number($counts['create'] + $counts['update'])) ?> change(s) to the roster</button>
            </form>
            <form method="post" action="<?= e($app->url('import')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="key" value="<?= e($key) ?>">
                <input type="hidden" name="action" value="discard">
                <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
                <button type="submit" class="quiet">Discard this preview</button>
            </form>
        </div>
    <?php } elseif ($done) { ?>
        <div class="card">
            <p>
                <?= $chip('ok', 'Applied') ?>
                <?= e((string) $batch['applied_at']) ?> UTC
                (<?= e($app->toDisplay((string) $batch['applied_at'])->format('D j M, H:i T')) ?>).
                This batch's counts and warnings are kept for good &mdash; they are what answers
                &ldquo;why did this member's dues flip back to N&rdquo; a year from now.
            </p>
            <form method="get" action="<?= e($app->url('import')) ?>">
                <input type="hidden" name="key" value="<?= e($key) ?>">
                <button type="submit" class="quiet">Import another file</button>
            </form>
        </div>
    <?php } ?>
<?php } ?>

<?php if ($staged !== []) { ?>
    <div class="card">
        <h2>Staged, not applied</h2>
        <table>
            <thead>
                <tr>
                    <th scope="col">Batch</th><th scope="col">File</th><th scope="col">Mode</th>
                    <th scope="col" class="num">Read</th><th scope="col" class="num">Create</th>
                    <th scope="col" class="num">Update</th><th scope="col">Staged</th><th scope="col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($staged as $row) { ?>
                <tr>
                    <td data-label="Batch" class="mono"><?= e((string) $row['id']) ?></td>
                    <td data-label="File"><?= e((string) $row['filename']) ?></td>
                    <td data-label="Mode"><?= e((string) $row['mode']) ?></td>
                    <td data-label="Read" class="num"><?= e($number((int) $row['rows_read'])) ?></td>
                    <td data-label="Create" class="num"><?= e($number((int) $row['rows_created'])) ?></td>
                    <td data-label="Update" class="num"><?= e($number((int) $row['rows_updated'])) ?></td>
                    <td data-label="Staged"><?= e($app->toDisplay((string) $row['started_at'])->format('j M H:i')) ?></td>
                    <td data-label="">
                        <a href="<?= e($app->url('import') . '?key=' . rawurlencode($key) . '&batch=' . (int) $row['id']) ?>">Review</a>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
<?php } ?>

<?php if ($failedBatches !== []) { ?>
    <div class="card">
        <h2><?= $chip('danger', 'Failed') ?> Imports that stopped part way</h2>
        <p class="hint">
            Each of these wrote some rows to the roster and then stopped, so each is kept as a
            record rather than discarded &mdash; it is the only thing that explains a roster that
            changed when no import says it did. None of them can be applied; re-uploading the file
            is what finishes the job.
        </p>
        <table>
            <thead>
                <tr>
                    <th scope="col">Batch</th><th scope="col">File</th>
                    <th scope="col" class="num">Rows written</th><th scope="col">Failed</th>
                    <th scope="col">Reason</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($failedBatches as $row) {
                $summary = json_decode((string) ($row['summary_json'] ?? ''), true);
                $partial = is_array($summary) ? ($summary['applied_before_failure'] ?? []) : [];
                $written = (int) ($partial['created'] ?? 0) + (int) ($partial['updated'] ?? 0);
            ?>
                <tr>
                    <td data-label="Batch" class="mono"><?= e((string) $row['id']) ?></td>
                    <td data-label="File"><?= e((string) $row['filename']) ?></td>
                    <td data-label="Rows written" class="num"><?= e($number($written)) ?></td>
                    <td data-label="Failed"><?= e($app->toDisplay((string) $row['failed_at'])->format('j M H:i')) ?></td>
                    <td data-label="Reason" class="mono"><?= e(mb_substr((string) $row['failure_reason'], 0, 120)) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
<?php } ?>

<?php if ($applied !== []) { ?>
    <div class="card">
        <h2>Recently applied</h2>
        <table>
            <thead>
                <tr>
                    <th scope="col">Batch</th><th scope="col">File</th><th scope="col">Mode</th>
                    <th scope="col" class="num">Created</th><th scope="col" class="num">Updated</th>
                    <th scope="col" class="num">Absent</th><th scope="col" class="num">Warnings</th>
                    <th scope="col">Applied</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($applied as $row) { ?>
                <tr>
                    <td data-label="Batch" class="mono"><?= e((string) $row['id']) ?></td>
                    <td data-label="File"><?= e((string) $row['filename']) ?></td>
                    <td data-label="Mode"><?= e((string) $row['mode']) ?></td>
                    <td data-label="Created" class="num"><?= e($number((int) $row['rows_created'])) ?></td>
                    <td data-label="Updated" class="num"><?= e($number((int) $row['rows_updated'])) ?></td>
                    <td data-label="Absent" class="num"><?= e($number((int) $row['rows_absent'])) ?></td>
                    <td data-label="Warnings" class="num"><?= e($number((int) $row['warnings_count'])) ?></td>
                    <td data-label="Applied"><?= e($app->toDisplay((string) $row['applied_at'])->format('j M H:i')) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
<?php } ?>

<footer>
    An import refreshes what Rodeo Houston knows and never overwrites what we know.
    Allowed User grants, scope overrides, passwords, contact history, officer assignments,
    tracked progress and a team's area all survive every import &mdash; with one deliberate
    exception, described under Metric changes above.
</footer>
