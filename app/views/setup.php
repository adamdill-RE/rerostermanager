<?php

declare(strict_types=1);

/**
 * The bootstrap screen, behind app.setup_key.
 *
 * This host has no SSH and no shell, so `php bin/migrate.php` cannot be run on
 * it at all. Everything a fresh installation needs before anybody can sign in
 * has to be reachable from a browser, and this is that: apply the migrations,
 * then unlock the master administrator (spec 3.1).
 *
 * It is a genuine administrative credential — whoever holds app.setup_key can
 * take the Admin account — so the whole route stops existing the moment the
 * key is removed from config.local.php, which is what to do once the app is
 * running.
 *
 * @var Rerm\App                        $app
 * @var array<string, mixed>            $state
 * @var array<int, array{0:string,1:string}> $notices  [level, message]
 * @var string                          $key
 */

$chip = static function (string $state, string $word): string {
    $class = match ($state) {
        'ok'    => 'chip-ok',
        'warn'  => 'chip-warn',
        default => 'chip-danger',
    };

    return '<span class="chip ' . $class . '">' . e($word) . '</span>';
};
?>
<h1>Setup</h1>
<p class="lede">
    First-run tasks for an installation with no shell. Remove
    <code>setup_key</code> from <code>config.local.php</code> when you are done
    and this page stops existing.
</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <?= $chip($level, $level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Failed')) ?>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<div class="card">
    <h2>1 &middot; Database connection</h2>
    <dl class="facts">
        <dt>Host</dt>
        <dd class="mono"><?= e($state['db_host']) ?></dd>
        <dt>Schema</dt>
        <dd class="mono"><?= e($state['db_name']) ?></dd>
        <dt>User</dt>
        <dd class="mono"><?= e($state['db_user']) ?></dd>
        <dt>Status</dt>
        <dd>
            <?php if ($state['db_connected']) { ?>
                <?= $chip('ok', 'Connected') ?> <span class="mono"><?= e($state['db_version']) ?></span>
            <?php } else { ?>
                <?= $chip('danger', 'Unreachable') ?>
                <div class="mono hint"><?= e($state['db_error']) ?></div>
                <div class="hint">
                    Fix <code>config/config.local.php</code> before going on. If the message
                    mentions <code>unix_socket</code>, <code>db.host</code> is pointing at the
                    web server instead of the database server — that is the wrong machine, not a
                    wrong password, and no password reset can fix it.
                </div>
            <?php } ?>
        </dd>
    </dl>
</div>

<?php if ($state['db_connected']) { ?>
    <div class="card">
        <h2>2 &middot; Schema</h2>
        <?php if ($state['migrations_broken'] !== []) { ?>
            <p>
                <?= $chip('danger', 'Registry mismatch') ?>
                A migration changed after it was applied, or is recorded but missing.
                This needs a human — it is not safe to apply anything on top.
            </p>
            <div class="mono hint"><?= e(implode(', ', $state['migrations_broken'])) ?></div>
        <?php } elseif ($state['migrations_pending'] === []) { ?>
            <p>
                <?= $chip('ok', 'Up to date') ?>
                <?= e((string) $state['migrations_applied']) ?> migration(s) applied. Nothing to do.
            </p>
        <?php } else { ?>
            <p>
                <?= $chip('warn', 'Pending') ?>
                <?= e((string) count($state['migrations_pending'])) ?> migration(s) have never been applied:
            </p>
            <div class="mono hint"><?= e(implode(', ', $state['migrations_pending'])) ?></div>
            <form method="post" action="<?= e($app->url('setup')) ?>">
                <input type="hidden" name="key" value="<?= e($key) ?>">
                <input type="hidden" name="action" value="migrate">
                <button type="submit">Apply migrations</button>
            </form>
        <?php } ?>
    </div>

    <div class="card">
        <h2>3 &middot; Master administrator</h2>
        <?php if (!$state['admin_exists']) { ?>
            <p>
                <?= $chip('warn', 'Not yet seeded') ?>
                Apply the migrations above first — the account is created by
                <code>003_seed_master_admin.sql</code>.
            </p>
        <?php } else { ?>
            <dl class="facts">
                <dt>Member number</dt>
                <dd class="mono"><?= e($state['admin_member_number']) ?></dd>
                <dt>Password</dt>
                <dd>
                    <?php if ($state['admin_locked']) { ?>
                        <?= $chip('warn', 'Locked') ?> shipped unusable; set one below
                    <?php } else { ?>
                        <?= $chip('ok', 'Set') ?> this account can sign in once Phase 3 lands
                    <?php } ?>
                </dd>
            </dl>
            <form method="post" action="<?= e($app->url('setup')) ?>">
                <input type="hidden" name="key" value="<?= e($key) ?>">
                <input type="hidden" name="action" value="set-password">
                <p>
                    <label for="pw">New password</label><br>
                    <input type="password" id="pw" name="password" autocomplete="new-password" required>
                </p>
                <p>
                    <label for="pw2">Repeat it</label><br>
                    <input type="password" id="pw2" name="password_confirm" autocomplete="new-password" required>
                </p>
                <p class="hint">
                    At least <?= e((string) $state['min_password_length']) ?> characters, and not
                    <code>1234</code>. Length is what matters; a rule nobody can satisfy produces a
                    sticky note on a monitor.
                </p>
                <button type="submit"><?= $state['admin_locked'] ? 'Set the password' : 'Replace the password' ?></button>
            </form>
        <?php } ?>
    </div>
<?php } ?>

<footer>
    When the schema is applied and the password is set, delete the
    <code>setup_key</code> line from <code>config/config.local.php</code>. With
    no key configured this route returns 404 to everyone, including you.
</footer>
