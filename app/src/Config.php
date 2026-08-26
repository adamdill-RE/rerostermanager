<?php

declare(strict_types=1);

namespace Rerm;

use RuntimeException;

/**
 * Configuration, layered.
 *
 * Three sources, each overriding the one before it:
 *
 *   1. config/config.php        committed defaults, no credentials
 *   2. config/config.local.php  gitignored, partial, holds the credentials
 *   3. RERM_* environment       see ENV_MAP below
 *
 * The layering exists because the same checkout runs in three places that
 * cannot share a file: a developer's docker container, CI, and a cPanel
 * account where the deploy overwrites config/config.php on every push and must
 * not be able to overwrite the file holding the database password.
 *
 * Environment sits on top because CI has nowhere to put a local file and the
 * docker-compose service is configured entirely with RERM_* variables.
 *
 * Values are read with dotted paths — $config->get('db.host') — so nothing has
 * to know how deep the array is, and a typo raises rather than silently
 * yielding null: get() throws unless a default is passed.
 */
final class Config
{
    /**
     * Environment overrides, mapped to their dotted config paths.
     *
     * The RERM_ prefix is not decoration. This account also runs RESM, which
     * reads RESM_*; on shared hosting the two apps' variables land in the same
     * places, and a shared name is how one app ends up talking to the other's
     * database.
     *
     * @var array<string, array{0: string, 1: string}> env name => [path, cast]
     */
    private const ENV_MAP = [
        'RERM_BASE_PATH'      => ['app.base_path', 'string'],
        'RERM_DEBUG'          => ['app.debug', 'bool'],
        'RERM_STATUS_KEY'     => ['app.status_key', 'string'],
        'RERM_SETUP_KEY'      => ['app.setup_key', 'string'],

        'RERM_DB_HOST'        => ['db.host', 'string'],
        'RERM_DB_PORT'        => ['db.port', 'int'],
        'RERM_DB_NAME'        => ['db.name', 'string'],
        'RERM_DB_USER'        => ['db.user', 'string'],
        'RERM_DB_PASS'        => ['db.pass', 'string'],
        'RERM_DB_SOCKET'      => ['db.socket', 'string'],

        'RERM_SESSION_SECURE' => ['session.secure', 'bool'],

        // Deliberately absent: mail.enabled and mail.transport. Delivery is
        // armed by a considered edit to config.local.php on the machine that
        // should be sending, never by an environment variable that could be
        // inherited, copied between hosts, or set by a docker-compose file
        // somebody adapted from another project. See docs/spec-v1.md 3.3a.
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values)
    {
    }

    /**
     * Reads the three layers for an application root.
     *
     * $env is injectable so the tests can exercise the mapping without
     * mutating the real environment.
     *
     * @param array<string, string>|null $env defaults to getenv()
     */
    public static function load(string $root, ?array $env = null): self
    {
        $base = $root . '/config/config.php';
        if (!is_file($base)) {
            throw new RuntimeException("Missing configuration: {$base}");
        }

        /** @var array<string, mixed> $values */
        $values = require $base;
        if (!is_array($values)) {
            throw new RuntimeException("{$base} must return an array");
        }

        $local = $root . '/config/config.local.php';
        if (is_file($local)) {
            /** @var array<string, mixed> $overrides */
            $overrides = require $local;
            if (!is_array($overrides)) {
                throw new RuntimeException("{$local} must return an array");
            }
            $values = self::merge($values, $overrides);
        }

        /** @var array<string, string> $environment */
        $environment = $env ?? getenv();

        return (new self($values))->withEnvironment($environment);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * A dotted lookup. Throws on an unknown path unless a default is given,
     * because a silent null is how a missing database name becomes a
     * connection to nothing at all.
     */
    public function get(string $path, mixed $default = self::class): mixed
    {
        $cursor = $this->values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                if ($default === self::class) {
                    throw new RuntimeException("No configuration value at '{$path}'");
                }

                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /** @return array<string, mixed> */
    public function section(string $path): array
    {
        $value = $this->get($path);
        if (!is_array($value)) {
            throw new RuntimeException("Configuration '{$path}' is not a section");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /** @param array<string, string> $env */
    private function withEnvironment(array $env): self
    {
        $values = $this->values;

        foreach (self::ENV_MAP as $name => [$path, $cast]) {
            if (!array_key_exists($name, $env)) {
                continue;
            }
            $raw = $env[$name];
            // An empty variable is treated as absent rather than as an empty
            // string. Docker and cPanel both hand through variables that were
            // declared but never given a value, and an empty db.host is worse
            // than the committed default.
            if ($raw === '') {
                continue;
            }
            $values = self::set($values, $path, self::cast($raw, $cast));
        }

        return new self($values);
    }

    private static function cast(string $raw, string $type): string|int|bool
    {
        return match ($type) {
            'int'  => (int) $raw,
            'bool' => in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true),
            default => $raw,
        };
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function set(array $values, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $leaf     = array_pop($segments);

        $cursor = &$values;
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor[$leaf] = $value;
        unset($cursor);

        return $values;
    }

    /**
     * Recursive for associative arrays, replacing for lists.
     *
     * The distinction matters for exactly one key: mail.allowed_recipients. A
     * local override of that list means "these addresses and no others", so
     * merging it into the default would let an address the operator removed
     * keep receiving.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($value)
            ) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }
}
