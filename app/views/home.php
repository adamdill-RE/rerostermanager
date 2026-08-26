<?php

declare(strict_types=1);

/**
 * What / serves until Phase 3 puts a login screen here.
 *
 * It exists because the alternative is a 403: .htaccess sets
 * DirectoryIndex index.php and Options -Indexes, so a mount directory with no
 * index.php is a forbidden directory listing rather than an empty app. A
 * holding page also gives the deploy something to verify against — "does the
 * subpath serve at all" is a different question from "does the roster work",
 * and it should be answerable before there is a roster.
 *
 * Nothing here reads the database or names a member. It is public.
 *
 * @var Rerm\App $app
 */
?>
<h1>Rodeo Express Roster Management</h1>
<p class="lede">
    Compliance tracking for the Rodeo Express Committee. This installation is
    up, and sign-in arrives with Phase&nbsp;3.
</p>

<div class="card">
    <h2>What is running</h2>
    <dl class="facts">
        <dt>Application</dt>
        <dd>Installed and serving</dd>
        <dt>Database schema</dt>
        <dd>Phase&nbsp;1 — tables, reference data, master administrator</dd>
        <dt>Sign-in</dt>
        <dd>Phase&nbsp;3 — not yet built</dd>
    </dl>
</div>

<footer>
    Administrators: the deployment health check is at
    <code><?= e($app->url('status')) ?></code>, and it answers only with the
    configured key.
</footer>
