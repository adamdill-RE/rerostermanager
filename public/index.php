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

    // Who is signed in is a property of the REQUEST, not of a route's
    // decision to mention it. The layout's nav strip (spec 8.6) belongs on
    // every screen a signed-in person can reach or on none of them, and
    // sourcing it here rather than at each of the render() calls below is
    // what stops the next route added from silently losing it — /import,
    // the longest screen in the application, had already lost it that way.
    //
    // Null on the public routes and on the 404 that fires before the session
    // starts, which is exactly what the layout tests for. A route that passes
    // its own 'user' still wins: += fills the gap, it does not overwrite.
    global $user;
    $data += ['user' => $user];

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
        ':action'     => Rerm\Audit\Action::PasswordChanged->value,
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
                ':action'     => Rerm\Audit\Action::PasswordResetRequested->value,
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
        ':action'     => Rerm\Audit\Action::PasswordResetCompleted->value,
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
            ':action'     => Rerm\Audit\Action::SetMasterPassword->value,
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
 * The list state a form carries so its 303 lands the officer back on the
 * exact page they acted from — whitelisted here, never echoed into a
 * Location header raw.
 *
 * One helper rather than one per screen (Phase 6): each allowed key names
 * what may travel, either as the list of values it may hold, as
 * ['int' => n] for a number that travels only when it exceeds n, or — since
 * Phase 7 — as ['ints' => n] for a LIST of such numbers, which is what
 * spec 7.2's team[] filter is and what the Committee Dashboard's drill-down
 * hands to spec 7.1. Everything else in the input is dropped, a key not in
 * the list included, so a crafted `return` cannot smuggle a parameter into
 * the redirect.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $allowed
 */
function return_query(array $input, array $allowed): string
{
    $params = [];

    foreach ($allowed as $key => $rule) {
        $value = $input[$key] ?? null;

        if (is_array($rule) && array_key_exists('int', $rule)) {
            $number = is_scalar($value) ? (int) $value : 0;
            if ($number > (int) $rule['int']) {
                $params[$key] = $number;
            }
            continue;
        }

        if (is_array($rule) && array_key_exists('ints', $rule)) {
            // De-duplicated and capped at 200, the same ceiling
            // RosterPage::teamIds() applies: a thousand-value query string
            // must not become a thousand-placeholder statement, and
            // max_input_vars is 1000 with silent truncation.
            $numbers = [];
            foreach ((array) $value as $item) {
                $number = is_scalar($item) ? (int) $item : 0;
                if ($number > (int) $rule['ints']) {
                    $numbers[$number] = $number;
                }
            }
            if ($numbers !== []) {
                $params[$key] = array_slice(array_values($numbers), 0, 200);
            }
            continue;
        }

        if (is_array($rule) && array_key_exists('text', $rule)) {
            // Bounded free text — a search term, which cannot be whitelisted
            // by value because its whole purpose is to be anything. What the
            // whitelist protects against is a crafted `return` smuggling an
            // extra PARAMETER into the redirect, and http_build_query below
            // already prevents that by percent-encoding & and =. So the rule
            // here is length and nothing else, and the value never reaches a
            // Location header unencoded.
            if (is_string($value) && trim($value) !== '') {
                $params[$key] = mb_substr(trim($value), 0, (int) $rule['text']);
            }
            continue;
        }

        if (is_string($value) && in_array($value, (array) $rule, true)) {
            $params[$key] = $value;
        }
    }

    $query = http_build_query($params);

    return $query === '' ? '' : '?' . $query;
}

/**
 * My Roster Status: the toggle, the filter, the page — and, since Phase 7,
 * the four drill-down filters (spec 7.3, decided 4).
 *
 * They have to travel, or logging a contact from a drilled-down view would
 * 303 back to the whole roster and the officer would lose the forty people
 * they were working. A table entry each, not new code.
 */
function dashboard_return_query(array $input): string
{
    return return_query($input, [
        'mode'     => ['mine', 'team'],
        'show'     => ['all'],
        'division' => ['int' => 0],
        'team'     => ['ints' => 0],
        'contact'  => ['never'],
        'assigned' => ['none'],
        'page'     => ['int' => 1],
        'size'     => ['int' => 0],
    ]);
}

/**
 * Assign Officers: the team, the bucket and the page (spec 7.4).
 *
 * `sel` is deliberately absent. It is a render hint that pre-ticks rows, and
 * carrying it back would re-tick a selection that has just been acted on.
 */
function assign_return_query(array $input): string
{
    return return_query($input, [
        'team'   => ['int' => 0],
        'bucket' => Rerm\Roster\AssignPage::BUCKETS,
        'page'   => ['int' => 1],
        'size'   => ['int' => 0],
    ]);
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
// Dropped Members (Phase 8.5) — Officer and above, through
// Capability::ViewRoster. READ-ONLY: the rows come through
// ScopedQuery::droppedForUser(), which is the ordinary scope predicate over
// the population every other read hides. No POST, no CSRF, nothing to write.
// ---------------------------------------------------------------------------

/** Renders Dropped Members — who fell off the roster, in the caller's scope. */
function dropped_screen(Rerm\App $app, Rerm\Auth\User $user): void
{
    $year = active_show_year($app);
    if ($year === null) {
        render($app, 'not-found', 'Not found', [], 404);

        return;
    }

    render($app, 'dropped', 'Dropped Members', [
        // A data screen (spec 8.2): the wide container above 720px.
        'wide'    => true,
        'user'    => $user,
        'year'    => $year,
        'dropped' => Rerm\Roster\DroppedPage::fromApp($app)->page($user, $year['id'], $_GET),
    ]);
}

// ---------------------------------------------------------------------------
// Committee Dashboard (spec 7.3) — Senior Officer and above, through
// Capability::ViewCommitteeDashboard. READ-ONLY: no POST, no CSRF check and
// no Access::allows() per member, because nothing on it writes. The route
// guard and ScopedQuery are still not optional.
// ---------------------------------------------------------------------------

/** Renders the Committee Dashboard — the roll-up by division, area and team. */
function committee_screen(Rerm\App $app, Rerm\Auth\User $user): void
{
    $year = active_show_year($app);
    if ($year === null) {
        render($app, 'not-found', 'Not found', [], 404);

        return;
    }

    render($app, 'committee', 'Committee Dashboard', [
        // A data screen (spec 8.2): the wide container above 720px.
        'wide'      => true,
        'user'      => $user,
        'year'      => $year,
        'committee' => Rerm\Roster\CommitteePage::fromApp($app)->page($user, $year['id'], $_GET),
    ]);
}

// ---------------------------------------------------------------------------
// Assign Officers (spec 7.4) — Officer and above, through
// Capability::AssignOfficers. One route, both verbs: the form posts back to
// the screen it came from and 303s to the same team, bucket and page.
// ---------------------------------------------------------------------------

/** Renders Assign Officers — the team picker, or one team's four buckets. */
function assign_screen(Rerm\App $app, Rerm\Auth\User $user): void
{
    $year = active_show_year($app);
    if ($year === null) {
        render($app, 'not-found', 'Not found', [], 404);

        return;
    }

    render($app, 'assign', 'Assign Officers', [
        // A data screen (spec 8.2): the wide container above 720px.
        'wide'    => true,
        'user'    => $user,
        'year'    => $year,
        'notices' => flash_take(),
        'assign'  => Rerm\Roster\AssignPage::fromApp($app)->page($user, $year['id'], $_GET),
    ]);
}

/**
 * What the officer is told about a bulk write, in one sentence plus whatever
 * the action could not do. Everything that was skipped is named — the count
 * is never left to be inferred from a list that got shorter (decided 2).
 *
 * @param array<string, mixed> $result
 * @return array{0: string, 1: string} flash kind, message
 */
function assign_notice(array $result, int $maxOfficers): array
{
    $outcome = $result['outcome'];
    $plural  = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);

    // refused_all shares this path: it IS a write that changed nothing, and
    // the clauses below say precisely why — "on another team" and "not in
    // your roster" are different facts and must not read as the same one.
    if ($outcome === 'assigned' || $outcome === 'removed' || $outcome === 'refused_all') {
        $parts = [];

        // The lead clause states what LANDED. When nothing did, it says so
        // in those words rather than reporting "Assigned 0 members" and
        // leaving the reason to the sentence after it.
        if ($outcome !== 'removed') {
            $parts[] = (int) $result['assigned'] === 0
                ? sprintf('Nothing was assigned to %s.', $result['officer_name'])
                : sprintf(
                    'Assigned %s to %s — now %s.',
                    $plural((int) $result['assigned'], 'member', 'members'),
                    $result['officer_name'],
                    $plural((int) $result['officer_load'], 'member assigned', 'members assigned')
                );
        } elseif ((int) $result['removed'] === 0) {
            $parts[] = 'Nothing was removed.';
        } else {
            $parts[] = $result['officer_name'] === ''
                ? sprintf('Removed %s.', $plural((int) $result['removed'], 'assignment', 'assignments'))
                : sprintf(
                    'Removed %s to %s — now %s.',
                    $plural((int) $result['removed'], 'assignment', 'assignments'),
                    $result['officer_name'],
                    $plural((int) $result['officer_load'], 'member assigned', 'members assigned')
                );
        }

        if ((int) $result['repointed'] > 0) {
            $parts[] = sprintf(
                'Cleared %s to an officer who is no longer eligible.',
                $plural((int) $result['repointed'], 'assignment', 'assignments')
            );
        }
        if ((int) $result['already'] > 0) {
            $parts[] = sprintf(
                '%s already had them.',
                $plural((int) $result['already'], 'member', 'members')
            );
        }
        if ($result['at_cap'] !== []) {
            // By name and count, never a silent trim: the officers they
            // already hold are the reason, so they are named too.
            $named = array_map(
                static fn (array $m): string => $m['name'] . ' (' . implode(', ', $m['officers']) . ')',
                array_slice($result['at_cap'], 0, 3)
            );
            $more  = count($result['at_cap']) - count($named);
            $parts[] = sprintf(
                'Skipped %s already at the %d-officer limit: %s%s.',
                $plural(count($result['at_cap']), 'member', 'members'),
                $maxOfficers,
                implode('; ', $named),
                $more > 0 ? sprintf(' and %d more', $more) : ''
            );
        }
        if ((int) $result['cross_team'] > 0) {
            $parts[] = sprintf(
                'Skipped %s on another team — assignment is same-team only.',
                $plural((int) $result['cross_team'], 'member', 'members')
            );
        }
        if ((int) $result['refused'] > 0) {
            $parts[] = sprintf(
                'Skipped %s that are not in your roster.',
                $plural((int) $result['refused'], 'member', 'members')
            );
        }

        $changed = (int) $result['assigned'] + (int) $result['removed'] + (int) $result['repointed'];

        return [$changed > 0 ? 'ok' : 'warn', implode(' ', $parts)];
    }

    if ($outcome === 'year_closed') {
        return ['danger', 'This show year is closed and read-only. Nothing was changed.'];
    }
    if ($outcome === 'bad_officer') {
        return ['danger', 'Choose an officer from this team. Nothing was changed.'];
    }
    if ($outcome === 'too_many') {
        return ['danger', sprintf(
            'That was more than %d members in one action. Nothing was changed — assign a page at a time.',
            Rerm\Roster\AssignOfficers::MAX_SELECTION
        )];
    }
    if ($outcome === 'nothing_to_do') {
        return ['warn', $result['action'] === 'remove'
            ? 'None of those members were assigned to that officer. Nothing was changed.'
            : 'Everyone on this team already has an officer. Nothing to do.'];
    }

    if ($outcome === 'nothing_selected') {
        return ['warn', $result['action'] === 'remove'
            ? 'Nobody was selected. Tick the members you want to remove first.'
            : 'Nobody was selected. Tick the members you want to assign first.'];
    }

    if ($outcome === 'bad_action') {
        // Only reachable from a hand-made POST: every button on the screen
        // carries one of the three actions.
        return ['danger', 'That form did not say what to do. Nothing was changed.'];
    }

    // Anything a future outcome adds. Unreachable today — a test holds every
    // declared outcome to a branch above — and a catch-all saying nothing
    // changed is the safe answer to a question this function cannot read.
    return ['danger', 'Nothing was changed.'];
}

/**
 * The Assign Officers POST (spec 7.4): CSRF, then the write through
 * Rerm\Roster\AssignOfficers — which re-checks Access::allows() with a
 * Subject per selected member and verifies the target officer against the
 * member's own team — then a 303 back to the same team, bucket and page.
 */
function assign_act(Rerm\App $app, Rerm\Auth\User $user): never
{
    // The form carries its list state as ONE hidden query string, parsed here
    // and then whitelisted like any other input.
    $state = [];
    parse_str(is_string($_POST['return'] ?? null) ? $_POST['return'] : '', $state);
    $return = 'assign' . assign_return_query($state);

    if (!Rerm\Csrf::check()) {
        flash_set(...stale_form_notice());
        redirect($app, $return);
    }

    $result = Rerm\Roster\AssignOfficers::fromApp($app)->apply($user, $_POST);

    // An if-chain, not a switch: tests/auth_test.php reads every `case '…':`
    // in this file as a route label, and these outcomes are not routes.
    if ($result['outcome'] === 'no_year') {
        render($app, 'not-found', 'Not found', [], 404);
        exit;
    }

    flash_set(...assign_notice(
        $result,
        (int) $app->config()->get('roster.max_officers_per_member', 3)
    ));
    redirect($app, $return);
}

// ---------------------------------------------------------------------------
// Designate Users (spec 7.5, 4.4) — Senior Officer and above, through
// Capability::DesignateAllowedUser. Both verbs on one route: the grant,
// revoke and scope forms post back to the search they came from and 303 to
// it. Every write re-checks BOTH questions inside Rerm\Admin\Designate —
// Access::allows() with a Subject for the member, Access::mayGrant() for the
// level — because the route guard proves only that this actor may use the
// screen.
// ---------------------------------------------------------------------------

/** The search state a designate form carries back through its 303. */
function designate_return_query(array $input): string
{
    return return_query($input, [
        'q'    => ['text' => 120],
        'only' => ['granted'],
        'page' => ['int' => 1],
        'size' => ['int' => 0],
    ]);
}

/** Renders Designate Users. */
function designate_screen(Rerm\App $app, Rerm\Auth\User $user): void
{
    render($app, 'designate', 'Designate Users', [
        // A data screen (spec 8.2): the wide container above 720px.
        'wide'      => true,
        'user'      => $user,
        'notices'   => flash_take(),
        'designate' => Rerm\Admin\DesignatePage::fromApp($app)->page($user, $_GET),
    ]);
}

/**
 * The designate POST: CSRF, then the write through Rerm\Admin\Designate,
 * then a 303 back to the same search. The one outcome that is not a redirect
 * is not_found — an out-of-scope or non-existent member gets the same 404 a
 * typed URL would, because this application does not discuss what exists with
 * people who cannot see it.
 */
function designate_act(Rerm\App $app, Rerm\Auth\User $user): never
{
    $state = [];
    parse_str(is_string($_POST['return'] ?? null) ? $_POST['return'] : '', $state);
    $return = 'designate' . designate_return_query($state);

    if (!Rerm\Csrf::check()) {
        flash_set(...stale_form_notice());
        redirect($app, $return);
    }

    $result  = Rerm\Admin\Designate::fromApp($app)->apply($user, $_POST);
    $outcome = $result['outcome'];
    $who     = (string) $result['member_name'];

    // An if-chain, not a switch: tests/auth_test.php reads every `case '…':`
    // in this file as a route label, and these outcomes are not routes.
    if ($outcome === 'granted') {
        $message = $who . ' now holds ' . $result['level']->label() . '.';
        if ($result['created'] === true) {
            $message .= ' A login was created with the initial password 1234, which they'
                . ' must change on first sign-in. Nothing was emailed — tell them yourself.';
        } elseif ($result['reactivated'] === true) {
            $message .= ' Their deactivated account was reopened.';
        }
        flash_set('ok', $message);
        redirect($app, $return);
    }

    if ($outcome === 'revoked') {
        flash_set('ok', $result['level']->label() . ' was revoked from ' . $who
            . '. Their title-derived level stands again.');
        redirect($app, $return);
    }

    if ($outcome === 'scope_set') {
        flash_set('ok', 'Scope override saved for ' . $who . '.');
        redirect($app, $return);
    }

    if ($outcome === 'scope_cleared') {
        flash_set('ok', $who . ' is back to the scope of their own member record.');
        redirect($app, $return);
    }

    if ($outcome === 'password_reset') {
        flash_set('ok', $who . "'s password is reset to 1234 and every session they had"
            . ' is signed out. They must choose a new password the next time they sign in.'
            . ' Nothing was emailed — tell them yourself.');
        redirect($app, $return);
    }

    if ($outcome === 'team_scope_set') {
        $n = count($result['teams']);
        flash_set('ok', sprintf(
            '%s now sees %d team%s, and nothing outside them.',
            $who,
            $n,
            $n === 1 ? '' : 's'
        ));
        redirect($app, $return);
    }

    if ($outcome === 'team_scope_cleared') {
        flash_set('ok', $who . ' is no longer narrowed to particular teams.'
            . ' They fall back to their division, or to their own team if their title says so.');
        redirect($app, $return);
    }

    if ($outcome === 'not_scopable') {
        flash_set('warn', 'Only an Officer or a Senior Officer can be narrowed to a set'
            . ' of teams. Anyone above them already sees everything, and a Member has no'
            . ' roster to narrow.');
        redirect($app, $return);
    }

    if ($outcome === 'no_account') {
        flash_set('warn', $who . ' has no login, so there is no password to reset.'
            . ' Grant them a level first.');
        redirect($app, $return);
    }

    if ($outcome === 'unchanged') {
        flash_set('warn', 'Nothing changed — ' . $who . ' already had that.');
        redirect($app, $return);
    }

    if ($outcome === 'nothing_to_revoke') {
        flash_set('warn', $who . ' holds no granted level, so there was nothing to revoke.');
        redirect($app, $return);
    }

    if ($outcome === 'bad_level') {
        flash_set('danger', 'You cannot grant that level. Nothing was changed.');
        redirect($app, $return);
    }

    if ($outcome === 'bad_scope') {
        flash_set('danger', 'That division or team does not exist. Nothing was changed.');
        redirect($app, $return);
    }

    if ($outcome === 'refused') {
        flash_set('danger', 'You may not do that to ' . $who . '. Nothing was changed.');
        redirect($app, $return);
    }

    if ($outcome === 'bad_action') {
        flash_set('danger', 'That form asked for something this screen does not do.');
        redirect($app, $return);
    }

    // not_found: an out-of-scope or non-existent member gets the 404.
    render($app, 'not-found', 'Not found', [], 404);
    exit;
}

// ---------------------------------------------------------------------------
// Flagged for Purge (spec 6.5) — Admin, through Capability::ImportRoster: the
// second half of the import lifecycle rather than a seventh capability. Both
// verbs on one route. Every write inside Rerm\Admin\Purge re-checks
// Access::allows() with a Subject per member, because a bulk purge of fifty
// is fifty questions and not one.
// ---------------------------------------------------------------------------

/** The list state a purge form carries back through its 303. */
function purge_return_query(array $input): string
{
    return return_query($input, [
        'list' => Rerm\Admin\PurgePage::LISTS,
        'page' => ['int' => 1],
        'size' => ['int' => 0],
    ]);
}

/** Renders Flagged for Purge — the flagged list, or the purged one. */
function purge_screen(Rerm\App $app, Rerm\Auth\User $user): void
{
    render($app, 'purge', 'Flagged for Purge', [
        // A data screen (spec 8.2): the wide container above 720px.
        'wide'    => true,
        'user'    => $user,
        'notices' => flash_take(),
        'purge'   => Rerm\Admin\PurgePage::fromApp($app)->page($_GET),
    ]);
}

/**
 * What the Admin is told about a purge or a restore, in one sentence plus
 * whatever it could not do. Everything skipped is named — the count is never
 * left to be inferred from a list that got shorter.
 *
 * @param array<string, mixed> $result
 * @return array{0: string, 1: string} flash kind, message
 */
function purge_notice(array $result): array
{
    $outcome = $result['outcome'];
    $done    = $result['action'] === 'purge' ? 'purged' : 'restored';

    if ($outcome === 'purged' || $outcome === 'restored') {
        $n       = (int) $result['affected'];
        $message = sprintf('%d member%s %s.', $n, $n === 1 ? '' : 's', $done);

        if ($result['names'] !== []) {
            $message .= ' ' . implode(', ', $result['names'])
                . ($n > count($result['names']) ? ', and others.' : '.');
        }

        if ($result['action'] === 'purge') {
            $message .= ' Nothing was deleted — their contact history, assignments'
                . ' and metrics are untouched, and Restore brings them back.';
        }

        if ((int) $result['skipped'] > 0) {
            $message .= sprintf(' %d was outside what you may act on.', (int) $result['skipped']);
        }

        return ['ok', $message];
    }

    if ($outcome === 'not_confirmed') {
        return ['danger', 'Type ' . Rerm\Admin\Purge::CONFIRM_WORD
            . ' exactly, in the box, to purge. Nothing was changed.'];
    }

    if ($outcome === 'nothing_selected') {
        return ['warn', 'Tick the members first. Nothing was changed.'];
    }

    if ($outcome === 'too_many') {
        return ['danger', sprintf(
            'That is more than %d members in one go. Nothing was changed — use a'
            . ' smaller page and do it in batches.',
            Rerm\Admin\Purge::MAX_SELECTION
        )];
    }

    if ($outcome === 'nothing_to_do') {
        return ['warn', 'Nothing changed — those members are already in the state you asked for.'];
    }

    return ['danger', 'That form asked for something this screen does not do.'];
}

/** The purge/restore POST: CSRF, the write, then a 303 back to the same list. */
function purge_act(Rerm\App $app, Rerm\Auth\User $user): never
{
    $state = [];
    parse_str(is_string($_POST['return'] ?? null) ? $_POST['return'] : '', $state);
    $return = 'purge' . purge_return_query($state);

    if (!Rerm\Csrf::check()) {
        flash_set(...stale_form_notice());
        redirect($app, $return);
    }

    flash_set(...purge_notice(Rerm\Admin\Purge::fromApp($app)->apply($user, $_POST)));
    redirect($app, $return);
}

// ---------------------------------------------------------------------------
// Export Roster (spec 7.5, Phase 8 decided 3) — Officer and above, SCOPED
// through Capability::ExportRoster. One export and one code path: every row
// goes through ScopedQuery::forUser() inside Rerm\Export\RosterExport, so an
// Admin's breadth is a consequence of their scope rather than a separate
// button.
// ---------------------------------------------------------------------------

/** Renders the Export screen — the year, the team filter and the row count. */
function export_screen(Rerm\App $app, Rerm\Auth\User $user): void
{
    render($app, 'export', 'Export Roster', [
        // A list of choices, not a data table (spec 8.2): the narrow column.
        'wide'    => false,
        'user'    => $user,
        'notices' => flash_take(),
        'export'  => Rerm\Admin\ExportPage::fromApp($app)->page($user, $_GET),
    ]);
}

/**
 * The download. CSRF first, then the file is built to a temp path outside the
 * document root, sent with readfile(), and unlinked — always, including on a
 * failure, because an export is ~1,950 people's home addresses and must not
 * survive on disk.
 *
 * The audit row is written BEFORE the body is sent: the rows have been read
 * and the file has been built by then, which is the fact worth keeping, and a
 * client that disconnects mid-download has still had the data.
 */
function export_act(Rerm\App $app, Rerm\Auth\User $user): never
{
    if (!Rerm\Csrf::check()) {
        flash_set(...stale_form_notice());
        redirect($app, 'export');
    }

    $page = Rerm\Admin\ExportPage::fromApp($app)->page($user, $_POST);
    $year = $page['year'];

    if ($year === null) {
        flash_set('danger', 'There is no show year to export.');
        redirect($app, 'export');
    }

    $teamIds = $page['selected_teams'];
    $export  = Rerm\Export\RosterExport::fromApp($app);

    try {
        $built = $export->build($user, (int) $year['id'], (string) $year['label'], $teamIds);
    } catch (Throwable $e) {
        // The one place a failure here is visible. Never a blank 500: on this
        // host app.debug is off in production and an uncaught throw renders
        // nothing at all.
        flash_set('danger', 'The export could not be built: ' . $e->getMessage());
        redirect($app, 'export');
    }

    $export->audit($user, (int) $year['id'], (string) $year['label'], $teamIds, (int) $built['rows']);

    // Nothing about a download is cacheable, and no proxy should keep a copy.
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $built['filename'] . '"');
    header('Content-Length: ' . (string) filesize($built['path']));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    readfile($built['path']);

    // Both temp files, gone, whether or not the client got the whole body.
    $export->discard($built['writer'], $built['path']);
    exit;
}

// ---------------------------------------------------------------------------
// Show Year (spec 5.1, Phase 8 decided 1 and 5) — Admin, through
// Capability::ManageShowYear. Create, set active, open/close, and the
// rollover. Everything that changes state is a POST with a token; the
// rollover PREVIEW is a GET, because it writes nothing and a link that says
// "show me what would happen" should be shareable.
// ---------------------------------------------------------------------------

/** Renders Show Year, including the rollover preview when one was asked for. */
function show_year_screen(Rerm\App $app, Rerm\Auth\User $user): void
{
    $years = Rerm\Admin\ShowYears::fromApp($app);
    $all   = $years->years();

    // The two ends of the rollover, defaulted so the form is never empty:
    // from the newest year that is not the target, into the active one.
    $from = (int) ($_GET['from_year'] ?? 0);
    $to   = (int) ($_GET['to_year'] ?? 0);

    $ids  = array_map(static fn (array $y): int => $y['id'], $all);
    $open = array_values(array_filter($all, static fn (array $y): bool => $y['is_open']));

    if (!in_array($from, $ids, true)) {
        $from = $ids[0] ?? 0;
    }
    if (!in_array($to, array_map(static fn (array $y): int => $y['id'], $open), true)) {
        $to = $open[0]['id'] ?? 0;
    }

    // The preview costs a query, so it runs only when both ends are real and
    // different — never on the first render of the page.
    $preview = null;
    if (isset($_GET['from_year'], $_GET['to_year']) && $from > 0 && $to > 0 && $from !== $to) {
        $preview = $years->rolloverPreview($from, $to);
    }

    render($app, 'show-year', 'Show Year', [
        // A data screen (spec 8.2): the wide container above 720px.
        'wide'     => true,
        'user'     => $user,
        'notices'  => flash_take(),
        'showYear' => [
            'years'     => $all,
            'from_year' => $from,
            'to_year'   => $to,
            'preview'   => $preview,
        ],
    ]);
}

/**
 * What the Admin is told about a show-year change.
 *
 * @param array<string, mixed> $result
 * @return array{0: string, 1: string} flash kind, message
 */
function show_year_notice(array $result): array
{
    $outcome = $result['outcome'];
    $label   = (string) $result['label'];

    if ($outcome === 'created') {
        return ['ok', 'Show year ' . $label . ' was created, open and not yet active.'];
    }

    if ($outcome === 'activated') {
        return ['ok', $label . ' is now the active show year. Every officer sees it from their next page load.'];
    }

    if ($outcome === 'opened') {
        return ['ok', $label . ' is open again and accepts changes.'];
    }

    if ($outcome === 'closed') {
        $message = $label . ' is closed and read-only.';
        $frozen  = (int) $result['in_progress'];
        if ($frozen > 0) {
            $message .= sprintf(
                ' %d metric%s frozen mid-chase, exactly as %s. Nothing was cleared.',
                $frozen,
                $frozen === 1 ? ' was' : 's were',
                $frozen === 1 ? 'it was' : 'they were'
            );
        }

        return ['ok', $message];
    }

    if ($outcome === 'carried') {
        $carried = (int) $result['carried'];
        $dropped = (int) $result['dropped'];

        $message = sprintf(
            '%d assignment%s carried into %s.',
            $carried,
            $carried === 1 ? '' : 's',
            $label
        );

        if ($dropped > 0) {
            $message .= sprintf(
                ' %d %s dropped because the officer no longer qualifies — those members'
                . ' are unassigned in %s and show up on Assign Officers.',
                $dropped,
                $dropped === 1 ? 'was' : 'were',
                $label
            );
        }

        return ['ok', $message];
    }

    if ($outcome === 'nothing_to_carry') {
        return ['warn', 'There was nothing to carry between those two years.'];
    }

    if ($outcome === 'not_confirmed') {
        return ['danger', 'Type ' . Rerm\Admin\ShowYears::CONFIRM_WORD
            . ' exactly, in the box, to go ahead. Nothing was changed.'];
    }

    if ($outcome === 'bad_label') {
        return ['danger', 'A show year needs a name of 1 to 32 characters. Nothing was created.'];
    }

    if ($outcome === 'duplicate_label') {
        return ['danger', 'There is already a show year called ' . $label . '.'];
    }

    if ($outcome === 'bad_dates') {
        return ['danger', 'Those dates do not make sense — check the format and that the'
            . ' start is not after the end. Nothing was created.'];
    }

    if ($outcome === 'same_year') {
        return ['warn', 'Pick two different show years to carry between.'];
    }

    if ($outcome === 'target_closed') {
        return ['danger', $label . ' is closed. Re-open it before carrying anything into it.'];
    }

    if ($outcome === 'unchanged') {
        return ['warn', 'Nothing changed — ' . $label . ' was already in that state.'];
    }

    if ($outcome === 'not_found') {
        return ['danger', 'That show year does not exist. Nothing was changed.'];
    }

    return ['danger', 'That form asked for something this screen does not do.'];
}

/** The show-year POST: CSRF, the write, then a 303 back to the screen. */
function show_year_act(Rerm\App $app, Rerm\Auth\User $user): never
{
    if (!Rerm\Csrf::check()) {
        flash_set(...stale_form_notice());
        redirect($app, 'show-year');
    }

    flash_set(...show_year_notice(Rerm\Admin\ShowYears::fromApp($app)->apply($user, $_POST)));
    redirect($app, 'show-year');
}

// ---------------------------------------------------------------------------
// The Audit Log (spec 7.5) — Admin, through Capability::ViewAuditLog.
// READ-ONLY: no POST, no CSRF check and no write path at all, because an
// audit row is append-only and outlives whatever it describes. The filter is
// a GET so a link to one investigation is shareable.
// ---------------------------------------------------------------------------

/** Renders the Audit Log. */
function audit_screen(Rerm\App $app, Rerm\Auth\User $user): void
{
    render($app, 'audit', 'Audit Log', [
        // A data screen (spec 8.2): the wide container above 720px.
        'wide'  => true,
        'user'  => $user,
        'audit' => Rerm\Admin\AuditPage::fromApp($app)->page($_GET),
    ]);
}

// ---------------------------------------------------------------------------
// Manage Teams (spec 7.3) — Admin, through Capability::ManageTeams. team.area
// only: it is display grouping, and a test holds Access, ScopedQuery,
// EligibleOfficers and AssignOfficers clean of the column, comments included.
// ---------------------------------------------------------------------------

/** Renders Manage Teams — every team, one editor open at a time. */
function teams_screen(Rerm\App $app, Rerm\Auth\User $user): void
{
    render($app, 'teams', 'Manage Teams', [
        // A data screen (spec 8.2): the wide container above 720px.
        'wide'    => true,
        'user'    => $user,
        'notices' => flash_take(),
        'teams'   => Rerm\Admin\TeamsPage::fromApp($app)->page($_GET),
    ]);
}

/** The area POST: CSRF, the write, then a 303 back to the list. */
function teams_act(Rerm\App $app, Rerm\Auth\User $user): never
{
    if (!Rerm\Csrf::check()) {
        flash_set(...stale_form_notice());
        redirect($app, 'teams');
    }

    $result  = Rerm\Admin\TeamsPage::fromApp($app)->save($user, $_POST);
    $outcome = $result['outcome'];

    // An if-chain, not a switch: tests/auth_test.php reads every `case '…':`
    // in this file as a route label, and these outcomes are not routes.
    if ($outcome === 'saved') {
        flash_set('ok', $result['team'] . ' now groups under ' . $result['area'] . '.');
    } elseif ($outcome === 'cleared') {
        flash_set('ok', $result['team'] . ' has no area and groups under (No area).');
    } elseif ($outcome === 'unchanged') {
        flash_set('warn', 'Nothing changed — that was already the area.');
    } elseif ($outcome === 'too_long') {
        flash_set('danger', 'An area name is at most 64 characters. Nothing was changed.');
    } else {
        flash_set('danger', 'That team does not exist. Nothing was changed.');
    }

    redirect($app, 'teams');
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
                    'Applied. %s created, %s updated, %s unchanged, %s dropped, %s account(s) '
                    . 'created or changed, %s metric(s) reset to Not started because they moved N to Y.',
                    number_format($result['created']),
                    number_format($result['updated']),
                    number_format($result['unchanged']),
                    number_format($result['dropped']),
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

/**
 * Officers a contact history load can be attributed to.
 *
 * Every ACTIVE account, not only the chosen team's, because an officer who
 * helped chase another team is not on it — and because the default is a
 * choice the Admin makes once, before the file has even been read, when
 * nothing yet knows which team it is about.
 *
 * @return array<int, array<string, mixed>>
 */
function contacts_officers(Rerm\App $app): array
{
    try {
        return $app->db()->query(
            'SELECT u.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' t.name AS team_name'
            . ' FROM app_user u JOIN member m ON m.id = u.member_id'
            . ' LEFT JOIN team t ON t.id = m.team_id'
            . ' WHERE u.is_active = 1 AND m.purged_at IS NULL'
            . ' ORDER BY m.last_name, m.first_name'
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * The uploaded history file's temporary path, or a message saying what went
 * wrong. The roster import's twin, against its own field name.
 *
 * @return array{0: ?string, 1: string} path, error
 */
function contacts_uploaded_file(): array
{
    $file = $_FILES['history'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, 'Choose a file first.'];
    }

    $error = (int) $file['error'];
    if ($error !== UPLOAD_ERR_OK) {
        return [null, match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                "That file is larger than %s, this server's upload ceiling. A contact history "
                . 'is a few hundred rows of text, so a file that big is almost certainly the '
                . 'roster export — which goes to Import Roster instead.',
                (string) ini_get('upload_max_filesize')
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
 * Runs the contact history action, if the request is one.
 *
 * The file is read straight from PHP's own temporary upload and never copied
 * anywhere. Nothing needs it after parsing — the staged rows are what the
 * apply reads — and it is a list of who called whom, which is the sort of
 * thing best kept nowhere. The sha256 on the batch still answers "have we
 * loaded this exact file before" without keeping a byte of it.
 *
 * @return array{notices: array<int, array{0: string, 1: string}>, batch: ?int}
 */
function contacts_act(Rerm\App $app, Rerm\Auth\User $actor): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return ['notices' => [], 'batch' => null];
    }

    // FIRST, before anything reads $_POST — see import_act(): past
    // post_max_size, PHP discards the body and the request arrives looking
    // like a form that was never submitted, and then like a CSRF failure.
    if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        return ['notices' => [['danger', import_oversize_message()]], 'batch' => null];
    }

    $action = $_POST['action'] ?? '';
    if ($action === '') {
        return ['notices' => [], 'batch' => null];
    }

    if (!Rerm\Csrf::check()) {
        return ['notices' => [stale_form_notice()], 'batch' => null];
    }

    $importer = Rerm\Import\ContactImporter::fromApp($app);

    try {
        if ($action === 'stage') {
            [$path, $error] = contacts_uploaded_file();
            if ($path === null) {
                return ['notices' => [['danger', $error]], 'batch' => null];
            }

            $officerId = (int) ($_POST['officer_id'] ?? 0);
            $teamId    = ($_POST['team_id'] ?? '') !== '' ? (int) $_POST['team_id'] : null;

            $notices = [];
            $already = $importer->appliedWithSameContents($path);
            if ($already !== null) {
                $notices[] = ['warn', sprintf(
                    'This exact file was already loaded on %s UTC, as batch %s, writing %s '
                    . 'contact(s). Loading it again is safe — every row it already wrote is '
                    . 'recognised below as already logged — but it will probably do nothing.',
                    (string) $already['applied_at'],
                    (string) $already['id'],
                    number_format((int) $already['rows_inserted'])
                )];
            }

            // basename only: the browser sends whatever it likes here, and
            // this string is stored and rendered.
            $name    = basename((string) ($_FILES['history']['name'] ?? 'contacts'));
            $batchId = $importer->stage($path, $name, $officerId, $teamId, $actor->id);

            $notices[] = ['ok', 'Read and checked. NOTHING has been written to the contact log '
                . 'yet — this is what would be, and the button at the bottom is what writes it.'];

            return ['notices' => $notices, 'batch' => $batchId];
        }

        if ($action === 'apply') {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $result  = $importer->apply($batchId, $actor->id);

            return [
                'notices' => [['ok', sprintf(
                    'Loaded. %s contact(s) written, %s already logged, %s not landed.',
                    number_format($result['inserted']),
                    number_format($result['duplicate']),
                    number_format($result['skipped'])
                )]],
                'batch'   => $batchId,
            ];
        }

        if ($action === 'discard') {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $importer->discard($batchId);

            return [
                'notices' => [['warn', "Batch {$batchId} was discarded. Nothing was written."]],
                'batch'   => null,
            ];
        }
    } catch (Rerm\Import\ImportException $e) {
        return ['notices' => [['danger', $e->getMessage()]], 'batch' => null];
    } catch (Throwable $e) {
        return ['notices' => [['danger', 'The load failed: ' . $e->getMessage()]], 'batch' => null];
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

    case 'committee':
        committee_screen($app, $user);
        break;

    case 'dropped':
        dropped_screen($app, $user);
        break;

    case 'assign':
        // Both verbs on one route. A POST never falls through: assign_act()
        // 303s to the screen it came from, or renders a 404 for a database
        // with no active show year.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            assign_act($app, $user);
        }

        assign_screen($app, $user);
        break;

    case 'designate':
        // Both verbs on one route. A POST never falls through:
        // designate_act() 303s to the search it came from, or renders a 404
        // for a member the actor may not see.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            designate_act($app, $user);
        }

        designate_screen($app, $user);
        break;

    case 'purge':
        // Both verbs on one route. A POST never falls through: purge_act()
        // 303s to the list it came from.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            purge_act($app, $user);
        }

        purge_screen($app, $user);
        break;

    case 'export':
        // Both verbs on one route. A POST never falls through: export_act()
        // sends the file and exits, or 303s back with a flash.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            export_act($app, $user);
        }

        export_screen($app, $user);
        break;

    case 'show-year':
        // Both verbs on one route. A POST never falls through:
        // show_year_act() 303s back to the screen with a flash.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            show_year_act($app, $user);
        }

        show_year_screen($app, $user);
        break;

    case 'audit':
        audit_screen($app, $user);
        break;

    case 'teams':
        // Both verbs on one route. A POST never falls through: teams_act()
        // 303s back to the list with a flash.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            teams_act($app, $user);
        }

        teams_screen($app, $user);
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

    case 'import-contacts':
        // The same schema guard the roster import carries, for the same
        // reason: this screen reads tables migration 009 adds, and a server
        // running newer code against an older database would render a blank
        // page rather than a sentence saying so.
        $blocker = import_schema_blocker($app);
        if ($blocker !== null) {
            render($app, 'import-contacts', 'Import Contact History', [
                'wide'      => false,
                'blocked'   => $blocker,
                'notices'   => [],
                'preview'   => null,
                'staged'    => [],
                'applied'   => [],
                'teams'     => [],
                'officers'  => [],
            ]);
            break;
        }

        $contacts = Rerm\Import\ContactImporter::fromApp($app);
        // A preview computed days ago was computed against a contact log that
        // has moved since, so applying it would write a diff nobody has read.
        $contacts->discardExpired();

        $outcome = contacts_act($app, $user);

        $batchId = $outcome['batch'] ?? null;
        if ($batchId === null && isset($_GET['batch'])) {
            $batchId = (int) $_GET['batch'];
        }

        $preview = null;
        if ($batchId !== null && $batchId > 0) {
            try {
                $preview = $contacts->preview($batchId);
            } catch (Rerm\Import\ImportException $e) {
                $outcome['notices'][] = ['warn', $e->getMessage()];
            }
        }

        render($app, 'import-contacts', 'Import Contact History', [
            // A row-by-row diff is data, not a list of choices.
            'wide'     => $preview !== null,
            'blocked'  => null,
            'notices'  => $outcome['notices'],
            'preview'  => $preview,
            'staged'   => $contacts->stagedBatches(5),
            'applied'  => $contacts->appliedBatches(5),
            'teams'    => import_teams($app),
            'officers' => contacts_officers($app),
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
