<?php

declare(strict_types=1);

/**
 * Consolidate RCFs (Phase 14, spec-v2 §15.5) — everything that ended with
 * the caller's approval, cut into the forms it will print on, and one
 * button that keeps them.
 *
 * The cut is shown BEFORE it is made: one heading per form-to-be, with the
 * sub-committee that will be at G5 and the lines under it, so the generator
 * reads the forms over as they will go. Grouping is a choice of two —
 * by division (the Division Chairman's bundle) or by team — and a group of
 * more than twenty-five lines shows as two forms, because that is what it
 * will be. Every line starts ticked: the request is to take all of them.
 *
 * Nothing is downloaded here. Generate KEEPS the forms (RcfStore) and lists
 * them below, where each opens on its own page and downloads from there
 * like every other kept form — one POST cannot send three files, and a form
 * kept before it is sent is the record spec-v2 §12.2 wanted.
 *
 * Every rendered value goes through e().
 *
 * @var Rerm\App                             $app
 * @var Rerm\Auth\User                       $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<int, array<string, mixed>>     $ready   RcfEntries::readyFor()
 * @var array<int, array<string, mixed>>     $cut     RcfEntries::cut($ready, $by)
 * @var string                               $by      'division' or 'team'
 * @var array<int, array<string, mixed>>     $forms   RcfEntries::generatedForms()
 * @var array{mine: int, waiting: int, ready: int} $counts
 */

use Rerm\Csrf;
use Rerm\Forms\RcfEntries;
use Rerm\Forms\RosterChangeForm;
use Rerm\View;
?>
<h1>Export Approved Requests to RCF Forms</h1>
<p class="lede">
    Every entry that ended with your approval and is not yet on a form, cut
    into the Roster Change Forms it will print on. Generate keeps the forms;
    each one is then downloaded from its own page, numbered and tracked like
    every other form.
</p>

<?= View::rcfEntriesNav($app, $user, 'rcf-consolidate', $counts) ?>

<?php if ($ready === []) { ?>
    <div class="card">
        <h2>Nothing to generate</h2>
        <p>No approved entry has ended with you. One appears here the moment you approve an entry as the last
            approver in its chain &mdash; or submit one yourself, if you are at the top of the chain.</p>
    </div>
<?php } else { ?>
    <form method="post" action="<?= e($app->url('rcf-consolidate')) ?>" class="consolidate">
        <?= Csrf::field() ?>
        <?php /* Enter regroups rather than generates: generating is the
                 consequential one, and it is the visible button at the end. */ ?>
        <button type="submit" name="action" value="regroup" class="vh" tabindex="-1" aria-hidden="true">Regroup</button>

        <div class="card">
            <h2>How to cut them</h2>
            <fieldset class="choice">
                <legend>One form per</legend>
                <?php foreach (RcfEntries::GROUP_BY as $key => $label) { ?>
                    <label class="radio"><input type="radio" name="by" value="<?= e($key) ?>"<?= $by === $key ? ' checked' : '' ?>>
                        <?= e($label) ?>
                        <span class="why"><?= $key === 'division'
                            ? 'the Division Chairman’s bundle: one form headed with the division, whichever teams the lines came from'
                            : 'one form headed Division - Team, for an approver whose chain covers one team' ?></span></label>
                <?php } ?>
            </fieldset>
            <button type="submit" name="action" value="regroup" class="quiet">Regroup</button>
            <p class="hint">A form has <?= e((string) RosterChangeForm::ROWS) ?> rows; a group with more lines than that becomes two forms.</p>
        </div>

        <?php foreach ($cut as $n => $form) { ?>
            <h2 id="form<?= $n + 1 ?>">Form <?= e((string) ($n + 1)) ?> of <?= e((string) count($cut)) ?> &mdash; <?= e((string) $form['label']) ?></h2>
            <table class="track consolidate">
                <caption><?= e((string) count($form['entries'])) ?> <?= count($form['entries']) === 1 ? 'line' : 'lines' ?>, headed <strong><?= e((string) $form['label']) ?></strong>. Untick a line to leave it for a later form.</caption>
                <thead><tr>
                    <th scope="col"><span class="vh">Ticked</span></th>
                    <th scope="col">Member</th>
                    <th scope="col">Change</th>
                    <th scope="col">Team</th>
                    <th scope="col">Asked by</th>
                    <th scope="col">Approved</th>
                </tr></thead>
                <tbody>
                <?php foreach ($form['entries'] as $entry) { $id = (int) $entry['id']; ?>
                    <tr id="e<?= $id ?>">
                        <td class="pick" data-label="Ticked"><input type="checkbox" name="ids[]" value="<?= $id ?>" checked
                            aria-label="Entry for <?= e((string) $entry['member_name']) ?>"></td>
                        <td data-label="Member"><?php if ($entry['member_id'] !== null) { ?>
                                <a href="<?= e($app->url('member?id=' . (int) $entry['member_id'] . '&from=rcf-consolidate')) ?>"><?= e((string) $entry['member_name']) ?></a>
                            <?php } else { ?><?= e((string) $entry['member_name']) ?><?php } ?>
                            <?php if ((string) $entry['member_number'] !== '') { ?><span class="why mono"><?= e((string) $entry['member_number']) ?></span><?php } ?>
                            <?php if ($entry['rookie']) { ?><span class="why">rookie</span><?php } ?>
                            <?php if ($entry['wait_list']) { ?><span class="why">wait list</span><?php } ?></td>
                        <td data-label="Change"><?= e((string) $entry['was']) ?> &rarr; <?= e((string) $entry['becomes']) ?><?php
                            if ((string) $entry['sponsor'] !== '') { ?> <span class="why">sponsor <?= e((string) $entry['sponsor']) ?></span><?php } ?></td>
                        <td data-label="Team"><?= e((string) ($entry['team_name'] !== '' ? $entry['team_name'] : $entry['subcommittee'])) ?></td>
                        <td data-label="Asked by"><?= e((string) $entry['requester_name']) ?>
                            <span class="why"><?= View::time($app, (string) $entry['requested_at']) ?></span></td>
                        <td data-label="Approved"><?= $entry['approved_at'] === null ? '' : View::time($app, (string) $entry['approved_at']) ?>
                            <span class="why">by <?= e((string) $entry['final_approver_name']) ?></span></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>

        <div class="card">
            <span class="chip chip-warn">A filled-in form is personal data</span>
            <p>
                Generate keeps <?= e((string) count($cut)) ?> <?= count($cut) === 1 ? 'form' : 'forms' ?> under your name as
                submitter, dated today, for the active show year. Nothing is downloaded yet: each form opens
                on its own page below, where you read it over, download it and track it. Every entry taken
                is marked as on its form and logged with your name.
            </p>
            <button type="submit" name="action" value="generate">Generate <?= e((string) count($cut)) ?> <?= count($cut) === 1 ? 'form' : 'forms' ?> from the ticked lines</button>
        </div>
    </form>
<?php } ?>

<h2 id="generated">Forms you have generated</h2>
<?php if ($forms === []) { ?>
    <div class="card"><p>No consolidated form yet. The first one appears here, ready to download from its page.</p></div>
<?php } else { ?>
    <table class="track">
        <caption>Newest first. Download a form from here, or open it to read it over, add the RCF number and mark it sent &mdash; or, if it was a test run, to void it, which takes it and its entries out of view for everybody.</caption>
        <thead><tr>
            <th scope="col">Generated</th>
            <th scope="col">Sub-committee</th>
            <th scope="col" class="num">Lines</th>
            <th scope="col">Downloaded</th>
            <th scope="col"><span class="vh">Download</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($forms as $form) { ?>
            <tr>
                <td data-label="Generated"><a href="<?= e($app->url('rcf?id=' . (int) $form['id'])) ?>"><?= View::time($app, (string) $form['generated_at']) ?></a>
                    <span class="why">form #<?= e((string) $form['id']) ?></span></td>
                <td data-label="Sub-committee"><?= e((string) $form['subcommittee']) ?></td>
                <td class="num" data-label="Lines"><?= e((string) $form['rows']) ?></td>
                <td data-label="Downloaded"><?= (int) $form['regenerated_count'] === 0
                    ? '<span class="chip chip-warn">not yet</span>'
                    : '<span class="chip chip-ok">' . e((string) $form['regenerated_count']) . ' time' . ((int) $form['regenerated_count'] === 1 ? '' : 's') . '</span>' ?></td>
                <td data-label="Download"><?php /* The same POST the form's own page makes (Phase 14.3): a
                        filled-in form is personal data, so it is never a link, and
                        each download is logged with the caller's name. One small form
                        per row, so pressing one never submits the grid above. */ ?>
                    <form method="post" action="<?= e($app->url('rcf?id=' . (int) $form['id'])) ?>" class="inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $form['id']) ?>">
                        <input type="hidden" name="action" value="download">
                        <button type="submit" class="quiet" aria-label="Download form #<?= e((string) $form['id']) ?>">Download</button>
                    </form></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
<?php } ?>

<p><a href="<?= e($app->url('rcfs')) ?>">Track RCFs</a> &middot; <a href="<?= e($app->url('menu')) ?>">Menu</a></p>
