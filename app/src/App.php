<?php

declare(strict_types=1);

namespace Rerm;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * The composition root. Everything else is handed what it needs by this.
 *
 * There is no container and no framework here, deliberately: the host has no
 * Composer and no build step, so a dependency is a file somebody has to
 * maintain by hand. What this replaces is the thing a container is actually
 * for — one object that knows the application root, the configuration and the
 * database connection, passed down instead of reached for through globals.
 *
 * The mount point lives here too. This application is served from a subpath
 * beside another application on the same domain, so a site-root URL is not
 * merely untidy, it points at RESM. url() and asset() are the only places a
 * path is built, and config app.base_path is the only place the subpath is
 * written down.
 */
final class App
{
    private ?PDO $pdo = null;

    private function __construct(
        private readonly string $root,
        private readonly Config $config,
    ) {
    }

    /**
     * $root is the application root — the directory holding app/, bin/, db/
     * and config/. On the server that is a SIBLING of the document root, not
     * a directory inside it: everything under public_html is web-reachable,
     * including anything placed beside the mount directory, so app/ has to
     * live outside it entirely (docs/hosting.md).
     */
    public static function boot(?string $root = null, ?Config $config = null): self
    {
        $root ??= dirname(__DIR__, 2);
        $root = rtrim($root, '/');

        return new self($root, $config ?? Config::load($root));
    }

    public function root(): string
    {
        return $this->root;
    }

    /** An absolute filesystem path inside the application root. */
    public function path(string $relative = ''): string
    {
        $relative = ltrim($relative, '/');

        return $relative === '' ? $this->root : $this->root . '/' . $relative;
    }

    public function config(): Config
    {
        return $this->config;
    }

    /** Connected on first use: a CLI that only prints --help opens nothing. */
    public function db(): PDO
    {
        return $this->pdo ??= Database::connect($this->config->section('db'));
    }

    public function debug(): bool
    {
        return $this->config->get('app.debug', false) === true;
    }

    /**
     * An application URL.
     *
     *     $app->url()               the menu
     *     $app->url('login')        the login screen
     *
     * Never concatenate onto a leading slash by hand. A link written that way
     * resolves to the domain root, where the landing page and RESM live.
     */
    public function url(string $path = ''): string
    {
        $base = (string) $this->config->get('app.base_path');
        if (!str_ends_with($base, '/')) {
            $base .= '/';
        }

        return $base . ltrim($path, '/');
    }

    /**
     * An asset URL, stamped with the file's modification time.
     *
     * .htaccess caches assets for a year, which is only safe because the stamp
     * changes when the file does. The host has no OPcache, so a file-copy
     * deploy is live on the next request and the stamp moves with it.
     */
    public function asset(string $path): string
    {
        $relative = ltrim($path, '/');
        $file     = $this->path('public/' . $relative);
        $url      = $this->url($relative);

        $stamp = is_file($file) ? filemtime($file) : false;

        return $stamp === false ? $url : $url . '?v=' . $stamp;
    }

    /**
     * The requested path, relative to the mount point.
     *
     *     https://host/rerm/          ->  ''
     *     https://host/rerm/status    ->  'status'
     *     https://host/rerm/a/b?x=1   ->  'a/b'
     *
     * The .htaccess rewrites every non-file request to index.php without
     * changing REQUEST_URI, so the original path arrives intact and the mount
     * point has to be trimmed off here. It comes from app.base_path like every
     * other path in this application, which is what lets the same code serve
     * from a subpath locally, on the server, and anywhere it is moved to.
     */
    public function requestPath(?string $requestUri = null): string
    {
        $uri  = $requestUri ?? (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        // parse_url returns false on a malformed URI and null when there is no
        // path component. Neither is a route.
        if (!is_string($path)) {
            return '';
        }

        $path = rawurldecode($path);
        $base = rtrim($this->url(), '/');

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return trim($path, '/');
    }

    /** Storage and comparison are UTC everywhere; this is display only. */
    public function displayTimezone(): DateTimeZone
    {
        return new DateTimeZone((string) $this->config->get('app.display_timezone'));
    }

    /**
     * Reads a UTC DATETIME out of the database into the display timezone.
     *
     * Through a named zone, never a fixed offset: Houston is UTC-6 in January
     * and UTC-5 in April, and the show runs across the change.
     */
    public function toDisplay(string|DateTimeInterface $utc): DateTimeImmutable
    {
        $moment = $utc instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($utc)
            : new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return $moment->setTimezone($this->displayTimezone());
    }

    /** The UTC timestamp string this schema's DATETIME columns expect. */
    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public function migrator(): Migrator
    {
        $dir = $this->path('db/migrations');
        if (!is_dir($dir)) {
            throw new RuntimeException("No migrations directory at {$dir}");
        }

        return new Migrator($this->db(), $dir);
    }
}
