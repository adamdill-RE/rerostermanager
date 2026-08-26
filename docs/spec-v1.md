# Rodeo Express Roster Management — Specification v1

Authoritative. Where this document and the original prose brief disagree, this
document wins and `docs/data-findings.md` says why.

*Committeeman, Committeemen and Committee Member are interchangeable
throughout.*

---

## 1. Purpose

### 1.1 What this is

Rodeo Express has ~1,950 committee members spread over 96 teams and 4
divisions. Four things must be true of each of them before the show, and today
roughly 60% of them are not:

1. HLSR dues paid
2. Committee dues paid
3. Background check completed
4. Indemnity signed

Houston Rodeo publishes a roster carrying those four flags, but it **lags
reality** — a member who paid on Tuesday may still read `N` on Friday's file.
The app therefore tracks two things at once: what the official roster says, and
what the officer who actually phoned the member knows.

### 1.2 Who uses it, and on what

Two populations, and the difference is a design constraint, not a preference:

- **Officers on a phone.** A Captain standing in a parking lot with 20 names to
  chase. They need one screen: who is outstanding, and a button that dials
  them. Every contact action is a 56px target and one tap from the list.
- **Leadership at a desk.** A Division Chairman comparing 25 teams. They need
  density, sorting, and comparison across groups that do not fit on a phone.

Every screen serves both. The rule is stated in §8: **one template, one query,
two layouts.** No separate mobile site, and no desktop table crammed onto a
phone with horizontal scroll.

### 1.3 What v1 is not

Not a recruiting system, not a shift system, not a payment system. It records
who is compliant and who has been chased. Forms creation and recruiting
automation are v2 (§11, Phase 9).

---

## 2. Hosting

Fully documented in `docs/hosting.md`; the constraints that shape the design are
in `CLAUDE.md`. The three that reach into this specification:

- **`max_execution_time` is 30s** — a 1,954-row import must batch and stay
  inside it, which is why §6 splits parse from apply.
- **`max_input_vars` is 1000, truncating silently** — which is why §7.5's
  assignment screen is select-then-act rather than one control per member.
- **`upload_max_filesize` is 2M** — the sample `.xls` is 1.2M, so a full
  roster fits, but not with much room. §6.1 accepts CSV as the preferred
  format for exactly this reason.

### 2.1 Coexistence with RESM

`reshiftmanager.com` hosts both apps. This repository owns `public_html/rerm/`
and `public_html/index.html`, and nothing else. It must never write a
`.htaccess` to `public_html/` itself: `DOCUMENT_ROOT` is `public_html`, so a
root rewrite would be evaluated for `/resm/` requests too.

Separate database (`reshiftmanager_rerm`), separate session cookie (`RERMSESS`,
path `/rerm/`), separate credentials. A member has one account here and a
different one in RESM. That is a deliberate cost: the two apps must be able to
fail independently, and a migration here must not be able to touch RESM's
tables during a shift. Unifying identity later is open item OI-8.

---

## 3. Identity and authentication

### 3.1 Who can sign in

Login is by **member number** (`Customer Number` from the import) and a
password. Three routes to an account:

1. **The seeded master admin.** Member number `987654321`, Admin level. Its
   password is **not** committed — the repository is public. The account ships
   locked with a non-verifiable hash and is unlocked once via
   `bin/set-admin-password.php` or the guarded `/setup` route.
2. **By title on import.** A member whose title maps to Officer, Senior Officer
   or Executive Officer (§4.2) gets an account created on import with the
   initial password `1234` and `must_change_password` set.
3. **By designation.** An Admin, Executive Officer or Senior Officer grants an
   individual member a level (§4.4). If they had no account, one is created the
   same way.

Everyone else — 1,758 of the 1,954 in the sample — has **no account at all**.
Not a disabled account: no row in `app_user`. A member is data, not a user.

### 3.2 First login

`must_change_password` forces a change before any other screen renders,
including by direct URL. The new password must be at least 8 characters and
must not be `1234`. There is no complexity theatre beyond that — length is what
matters and a rule nobody can satisfy produces a sticky note.

Changing a password revokes every other session for that account.

### 3.3 Password recovery

An officer enters their **member number**. If an account exists and the member
has an email on file, a single-use token valid for 60 minutes is emailed.

**The email must be unambiguous about which member number it applies to.** Two
addresses in the sample are shared between two people each, and spouses sharing
an inbox is the normal case, not the edge case. Required wording:

> Subject: Reset the password for Rodeo Express member number 1234567
>
> This resets the password for **member number 1234567** only.
> If you or someone in your household uses a different member number,
> this link will not change that account's password.

The response to the request is **always** "If that member number has an account
with an email on file, a reset link is on its way." — identical whether or not
the account exists. No enumeration.

The member with no email on file (§`docs/data-findings.md` §5) gets a page
saying no address is on file and to contact an officer, not a silent success.

### 3.4 Sessions

Same model as RESM, for the same measured reasons. The PHP session holds one
thing — the id of an `auth_token` row — because `gc_maxlifetime` is 1440s here
and garbage collection belongs to the host. The `auth_token` row is the real
session, which is what makes revocation immediate.

The cookie is `selector.verifier`. The selector is an indexed lookup key and is
useless alone; only a SHA-256 of the verifier is stored, compared with
`hash_equals`. Resuming rotates both and pushes the expiry out.

Rotation is a **compare-and-swap**; only the request that wins sends a new
cookie. A known selector with a wrong verifier is refused and audited but does
**not** revoke the token family — a request that lost a rotation race lands in
the same branch, and signing an officer out over a race they cannot see is the
worse failure.

"Keep me signed in" is 90 days rolling. This app is used in bursts weeks apart;
a session that expires between them is the whole friction budget.

### 3.5 Rate limiting

10 failed attempts from one IP in 15 minutes triggers a 60-second lockout.
Deliberately loose: this is an internal roster tool, and a locked-out Captain
is a worse outcome than a guessing attempt. Successes are recorded too, so the
audit can tell a typo from an attack.

---

## 4. Access model

### 4.1 Levels

Five, ordered. Each includes everything below it.

| Level | Rank | Scope |
| --- | ---: | --- |
| Admin | 5 | Everything, plus import, export and show-year control |
| Executive Officer | 4 | The whole committee |
| Senior Officer | 3 | Their own division |
| Officer | 2 | Their own team |
| Member | 1 | No login |

### 4.2 Title map

Applied on import, matching the export's exact strings.

| Title | Level |
| --- | --- |
| `Chairman` | Executive Officer |
| `Vice President` | Executive Officer |
| `Officer in Charge` | Executive Officer |
| `Division Chairman` | Executive Officer |
| `Division Vice Chairman` | Senior Officer |
| `Coordinator` | Senior Officer |
| `Ambassador` | Senior Officer |
| `Vice Chairman` | Officer |
| `Captain` | Officer |
| `Assistant Captain` | Officer |
| `Committee Member` | Member |
| `Lifetime Committeemen` | Member |
| `Lifetime Vice Presidents` | Member |
| `Lifetime Director` | Member |
| `Past Committee Chairman` | Member |
| *anything else* | **Member, with an import warning naming the title** |

The map lives in one place, `Rerm\Auth\TitleMap`, and is transcribed a second
time in `tests/title_map_test.php` so a change has to be made twice on purpose.

Three notes:

- **`Division Chairman` is Executive, not division-scoped.** All four are filed
  under Logistics Division in the export, so their placement cannot name what
  they run. Executive scope makes the question moot.
- **Lifetime and Past titles get no login.** The prose spec's "any title other
  than Committee Member or Lifetime Committeemen is an officer" would have
  handed accounts to 13 honorary members.
- **The Member Services carve-out is not implemented.** It resolves to one
  person who is already Executive by title. See `docs/data-findings.md` §4a.

### 4.3 Scope resolution

```
Admin, Executive Officer  ->  every member
Senior Officer            ->  members whose division = the officer's division
Officer                   ->  members whose team = the officer's team
```

Scope comes from the **member record of the signed-in user**, not from the
team table — teams span divisions (`docs/data-findings.md` §4b), so division is
a property of the person.

No division is ever blank: a member whose `Subcommittee 3` arrives empty is
placed in the real `(No Division)` row (§5.1a). A Senior Officer **can** be
scoped to it, which is the point — those 72 members have an owner rather than
belonging to nobody. An Admin can still set an explicit scope override.

**Scope is enforced in the query, not in the view.** Every roster read goes
through one method, `Rerm\Roster\ScopedQuery::forUser()`, which appends the
scope predicate. A screen cannot forget to filter because it never builds the
`WHERE` clause itself.

### 4.4 Allowed Users

An Admin, Executive Officer or Senior Officer may grant any imported member a
level **at or below their own**. Senior Officers cannot create Executives;
Executives cannot create Admins. Only an Admin creates an Admin.

A grant is **durable**: it survives every subsequent import regardless of what
the member's title becomes, which is the entire point — a Committee Member
doing roster work for a division keeps their access when the roster refreshes.

A granted level replaces the title-derived level. Scope for a granted Senior
Officer is still their own division and for a granted Officer still their own
team, unless an Admin sets an explicit scope override (a division id or a team
id stored on `app_user`).

Every grant and revocation writes to `audit_log` with the actor, the target,
the level and the timestamp. Revocation is available to anyone who could have
granted it.

### 4.5 Capabilities

| Capability | Minimum level | Scope |
| --- | --- | --- |
| `view_own_record` | Member (with login) | Own |
| `change_own_password` | Member (with login) | Own |
| `view_roster` | Officer | Scoped |
| `log_contact` | Officer | Scoped |
| `set_metric_progress` | Officer | Scoped |
| `assign_officers` | Officer | Scoped |
| `view_status_dashboard` | Officer | Scoped |
| `view_committee_dashboard` | Senior Officer | Scoped |
| `designate_allowed_user` | Senior Officer | Scoped, capped at own level |
| `import_roster` | Admin | Everywhere |
| `export_roster` | Admin | Everywhere |
| `manage_show_year` | Admin | Everywhere |
| `designate_admin` | Admin | Everywhere |
| `manage_teams` | Admin | Everywhere |
| `view_audit_log` | Admin | Everywhere |

Encoded once in `Rerm\Auth\Capability` and `Rerm\Auth\Access`, transcribed
again in `tests/access_test.php`.

**A scoped capability requires a subject.** Asking "may this officer log a
contact?" without naming the member is not a question with an answer, and
answering yes is how an Officer edits another team's data. Passing null denies.

---

## 5. Data model

### 5.1 Show years

Everything metric-, contact- and assignment-related is keyed to a show year. A
show year is `open` (accepting changes) or `closed` (read-only, exportable).
Exactly one is `active` at a time.

Closing a show year freezes its metrics, contacts and assignments. Opening the
next **carries assignments forward** as a starting point — officers rarely
change wholesale, and re-assigning 1,950 people from scratch each year is work
nobody would do. Metrics and contacts do **not** carry: they reset, because
last year's dues and last year's phone calls say nothing about this year.

Carried assignments are copied, not shared — new rows against the new show
year, so editing this year's cannot rewrite last year's record. Any carried
assignment whose officer is no longer eligible (§6.6) is carried anyway and
flagged for reassignment rather than dropped.

### 5.1a Divisions, and the one that is ours

Four divisions come from the export. A fifth, **`(No Division)`**, is seeded by
migration and flagged `is_placeholder = 1`.

72 members arrive with a blank `Subcommittee 3` — 57 honorary members parked in
a `Lifetime` pseudo-team and **15 ordinary Committee Members on real teams**.
They land in the placeholder row, which buys three things a `NULL` cannot:

- `member.division_id` is `NOT NULL`, so no query anywhere carries a null
  branch and no roll-up can quietly omit a bucket.
- A Senior Officer can be **scoped to it**, giving those 15 members an owner.
- It sorts, groups and drills down on the Committee Dashboard like any other.

Three rules keep it honest, and each is asserted by a test:

1. **Every import re-evaluates membership.** A populated `Subcommittee 3` moves
   the member out to the real division; a blank one moves them in. Never sticky.
2. **The export writes it back as blank**, never as the literal "No Division".
   It is our bookkeeping, not Rodeo Houston's data, and must not travel back to
   them as though it were theirs.
3. **The `no_division` import warning still fires.** The bucket makes those
   members reachable; it does not make them correctly placed.

### 5.2 Tables

```
show_year          id, label, starts_on, ends_on, is_active, is_open

division           id, name, is_placeholder, is_active
                   -- is_placeholder marks the seeded (No Division) row (5.1a):
                   -- scopeable and groupable, but exported as blank
team               id, name, division_id (modal, display only),
                   area (nullable, display grouping ONLY), is_active

member             id, member_number UNIQUE, first_name, last_name,
                   preferred_name, full_name, prefix,
                   address, city, state, zip,
                   phone, phone_e164, phone_type,
                   email,
                   title, title_level,          -- as imported
                   division_id NOT NULL, team_id,   -- (No Division) never NULL
                   legal_name_verified, is_rookie, in_other_committees,
                   badge_pickup_person,
                   first_imported_at, last_seen_import_id,
                   absent_since_import_id NULL, -- flagged for purge
                   is_active

app_user           id, member_id UNIQUE, level,
                   granted_level NULL, granted_by, granted_at,   -- durable
                   scope_division_id NULL, scope_team_id NULL,   -- override
                   password_hash, must_change_password, password_changed_at,
                   is_active, created_at

auth_token         id, user_id, selector UNIQUE, verifier_hash,
                   is_persistent, issued_at, last_used_at, expires_at,
                   revoked_at, user_agent, ip
login_attempt      id, ip, member_number, succeeded, occurred_at
password_reset     id, user_id, selector UNIQUE, verifier_hash,
                   expires_at, used_at, requested_ip

member_metric      member_id, show_year_id, metric,
                   imported_value ENUM('Y','N','unknown'),
                   imported_at, imported_batch_id,
                   progress ENUM('not_started','in_progress','claimed_complete'),
                   progress_by, progress_at, progress_note
                   PRIMARY KEY (member_id, show_year_id, metric)

contact_log        id, member_id, show_year_id, contacted_by,
                   contact_type ENUM('call','text','email','in_person','other'),
                   occurred_at, notes

assignment         id, member_id, officer_member_id, show_year_id,
                   assigned_by, assigned_at, removed_at NULL
                   UNIQUE (member_id, officer_member_id, show_year_id)
                            over a VIRTUAL not-removed column

import_batch       id, show_year_id, mode ENUM('complete','update','team'),
                   team_id NULL, filename, sha256, uploaded_by,
                   rows_read, rows_created, rows_updated, rows_unchanged,
                   rows_absent, warnings_count,
                   started_at, applied_at NULL, dry_run
import_warning     id, import_batch_id, row_number, member_number NULL,
                   kind, detail

audit_log          id, actor_user_id, action, entity, entity_id,
                   before_json, after_json, occurred_at, ip
```

### 5.3 Conventions

Inherited from RESM and non-negotiable:

- InnoDB and `utf8mb4_unicode_ci` named explicitly on every table. MariaDB's
  defaults differ from MySQL's and the server default is not ours to rely on.
- Every `DATETIME` is UTC; the connection pins `time_zone` to `+00:00`. Display
  converts to `America/Chicago` through a real timezone, never a fixed offset.
- Uniqueness keys over generated columns are **`VIRTUAL`, never `STORED`** —
  under MySQL a column a STORED generated column reads cannot carry
  `ON DELETE CASCADE`, error 1215, and the table simply will not create.
- Nothing is deleted to deactivate. `is_active` flags exist so retiring a team
  preserves the records pointing at it.
- Operational history is append-only. `contact_log` is never updated;
  `assignment` is superseded via `removed_at`.

### 5.4 Effective metric status

The single function every screen uses. Never re-derived anywhere else.

| `imported_value` | `progress` | Contacted this year | Effective | Colour |
| --- | --- | --- | --- | --- |
| `Y` | *(any)* | *(any)* | **Complete** | green |
| `N` | `claimed_complete` | *(any)* | **Reported** | blue |
| `N` | `in_progress` | *(any)* | **In Progress** | amber |
| `N` | `not_started` | yes | **Contacted** | amber outline |
| `N` | `not_started` | no | **Outstanding** | red |
| `unknown` | *(any)* | *(any)* | **Not reported** | grey |

Colour is never the only signal — every chip carries its word.

An import that sets `imported_value` to `Y` **resets `progress` to
`not_started`**: the thing being tracked has happened and the note is now
history. An import that leaves it `N` **preserves progress**, so a roster
refresh never erases an officer's work.

---

## 6. Roster import

Admin only. The single most dangerous screen in the app — it can rewrite 1,954
rows — so it is a two-step with a diff in between.

### 6.1 Input

`.csv` preferred, `.xls`/`.xlsx` accepted. `upload_max_filesize` is 2M and the
sample `.xls` is 1.2M, so the margin on a binary workbook is thin; the Admin
screen says so and offers "Save as CSV" as the recommended path.

Headers are matched **by name, case-insensitively, ignoring surrounding
whitespace** — never by position. A file missing `Customer Number`, `Title`, or
`Subcommittee 1` is rejected outright with the headers it did find listed.

### 6.2 Modes

| Mode | Existing members | New members | Members not in the file |
| --- | --- | --- | --- |
| **Complete** | update all fields + metrics | create | **flag** `absent_since_import_id` |
| **Update** | update metrics + contact only | **ignore, warn** | untouched |
| **Team** | update all fields + metrics, within the chosen team | create into that team | **flag**, within that team only |

Always updated in every mode, per the brief: **phone, phone type and email.**

Team mode requires the Admin to choose the team in the UI, and the import
**verifies** that every row's `Subcommittee 1` matches it. A mismatch is a
warning and the row is skipped, never silently retargeted.

### 6.3 Two-step apply

1. **Parse and stage.** The file is read into `import_batch` + staging rows
   with `dry_run = 1`. Nothing in `member` changes. Warnings are collected.
2. **Preview.** The Admin sees: rows read, would-create, would-update,
   unchanged, would-flag-absent, and the full warning list. Metric flips are
   summarised — "412 members would move to Committee Dues = Y". The 20 largest
   changes are shown row by row.
3. **Apply.** A second, explicit POST with the batch id. Only then is `member`
   written.

Staged batches older than 24 hours are discarded.

30 seconds is the ceiling for both steps. Parsing 1,954 rows is trivial;
applying is ~2,000 upserts plus ~10,000 metric rows, so the apply batches
inserts and runs inside one transaction per 500 rows.

### 6.4 Warnings

Never fatal, always listed, always attributed to a row number and member number:

| Kind | Trigger |
| --- | --- |
| `unknown_title` | Title not in the §4.2 map — imported as Member |
| `no_division` | `Subcommittee 3` blank — 72 rows in the sample |
| `no_email` | Blank email — cannot recover a password |
| `non_cell_phone` | Type is not `CELL PHONE` — no text link |
| `shared_email` | Address already used by a different member number |
| `team_division_conflict` | Team seen under a different division |
| `duplicate_member_number` | Same number twice in one file |
| `wrong_team` | Team mode, row belongs elsewhere |
| `new_team` | Team name not previously seen — created |
| `unparseable_phone` | Cannot normalise to E.164 — imported as text only |

The preview groups warnings by kind with counts, expandable to rows. A 72-row
`no_division` list must not bury a single `duplicate_member_number`.

### 6.5 Absence and purge

A complete or team import **flags** members it did not see. It never deletes.

Flagged members appear on an Admin "Flagged for purge" screen with the batch
that flagged them, and are excluded from dashboards and rosters by default.
Purging is a separate, explicitly confirmed, logged action. A member who
reappears in a later import is un-flagged automatically.

### 6.6 What an import owns

The most important rule in this application, stated once so no screen has to
re-decide it: **an import refreshes what Rodeo Houston knows, and never
overwrites what we know.**

#### HLSR owns — every import overwrites, unconditionally

| Field | Source column |
| --- | --- |
| `member.title`, `member.title_level` | `Title` |
| `member.team_id` | `Subcommittee 1` |
| `member.division_id` | `Subcommittee 3` (blank → `(No Division)`, §5.1a) |
| `first_name`, `last_name`, `preferred_name`, `full_name`, `prefix` | the name columns |
| `address`, `city`, `state`, `zip` | the address columns |
| `phone`, `phone_e164`, `phone_type` | `Primary Phone`, `Primary Phone Type` |
| `email` | `Primary Email` |
| `member_metric.imported_value` ×4 | `Show Dues`, `Committee Dues`, `Indemnity`, `Background Check Completed` |
| harassment training `imported_value` | `Harassment prevention training` (tri-state) |
| `is_rookie`, `in_other_committees`, `legal_name_verified`, `badge_pickup_person` | as named |

Phone and email are updated in **every** mode, including Update mode — the
brief calls for it and they are the two fields that go stale fastest.

#### We own — no import ever writes these

| Table / column | What it holds |
| --- | --- |
| `app_user.granted_level`, `granted_by`, `granted_at` | Allowed User designations |
| `app_user.scope_division_id`, `scope_team_id` | explicit scope overrides |
| `app_user.password_hash`, `must_change_password`, `password_changed_at` | credentials |
| `contact_log` — **every row, every column** | who called whom, when, type, notes |
| `assignment` | officer ↔ member assignments |
| `member_metric.progress`, `progress_by`, `progress_at`, `progress_note` | our tracked status — *one exception below* |
| `team.area` | Admin-editable display grouping |
| `audit_log` | everything |

#### Why designations are durable

An import rewrites `member.title` and the title-derived level. It never touches
`app_user.granted_level`. Effective level is:

```
effective_level = granted_level ?? title_level
```

So a Committee Member designated a Senior Officer stays one no matter what the
next roster calls them — which is the entire reason designation exists.

#### The one exception, and it is deliberate

**When an import flips a metric's `imported_value` from `N` to `Y`, that
metric's `progress` resets to `not_started`.**

The thing being chased has happened, so "in progress" is now false. Without the
reset, a later correction back to `N` would resurface a months-old status as
though it were current — an officer would see "In Progress" for work nobody is
doing.

Two guardrails make it safe:

- **The reset is recorded, never silent.** The prior `progress`, its author and
  its note go to `audit_log` with the `import_batch_id` that cleared them.
- **`contact_log` is never touched by it.** The record of who called whom, when,
  and what was said survives every import unconditionally. That is what keeps
  "why did Johnson's dues flip back to N" answerable, and it is why the reset
  costs a status flag rather than an officer's work.

An import that leaves `imported_value` at `N` **preserves progress untouched**.
A roster refresh must never erase chasing that is still in flight.

#### Two consequences worth designing for

**A demotion by import revokes login.** `Captain` → `Committee Member` drops the
title level to Member, so `app_user.is_active` becomes 0 — unless a
`granted_level` holds it open. The row is **deactivated, never deleted**: the
audit trail outlives the account, and a re-promotion on a later import
reactivates the same row rather than creating a second one.

**A demotion orphans assignments.** Members assigned to an officer who is no
longer an officer, or who moved to another team, surface on the Assign screen
(§7.4) as **"officer no longer eligible"** with the members they held. The
assignment rows are **not** deleted — an assignment that silently empties is
how twenty people stop being chased without anyone noticing. Re-assignment is
an explicit act.

---

## 7. Screens

### 7.0 Menu

Tiles, filtered by capability. Hiding a tile is presentation; every target
re-checks server-side.

```
Officer and above     My Roster Status      ← default landing screen
                      View My Roster
                      Assign Officers
Senior Officer and above
                      Committee Dashboard
Admin                 Import Roster
                      Export Roster
                      Show Year
                      Designate Users
                      Flagged for Purge
                      Audit Log
Everyone              Change Password · Sign out
```

**My Roster Status is the landing screen**, not the menu. An officer signing in
to chase people should already be looking at who to chase.

### 7.1 My Roster Status

The product. Two halves on one screen.

**Top: the dashboard.** For the current filter, one card per metric showing
complete / outstanding as a number and a proportion bar, plus two summary
figures the brief specifically asks for: **contacted but still outstanding**,
and **never contacted**. On a phone the four cards are a 2×2 grid; on a desktop
they are a row with the two summary figures beside them.

**A toggle at the top switches between:**
- **My members** — those assigned to me (§7.5)
- **My team** — everyone in scope

Senior Officers and above get the same toggle, and it applies within whatever
division/team filter is set. Default is **My members** if the officer has any
assignments, otherwise **My team**.

**Bottom: the list.** Default filter is **outstanding on any metric**, sorted
**never contacted first, then oldest contact first** — so the top of the list
is always the next call to make. Never the full roster by default: that is
~1,200 rows.

Each row carries preferred name (falling back to first name), last name, four
metric chips, last contact (relative — "9 days ago"), who made it, and its
type. Actions: **Call**, **Text**, **Email**, **Log contact**.

- Text is **absent** when the phone type is not `CELL PHONE`.
- Email is **absent** when there is no address.
- Absent, not disabled — a greyed button invites a tap that does nothing.

**Log contact** opens a sheet: type (call / text / email / in person / other),
optional note, and per-metric progress. The contact is recorded against the
signed-in user, the show year and the moment — never back-dated, never edited.
A mistake is corrected by logging a correcting contact, because "who said this
member was paying" must stay answerable.

### 7.2 View My Roster

The reference view: everyone in scope, not just the outstanding.

Filter by team (multi-select for Senior and above), predictive search by name
or member number matching from the third character. Search runs against
preferred name, first, last and member number.

Columns: name, team, four metric chips, last contact, contacting officer.
Expanding a row shows the **full contact history for the current show year** —
every entry with its type, note, officer and timestamp — and the member's
assigned officers.

Pagination at 50 rows on a phone, 100 on a desktop, with a count always
visible. A view that says "showing 50 of 1,247" is honest; infinite scroll on a
compliance list is not.

### 7.3 Committee Dashboard

Senior Officer and above. The §7.1 dashboard computed per group instead of for
one roster.

Grouped by **division**, then by **area**, then by **team** — three collapsible
levels. Area is the display grouping from `docs/data-findings.md` §4d and
carries no permission meaning.

Each row: group name, member count, and the four metrics as compact proportion
bars with counts. Sortable by any metric so "which team is worst on background
checks" is one tap. Two extra columns the brief implies and the data demands:
**never contacted** and **no officer assigned**.

Drilling into a group applies it as the filter on §7.1 and navigates there —
the dashboard's job is to end at the list of people to call.

A Senior Officer sees their division's groups. An Executive sees all four
divisions plus `(No Division)`, which holds 72 members and must never be hidden
just because it is untidy — it is a real division row (§5.1a) and behaves like
any other here.

### 7.4 Assign Officers to Committeemen

Per the brief, the screen that has to work best. Same-team assignment only.

**Four buckets, in this order:**

1. **Unassigned** — members in scope with no current officer. Default view.
2. **Officer no longer eligible** — members whose assigned officer was demoted
   or moved teams by an import (§6.6). The assignment still exists and is
   shown; it just needs re-pointing. Above bucket 3 because it is invisible
   work that an import created and nobody requested.
3. **No officer on this team** — members whose team has no assignable officer
   at all. 7 teams, and members of teams whose only officers are already at
   capacity. Not an error the officer can fix; a number leadership must see.
4. **Assigned** — collapsed by default, expandable to review or change.

**The interaction is select-then-assign, not one control per member.**
`max_input_vars` is 1000 and truncates silently, and an 85-person team with
three selects each would exceed it. So:

- Checkboxes down the list, with **Select all** and **Select all outstanding**.
- A sticky action bar showing "12 selected".
- Choose an officer from the team's officers, press **Assign to 12 members**.
- Repeat for the second and third officer. Assignment is additive; a member
  ends with 1–3 officers.

The officer picker lists only assignable officers on that member's team, with
each one's current load ("Rivera — 14 assigned"), so the work spreads rather
than landing on whoever is first alphabetically.

Removing an assignment sets `removed_at`; the row is never deleted.

### 7.5 Admin screens

**Import Roster** — §6.

**Export Full Roster by Show Year** — CSV of every imported column plus
everything the app generated: effective status per metric, progress and who set
it, assigned officers, contact count, last contact date/type/officer. Streamed
with `fputcsv` to `php://output`, never assembled in memory: 1,954 rows × ~45
columns against a 128M limit is survivable but pointless to risk.

**Show Year** — create, set active, open/close. Closing warns how many
in-progress items will freeze.

**Designate Users** — search the whole roster, regardless of title, and set a
level. The granter's own level caps what they may set. Shows current level,
whether it came from title or grant, and who granted it. Revocation here.

**Flagged for Purge** — §6.5.

**Audit Log** — filterable by actor, action and date.

---

## 8. Design

### 8.1 Tokens

Inherited from RESM verbatim (`CLAUDE.md` → Design system) so the two apps read
as one product. Same palette, same status colours, same 56px/64px targets, same
required dark theme.

### 8.2 The responsive rule

**One template, one query, two layouts.** The breakpoint is 720px.

- **Below 720px** a data row is a stacked card: name on its own line at
  `--font-status`, four metric chips on one wrapped line, last-contact as a
  muted line, and the Call / Text / Email / Log actions as a row of 56px
  targets.
- **At or above 720px** the same rows are a real table with sortable headers
  and a wide container (`--page-wide: 78rem`).

Never a horizontally scrolling table on a phone, and never two codebases. If a
column cannot survive the transformation it does not belong in the table.

Menu, login, password and single-member screens keep RESM's narrow `34rem`
column at every width — they are lists of choices, not data.

### 8.3 Status chips

Every chip is a word plus a colour, never a colour alone, and never a bare
letter. `Y`/`N` is what the file says, not what a human reads at arm's length.

```
Complete · Reported · In Progress · Contacted · Outstanding · Not reported
```

Abbreviated to a single glyph plus a `title`/`aria-label` only inside the
desktop table, where the column header supplies the context.

### 8.4 Contact actions

```html
<a href="tel:+17135551234">Call</a>
<a href="sms:+17135551234">Text</a>       <!-- CELL PHONE only -->
<a href="mailto:member@example.com">Email</a>        <!-- when an address exists -->
```

Tapping one **offers to log the contact** on return — it cannot detect that the
call happened, so the list shows an inline "Log this?" affordance on the row
just acted on. Never auto-log: a dialled call that went to voicemail is not a
contact, and an officer who finds the app inventing history stops trusting it.

---

## 9. The landing page

`https://www.reshiftmanager.com/` is a static `index.html` deployed from
`site/`. Two buttons: **Shift Management (RESM)** → `/resm/`, **Roster
Management (RERM)** → `/rerm/`.

Deliberately static, deliberately alone. No PHP, no `.htaccess`, no shared
assets directory. `DirectoryIndex` picks it up, and because it introduces no
rewrite rules at the document root it cannot affect how `/resm/` is served.
CSS is inline for the same reason: one file at `public_html/`, owned by one
repository, is the whole surface area.

---

## 10. Non-functional

| Concern | Requirement |
| --- | --- |
| Page weight | < 100KB on first paint; no framework, no webfont |
| First paint | < 2s on 3G — this is a phone tool used in parking lots |
| Roster query | < 500ms for the largest scope (1,954 members) |
| Import | Full 1,954-row parse and apply inside 30s |
| Export | Streamed, constant memory |
| Accessibility | Contrast ≥ 4.5:1 for text; every status carries a word; every action reachable by keyboard |
| Audit | Every grant, import, purge and password reset logged with actor and time |
| Backups | cPanel's schedule; a pre-import snapshot is the Admin's responsibility and the import screen says so |

---

## 11. Build sequence

### Phase 0 — Foundation
Repository, `.cpanel.yml`, docker mirror of the server layout, CI against MySQL
8.0 **and** MariaDB 10.11, config layering, migrator, `/status`, the §9 landing
page. **Done when** `git push` + Deploy HEAD serves `/rerm/` and `/`.

### Phase 1 — Schema
Every table in §5.2, reference data, master admin seed, show year. **Done when**
`php bin/migrate.php` runs clean twice on both engines and `schema_test.php`
asserts collation, UTC and the VIRTUAL uniqueness keys.

### Phase 2 — Import
§6 in full: three modes, header matching, staging, preview diff, warnings,
absence flagging. **Done when** the 1,954-row sample imports inside 30s with a
diff shown before commit and every §6.4 warning fires on the real file.

### Phase 3 — Auth and access
§3 and §4: login, forced reset, recovery email, rotating tokens, rate limit,
the capability matrix, `ScopedQuery`. **Done when** `access_test.php` and
`title_map_test.php` are green and every route is guarded server-side.

### Phase 4 — View My Roster
§7.2, including search, filters, contact links and expandable history. **Done
when** an Officer sees exactly their team on a phone and a Senior Officer sees
exactly their division.

### Phase 5 — My Roster Status
§7.1: the dashboard, the list, log-a-contact, progress statuses, the
mine/team toggle. **Done when** §5.4's table is provably correct under test for
all 18 combinations.

### Phase 6 — Assign Officers
§7.4: three buckets, bulk select-then-assign, load display, thin-team counting.
**Done when** every assignable member in the sample has 1–3 officers or a named
reason, and the 432 members on thin teams are counted rather than lost.

### Phase 7 — Committee Dashboard
§7.3: three-level roll-up with drill-down into §7.1.

### Phase 8 — Admin
§7.5: designation, export, show-year control, purge confirmation, audit log.
**Done when** a full round trip works: import → chase → export.

### Phase 9 — v2
Create Forms. Recruiting and retention automation. Out of scope for v1.

**Phases 4 and 5 are the product.** Everything before is plumbing and everything
after is leverage. If the schedule slips, it slips at 7 and 8.

---

## 12. Open items

Carried from `docs/data-findings.md` §9, plus those this document raises.
Struck-through rows are decided and are recorded here only so the reasoning
stays findable.

| # | Question | Assumed for v1 |
| --- | --- | --- |
| OI-1 | Senior Officer scope: area rather than division? | Division, as specified |
| ~~OI-2~~ | Are `Coordinator` and `Ambassador` Senior or Officer? | **Resolved: Senior Officer.** 12 people |
| OI-3 | Is harassment training a fifth scored metric? | No — shown, not scored |
| OI-4 | Retention rule for members flagged absent | Flag only; Admin confirms purge |
| ~~OI-5~~ | Do the 72 division-less members belong somewhere? | **Resolved: a real `(No Division)` row** (§5.1a), scopeable, exported as blank |
| OI-6 | Is `Badge Pickup Person` useful? | Imported, not surfaced |
| OI-7 | Does a team import name its own team? | Chosen in UI, verified against file |
| OI-8 | Unify identity with RESM? | No — separate credentials, member number reconciles them |
| OI-9 | Can `mail()` deliver reliably from this host? | Assume yes; fall back to authenticated SMTP through a domain mailbox if bounce rates say otherwise |
| ~~OI-10~~ | Does closing a show year carry assignments forward? | **Resolved: yes.** Assignments carry as new rows; metrics and contacts reset |
| OI-11 | Maximum officers per member | 3, matching the brief's "generally 2, sometimes 3" |
