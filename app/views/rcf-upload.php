<?php

declare(strict_types=1);

/**
 * Upload RCFs (Phase 13, spec-v2 §14) — Roster Change Forms that arrived by
 * email, read into the same record as the ones made here.
 *
 * Three states of one screen, in the order they happen:
 *
 *   * the upload card and the recent uploads, when nothing is being
 *     previewed;
 *   * a staged batch: one card per file, saying exactly what was read out
 *     of it and every doubt the reader had, with a tick box per readable
 *     file and ONE button that keeps the ticked ones — nothing has been
 *     written when this is on the screen, and the words say so;
 *   * a kept batch: the same cards, each saying what it became, with the
 *     form linked.
 *
 * max_input_vars is 1000 with silent truncation (docs/hosting.md), so the
 * keep form carries one input per FILE (a tick box), never per line: the
 * lines are a table, read-only. No JavaScript anywhere in this application.
 * Every rendered value goes through e().
 *
 * @var Rerm\App                             $app
 * @var Rerm\Auth\User                       $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>|null            $preview  RcfUpload::preview()'s answer, or null
 * @var array{staged: array<int, array<string, mixed>>, kept: array<int, array<string, mixed>>} $recent
 * @var array{max_files: int, file_size: string, post_size: string} $limits
 */

use Rerm\Csrf;
use Rerm\Forms\RcfReader;
use Rerm\Forms\RcfUpload;
use Rerm\View;

$number = static fn (int $n): string => number_format($n);

/** A stored DATE as the day it names, in words. PLAIN; the caller escapes. */
$day = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

    return $parsed instanceof DateTimeImmutable ? $parsed->format('j M Y') : $iso;
};

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

/** The chip for one kind of doubt: the ones that keep a line as typed are louder. */
$kindChip = static function (string $kind) use ($chip): string {
    return match ($kind) {
        RcfReader::UNKNOWN_TYPE, RcfReader::UNKNOWN_REASON, RcfReader::NO_TYPE,
        RcfReader::DATE_UNREAD, RcfReader::COLUMN_MISSING, RcfUpload::LIKELY_DUPLICATE => $chip('warn', 'Look'),
        RcfReader::SERIAL_FOUND => $chip('ok', 'Found'),
        default                 => $chip('muted', 'Note'),
    };
};

$size = static function (int $bytes): string {
    return $bytes >= 1048576
        ? number_format($bytes / 1048576, 1) . ' MB'
        : number_format(max(1, (int) ceil($bytes / 1024))) . ' KB';
};

$batchRow = static function (array $batch, bool $kept) use ($app, $number): void {
    ?><tr>
        <td data-label="Upload" class="mono"><a href="<?= e($app->url('rcf-upload?batch=' . (int) $batch['id'])) ?>"><?= e((string) $batch['id']) ?></a></td>
        <td data-label="<?= $kept ? 'Kept' : 'Read' ?>"><?= View::time($app, (string) ($kept ? $batch['applied_at'] : $batch['started_at'])) ?></td>
        <td data-label="By"><?= e((string) $batch['uploader_name']) ?></td>
        <td data-label="Files" class="num"><?= e($number((int) $batch['files_read'])) ?></td>
        <?php if ($kept) { ?>
            <td data-label="Forms kept" class="num"><?= e($number((int) $batch['files_kept'])) ?></td>
            <td data-label="Lines" class="num"><?= e($number((int) $batch['lines_kept'])) ?></td>
        <?php } else { ?>
            <td data-label="Readable" class="num"><?= e($number((int) $batch['files_ready'])) ?></td>
            <td data-label="Refused" class="num"><?= e($number((int) $batch['files_refused'])) ?></td>
        <?php } ?>
    </tr><?php
};
?>
<p class="hint"><a href="<?= e($app->url('rcfs')) ?>">&larr; Track RCFs</a></p>
<h1>Upload RCFs</h1>
<p class="lede">
    Roster Change Forms that came in by email &mdash; filled in by hand, numbered
    by the Division Chairman, forwarded &mdash; read into the same record as the
    forms made here. Each one is read first and shown back to you; nothing is
    kept until you say so, because a kept form is a record and nothing deletes one.
</p>

<?php if ($preview === null) { ?>
    <?= View::rcfUploadForm($app, $limits) ?>

    <?php if ($recent['staged'] !== []) { ?>
        <div class="card">
            <h2>Read, not yet kept</h2>
            <p class="hint">Each of these is a preview waiting for a decision. Open one to keep or discard it; one nobody decides on is thrown away after a day.</p>
            <table>
                <thead><tr>
                    <th scope="col">Upload</th><th scope="col">Read</th><th scope="col">By</th>
                    <th scope="col" class="num">Files</th><th scope="col" class="num">Readable</th><th scope="col" class="num">Refused</th>
                </tr></thead>
                <tbody><?php foreach ($recent['staged'] as $batch) { $batchRow($batch, false); } ?></tbody>
            </table>
        </div>
    <?php } ?>

    <?php if ($recent['kept'] !== []) { ?>
        <div class="card">
            <h2>Kept</h2>
            <p class="hint">What each upload added to the record. The forms themselves are on <a href="<?= e($app->url('rcfs')) ?>">Track RCFs</a>.</p>
            <table>
                <thead><tr>
                    <th scope="col">Upload</th><th scope="col">Kept</th><th scope="col">By</th>
                    <th scope="col" class="num">Files</th><th scope="col" class="num">Forms kept</th><th scope="col" class="num">Lines</th>
                </tr></thead>
                <tbody><?php foreach ($recent['kept'] as $batch) { $batchRow($batch, true); } ?></tbody>
            </table>
        </div>
    <?php } ?>

<?php } else {
    $batch = $preview['batch'];
    $files = $preview['files'];
    $done  = $batch['applied_at'] !== null;
    $ready = 0;
    foreach ($files as $file) {
        if ($file['status'] === 'ready') {
            $ready++;
        }
    }
    $action = $app->url('rcf-upload');
?>
    <div class="card">
        <h2><?= $done ? $chip('ok', 'Kept') . ' What was kept' : $chip('warn', 'Nothing kept yet') . ' What was read' ?></h2>
        <p>
            Upload <span class="mono"><?= e((string) $batch['id']) ?></span>
            &middot; <?= e($number((int) $batch['files_read'])) ?> <?= (int) $batch['files_read'] === 1 ? 'file' : 'files' ?>
            read by <?= e((string) $batch['uploader_name']) ?> <?= View::time($app, (string) $batch['started_at']) ?>
            &middot; <?= e($number((int) $batch['files_ready'])) ?> readable as a form,
            <?= e($number((int) $batch['files_refused'])) ?> refused.
            <?php if ($done) { ?>
                Kept <?= View::time($app, (string) $batch['applied_at']) ?>:
                <strong><?= e($number((int) $batch['files_kept'])) ?></strong>
                <?= (int) $batch['files_kept'] === 1 ? 'form' : 'forms' ?>,
                <?= e($number((int) $batch['lines_kept'])) ?> <?= (int) $batch['lines_kept'] === 1 ? 'line' : 'lines' ?>,
                each logged with the uploader&rsquo;s name.
            <?php } else { ?>
                Below is exactly what was read out of each file. Untick any that read wrong, then keep the rest
                with the button at the bottom &mdash; a kept form cannot be deleted, only left out now.
            <?php } ?>
        </p>
    </div>

    <?php if (!$done) { ?>
    <form method="post" action="<?= e($action) ?>" class="keep">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="keep">
        <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
    <?php } ?>

    <?php foreach ($files as $file) {
        $status = (string) $file['status'];
        $word   = match ($status) {
            'ready'    => $chip('ok', 'Readable'),
            'kept'     => $chip('ok', 'Kept'),
            'left_out' => $chip('muted', 'Left out'),
            default    => $chip('danger', 'Set aside'),
        };
    ?>
        <div class="card file" id="f<?= e((string) $file['id']) ?>">
            <h2>#<?= e((string) $file['position']) ?> &middot; <span class="mono"><?= e((string) $file['filename']) ?></span> <?= $word ?></h2>
            <p class="hint">
                <?= (string) $file['format'] !== '' ? e(strtoupper((string) $file['format'])) . ' &middot; ' : '' ?>
                <?= e($size((int) $file['size_bytes'])) ?>
                <?php if ((string) ($file['read']['sheet'] ?? '') !== '') { ?>&middot; sheet <span class="mono"><?= e((string) $file['read']['sheet']) ?></span><?php } ?>
            </p>

            <?php if ($status === 'refused') { ?>
                <p><?= e((string) $file['refusal']) ?></p>
            <?php } elseif ($file['form'] !== null) {
                $form     = $file['form'];
                $resolved = $file['resolved'];
            ?>
                <dl class="facts">
                    <dt>Submitted by</dt>
                    <dd><?= (string) $form['submitter'] === '' ? $chip('warn', 'Not on the form') : e((string) $form['submitter']) ?></dd>
                    <dt>Dated</dt>
                    <dd><?= (string) $file['form_date'] === ''
                        ? $chip('warn', 'Not on the form') . ' <span class="why">the day it is kept stands in</span>'
                        : e($day((string) $file['form_date'])) ?></dd>
                    <dt>Sub-committee</dt>
                    <dd><?= (string) $form['subcommittee'] === '' ? $chip('warn', 'Not on the form') : e((string) $form['subcommittee']) ?>
                        <?php if ((string) ($resolved['team_name'] ?? '') !== '') { ?>
                            <span class="why">team <?= e((string) $resolved['team_name']) ?><?= (string) ($resolved['division_name'] ?? '') !== '' ? ', ' . e((string) $resolved['division_name']) : '' ?></span>
                        <?php } elseif ((string) ($resolved['division_name'] ?? '') !== '') { ?>
                            <span class="why">division <?= e((string) $resolved['division_name']) ?></span>
                        <?php } elseif ((string) $form['subcommittee'] !== '') { ?>
                            <span class="why">matches no team or division by name</span>
                        <?php } ?></dd>
                    <dt>Show year</dt>
                    <dd><?= e((string) ($resolved['show_year'] ?? $form['year'])) ?><?= ($resolved['year_assumed'] ?? false) ? ' <span class="why">assumed &mdash; the title names none this application has</span>' : '' ?></dd>
                    <dt>RCF #</dt>
                    <dd><?= (string) $file['serial'] === '' ? $chip('muted', 'Not on the form') : '<span class="mono">' . e((string) $file['serial']) . '</span> <span class="why">from the CHANGE FORM # box; written to every line</span>' ?></dd>
                    <dt>Lines</dt>
                    <dd><?= e($number((int) $file['row_count'])) ?></dd>
                    <?php if ($status === 'kept' && $file['rcf_id'] !== null) { ?>
                        <dt>Kept as</dt>
                        <dd><a href="<?= e($app->url('rcf?id=' . (int) $file['rcf_id'])) ?>">form #<?= e((string) $file['rcf_id']) ?></a> &mdash; track it there</dd>
                    <?php } ?>
                </dl>

                <?php if ($file['warnings'] !== []) { ?>
                    <ul class="rows">
                        <?php foreach ($file['warnings'] as $warning) { ?>
                            <li><?= $kindChip((string) ($warning['kind'] ?? '')) ?> <?= e((string) ($warning['detail'] ?? '')) ?></li>
                        <?php } ?>
                    </ul>
                <?php } ?>

                <table class="lines">
                    <caption>The filled lines, as read. Anything reshaped or doubted is said beside the line.</caption>
                    <thead><tr>
                        <th scope="col">#</th>
                        <th scope="col">Member</th>
                        <th scope="col">Change</th>
                        <th scope="col">Notes</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($file['lines'] as $line) { ?>
                        <tr>
                            <td data-label="Row" class="num"><?= e((string) $line['position']) ?></td>
                            <td data-label="Member"><?= e((string) $line['member_name']) ?>
                                <?php if ((string) $line['member_number'] !== '') { ?><span class="why mono"><?= e((string) $line['member_number']) ?></span><?php } ?>
                                <?php if ($line['member_id'] !== null) { ?>
                                    <span class="why"><?= $line['on_roster'] ? 'on the roster' : 'on the roster, dropped or purged' ?></span>
                                <?php } elseif ((string) $line['member_number'] !== '') { ?>
                                    <span class="why">not on the roster</span>
                                <?php } ?>
                                <?php if ($line['rookie']) { ?><span class="why">rookie</span><?php } ?>
                                <?php if ($line['wait_list']) { ?><span class="why">wait list</span><?php } ?></td>
                            <td data-label="Change"><?= e((string) $line['change']) ?><?php
                                if ((string) $line['sponsor'] !== '') { ?> <span class="why">&middot; <?= e((string) $line['sponsor']) ?></span><?php } ?></td>
                            <td data-label="Notes"><?php if ($line['warnings'] === []) { ?><span class="why">&mdash;</span><?php }
                                foreach ($line['warnings'] as $warning) { ?>
                                    <span class="why"><?= $kindChip((string) ($warning['kind'] ?? '')) ?> <?= e((string) ($warning['detail'] ?? '')) ?></span>
                                <?php } ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>

                <?php if (!$done && $status === 'ready') { ?>
                    <label class="choice">
                        <input type="checkbox" name="keep[<?= e((string) $file['id']) ?>]" value="1"<?= $file['keep_by_default'] ? ' checked' : '' ?>>
                        <span><span class="what">Keep this form</span>
                        <span class="why"><?= $file['keep_by_default']
                            ? 'Ticked: it reads as a form. Untick it to leave it out.'
                            : 'Unticked: it looks like a form already kept' . ($file['like_rcf_id'] !== null ? ' (<a href="' . e($app->url('rcf?id=' . (int) $file['like_rcf_id'])) . '">form #' . e((string) $file['like_rcf_id']) . '</a>)' : '') . '. Tick it if it really is a second form.' ?></span></span>
                    </label>
                <?php } ?>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (!$done) { ?>
        <div class="card">
            <h2>Keep the ticked forms, or throw this away</h2>
            <p>
                <?= $chip('warn', 'Nothing kept yet') ?>
                Keeping writes each ticked form and its lines to the record, with your name,
                and logs one entry per form. A kept form is never deleted &mdash; a wrong one
                is left out <em>now</em>, by unticking it.
            </p>
            <?php if ($ready === 0) { ?>
                <p class="hint">None of these files read as a form, so there is nothing to keep.</p>
            <?php } else { ?>
                <button type="submit">Keep the ticked forms</button>
            <?php } ?>
        </div>
    </form>
        <form method="post" action="<?= e($action) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="discard">
            <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
            <button type="submit" class="quiet">Discard this upload</button>
        </form>
    <?php } else { ?>
        <p>
            <a class="btnlink" href="<?= e($app->url('rcf-upload')) ?>">Upload more</a>
            <a class="btnlink" href="<?= e($app->url('rcfs')) ?>">Track RCFs</a>
        </p>
    <?php } ?>
<?php } ?>
