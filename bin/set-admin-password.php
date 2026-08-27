<?php

declare(strict_types=1);

/**
 * Unlocks the seeded master administrator (spec 3.1, migration 003).
 *
 *     php bin/set-admin-password.php
 *
 * The account ships with password_hash = '*' — the /etc/shadow convention,
 * not a hash of anything — so it exists and cannot be signed into until this
 * runs (or the /setup route does, where there is no shell). The repository is
 * public; no hash may ever be committed, which is why unlocking is a runtime
 * act and never a migration.
 *
 * The password is read from the terminal, never from argv: an argument lands
 * in shell history and in `ps` output for as long as the process runs. With
 * no terminal (a pipe), it reads one line from STDIN instead, so
 * `php bin/set-admin-password.php < password-file` works where TTYs do not.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Password;

/** One secret line from the terminal, echo off where the terminal allows. */
function read_password(string $prompt): string
{
    fwrite(STDOUT, $prompt);

    $interactive = function_exists('posix_isatty')
        ? @posix_isatty(STDIN)
        : stream_isatty(STDIN);

    if ($interactive) {
        // stty lives on every target this runs on (EL9, the docker image,
        // CI's ubuntu). If it fails the password just echoes — worth a
        // warning, not a refusal.
        shell_exec('stty -echo 2>/dev/null');
        $line = fgets(STDIN);
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    } else {
        $line = fgets(STDIN);
    }

    return rtrim((string) $line, "\r\n");
}

$pdo = $app->db();

$read = $pdo->prepare(
    'SELECT u.id, u.password_hash FROM app_user u '
    . 'INNER JOIN member m ON m.id = u.member_id '
    . 'WHERE m.member_number = :number'
);
$read->execute([':number' => App::MASTER_ADMIN_NUMBER]);
$account = $read->fetch();

if (!is_array($account)) {
    fwrite(STDERR, "The master administrator does not exist yet.\n"
        . "Apply the migrations first: php bin/migrate.php\n");
    exit(1);
}

$locked = password_get_info((string) $account['password_hash'])['algo'] === null;
printf(
    "Master administrator (member number %s) is %s.\n",
    App::MASTER_ADMIN_NUMBER,
    $locked ? 'locked — no password has ever been set' : 'unlocked; this REPLACES its password'
);

$password = read_password('New password: ');
$confirm  = read_password('Again: ');

if ($password !== $confirm) {
    fwrite(STDERR, "The two did not match. Nothing was changed.\n");
    exit(1);
}

$passwords = Password::fromApp($app);
$problem   = $passwords->problemWith($password);
if ($problem !== null) {
    fwrite(STDERR, $problem . " Nothing was changed.\n");
    exit(1);
}

$pdo->prepare(
    'UPDATE app_user SET password_hash = :hash, must_change_password = 0, '
    . 'password_changed_at = UTC_TIMESTAMP() WHERE id = :id'
)->execute([':hash' => $passwords->hash($password), ':id' => (int) $account['id']]);

// Logged like every credential change. The actor is NULL: nobody was signed
// in — whoever holds a shell on the application root did this, and saying so
// is more honest than attributing it to the account it just unlocked.
$pdo->prepare(
    'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, after_json, ip) '
    . 'VALUES (NULL, :action, :entity, :entity_id, :after_json, :ip)'
)->execute([
    ':action'     => 'set_master_password',
    ':entity'     => 'app_user',
    ':entity_id'  => (string) (int) $account['id'],
    ':after_json' => '{"source":"bin/set-admin-password.php"}',
    ':ip'         => '',
]);

printf("Done. Sign in at /login as %s.\n", App::MASTER_ADMIN_NUMBER);
