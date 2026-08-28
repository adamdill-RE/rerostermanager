<?php

declare(strict_types=1);

/**
 * Export Roster (spec 7.5, Phase 8 decided 3) — one export, scoped to whoever
 * is asking, narrowed by team if they want.
 *
 * The screen's job is to say what the file will hold BEFORE it is built: the
 * exact row count, the breadth in the caller's own words, and the header row
 * itself. A download that turns out to carry 1,954 home addresses when
 * somebody expected 82 is what this page exists to prevent.
 *
 * The button is a POST with a CSRF token, not a link. The response body is
 * ~85 people's addresses and phone numbers, and it is logged as PII leaving
 * the building — neither of which belongs behind a GET an <img src> can send.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>  $export everything ExportPage::page() decided
 */

use Rerm\Csrf;

$number = static fn (int $n): string => number_format($n);
$year   = $export['year'];
$rows   = (int) $export['rows'];
?>
<h1>Export Roster</h1>
<p class="lede">
    Every member you can see, as a spreadsheet, for one show year. What you get
    is <?= e((string) $export['scope_word']) ?> &mdash; the same people every
    other screen shows you, in one file.
</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip chip-<?= e($level === 'ok' ? 'ok' : ($level === 'warn' ? 'warn' : 'danger')) ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<?php if ($year === null) { ?>
    <div class="card">
        <p>There is no show year to export. Create one first.</p>
    </div>
<?php } else { ?>

<div class="card">
    <span class="chip chip-warn">This file is personal data</span>
    <p>
        It carries <?= e($number($rows)) ?>
        <?= $rows === 1 ? 'person&rsquo;s' : 'people&rsquo;s' ?> home addresses,
        phone numbers and email addresses, and it leaves this server the moment you
        download it. It is logged with your name, your scope and the row count.
        Delete it when you are done with it, and do not forward it.
    </p>
</div>

<form method="get" action="<?= e($app->url('export')) ?>">
    <label for="year">Show year</label>
    <select id="year" name="year">
        <?php foreach ($export['years'] as $option) { ?>
            <option value="<?= e((string) $option['id']) ?>"
                <?= (int) $option['id'] === (int) $year['id'] ? ' selected' : '' ?>>
                <?= e((string) $option['label']) ?><?php
                if ((int) $option['is_active'] === 1) { ?> &mdash; active<?php }
                if ((int) $option['is_open'] === 0) { ?> (closed)<?php } ?>
            </option>
        <?php } ?>
    </select>

    <?php if ($export['can_filter_teams'] && $export['teams'] !== []) { ?>
        <fieldset>
            <legend>Narrow to particular teams (optional)</legend>
            <p class="hint">
                Leave every box clear to export <?= e((string) $export['scope_word']) ?>.
                Ticking teams can only ever narrow that &mdash; it never widens it.
            </p>
            <?php foreach ($export['teams'] as $team) { ?>
                <label class="choice" for="t<?= e((string) $team['id']) ?>">
                    <input type="checkbox" id="t<?= e((string) $team['id']) ?>"
                           name="team[]" value="<?= e((string) $team['id']) ?>"
                        <?= in_array((int) $team['id'], $export['selected_teams'], true) ? ' checked' : '' ?>>
                    <span>
                        <span class="what"><?= e((string) $team['name']) ?></span>
                        <span class="why"><?= e($number((int) $team['members'])) ?> members</span>
                    </span>
                </label>
            <?php } ?>
        </fieldset>
    <?php } ?>

    <button type="submit" class="quiet">Update the count</button>
</form>

<div class="card">
    <h2><?= e($number($rows)) ?> <?= $rows === 1 ? 'row' : 'rows' ?></h2>
    <p>
        <?php if ($rows === 0) { ?>
            Nothing matches. The file would be a header row and nothing else, so
            there is nothing to download.
        <?php } else { ?>
            <?= e($number($rows)) ?> <?= $rows === 1 ? 'member' : 'members' ?>
            &times; <?= e($number(count($export['columns']))) ?> columns, for show year
            <?= e((string) $year['label']) ?>.
        <?php } ?>
    </p>

    <?php if ($rows > 0) { ?>
        <form method="post" action="<?= e($app->url('export')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="year" value="<?= e((string) $year['id']) ?>">
            <?php foreach ($export['selected_teams'] as $teamId) { ?>
                <input type="hidden" name="team[]" value="<?= e((string) $teamId) ?>">
            <?php } ?>
            <button type="submit">Download the spreadsheet</button>
        </form>
    <?php } ?>
</div>

<details>
    <summary>What the columns are (<?= e($number(count($export['columns']))) ?>)</summary>
    <p class="hint">
        Rodeo Houston&rsquo;s own columns first, spelled exactly as they spell them,
        so the file reads back into this application and into theirs. Then what this
        application knows: the effective status of each requirement, the progress an
        officer recorded, who is assigned, and the last contact.
    </p>
    <ol>
        <?php foreach ($export['columns'] as $column) { ?>
            <li><?= e($column) ?></li>
        <?php } ?>
    </ol>
</details>

<?php } ?>

<p><a href="<?= e($app->url('menu')) ?>">Back to the menu</a></p>
