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

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip chip-<?= e($level === 'ok' ? 'ok' : ($level === 'warn' ? 'warn' : 'danger')) ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

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

    <?php
    // The three shared lists, emitted once each and used by all twenty-five
    // rows. See the file comment.
    ?>
    <datalist id="rcf-members"><?php foreach ($members as $member) {
        // The label carries the name officers actually use, and ONLY when it
        // differs from the one on the membership record — "Bud" is how they
        // will look him up and "Robert Alpha" is what goes on the form.
        $knownAs = (string) $member['known_as'] === (string) $member['form_name']
            ? '' : (string) $member['known_as'];
        ?><option value="<?= e((string) $member['member_number'] . ' - ' . (string) $member['form_name']) ?>"<?=
            $knownAs === '' ? '' : ' label="' . e($knownAs) . '"' ?>></option><?php
    } ?></datalist>

    <datalist id="rcf-subcommittees"><?php foreach ($subcommittees as $option) {
        ?><option value="<?= e((string) $option['label']) ?>"></option><?php
    } ?></datalist>

    <datalist id="rcf-officers"><?php foreach ($officers as $officer) {
        ?><option value="<?= e((string) $officer['name']) ?>"></option><?php
    } ?></datalist>

    <div class="card">
        <h2><?= e((string) $chosen['label']) ?></h2>
        <p>
            <?php if ($members === []) { ?>
                There is no member list for this sub-committee &mdash; either you
                can see nobody on it, or it is too large to offer as a list. Type
                each person as
                <strong>member number - name</strong> instead; the form takes it
                either way.
            <?php } else { ?>
                <?= e(number_format(count($members))) ?>
                <?= count($members) === 1 ? 'person is' : 'people are' ?>
                in the member list below &mdash; the ones you can see on this
                sub-committee. Somebody who is not on it yet is typed in:
                <strong>member number - name</strong>.
            <?php } ?>
        </p>
    </div>

    <?php
    // The three <select> option lists, built ONCE per possible selection
    // rather than once per row. Twenty-five rows of controls is the whole
    // weight of this page, and spec §10's budget is 100KB on first paint —
    // which is why the labels below are terse, the shared lists are
    // datalists, and every control is named by aria-label rather than by a
    // <label> element it would have to be given an id to reach.
    $options = static function (array $choices, string $selected): string {
        $html = '<option value="">&mdash;</option>';
        foreach ($choices as $value => $label) {
            $html .= '<option value="' . e((string) $value) . '"'
                . ((string) $value === $selected ? ' selected' : '') . '>'
                . e((string) $label) . '</option>';
        }

        return $html;
    };

    $typeChoices = [];
    foreach ($rcf['types'] as $code => $description) {
        $typeChoices[$code] = $code . ' — ' . $description;
    }

    $reasonChoices = [];
    foreach ($rcf['reasons'] as $number => $reason) {
        $reasonChoices[$number] = $number . ') ' . $reason;
    }

    /** A text field in a grid cell. Never given an id: nothing points at one. */
    $field = static function (
        int $i,
        int $n,
        string $name,
        string $label,
        string $value,
        string $list = '',
        string $placeholder = ''
    ): string {
        return '<input type="text" name="row[' . $i . '][' . $name . ']"'
            . ' aria-label="' . e($label . ', row ' . $n) . '"'
            . ($value === '' ? '' : ' value="' . e($value) . '"')
            . ($list === '' ? '' : ' list="' . $list . '" autocomplete="off"')
            . ($placeholder === '' ? '' : ' placeholder="' . e($placeholder) . '"')
            . '>';
    };
    ?>
    <table class="rcf">
        <caption>
            One row per person. Leave the rest blank &mdash; blank rows print
            blank, exactly as they do on the paper form.
        </caption>
        <colgroup>
            <col style="width:3%"><col style="width:6%"><col style="width:5%">
            <col style="width:19%"><col style="width:11%"><col style="width:11%">
            <col style="width:4%"><col style="width:12%"><col style="width:14%">
            <col style="width:15%">
        </colgroup>
        <thead>
            <tr>
                <th scope="col"><span class="vh">Row</span>#</th>
                <th scope="col">*Type</th>
                <th scope="col">RE rookie</th>
                <th scope="col">Member &mdash; number and name</th>
                <th scope="col">Change/add title</th>
                <th scope="col">Previous title</th>
                <th scope="col">Wait list</th>
                <th scope="col">**Remove reason</th>
                <th scope="col">New sub-committee</th>
                <th scope="col">Interview or sponsor</th>
            </tr>
        </thead>
        <tbody>
<?php foreach (array_slice($entries, 0, $visibleRows, true) as $i => $entry) { $n = $i + 1; ?>
<tr><td class="n" data-label="Row"><?= e((string) $n) ?>)</td>
<td data-label="*Type"><select name="row[<?= $i ?>][type]" aria-label="Type, row <?= $n ?>"><?=
    $options($typeChoices, (string) $entry['type']) ?></select></td>
<td class="tick" data-label="RE rookie"><input type="checkbox" name="row[<?= $i ?>][rookie]"
    value="<?= e(RosterChangeForm::TICKED) ?>" aria-label="Rodeo Express rookie, row <?= $n ?>"<?=
    (string) $entry['rookie'] === RosterChangeForm::TICKED ? ' checked' : '' ?>></td>
<td data-label="Member"><?= $field($i, $n, 'member', 'Member', (string) $entry['member'],
    'rcf-members', '1234567 - Jane Smith') ?></td>
<td data-label="Change/add title"><?= $field($i, $n, 'new_title', 'New title',
    (string) $entry['new_title']) ?></td>
<td data-label="Previous title"><?= $field($i, $n, 'previous_title', 'Previous title',
    (string) $entry['previous_title'], '', 'from the roster') ?></td>
<td class="tick" data-label="Wait list"><input type="checkbox" name="row[<?= $i ?>][wait_list]"
    value="<?= e(RosterChangeForm::TICKED) ?>" aria-label="On the wait list, row <?= $n ?>"<?=
    (string) $entry['wait_list'] === RosterChangeForm::TICKED ? ' checked' : '' ?>></td>
<td data-label="**Remove reason"><select name="row[<?= $i ?>][remove_reason]"
    aria-label="Remove reason, row <?= $n ?>"><?=
    $options($reasonChoices, (string) $entry['remove_reason']) ?></select></td>
<td data-label="New sub-committee"><?= $field($i, $n, 'new_subcommittee', 'New sub-committee',
    (string) $entry['new_subcommittee'], 'rcf-subcommittees') ?></td>
<td data-label="Interview or sponsor"><?= $field($i, $n, 'sponsor', 'Interview or sponsor',
    (string) $entry['sponsor'], 'rcf-officers', 'date, or an officer') ?></td>
</tr>
<?php } ?>
        </tbody>
    </table>

    <input type="hidden" name="visible" value="<?= e((string) $visibleRows) ?>">

<?php if ($visibleRows < (int) $rcf['rows']) { ?>
    <p class="hint">
        <?= e((string) $visibleRows) ?> of <?= e((string) $rcf['rows']) ?> rows shown.
        Rows you never fill in print blank, so there is nothing to lose by
        leaving them off.
    </p>
    <button type="submit" name="action" value="rows" class="quiet">
        Add <?= e((string) Rerm\Forms\RcfPage::VISIBLE_STEP) ?> more rows
    </button>
<?php } ?>

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

<details>
    <summary>What the codes mean</summary>
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
</details>

<p><a href="<?= e($app->url('forms')) ?>">Back to Create Forms</a></p>
