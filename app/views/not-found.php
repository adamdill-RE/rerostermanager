<?php

declare(strict_types=1);

/**
 * The page for "there is nothing here for you" — and, since Phase 10.3, it
 * says WHICH of three things that means, because one sentence for all of
 * them was wrong for two of the people reading it:
 *
 *   missing  a path that does not exist, a wrong status or setup key, a
 *            member outside the caller's scope. Deliberately incurious: it
 *            does not say whether the thing exists and is forbidden or does
 *            not exist at all, and it never will.
 *   refused  a signed-in person whose level does not open this screen. The
 *            menu already shows which screens exist, so nothing is given
 *            away by saying so — and "nothing at this address" taught an
 *            Officer who tapped a leadership link to distrust the page.
 *   no_year  a roster screen with no active show year to work inside. An
 *            Admin on a fresh install read "nothing at this address" on
 *            their own dashboard; now they read what to do about it.
 *
 * @var Rerm\App             $app
 * @var ?Rerm\Auth\User      $user
 * @var string               $reason  missing | refused | no_year
 */

use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\User;

$reason   = $reason ?? 'missing';
$signedIn = isset($user) && $user instanceof User;
?>
<?php if ($reason === 'refused' && $signedIn) { ?>
    <h1>Not open to your level</h1>
    <p class="lede">
        This screen is for a level above <?= e($user->level->label()) ?>. Nothing
        you can see is affected, and everything your level opens is on the menu.
    </p>
    <p><a href="<?= e($app->url('menu')) ?>">Back to the menu</a></p>
<?php } elseif ($reason === 'no_year' && $signedIn) { ?>
    <h1>No show year is active</h1>
    <p class="lede">
        Every roster screen works inside the active show year, and no year is
        active yet, so there is nothing to show.
    </p>
    <?php if (Access::mayUse($user, Capability::ManageShowYear)) { ?>
        <p><a href="<?= e($app->url('show-year')) ?>">Open Show Year and make one active</a></p>
    <?php } else { ?>
        <p>An Admin makes a show year active. Until then, nothing here has changed.</p>
        <p><a href="<?= e($app->url('menu')) ?>">Back to the menu</a></p>
    <?php } ?>
<?php } else { ?>
    <h1>Not found</h1>
    <p class="lede">There is nothing at this address.</p>
    <p><a href="<?= e($app->url()) ?>">Back to the start</a></p>
<?php } ?>
