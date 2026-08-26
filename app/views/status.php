<?php

declare(strict_types=1);

/**
 * The deployment health check, behind app.status_key.
 *
 * It exists because the only other way to answer "did that deploy land, and
 * can it reach the database" on this host is cPanel Terminal, and the person
 * asking is often looking at a white page on a phone. Every check below is one
 * that has actually gone wrong on this account or its sibling.
 *
 * It shows no credentials and no member data, but it does describe the
 * installation, which is why it is key-guarded and why a wrong key 404s rather
 * than 403s — a 403 confirms the route exists.
 *
 * @var Rerm\App                  $app
 * @var array<string, mixed>      $checks
 */

/** A chip is a word plus a colour, never a colour alone. */
$chip = static function (string $state, string $word): string {
    $class = match ($state) {
        'ok'     => 'chip-ok',
        'warn'   => 'chip-warn',
        default  => 'chip-danger',
    };

    return '<span class="chip ' . $class . '">' . e($word) . '</span>';
};
?>
<h1>Status</h1>
<p class="lede">Deployment health. Not a monitoring endpoint — read it when something looks wrong.</p>

<div class="card">
    <h2>Runtime</h2>
    <dl class="facts">
        <dt>PHP</dt>
        <dd>
            <span class="mono"><?= e(PHP_VERSION) ?></span>
            <?php if ($checks['php_matches_production']) { ?>
                <?= $chip('ok', 'Matches production') ?>
            <?php } else { ?>
                <?= $chip('warn', 'Differs from production') ?>
            <?php } ?>
        </dd>
        <dt>SAPI</dt>
        <dd class="mono"><?= e(PHP_SAPI) ?></dd>
        <dt>Application root</dt>
        <dd class="mono"><?= e($app->root()) ?></dd>
        <dt>Document root</dt>
        <dd class="mono"><?= e($checks['document_root']) ?></dd>
        <dt>Mount point</dt>
        <dd class="mono"><?= e($app->url()) ?></dd>
        <dt>Debug</dt>
        <dd>
            <?php if ($app->debug()) { ?>
                <?= $chip('danger', 'On') ?> never leave this on here
            <?php } else { ?>
                <?= $chip('ok', 'Off') ?>
            <?php } ?>
        </dd>
    </dl>
</div>

<div class="card">
    <h2>Database</h2>
    <dl class="facts">
        <dt>Host</dt>
        <dd class="mono"><?= e($checks['db_host']) ?></dd>
        <dt>Schema</dt>
        <dd class="mono"><?= e($checks['db_name']) ?></dd>
        <dt>Connection</dt>
        <dd>
            <?php if ($checks['db_connected']) { ?>
                <?= $chip('ok', 'Connected') ?>
                <span class="mono"><?= e($checks['db_version']) ?></span>
            <?php } else { ?>
                <?= $chip('danger', 'Unreachable') ?>
                <div class="mono hint"><?= e($checks['db_error']) ?></div>
            <?php } ?>
        </dd>
        <?php if ($checks['db_connected']) { ?>
            <dt>Session time zone</dt>
            <dd>
                <span class="mono"><?= e($checks['db_time_zone']) ?></span>
                <?= $checks['db_time_zone'] === '+00:00' ? $chip('ok', 'UTC') : $chip('danger', 'Not UTC') ?>
            </dd>
            <dt>Migrations</dt>
            <dd>
                <?php if ($checks['migrations_pending'] === [] && $checks['migrations_broken'] === []) { ?>
                    <?= $chip('ok', 'Up to date') ?>
                    <?= e((string) $checks['migrations_applied']) ?> applied
                <?php } elseif ($checks['migrations_broken'] !== []) { ?>
                    <?= $chip('danger', 'Registry mismatch') ?>
                    <div class="mono hint">
                        <?= e(implode(', ', $checks['migrations_broken'])) ?>
                    </div>
                <?php } else { ?>
                    <?= $chip('warn', 'Pending') ?>
                    <div class="mono hint">
                        <?= e(implode(', ', $checks['migrations_pending'])) ?>
                    </div>
                    <div class="hint">
                        Migrations are never applied by a deploy. Run
                        <code>php bin/migrate.php --status</code> first.
                    </div>
                <?php } ?>
            </dd>
        <?php } ?>
    </dl>
</div>

<div class="card">
    <h2>Writable directories</h2>
    <dl class="facts">
        <?php foreach ($checks['writable'] as $label => $writable) { ?>
            <dt class="mono"><?= e($label) ?></dt>
            <dd><?= $writable ? $chip('ok', 'Writable') : $chip('danger', 'Not writable') ?></dd>
        <?php } ?>
    </dl>
</div>

<div class="card">
    <h2>Mail</h2>
    <dl class="facts">
        <dt>Delivery</dt>
        <dd>
            <?php if ($checks['mail_can_deliver']) { ?>
                <?= $chip('warn', 'Armed') ?> this installation can send email
            <?php } else { ?>
                <?= $chip('ok', 'Disabled') ?> nothing can leave this machine
            <?php } ?>
        </dd>
        <dt>Transport</dt>
        <dd class="mono"><?= e($checks['mail_transport']) ?></dd>
        <dt>Allowlist</dt>
        <dd><?= $checks['mail_allowlist'] === 0 ? 'empty — every recipient permitted' : e((string) $checks['mail_allowlist']) . ' address(es) permitted' ?></dd>
    </dl>
</div>

<footer>Generated <?= e($checks['generated_at']) ?> UTC.</footer>
