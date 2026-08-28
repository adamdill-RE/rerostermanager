# Rodeo Express — Roster Management (RERM)

Tracks the ~1,950 members of the Rodeo Express Committee against four
compliance metrics — HLSR dues, committee dues, background check, indemnity —
and gives every officer a scoped, phone-first list of the people they are
responsible for chasing.

Server-rendered PHP 8.2 and MySQL 8.0. No build step, no Composer, deployed by
file copy.

| Document | What it is |
| --- | --- |
| **`CLAUDE.md`** | Constraints, topology, access model and the phase plan. Start here. |
| **`docs/spec-v1.md`** | Authoritative screen-by-screen specification. |
| **`docs/data-findings.md`** | What the real Rodeo Houston export contains. Read before writing import or permission code. |
| **`docs/hosting.md`** | The measured server environment. |

## Where this build stands

**Phase 2 — Import, complete.** `docs/spec-v1.md` §6 in full: three modes, a
staged preview with a row-by-row diff, thirteen kinds of warning and absence
flagging that never deletes anybody. A 1,954-row roster stages and applies in
about a second against a limit of thirty, and a second import of the same file
reports 1,954 unchanged and nothing created — which is the assertion that
proves the diff is honest.

It runs two ways, because this host has **no SSH and no cPanel Terminal**, so a
CLI-only importer is one production could not use:

- **`/import`**, guarded for now by `app.setup_key` — the only thing that can
  load a roster onto the live site. Phase 3 replaces that guard with
  `Capability::IMPORT_ROSTER`; the marker is in `public/index.php`.
- **`bin/import-roster.php`**, for local work against a real file.

There is a **second, separate import** for contact history — contacts made
before this application existed (`docs/spec-v1.md` §6.7). `/import-contacts`
and `bin/import-contacts.php`, its own tables, its own capability
(`import_contact_history`), its own audit verb. Separate because it writes
`contact_log`, which the roster import may never touch; it is the counterpart
to §6.6's boundary rather than a hole in it. Rows land on the date they really
happened, attributed to the officer who really made them, and loading the same
file twice writes them once.

Both are two steps with a diff in between, and neither writes to `member` until
a second, explicit act naming a batch id. The rule underneath all of it is
`docs/spec-v1.md` §6.6: **an import refreshes what Rodeo Houston knows and
never overwrites what we know.** Allowed User grants, scope overrides,
passwords, contact history, officer assignments, tracked progress and a team's
area all survive it — with one deliberate exception, a metric moving `N` to `Y`
clearing that metric's progress, which is written to `audit_log` rather than
happening quietly.

**Phase 1 — Schema, complete.** Every table in `docs/spec-v1.md` §5.2 exists as
`db/migrations/001_schema.sql`, with the divisions, the seeded `(No Division)`
placeholder and the first show year in `002`, and a master administrator that
**ships locked** in `003` — no password hash is committed, and none ever may be,
because this repository is public.

Alongside them: `Rerm\Migrator` and `bin/migrate.php` (`--status`, `--dry-run`,
and a checksum registry that refuses to run if an applied migration changed),
`Rerm\Database`, and the composition root the rest of the application hangs off
— `app/bootstrap.php`, `Rerm\Config`, `Rerm\App`. CI applies the migrations
twice on **both** MySQL 8.0 and MariaDB 10.11 and runs `tests/schema_test.php`
against each.

**Phase 0's front controller, retrofitted.** `public/index.php` was never
built, so a deployed `/rerm/` answered **403**: `.htaccess` sets
`DirectoryIndex index.php` and `Options -Indexes`, and a mount directory with
no `index.php` is a forbidden directory listing rather than an empty
application. It now serves a holding page, and `/status` — guarded by
`app.status_key` — reports PHP, the database connection, the migration state
and whether `var/` is writable, which is how a bad deploy gets diagnosed on a
host with no shell to hand.

Phase 3 is next: authentication and the capability matrix
(`docs/spec-v1.md` §3 and §4). `Rerm\Auth\TitleMap` and `Rerm\Auth\Level`
already exist, because the import writes `member.title_level` on every row and
creates an account for every officer title; what remains is login, the forced
first reset, recovery, rotating tokens, the rate limit and `ScopedQuery`.

```sh
php bin/migrate.php --status      # what is applied, what is pending
php bin/migrate.php --dry-run     # what would run, without running it
php bin/migrate.php               # apply
php tests/run.php --strict        # what CI runs

php bin/import-roster.php roster.xls        # parse, diff, stage — writes nothing
php bin/import-roster.php --apply=<id>      # the step that writes
php bin/import-roster.php --dry-run f.xls   # parse, diff, keep nothing
php bin/import-roster.php --list            # what is staged, and until when

# Contacts made before the app existed (spec 6.7). Same two steps.
php bin/import-contacts.php --officer=<number> --team="Bus Ops Team A" f.csv
php bin/import-contacts.php --apply=<id>
php bin/import-contacts.php --template      # a CSV header row it understands
```

## Getting started

```sh
docker compose up -d
open http://localhost:8081/
```

The local environment mirrors the server deliberately. `public/` is mounted
inside the document root at `/rerm/`, `site/index.html` is mounted at the
document root itself, application code is mounted at a *sibling* of the
document root, and `docker/php/php.ini` reproduces production's limits — down
to leaving the unsafe session defaults unsafe, so code that relies on a safe
one fails here rather than on the server.

Ports are 8081 and 3308 rather than 8080 and 3307, because RESM's compose file
uses those and both are often up at once.

## Layout

| Path | What it is |
| --- | --- |
| `app/` | Application code. **Never web-accessible** — see below. |
| `app/src/` | Classes, autoloaded as `Rerm\…` (no Composer). |
| `bin/` | CLI entry points: migrations, import, admin password. |
| `config/` | `config.php` is committed; `config.local.php` holds credentials and is not. |
| `db/migrations/` | Numbered `.sql` files, applied once each in order. |
| `public/` | The `/rerm/` document root — the only directory that reaches the web server. |
| `site/` | **Exactly one file**, `index.html`, which lands at the domain root. |
| `docs/` | The spec, the data analysis, the measured hosting environment. |
| `tests/` | A small runner and the suite. |

## This app shares a domain with RESM

`reshiftmanager.com` hosts two applications and a landing page. Only one
repository may own each path:

| Path | Owner |
| --- | --- |
| `public_html/resm/` | the **reshiftmanager** repository |
| `public_html/rerm/` | **this** repository |
| `public_html/index.html` | **this** repository |

`DOCUMENT_ROOT` is `public_html` itself. Three rules follow, and breaking any
of them takes RESM down during a shift:

1. **No `.htaccess` at `public_html/`.** A root rewrite rule is evaluated for
   `/resm/` requests too. This is why the landing page is a static `index.html`
   with inline CSS — `DirectoryIndex` picks it up and it introduces no
   configuration at all. `site/` is checked in CI to contain only that file.
2. **No recursive copy or delete at document-root level** in `.cpanel.yml`.
   The landing page is copied as a single named file. CI greps for violations.
3. **Separate everything.** Database `reshiftmanager_rerm`, session cookie
   `RERMSESS` scoped to `/rerm/`, env prefix `RERM_`. The two apps must be able
   to fail independently.

### Why `app/` sits outside the document root

Everything under `public_html` is reachable by URL, including anything placed
beside `rerm/`. Application code therefore lives in a sibling directory,
`/home/reshiftmanager/rerm-app/`, and is reached by filesystem path.
`public/index.php` finds it by probing, so the same file works locally and on
the server with nothing to configure.

Hiding code inside the document root behind an `.htaccess` rule would be
strictly weaker and easy to get wrong.

## Deploying

`git push`, then **Deploy HEAD Commit** in cPanel. `.cpanel.yml` copies
`public/` into `public_html/rerm/`, `site/index.html` to `public_html/`, and
the rest into `~/rerm-app/`, then fixes modes to 0755/0644.

**The database is not on the web server.** Ahosting runs it separately, so
`db.host` is the address cPanel shows under Remote MySQL — an IP rather than a
hostname — not `localhost` and not `127.0.0.1`. Point the app at this machine
and you reach a different MySQL instance, which answers
`SQLSTATE[HY000] [1524] Plugin 'unix_socket' is not loaded`. That reads like a
credentials problem and is not one — it is the local instance refusing an
account it has never heard of. No password reset will fix it.

`config.local.php` is the one file on the server the deploy does not own — it
holds the database password, is not in git, and must survive every deploy. The
deploy therefore never chmods `config/` recursively.

Migrations are **not** run automatically:

```sh
cd ~/rerm-app
php bin/migrate.php --status
php bin/migrate.php
```

## Working on this

**Never commit a roster.** A real export carries ~1,950 people's home
addresses, phone numbers and email addresses, and this repository is public.
`.gitignore` blocks `*.xls`, `*.xlsx`, `*.csv` and `/data/`, and CI fails the
build if one is tracked anyway.

**Migrations are immutable once applied.** The runner records a checksum and
refuses to run if an applied file has changed. Add a new migration instead. A
pure-data migration may opt into a transaction with a `-- rerm:atomic` line;
schema migrations cannot, because MySQL commits implicitly on DDL.

**Uniqueness keys over generated columns are `VIRTUAL`, never `STORED`.** Under
MySQL a column a STORED generated column reads cannot carry `ON DELETE
CASCADE` — error 1215, and the table will not create. MariaDB accepts the same
shape, which is exactly how RESM shipped one that production could not build.
CI runs both engines for that reason.

**Nothing hard-codes `/rerm/`** outside `config/config.php`. Build every URL
with `$app->url(…)` and `$app->asset(…)`. CI greps for violations.

**Escape every rendered value** with `e()`, and bind every query parameter.
There is no exception to either. A named PDO placeholder cannot be reused
within one statement — emulated prepares are off.

**Every POST checks `Csrf::check()`,** and every handler that needs a user asks
for one itself. Reaching a route proves nothing about permission.

**Scope is enforced in the query, not the view.** Every roster read goes through
`Rerm\Roster\ScopedQuery::forUser()`. A screen cannot forget to filter because
it never builds the `WHERE` clause itself.

**Imports never delete.** A complete roster *flags* members it did not see;
purging is a separate, explicitly confirmed, logged action. Every
`import_batch` keeps its row counts, warnings and the user who ran it, because
"why did Johnson's dues flip back to N" must stay answerable.
