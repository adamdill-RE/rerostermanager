<?php

declare(strict_types=1);

/**
 * Sign in (spec 3.1).
 *
 * By MEMBER NUMBER, never email: email is not unique in this roster — two
 * addresses are shared by four people — and one member has none at all
 * (docs/data-findings.md 5). The number is on every officer's badge paperwork
 * and is the one identifier that is theirs alone.
 *
 * The failure message never says which half was wrong, and is identical for
 * a member number that has no account at all: an answer that varies is an
 * oracle for walking the 6–7 digit number space.
 *
 * @var Rerm\App                             $app
 * @var array<int, array{0:string,1:string}> $notices  [level, message]
 * @var string                               $memberNumber  sticky on failure
 */
?>
<h1>Sign in</h1>
<p class="lede">Rodeo Express Roster — officers and designated users only.</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip <?= $level === 'ok' ? 'chip-ok' : ($level === 'warn' ? 'chip-warn' : 'chip-danger') ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Refused')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<form method="post" action="<?= e($app->url('login')) ?>">
    <?= Rerm\Csrf::field() ?>

    <p>
        <label for="member_number">Member number</label><br>
        <input type="text" id="member_number" name="member_number"
               value="<?= e($memberNumber) ?>"
               inputmode="numeric" autocomplete="username" autofocus required>
    </p>

    <p>
        <label for="password">Password</label><br>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
    </p>

    <label class="choice">
        <input type="checkbox" name="remember" value="1">
        <span>
            <span class="what">Keep me signed in</span>
            <span class="why">90 days on this device. Leave it off on a shared one.</span>
        </span>
    </label>

    <button type="submit">Sign in</button>
</form>

<footer>
    <a href="<?= e($app->url('forgot')) ?>">Forgot your password?</a>
</footer>
