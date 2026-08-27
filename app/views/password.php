<?php

declare(strict_types=1);

/**
 * Change password — including the FORCED first change (spec 3.2).
 *
 * When must_change_password is set, every other route redirects here,
 * direct URLs included; the guard lives in public/index.php, not in any
 * menu. The rules are short on purpose: length, and never the shipped
 * initial password. Complexity nobody can satisfy produces a sticky note.
 *
 * @var Rerm\App                             $app
 * @var array<int, array{0:string,1:string}> $notices
 * @var Rerm\Auth\User                       $user
 * @var bool                                 $forced   must_change_password
 * @var int                                  $minLength
 */
?>
<h1><?= $forced ? 'Choose your password' : 'Change password' ?></h1>
<p class="lede">
    <?php if ($forced) { ?>
        Signed in as <?= e($user->displayName) ?>. The initial password works
        exactly once — choose your own before anything else opens.
    <?php } else { ?>
        Signed in as <?= e($user->displayName) ?>. Changing it signs out every
        other device this account is signed in on.
    <?php } ?>
</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip <?= $level === 'ok' ? 'chip-ok' : ($level === 'warn' ? 'chip-warn' : 'chip-danger') ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Refused')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<form method="post" action="<?= e($app->url('password')) ?>">
    <?= Rerm\Csrf::field() ?>

    <p>
        <label for="current">Current password</label><br>
        <input type="password" id="current" name="current"
               autocomplete="current-password" required>
    </p>

    <p>
        <label for="password">New password</label><br>
        <input type="password" id="password" name="password"
               autocomplete="new-password" required minlength="<?= (int) $minLength ?>">
        <span class="hint why">At least <?= (int) $minLength ?> characters. A short phrase you can type on a phone works well.</span>
    </p>

    <p>
        <label for="password_confirm">New password, again</label><br>
        <input type="password" id="password_confirm" name="password_confirm"
               autocomplete="new-password" required minlength="<?= (int) $minLength ?>">
    </p>

    <button type="submit">Set password</button>
</form>

<?php if (!$forced) { ?>
    <footer><a href="<?= e($app->url()) ?>">Back to the menu</a></footer>
<?php } ?>
