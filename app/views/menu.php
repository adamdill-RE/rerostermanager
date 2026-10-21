<?php

declare(strict_types=1);

use Rerm\Menu;

/**
 * The menu (spec 7.0) — its own route since Phase 5 moved the landing page
 * to My Roster Status. It still lands here for a signed-in user whose level
 * has no dashboard (a future Member-level Allowed User), and it is the one
 * place every screen carries a line on what it is FOR; the drop-down in the
 * bar (Phase 14.4) lists the same screens by name alone.
 *
 * The tiles and their groups are Rerm\Menu, written down once for both.
 * They are FILTERED by capability, and that is presentation only: every
 * target re-checks server-side through the same Access call. Hiding a tile
 * hides nothing.
 *
 * @var Rerm\App       $app
 * @var Rerm\Auth\User $user
 */
?>
<h1>Menu</h1>
<p class="lede">
    Signed in as <?= e($user->displayName) ?> —
    <?= e($user->level->label()) ?>, member number <?= e($user->memberNumber) ?>.
</p>

<?php foreach (Menu::forUser($user) as $group) { ?>
    <h2 class="menu-group"><?= e($group['heading']) ?></h2>
    <ul class="menu">
        <?php foreach ($group['tiles'] as $tile) { ?>
            <li>
                <?php if ($tile['route'] !== null) { ?>
                    <a href="<?= e($app->url($tile['route'])) ?>">
                        <span class="what"><?= e($tile['label']) ?></span>
                        <span class="why"><?= e($tile['why']) ?></span>
                    </a>
                <?php } else { ?>
                    <span class="what"><?= e($tile['label']) ?></span>
                    <span class="why">Arrives with <?= e($tile['phase']) ?>.</span>
                <?php } ?>
            </li>
        <?php } ?>
    </ul>
<?php } ?>

<h2 class="menu-group">Account</h2>
<ul class="menu">
    <li><a href="<?= e($app->url('password')) ?>"><span class="what">Change password</span>
        <span class="why">Signs out every other device this account is signed in on.</span></a></li>
</ul>

<form method="post" action="<?= e($app->url('logout')) ?>">
    <?= Rerm\Csrf::field() ?>
    <button type="submit" class="quiet">Sign out</button>
</form>
