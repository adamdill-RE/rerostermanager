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
| **`docs/spec-v1.md`** | Authoritative screen-by-screen specification for v1. Closed — the record of what was decided and why. |
| **`docs/spec-v2.md`** | v2, from Create Forms on. Cross-references v1 rather than editing it. |
| **`docs/data-findings.md`** | What the real Rodeo Houston export contains. Read before writing import or permission code. |
| **`docs/hosting.md`** | The measured server environment. |

## Where this build stands

### For the next session

This branch is **1.12.0**: Phase 12, Look Up Members — the second feature
to come from a real user, and the first for the Admin's desk
(`docs/spec-v2.md` §13). Before it, `main` was 1.11.0: Phase 11, Track RCFs,
merged as [#27](https://github.com/adamdill-RE/rerostermanager/pull/27).
The server carries whatever was last deployed with cPanel's **Deploy HEAD
Commit**; the footer of every screen and `/status` say which build that is.
**Phase 12 needs no migration** — it reads tables 010 and 011 created and
adds nothing — but it does need both of those applied, and says so with a
sentence rather than a blank page if they are not. Phase 11's
`011_rcf_tracking.sql` is still the one to check with
`php bin/migrate.php --status` after a deploy.

**How each phase was worked, and what to keep doing:** one branch per phase
restarted from `origin/main`; `php tests/run.php --strict` against a
reachable database (`RERM_DB_HOST`, `RERM_DB_NAME`, `RERM_DB_USER`,
`RERM_DB_PASS` — the docker database, or any MySQL 8 / MariaDB 10.11 with the
migrations applied); the three CI scripts locally before a commit
(`.github/check-no-pii.py`, `.github/check-deployment.py`,
`.github/check-mail-safety.php`); a PR against `main`; CI green on **both**
MySQL 8.0 and MariaDB 10.11; then the merge. Every phase bumps `app.version`
in `config/config.php` in the commit that closes it, adds a paragraph below
this one, a section to `docs/spec-v2.md`, and a row to `CLAUDE.md`'s phase
table. Tests are transcribed beside a generated fixture, never computed from
the code under test, and every new file's examples come from `example.com`
and `(555) 555-01xx` — the repository is public and the PII check fails the
build on anything else.

**What is next is a decision, not a backlog.** The candidates, all
documented and none started:

- **`docs/spec-v2.md` §11**, the open items. The nearest to ready are
  V2-11 (the Division Chairman's numbered form, generated from tracked
  lines — the second half of the feedback that produced Phase 11, and the
  tracking it needs now exists), V2-10 (a Vice Chairman seeing their
  Officers' forms), V2-3 (which form follows the Roster Change Form —
  `/forms` is shaped for it), V2-7 and V2-8 (the team default and the
  Result column on View My Roster), and V2-6 (whether `import_change` is
  retained forever).
- **OI-12, multi-year contact history reporting.** The member card already
  shows every show year's contacts for one person (§9.1); what remains is the
  report across people, and `import_change` is the shape it should take.
- **`CLAUDE.md`'s 10.x row** — recruiting and retention automation — which
  has no design yet and should get a spec-v2 section before any code.

Whichever it is, the constraints that shaped the last three phases still
hold: no script, no framework, no build step, one template with two layouts
at 720px, every figure landing on exactly the people it counted, and nothing
that ever deletes a member or a contact.

**Phase 12 — Look Up Members.** An Admin gets lists of member numbers from
outside — Rodeo Houston writing back about a dozen people, a Division
Chairman's email naming eight, a spreadsheet column of forty — and answering
"where does each of these stand" meant one member at a time through Import
History, the member card and Track RCFs. **Look Up Members** at `/lookup`
(Admin, its own `look_up_members` capability, Everywhere) takes the list
however it arrived and answers it on one screen, **in the order given**.
`Rerm\Admin\MemberNumbers` reads it the way a person meant it — commas,
spaces, new lines, semicolons; quotes and brackets; Excel's `1234567.0`,
`1.234567E+6` and `1,234,567`; full-width digits from a phone; a number
typed without its leading zeros matched to the one member who has them —
and **reports every decision** above the table: what was found, what the
roster does not hold with a hint about its shape, what was read as what,
what was repeated, what was a word. Nothing is dropped silently and nothing
is guessed: a fourteen-digit run is never split. Each row is the member's
number, name (to the card, with the list as the way back), title, team,
division, **Roster** (on it, dropped by which import, or purged), **First
seen** with the creating import, **Last change** — every field the last
import that touched them changed, or *None since they appeared*, or *None
recorded* for a member who predates the record — and **RCFs** as a fold:
each form that named them, the day it was generated, who generated it,
what the line asked for, its RCF number, the two tracked dates and whether
the roster shows it, through `RcfTracking::forMembers()`. Nothing writes;
`Rerm\Admin\LookupPage` is four queries for the whole list, capped at
three hundred with the rest listed. The list is POSTed (a query string will
not carry it) and a GET with `numbers` answers too, so a short list is a
link. Design: `docs/spec-v2.md` §13.

**Phase 11 — Track RCFs.** The first feature to come from a real user: a
member rings to say nobody has asked them to pay their dues, and "was an RCF
ever submitted for them, and where did it stop" meant sorting through months
of email. Every Roster Change Form produced is now **kept as it was printed**
(`rcf`, `rcf_row`, migration 011, `Rerm\Forms\RcfStore`) and can be
**downloaded again** byte for byte however the roster has moved since. **Track
RCFs** at `/rcfs` opens with a search for a member across every form, then
the caller's own forms and — for an Executive Officer, through the new
`view_all_forms` capability — everyone else's with who made each, every form
a row of *n of m* fractions: numbered, to the Division Chairman, to Rosters,
in the roster. One form at `/rcf?id=` is its lines with three controls each,
**per line** because the Division Chairman bundles lines from several forms
into one numbered form of their own: the RCF number, the day it went to the
Division Chairman, the day it went to Rosters — with an **every-line row that
wins over the lines** and two **today** buttons that date only the lines not
yet dated, so the whole-form case is one tap. **"In the roster" is derived,
never typed**: read out of `import_change` at read time, the first import
after the form that says what the line asked for. The member card lists the
forms a person is on; Create Forms' "your last form" now ends in Track it;
every tracking change is one audit row with before and after; nothing
deletes a form. Design, with the feedback quoted: `docs/spec-v2.md` §12.

**Phase 10.5 — the rest of the review.** The seven "later" items from the
UX review of 10.2, closing it: none a script, a framework, a schema change or
a second layout. The one new reader is `Rerm\Roster\SinceImport`, which
answers **what the last import did for the people on a screen** — how many of
them Rodeo Houston's file moved to Y on each requirement, read out of Phase
10's `import_change` through whatever predicate the screen is already using,
so the `+3 since the import of 4 Sep` under a card describes exactly the
people the card counts. My Roster Status prints it in the banner and under
every card; the Committee Dashboard carries it as a **Newly met** column that
sorts. The **import forms are tidied**: the roster import's three modes are
one `fieldset` with the question as its legend and the team chooser under the
Team option it belongs to; the contact import puts the **form first**, folds
the column manual below it, and keeps the officer and the team chosen after a
discard. **Manage Teams** groups its ninety-six rows under their areas, with
`(No area)` last, and gains a find box on the roster's word rule over name,
area and division. **Import History** counts and pages its list of imports and
links a member's history to their card; the **Audit Log** answers a member
number with every row about that member or their account. On the **Roster
Change Form**, Enter now downloads rather than reloading the team — a hidden
first submit, off the tab order — and the codes are open on a desktop and a
fold on a phone. The **Status page** says mail off is fine (it is the shipped
state), names `/setup` before the shell command this host does not have, and
ends with a way into the application. Design: `docs/spec-v2.md` §10.

**Phase 10.4 — the member card, and the rest of the review.** The sixteen
"next" items from the UX review of 10.2, again none of them a script, a
framework or a second layout. The one new screen is the **member card** at
`/member` — the single-member screen spec-v1 §8.2 named and nothing ever
built: one person, narrow column, Call / Text / Email as the page's largest
targets, the four chips and the Result word, the **log-contact form open**
rather than one row at a time, this show year's contact history, and every
earlier year's under a fold (OI-12's deferred report, now a query). Every list
links a name to it, carrying that list's state as `back` so the way back keeps
the drill-down, the search and the page; and it opens **dropped members** too,
whose contact — "they say they have moved" — now lands in `contact_log`
through `ScopedQuery::contactable()`. The log form gains **one answer for
everything open** — a radio row that applies to every requirement the roster
still shows unmet, with the per-metric selects folded under it — and the Text
and Email links **start themselves** from two configurable templates, sent by
the officer's own phone and never by this application. The Committee
Dashboard prints the **share complete** beside each count and can sort a
requirement by it; the Assign chooser starts on the **most unassigned** and
every column sorts; the roster's team filter is **tick boxes**, not a
`<select multiple>`; the menu is **three groups by job** with a line under
each screen; every timestamp is one `<time>` helper; a **print stylesheet**
puts the table back and opens every fold; the app is **installable** from a
web manifest with its own icons; Designate's buttons are absent rather than
disabled; the import, the contact import, forgot, reset and setup all
**post-redirect-get**; Export and Create Forms say what you **last
downloaded**; and a skip link, `aria-sort`, `aria-current` and `scope` land
where they were missing. Design: `docs/spec-v2.md` §9.

**Phase 10.3 — the call loop, and the shell.** Fifteen changes from a UX
review of the build at 10.2, none of them a script, a framework, a schema
change or a second layout. On a phone, My Roster Status now **folds** its four
cards and its controls behind 56px labels below 720px, so the first screen is
the first call to make; the lede offers a skip to the list and says how many
**days the show year has to go**, from the end date that was in the table and
on no screen. Chips carry a **short word** — Open, Reported, Handling — with
the owner's full word as the title, so four of them fit one line of a card.
A **search finds "John Smith"**: `RosterPage::searchClause()` splits the term
into words and every word must land, and Designate Users and Import History
now search through the same clause rather than a copy. The **log-contact
sheet carries Call, Text and Email** inside it and opens on its first control,
so the dial happens from the sheet and the return lands on the form; every
write that changes one row **303s back anchored to that row**, marked. The
shell gains a **nav** of the four working screens, filtered by capability,
**Sign out** on every screen, **one notice component** with one vocabulary
inside the sticky bar with `role="status"`, a **link token** that meets 4.5:1
on Dust Light, and `color-scheme` so native controls draw dark in the dark
theme; the forced-password screen no longer offers a Menu link that loops,
and a password change is confirmed. The Committee Dashboard gains **All
teams**, every team in scope on one sortable page — the comparison spec 1.2
promised a Division Chairman — and Assign Officers and Flagged for Purge read
**"12 selected" live through a CSS counter**. Consequential actions are gated
by consequence: applying an import asks to be told the diff was read, Make
active and Re-open are a step with a card, and a purge types its word on a
second page that names the ticked members and keeps them if the word is
wrong. The export is **one form**, so the file can never disagree with the
boxes. And the refusal page says which of three things it means. Design:
`docs/spec-v2.md` §8; the review it came from is the ledger there.

**Phase 10.2 — find, and log where you found them.** Two things an officer
kept having to leave a screen to do. My Roster Status now carries View My
Roster's **search box** — name or member number, from three characters, the
same clause and the same floor from `Rerm\Roster\RosterPage` — so one member
can be found on the working list under either half of the My members / My
team toggle without paging through fifty rows. It narrows the same predicate
the four cards and the list share, so the cards above a searched list
describe exactly the people in it; the term rides on every link, on the team
picker and on the log-contact sheet's return state, so nothing about the view
is lost by using it. And View My Roster, the screen that could always find
somebody by name, gains **Log contact** on the row: the same sheet, rendered
once by `Rerm\View::logContactSheet()` for both screens, posting to the same
route and the same per-member permission check, and coming back to the row it
was opened from with the search, filter, sort and page intact. Design:
`docs/spec-v2.md` §7.

**Phase 10.1 — what the call produced.** My Roster Status now answers the
question an officer rings back to find out. Each row carries the member's
**imported title** — Rodeo Houston's word, never the level derived from it —
and a **Result** column saying what the last contact actually produced:
*Reported Complete*, *Member Handling*, *No commitment yet*, or the em dash of
somebody nobody has reached. It is derived from the four status chips beside
it and nothing else, so it cannot claim a commitment none of them shows; a
test walks all 2,592 combinations to prove it. Under each row a closed
expansion holds the show year's whole contact history, the same move View My
Roster makes, on the screen where the calls are made. Design and the byte
measurements behind what it leaves out: `docs/spec-v2.md` §6.

**Phase 9 — Create Forms, complete.** The phrase that survived the whole of v1
undefined now means something: Create Forms produces the committee's own
paperwork, filled in from the roster the officer can already see, and downloads
it as a spreadsheet. `/forms` is the menu; the first form on it is the **Roster
Change Form** at `/form-rcf` — additions, removals, title changes and team
changes for one sub-committee, up to twenty-five people, `.xlsx` out. Officer
and above, scoped like every other member read.

The requirement is fidelity, and it is asserted rather than eyeballed: a blank
form this application generates was diffed against the workbook Rodeo Houston
sends out, cell by cell — **558 cells, style id and value, zero differences**,
all sixty merged ranges and every column width and row height identical. The
style sheet ships as `app/templates/rcf/styles.xml`, byte for byte, **with the
feature property bag it points at** — two of its cell formats index into that
bag and resolve to `CellControl -> Checkbox`, which is how the ROOKIE and WAIT
LIST columns turn out to be Excel tick boxes rather than the `y/n` text their
older printed instructions still ask for. Ship the style sheet alone and Excel
repairs the file on open. Everything else is transcribed in
`Rerm\Forms\RosterChangeForm` and transcribed a second time in
`tests/forms_test.php`. Design: `docs/spec-v2.md`.

A filled-in form names members and carries their member numbers, so it is
handled exactly like the export — built outside the document root, unlinked as
soon as it is sent, logged with the actor, the sub-committee and the row count,
and downloaded by POST rather than by a link.

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

Phases 3 to 8.7 — authentication and the capability matrix, View My Roster,
My Roster Status, Assign Officers, the Committee Dashboard, the Admin
screens, and the fit-and-finish sub-phases — are complete and are described
screen by screen in `docs/spec-v1.md`; the paragraphs above pick up from
Phase 9, where `docs/spec-v2.md` begins.

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
