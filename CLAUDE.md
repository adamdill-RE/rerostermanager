# Rodeo Express Roster Management (RERM)

Tracks the ~1,950 members of the Rodeo Express Committee against four
compliance metrics, and gives every officer a scoped, phone-first view of the
people they are responsible for chasing.

`docs/spec-v1.md` is the authoritative screen-by-screen specification.
`docs/data-findings.md` records what the real Rodeo Houston export actually
contains — read it before writing any import or permission code, because it
contradicts the original prose spec in seven measured places.

---

## Deployment target

Ahosting Reseller Gold — cPanel, LiteSpeed (LSAPI), CloudLinux+CageFS.
PHP 8.2.33, MySQL 8.0.41. **NO Node build step. NO Composer deps.**
Code deploys by file copy — nothing may require a build to run.
Server sh193 · cPanel 136.0 · EL9 · x86_64.

The database is MySQL 8.0.41 on a **separate host (152.160.193.196)**, not the
MariaDB cPanel reports — that one belongs to the web server and answers
`SQLSTATE[HY000] [1524] Plugin 'unix_socket' is not loaded` to anything that
reaches it. That error means the wrong server, never a wrong password.
No `RETURNING`. Full runtime measurements: `docs/hosting.md`.

### This app shares a domain with RESM

`reshiftmanager.com` now hosts three things, and only one repository may own
each path:

| Path | Owner | Deployed from |
| --- | --- | --- |
| `public_html/resm/` | **reshiftmanager** repo | its `public/` |
| `public_html/rerm/` | **this repo** | our `public/` |
| `public_html/index.html` | **this repo** | our `site/index.html` |

Live URL: `https://www.reshiftmanager.com/rerm/`
App code: `/home/reshiftmanager/rerm-app/` (a sibling of `public_html`)

Three rules follow, and breaking any of them takes RESM down during a shift:

1. **Never deploy a `.htaccess` to `public_html/` itself.** `DOCUMENT_ROOT` is
   `public_html`, so a root rewrite rule is evaluated for `/resm/` too. The
   landing page is therefore a single static `index.html` picked up by
   `DirectoryIndex` — no rewrites, no root config, nothing to interfere.
2. **Never `rm -rf` anything at `public_html/` level** in `.cpanel.yml`. Clear
   only `$WEB_DIR` (`public_html/rerm`), which this repo owns outright.
3. **Separate database, separate cookie, separate everything.** DB is
   `reshiftmanager_rerm`, session cookie is `RERMSESS` scoped to `/rerm/`,
   env prefix is `RERM_`. RESM must be able to fail without taking this with
   it, and a migration here must not be able to touch its tables.

The app is served from the `/rerm/` subpath, never the domain root. Never
hard-code a site-root path: every internal link, form action, redirect, asset
URL and cookie path is built from `app.base_path` (`/rerm/`) via
`$app->url()` / `$app->asset()`.

---

## Hard constraints

- **No WebSockets or SSE** — CloudLinux LVE caps entry processes. Polling only,
  and this app barely needs it: nothing here is real-time.
- **No localStorage for auth state.** Sessions are HttpOnly cookies. Host
  defaults are unsafe — `cookie_httponly`, `cookie_secure`, `samesite` and
  `use_strict_mode` are all off and `cookie_path` is `/`. Set every one
  explicitly before `session_start()`; path must be `/rerm/`.
- **Long sessions cannot be PHP sessions.** `gc_maxlifetime` is 1440s and GC is
  not ours on shared hosting. Use a DB-backed rotating selector/verifier token.
- **Every DB call via PDO prepared statements.** No string-built SQL, ever.
- **Role + scope enforced server-side on EVERY request.** Hiding a menu tile
  hides nothing.
- **`app/` is never web-accessible.** Only `public/` ships to `public_html/rerm/`,
  and `site/` ships one file to `public_html/`. `DOCUMENT_ROOT` is
  `/home/reshiftmanager/public_html`, so `app/` must sit outside it entirely.
- **Server dirs 0755, files 0644.** A 0700 dir yields 404, not 403.
- **The app cannot send email until somebody deliberately lets it.**
  `mail.enabled` ships `false` and `mail.transport` ships `file`; an allowlist
  and a per-request ceiling stand behind them, and `app.debug === true` forces
  `file` whatever config says. CI fails the build if the committed defaults
  could deliver. An import loads ~1,950 real addresses — a stray loop reaches
  real people and cannot be recalled. Full design: `docs/spec-v1.md` §3.3a.
- **Nothing ever deletes a member or a contact.** Purge is `purged_at`; every
  foreign key referencing `member` is `RESTRICT`, never `CASCADE`. Contact
  history must still be queryable years from now (`docs/spec-v1.md` §5.5).
- `max_input_vars` is **1000** and PHP truncates silently past it. A bulk
  assignment form covering an 85-person team must chunk or paginate.
- `max_execution_time` is 30s and `upload_max_filesize` is 2M. A 1,954-row
  import must stream and batch — see Phase 2.
- **`.xls`, `.xlsx` and `.csv` are all read natively**, by `Rerm\Roster`, with
  no Composer and no build step. `Spreadsheet::open()` chooses by **magic
  bytes, never by extension**. Rodeo Houston sends a legacy `.xls`, so this is
  load-bearing, not a nicety. Every cell comes back as a **string** — a float
  would turn Customer Number 1234567 into 1234567.0.

---

## Design system

Inherited from RESM verbatim so the two apps read as one product.

```
Rodeo Orange  #EF7622   accent only — 2.9:1 on white, never white text on it
Action Orange #B85416   buttons, white text OK
Rodeo Brown   #7F5E46   body text
Rodeo Dust    #C9B29B   surfaces and borders, never text
Dust Light    #F2EAE2   page and callout surfaces
Ink           #2B2018   primary text, and text on orange
```

Status colours (`--ok` #2F6B3A, `--warn` #8A5A00, `--danger` #A32B1C) always
pair with a word, never hue alone. Dark theme is required and follows the
device. Min touch target 56px, primary 64px.

### Where RERM departs from RESM

RESM is one screen used outdoors at 02:00 in February. RERM is a
data-comprehension tool used year-round, at a desk as often as on a phone.

- **`--page-max` is `34rem` in RESM and is wrong here.** Roster and dashboard
  screens use a wide container (`--page-wide: 78rem`) and become genuinely
  two-dimensional on a desktop. Menu, login and single-member screens keep the
  narrow phone column.
- **Every data table is responsive by transformation, not by scrolling.** Below
  720px a roster row becomes a stacked card with the four metric chips on one
  line and the call/text/email actions as 56px targets. Above 720px the same
  data is a real table with sortable columns. One template, one query, two
  layouts — never two codebases.
- **Contact actions are the mobile priority.** `tel:`, `sms:` and `mailto:`
  links are first-class targets, and `sms:` is suppressed when the member's
  phone type is not `CELL PHONE` (116 members in the sample — see below).

---

## Committee topology

Four levels exist in the export; the app models **three** and treats the fourth
as display grouping only.

```
Committee                     1,954 members
  Division                    Subcommittee 3 — 4 values, plus (No Division)
    [Area]                    NOT in the data — derived from team-name prefix,
                              display grouping ONLY, never an access check
      Team                    Subcommittee 1 — 96 values
```

`Subcommittee 2` is junk (`Tba 9` ×1,898) and is not imported.

**`(No Division)` is a real division row, not a NULL.** 72 members arrive with
a blank `Subcommittee 3` and they land here, so `member.division_id` is
`NOT NULL` and no query carries a null branch. Three things follow from it
being a placeholder rather than a fact, and all three are load-bearing:

- It is flagged `is_placeholder = 1`. An officer **can** be scoped to it, so
  those 72 people have an owner — which a NULL could never give them.
- **Every import re-evaluates it.** A member whose `Subcommittee 3` arrives
  populated moves out to the real division; one that arrives blank moves in.
  Membership is never sticky.
- **The export writes it back as blank, never as "No Division".** It is our
  bookkeeping, not Rodeo Houston's data, and it must not travel back to them
  as though it were. A test asserts that.

The `no_division` import warning still fires. The bucket makes those members
reachable; it does not make them correctly placed.

**The `area` column on `team` is nullable, seeded by prefix heuristic, and
editable by an Admin. It must never appear in `Rerm\Auth\Access`.** A test
asserts that. It exists so a 96-team dashboard is legible, nothing more.

### Scope is derived from title, not from placement

The Chairman, the Vice President and all four Division Chairmen are filed under
`Logistics Division / Administration` in the export. Their own `Subcommittee 3`
value is therefore meaningless as a scope. This is why scope comes from the
**title-to-level map** below and only Senior and Officer levels read the
member's own division or team.

---

## Access levels

Five levels. `Rerm\Auth\Level` is the enum; `Rerm\Auth\Capability` and
`Rerm\Auth\Access` are the matrix, transcribed once and re-transcribed in
`tests/access_test.php` so a change has to be made twice on purpose.

| Level | Sees | Titles that map here |
| --- | --- | --- |
| **Admin** | Everything, plus import/export/show-year | none — designated only |
| **Executive Officer** | Whole committee | Chairman, Vice President, Officer in Charge, Division Chairman |
| **Senior Officer** | Their whole Division | Division Vice Chairman, Coordinator, Ambassador |
| **Officer** | Their Team only | Vice Chairman, Captain, Assistant Captain |
| **Member** | No login | everything else |

**Titles with no login:** `Committee Member`, `Lifetime Committeemen`,
`Lifetime Vice Presidents`, `Lifetime Director`, `Past Committee Chairman`.
An individual may still be granted a login as an **Allowed User** (below).

Match title strings **exactly as the export spells them** — `Division Chairman`
(singular), `Division Vice Chairman` (not "Divisional"), `Lifetime Vice
Presidents` (plural), `Lifetime Committeemen` (plural). An unrecognised title
imports as **Member with no login** and raises an import warning naming it. It
never silently becomes an officer.

### Allowed Users

An Admin, Executive Officer or Senior Officer may grant any imported member a
level **at or below their own**. A grant:

- creates a login if the member has none (initial password `1234`, forced reset);
- is **durable** — it survives every subsequent import regardless of what the
  member's title becomes;
- records who granted it and when, in `audit_log`;
- is revocable by anyone who could have granted it.

A granted level *replaces* the title-derived level. Scope for a granted Senior
Officer is still their own division, and for a granted Officer still their own
team, unless an Admin sets an explicit scope override.

---

## The four metrics, and the fifth

| App name | Export column | Values |
| --- | --- | --- |
| HLSR Dues | `Show Dues` | Y / N |
| Committee Dues | `Committee Dues` | Y / N |
| Indemnity | `Indemnity` | Y / N |
| Background Check | `Background Check Completed` | Y / N |
| *Harassment Training* | `Harassment prevention training` | Y / N / **blank** |

Harassment training is **tri-state** — 1,716 of 1,954 rows are blank, which is
not the same as N. It is imported and displayed but is **not** one of the four
scored metrics and does not enter any completion percentage. Blank renders as
"Not reported", never as a failure.

### Imported truth vs. tracked progress

The official roster lags reality. Every metric therefore carries two values:

- **`imported`** — `Y`/`N` from the last import that covered this member. Never
  written by a user.
- **`progress`** — ours: `not_started` → `in_progress` → `claimed_complete`.
  Set by an officer after contact. `in_progress` means "they told me they are
  paying/signing".

The **effective status** shown everywhere is a pure function of the two:

```
imported = Y                       -> Complete   (green)
imported = N, progress = claimed   -> Reported    (blue)  awaiting next import
imported = N, progress = in_prog   -> In Progress (amber)
imported = N, progress = none, contacted -> Contacted  (amber outline)
imported = N, progress = none, never contacted -> Outstanding (red)
```

An import that flips `imported` to `Y` **clears** the progress row — the thing
being tracked has happened, and the clearing is written to `audit_log` with the
batch that caused it. An import that leaves it `N` **keeps** progress, so an
officer's work is not erased by a roster refresh.

`contact_log` is untouched by any of this, ever. Progress is a status flag;
the contact history is the record, it is retained across every show year, and
producing a member's history going back years is a v2 feature that v1 exists
to keep possible.

---

## Data contract with the Rodeo Houston export

Measured against `Same_Full_Coommittee_Dataset.xls`, 1,954 rows × 33 columns.
Full analysis in `docs/data-findings.md`. The load-bearing facts:

- **`Customer Number` is the natural key**, not "Member Number". 6–7 digits,
  1,954/1,954 unique. Stored `VARCHAR(32)` — it is an identifier, never
  arithmetic, and leading zeros must survive a round trip. The seeded master
  admin uses `987654321`, safely outside the observed range.
- **Names are not unique.** 1,951 unique of 1,954. Never key on a name.
- **Email is not unique and may be absent.** Two addresses are shared by four
  people; one member has none at all. Email is contact and recovery only,
  never a login and never a key.
- **Phone type matters.** 1,838 `CELL PHONE`, 111 `HOME`, 5 `BUSINESS`.
  Suppress `sms:` for the 116 non-cell numbers rather than offering a text
  that silently fails.
- **7 teams span two divisions** and **72 members have no division**, 15 of
  them ordinary Committee Members on real teams. They land in the real
  `(No Division)` row above, so a division-scoped officer can be given them
  rather than them belonging to nobody.
- **41 of 96 teams have fewer than two officers**, 7 have none, covering 432
  members. Assignment is same-team only (decided), so those members surface in
  an explicit **"No officer on team"** bucket rather than silently vanishing.
- **Dead columns in this export** — `Badge Released` (all N), `Badge Released
  Date`, `Badge Issue Date`, `Eligible for Service History`, `Eligibility
  Updated By` (all empty), `LTC Applied` (all N). Imported to columns that
  exist but are not surfaced in any screen until they carry data.

Phone numbers arrive uniformly as `(555) 555-0100`. Keep the imported string
for display and an E.164 form for `tel:`/`sms:` — same two-column pattern as
RESM's `PhoneNumber`.

---

## What an import owns, and what it must never touch

The single most important rule in this application. An import refreshes what
**Rodeo Houston** knows and never overwrites what **we** know. Full column
table in `docs/spec-v1.md` §6.6.

**HLSR owns — every import overwrites, unconditionally:**
title · team · division · names · address · phone · phone type · email ·
the four metric `imported` values · harassment training · rookie ·
in-other-committees · legal-name-verified · badge pickup

**We own — no import ever writes these:**
Allowed User grants and who made them · scope overrides · passwords and
must-change flags · `contact_log` in full · officer assignments ·
metric `progress` and its note and author · `team.area` · `audit_log`

That boundary is what makes a designation **durable**: an import rewrites
`member.title` and the title-derived level, and never `app_user.granted_level`.
Effective level is `granted_level ?? title_level`, so a Committee Member made
a Senior Officer stays one no matter what the next roster calls them.

### The one exception, and it is deliberate — confirmed

**When an import flips a metric's `imported` value from `N` to `Y`, that
metric's `progress` resets to `not_started`.** The thing being chased has
happened, so "in progress" is now false. Without it, a later correction back
to `N` would resurface a months-old status as though it were current.

The reset is recorded, never silent: the prior value goes to `audit_log` with
the batch that cleared it. **`contact_log` is never touched by it** — the
record of who called whom, and when, and what was said survives every import
unconditionally. That is what answers "why did Johnson's dues flip back to N".

### Two consequences worth designing for

- **A demotion by import revokes login.** Captain → Committee Member drops the
  title level to Member, so `app_user.is_active` goes to 0 — unless a
  `granted_level` holds it open. The row is deactivated, never deleted; the
  audit trail outlives the account.
- **A demotion orphans assignments.** Members assigned to an officer who is no
  longer an officer, or no longer on the team, surface on the Assign screen as
  **"officer no longer eligible"** and need reassignment. The rows are not
  deleted — a silently emptied assignment is how 20 people stop being chased
  without anyone noticing.

---

## Build phases

Each phase ends shippable. `docs/spec-v1.md` carries the detail.

| Phase | What lands | Done when |
| --- | --- | --- |
| **0 · Foundation** | Repo, `.cpanel.yml`, docker, CI, migrator, config layering, root landing page, `/status` | `git push` + Deploy HEAD serves `/rerm/` and `/` |
| **1 · Schema** | All tables, show years, reference data, master admin seed | `php bin/migrate.php` clean on MySQL 8.0 **and** MariaDB 10.11 |
| **2 · Import** | Three import modes, dry-run preview, title mapping, warnings report, purge flagging | 1,954 rows import inside 30s with a diff shown before commit |
| **3 · Auth** | Login by member number, forced first reset, email recovery, rotating tokens, rate limit, the permission matrix | `tests/access_test.php` green; every route guarded |
| **4 · View My Roster** | Scoped roster, predictive search, team filter, contact links, expandable contact history | An Officer sees their team and nothing else, on a phone |
| **5 · My Roster Status** | The dashboard, the member list, log-a-contact, progress statuses, mine/team toggle | Effective-status table above is provably correct |
| **6 · Assign Officers** | Unassigned isolation, bulk assign, thin-team flagging | Every assignable member has 1–3 officers or a named reason |
| **7 · Committee Dashboard** | Roll-up by division and team with drill-down | An Executive can find the worst team in two taps |
| **8 · Admin** | Designate Admins and Allowed Users, export by show year, show-year start/stop | A full round trip: import → work → export |
| **9 · v2** | Create Forms; recruiting and retention automation | out of scope for v1 |

Phases 4 and 5 are the product. Everything before them is plumbing and
everything after is leverage — if the schedule slips, it slips at 7 and 8.

---

## Commands

```sh
docker compose up -d                 # http://localhost:8080/
docker compose exec web php bin/migrate.php
docker compose exec web php tests/run.php
php bin/import-roster.php --dry-run path/to/roster.xls
```

Deploy: `git push`, then **Deploy HEAD Commit** in cPanel. Migrations are never
automatic — run `php bin/migrate.php --status` first.

---

## Working on this

- **Escape every rendered value** with `e()`. Bind every query parameter. No
  exceptions to either. A named PDO placeholder cannot be reused within one
  statement — emulated prepares are off.
- **Every POST checks `Csrf::check()`.** Reaching a route proves nothing.
- **Migrations are immutable once applied.** The runner checksums them. Add a
  new file instead. Pure-data migrations may opt into a transaction with
  `-- rerm:atomic`; schema migrations cannot, because MySQL commits implicitly
  on DDL.
- **Nothing hard-codes `/rerm/`** outside `config/config.php`.
- **Imports are append-only in their record.** Every `import_batch` keeps its
  row counts, warnings and the user who ran it. "Why did Johnson's dues flip
  back to N" must stay answerable.
- **Never destructively purge on import.** A complete roster flags absent
  members; an Admin confirms the purge as a separate, logged action.
