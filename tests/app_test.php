<?php

declare(strict_types=1);

/**
 * The composition root: configuration layering and URL building.
 *
 * Neither needs a database, so these run everywhere — including on a machine
 * that has none, where they are the only thing standing between a broken
 * config loader and a deploy.
 *
 * Note that no test here writes the real mount point. It is configuration,
 * read from one place, and a test that hard-coded it would be asserting the
 * value rather than the mechanism — while quietly becoming the second place
 * the subpath is written down.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Config;
use Rerm\Database;

/** A config with the shape App expects, and nothing else. */
function app_test_config(string $basePath = '/mount/'): Config
{
    return Config::fromArray([
        'app' => [
            'base_path'        => $basePath,
            'display_timezone' => 'America/Chicago',
            'debug'            => false,
        ],
        'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'rerm', 'user' => 'u', 'pass' => 'p', 'socket' => null],
    ]);
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

test('the committed configuration loads and carries the mount point', function (): void {
    $config = Config::load(dirname(__DIR__), []);

    $base = (string) $config->get('app.base_path');
    assertTrue(str_starts_with($base, '/'), "app.base_path must be absolute, got {$base}");
    assertTrue(str_ends_with($base, '/'), "app.base_path must keep its trailing slash, got {$base}");

    // Storage and comparison are UTC; this is display only, and it is a real
    // timezone rather than an offset because Houston observes DST and the show
    // runs across the March change.
    assertSame('America/Chicago', $config->get('app.display_timezone'));
});

test('the shipped configuration cannot send email', function (): void {
    // .github/check-mail-safety.php asserts this against the file. This
    // asserts it against what the loader actually produces, which is what the
    // application will read.
    $config = Config::load(dirname(__DIR__), []);

    assertSame(false, $config->get('mail.enabled'));
    assertTrue(in_array($config->get('mail.transport'), ['log', 'file'], true));
});

test('RERM_ environment overrides the committed defaults', function (): void {
    $config = Config::load(dirname(__DIR__), [
        'RERM_DB_HOST' => 'db.example.com',
        'RERM_DB_PORT' => '3307',
        'RERM_DEBUG'   => '1',
    ]);

    assertSame('db.example.com', $config->get('db.host'));
    // Cast, not left a string: a port compared with === against an int would
    // silently never match.
    assertSame(3307, $config->get('db.port'));
    assertSame(true, $config->get('app.debug'));

    // Untouched keys keep the committed value.
    assertSame('rerm', $config->get('db.name'));
});

test('an empty environment variable is absent, not an empty value', function (): void {
    // Docker and cPanel both hand through variables that were declared and
    // never given a value. An empty db.host is worse than the default.
    $config = Config::load(dirname(__DIR__), ['RERM_DB_HOST' => '']);

    assertTrue($config->get('db.host') !== '', 'an empty RERM_DB_HOST blanked db.host');
});

test('mail.enabled and mail.transport are not settable from the environment', function (): void {
    // Delivery is armed by a considered edit on the machine that should be
    // sending — never by a variable that can be inherited, copied between
    // hosts, or set by a compose file somebody adapted from another project.
    $config = Config::load(dirname(__DIR__), [
        'RERM_MAIL_ENABLED'   => '1',
        'RERM_MAIL_TRANSPORT' => 'send',
    ]);

    assertSame(false, $config->get('mail.enabled'));
    assertTrue($config->get('mail.transport') !== 'send');
});

test('a missing configuration path raises rather than returning null', function (): void {
    $config = app_test_config();

    assertThrows(static fn () => $config->get('db.hsot'), 'db.hsot');
    assertSame('fallback', $config->get('db.hsot', 'fallback'));
});

// ---------------------------------------------------------------------------
// App
// ---------------------------------------------------------------------------

test('every URL is built from the configured mount point', function (): void {
    $app = App::boot(dirname(__DIR__), app_test_config('/mount/'));

    assertSame('/mount/', $app->url());
    assertSame('/mount/login', $app->url('login'));
    // A caller's leading slash is not a site-root escape hatch: this app sits
    // beside another one on the same domain, and /login there is not ours.
    assertSame('/mount/login', $app->url('/login'));
});

test('a mount point without a trailing slash still composes', function (): void {
    $app = App::boot(dirname(__DIR__), app_test_config('/mount'));

    assertSame('/mount/', $app->url());
    assertSame('/mount/login', $app->url('login'));
});

test('an asset is stamped when the file exists and plain when it does not', function (): void {
    $app = App::boot(dirname(__DIR__), app_test_config('/mount/'));

    // .htaccess caches assets for a year, which is only safe because the stamp
    // moves when the file does.
    $stamped = $app->asset('.htaccess');
    assertTrue((bool) preg_match('#^/mount/\.htaccess\?v=\d+$#', $stamped), "unstamped: {$stamped}");

    assertSame('/mount/nothing-here.css', $app->asset('nothing-here.css'));
});

test('UTC is stored and America/Chicago is displayed', function (): void {
    $app = App::boot(dirname(__DIR__), app_test_config());

    // Central Daylight Time, UTC-5. Through a named zone, never a fixed
    // offset — the same wall clock in January is UTC-6.
    assertSame('2027-03-06 14:30 CST', $app->toDisplay('2027-03-06 20:30:00')->format('Y-m-d H:i T'));
    assertSame('2027-07-04 15:30 CDT', $app->toDisplay('2027-07-04 20:30:00')->format('Y-m-d H:i T'));

    // And what goes into a DATETIME column is UTC, in the format it expects.
    assertTrue((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', App::now()));
});

test('the DSN names a host and port, or a socket, but never both', function (): void {
    assertSame(
        'mysql:host=db.example.com;port=3307;dbname=rerm;charset=utf8mb4',
        Database::dsn(['host' => 'db.example.com', 'port' => 3307, 'name' => 'rerm', 'socket' => null])
    );

    assertSame(
        'mysql:unix_socket=/tmp/mysql.sock;dbname=rerm;charset=utf8mb4',
        Database::dsn(['host' => 'ignored', 'port' => 3306, 'name' => 'rerm', 'socket' => '/tmp/mysql.sock'])
    );

    assertThrows(static fn () => Database::dsn(['host' => 'x', 'name' => '']), 'db.name');
});

test('e() escapes for both text and attribute contexts', function (): void {
    assertSame('&lt;script&gt;', e('<script>'));
    // ENT_QUOTES: a single quote closes an attribute just as well as a double.
    assertSame('&#039;a&quot;b&#039;', e("'a\"b'"));
    assertSame('', e(null));
});
