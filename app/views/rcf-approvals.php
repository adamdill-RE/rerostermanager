<?php

declare(strict_types=1);

/**
 * Approve RCF Entries (Phase 14, spec-v2 §15.4) — what is waiting on the
 * caller, oldest first, and what they have already decided.
 *
 * One tick box per entry and two buttons: Approve the ticked ones — all
 * ticked to begin with, because a Division Chairman with eleven waiting
 * approves them in one tap — or Return them with one note, which is
 * required: "no" without a reason is a request the Vice Chairman has to
 * ring about. Each row says where it goes next if approved, read from the
 * flow, so an approver knows whether they are the last word.
 *
 * Every rendered value goes through e().
 *
 * @var Rerm\App                             $app
 * @var Rerm\Auth\User                       $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<int, array<string, mixed>>     $waiting  RcfEntries::waitingFor()
 * @var array<int, array<string, mixed>>     $decided  RcfEntries::decidedBy()
 * @var array{mine: int, waiting: int, ready: int} $counts
 */

use Rerm\Csrf;
use Rerm\Forms\RcfEntries;
use Rerm\View;

$member = static function (array $entry) use ($app): string {
    $name = e((string) $entry['member_name']);
    if ($entry['member_id'] !== null) {
        $name = '<a href="' . e($app->url('member?id=' . (int) $entry['member_id'] . '&from=rcf-approvals')) . '">' . $name . '</a>';
    }
    if ((string) $entry['member_number'] !== '') {
        $name .= ' <span class="why mono">' . e((string) $entry['member_number']) . '</span>';
    }

    return $name;
};
?>
<h1>Approve RCF Requests</h1>
<p class="lede">
    Requests other officers have asked for that are waiting on you. Approve them
    and they move up the chain &mdash; or, if you are the last approver, they
    become yours to put on a form. Return one with a note and the officer who
    asked reads why.
</p>

<?= View::rcfEntriesNav($app, $user, 'rcf-approvals', $counts) ?>

<h2 id="waiting">Waiting for you</h2>
<?php if ($waiting === []) { ?>
    <div class="card"><p>Nothing is waiting on you. A request appears here the moment somebody whose approver you are submits one.</p></div>
<?php } else { ?>
    <form method="post" action="<?= e($app->url('rcf-approvals')) ?>" class="approvals">
        <?= Csrf::field() ?>
        <?php /* Enter approves (Phase 10.5's lesson): the first submit is the
                 one a finished review wants, hidden and off the tab order. */ ?>
        <button type="submit" name="action" value="approve" class="vh" tabindex="-1" aria-hidden="true">Approve the ticked requests</button>
        <table class="track approvals">
            <caption>Oldest first &mdash; the order to work them in. Every row starts ticked; untick the ones you are not deciding today.</caption>
            <thead><tr>
                <th scope="col"><span class="vh">Ticked</span></th>
                <th scope="col">Asked</th>
                <th scope="col">Sub-committee</th>
                <th scope="col">Member</th>
                <th scope="col">Change</th>
                <th scope="col">If approved</th>
            </tr></thead>
            <tbody>
            <?php foreach ($waiting as $entry) { $id = (int) $entry['id']; ?>
                <tr id="e<?= $id ?>">
                    <td class="pick" data-label="Ticked"><input type="checkbox" name="ids[]" value="<?= $id ?>" checked
                        aria-label="Entry for <?= e((string) $entry['member_name']) ?>"></td>
                    <td data-label="Asked"><?= View::time($app, (string) $entry['requested_at']) ?>
                        <span class="why">by <?= e((string) $entry['requester_name']) ?></span></td>
                    <td data-label="Sub-committee"><?= e((string) $entry['subcommittee']) ?></td>
                    <td data-label="Member"><?= $member($entry) ?><?php
                        if ($entry['rookie']) { ?> <span class="why">rookie</span><?php }
                        if ($entry['wait_list']) { ?> <span class="why">wait list</span><?php } ?></td>
                    <td data-label="Change"><?= e((string) $entry['was']) ?> &rarr; <?= e((string) $entry['becomes']) ?><?php
                        if ((string) $entry['sponsor'] !== '') { ?> <span class="why">sponsor <?= e((string) $entry['sponsor']) ?></span><?php } ?></td>
                    <td data-label="If approved"><?php if (($entry['next_approver'] ?? null) !== null) { ?>
                        then to <?= e((string) $entry['next_approver']['name']) ?>
                    <?php } else { ?>
                        <span class="chip chip-info">then ready for you to generate</span>
                    <?php } ?><?php if (count($entry['steps']) > 1) { ?>
                        <span class="why"><?php
                            $said = [];
                            foreach ($entry['steps'] as $step) {
                                if ($step['action'] === 'approved') { $said[] = 'approved by ' . e((string) $step['actor_name']); }
                            }
                            echo implode(' · ', $said); ?></span>
                    <?php } ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>

        <div class="cards">
            <div class="card">
                <h2>Approve</h2>
                <p>The ticked requests move on to the next approver, or become yours to generate.</p>
                <button type="submit" name="action" value="approve">Approve the ticked requests</button>
            </div>
            <div class="card">
                <h2>Return</h2>
                <label for="note">Why &mdash; the officer who asked reads this</label>
                <textarea id="note" name="note" rows="2" maxlength="<?= e((string) RcfEntries::NOTE_MAX) ?>"
                    placeholder="e.g. Already resigned in March; no form needed."></textarea>
                <p class="hint">A return without a note is refused.</p>
                <button type="submit" name="action" value="return" class="quiet">Return the ticked requests with this note</button>
            </div>
        </div>
    </form>
<?php } ?>

<h2 id="decided">Decided by you</h2>
<?php if ($decided === []) { ?>
    <div class="card"><p>You have not approved or returned a request yet.</p></div>
<?php } else { ?>
    <table class="track">
        <caption>The last fifty requests you approved or returned, newest first, and where each is now.</caption>
        <thead><tr>
            <th scope="col">Asked</th>
            <th scope="col">Sub-committee</th>
            <th scope="col">Member</th>
            <th scope="col">Change</th>
            <th scope="col">Where it is</th>
        </tr></thead>
        <tbody>
        <?php foreach ($decided as $entry) { ?>
            <tr>
                <td data-label="Asked"><?= View::time($app, (string) $entry['requested_at']) ?>
                    <span class="why">by <?= e((string) $entry['requester_name']) ?></span></td>
                <td data-label="Sub-committee"><?= e((string) $entry['subcommittee']) ?></td>
                <td data-label="Member"><?= $member($entry) ?></td>
                <td data-label="Change"><?= e((string) $entry['was']) ?> &rarr; <?= e((string) $entry['becomes']) ?></td>
                <td data-label="Where it is"><span class="chip <?= e(match ((string) $entry['status']) {
                    'pending' => 'chip-warn', 'approved' => 'chip-info', 'generated' => $entry['landed'] === null ? 'chip-info' : 'chip-ok',
                    'returned' => 'chip-danger', default => 'chip-muted',
                }) ?>"><?= e($entry['status'] === 'generated' && $entry['landed'] !== null ? 'In the roster' : (string) $entry['status_label']) ?></span>
                    <span class="why"><?php if ($entry['status'] === 'generated' && $entry['rcf_id'] !== null) { ?>
                        <a href="<?= e($app->url('rcf?id=' . (int) $entry['rcf_id'])) ?>"><?= e((string) $entry['where']) ?></a>
                    <?php } else { ?><?= e((string) $entry['where']) ?><?php } ?><?php
                        if ($entry['landed'] !== null) { ?>, import of <?= View::time($app, (string) $entry['landed']['at']) ?><?php } ?></span>
                    <?php /* The whole chain in one line (Phase 14.2): every hand, then
                             what the roster said. HLSR's answer is the last word. */ ?>
                    <span class="why trail"><?= e(implode(' · ', (array) $entry['trail'])) ?></span></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
<?php } ?>

<p><a href="<?= e($app->url('rcfs')) ?>">Track RCFs</a> &middot; <a href="<?= e($app->url('menu')) ?>">Menu</a></p>
