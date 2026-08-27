<?php

declare(strict_types=1);

/**
 * Password recovery, step one (spec 3.3).
 *
 * The response is ALWAYS the same sentence, whether or not the member number
 * has an account: an answer that varies is an enumeration oracle over a 6–7
 * digit space. The one deliberate exception, made by the spec itself: a
 * member who HAS an account but NO email on file gets told so — a silent
 * success there would leave an officer waiting on an email that can never
 * come, with no way to learn why.
 *
 * @var Rerm\App                             $app
 * @var array<int, array{0:string,1:string}> $notices
 * @var bool                                 $sent     show the identical response
 * @var bool                                 $noEmail  account exists, no address on file
 * @var string                               $memberNumber
 */
?>
<h1>Forgot password</h1>
<p class="lede">
    Enter your member number and a reset link goes to the email address the
    roster has on file for it.
</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip <?= $level === 'ok' ? 'chip-ok' : ($level === 'warn' ? 'chip-warn' : 'chip-danger') ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Refused')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<?php if ($noEmail) { ?>
    <div class="card">
        <span class="chip chip-warn">No email on file</span>
        <p>
            The roster has no email address for member number
            <strong><?= e($memberNumber) ?></strong>, so a reset link has
            nowhere to go. Contact an officer on your team — an Admin can help
            you back into the account.
        </p>
    </div>
<?php } elseif ($sent) { ?>
    <div class="card">
        <span class="chip chip-ok">Requested</span>
        <p>
            If that member number has an account with an email on file, a
            reset link is on its way. It works once, for 60 minutes. The email
            names the member number it applies to — check it if your household
            shares an inbox.
        </p>
    </div>
<?php } else { ?>
    <form method="post" action="<?= e($app->url('forgot')) ?>">
        <?= Rerm\Csrf::field() ?>

        <p>
            <label for="member_number">Member number</label><br>
            <input type="text" id="member_number" name="member_number"
                   value="<?= e($memberNumber) ?>"
                   inputmode="numeric" autocomplete="username" autofocus required>
        </p>

        <button type="submit">Email me a reset link</button>
    </form>
<?php } ?>

<footer>
    <a href="<?= e($app->url('login')) ?>">Back to sign in</a>
</footer>
