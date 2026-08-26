<?php

declare(strict_types=1);

/**
 * The front controller. Everything under the mount point arrives here.
 *
 * This is the ONLY .php file that ships to the document root; .htaccess
 * rewrites every request that is not a real file to it, and everything it
 * needs lives outside public_html entirely (docs/hosting.md).
 *
 * Two routes for now. Phase 3 replaces the match below with real routing and
 * a session guard; until then the point of this file is that the mount point
 * SERVES. Without it, DirectoryIndex finds no index.php, Options -Indexes
 * refuses the listing, and the deploy answers 403 — which reads like a
 * permissions problem and is not one.
 */

$app = require locate_app_root() . '/app/bootstrap.php';

/**
 * Finds the application root, which is NOT a parent of this file on the
 * server.
 *
 * DOCUMENT_ROOT is public_html itself, so everything beside the mount
 * directory is web-reachable and app/ has to sit outside it altogether. The
 * layout is a sibling:
 *
 *     /home/reshiftmanager/rerm-app/           app/ bin/ db/ config/ var/
 *     /home/reshiftmanager/public_html/rerm/   this file
 *
 * Locally, docker mirrors that exactly rather than being convenient, so the
 * same probe answers in both places and there is nothing to configure. A plain
 * checkout served straight from public/ is the third case.
 */
function locate_app_root(): string
{
    $candidates = [];

    // An explicit answer always wins, for a layout nobody anticipated.
    $configured = getenv('RERM_APP_ROOT');
    if (is_string($configured) && $configured !== '') {
        $candidates[] = rtrim($configured, '/');
    }

    // A sibling of the document root — the server, and docker.
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($documentRoot) && $documentRoot !== '') {
        $candidates[] = dirname(rtrim($documentRoot, '/')) . '/rerm-app';
    }

    // public/ as a direct child of the checkout.
    $candidates[] = dirname(__DIR__);

    foreach ($candidates as $candidate) {
        if (is_file($candidate . '/app/bootstrap.php')) {
            return $candidate;
        }
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The application directory was not found.\n\n"
        . "It is a SIBLING of the document root, not a parent of this file, and it holds\n"
        . "app/, bin/, db/ and config/. Set RERM_APP_ROOT if it lives somewhere else.\n\n"
        . 'Looked in: ' . implode(', ', $candidates) . "\n";
    exit(1);
}

/** Renders a view inside the shell. Views receive $app and their own data. */
function render(Rerm\App $app, string $view, string $title, array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');

    // Nothing here is cacheable: .htaccess already sets text/html to zero
    // seconds so a deploy is visible on the next request, and this restates it
    // for anything between the browser and LiteSpeed.
    header('Cache-Control: no-store');

    // Defence in depth for a server-rendered app with no inline scripts and no
    // third-party assets. It costs nothing and it is one fewer thing to
    // remember when the first real screen ships.
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');

    extract($data, EXTR_SKIP);

    ob_start();
    require $app->path('app/views/' . $view . '.php');
    $body = (string) ob_get_clean();

    require $app->path('app/views/layout.php');
}

/**
 * Is the caller allowed to see /status?
 *
 * Constant-time, and a missing key means the route does not exist rather than
 * that it exists and was refused. app.status_key ships null, so a fresh deploy
 * has no health check until somebody configures one in config.local.php.
 */
function status_permitted(Rerm\App $app): bool
{
    if ($app->debug()) {
        return true;
    }

    $configured = $app->config()->get('app.status_key', null);
    if (!is_string($configured) || $configured === '') {
        return false;
    }

    $supplied = $_GET['key'] ?? '';

    return is_string($supplied) && hash_equals($configured, $supplied);
}

/** Everything /status reports, gathered without letting one failure hide the rest. */
function status_checks(Rerm\App $app): array
{
    $config = $app->config();

    $checks = [
        'generated_at'            => Rerm\App::now(),
        'php_matches_production'  => str_starts_with(PHP_VERSION, '8.2.'),
        'document_root'           => (string) ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown'),
        'db_host'                 => (string) $config->get('db.host'),
        'db_name'                 => (string) $config->get('db.name'),
        'db_connected'            => false,
        'db_version'              => '',
        'db_time_zone'            => '',
        'db_error'                => '',
        'migrations_applied'      => 0,
        'migrations_pending'      => [],
        'migrations_broken'       => [],
        'mail_can_deliver'        => $config->get('mail.enabled') === true
                                     && $config->get('mail.transport') === 'send',
        'mail_transport'          => (string) $config->get('mail.transport'),
        'mail_allowlist'          => count((array) $config->get('mail.allowed_recipients', [])),
        'writable'                => [],
    ];

    foreach (['var', 'var/sessions', 'var/imports', 'var/mail'] as $relative) {
        $checks['writable'][$relative] = is_dir($app->path($relative))
            && is_writable($app->path($relative));
    }

    try {
        $pdo = $app->db();
        $checks['db_connected'] = true;
        $checks['db_version']   = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $checks['db_time_zone'] = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();

        foreach ($app->migrator()->status() as $migration) {
            match ($migration['state']) {
                'applied' => $checks['migrations_applied']++,
                'pending' => $checks['migrations_pending'][] = $migration['filename'],
                default   => $checks['migrations_broken'][] = $migration['state']
                             . ' ' . $migration['filename'],
            };
        }
    } catch (Throwable $e) {
        // The database being unreachable is the single most likely reason
        // somebody is reading this page, so it renders rather than throws.
        $checks['db_error'] = $e->getMessage();
    }

    return $checks;
}

/**
 * The bootstrap credential, from the POST body when there is one.
 *
 * /setup is the only way to apply a migration on this host — there is no SSH
 * and no shell — so it is also the only route that can change the database.
 * That makes app.setup_key a genuine administrative credential rather than a
 * convenience, and it ships null so the route does not exist until somebody
 * deliberately creates one.
 *
 * There is no debug bypass here, unlike /status: a route that can set the
 * Admin password should be reachable one way only.
 */
function setup_key_supplied(): string
{
    $supplied = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['key'] ?? '')
        : ($_GET['key'] ?? '');

    return is_string($supplied) ? $supplied : '';
}

function setup_permitted(Rerm\App $app): bool
{
    $configured = $app->config()->get('app.setup_key', null);
    if (!is_string($configured) || $configured === '') {
        return false;
    }

    return hash_equals($configured, setup_key_supplied());
}

/** Everything /setup needs to describe the installation. */
function setup_state(Rerm\App $app): array
{
    $config = $app->config();

    $state = [
        'db_host'             => (string) $config->get('db.host'),
        'db_name'             => (string) $config->get('db.name'),
        'db_user'             => (string) $config->get('db.user'),
        'db_connected'        => false,
        'db_version'          => '',
        'db_error'            => '',
        'migrations_applied'  => 0,
        'migrations_pending'  => [],
        'migrations_broken'   => [],
        'admin_exists'        => false,
        'admin_locked'        => true,
        'admin_member_number' => Rerm\App::MASTER_ADMIN_NUMBER,
        'min_password_length' => (int) $config->get('auth.min_password_length'),
    ];

    try {
        $pdo = $app->db();
        $state['db_connected'] = true;
        $state['db_version']   = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        foreach ($app->migrator()->status() as $migration) {
            match ($migration['state']) {
                'applied' => $state['migrations_applied']++,
                'pending' => $state['migrations_pending'][] = $migration['filename'],
                default   => $state['migrations_broken'][] = $migration['state']
                             . ' ' . $migration['filename'],
            };
        }

        $hash = master_admin_hash($app);
        if ($hash !== null) {
            $state['admin_exists'] = true;
            // Locked means the shipped sentinel: not a hash of anything, so
            // password_verify() refuses every input including the sentinel.
            $state['admin_locked'] = password_get_info($hash)['algo'] === null;
        }
    } catch (Throwable $e) {
        $state['db_error'] = $e->getMessage();
    }

    return $state;
}

/** The master administrator's stored hash, or null when the row is not there yet. */
function master_admin_hash(Rerm\App $app): ?string
{
    $read = $app->db()->prepare(
        'SELECT u.password_hash FROM app_user u '
        . 'INNER JOIN member m ON m.id = u.member_id '
        . 'WHERE m.member_number = :number'
    );
    $read->execute([':number' => Rerm\App::MASTER_ADMIN_NUMBER]);

    $hash = $read->fetchColumn();

    return is_string($hash) ? $hash : null;
}

/**
 * Applies the migrations, or sets the administrator password.
 *
 * Only on POST, and only with the key in the request BODY — so the credential
 * that performs a mutation never travels in a URL, where it would reach the
 * access log and any Referer header.
 *
 * @return array<int, array{0: string, 1: string}> notices
 */
function setup_act(Rerm\App $app): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [];
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'migrate') {
        try {
            $applied = $app->migrator()->migrate();

            return $applied === []
                ? [['warn', 'Nothing was pending; the schema was already up to date.']]
                : [['ok', 'Applied ' . implode(', ', $applied) . '.']];
        } catch (Throwable $e) {
            return [['danger', 'Migration failed: ' . $e->getMessage()]];
        }
    }

    if ($action === 'set-password') {
        return [setup_set_admin_password($app)];
    }

    return [];
}

/** @return array{0: string, 1: string} */
function setup_set_admin_password(Rerm\App $app): array
{
    $config    = $app->config();
    $password  = (string) ($_POST['password'] ?? '');
    $confirm   = (string) ($_POST['password_confirm'] ?? '');
    $minimum   = (int) $config->get('auth.min_password_length');

    if ($password !== $confirm) {
        return ['danger', 'The two passwords did not match. Nothing was changed.'];
    }
    if (strlen($password) < $minimum) {
        return ['danger', "The password must be at least {$minimum} characters. Nothing was changed."];
    }
    if ($password === (string) $config->get('auth.default_password')) {
        return ['danger', 'That is the shipped placeholder password. Choose another one.'];
    }

    try {
        $pdo = $app->db();

        if (master_admin_hash($app) === null) {
            return ['danger', 'The master administrator does not exist yet — apply the migrations first.'];
        }

        $hash = password_hash(
            $password,
            (string) $config->get('auth.password_algo'),
            ['cost' => (int) $config->get('auth.password_cost')]
        );

        $update = $pdo->prepare(
            'UPDATE app_user u '
            . 'INNER JOIN member m ON m.id = u.member_id '
            . 'SET u.password_hash = :hash, '
            . '    u.must_change_password = 0, '
            . '    u.password_changed_at = UTC_TIMESTAMP() '
            . 'WHERE m.member_number = :number'
        );
        $update->execute([':hash' => $hash, ':number' => Rerm\App::MASTER_ADMIN_NUMBER]);

        // Logged, because every credential change is. The actor is null: no
        // signed-in user did this, the setup key did, and saying so is more
        // honest than attributing it to the account it just unlocked.
        $audit = $pdo->prepare(
            'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, after_json, ip) '
            . 'VALUES (NULL, :action, :entity, :entity_id, :after_json, :ip)'
        );
        $audit->execute([
            ':action'     => 'set_master_password',
            ':entity'     => 'app_user',
            ':entity_id'  => Rerm\App::MASTER_ADMIN_NUMBER,
            ':after_json' => '{"source":"setup route","password":"set by an operator holding app.setup_key"}',
            ':ip'         => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);

        return ['ok', 'The master administrator password is set. Remove setup_key from config.local.php now.'];
    } catch (Throwable $e) {
        return ['danger', 'Could not set the password: ' . $e->getMessage()];
    }
}

switch ($app->requestPath()) {
    case '':
        render($app, 'home', 'Roster Management');
        break;

    case 'status':
        if (!status_permitted($app)) {
            render($app, 'not-found', 'Not found', [], 404);
            break;
        }
        render($app, 'status', 'Status', ['checks' => status_checks($app)]);
        break;

    case 'setup':
        if (!setup_permitted($app)) {
            render($app, 'not-found', 'Not found', [], 404);
            break;
        }
        // The action runs BEFORE the state is read, so the page reflects what
        // just happened rather than what was true when the form was drawn.
        $notices = setup_act($app);
        render($app, 'setup', 'Setup', [
            'state'   => setup_state($app),
            'notices' => $notices,
            'key'     => setup_key_supplied(),
        ]);
        break;

    default:
        render($app, 'not-found', 'Not found', [], 404);
}
