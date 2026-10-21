<?php

declare(strict_types=1);

/**
 * Create Approval Flow (Phase 14, spec-v2 §15.3) — who approves whom.
 *
 * Every account at Senior Officer and above, one row each, with what is
 * drawn for it and the chain that follows: "→ Bob → Carl" in names, so the
 * Admin reads the route rather than following arrows. ONE row is open for
 * editing at a time (`?account=`), the Designate Users pattern, because a
 * select of fifty accounts drawn fifty times over is a page that will not
 * load on a phone (spec-v1 §10). The three states are all spelled in words,
 * and a save that would make a loop is refused by name with nothing written.
 *
 * Every rendered value goes through e().
 *
 * @var Rerm\App                             $app
 * @var Rerm\Auth\User                       $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<int, array<string, mixed>>     $accounts ApprovalFlow::accounts()
 * @var ?int                                 $selected the account whose row is open
 */

use Rerm\Csrf;
use Rerm\Forms\ApprovalFlow;

$byId = [];
foreach ($accounts as $account) {
    $byId[(int) $account['id']] = $account;
}
$open = $selected !== null ? ($byId[$selected] ?? null) : null;

$unset = 0;
foreach ($accounts as $account) {
    if ($account['state'] === 'unset') {
        $unset++;
    }
}
?>
<h1>Approval Flow</h1>
<p class="lede">
    Who approves whose RCF requests. A request goes to the officer&rsquo;s approver,
    then to that approver&rsquo;s approver, and so on until it reaches somebody
    at the <strong>top of the chain</strong>, who puts everything they approved
    onto a Roster Change Form. If Bob approves Sheila and Carl approves Bob,
    Sheila&rsquo;s request waits on Bob, then on Carl, and Carl generates the form.
</p>

<div class="card">
    <h2>Three states, all in words</h2>
    <ul class="rows">
        <li><strong>Approved by</strong> somebody &mdash; their requests wait on that account.</li>
        <li><strong>Top of the chain</strong> &mdash; nobody is above them; their own requests are approved the moment
            they are submitted and they generate the forms.</li>
        <li><strong>Not set</strong> &mdash; their requests are <em>refused</em> with a sentence naming this screen.
            Nothing is routed to a default.<?php if ($unset > 0) { ?>
                <span class="chip chip-warn"><?= e((string) $unset) ?> not set</span><?php } ?></li>
    </ul>
    <p class="hint">Only accounts at Senior Officer and above are listed: Captains fill in Roster Change Forms on Create Forms rather than submitting requests.</p>
</div>

<?php if ($accounts === []) { ?>
    <div class="card"><p>No account at Senior Officer or above exists yet.</p></div>
<?php } else { ?>
    <table class="track flow">
        <caption><?= e((string) count($accounts)) ?> accounts. Open a row to change who approves it; the save is refused if it would make a loop.</caption>
        <thead><tr>
            <th scope="col">Account</th>
            <th scope="col">Placement</th>
            <th scope="col">Approved by</th>
            <th scope="col">Chain</th>
            <th scope="col"><span class="vh">Change</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($accounts as $account) { $id = (int) $account['id']; $isOpen = $open !== null && (int) $open['id'] === $id; ?>
            <tr id="a<?= $id ?>"<?= $isOpen ? ' class="open"' : '' ?>>
                <td data-label="Account"><strong><?= e((string) $account['name']) ?></strong>
                    <span class="why"><?= e((string) $account['title']) ?> &middot; <?= e($account['level']->label()) ?></span></td>
                <td data-label="Placement"><?= e(trim((string) $account['division_name'] . ' · ' . (string) $account['team_name'], ' ·')) ?></td>
                <td data-label="Approved by"><?php if ($isOpen) { ?>
                    <form method="post" action="<?= e($app->url('approval-flow')) ?>" class="inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="account" value="<?= $id ?>">
                        <label for="approver" class="vh">Approved by</label>
                        <select id="approver" name="approver[<?= $id ?>]">
                            <option value="<?= e(ApprovalFlow::UNSET) ?>"<?= $account['value'] === ApprovalFlow::UNSET ? ' selected' : '' ?>>&mdash; not set: requests refused</option>
                            <option value="<?= e(ApprovalFlow::TOP) ?>"<?= $account['value'] === ApprovalFlow::TOP ? ' selected' : '' ?>>Nobody &mdash; top of the chain</option>
                            <?php foreach ($accounts as $candidate) { if ((int) $candidate['id'] === $id) { continue; } ?>
                                <option value="<?= e((string) $candidate['id']) ?>"<?= $account['value'] === (string) $candidate['id'] ? ' selected' : '' ?>><?=
                                    e((string) $candidate['name']) ?> &mdash; <?= e((string) $candidate['title']) ?></option>
                            <?php } ?>
                        </select>
                        <button type="submit" name="action" value="save">Save</button>
                        <a href="<?= e($app->url('approval-flow')) ?>" class="quiet">Cancel</a>
                    </form>
                <?php } else { ?>
                    <?= match ((string) $account['state']) {
                        'top'      => '<span class="chip chip-ok">Top of the chain</span>',
                        'approver' => e((string) ($byId[(int) $account['approver_id']]['name'] ?? ('account #' . (int) $account['approver_id']))),
                        default    => '<span class="chip chip-warn">Not set</span>',
                    } ?>
                <?php } ?></td>
                <td data-label="Chain"><?php if ($account['chain_end'] === 'unset') { ?>
                        <span class="why">requests refused until set</span>
                    <?php } elseif ($account['chain'] === []) { ?>
                        <span class="why">approves their own; generates the form</span>
                    <?php } else { ?>
                        &rarr; <?= e(implode(' → ', $account['chain'])) ?><?php
                        if ($account['chain_end'] === 'loop') { ?> <span class="chip chip-danger">loop</span><?php } ?>
                    <?php } ?></td>
                <td data-label="Change"><?php if (!$isOpen) { ?>
                    <a href="<?= e($app->url('approval-flow?account=' . $id)) ?>#a<?= $id ?>">Change</a>
                <?php } ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
<?php } ?>

<p><a href="<?= e($app->url('designate')) ?>">Designate Users</a> &middot; <a href="<?= e($app->url('menu')) ?>">Menu</a></p>
