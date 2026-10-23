<?php

declare(strict_types=1);

/**
 * Create RCF Entries (Phase 14, spec-v2 §15.4) — a Vice Chairman asks for
 * one change at a time, and reads where each request has got to.
 *
 * The grid is the Roster Change Form's own (`rcf-grid.php`), drawn from the
 * same `RcfPage::page()`, so picking a member, typing a newcomer and the two
 * tick boxes work exactly as they do on the form; the difference is the
 * button. Every filled row becomes one ENTRY, routed where the flow says
 * this account's entries go — which the card at the top says in a sentence
 * before anything is typed, because an account whose approver is not set
 * has its entries refused (§15.3) and should learn that first.
 *
 * Below the grid, the caller's own entries newest first: waiting on whom,
 * approved by whom, returned with what note, or on which form. A pending
 * one can be withdrawn. Every rendered value goes through e().
 *
 * @var Rerm\App                             $app
 * @var Rerm\Auth\User                       $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>                 $rcf     RcfPage::page()'s answer
 * @var array<int, array<string, mixed>>     $mine    RcfEntries::mine()
 * @var array{mine: int, waiting: int, ready: int} $counts
 * @var array{state: string, approver: ?array{id: int, name: string}, approves?: int} $route
 */

use Rerm\Csrf;
use Rerm\Forms\RcfEntries;
use Rerm\Forms\RcfPage;
use Rerm\Forms\RosterChangeForm;
use Rerm\View;

/** @var array<int, array<string, mixed>> $subcommittees */
$subcommittees = $rcf['subcommittees'];

/** @var array<string, mixed>|null $chosen */
$chosen = $rcf['subcommittee'];

$grouped = [];
foreach ($subcommittees as $option) {
    $grouped[(string) $option['group']][] = $option;
}

$canSubmit = $route['state'] !== 'unset';
$back      = 'rcf-entries';
?>
<h1>Create RCF Requests</h1>
<p class="lede">
    Ask for one change at a time &mdash; an addition, a removal, a title or a
    team change &mdash; and it goes up the chain for approval. The approver at
    the top of the chain puts everything approved onto a Roster Change Form,
    so nobody retypes it.
</p>

<?= View::rcfEntriesNav($app, $user, 'rcf-entries', $counts) ?>

<div class="card">
    <h2>Where your requests go</h2>
    <?php if ($route['state'] === 'approver' && $route['approver'] !== null) { ?>
        <p>Every request you submit waits on <strong><?= e((string) $route['approver']['name']) ?></strong>
            first, and goes on up the chain from there.</p>
    <?php } elseif ($route['state'] === 'top') { ?>
        <p>You are at the <strong>top of the chain</strong>: your requests are approved the moment you
            submit them, and you put them on a form yourself on
            <a href="<?= e($app->url('rcf-consolidate')) ?>">Export Approved Requests to RCF Forms</a>.</p>
    <?php } elseif ((int) ($route['approves'] ?? 0) > 0) { ?>
        <?php /* The final approver's trap (Phase 14.5, spec-v2 §19.2): other
                 people's requests end with this account, and its own are
                 refused, because being somebody's approver places nobody.
                 Say which of the two fixes this is. */ ?>
        <p><span class="chip chip-warn">Nobody is set as your approver yet</span>
            <?= e((string) ($route['approves'] ?? 0)) ?> <?= (int) ($route['approves'] ?? 0) === 1 ? 'account routes its' : 'accounts route their' ?> requests to you,
            but your own row on Create Approval Flow is <strong>not set</strong>,
            so your own requests cannot be submitted. If you are the final approver, an Admin
            marks you <strong>Top of the chain</strong> there: your requests are then approved the
            moment you submit them, and you put them on a form with everything you approved.
            Nothing you type here is routed to a default.</p>
    <?php } else { ?>
        <p><span class="chip chip-warn">Nobody is set as your approver yet</span>
            Your requests cannot be submitted until an Admin draws, on Create Approval Flow,
            who approves them &mdash; or marks you <strong>Top of the chain</strong>, if nobody
            does. Nothing you type here is routed to a default.</p>
    <?php } ?>
</div>

<?php if ($subcommittees === []) { ?>
    <div class="card"><p>No sub-committee holds anybody yet, so there is nothing to change. Import a roster first.</p></div>
<?php } elseif ($canSubmit) { ?>
<form method="post" action="<?= e($app->url('rcf-entries')) ?>">
    <?= Csrf::field() ?>
    <?php /* Enter submits the FIRST submit in the form (Phase 10.5's lesson):
             hidden and off the tab order, so Enter submits the entries rather
             than reloading the sub-committee and losing the rows. */ ?>
    <button type="submit" name="action" value="submit" class="vh" tabindex="-1" aria-hidden="true">Submit these requests</button>

    <div class="card">
        <h2>Which sub-committee</h2>
        <label for="subcommittee">Sub-Committee</label>
        <select id="subcommittee" name="subcommittee"><option value="">Choose a sub-committee&hellip;</option><?php
            foreach ($grouped as $group => $options) {
                ?><optgroup label="<?= e($group === '' ? 'Ungrouped' : $group) ?>"><?php
                foreach ($options as $option) {
                    ?><option value="<?= e((string) $option['key']) ?>"<?=
                        $chosen !== null && $option['key'] === $chosen['key'] ? ' selected' : '' ?>><?=
                        e((string) $option['name']) ?> (<?= e(number_format((int) $option['members'])) ?>)</option><?php
                }
                ?></optgroup><?php
            } ?></select>
        <p class="hint">
            The sub-committee these requests are about. It is written on every one
            of them, because the form they end up on is headed with it &mdash; a
            removal or a title change does not say which team otherwise.
        </p>
        <button type="submit" name="action" value="load" class="quiet">Load this sub-committee</button>
    </div>

<?php if ($chosen === null) { ?>
    <div class="card"><p>Choose a sub-committee and press <strong>Load this sub-committee</strong>. Nothing you type is lost when you do.</p></div>
<?php } else { ?>
    <?php require __DIR__ . '/rcf-grid.php'; ?>

    <div class="card">
        <h2>Submit</h2>
        <p>
            Every filled row becomes one request. Rows you leave blank are not
            submitted. Each request is logged with your name and can be withdrawn
            while it is still waiting.
        </p>
        <button type="submit" name="action" value="submit">Submit these requests</button>
    </div>
<?php } ?>
</form>
<?php } ?>

<h2 id="mine">Your requests</h2>
<?php if ($mine === []) { ?>
    <div class="card"><p>You have not submitted a request yet. The first one appears here, with where it is waiting.</p></div>
<?php } else { ?>
    <table class="track entries">
        <caption>Newest first. Withdraw takes back a request that is still waiting; a returned one carries the approver&rsquo;s note.</caption>
        <thead><tr>
            <th scope="col">Submitted</th>
            <th scope="col">Sub-committee</th>
            <th scope="col">Member</th>
            <th scope="col">Change</th>
            <th scope="col">Where it is</th>
            <th scope="col"><span class="vh">Actions</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($mine as $entry) { ?>
            <tr id="e<?= e((string) $entry['id']) ?>">
                <td data-label="Submitted"><?= View::time($app, (string) $entry['requested_at']) ?></td>
                <td data-label="Sub-committee"><?= e((string) $entry['subcommittee']) ?></td>
                <td data-label="Member">
                    <?php if ($entry['member_id'] !== null) { ?>
                        <a href="<?= e($app->url('member?id=' . (int) $entry['member_id'] . '&from=rcf-entries')) ?>"><?= e((string) $entry['member_name']) ?></a>
                    <?php } else { ?>
                        <?= e((string) $entry['member_name']) ?>
                    <?php } ?>
                    <?php if ((string) $entry['member_number'] !== '') { ?><span class="why mono"><?= e((string) $entry['member_number']) ?></span><?php } ?>
                </td>
                <td data-label="Change"><?= e((string) $entry['was']) ?> &rarr; <?= e((string) $entry['becomes']) ?><?php
                    if ($entry['rookie']) { ?> <span class="why">rookie</span><?php }
                    if ($entry['wait_list']) { ?> <span class="why">wait list</span><?php } ?></td>
                <td data-label="Where it is">
                    <span class="chip <?= e(match ((string) $entry['status']) {
                        'pending' => 'chip-warn', 'approved' => 'chip-info', 'generated' => $entry['landed'] === null ? 'chip-info' : 'chip-ok',
                        'returned' => 'chip-danger', default => 'chip-muted',
                    }) ?>"><?= e($entry['status'] === 'generated' && $entry['landed'] !== null ? 'In the roster' : (string) $entry['status_label']) ?></span>
                    <span class="why"><?php if ($entry['status'] === 'generated' && $entry['rcf_id'] !== null) { ?>
                        <a href="<?= e($app->url('rcf?id=' . (int) $entry['rcf_id'])) ?>"><?= e((string) $entry['where']) ?></a>
                    <?php } else { ?><?= e((string) $entry['where']) ?><?php } ?><?php
                        if ($entry['landed'] !== null) { ?>, import of <?= View::time($app, (string) $entry['landed']['at']) ?><?php } ?></span>
                    <?php /* The whole chain in one line (Phase 14.2): every hand, then
                             what the roster said. HLSR's answer is the last word. */ ?>
                    <span class="why trail"><?= e(implode(' · ', (array) $entry['trail'])) ?></span>
                </td>
                <td data-label="Actions"><?php if ($entry['status'] === 'pending') { ?>
                    <form method="post" action="<?= e($app->url('rcf-entries')) ?>" class="inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="withdraw">
                        <input type="hidden" name="id" value="<?= e((string) $entry['id']) ?>">
                        <button type="submit" class="quiet">Withdraw</button>
                    </form>
                <?php } ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
<?php } ?>

<section class="codes">
    <input type="checkbox" id="fold-codes" class="fold vh">
    <label for="fold-codes" class="fold-label">What the codes mean</label>
    <div class="folded">
    <h2>What the codes mean</h2>
    <p class="hint">The form&rsquo;s own, printed on it: the same list the Division Chairman reads.</p>
    <h3>*Type</h3>
    <ul class="rows">
        <?php foreach (RosterChangeForm::TYPES as $code => $description) { ?>
            <li><strong><?= e((string) $code) ?></strong> = <?= e((string) $description) ?></li>
        <?php } ?>
    </ul>
    <h3>**Remove reason</h3>
    <ul class="rows">
        <?php foreach (RosterChangeForm::REMOVE_REASONS as $number => $reason) { ?>
            <li><strong><?= e((string) $number) ?>)</strong> <?= e((string) $reason) ?></li>
        <?php } ?>
    </ul>
    </div>
</section>

<p><a href="<?= e($app->url('rcfs')) ?>">Track RCFs</a> &middot; <a href="<?= e($app->url('forms')) ?>">Create Forms</a> &middot; <a href="<?= e($app->url('menu')) ?>">Menu</a></p>
