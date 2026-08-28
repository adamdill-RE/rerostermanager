<?php

declare(strict_types=1);

/**
 * Import History (Phase 10) — what every import actually did, kept.
 *
 * The screen exists because Rodeo Houston's export has no audit trail in it.
 * It is a snapshot: the committee as of the moment somebody pressed Export,
 * with nothing about how it got that way. So "when did Johnson move team",
 * "which file dropped these eleven people" and "when did this Captain stop
 * being a Captain" used to be answerable only by keeping the old spreadsheets
 * and diffing them by hand.
 *
 * Three shapes, one file, decided in Rerm\Import\ImportHistory:
 *
 *   * the list of imports, each with the summary it stored when it ran;
 *   * one import, grouped by what it changed, drillable to the people;
 *   * one member, and everything every import has ever done to them.
 *
 * Read-only. There is no form here that writes anything, and no POST at all,
 * so there is no CSRF check to forget: the only controls are a search box and
 * links. A wrong import is fixed by importing the right file (spec 6.3), never
 * by editing the record of what the wrong one did.
 *
 * @var Rerm\App                             $app
 * @var Rerm\Auth\User                       $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>                 $history everything ImportHistory::page() decided
 */

use Rerm\Import\ImportHistory;

$number = static fn (int $n): string => number_format($n);

$chip = static function (string $level, string $word): string {
    $class = match ($level) {
        'ok'    => 'chip-ok',
        'warn'  => 'chip-warn',
        'info'  => 'chip-info',
        'muted' => 'chip-muted',
        default => 'chip-danger',
    };

    return '<span class="chip ' . $class . '">' . e($word) . '</span>';
};

/** A stored value as the history shows it: absence and blank are different. */
$value = static function (?string $text): string {
    if ($text === null) {
        return '<span class="chip chip-muted">not set</span>';
    }
    if (trim($text) === '') {
        return '<span class="chip chip-muted">blank</span>';
    }

    return '<span class="mono">' . e($text) . '</span>';
};

/** A link into this screen, with the parameters it understands and no others. */
$href = static function (array $params) use ($app): string {
    $params = array_filter(
        $params,
        static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== 0
    );
    $query = http_build_query($params);

    return $app->url('import-history') . ($query === '' ? '' : '?' . $query);
};

/** When a UTC timestamp happened, in Houston's words. */
$when = static fn (string $utc): string => $app->toDisplay($utc)->format('j M Y, H:i T');

$view = (string) $history['view'];
?>
<h1>Import History</h1>
<p class="lede">
    What every import actually did, kept for good. The roster Rodeo Houston
    sends is a snapshot with no history in it &mdash; it says who is on the
    committee today and nothing about who was on it last month &mdash; so this
    is where &ldquo;when did that change, and which file changed it&rdquo; is
    answered without keeping and diffing spreadsheets.
</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <?= $chip($level, $level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<form class="quick" method="get" action="<?= e($app->url('import-history')) ?>">
    <label for="member">One member&rsquo;s history</label>
    <input type="text" id="member" name="member" value="<?= e((string) $history['q']) ?>"
           placeholder="Member number, or part of a name"
           autocomplete="off" inputmode="search">
    <button type="submit" class="quiet">Look them up</button>
</form>

<?php if (($history['missing'] ?? 0) > 0) { ?>
    <div class="card">
        <?= $chip('warn', 'Gone') ?>
        <span>
            There is no import <?= e((string) $history['missing']) ?>. A file that was
            uploaded and never applied is discarded after a day and changed nothing,
            so it is not here; every import below really happened.
        </span>
    </div>
<?php } ?>

<?php // ---------------------------------------------------------------- ?>
<?php if ($view === 'search') { ?>

    <?php if ($history['matches'] === []) { ?>
        <div class="card">
            <h2>Nobody matches &ldquo;<?= e((string) $history['q']) ?>&rdquo;</h2>
            <p>
                Try the member number from Rodeo Houston&rsquo;s file &mdash; it is the
                only value that is unique across the whole roster. Names are not:
                1,951 of 1,954 are distinct, so three of them are shared.
            </p>
        </div>
    <?php } else { ?>
        <div class="card">
            <h2><?= e($number(count($history['matches']))) ?> members match</h2>
            <p class="hint">
                Names are not unique in this roster, so a name that matches more than
                one person is a question rather than an answer. Pick the one you mean.
            </p>
        </div>
        <table>
            <caption>Matches for &ldquo;<?= e((string) $history['q']) ?>&rdquo;</caption>
            <thead>
                <tr>
                    <th scope="col">Member</th><th scope="col">Number</th>
                    <th scope="col">Team</th><th scope="col">Division</th><th scope="col">State</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history['matches'] as $match) { ?>
                <tr>
                    <td data-label="Member">
                        <a href="<?= e($href(['member' => $match['member_number']])) ?>"><?= e($match['name']) ?></a>
                    </td>
                    <td data-label="Number" class="mono"><?= e($match['member_number']) ?></td>
                    <td data-label="Team"><?= e($match['team_name']) ?></td>
                    <td data-label="Division"><?= e($match['division_name']) ?></td>
                    <td data-label="State">
                        <?php if ($match['purged']) { ?>
                            <?= $chip('danger', 'Purged') ?>
                        <?php } elseif ($match['dropped']) { ?>
                            <?= $chip('warn', 'Dropped') ?>
                        <?php } else { ?>
                            <?= $chip('ok', 'On the roster') ?>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>

<?php // ---------------------------------------------------------------- ?>
<?php } elseif ($view === 'member') {
    $member = $history['member'];
?>

    <div class="card">
        <h2><?= e((string) $member['name']) ?></h2>
        <dl class="facts">
            <dt>Member number</dt>
            <dd class="mono"><?= e((string) $member['member_number']) ?></dd>
            <dt>Team</dt>
            <dd><?= e((string) $member['team_name']) ?></dd>
            <dt>Division</dt>
            <dd><?= e((string) $member['division_name']) ?></dd>
            <dt>On the roster</dt>
            <dd>
                <?php if ($member['purged']) { ?>
                    <?= $chip('danger', 'Purged') ?> an Admin removed them deliberately
                <?php } elseif ($member['dropped']) { ?>
                    <?= $chip('warn', 'Dropped') ?> the last complete import did not list them
                <?php } else { ?>
                    <?= $chip('ok', 'Yes') ?>
                <?php } ?>
            </dd>
        </dl>
        <p class="hint">
            Every import that has changed anything about this member, newest
            first. Nothing an officer did is here &mdash; contacts, progress and
            assignments are ours and no import writes them (spec 6.6). This is
            only what arrived in a file.
        </p>
    </div>

<?php // ---------------------------------------------------------------- ?>
<?php } elseif ($view === 'batch') {
    $batch = $history['batch'];
?>

    <p><a href="<?= e($href([])) ?>">&larr; Every import</a></p>

    <div class="card">
        <h2>Import <?= e((string) $batch['id']) ?> &middot; <?= e((string) $batch['filename']) ?></h2>
        <dl class="facts">
            <dt>Applied</dt>
            <dd>
                <?php if ($batch['failed_at'] !== null) { ?>
                    <?= $chip('danger', 'Stopped part way') ?>
                    <?= e($when((string) $batch['failed_at'])) ?>
                <?php } else { ?>
                    <?= $chip('ok', 'Applied') ?>
                    <?= e($when((string) $batch['applied_at'])) ?>
                <?php } ?>
            </dd>
            <dt>By</dt>
            <dd><?= $batch['actor'] === null ? 'not recorded' : e((string) $batch['actor']) ?></dd>
            <dt>Mode</dt>
            <dd>
                <?= e((string) $batch['mode']) ?><?php
                if ($batch['team_name'] !== '') { ?> &middot; <?= e((string) $batch['team_name']) ?><?php } ?>
            </dd>
            <dt>Show year</dt>
            <dd><?= e((string) $batch['show_year']) ?></dd>
            <dt>Rows in the file</dt>
            <dd><?= e($number((int) $batch['rows_read'])) ?></dd>
            <dt>Created</dt>
            <dd><?= e($number((int) $batch['rows_created'])) ?></dd>
            <dt>Updated</dt>
            <dd><?= e($number((int) $batch['rows_updated'])) ?></dd>
            <dt>Unchanged</dt>
            <dd><?= e($number((int) $batch['rows_unchanged'])) ?></dd>
            <dt>Dropped</dt>
            <dd><?= e($number((int) $batch['rows_dropped'])) ?></dd>
            <dt>Warnings</dt>
            <dd><?= e($number((int) $batch['warnings'])) ?></dd>
        </dl>

        <?php if ($batch['failed_at'] !== null) { ?>
            <p class="hint">
                This import wrote
                <?= e($number((int) ($batch['partial']['created'] ?? 0)
                    + (int) ($batch['partial']['updated'] ?? 0))) ?>
                rows and then stopped. The counts above are what the file
                <em>said</em>; the changes below are what actually landed.
            </p>
            <p class="mono hint"><?= e((string) $batch['failure_reason']) ?></p>
        <?php } ?>
    </div>

    <?php if ($batch['metric_flips'] !== [] || $batch['new_teams'] !== [] || $batch['warning_counts'] !== []) { ?>
        <div class="card">
            <h2>What this file said, as it was read</h2>
            <p class="hint">
                The summary this import wrote when it was parsed, kept ever since.
                It is what the Admin read on the preview before pressing Apply.
            </p>

            <?php if ($batch['metric_flips'] !== []) { ?>
                <h3>Requirements that moved</h3>
                <ul class="rows">
                    <?php foreach ($batch['metric_flips'] as $flip) { ?>
                        <li>
                            <?= e(ImportHistory::fieldLabel('metric:' . (string) ($flip['metric'] ?? ''))) ?>:
                            <span class="mono"><?= e((string) ($flip['from'] ?? '')) ?></span> &rarr;
                            <span class="mono"><?= e((string) ($flip['to'] ?? '')) ?></span>
                            &middot; <?= e($number((int) ($flip['members'] ?? 0))) ?> members
                        </li>
                    <?php } ?>
                </ul>
            <?php } ?>

            <?php if ($batch['new_teams'] !== []) { ?>
                <h3>Teams this file introduced</h3>
                <ul class="rows">
                    <?php foreach ($batch['new_teams'] as $team) { ?>
                        <li><?= e((string) $team) ?></li>
                    <?php } ?>
                </ul>
            <?php } ?>

            <?php if ($batch['warning_counts'] !== []) { ?>
                <h3>Warnings</h3>
                <ul class="rows">
                    <?php foreach ($batch['warning_counts'] as $kind => $count) { ?>
                        <li><span class="mono"><?= e((string) $kind) ?></span> &middot;
                            <?= e($number((int) $count)) ?></li>
                    <?php } ?>
                </ul>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="card">
        <h2>What it changed</h2>
        <?php if ($history['groups'] === []) { ?>
            <p>
                Nothing was recorded field by field for this import. Imports before
                this record existed kept only the counts above &mdash; those are
                still exact, and everything from this one onwards is listed here in
                full.
            </p>
        <?php } else { ?>
            <nav class="toggle" aria-label="What changed">
                <a href="<?= e($href(['batch' => $batch['id']])) ?>"
                    <?= $history['kind'] === '' ? 'class="current" aria-current="page"' : '' ?>>
                    Everything <span class="n"><?= e($number((int) $history['total'])) ?></span>
                </a>
                <?php foreach ($history['groups'] as $group) { ?>
                    <a href="<?= e($href([
                        'batch' => $batch['id'],
                        'kind'  => $group['kind'],
                        'field' => $group['field'],
                    ])) ?>" <?= $history['kind'] === $group['kind'] && $history['field'] === $group['field']
                        ? 'class="current" aria-current="page"' : '' ?>>
                        <?= e((string) $group['label']) ?>
                        <span class="n"><?= e($number((int) $group['members'])) ?></span>
                    </a>
                <?php } ?>
            </nav>
        <?php } ?>
    </div>

<?php // ---------------------------------------------------------------- ?>
<?php } else { ?>

    <?php if ($history['batches'] === []) { ?>
        <div class="card">
            <h2>No import has been applied yet</h2>
            <p>
                Once a roster is applied on
                <a href="<?= e($app->url('import')) ?>">Import Roster</a>, what it
                changed appears here and stays here.
            </p>
        </div>
    <?php } else { ?>
        <table>
            <caption>
                Every import that touched the roster, newest first &mdash; applied
                and stopped-part-way both. A file that was uploaded and never
                applied changed nothing and is not here.
            </caption>
            <thead>
                <tr>
                    <th scope="col">Import</th>
                    <th scope="col">File</th>
                    <th scope="col">When</th>
                    <th scope="col" class="num">Created</th>
                    <th scope="col" class="num">Updated</th>
                    <th scope="col" class="num">Dropped</th>
                    <th scope="col" class="num">Warnings</th>
                    <th scope="col">By</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history['batches'] as $batch) { ?>
                <tr>
                    <td data-label="Import" class="mono">
                        <a href="<?= e($href(['batch' => $batch['id']])) ?>"><?= e((string) $batch['id']) ?></a>
                        <?php if ($batch['failed_at'] !== null) { ?>
                            <?= $chip('danger', 'Stopped') ?>
                        <?php } ?>
                    </td>
                    <td data-label="File"><?= e((string) $batch['filename']) ?></td>
                    <td data-label="When">
                        <?= e($when((string) ($batch['applied_at'] ?? $batch['failed_at'] ?? $batch['started_at']))) ?>
                    </td>
                    <td data-label="Created" class="num"><?= e($number((int) $batch['rows_created'])) ?></td>
                    <td data-label="Updated" class="num"><?= e($number((int) $batch['rows_updated'])) ?></td>
                    <td data-label="Dropped" class="num"><?= e($number((int) $batch['rows_dropped'])) ?></td>
                    <td data-label="Warnings" class="num"><?= e($number((int) $batch['warnings'])) ?></td>
                    <td data-label="By"><?= $batch['actor'] === null ? '&mdash;' : e((string) $batch['actor']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>

<?php } ?>

<?php // The change rows, shared by the batch view and the member view. ?>
<?php if ($view === 'batch' || $view === 'member') { ?>
    <?php if ($history['rows'] === []) { ?>
        <div class="card">
            <p>
                <?php if ($view === 'member') { ?>
                    No import has changed anything about this member. They were on
                    the roster before this record began, and every file since has
                    said the same things about them.
                <?php } else { ?>
                    Nothing to list for this group.
                <?php } ?>
            </p>
        </div>
    <?php } else { ?>
        <p class="lede">
            Showing <?= e($number((int) $history['from_row'])) ?>&ndash;<?= e($number((int) $history['to_row'])) ?>
            of <?= e($number((int) $history['total'])) ?> changes.
        </p>
        <table>
            <thead>
                <tr>
                    <?php if ($view === 'member') { ?>
                        <th scope="col">When</th>
                        <th scope="col">Import</th>
                    <?php } else { ?>
                        <th scope="col">Member</th>
                        <th scope="col">Number</th>
                    <?php } ?>
                    <th scope="col">What</th>
                    <th scope="col">From</th>
                    <th scope="col">To</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history['rows'] as $row) { ?>
                <tr>
                    <?php if ($view === 'member') { ?>
                        <td data-label="When"><?= e($when((string) $row['occurred_at'])) ?></td>
                        <td data-label="Import" class="mono">
                            <a href="<?= e($href(['batch' => $row['batch_id']])) ?>"><?= e((string) $row['batch_id']) ?></a>
                            <span class="sub"><?= e((string) $row['filename']) ?></span>
                        </td>
                    <?php } else { ?>
                        <td data-label="Member">
                            <a href="<?= e($href(['member' => $row['member_number']])) ?>"><?= e((string) $row['name']) ?></a>
                        </td>
                        <td data-label="Number" class="mono"><?= e((string) $row['member_number']) ?></td>
                    <?php } ?>
                    <td data-label="What">
                        <?php if ($row['kind'] === 'updated') { ?>
                            <?= e((string) $row['field_label']) ?>
                        <?php } else { ?>
                            <?= $chip(
                                $row['kind'] === 'dropped' ? 'danger' : ($row['kind'] === 'returned' ? 'ok' : 'info'),
                                (string) $row['kind_label']
                            ) ?>
                        <?php } ?>
                    </td>
                    <td data-label="From">
                        <?= $row['kind'] === 'updated' ? $value($row['before']) : '' ?>
                    </td>
                    <td data-label="To">
                        <?= $row['kind'] === 'updated' ? $value($row['after']) : '' ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

        <?php if ((int) $history['pages'] > 1) { ?>
            <nav class="toggle" aria-label="Pages">
                <?php if ((int) $history['page'] > 1) { ?>
                    <a href="<?= e($href([
                        'batch'  => $view === 'batch' ? (int) $history['batch']['id'] : 0,
                        'member' => $view === 'member' ? (string) $history['member']['member_number'] : '',
                        'kind'   => (string) ($history['kind'] ?? ''),
                        'field'  => (string) ($history['field'] ?? ''),
                        'page'   => (int) $history['page'] - 1,
                    ])) ?>">&larr; Newer</a>
                <?php } ?>
                <?php if ((int) $history['page'] < (int) $history['pages']) { ?>
                    <a href="<?= e($href([
                        'batch'  => $view === 'batch' ? (int) $history['batch']['id'] : 0,
                        'member' => $view === 'member' ? (string) $history['member']['member_number'] : '',
                        'kind'   => (string) ($history['kind'] ?? ''),
                        'field'  => (string) ($history['field'] ?? ''),
                        'page'   => (int) $history['page'] + 1,
                    ])) ?>">Older &rarr;</a>
                <?php } ?>
            </nav>
        <?php } ?>
    <?php } ?>
<?php } ?>

<footer>
    Nothing on this screen can be edited, and nothing here undoes an import. A
    file that got something wrong is corrected by importing the right one,
    which diffs against the roster as it now stands. This is the record of what
    happened, and it is kept for good.
</footer>
