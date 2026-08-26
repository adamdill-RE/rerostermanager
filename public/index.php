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

    default:
        render($app, 'not-found', 'Not found', [], 404);
}
