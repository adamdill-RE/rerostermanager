<?php

declare(strict_types=1);

/**
 * Password recovery, step two — the emailed link (spec 3.3).
 *
 * The token travels in the URL only on the GET that the email link makes;
 * the POST that changes anything carries it in the body, beside the CSRF
 * token. A spent or expired link says so and points back at /forgot rather
 * than guessing at what the visitor meant.
 *
 * @var Rerm\App                             $app
 * @var array<int, array{0:string,1:string}> $notices
 * @var ?string                              $token    valid token, or null
 * @var string                               $memberNumber  whose password this sets
 * @var bool                                 $done     password was changed
 * @var int                                  $minLength
 */
?>
<h1>Reset password</h1>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip <?= $level === 'ok' ? 'chip-ok' : ($level === 'warn' ? 'chip-warn' : 'chip-danger') ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Refused')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<?php if ($done) { ?>
    <div class="card">
        <span class="chip chip-ok">Password set</span>
        <p>
            The password for member number <strong><?= e($memberNumber) ?></strong>
            is changed, and every device that was signed in has been signed
            out. Sign in with the new one.
        </p>
    </div>
    <p><a href="<?= e($app->url('login')) ?>">Go to sign in</a></p>
<?php } elseif ($token === null) { ?>
    <div class="card">
        <span class="chip chip-danger">Link not usable</span>
        <p>
            This reset link has expired, has already been used, or was not
            issued by this application. Links work once, for 60 minutes.
        </p>
    </div>
    <p><a href="<?= e($app->url('forgot')) ?>">Request a new link</a></p>
<?php } else { ?>
    <p class="lede">
        This sets a new password for member number
        <strong><?= e($memberNumber) ?></strong> only.
    </p>

    <form method="post" action="<?= e($app->url('reset')) ?>">
        <?= Rerm\Csrf::field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <p>
            <label for="password">New password</label><br>
            <input type="password" id="password" name="password"
                   autocomplete="new-password" required minlength="<?= (int) $minLength ?>" autofocus>
            <span class="hint why">At least <?= (int) $minLength ?> characters.</span>
        </p>

        <p>
            <label for="password_confirm">New password, again</label><br>
            <input type="password" id="password_confirm" name="password_confirm"
                   autocomplete="new-password" required minlength="<?= (int) $minLength ?>">
        </p>

        <button type="submit">Set password</button>
    </form>
<?php } ?>
