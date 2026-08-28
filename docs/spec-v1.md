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

### 3.3a Mail safety

Password recovery is the only message this application sends, and there is **no
bulk send path in v1** — deliberately. That is the first and largest safeguard.

The risk is not deliverability. The account has a **dedicated IP**
(`docs/hosting.md`), so sender reputation is ours to build rather than inherit
from whoever else shares a shared address. The risk is **sending by mistake
while we are building**: an import loads ~1,950 real committee members' real
email addresses, and a stray loop against that table reaches actual people who
did not ask for it, cannot be recalled, and would burn the new IP's reputation
on its first day.

So delivery is not the default state that development has to opt out of. It is
a state that production has to opt *into*, four times.

| # | Interlock | Ships as | Blocks a send when |
| ---: | --- | --- | --- |
| 1 | `mail.enabled` | `false` | not explicitly enabled in `config.local.php` |
| 2 | `mail.transport` | `file` | not `send` |
| 3 | `mail.allowed_recipients` | `[]` | non-empty and the address is not on it |
| 4 | `mail.max_per_request` | `5` | more messages than this in one request |

**These are independent, not layered.** Any one of them blocks delivery on its
own, so misconfiguring three still sends nothing. The shipped defaults block it
three times over.

Two of them are worth explaining:

**`transport: 'file'` is the useful development setting**, not `log`. It writes
a readable `.eml` into `var/mail/` — outside the document root, `0700`, and
gitignored — so a developer testing recovery opens the file and clicks the real
link. Nothing is faked, and nothing exists that could escape the machine.

**`allowed_recipients` is the interlock that survives human error.** It is the
one still standing when somebody enables the first two on a box that happens to
have a real roster loaded. Populate it with your own addresses in every
environment that is not production; leave it empty in production, where an
allowlist would break recovery for the committee.

**`max_per_request` throws rather than trims.** Recovery sends exactly one
message, so anything past a handful is a loop that should not exist. Silently
capping it would hide the very bug the ceiling exists to catch.

#### The hard interlock

`app.debug === true` **forces the transport to `file`**, whatever the
configuration says. Debug is only ever true off production, so this is the one
rule that cannot be defeated by editing config in the wrong place. It is
checked in `Rerm\Mail\Mailer` before any transport is selected, and asserted
by a test.

#### What CI enforces

`.github/check-mail-safety.php` loads `config/config.php` and fails the build if
the committed defaults would ever send: `enabled` truthy, or `transport` set to
`send`. The committed configuration is what a fresh deploy reads, and a fresh
deploy must not be able to email anybody.

#### Going live

Enabling delivery is three edits in `config.local.php` **on the production box
only** — `enabled => true`, `transport => 'send'`, and an empty
`allowed_recipients` — plus SPF, DKIM and DMARC on the dedicated IP in cPanel.
Send a recovery to yourself and read the headers before telling anyone the
feature exists.

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

A title confers a **level** — what its holder may do — and a **default scope
breadth**: how much they see before anybody records anything about them. The
two are separate questions, and only one title answers the second differently.

| Title | Level | Default breadth | People |
| --- | --- | --- | ---: |
| `Chairman` | Executive Officer | everything | 1 |
| `Vice President` | Executive Officer | everything | — |
| `Officer in Charge` | Executive Officer | everything | — |
| `Division Chairman` | Executive Officer | everything | 4 |
| `Division Vice Chairman` | Senior Officer | division | 8 |
| `Coordinator` | Senior Officer | division | 5 |
| `Ambassador` | Senior Officer | division | 7 |
| `Vice Chairman` | Senior Officer | **their own team** | 21 |
| `Captain` | Officer | own team | 82 |
| `Assistant Captain` | Officer | own team | 66 |
| `Committee Member` | Member |
| `Lifetime Committeemen` | Member |
| `Lifetime Vice Presidents` | Member |
| `Lifetime Director` | Member |
| `Past Committee Chairman` | Member |
| *anything else* | **Member, with an import warning naming the title** |

The map lives in one place, `Rerm\Auth\TitleMap` — both halves of it, the
level and the breadth — and is transcribed a second time in
`tests/title_map_test.php` so a change has to be made twice on purpose.

Four notes:

- **`Vice Chairman` is a Senior Officer who sees one team** (Phase 8.5). The 21
  of them need the Committee Dashboard and the ability to designate, which are
  Senior Officer capabilities — but promoting them with the level's usual
  whole-division visibility would have widened 21 people from one team to
  several hundred members on the next import, with nobody doing anything. So
  the breadth is part of the map, and an Admin widens each of them
  deliberately to the teams they really cover (§4.3).

  Two other ways of arranging that are wrong, and both are tempting. An
  **import must not seed the team set** — an import never writes a scope
  override (§6.6), and that boundary is what makes a designation durable. And
  an empty team set **must not mean "own team" generally**, or it silently
  narrows the 20 Senior Officers who already exist. A change that promotes 21
  people must not demote 20 others; `tests/title_map_test.php` asserts it does
  not.
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
Senior Officer            ->  members whose division = the officer's division,
                              OR, when scoped, the teams they are scoped to
Officer                   ->  members whose team = the officer's team,
                              OR, when scoped, the teams they are scoped to
```

**An Officer or a Senior Officer may be scoped to a SET of teams**
(Phase 8.5, widened to Officers in 8.6). Some Vice Chairmen cover three teams
and some cover one, and neither "a whole division" nor "a single team"
describes the first. Officers turned out to need the same shape for a
different reason: a Captain runs their own team and helps with another, which
a single `scope_team_id` cannot say. The set lives in `app_user_team` and is
set by an Admin on Designate Users. Executive Officer and Admin are refused it
— they see everything, so a narrowing would put a WHERE clause on a query that
should have none — and so is a Member, who has no roster to narrow. The
refusal is named (`not_scopable`), never silent.

**A team set widens SIGHT, never assignability.** `EligibleOfficers` decides
who may be assigned a member by the officer's own `member.team_id`, and it
does not read scope at all. So an Officer scoped to a second team can see it,
chase it, and log contact against it, and does **not** become one of its
assignable officers — assignment stays same-team (§6.2). That separation is
deliberate: helping chase a team is not the same as being its officer of
record, and the Assign screen must not start naming somebody as one.

**The division override is read only at Senior Officer and above.** Both
`ScopedQuery` and `Access` consult team at Officer level and never division,
so Designate Users offers the control only where it does something — an Admin
who can set a field that changes nothing has been told a lie by the screen.

Scope resolves in one place, `Rerm\Auth\User::fromRow`, so `ScopedQuery` and
`Access` cannot disagree about it. Explicit always beats implicit:

1. an explicit team set;
2. an explicit division override on `app_user`;
3. the title's own default breadth (§4.2).

**A Senior Officer sees their whole division by default, and that is
deliberate.** The export files all eight Division Vice Chairmen under an area
rather than a division (`docs/data-findings.md` §4d), so an area-scoped
reading was considered and rejected: Senior Officers help across the division,
and a Coordinator covering two areas that week would be locked out of one of
them. The breadth is the job, not an over-grant.

Phase 8.5 does not overturn that. The division remains the default for the 20
Senior Officers who already existed — 8 Division Vice Chairmen, 7 Ambassadors,
5 Coordinators — and the team set is an *optional narrowing* an Admin applies
where the committee's real shape needs one.

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
| `export_roster` | Officer | Scoped |
| `view_committee_dashboard` | Senior Officer | Scoped |
| `designate_allowed_user` | Senior Officer | Scoped, capped at own level |
| `import_roster` | Admin | Everywhere |
| `manage_show_year` | Admin | Everywhere |
| `designate_admin` | Admin | Everywhere |
| `manage_teams` | Admin | Everywhere |
| `view_audit_log` | Admin | Everywhere |

Encoded once in `Rerm\Auth\Capability` and `Rerm\Auth\Access`, transcribed
again in `tests/access_test.php`.

**`export_roster` moved from Admin / Everywhere to Officer / Scoped in Phase 8**
(§7.5). There is one export and every row of it goes through
`ScopedQuery::forUser()`, exactly like every other roster read, so breadth is
decided by who is asking rather than by which button they pressed: an Admin or
Executive Officer gets the whole committee, a Senior Officer their division, an
Officer their team. The shape is `view_roster`'s and the reason is the same —
the route guard answers "may they use this screen" and `ScopedQuery` answers
"which rows". An Officer exporting their own team exports data they already
read, row by row, on View My Roster.

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
year, so editing this year's cannot rewrite last year's record.

**Only assignments whose officer is still eligible are carried** (Phase 8
decided 5). This supersedes the original rule, which carried an ineligible
assignment anyway and flagged it for reassignment — a decision made before
Phase 6 turned "officer no longer eligible" into a real, visible bucket that
somebody works. Eligible means what `Rerm\Roster\EligibleOfficers` already
means by it and nothing new: the officer is a visible member (not system, not
purged, not absent-flagged), still on that member's team, and still at Officer
level or above by effective level. Rank comparison in PHP, never a SQL `>=` on
the ENUM.

The consequence is deliberate. A member whose only officer no longer qualifies
arrives in the new year **unassigned** — bucket 1 on the Assign screen, where
somebody is already working — rather than pre-loaded into bucket 2 as invisible
cleanup nobody asked for. A year rolls over into a clean state.

It is never silent. The rollover reports both numbers before it runs and logs
them after: how many assignments carried, and how many were dropped because
their officer no longer qualifies.

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
                   is_active_key VIRTUAL -- 1 when active, NULL otherwise, so a
                                         -- unique key makes "exactly one" a
                                         -- schema rule rather than a habit

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
                   purged_at NULL,              -- SOFT delete only (5.5, 6.5)
                   is_system,                   -- ours, not HLSR's (5.2a)
                   is_active

app_user           id, member_id UNIQUE, level,
                   granted_level NULL, granted_by, granted_at,   -- durable
                   effective_level VIRTUAL,     -- granted_level ?? level (4.4)
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

### 5.2a What the schema adds, and why

Four things in `db/migrations/001_schema.sql` are not in the list above. Each
exists because building the tables surfaced a case the column list did not
cover, and each is asserted by `tests/schema_test.php`.

**`member.is_system`** — the seeded master admin (§3.1) is a member row, because
every account belongs to one, but they are not on the committee. Without the
flag the first Complete import would flag them absent for not appearing in the
file, put them on the Flagged for Purge screen (§6.5), and invite an Admin to
purge the only account that can sign in. An import never creates, updates,
absents or purges a system row; no roster or roll-up counts one; and the export
does not write one back to Rodeo Houston as though it were theirs.

**`app_user.effective_level`** — a `VIRTUAL` generated column holding
`COALESCE(granted_level, level)`, so §4.4's rule is written down once, in the
schema, and no query re-derives it. `level` is the title-derived level as of the
last import, which is what makes the pair self-contained: effective level is
computable from the `app_user` row alone, with no join to `member`.

**Two enforced singletons.** `show_year.is_active_key` and
`assignment.is_current` are `VIRTUAL` columns that are `1` while the row counts
and `NULL` once it does not, and `NULL` does not collide in a unique index. So
"exactly one active show year" and "one live assignment per member, officer and
show year" are refused by the database rather than remembered by the
application, while any number of superseded assignment rows sit behind the live
one. `VIRTUAL` and never `STORED`, per §5.3.

**Six dead export columns** (`docs/data-findings.md` §1) are imported to columns
that exist and are surfaced nowhere. The four that have *never* carried a value
are stored as raw text with a `_raw` suffix: a typed `DATE` column would have to
guess a format nobody has observed, and would fail an entire import the first
time it guessed wrong. Typing them is a later migration, once a real export
populates one.

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
- **Every foreign key referencing `member` is `RESTRICT`, never `CASCADE`.**
  Contact history must outlive the roster (§5.5), and a member row that can be
  deleted is one migration away from taking years of it along.

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

### 5.5 Retention: contact history outlives everything

`contact_log` is keyed to a show year, but it is **never reset, rolled or
purged by anything**. Not by closing a show year, not by opening the next one,
not by an import, not by a member purge.

That is a deliberate v1 constraint in service of a v2 feature. Producing a
member's contact history **across multiple years** — every call, who made it,
what was said — is a thing leadership will want, and the only way to have it in
2029 is to not throw it away in 2026. Retention costs nothing now: 1,950
members at even a dozen contacts a year is a rounding error against any storage
limit, and there is no scenario where the cheaper choice is deleting it.

Three rules make it true, each asserted by a test:

1. **Closing a show year freezes `contact_log`; it never deletes.** §5.1's
   "contacts reset" means the *new* year starts empty, not that the old one is
   cleared.
2. **Every foreign key referencing `member` is `RESTRICT`.** A member row
   cannot be deleted while history points at it, which is what makes rule 3
   enforceable rather than merely intended.
3. **A purge is a soft delete** (§6.5). `purged_at` hides the member; nothing
   removes them.

The v2 screen is then a query, not a migration — which is the whole point of
deciding this now. Any v1 change that would make a contact row unreachable from
its member, or a member row deletable, breaks it.

---

## 6. Roster import

Admin only. The single most dangerous screen in the app — it can rewrite 1,954
rows — so it is a two-step with a diff in between.

### 6.1 Input

**All three formats are read natively: `.xls`, `.xlsx` and `.csv`.** The
administrator uploads whatever Rodeo Houston sent and the app deals with it.

That is not a convenience. Rodeo Houston sends a **legacy `.xls`**
(`docs/data-findings.md`), so a "please re-save this as CSV first" step would
sit in front of every single import — and the step people forget is the one
that matters. There is no Composer here and therefore no PhpSpreadsheet, so
`Rerm\Roster` implements the readers directly:

| Class | Format | Built on |
| --- | --- | --- |
| `XlsReader` | `.xls` — BIFF8 records in an OLE2 container | `CompoundFile`, no extension needed |
| `XlsxReader` | `.xlsx` / `.xlsm` — zipped XML | `zip` + `xmlreader` |
| `CsvReader` | `.csv` | `fgetcsv` + `mbstring` |

`Spreadsheet::open()` picks between them **by reading the first eight bytes,
never by the extension**: `D0CF11E0A1B11AE1` is OLE2, `PK\x03\x04` is a zip,
anything else is text. The extension is the least reliable thing about an
uploaded roster — "Save as CSV" in Excel offers to keep the `.xls` name, and a
workbook mailed as `.xls` is very often really `.xlsx`. Sniffing costs nothing
and removes an entire class of "the import says my file is corrupt".

Every value arrives as a **string**. That is the whole contract of
`SpreadsheetReader`, and it exists for one column: `Customer Number` is the
natural key, and a reader that hands back a float turns 1234567 into 1234567.0.
It is an identifier, never arithmetic.

Verification, in full, is `docs/data-findings.md` §10: both binary readers were
checked cell by cell against independent implementations over the real
1,954-row export — 64,515 cells, zero differences.

**Size.** `upload_max_filesize` is 2M. The sample `.xls` is 1.2M and its
`.xlsx` equivalent is 0.4M, so all three formats fit comfortably; a 1.9M
workbook of 9,770 rows parses in 2.6s using 22MB, against limits of 30s and
128M. Headroom is roughly five times the real roster.

Headers are matched **by name, case-insensitively, ignoring surrounding
whitespace** — never by position. A file missing `Customer Number`, `Title`, or
`Subcommittee 1` is rejected outright with the headers it did find listed.

**Sentinel text is normalised to blank.** Six cells in the sample hold the
literal strings `N/A`, `None`, `none` or `Na` in `Prefix` and `Preferred Name`
(`docs/data-findings.md` §10.3). A member whose preferred name is "N/A" must
not be greeted as "N/A Smith", so on import a value matching
`N/A`, `NA`, `NONE`, `NULL` or `-` case-insensitively becomes an empty string
in those two columns. It is **not** applied to the metric columns, where only
`Y` and `N` are meaningful and anything else deserves a warning.

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

### 6.5 Dropped members, and purge

A complete or team import **drops** members it did not see. It never deletes.

"Dropped" is the word throughout, in the schema as well as on the screens
(Phase 8.5, migration 007): `member.dropped_since_import_id`,
`import_batch.rows_dropped`, and `dropped` in `import_staged_row.action`.

**Dropped is not purged, and the two must not blur:**

| | Set by | Cleared by | Means |
| --- | --- | --- | --- |
| **Dropped** | an import, automatically | the next import that lists them | "find out whether this person left" |
| **Purged** | an Admin, with a typed word | Restore, by an Admin | "we have decided" |

Both are soft; neither deletes anything.

Dropped members appear on the Admin **Flagged for Purge** screen with the batch
that dropped them, and — since Phase 8.5 — on a read-only **Dropped Members**
screen scoped to whoever is looking, so an officer can see that somebody on
their own team has fallen off the roster and ring them. They are excluded from
dashboards and rosters by default. Purging is a separate, explicitly confirmed,
logged action. A member who reappears in a later import is picked back up
automatically.

**Purging is a soft delete, and this is not negotiable.** It sets
`member.purged_at` and drops the member out of every roster and roll-up. It
does **not** delete the row, and nothing cascades from it: `contact_log`,
`assignment` and `member_metric` all survive intact. See §5.5 — the contact
history has to outlive the roster, and a member who lapses for a season and
returns is the ordinary case, not the exception. A purge that deleted rows
would take their history with them and could not be undone.

Every foreign key pointing at `member` is therefore `RESTRICT`, never
`CASCADE`. A test asserts it, because `ON DELETE CASCADE` is the default a
future migration will reach for without thinking.

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

A Senior Officer sees their division's groups. An Executive sees all four
divisions plus `(No Division)`, which holds 72 members and must never be hidden
just because it is untidy — it is a real division row (§5.1a) and behaves like
any other here.

**Four things were decided with the owner at Phase 6 close.** They are recorded
here so Phase 7 builds them rather than re-deciding them.

#### Columns: compliance AND triage, every one sortable

Each row carries the group name, the member count, the four metrics as compact
proportion bars with counts — and, beside them, **unassigned**, **no officer on
this team** and **never contacted**.

Both families, because compliance alone cannot answer the question this screen
exists for. `docs/data-findings.md` §8 measured 50–65% of the committee
outstanding on every metric, so 96 teams will all render between roughly 35%
and 50% complete and sorting by "worst on background checks" returns a list
whose top and bottom differ by noise. The compliance numbers describe the
*committee*; they do not distinguish the *teams*.

What distinguishes them is movement and ownership. **Never contacted**
separates "behind, but somebody is working it" from "nobody has touched these
people". **Unassigned** — real since Phase 6 — says a team is not behind but
*unowned*, which has a completely different remedy. So:

- every column sorts, in both directions;
- the **default sort is never-contacted, descending**, so the first screen
  answers "where is nobody working?" rather than "who is behind?", which at 65%
  outstanding is a question with no useful answer;
- the four metric bars stay exactly as specified. They are the reference an
  Executive will want; they are just not the entry point.

`assignment` coverage sits **beside** the compliance numbers rather than in a
panel of its own, because a team's unassigned count changes what you do about
its bad numbers. "No officer on this team" (§7.4 bucket 3 — 7 teams, 432
members) is a column here as well as a section there: officers read the Assign
screen, leadership reads this one, and closing that gap is leadership's act.

`Rerm\Roster\AssignPage` already computes members / unassigned / ineligible /
eligible-officer-count per team for §7.4's team picker. Phase 7 lifts that
query rather than writing a second one — and it yields **assignments needing
re-pointing** for free, which is a fifth triage column if it is wanted.

#### Area is seeded by migration, then Admin-editable

`team.area` is `NULL` for all 96 teams today: the column, its index and the
rule that no import writes it all exist, but the seeding never did. Phase 7
adds it as a **pure-data migration** (`-- rerm:atomic`), and Phase 8's Manage
Teams screen makes it editable.

The heuristic, stated once: the seven bare-area team names — `Reed Road`,
`610`, `Emlr`, `Bus Ops`, `Ost-Smith Lands`, `Chuckwagon`, `Administration` —
are the area list, and every other team takes **the longest of those its name
starts with**. A team matching none keeps `NULL` and groups under **(No area)**,
the same honest-placeholder pattern as `(No Division)`.

That migration is also where the master administrator's `preferred_name`
becomes `'Master'`, ending "Master Administrator Administrator". The standing
rule holds — no migration is added *solely* for that — and this is the first
pure-data migration since it was noticed.

`area` may appear in this screen's code. It must still never appear in
`Rerm\Auth\Access`, `ScopedQuery`, `EligibleOfficers` or `AssignOfficers`;
`tests/access_test.php` asserts that for all four.

#### Drill-down carries `mode=team`, and every figure filters to itself

Drilling into a group applies it as the filter on §7.1 and navigates there —
the dashboard's job is to end at the list of people to call.

Two consequences, and the first is a trap Phase 6 created:

**§7.1 defaults to My members the moment an officer holds an assignment**, and
Phase 6 made that branch real. A Senior Officer drilling into "40 never
contacted" would otherwise land on §7.1 filtered to that team *and* silently
narrowed to the handful assigned to them personally — three people, not forty.
The dashboard's promise breaks exactly when Phase 6 succeeds. So **the
drill-down link carries `mode=team` explicitly**, in the URL where it is
visible, rather than a new defaulting rule that is not.

**Every figure equals the list filtered to it** (§7.1's own rule, Phase 5).
Clicking the number 40 under never-contacted must land on those 40, not on all
85 members of the team. So §7.1 gains three filters it does not have:

| Filter | Spelling |
| --- | --- |
| the group | `division=` / `team[]=` — §7.2's existing shape, not a second one |
| never contacted | `contact=never` |
| no officer | `assigned=none` |

They travel through the same whitelist helper the log-contact and assign forms
already use (`return_query()`), so the return-state work is a table entry
rather than new code. Proving the equality for the two new figures is the real
work here, and the tests owe it.

#### What Phase 7 decided for itself

Three things were left open for the build. They were decided as follows, and
`tests/committee_test.php` holds each one.

**The three levels open through the URL, not through `<details>`.** `<details>`
collapses *pixels* and ships the *bytes* anyway: a closed one has already
downloaded every row inside it, and §10 budgets the download. Four divisions,
~20 areas and 96 teams with four proportion bars each is a page the browser
would draw a corner of and fetch all of. So `?division=` opens one division
into its areas and `?area=` opens one of those into its teams — Phase 6's
one-bucket-at-a-time, and Phase 5's one-log-sheet-at-a-time, applied to a
tree. The state is shareable and survives a reload. Where a level offers only
one choice — a Senior Officer's single division — it is simply open, because
there is nothing to collapse it to. Measured at the real roster's shape: 31.6KB
for the divisions alone, 49.2KB with one division open, **75.4KB fully
expanded** (5 divisions + 8 areas + a 15-team area), against the 100KB budget;
the roll-up read is under 50ms against a 500ms one.

**`AssignPage::teamsInScope()` stays private.** What the two screens share is
the *predicate* — `EligibleOfficers::memberHasAssignment()` and
`::countsByTeam()` — not an aggregate. That query `INNER JOIN`s `team` and
groups by team alone, so lifting it would have dropped the members with no
team and collapsed the seven division-spanning teams into one row each; this
roll-up's group is the **(division, team) pair**, because division is a
property of the member. A test holds the two screens' coverage numbers to each
other, so the choice is verified rather than argued.

**Sort state is a whitelist, and never reaches a query.** The roll-up is
derived in PHP, so it is also sorted in PHP over rows that already exist —
there is no `ORDER BY` for a sort key to reach. The key still chooses from
`CommitteePage::sortKeys()`, because "it cannot reach SQL today" is a
coincidence of the implementation and not a rule. A metric column sorts by its
**outstanding count**: every other sortable figure on the row is a count of
people, and a completion rate mixed in among them would make "descending" mean
two different things on one screen. Ties break on the group name ascending in
both directions, which at 50–65% outstanding is the ordinary case.

Two smaller consequences, both of the every-figure-equals-the-list rule. A
figure is a **link only where §7.1 can reproduce it**: "no officer on this
team" has no filter spelling and so is a number rather than a link, and
`(No team)` — members with no team at all, who can never be assigned because
assignment is same-team — is a counted group that carries no drill-down. And a
triage drill-down carries `show=all` as well as `mode=team`, because §7.1's
list defaults to outstanding-only and a fully complete member who has never
been contacted would otherwise be counted by the figure and missing from the
list it landed on.

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
each one's current load ("A. Rivera — 14 assigned"), so the work spreads rather
than landing on whoever is first alphabetically.

**Rows carry the member's title and are ordered by it**, then by last name, in
every bucket — so a team reads top-down as its own hierarchy. The order is
seniority, never the title string: alphabetically `Assistant Captain` outranks
everybody and `Committee Member` lands in the middle. It comes from
`title_level` for the coarse rank and `TitleMap::titles()` for the order within
a level, because the three Officer titles are one level and a team that lists
them alphabetically is not a hierarchy. An unrecognised title sorts last among
Members, the same direction §4.2 errs in. An Allowed User sorts by the title
shown rather than the level they were granted — a row reading "Committee
Member" from among the Captains looks like a bug whatever the grant says.

That ordering **supersedes the never-contacted-first rule for this screen**.
Triage order lives on §7.1, which is where an officer decides who to call;
this screen is where somebody decides who is responsible. The last-contact
column stays and is still read — it just no longer sorts.

Removing an assignment sets `removed_at`; the row is never deleted.

### 7.5 Admin screens

**Import Roster** — §6.

**Export Roster by Show Year** — `.xlsx`, not CSV (Phase 8 decided 3). Every
imported column in `Rerm\Import\HeaderMap`'s order and spelling, then
everything the app generated: effective status per metric, progress and who set
it and when, division, area, assigned officers, contact count, last contact
date/type/officer. 55 columns.

It is **one export, not two**, and `export_roster` is Officer / Scoped
accordingly (§4.5). Every row goes through `ScopedQuery::forUser()`, so breadth
is decided by who is asking — an Admin gets the committee, a Senior Officer
their division, an Officer their team — and a `team[]` filter (§7.2's shape,
never a second spelling) intersects that predicate, so it can only ever narrow.
The screen states the exact row count and the full column list *before* the
file is built.

Two rules with tests behind them: **`(No Division)` writes back as blank**
(§5.1a rule 2), and **the master administrator is never exported** (`is_system`,
which falls out of `ScopedQuery` rather than being a special case).

"Never assembled in memory" holds in spirit and changes in mechanism, because
`ZipArchive` writes to a file path and not to `php://output`: the sheet XML is
streamed to a temp file one row at a time, zipped, `readfile()`d out and
unlinked. Measured at the real roster's size: **1,954 rows × 55 columns in
0.45s using 4 MB**, against a 30s and 128M budget. The temp files live in
`var/exports`, 0700 and outside the document root — an export is ~1,950
people's home addresses, and it is logged with the actor, the scope and the row
count.

Written with no Composer: `Rerm\Export\XlsxWriter` builds the five-part
package with `ZipArchive` and escaped string concatenation. Every cell is an
inline string (`t="inlineStr"`), so `Customer Number` 1234567 cannot become
1234567.0 — the same rule `XlsxReader` enforces coming the other way, made
structural rather than remembered.

**Show Year** — create, set active, open/close, and carry assignments forward.
Closing **warns and then closes** (Phase 8 decided 1): the screen says how many
metric progress rows are still `in_progress` or `claimed_complete` and freezes
them as they are, because a metric stuck mid-chase is the normal end-of-year
state and refusing would mean faking edits in order to be allowed to close. The
count is shown before the confirm and recorded in the audit row. Closing never
clears anything (§5.5). The rollover carries only still-eligible assignments and
reports both numbers before it runs — see §5.1.

**Designate Users** — search the roster **regardless of title**, and set a
level. That is the point of the search: 1,758 of 1,954 members have no account
at all, and every one of them is a legitimate target for a grant. The granter's
own level caps what they may set (§4.4, `Access::mayGrant()`). Shows current
level, whether it came from title or grant, who granted it and when, and the
state of the account. Revocation here, capped by the *granted* level so it is
available to exactly the people who could have made the grant (Phase 8
decided 2).

The list itself still goes through `ScopedQuery::forUser()`, because
`designate_allowed_user` is Scoped (§4.5): for an Admin or Executive Officer
that *is* the whole roster, and for a Senior Officer it is the division they may
actually act on — so the screen never offers a control the write path is obliged
to refuse.

An Admin may also set the **scope override** here (§4.4) — the `app_user`
division and team columns that have existed since Phase 1 and that nothing wrote
until now. It is the only mechanism that can point a Senior Officer at a
division other than their own, which is how the 72 members in `(No Division)`
come to have an owner (§5.1a).

**Flagged for Purge** — §6.5. Per-member checkboxes, never a bulk sweep, plus a
typed `CONFIRM`. A **Restore** control sits beside it, because an import does
*not* clear `purged_at`: without it a mistaken purge is invisible forever and
needs somebody at the database (Phase 8 decided 4). Both directions are logged;
nothing cascades from either.

**Manage Teams** — §7.3's `team.area`, editable by an Admin, one team at a time.
It stays display grouping: `area` may appear here and must still never appear in
`Access`, `ScopedQuery`, `EligibleOfficers` or `AssignOfficers`, which
`tests/access_test.php` asserts for all four, comments included.

**Audit Log** — filterable by actor, action and date, paginated at the two
configured page sizes. Read-only: no POST, no CSRF, no write path, because an
audit row is append-only and outlives whatever it describes. The action filter
is why `Rerm\Audit\Action` exists — a filter over free text is a filter that
silently matches nothing the first time somebody misspells a verb — and it also
offers any string the table holds that the enum does not know, so history stays
findable.

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
<a href="tel:+15555550100">Call</a>
<a href="sms:+15555550100">Text</a>       <!-- CELL PHONE only -->
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
§7.3: three-level roll-up with drill-down into §7.1, per the four decisions
recorded there. Seeds `team.area` by migration; adds the group,
never-contacted and no-officer filters to §7.1. **Done when** an Executive can
reach the team nobody is working in two taps, and every figure on the roll-up
lands on exactly the people it counted. **Shipped 2026-08-27.**

### Phase 8 — Admin
§7.5: designation and the scope override, purge and restore, the `.xlsx`
export, show-year control and the rollover, the audit log, and `team.area`
made editable. Widens `export_roster` to Officer / Scoped (§4.5) and changes
what a rollover carries (§5.1).
**Done when** a full round trip works: import → chase → export.

### Phase 8.5 — Fit and finish
Six features from real use of Phase 8: an Admin password reset (§4.4), the
"absent" → "dropped" rename in the schema as well as the screens (§6.5), a
Team column on the import's dropped and changed tables, a sticky nav strip
back to the menu, a scoped read-only Dropped Members screen, and Vice Chairmen
promoted to Senior Officer with the team-set scope that makes it safe (§4.2,
§4.3). Ships migrations 007 and 008.
**Done when** nobody's visibility changed who did not need it to.

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
| ~~OI-2~~ | Are `Coordinator` and `Ambassador` Senior or Officer? | **Resolved: Senior Officer.** 12 people |
| OI-3 | Is harassment training a fifth scored metric? | No — shown, not scored |
| OI-4 | Retention rule for members flagged absent | Flag only; Admin confirms purge |
| ~~OI-5~~ | Do the 72 division-less members belong somewhere? | **Resolved: a real `(No Division)` row** (§5.1a), scopeable, exported as blank |
| OI-6 | Is `Badge Pickup Person` useful? | Imported, not surfaced |
| ~~OI-7~~ | Does a team import name its own team? | **Closed: chosen in the UI, verified against every row** (Phase 2). A row naming another team is warned about and skipped, never retargeted |
| OI-8 | Unify identity with RESM? | No — separate credentials, member number reconciles them |
| ~~OI-9~~ | Can `mail()` deliver reliably from this host? | **Closed: yes — the account has a dedicated IP.** `mail()` + SPF/DKIM/DMARC; SMTP drops to a contingency. Sending *by mistake* is the real risk, handled by §3.3a |
| ~~OI-10~~ | Does closing a show year carry assignments forward? | **Resolved: yes.** Assignments carry as new rows; metrics and contacts reset |
| OI-11 | Maximum officers per member | 3, matching the brief's "generally 2, sometimes 3" |
| OI-12 | Multi-year contact history reporting (v2) | Deferred to v2, but v1 **retains the data unconditionally** (§5.5) so the report is a query rather than a migration |
| ~~OI-13~~ | Which roll-up columns lead the Committee Dashboard? | **Decided: both, all sortable**, default sort never-contacted descending (§7.3). At 50–65% outstanding, compliance does not distinguish teams; contact and coverage do |
| ~~OI-14~~ | Is `team.area` worth populating for the middle roll-up level? | **Closed: yes.** Seeded by migration 006 in Phase 7, Admin-editable from Phase 8's Manage Teams (§7.5), longest-prefix rule over the seven bare-area team names (§7.3) |
| ~~OI-18~~ | Does the Designate Users search read through `ScopedQuery`? | **Decided: yes** (§7.5). "The whole roster" means regardless of title, not regardless of scope: `designate_allowed_user` is Scoped (§4.5), so an unscoped list would show a Senior Officer names their own roster refuses them and then offer a control the write path must refuse. For an Admin it is the whole roster either way |
| ~~OI-19~~ | Does the scope override get a UI in v1? | **Decided: yes, in Phase 8**, on Designate Users, Admin only (§4.4, §7.5). It is the only mechanism that can point a Senior Officer at a division other than their own, which is what gives the 72 members of `(No Division)` an owner — so deferring it would have left §5.1a's central claim unimplementable |
| ~~OI-1~~ | Senior Officer scope: area rather than division? | **Closed by Phase 8.5, and not as an area.** A Senior Officer may now be narrowed to an explicit SET of teams (§4.3), which is what the committee's real shape needed — some Vice Chairmen cover three teams, some one. The division stays the default and the 20 Senior Officers who predate the change keep it |
| ~~OI-21~~ | Can an Admin reset somebody else's password? | **Added by Phase 8.5.** On Designate Users, capped by `Access::mayGrant()` against the target's EFFECTIVE level — a reset sets the password to a value the actor knows, so it is equivalent to taking the account and nobody may reach upward. Sets `must_change_password`, revokes every session, refuses `is_system`, emails nothing |
| ~~OI-22~~ | Should "absent" be renamed? | **Yes, everywhere, Phase 8.5** (§6.5). The owner's word is "dropped"; migration 007 renames the column, the batch counter and the ENUM value so the schema and the screens say the same thing |
| ~~OI-20~~ | Does the audit vocabulary become a type? | **Decided: yes** — `Rerm\Audit\Action` (§7.5). The Audit Log filters by action, and a filter over free text silently matches nothing the first time somebody misspells a verb. Reading stays tolerant: an unknown historical string renders as itself and is still filterable |
| ~~OI-15~~ | How does drill-down interact with §7.1's My members default? | **Decided: the link carries `mode=team` explicitly** (§7.3). Phase 6 made the default real, and it would otherwise hide the very people the drill-down counted |
| ~~OI-16~~ | Does assignment coverage belong on the Committee Dashboard? | **Decided: beside the compliance numbers**, not in a panel of its own (§7.3). Unassigned changes what you do about a team's bad numbers |
| ~~OI-17~~ | Does the Assign screen order by contact age or by title? | **Decided: by title, then name** (§7.4), superseding decided 5 for that screen. Never-contacted-first stays on §7.1, which is the screen for deciding who to call |
