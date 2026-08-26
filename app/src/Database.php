<?php

declare(strict_types=1);

namespace Rerm;

use PDO;
use PDOException;
use RuntimeException;

/**
 * The one place a PDO connection is made.
 *
 * Every attribute below is set because the default is wrong for this host, and
 * two of them were paid for on the sibling application first.
 *
 * The database is on a SEPARATE MACHINE from the web server — 152.160.193.196,
 * the address cPanel shows under Remote MySQL (docs/hosting.md). Pointing this
 * at localhost or 127.0.0.1 reaches the MySQL instance on the web server
 * instead, which answers:
 *
 *     SQLSTATE[HY000] [1524] Plugin 'unix_socket' is not loaded
 *
 * That is the wrong server, not a wrong password. No password reset can fix
 * it, so connect() re-states it rather than letting the raw driver message
 * send somebody to cPanel's Change Password for an afternoon.
 */
final class Database
{
    /**
     * Pinned on connect, before the first query of the request can run.
     *
     * time_zone is an OFFSET and not the name 'UTC' on purpose: named zones
     * need the mysql.time_zone_* tables loaded, which is the server
     * administrator's business on shared hosting and not ours. '+00:00' works
     * on an empty server. Every DATETIME in this schema is UTC, and so is
     * every CURRENT_TIMESTAMP default, because of this line. Display converts
     * to America/Chicago through a real timezone, never a fixed offset —
     * Houston observes DST and the show runs across the March change.
     *
     * sql_mode is pinned for the same reason the schema names its collation:
     * MySQL 8.0 and MariaDB 10.11 ship different defaults, CI runs both, and
     * a difference that only appears on one of them is a defect discovered in
     * production. STRICT_ALL_TABLES means a value too long for its column
     * raises instead of being truncated — a silently shortened member number
     * is a wrong member.
     */
    private const INIT_COMMAND =
        "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'";

    /** @param array<string, mixed> $db the config's db section */
    public static function connect(array $db): PDO
    {
        $dsn = self::dsn($db);

        try {
            return new PDO(
                $dsn,
                (string) ($db['user'] ?? ''),
                (string) ($db['pass'] ?? ''),
                [
                    // Nothing checks a return value in this codebase; every
                    // failure arrives as an exception or not at all.
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

                    // Real server-side prepares. Emulation would let a bound
                    // parameter be interpolated by the driver, and it is also
                    // why a named placeholder cannot be reused twice within
                    // one statement — bind each occurrence its own name.
                    PDO::ATTR_EMULATE_PREPARES   => false,

                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                    // With emulation off the driver returns native types, so
                    // an INT column arrives as an int. Left on, every integer
                    // would be a string and === comparisons would quietly
                    // fail.
                    PDO::ATTR_STRINGIFY_FETCHES  => false,

                    // Runs inside the connection handshake, so it costs no
                    // extra round trip to a database on another machine.
                    PDO::MYSQL_ATTR_INIT_COMMAND => self::INIT_COMMAND,
                ]
            );
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'unix_socket')) {
                throw new RuntimeException(
                    "Connected to the wrong database server. db.host is '"
                    . (string) ($db['host'] ?? '')
                    . "'; this account's MySQL runs on separate hardware — the address "
                    . 'under Remote MySQL in cPanel. See docs/hosting.md. '
                    . 'This is not a credentials problem and changing the password cannot fix it.',
                    (int) $e->getCode(),
                    $e
                );
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $db */
    public static function dsn(array $db): string
    {
        $name = (string) ($db['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('db.name is not configured');
        }

        $socket = $db['socket'] ?? null;
        if (is_string($socket) && $socket !== '') {
            return sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name);
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) ($db['host'] ?? '127.0.0.1'),
            (int) ($db['port'] ?? 3306),
            $name
        );
    }
}
