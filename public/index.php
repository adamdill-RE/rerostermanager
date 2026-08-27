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

/**
 * Teams, for the team-mode picker.
 *
 * Capped, and deliberately: 96 teams is a long <select> but it is one input,
 * where a control per team would be 96 — and max_input_vars is 1000 with
 * silent truncation past it.
 *
 * @return array<int, array<string, mixed>>
 */
function import_teams(Rerm\App $app): array
{
    try {
        return $app->db()->query(
            'SELECT t.id, t.name, COUNT(m.id) AS members '
            . 'FROM team t LEFT JOIN member m ON m.team_id = t.id AND m.is_system = 0 AND m.purged_at IS NULL '
            . 'WHERE t.is_active = 1 GROUP BY t.id, t.name ORDER BY t.name'
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
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

// ---------------------------------------------------------------------------
// Import (spec 6)
// ---------------------------------------------------------------------------

/**
 * May the caller reach /import?
 *
 * PHASE 3: replace with Capability::IMPORT_ROSTER, which is Admin-only and
 * everywhere-scoped. Two lines: this function becomes a level check against
 * the signed-in user, and the key inputs come out of the form.
 *
 * Until then it is app.setup_key, the same credential /setup uses, because
 * there is no login yet to put anything behind. That is not a placeholder for
 * its own sake: this server has NO SSH and NO cPanel Terminal, so the CLI
 * importer cannot be run on it at all, and a roster import that only works on
 * a laptop is a roster import production does not have. A key-guarded screen
 * is the only thing that can load 1,954 members onto the live site today.
 *
 * The key is a genuine administrative credential either way — anyone holding
 * it can rewrite the whole roster — so it ships null and the route does not
 * exist until somebody configures one.
 */
function import_permitted(Rerm\App $app): bool
{
    $configured = $app->config()->get('app.setup_key', null);
    if (!is_string($configured) || $configured === '') {
        return false;
    }

    return hash_equals($configured, import_key_supplied());
}

/**
 * The import key, from the POST body if it survived and the query string if it
 * did not.
 *
 * Separate from setup_key_supplied() because of one measured behaviour: when a
 * request body exceeds post_max_size, PHP DISCARDS $_POST and $_FILES and
 * carries on. The key goes with them. Read only from the body, an Admin
 * uploading a roster over the limit gets a 404 — the screen simply
 * disappears — which is the least diagnosable failure this page could produce,
 * on the host with the tightest upload ceiling.
 *
 * The fallback costs nothing here that has not already been spent. The only
 * way to reach this form at all is GET /rerm/import?key=…, so the key is
 * already in the URL, already in the access log, and already in history. That
 * is NOT true of /setup, whose POST carries no query string and whose key
 * therefore never appears in a URL for a mutation — which is why that function
 * is left exactly as it is rather than being widened to cover both.
 */
function import_key_supplied(): string
{
    $body = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['key'] ?? '') : '';
    if (is_string($body) && $body !== '') {
        return $body;
    }

    $query = $_GET['key'] ?? '';

    return is_string($query) ? $query : '';
}

/** Why an entire request body went missing, in words an Admin can act on. */
function import_oversize_message(): string
{
    $sent = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    return sprintf(
        'The upload was larger than this server accepts (post_max_size is %s; you sent %s). '
        . 'PHP discards the whole request body when that happens, including the form itself — '
        . 'so this is a size problem, not a session problem, however it looks. A .csv of the '
        . 'same roster is roughly a third the size of a .xls and imports identically.',
        (string) ini_get('post_max_size'),
        $sent > 0 ? number_format($sent / 1048576, 1) . 'M' : 'more'
    );
}

/**
 * The uploaded roster's temporary path, or a message saying what went wrong.
 *
 * Every branch exists because of a measured limit on this host
 * (docs/hosting.md). The body-too-large case is handled earlier, in
 * import_act(), because by the time execution reaches here $_POST has already
 * had to survive.
 *
 * @return array{0: ?string, 1: string} path, error
 */
function import_uploaded_file(): array
{
    $limit = (string) ini_get('upload_max_filesize');

    $file = $_FILES['roster'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, 'Choose a roster file first.'];
    }

    $error = (int) $file['error'];
    if ($error !== UPLOAD_ERR_OK) {
        return [null, match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'That file is larger than %s, which is this server\'s upload ceiling. The sample '
                . '.xls is 1.2M and its .csv equivalent is about 0.4M — re-saving as CSV is the '
                . 'quickest way past this, and all three formats import identically.',
                $limit
            ),
            UPLOAD_ERR_PARTIAL   => 'The upload was cut off part way. Try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload to disk.',
            default              => 'The upload failed (error ' . $error . ').',
        }];
    }

    $path = (string) ($file['tmp_name'] ?? '');
    if ($path === '' || !is_uploaded_file($path)) {
        return [null, 'That was not an uploaded file.'];
    }

    return [$path, ''];
}

/**
 * Runs the import action, if the request is one.
 *
 * The roster is read straight from PHP's own temporary upload and never
 * copied into var/imports. Nothing needs it after parsing — the staged rows
 * are what the apply reads — and the file is ~1,950 people's home addresses,
 * phone numbers and email addresses. The safest place for it is nowhere, and
 * the sha256 on the batch still answers "have we imported this exact file
 * before" without keeping a byte of it.
 *
 * @return array{notices: array<int, array{0: string, 1: string}>, batch: ?int}
 */
function import_act(Rerm\App $app): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return ['notices' => [], 'batch' => null];
    }

    // FIRST, before anything reads $_POST. When a request body exceeds
    // post_max_size PHP discards $_POST and $_FILES entirely and carries on,
    // so an oversized roster arrives looking like a form that was never
    // submitted — and, one check later, like a CSRF failure. Saying so here is
    // the difference between "your file is too big" and an Admin investigating
    // their session for an afternoon.
    if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        return ['notices' => [['danger', import_oversize_message()]], 'batch' => null];
    }

    $action = $_POST['action'] ?? '';
    if ($action === '') {
        return ['notices' => [], 'batch' => null];
    }

    // Reaching the route proves nothing. Every POST checks the token.
    if (!Rerm\Csrf::check()) {
        return ['notices' => [['danger', 'That form was stale or came from somewhere else. '
            . 'Nothing was changed — reload this page and try again.']], 'batch' => null];
    }

    $importer = Rerm\Import\Importer::fromApp($app);

    try {
        if ($action === 'stage') {
            [$path, $error] = import_uploaded_file();
            if ($path === null) {
                return ['notices' => [['danger', $error]], 'batch' => null];
            }

            $mode   = (string) ($_POST['mode'] ?? Rerm\Import\Importer::MODE_COMPLETE);
            $teamId = $mode === Rerm\Import\Importer::MODE_TEAM && ($_POST['team_id'] ?? '') !== ''
                ? (int) $_POST['team_id']
                : null;

            $name = (string) ($_FILES['roster']['name'] ?? 'roster');
            // basename only: the browser sends whatever it likes here, and
            // this string is stored and rendered.
            $batchId = $importer->stage($path, basename($name), $mode, $teamId);

            return [
                'notices' => [['ok', 'Read and staged. NOTHING has been written to the roster yet — '
                    . 'this is the diff, and the button at the bottom is what applies it.']],
                'batch'   => $batchId,
            ];
        }

        if ($action === 'apply') {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $result  = $importer->apply($batchId);

            return [
                'notices' => [['ok', sprintf(
                    'Applied. %s created, %s updated, %s unchanged, %s flagged absent, %s account(s) '
                    . 'created or changed, %s metric(s) reset to Not started because they moved N to Y.',
                    number_format($result['created']),
                    number_format($result['updated']),
                    number_format($result['unchanged']),
                    number_format($result['absent']),
                    number_format($result['accounts']),
                    number_format($result['progress_reset'])
                )]],
                'batch'   => $batchId,
            ];
        }

        if ($action === 'discard') {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $importer->discard($batchId);

            return ['notices' => [['warn', "Staged batch {$batchId} was discarded. Nothing was written."]], 'batch' => null];
        }
    } catch (Rerm\Import\ImportException $e) {
        return ['notices' => [['danger', $e->getMessage()]], 'batch' => null];
    } catch (Throwable $e) {
        return ['notices' => [['danger', 'The import failed: ' . $e->getMessage()]], 'batch' => null];
    }

    return ['notices' => [], 'batch' => null];
}

switch ($app->requestPath()) {
    case '':
        render($app, 'home', 'Roster Management');
        break;

    case 'import':
        if (!import_permitted($app)) {
            render($app, 'not-found', 'Not found', [], 404);
            break;
        }

        Rerm\Session::start($app);

        $importer = Rerm\Import\Importer::fromApp($app);
        // A stale preview was computed against a roster that has since
        // changed, so applying it would write a diff nobody has read.
        $importer->discardExpired();

        $outcome = import_act($app);

        $batchId = $outcome['batch'] ?? null;
        if ($batchId === null && isset($_GET['batch'])) {
            $batchId = (int) $_GET['batch'];
        }

        $preview = null;
        if ($batchId !== null && $batchId > 0) {
            try {
                $preview = $importer->preview($batchId);
            } catch (Rerm\Import\ImportException $e) {
                $outcome['notices'][] = ['warn', $e->getMessage()];
            }
        }

        render($app, 'import', 'Import Roster', [
            // A 1,954-row diff is data, not a list of choices (spec 8.2).
            'wide'    => true,
            'notices' => $outcome['notices'],
            'preview' => $preview,
            'staged'  => $importer->stagedBatches(10),
            'applied' => $importer->appliedBatches(5),
            'teams'   => import_teams($app),
            'key'     => import_key_supplied(),
        ]);
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
