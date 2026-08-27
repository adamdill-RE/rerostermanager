<?php

declare(strict_types=1);

/**
 * The front controller. Everything under the mount point arrives here.
 *
 * This is the ONLY .php file that ships to the document root; .htaccess
 * rewrites every request that is not a real file to it, and everything it
 * needs lives outside public_html entirely (docs/hosting.md).
 *
 * Since Phase 3, dispatch is guarded: every route is declared in Rerm\Routes
 * beside the guard it requires, and a path that table does not name is a 404
 * before any handler runs. tests/auth_test.php enumerates both the table and
 * the dispatch arms below, so a route cannot be added here without deciding,
 * in writing, who may reach it.
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
 * A See Other after a state change, or away from a screen the caller may not
 * see. Always through $app->url(): a Location built by hand lands on the
 * domain root, which is the landing page and RESM.
 */
function redirect(Rerm\App $app, string $path = ''): never
{
    header('Location: ' . $app->url($path), true, 303);
    exit;
}

/** The requesting address, as every throttle, token and audit row records it. */
function request_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** The one refusal every POST shares when its CSRF token is stale or absent. */
function stale_form_notice(): array
{
    return ['danger', 'That form was stale or came from somewhere else. '
        . 'Nothing was changed — reload this page and try again.'];
}

// ---------------------------------------------------------------------------
// Identity (spec 3)
// ---------------------------------------------------------------------------

/**
 * The login attempt, if this request is one.
 *
 * The refusal is one sentence and never says which half was wrong — or
 * whether the member number has an account at all. An answer that varies is
 * an oracle for walking a 6–7 digit number space that currently holds 196
 * accounts. For the same reason a member number with no account still costs
 * a bcrypt verification, so the two refusals take the same time.
 *
 * @return array{notices: array<int, array{0:string,1:string}>, member_number: string}
 */
function login_act(Rerm\App $app, Rerm\Auth\Auth $auth): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return ['notices' => [], 'member_number' => ''];
    }

    $number = trim((string) ($_POST['member_number'] ?? ''));

    if (!Rerm\Csrf::check()) {
        return ['notices' => [stale_form_notice()], 'member_number' => $number];
    }

    $password = (string) ($_POST['password'] ?? '');
    $remember = ($_POST['remember'] ?? '') === '1';
    $ip       = request_ip();

    // Both limbs (spec 3.5): this IP, and this member number from anywhere.
    // An attempt the throttle refuses is not recorded — it proved nothing
    // about the password, and counting it would hold the lockout open for as
    // long as anybody hammers.
    $throttle = Rerm\Auth\LoginThrottle::fromApp($app);
    $wait     = $throttle->retryAfter($ip, $number);
    if ($wait !== null) {
        return ['notices' => [['danger', sprintf(
            'Too many failed attempts. Wait %d second%s and try again.',
            $wait,
            $wait === 1 ? '' : 's'
        )]], 'member_number' => $number];
    }

    $passwords = Rerm\Auth\Password::fromApp($app);

    $read = $app->db()->prepare(
        'SELECT u.id, u.password_hash, u.must_change_password '
        . 'FROM app_user u INNER JOIN member m ON m.id = u.member_id '
        . 'WHERE m.member_number = :number AND u.is_active = 1'
    );
    $read->execute([':number' => $number]);
    $account = $read->fetch();

    if (!is_array($account)) {
        // No account — verify against a throwaway hash so this branch costs
        // what a wrong password costs. Derived at most once per process, and
        // of a value nobody can know; no hash is committed anywhere.
        static $decoy = null;
        $decoy ??= $passwords->hash(bin2hex(random_bytes(16)));
        $passwords->verify($password, $decoy);
    }

    if (!is_array($account) || !$passwords->verify($password, (string) $account['password_hash'])) {
        $throttle->recordFailure($ip, $number);

        return ['notices' => [['danger', 'That member number and password did not match.']],
            'member_number' => $number];
    }

    $throttle->recordSuccess($ip, $number);

    // The one moment the plaintext is legitimately in hand: bring the stored
    // hash up to the configured cost if it lags.
    if ($passwords->needsRehash((string) $account['password_hash'])) {
        $app->db()->prepare('UPDATE app_user SET password_hash = :hash WHERE id = :id')
            ->execute([':hash' => $passwords->hash($password), ':id' => (int) $account['id']]);
    }

    $auth->signIn((int) $account['id'], $remember);

    // The forced first change (spec 3.2). The redirect is a convenience; the
    // guard that actually pins every route to /password is in the dispatch
    // below, so a typed URL changes nothing.
    redirect($app, (int) $account['must_change_password'] === 1 ? 'password' : '');
}

/**
 * The password change — voluntary, and the forced first one (spec 3.2).
 *
 * @return array<int, array{0:string,1:string}> notices
 */
function password_act(Rerm\App $app, Rerm\Auth\Auth $auth, Rerm\Auth\User $user): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [];
    }
    if (!Rerm\Csrf::check()) {
        return [stale_form_notice()];
    }

    $current = (string) ($_POST['current'] ?? '');
    $new     = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    $passwords = Rerm\Auth\Password::fromApp($app);

    $read = $app->db()->prepare('SELECT password_hash FROM app_user WHERE id = :id');
    $read->execute([':id' => $user->id]);
    $hash = (string) $read->fetchColumn();

    if (!$passwords->verify($current, $hash)) {
        return [['danger', 'The current password did not match. Nothing was changed.']];
    }
    if ($new !== $confirm) {
        return [['danger', 'The two new passwords did not match. Nothing was changed.']];
    }
    $problem = $passwords->problemWith($new);
    if ($problem !== null) {
        return [['danger', $problem . ' Nothing was changed.']];
    }

    $app->db()->prepare(
        'UPDATE app_user SET password_hash = :hash, must_change_password = 0, '
        . 'password_changed_at = UTC_TIMESTAMP() WHERE id = :id'
    )->execute([':hash' => $passwords->hash($new), ':id' => $user->id]);

    // Every OTHER session (spec 3.2): the device standing at this form keeps
    // its login; a phone left on a bar loses its.
    Rerm\Auth\TokenStore::fromApp($app)->revokeAllFor($user->id, $auth->currentTokenId());

    $app->db()->prepare(
        'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, after_json, ip) '
        . 'VALUES (:actor, :action, :entity, :entity_id, :after_json, :ip)'
    )->execute([
        ':actor'      => $user->id,
        ':action'     => 'password_changed',
        ':entity'     => 'app_user',
        ':entity_id'  => (string) $user->id,
        ':after_json' => '{"other_sessions":"revoked"}',
        ':ip'         => request_ip(),
    ]);

    redirect($app);
}

/**
 * The recovery request (spec 3.3).
 *
 * The response is ALWAYS the same sentence whether or not an account exists —
 * with the spec's one deliberate exception: an account with no email on file
 * is told so, because a silent success there strands an officer waiting on
 * mail that can never arrive.
 *
 * @return array{notices: array<int, array{0:string,1:string}>, sent: bool, no_email: bool, member_number: string}
 */
function forgot_act(Rerm\App $app): array
{
    $none = ['notices' => [], 'sent' => false, 'no_email' => false, 'member_number' => ''];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $none;
    }

    $number = trim((string) ($_POST['member_number'] ?? ''));

    if (!Rerm\Csrf::check()) {
        return ['notices' => [stale_form_notice()], 'sent' => false, 'no_email' => false,
            'member_number' => $number];
    }

    $read = $app->db()->prepare(
        'SELECT u.id, m.email, m.member_number FROM app_user u '
        . 'INNER JOIN member m ON m.id = u.member_id '
        . 'WHERE m.member_number = :number AND u.is_active = 1'
    );
    $read->execute([':number' => $number]);
    $account = $read->fetch();

    if (is_array($account)) {
        $email = trim((string) ($account['email'] ?? ''));

        if ($email === '') {
            return ['notices' => [], 'sent' => false, 'no_email' => true, 'member_number' => $number];
        }

        $resets  = Rerm\Auth\PasswordReset::fromApp($app);
        $ceiling = (int) $app->config()->get('auth.max_outstanding_resets', 3);

        // Silently stop issuing past the ceiling: the screen's answer never
        // changes, but a replayed form must not fill a household inbox.
        if ($resets->outstandingFor((int) $account['id']) < $ceiling) {
            $token   = $resets->issue((int) $account['id'], request_ip());
            $minutes = (int) $app->config()->get('auth.reset_token_minutes', 60);

            // Absolute, from configuration — never from the Host header,
            // which whoever sent the request controls.
            $host = rtrim((string) $app->config()->get('app.canonical_url', ''), '/');
            $link = $host . $app->url('reset') . '?token=' . rawurlencode($token);

            // The subject and first line NAME the member number: two inboxes
            // in this roster serve two members each, holding different
            // titles, and an unqualified email hands the wrong account to
            // whoever opens it first (docs/data-findings.md 5).
            Rerm\Mail\Mailer::fromApp($app)->send(
                $email,
                Rerm\Auth\PasswordReset::emailSubject((string) $account['member_number']),
                Rerm\Auth\PasswordReset::emailBody((string) $account['member_number'], $link, $minutes)
            );

            $app->db()->prepare(
                'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, after_json, ip) '
                . 'VALUES (NULL, :action, :entity, :entity_id, :after_json, :ip)'
            )->execute([
                ':action'     => 'password_reset_requested',
                ':entity'     => 'app_user',
                ':entity_id'  => (string) (int) $account['id'],
                ':after_json' => '{"delivery":"per mail interlocks"}',
                ':ip'         => request_ip(),
            ]);
        }
    }

    return ['notices' => [], 'sent' => true, 'no_email' => false, 'member_number' => $number];
}

/**
 * The emailed link (spec 3.3): render the form on GET, spend the token on
 * POST. Spending is a compare-and-swap in PasswordReset::consume(), so the
 * same link submitted twice changes exactly one password.
 *
 * @return array{notices: array<int, array{0:string,1:string}>, token: ?string, member_number: string, done: bool}
 */
function reset_act(Rerm\App $app): array
{
    $resets = Rerm\Auth\PasswordReset::fromApp($app);

    $supplied = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['token'] ?? '')
        : ($_GET['token'] ?? '');
    $supplied = is_string($supplied) ? $supplied : '';

    $row = $supplied === '' ? null : $resets->validate($supplied);
    if ($row === null) {
        return ['notices' => [], 'token' => null, 'member_number' => '', 'done' => false];
    }

    $read = $app->db()->prepare(
        'SELECT m.member_number FROM app_user u INNER JOIN member m ON m.id = u.member_id '
        . 'WHERE u.id = :id'
    );
    $read->execute([':id' => (int) $row['user_id']]);
    $memberNumber = (string) $read->fetchColumn();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return ['notices' => [], 'token' => $supplied, 'member_number' => $memberNumber, 'done' => false];
    }

    if (!Rerm\Csrf::check()) {
        return ['notices' => [stale_form_notice()], 'token' => $supplied,
            'member_number' => $memberNumber, 'done' => false];
    }

    $new     = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    $passwords = Rerm\Auth\Password::fromApp($app);
    if ($new !== $confirm) {
        return ['notices' => [['danger', 'The two passwords did not match. Nothing was changed.']],
            'token' => $supplied, 'member_number' => $memberNumber, 'done' => false];
    }
    $problem = $passwords->problemWith($new);
    if ($problem !== null) {
        return ['notices' => [['danger', $problem . ' Nothing was changed.']],
            'token' => $supplied, 'member_number' => $memberNumber, 'done' => false];
    }

    if (!$resets->consume((int) $row['id'])) {
        // Lost a race with another submission of the same link.
        return ['notices' => [], 'token' => null, 'member_number' => '', 'done' => false];
    }

    $app->db()->prepare(
        'UPDATE app_user SET password_hash = :hash, must_change_password = 0, '
        . 'password_changed_at = UTC_TIMESTAMP() WHERE id = :id'
    )->execute([':hash' => $passwords->hash($new), ':id' => (int) $row['user_id']]);

    // ALL sessions, not all-but-one: none of them is the person standing at
    // this form, and if the reset was hostile they are exactly what must die.
    Rerm\Auth\TokenStore::fromApp($app)->revokeAllFor((int) $row['user_id']);

    $app->db()->prepare(
        'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, after_json, ip) '
        . 'VALUES (NULL, :action, :entity, :entity_id, :after_json, :ip)'
    )->execute([
        ':action'     => 'password_reset_completed',
        ':entity'     => 'app_user',
        ':entity_id'  => (string) (int) $row['user_id'],
        ':after_json' => '{"all_sessions":"revoked"}',
        ':ip'         => request_ip(),
    ]);

    return ['notices' => [], 'token' => $supplied, 'member_number' => $memberNumber, 'done' => true];
}

// ---------------------------------------------------------------------------
// /status
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// /setup
// ---------------------------------------------------------------------------

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
            ':ip'         => request_ip(),
        ]);

        return ['ok', 'The master administrator password is set. Remove setup_key from config.local.php now.'];
    } catch (Throwable $e) {
        return ['danger', 'Could not set the password: ' . $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// View My Roster (spec 7.2) — Officer and above, through Capability::ViewRoster
// ---------------------------------------------------------------------------

/**
 * The active show year, which everything metric-, contact- and
 * assignment-related is keyed to. The schema enforces exactly one active row
 * (spec 5.2a), so a missing one means a database that was never seeded —
 * worth a sentence rather than a blank page.
 *
 * @return ?array{id: int, label: string, is_open: bool}
 */
function active_show_year(Rerm\App $app): ?array
{
    $row = $app->db()->query('SELECT id, label, is_open FROM show_year WHERE is_active = 1')->fetch();

    return is_array($row)
        ? [
            'id'      => (int) $row['id'],
            'label'   => (string) $row['label'],
            // The write paths re-read this before writing; the screens use it
            // to say a closed year is read-only instead of offering forms
            // whose submissions would be refused.
            'is_open' => (int) $row['is_open'] === 1,
        ]
        : null;
}

// ---------------------------------------------------------------------------
// My Roster Status (spec 7.1) — Officer and above, through
// Capability::ViewStatusDashboard, and the landing screen since Phase 5
// ---------------------------------------------------------------------------

/**
 * A one-request notice carried across the POST → 303 → GET boundary in the
 * session, so log-contact can confirm on the page the officer lands back on.
 * Taken exactly once: the reload after that is clean.
 *
 * @return array<int, array{0: string, 1: string}>
 */
function flash_take(): array
{
    $notice = Rerm\Session::get('flash_notice');
    Rerm\Session::forget('flash_notice');

    return is_array($notice) ? [$notice] : [];
}

function flash_set(string $kind, string $message): void
{
    Rerm\Session::set('flash_notice', [$kind, $message]);
}

/**
 * The list state a log-contact form carries so its 303 lands the officer
 * back on the exact filtered page they acted from — whitelisted here, never
 * echoed into a Location header raw.
 *
 * @param array<string, mixed> $input
 */
function dashboard_return_query(array $input): string
{
    $params = [];
    if (($input['mode'] ?? '') === 'mine' || ($input['mode'] ?? '') === 'team') {
        $params['mode'] = $input['mode'];
    }
    if (($input['show'] ?? '') === 'all') {
        $params['show'] = 'all';
    }
    $page = (int) ($input['page'] ?? 1);
    if ($page > 1) {
        $params['page'] = $page;
    }
    $size = (int) ($input['size'] ?? 0);
    if ($size > 0) {
        $params['size'] = $size;
    }

    $query = http_build_query($params);

    return $query === '' ? '' : '?' . $query;
}

/** Renders My Roster Status — the 'dashboard' route, and the landing page. */
function dashboard_screen(Rerm\App $app, Rerm\Auth\User $user): void
{
    $year = active_show_year($app);
    if ($year === null) {
        render($app, 'not-found', 'Not found', [], 404);

        return;
    }

    render($app, 'dashboard', 'My Roster Status', [
        // A data screen (spec 8.2): the wide container above 720px.
        'wide'    => true,
        'user'    => $user,
        'year'    => $year,
        'notices' => flash_take(),
        // 'statusPage', not 'status': render() extracts with EXTR_SKIP and
        // its own int $status parameter would win that collision.
        'statusPage' => Rerm\Roster\StatusPage::fromApp($app)->page($user, $year['id'], $_GET),
    ]);
}

/**
 * The log-contact POST (spec 7.1, decided 2): CSRF, then the write through
 * Rerm\Roster\LogContact — which re-checks Access::allows() with a Subject
 * per member — then a 303 back to the same filtered page. The one outcome
 * that is not a redirect is not_found: an out-of-scope or non-existent
 * member gets the same 404 a typed URL would, because this application does
 * not discuss what exists with people who cannot see it.
 */
function log_contact_act(Rerm\App $app, Rerm\Auth\User $user): never
{
    // The form carries its list state as ONE hidden query string (fifty
    // forms a page — one field beats four); parsed here and then whitelisted
    // like any other input.
    $state = [];
    parse_str(is_string($_POST['return'] ?? null) ? $_POST['return'] : '', $state);
    $return = 'dashboard' . dashboard_return_query($state);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect($app, 'dashboard');
    }

    if (!Rerm\Csrf::check()) {
        flash_set(...stale_form_notice());
        redirect($app, $return);
    }

    $result  = Rerm\Roster\LogContact::fromApp($app)->log($user, $_POST);
    $outcome = $result['outcome'];

    // An if-chain, not a switch: tests/auth_test.php reads every `case '…':`
    // in this file as a route label, and these outcomes are not routes.
    if ($outcome === 'logged') {
        $message = 'Contact with ' . $result['member_name'] . ' is logged.';
        if ($result['progress_changes'] > 0) {
            $message .= sprintf(
                ' %d progress status%s updated.',
                $result['progress_changes'],
                $result['progress_changes'] === 1 ? '' : 'es'
            );
        }
        flash_set('ok', $message);
        redirect($app, $return);
    }

    if ($outcome === 'year_closed') {
        flash_set('danger', 'This show year is closed and read-only. Nothing was logged.');
        redirect($app, $return);
    }

    if ($outcome === 'bad_type') {
        flash_set('danger', 'Choose how the contact happened. Nothing was logged.');
        redirect($app, $return);
    }

    // no_year, not_found: an out-of-scope or non-existent member gets the
    // same 404 a typed URL would.
    render($app, 'not-found', 'Not found', [], 404);
    exit;
}

// ---------------------------------------------------------------------------
// Import (spec 6) — Admin only since Phase 3, through Capability::ImportRoster
// ---------------------------------------------------------------------------

/**
 * Is the database actually carrying the schema this code expects?
 *
 * Deploying is a file copy and applying a migration is a separate, deliberate
 * act — correctly, because a deploy that migrated itself would change the live
 * roster's schema the moment a file landed, with nobody watching. The gap
 * between the two is real, and this is what an Admin walks into during it.
 *
 * Without this check they walk into a BLANK 500: /import queries a column that
 * migration 005 adds, display_errors is Off on the server, and the page comes
 * back zero bytes long. There is no shell on this host to read a log with, so
 * a blank page is the end of the road. It is the same failure Phase 0 shipped
 * — a mount point answering 403 that read like a permissions problem — and it
 * costs four lines to turn into a sentence naming the fix.
 *
 * @return ?string null when the schema is current, else what to do about it
 */
function import_schema_blocker(Rerm\App $app): ?string
{
    try {
        $pending = $app->migrator()->pending();
    } catch (Throwable $e) {
        return 'The database could not be reached, so this screen cannot tell whether the schema '
            . 'is up to date. Check /status for the connection.' . "\n\n" . $e->getMessage();
    }

    if ($pending === []) {
        return null;
    }

    return sprintf(
        "The code on this server is newer than its database: %d migration(s) have never been "
        . "applied (%s).\n\nThis screen reads columns those migrations add, so it would fail "
        . "with a blank page rather than an error you could read. Apply them first, from /setup "
        . "with the same key — migrations are never applied by a deploy, deliberately.",
        count($pending),
        implode(', ', $pending)
    );
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
 * $actor is the signed-in Admin. Since Phase 3 there always is one — the
 * route guard saw to it — and they are recorded on the batch
 * (import_batch.uploaded_by) and on every audit row the apply writes, so
 * "who ran this" has an answer batch 1 never got.
 *
 * @return array{notices: array<int, array{0: string, 1: string}>, batch: ?int}
 */
function import_act(Rerm\App $app, Rerm\Auth\User $actor): array
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
        return ['notices' => [stale_form_notice()], 'batch' => null];
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
            $batchId = $importer->stage($path, basename($name), $mode, $teamId, $actor->id);

            return [
                'notices' => [['ok', 'Read and staged. NOTHING has been written to the roster yet — '
                    . 'this is the diff, and the button at the bottom is what applies it.']],
                'batch'   => $batchId,
            ];
        }

        if ($action === 'apply') {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $result  = $importer->apply($batchId, $actor->id);

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

// ---------------------------------------------------------------------------
// Dispatch. The guard first — Rerm\Routes names every route and what it
// requires, and a path it does not name is a 404 before any handler runs.
// ---------------------------------------------------------------------------

$path  = $app->requestPath();
$guard = Rerm\Routes::guard($path);

if ($guard === null) {
    render($app, 'not-found', 'Not found', [], 404);
    exit;
}

// Every route gets a session: the public ones render forms whose CSRF tokens
// live in it, and the guarded ones hold the auth_token id in it (spec 3.4).
Rerm\Session::start($app);

$auth = Rerm\Auth\Auth::fromApp($app);
$user = null;

if ($guard !== Rerm\Routes::PUBLIC
    && $guard !== Rerm\Routes::STATUS_KEY
    && $guard !== Rerm\Routes::SETUP_KEY
) {
    $user = $auth->currentUser();

    if ($user === null) {
        redirect($app, 'login');
    }

    // The forced first change blocks EVERY other screen, direct URLs
    // included (spec 3.2). /password is where it is satisfied and /logout is
    // the one other thing a person half signed-in may do.
    if ($user->mustChangePassword && $path !== 'password' && $path !== 'logout') {
        redirect($app, 'password');
    }

    // A capability guard on top of being signed in. mayUse() is the level
    // check — no subject exists at routing time; anything a screen does TO a
    // member re-checks Access::allows() with one. The refusal is a 404, not
    // a 403: this application does not discuss what exists with people who
    // cannot see it.
    if ($guard !== Rerm\Routes::SIGNED_IN
        && !Rerm\Auth\Access::mayUse($user, Rerm\Auth\Capability::from($guard))
    ) {
        render($app, 'not-found', 'Not found', [], 404);
        exit;
    }
}

switch ($path) {
    case '':
        // The landing swap (Phase 5 decided 1): My Roster Status for anyone
        // who may use it, the menu for anyone who may not. mayUse is the
        // LEVEL question — no member is being acted on by rendering a screen;
        // everything on the screen re-checks per member.
        if (Rerm\Auth\Access::mayUse($user, Rerm\Auth\Capability::ViewStatusDashboard)) {
            dashboard_screen($app, $user);
        } else {
            render($app, 'menu', 'Menu', ['user' => $user]);
        }
        break;

    case 'dashboard':
        dashboard_screen($app, $user);
        break;

    case 'menu':
        render($app, 'menu', 'Menu', ['user' => $user]);
        break;

    case 'log-contact':
        log_contact_act($app, $user);

        // Unreachable: log_contact_act() redirects or exits.

    case 'login':
        // Already signed in: the menu, not a second login. The link in a
        // recovery email lands here too, which is why this is a redirect
        // rather than an error.
        if ($auth->currentUser() !== null) {
            redirect($app);
        }

        $outcome = login_act($app, $auth);
        render($app, 'login', 'Sign in', [
            'notices'      => $outcome['notices'],
            'memberNumber' => $outcome['member_number'],
        ]);
        break;

    case 'logout':
        // POST only, with a token: a GET that signs somebody out is a GET an
        // <img src> can send.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Rerm\Csrf::check()) {
            $auth->signOut();
        }
        redirect($app, 'login');

    case 'password':
        $notices = password_act($app, $auth, $user);
        render($app, 'password', 'Change password', [
            'notices'   => $notices,
            'user'      => $user,
            'forced'    => $user->mustChangePassword,
            'minLength' => (int) $app->config()->get('auth.min_password_length'),
        ]);
        break;

    case 'forgot':
        $outcome = forgot_act($app);
        render($app, 'forgot', 'Forgot password', [
            'notices'      => $outcome['notices'],
            'sent'         => $outcome['sent'],
            'noEmail'      => $outcome['no_email'],
            'memberNumber' => $outcome['member_number'],
        ]);
        break;

    case 'reset':
        $outcome = reset_act($app);
        render($app, 'reset', 'Reset password', [
            'notices'      => $outcome['notices'],
            'token'        => $outcome['token'],
            'memberNumber' => $outcome['member_number'],
            'done'         => $outcome['done'],
            'minLength'    => (int) $app->config()->get('auth.min_password_length'),
        ]);
        break;

    case 'roster':
        $year = active_show_year($app);
        if ($year === null) {
            render($app, 'not-found', 'Not found', [], 404);
            break;
        }

        // Read-only: the guard above answered "may they use this screen" and
        // ScopedQuery inside RosterPage answers "which rows". The moment a
        // later phase puts a button on these rows, that action checks
        // Access::allows() with a Subject per member.
        render($app, 'roster', 'View My Roster', [
            // A data screen (spec 8.2): the wide container above 720px.
            'wide'   => true,
            'user'   => $user,
            'year'   => $year,
            'roster' => Rerm\Roster\RosterPage::fromApp($app)->page($user, $year['id'], $_GET),
        ]);
        break;

    case 'import':
        // BEFORE anything touches the importer, which reads columns a pending
        // migration may not have added yet.
        $blocker = import_schema_blocker($app);
        if ($blocker !== null) {
            render($app, 'import', 'Import Roster', [
                'wide'    => false,
                'blocked' => $blocker,
                'notices' => [],
                'preview' => null,
                'staged'  => [],
                'applied' => [],
                'failedBatches' => [],
                'teams'   => [],
            ]);
            break;
        }

        $importer = Rerm\Import\Importer::fromApp($app);
        // A stale preview was computed against a roster that has since
        // changed, so applying it would write a diff nobody has read.
        $importer->discardExpired();

        $outcome = import_act($app, $user);

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
            'blocked' => null,
            'notices' => $outcome['notices'],
            'preview' => $preview,
            'staged'  => $importer->stagedBatches(10),
            'applied' => $importer->appliedBatches(5),
            // Kept and listed, never swept: a batch that wrote rows and then
            // stopped is the only record of a roster that changed when no
            // import says it did.
            'failedBatches' => $importer->failedBatches(5),
            'teams'   => import_teams($app),
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
        // Unreachable while the guard table and this switch agree; the test
        // that compares them is what keeps this arm dead.
        render($app, 'not-found', 'Not found', [], 404);
}
