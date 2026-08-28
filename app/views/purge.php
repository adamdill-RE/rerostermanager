<?php

declare(strict_types=1);

/**
 * Flagged for Purge (spec 6.5) — the members the last complete or team import
 * did not see, and the ones somebody has already purged.
 *
 * Two lists behind one toggle, because there are two jobs and the second one
 * only exists because of the first: an import does NOT clear `purged_at`, so
 * without Restore a mistaken purge is invisible forever.
 *
 * The interaction is deliberately heavier than every other screen in the app.
 * Per-member checkboxes rather than a "purge everything flagged" button, and
 * a word typed by hand before the purge runs. That is the point: 432 members
 * sit on thin teams and 72 have no division, and a bulk sweep over a list
 * nobody read is how they leave.
 *
 * Every rendered value goes through e(). Everything was decided in PurgePage
 * and Purge; this file derives nothing.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>  $purge everything PurgePage::page() decided
 */

use Rerm\Csrf;
use Rerm\View;

$number = static fn (int $n): string => number_format($n);
$isPurged = $purge['list'] === 'purged';

$href = static function (array $overrides = []) use ($app, $purge): string {
    $params = array_filter([
        'list' => $purge['list'] === 'flagged' ? '' : (string) $purge['list'],
        'page' => $purge['page'] > 1 ? (string) $purge['page'] : '',
        'size' => $purge['size'] !== $purge['size_default'] ? (string) $purge['size'] : '',
    ], static fn (string $v): bool => $v !== '');

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = (string) $value;
    }

    $query = http_build_query($params);

    return $app->url('purge') . ($query === '' ? '' : '?' . $query);
};
?>
<h1>Flagged for Purge</h1>
<p class="lede">
    An import flags the members it did not see. It never deletes one. Purging is
    this screen, done deliberately, and it is still a soft delete: contact
    history, assignments and metrics all survive it intact.
</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip chip-<?= e($level === 'ok' ? 'ok' : ($level === 'warn' ? 'warn' : 'danger')) ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<div class="toggle">
    <a href="<?= e($href(['list' => null, 'page' => null])) ?>"
       class="<?= $isPurged ? '' : 'current' ?>">
        Flagged
        <span class="n"><?= e($number($isPurged ? (int) $purge['other_total'] : (int) $purge['total'])) ?></span>
    </a>
    <a href="<?= e($href(['list' => 'purged', 'page' => null])) ?>"
       class="<?= $isPurged ? 'current' : '' ?>">
        Purged
        <span class="n"><?= e($number($isPurged ? (int) $purge['total'] : (int) $purge['other_total'])) ?></span>
    </a>
</div>

<?php if ($purge['total'] === 0) { ?>
    <div class="card">
        <p>
            <?php if ($isPurged) { ?>
                Nobody has been purged. There is nothing to restore.
            <?php } else { ?>
                No member is flagged. Every member on the roster was seen by the
                last complete or team import.
            <?php } ?>
        </p>
    </div>
<?php } else { ?>

<?php if (!$isPurged) { ?>
    <div class="card">
        <span class="chip chip-warn">Read this first</span>
        <p>
            A flag means one import did not list this member. That is not the same
            as them having left: a team-mode import lists one team, and a member
            who reappears in a later roster is un-flagged automatically. Purge the
            ones you know have gone, and leave the rest flagged.
        </p>
    </div>
<?php } ?>

<p class="lede">
    Showing <?= e($number((int) $purge['from'])) ?>&ndash;<?= e($number((int) $purge['to'])) ?>
    of <?= e($number((int) $purge['total'])) ?>
    <?php if ($purge['pages'] > 1) { ?>
        &middot; page <?= e($number((int) $purge['page'])) ?> of <?= e($number((int) $purge['pages'])) ?>
    <?php } ?>
    &middot;
    <?php if ($purge['size'] === $purge['size_default']) { ?>
        <a href="<?= e($href(['size' => $purge['size_large'], 'page' => null])) ?>">Show <?= e((string) $purge['size_large']) ?> per page</a>
    <?php } else { ?>
        <a href="<?= e($href(['size' => null, 'page' => null])) ?>">Show <?= e((string) $purge['size_default']) ?> per page</a>
    <?php } ?>
</p>

<form method="post" action="<?= e($app->url('purge')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= $isPurged ? 'restore' : 'purge' ?>">
    <input type="hidden" name="return" value="<?= e(http_build_query(array_filter([
        'list' => $isPurged ? 'purged' : '',
        'page' => $purge['page'] > 1 ? (string) $purge['page'] : '',
        'size' => $purge['size'] !== $purge['size_default'] ? (string) $purge['size'] : '',
    ], static fn (string $v): bool => $v !== ''))) ?>">

    <table class="assign">
        <thead>
            <tr>
                <th><span class="vh">Select</span></th>
                <th>Member</th>
                <th><?= $isPurged ? 'Purged' : 'Flagged by' ?></th>
                <th class="num">Kept</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($purge['rows'] as $row) { ?>
            <tr>
                <td class="pick" data-label="Select">
                    <input type="checkbox" name="member_id[]"
                           id="m<?= e((string) $row['id']) ?>"
                           value="<?= e((string) $row['id']) ?>">
                </td>
                <td class="who" data-label="Member">
                    <label for="m<?= e((string) $row['id']) ?>">
                        <?= e((string) $row['name']) ?>
                        <span class="off">
                            <?= e((string) $row['member_number']) ?>
                            &middot; <?= e($row['team_name'] === '' ? '(No team)' : (string) $row['team_name']) ?>
                            &middot; <?= e((string) $row['division_name']) ?>
                        </span>
                        <span class="off"><?= e((string) $row['title']) ?></span>
                    </label>
                </td>
                <td data-label="<?= $isPurged ? 'Purged' : 'Flagged by' ?>">
                    <?php if ($isPurged && $row['purged_at'] !== null) { ?>
                        <?php [$words, $absolute] = View::when($app, (string) $row['purged_at']); ?>
                        <span title="<?= e($absolute) ?>"><?= e($words) ?></span>
                    <?php } elseif ($row['batch_id'] !== null) { ?>
                        Import #<?= e((string) $row['batch_id']) ?>
                        <span class="off">
                            <?= e((string) $row['batch_mode']) ?> mode<?php
                            if ($row['batch_started_at'] !== null) { ?>,
                                <?= e($app->toDisplay((string) $row['batch_started_at'])->format('j M Y')) ?><?php } ?>
                        </span>
                        <?php if ($row['batch_filename'] !== '') { ?>
                            <span class="off"><?= e((string) $row['batch_filename']) ?></span>
                        <?php } ?>
                    <?php } else { ?>
                        &mdash;
                    <?php } ?>
                </td>
                <td class="num" data-label="Kept">
                    <?= e($number((int) $row['contact_count'])) ?> contact<?= $row['contact_count'] === 1 ? '' : 's' ?>,
                    <?= e($number((int) $row['assignment_count'])) ?> assignment<?= $row['assignment_count'] === 1 ? '' : 's' ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <div class="actionbar">
        <?php if ($isPurged) { ?>
            <p class="ab">
                <span>Restoring puts these members back on every roster and roll-up,
                    with everything they already had.</span>
                <button type="submit">Restore selected</button>
            </p>
        <?php } else { ?>
            <p class="ab">
                <label for="confirm">
                    Type <code><?= e((string) $purge['confirm_word']) ?></code> to purge the members you ticked
                </label>
                <input type="text" id="confirm" name="confirm" value=""
                       autocomplete="off" spellcheck="false"
                       inputmode="text" size="10">
            </p>
            <p class="ab">
                <span>
                    A purge hides them from every roster and roll-up. It deletes
                    nothing: their contact history, assignments and metrics stay
                    exactly where they are, and Restore brings them back.
                </span>
                <button type="submit">Purge selected</button>
            </p>
        <?php } ?>
    </div>
</form>

<?php if ($purge['pages'] > 1) { ?>
    <p class="lede">
        <?php if ($purge['page'] > 1) { ?>
            <a href="<?= e($href(['page' => $purge['page'] - 1])) ?>">&larr; Previous</a>
        <?php } ?>
        <?php if ($purge['page'] < $purge['pages']) { ?>
            <a href="<?= e($href(['page' => $purge['page'] + 1])) ?>">Next &rarr;</a>
        <?php } ?>
    </p>
<?php } ?>

<?php } ?>

<p><a href="<?= e($app->url('menu')) ?>">Back to the menu</a></p>
