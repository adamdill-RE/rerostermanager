<?php

declare(strict_types=1);

/**
 * The Roster Change Form (spec-v2 §2) — one screen, one POST, one .xlsx.
 *
 * The screen is the paper form, in the paper form's order: who is submitting
 * it, when, which sub-committee, and then twenty-five numbered rows. That is
 * not nostalgia — an officer who has filled one of these in by hand should be
 * able to fill this in without being taught, and a form that reorders the
 * columns is a form they have to read twice.
 *
 * Three controls are `<datalist>` rather than `<select>`, and it is the same
 * decision each time: they repeat twenty-five times. A hundred teams drawn
 * twenty-five times over is 150KB of `<option>` on a page with a 100KB
 * first-paint budget (spec §10). A datalist is emitted ONCE and shared by
 * every row — and it happens to be exactly the behaviour the member field
 * needs anyway, because "pick somebody off the roster, or type in somebody
 * who is not on it yet" is a text box with suggestions and not a menu.
 *
 * **RE rookie and Wait list are tick boxes because the cells they land in
 * ARE tick boxes** — Excel checkboxes, established from the workbook's own
 * feature property bag (`Rerm\Forms\RosterChangeForm`). The form's older
 * printed instructions still say `y/n` and 'Please enter "Yes" or "No"'
 * beside them; the cells are what Rodeo Houston processes, so the screen
 * matches the cells.
 *
 * There is no JavaScript, here or anywhere in this application: the host has
 * no build step, and the Content-Security-Policy render() sets forbids script
 * outright.
 *
 * **Changing the sub-committee re-submits the whole form.** The member list
 * depends on it, so it has to go back to the server — and going back with a
 * plain link would throw away twenty-five rows of typing. Both buttons post
 * the same form; only `action=download` builds a file.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>  $rcf everything RcfPage::page() decided
 */

use Rerm\Csrf;
use Rerm\Forms\RosterChangeForm;

/** @var array<int, array<string, mixed>> $officers */
$officers = $rcf['officers'];

/** @var array<int, array<string, mixed>> $subcommittees */
$subcommittees = $rcf['subcommittees'];

/** @var array<string, mixed>|null $chosen */
$chosen = $rcf['subcommittee'];

/** @var array<int, array<string, string>> $members */
$members = $rcf['members'];

/** @var array<int, array<string, string>> $entries */
$entries = $rcf['entries'];

$submitterNumber = $rcf['submitter'] === null ? '' : (string) $rcf['submitter']['member_number'];

// The screen draws five rows and grows five at a time; the FILE always has
// twenty-five. A row that was never drawn submits nothing and prints blank,
// which is what an untouched row on the paper form does — and drawing all
// twenty-five up front is ~45KB of controls on a page that usually carries
// three names (spec-v2 §2.4, spec §10).
$visibleRows = (int) $rcf['visible_rows'];

// The sub-committee list, grouped by division, so ninety-six teams are
// findable. A team filed under the placeholder division groups under its own
// name and carries no prefix anywhere — that name is this application's
// bookkeeping and never travels (CLAUDE.md).
$grouped = [];
foreach ($subcommittees as $option) {
    $grouped[(string) $option['group']][] = $option;
}
?>
<h1>Roster Change Form</h1>
<p class="lede">
    Ask Rodeo Houston to add, remove, retitle or move members of one
    sub-committee. Up to <?= e((string) $rcf['rows']) ?> people at a time. It
    downloads as the same spreadsheet you fill in by hand, so it can be sent on
    as it is.
</p>

<?php if ($subcommittees === []) { ?>
    <div class="card">
        <p>
            No sub-committee holds anybody yet, so there is nothing to change.
            Import a roster first.
        </p>
    </div>
    <p><a href="<?= e($app->url('forms')) ?>">Back to Create Forms</a></p>
    <?php return; ?>
<?php } ?>

<form method="post" action="<?= e($app->url('form-rcf')) ?>">
    <?= Csrf::field() ?>
    <?php /* Phase 10.5: Enter in any field submits the FIRST submit button
             in the form, and until now that was "Load the team" — which
             threw away every row typed so far. This visually hidden button
             comes first so Enter downloads, the action a completed form
             wants. It is out of the tab order; the visible one below is
             the one a keyboard reaches. */ ?>
    <button type="submit" name="action" value="download" class="vh" tabindex="-1" aria-hidden="true">Download the form</button>

    <div class="card">
        <h2>About this form</h2>

        <label for="submitter">Name &amp; title of whom is submitting this form</label>
        <select id="submitter" name="submitter"><?php
            if ($officers === []) { ?><option value="">No officers are on the roster</option><?php }
            foreach ($officers as $officer) {
                // Name and title, and not the team: the title is the part the
                // form asks for and the part the "VC or higher" rule is read
                // against. A hundred and sixty-eight officers is a control
                // this page pays for twice (spec-v2 §2.4).
                ?><option value="<?= e((string) $officer['member_number']) ?>"<?=
                    (string) $officer['member_number'] === $submitterNumber ? ' selected' : '' ?>><?=
                    e(trim((string) $officer['name'] . ' — ' . (string) $officer['title'], ' —')) ?></option><?php
            } ?></select>
        <p class="hint">Defaults to you. Change it if you are filling this in for somebody else.</p>

        <label for="date">Date</label>
        <input type="date" id="date" name="date" value="<?= e((string) $rcf['date']) ?>">

        <label for="subcommittee">Sub-Committee</label>
        <select id="subcommittee" name="subcommittee"><option value="">Choose a sub-committee&hellip;</option><?php
            foreach ($grouped as $group => $options) {
                ?><optgroup label="<?= e($group === '' ? 'Ungrouped' : $group) ?>"><?php
                foreach ($options as $option) {
                    // The division is already the group heading, so the option
                    // shows the team alone — the same words, half the bytes,
                    // and a shorter line to read.
                    ?><option value="<?= e((string) $option['key']) ?>"<?=
                        $chosen !== null && $option['key'] === $chosen['key'] ? ' selected' : '' ?>><?=
                        e((string) $option['name']) ?> (<?= e(number_format((int) $option['members'])) ?>)</option><?php
                }
                ?></optgroup><?php
            } ?></select>
        <p class="hint">
            The sub-committee this form is about. Choosing it fills the member
            list below with the people you can see on it.
        </p>

        <button type="submit" name="action" value="load" class="quiet">
            Load this sub-committee
        </button>
    </div>

<?php if ($chosen === null) { ?>
    <div class="card">
        <p>
            Choose a sub-committee and press <strong>Load this sub-committee</strong>.
            Nothing you type is lost when you do &mdash; the whole form comes back
            with it.
        </p>
    </div>
<?php } else { ?>

    <?php /* The grid — datalists, the member-list card, the rows and the
             Add-rows button — is one partial shared with Create RCF
             Entries (Phase 14, spec-v2 §15.4). */ ?>
    <?php require __DIR__ . '/rcf-grid.php'; ?>

    <div class="card">
        <span class="chip chip-warn">This file is personal data</span>
        <p>
            It names the people on it and carries their member numbers, and it
            leaves this server the moment you download it. It is logged with your
            name, the sub-committee and the number of rows. Send it where it needs
            to go and delete your copy.
        </p>
        <button type="submit" name="action" value="download">Download the form</button>
    </div>

<?php } ?>
</form>

<?php /* Phase 10.5: the codes are the form's own footnotes, and on a desktop
         they sit beside the rows that use them — open, not behind a
         summary. On a phone the same content folds under a 56px label. */ ?>
<section class="codes">
    <input type="checkbox" id="fold-codes" class="fold vh">
    <label for="fold-codes" class="fold-label">What the codes mean</label>
    <div class="folded">
    <h2>What the codes mean</h2>
    <p class="hint">
        Printed on the form itself, so this is the same list the Division
        Chairman reads.
    </p>
    <h3>*Type</h3>
    <ul class="rows">
        <?php foreach ($rcf['types'] as $code => $description) { ?>
            <li><strong><?= e((string) $code) ?></strong> = <?= e((string) $description) ?></li>
        <?php } ?>
    </ul>
    <h3>**Remove reason</h3>
    <ul class="rows">
        <?php foreach ($rcf['reasons'] as $number => $reason) { ?>
            <li><strong><?= e((string) $number) ?>)</strong> <?= e((string) $reason) ?></li>
        <?php } ?>
    </ul>
    <h3>Wait list</h3>
    <p class="hint">
        Everyone submitted as an addition should already be on the Rodeo Express
        waitlist &mdash; they can add themselves from the Committee Volunteer
        Request tab of their HLSR member account, or by calling membership.
        Rodeo Express cannot guarantee an addition for anyone who is not on it,
        and the processing time increases.
    </p>
    <h3>Interview required or sponsored by</h3>
    <p class="hint">
        One of the two, for every new recruit: the date the interview was
        conducted, or the name of the officer sponsoring them, who must be a
        Vice Chairman or higher.
    </p>
    <h3>RE rookie</h3>
    <p class="hint">
        Tick it if they have never been on Rodeo Express before. It is a tick
        box on the form itself, which is why it is one here.
    </p>
    </div>
</section>

<p><a href="<?= e($app->url('forms')) ?>">Back to Create Forms</a></p>
