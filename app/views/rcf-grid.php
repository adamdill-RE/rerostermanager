<?php

declare(strict_types=1);

/**
 * The Roster Change Form's grid (spec-v2 §2.3, §2.4), drawn ONCE for two
 * screens (Phase 14, spec-v2 §15.4): the form itself on /form-rcf, and
 * Create RCF Entries on /rcf-entries, where every filled row becomes one
 * entry instead of one line of a file. One partial, one `RcfPage::page()`,
 * so the two cannot drift — the same three shared datalists, the same five
 * rows growing five at a time, the same two tick boxes.
 *
 * Required from inside a <form>, after the sub-committee has been chosen:
 * the caller draws the heading, the buttons and the "nothing chosen yet"
 * card. Every rendered value goes through e().
 *
 * @var Rerm\App             $app
 * @var array<string, mixed> $rcf  everything RcfPage::page() decided
 */

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

$visibleRows = (int) $rcf['visible_rows'];
?>
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
