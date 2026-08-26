# Migrations

Numbered `.sql` files, applied once each in order and never edited afterwards.
The migrator records a SHA-256 of every applied file and refuses to run if one
has changed — add a new migration instead.

A migration whose contents are **pure data** may opt into a transaction with a
`-- rerm:atomic` line. Schema migrations cannot: MySQL commits implicitly on
DDL, so a half-applied `CREATE TABLE` run cannot be rolled back and the file
must be written so that re-running the *next* attempt is safe.

## Conventions

Every one of these is load-bearing on this host — see `docs/hosting.md`.

- **Name `ENGINE=InnoDB` and `COLLATE=utf8mb4_unicode_ci` explicitly on every
  table.** MariaDB's defaults differ from MySQL's (`utf8mb4_0900_ai_ci` here)
  and the server default is not ours to rely on. A test asserts it.
- **Every `DATETIME` is UTC.** The connection pins `time_zone` to `+00:00`, so
  `CURRENT_TIMESTAMP` defaults record UTC too. Display converts to
  `America/Chicago` through a real timezone, never a fixed offset.
- **Uniqueness keys over generated columns are `VIRTUAL`, never `STORED`.**
  Under MySQL, a column that a STORED generated column reads cannot carry
  `ON DELETE CASCADE`: error 1215, and the table simply will not create.
  MariaDB accepts the same shape, which is how RESM shipped one that
  production could not build.
- **No `RETURNING`.** That is a MariaDB extension. An insert that needs its own
  row back takes a second statement.
- **Nothing is deleted to deactivate.** `is_active` flags exist so that
  retiring a team or a division preserves the records pointing at them, which
  is why most foreign keys `RESTRICT`.
- **Operational history is append-only.** `contact_log` rows are never updated
  and `assignment` rows are superseded via `removed_at`, so "who said this
  member was paying, and when" stays answerable.

## Planned

`001_schema.sql` implements `docs/spec-v1.md` §5.2 in full — Phase 1.
