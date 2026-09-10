<?php

declare(strict_types=1);

/**
 * The second step of a purge (Phase 10.3): the members that were ticked,
 * by name, and the typed word. Rendered by purge_act() when a purge POST
 * arrives without the word — which is every purge, since the list page no
 * longer asks for it — and rendered again, with a notice and the same
 * names, when the word was wrong. The ids travel in hidden fields, so
 * nothing about the selection is lost between the two.
 *
 * The rows are Purge::preview()'s, which is the read apply() makes: what is
 * listed is exactly what the word will act on.
 *
 * @var Rerm\App       $app
 * @var Rerm\Auth\User $user
 * @var array<int, array<string, mixed>> $rows
 * @var string         $return   the list state, as the form carried it
 * @var string         $back     the list URL path to go back to
 */

use Rerm\Admin\Purge;
use Rerm\Csrf;

$number = static fn (int $n): string => number_format($n);
$n      = count($rows);
?>
<h1>Purge <?= e($number($n)) ?> <?= $n === 1 ? 'member' : 'members' ?>?</h1>
<p class="lede">
    A purge hides them from every roster and roll-up. It deletes nothing:
    their contact history, assignments and metrics stay exactly where they
    are, and Restore on the Purged list brings them back.
</p>

<div class="card">
    <ul class="rows">
        <?php foreach ($rows as $row) { ?>
            <li>
                <strong><?= e((string) $row['name']) ?></strong>
                &middot; <?= e((string) $row['member_number']) ?>
                <?php if ((int) $row['contact_count'] > 0 || (int) $row['assignment_count'] > 0) { ?>
                    <span class="why">&middot; keeps <?= e($number((int) $row['contact_count'])) ?>
                        contact<?= (int) $row['contact_count'] === 1 ? '' : 's' ?>,
                        <?= e($number((int) $row['assignment_count'])) ?>
                        assignment<?= (int) $row['assignment_count'] === 1 ? '' : 's' ?></span>
                <?php } ?>
            </li>
        <?php } ?>
    </ul>
</div>

<form method="post" action="<?= e($app->url('purge')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="purge">
    <input type="hidden" name="return" value="<?= e($return) ?>">
    <?php foreach ($rows as $row) { ?>
        <input type="hidden" name="member_id[]" value="<?= e((string) $row['id']) ?>">
    <?php } ?>

    <p>
        <label for="confirm">Type <code><?= e(Purge::CONFIRM_WORD) ?></code> to purge them</label><br>
        <input type="text" id="confirm" name="confirm" value=""
               autocomplete="off" spellcheck="false" inputmode="text" autofocus>
    </p>

    <button type="submit">Purge <?= e($number($n)) ?> <?= $n === 1 ? 'member' : 'members' ?></button>
</form>

<p class="hint"><a href="<?= e($app->url($back)) ?>">Cancel &mdash; keep them flagged</a></p>
