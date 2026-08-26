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

## Applied in order

| File | What it does |
| --- | --- |
| `001_schema.sql` | Every table in `docs/spec-v1.md` §5.2 and §5.2a. Schema, so **not** atomic. |
| `002_seed_reference.sql` | The four export divisions, the seeded `(No Division)` placeholder, the first show year. Pure data, atomic. |
| `003_seed_master_admin.sql` | Member number `987654321` and its Admin account, **shipped locked**. Pure data, atomic. |

`schema_migration` is created by the migrator itself rather than by a
migration: something has to exist before the first migration can be recorded,
and a migration that records itself is a chicken-and-egg problem with a worse
failure mode. It follows the same conventions as everything above, because
`tests/schema_test.php` checks every table in the database.

## Writing one

```sh
php bin/migrate.php --status      # what is applied, what is pending
php bin/migrate.php --dry-run     # what would run, and how many statements
php bin/migrate.php               # apply
```

Three things the migrator refuses outright, each naming the file and the
statement:

- **A migration that changed after it was applied.** Add a new file.
- **`-- rerm:atomic` on a file containing DDL.** MySQL commits implicitly on
  DDL, so the transaction could not roll it back and would report a rollback
  that did not happen.
- **`RETURNING`.** A MariaDB extension. It passes CI's MariaDB job, passes
  review, and fails on the only server that matters.

A schema migration has no transaction to fall back on, so write it to survive
being re-run: `CREATE TABLE IF NOT EXISTS` throughout, and anything MySQL has
no `IF NOT EXISTS` form for — `ADD CONSTRAINT`, for instance — guarded against
`information_schema` first. `001_schema.sql` shows both.

## Master admin

`003` seeds the account that unlocks a brand-new database, and it **ships
locked**: `password_hash` is `'*'`, the `/etc/shadow` convention, and not a
hash of anything. `password_verify()` returns false against it for every
input, and `tests/schema_test.php` asserts that over a battery of candidates.

No password hash belongs in this file or any other. This repository is public
— it has to be, because cPanel's Deploy HEAD Commit reads it over HTTPS — and
a bcrypt hash of a weak password is a weak password, published, on an
application that will hold ~1,950 people's home addresses and phone numbers.
Unlock the account deliberately, once, on the machine running the app.
