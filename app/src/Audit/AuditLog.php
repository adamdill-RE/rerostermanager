<?php

declare(strict_types=1);

namespace Rerm\Audit;

use JsonException;
use PDO;
use Rerm\App;
use Rerm\Auth\User;

/**
 * The one place an `audit_log` row is written by Phase 8.
 *
 * Six screens land here — grants, revocations, scope overrides, purges,
 * restores, show-year changes, the rollover, team edits and every export —
 * and each of them writing its own INSERT would be nine more places for the
 * JSON encoding, the IP truncation or the actor to go quietly wrong. Phases 2
 * to 6 each wrote their own; that was fine at one writer per phase and stops
 * being fine at nine in one.
 *
 * Two rules the shape enforces rather than documents:
 *
 *   * **`before_json` / `after_json` are real JSON or NULL.** MariaDB
 *     implements the JSON type as LONGTEXT with a `json_valid` CHECK, so a
 *     bare string that MySQL accepts is REJECTED there — and this repository
 *     ships to MySQL while CI proves both. json() encodes or returns null;
 *     it never hands the driver a fragment.
 *   * **Nothing here reads or writes anything else.** An audit row is
 *     append-only and outlives what it describes, which is why `entity` and
 *     `entity_id` carry no foreign key (001_schema.sql). Writing the row is
 *     therefore never conditional on the entity still existing.
 *
 * Batched, because the database is on another machine (docs/hosting.md): a
 * purge of fifty members is one round trip, not fifty.
 */
final class AuditLog
{
    /** Rows per INSERT. Fifty x seven placeholders is comfortable. */
    private const CHUNK = 50;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db());
    }

    /**
     * One row.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        ?User $actor,
        Action $action,
        string $entity,
        string $entityId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->recordMany($actor, [[
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'before'    => $before,
            'after'     => $after,
        ]]);
    }

    /**
     * Many rows, one actor, chunked inserts.
     *
     * @param array<int, array{action: Action, entity: string, entity_id: string,
     *     before?: ?array<string, mixed>, after?: ?array<string, mixed>}> $rows
     */
    public function recordMany(?User $actor, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // The actor is nullable because two real cases have none: the CLI
        // password reset (bin/set-admin-password.php) and a refused session
        // token, where the whole point is that nobody was authenticated.
        $actorId = $actor?->id;
        $ip      = self::ip();

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $places = [];
            $bind   = [];

            // Numbered per chunk: a named placeholder cannot be reused within
            // one statement here, emulated prepares being off.
            foreach (array_values($chunk) as $i => $row) {
                $places[] = "(:actor_{$i}, :action_{$i}, :entity_{$i}, :entity_id_{$i},"
                    . " :before_{$i}, :after_{$i}, :ip_{$i})";

                $bind[":actor_{$i}"]     = $actorId;
                $bind[":action_{$i}"]    = $row['action']->value;
                $bind[":entity_{$i}"]    = substr($row['entity'], 0, 64);
                $bind[":entity_id_{$i}"] = substr($row['entity_id'], 0, 64);
                $bind[":before_{$i}"]    = self::json($row['before'] ?? null);
                $bind[":after_{$i}"]     = self::json($row['after'] ?? null);
                $bind[":ip_{$i}"]        = $ip;
            }

            $this->pdo->prepare(
                'INSERT INTO audit_log'
                . ' (actor_user_id, action, entity, entity_id, before_json, after_json, ip)'
                . ' VALUES ' . implode(', ', $places)
            )->execute($bind);
        }
    }

    /**
     * A payload as JSON, or null.
     *
     * Null in, null out — the column is nullable and NULL is the honest value
     * for "there was no before". An array that cannot be encoded (a resource,
     * a recursive structure) also becomes null rather than a fragment: an
     * audit row with an empty payload still records who did what and when,
     * and a row rejected by the CHECK records nothing at all.
     *
     * @param array<string, mixed>|null $payload
     */
    public static function json(?array $payload): ?string
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return null;
        }
    }

    /** The requesting address, as every throttle, token and audit row records it. */
    private static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }
}
