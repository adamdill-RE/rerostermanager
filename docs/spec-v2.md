# Rodeo Express Roster Management — Specification v2

v1 is complete (phases 0–8.7) and `docs/spec-v1.md` is its record: what was
decided, and why. **That document is not edited further.** This one carries v2,
cross-references it, and keeps its own open-items table.

Read `CLAUDE.md` first for the constraints and the ownership rules,
`docs/data-findings.md` for what the real Rodeo Houston export actually
contains, and `docs/hosting.md` for the measured runtime numbers. Every hard
constraint in those documents still applies here — nothing in v2 relaxes one.

---

## 1. Create Forms

### 1.1 What it means

"Create Forms" is named three times in v1 and defined in none of them
(`docs/spec-v1.md` §1.3, §11; `CLAUDE.md`'s phase table). It is now decided:

> **Create Forms produces the committee's own paperwork, filled in from the
> roster the officer can already see, and downloads it as a spreadsheet that
> looks exactly like the form they fill in by hand today.**

It is not a form *builder*, not a way to push data back to Rodeo Houston, and
not recruiting automation. Those remain out of scope. The first form is the
Roster Change Form (§2); the menu exists so the second and third have
somewhere to appear.

**"Looks exactly like" is the requirement, not a nicety.** These forms are
read by a Division Chairman and then by Rodeo Houston's membership office. A
generated form that differs visibly from the one they process is a form that
gets questioned instead of processed, and the officer who produced it is worse
off than if they had filled one in by hand. Fidelity is therefore asserted, not
eyeballed — see §2.5.

### 1.2 The screen

`/forms` — a list of the forms this application can produce, one card each,
with a sentence saying what the form is *for* before somebody picks it. Officer
and above.

### 1.3 The capability

| Capability | Minimum level | Scope |
| --- | --- | --- |
| `create_forms` | Officer | Scoped |

The shape is `export_roster`'s, for `export_roster`'s reason (spec-v1 §4.5,
Phase 8 decided 3): a form names members and carries their member numbers, and
the picker that puts them on it reads through `ScopedQuery::forUser()` like
every other member read. Breadth is decided by who is asking rather than by
which button they pressed, so there is one code path rather than a scoped one
and a full one that have to agree.

The floor is Officer because filling in a Roster Change Form is an Officer's
job — they are the person who knows somebody has resigned — and because
everything the form shows them about a member, they already read row by row on
View My Roster.

It is **not** `export_roster` reused. That one means "may take the roster away
as a file"; this one means "may produce committee paperwork". Either should be
grantable without the other. Transcribed a second time in
`tests/access_test.php`, as every row of the matrix is.

### 1.4 A form is PII leaving the building

Identical handling to the export (spec-v1 §10, `CLAUDE.md`): the file is built
in `var/exports` (0700, outside the document root), sent with `readfile()`,
unlinked as soon as it has been sent — and by the writer's destructor if
anything throws first — and logged with the actor, the sub-committee and the
row count. The download is a **POST**, never a link: its body names members,
which is not something a GET an `<img src>` can send should produce.

`Rerm\Audit\Action::CreateForm` (`create_form`) is the second READ in the audit
vocabulary, and it is there for the first one's reason. **One verb for every
form**, not one per form type: what was produced is in the row's own details,
so a seventh form does not need a seventh verb and "which forms went out for
Bus Ops Team A" stays one filter.

---

## 2. The Roster Change Form (RCF)

`/form-rcf` — additions, removals, title changes and team changes for one
sub-committee, up to twenty-five people, downloaded as `.xlsx`.

Route names are flat and hyphenated (`form-rcf`, not `forms/rcf`) like every
other route in this application: `.htaccess` rewrites with a relative
substitution and no `RewriteBase`, and there is no reason for this to be the
first request that finds out how LiteSpeed resolves that from a subdirectory.

### 2.1 The form's own vocabulary

Printed on the paper AND offered on the screen, from one list in
`Rerm\Forms\RosterChangeForm` so the two cannot drift. A form whose printed
legend disagrees with its own dropdown collects answers nobody can act on.

**`*TYPE`** — the code goes in the cell, because the form says "Please Enter
The Appropriate Code":

| Code | Means |
| --- | --- |
| `A` | Addition |
| `R` | Remove |
| `T` | Title Change |
| `S` | Sub-Committee Change (Team Change) |
| `S & T` | Sub-Committee Change (Team Change) & Title Change |

`S & T` keeps its spaces. That is the form's spelling, and `S&T` is a form
somebody has to interpret.

**`**REMOVE REASON`** — the **number** goes in the cell. That is what the
instruction over the numbered list asks for, and what the 26-character column
can show without clipping: reason 3 is sixty-two characters long and the column
does not wrap.

| # | Reason |
| --- | --- |
| 1 | Deceased Member |
| 2 | Did Not Meet Requirements |
| 3 | Leadership Recommendation (please contact Division Chairman) |
| 4 | Member Resigned |
| 5 | No Response to Communications |
| 6 | No Show for All Assignments |

**`RE ROOKIE` and `WAIT LIST` are Excel CHECKBOXES**, and that had to be
discovered rather than read off the form. The blank workbook carries a numeric
`0` in both columns of all twenty-five rows, which reads like a leftover and is
not one: cell formats 60 and 61 — which is exactly those fifty cells and
nothing else — carry `<xfpb:xfComplement i="0"/>`, an index into the workbook's
feature property bag that resolves through `XFComplement -> XFControls ->
CellControl` to `Checkbox`. The `0` is an unchecked box.

So both columns carry a **boolean** — `t="b"`, `1` or `0` — on **every** row,
exactly as the blank form does, and both are tick boxes on the screen. The type
is not a detail: Excel draws a box for a boolean and prints the value for
anything else, so the same `0` written as a plain number is the character 0
sitting where an empty box belongs. Neither value counts towards
whether a row says anything (§2.6): an unticked box submits nothing, so if they
counted, all twenty-five rows would look filled in the moment the form was
drawn — and a tick with no name beside it is not a change request anyway.

The form's older printed instructions still say `y/n` under ROOKIE and 'Please
enter "Yes" or "No"' beside WAIT LIST. Those predate the conversion. **The
cells are what Rodeo Houston processes, so the cells win** — and writing "Yes"
into a checkbox-formatted cell is how Excel stops drawing the checkbox at all.

This took **two** goes to get right, and both failures are worth keeping:

1. The first version shipped `styles.xml` alone. Excel resolved
   `xfComplement i="0"` into a feature property bag that was not in the
   package and opened the form with *"Repaired Records: Format from
   /xl/styles.xml part (Styles)"* — the checkboxes gone, the user told their
   form was damaged.
2. The second shipped the bag but wrote the cells as plain numbers. Excel
   opened it without complaint and printed `1` and `0` in fifty cells that
   should have been boxes.

The rules that came out of them are in §2.5, and so is the reason the second
one was not caught by a comparison that had already reported zero differences.

### 2.2 What the screen offers, and to whom

Three lists, three different answers, on purpose. This is the whole permission
surface of the feature.

| List | Breadth | Why |
| --- | --- | --- |
| **Members** | **Scoped** | `ScopedQuery::forUser()` intersected with the chosen sub-committee. Producing a form is not a reason to widen who can enumerate 1,954 people's names and member numbers, and the free-text half of the same control already covers the member who is not in the list. |
| **Sub-committees** | Whole committee | A team list is not personal data — it is 96 names Rodeo Houston publishes — and "S = Sub-Committee Change" means moving somebody to a team that is by definition not the one the form is about. Only teams and divisions that actually hold somebody are offered: a list containing an empty team invites a move to nowhere. |
| **Officers** | Whole committee | The submitter defaults to whoever is signed in and may be changed to any officer; the sponsor for a new recruit "must be a VC or higher", which is frequently somebody outside the submitter's own team. What is exposed is a name, a title and a team, and nothing else — no address, no phone number, no email, no compliance status. The title travels with the name so the "VC or higher" rule can be checked by eye. |

"Officer" is `Rerm\Roster\EligibleOfficers`' own definition — a visible member
whose effective level (`granted_level ?? title_level`) is Officer or above —
read through that class so this list and the assignment picker cannot disagree
about who is one.

**`(No Division)` is never offered and never printed.** It is this
application's bookkeeping for the 72 members who arrive with a blank
`Subcommittee 3`, and the rule that it must not travel back to Rodeo Houston as
though it were their data (`CLAUDE.md`; spec-v1 §5.1a rule 2) applies to a form
at least as much as to the export — more, because a human reads a form. Its
**teams** are real and are offered, under their own names, with nothing
prefixed. A test asserts that nothing printed on any form says it.

### 2.3 One control for "pick them, or type them in"

The form has two columns, `HLS&R NO` and `MEMBER NAME`; the screen has one
field, because the job is one decision. Picking somebody off the roster and
typing somebody who is not on it are the same gesture — which is the point: an
addition is a person from the wider membership, who by definition has no row
here yet.

The field is a text box with a shared `<datalist>`, read forgivingly, in both
orders, because somebody typing a new recruit is not copying a format:

```
1234567 - Jane Smith        number and name
Jane Smith - 1234567        name and number
1234567                     number alone; the name is filled in from the roster
Jane Smith                  name alone; HLS&R NO is left for Rodeo Houston
```

A member number is "unbroken, at least four characters, letters and digits
only, carrying at least one digit" — not `\d{6,7}`, though every real one is:
`member_number` is `VARCHAR(32)` because it is an identifier and never
arithmetic, the seeded master administrator is `987654321`, and leading zeros
have to survive a round trip. The digit requirement is what keeps "Jane
Sample-Smith" a name.

**Two fields fill themselves in**, and both are things this application already
knows:

* the member's **name**, when only a number was given and it is in scope;
* their **previous title**, when it was left blank — a title change is a
  request to replace a title we are already holding, and making somebody
  retype it is how it ends up disagreeing with the roster.

Anything typed always wins, and the lookup runs through the caller's own scope,
so it can only ever fill in a member they could already read. A number
belonging to somebody out of scope fills in nothing and the row still goes on
the form with what they typed — the same answer every other screen gives them.

### 2.4 The page, and the byte budget

Twenty-five rows of ten controls is the heaviest screen in the application, and
spec-v1 §10 sets first paint at under 100KB. Three decisions come out of that,
and all three were measured rather than assumed:

1. **Three of the controls are `<datalist>` rather than `<select>`**, because
   they repeat per row. A hundred teams drawn twenty-five times over is ~150KB
   of `<option>` on its own. A datalist is emitted **once** and shared by every
   row — and it is what the member field needs anyway (§2.3).
2. **The screen draws five rows and grows five at a time**, to a maximum of
   twenty-five. The paper form has twenty-five rows and so does the file; the
   screen does not have to, and a form usually carries three names. A row that
   was never drawn submits nothing and prints blank — exactly what an untouched
   row on the paper form does. The count never shrinks below what is already
   filled in.
3. **The member picker is capped at 300**, which is ~16KB. The largest real
   division is 675, which would be ~36KB — a third of the whole budget for one
   control. Past the cap the list is dropped and the field stays a text box
   that still accepts anything, so the feature degrades to "type it in" — which
   it can already do — rather than to a page that will not load in a parking
   lot. A team, which is what an RCF is normally about, is twenty people.

Measured, with the layout's inline CSS included:

| The page | Bytes | Over the wire |
| --- | --- | --- |
| Nothing chosen yet | ~37KB | ~9KB |
| A team, five rows — what this screen normally is | ~59KB | ~11KB |
| A 290-person division, five rows | ~74KB | ~12KB |
| All twenty-five rows drawn | ~115KB | ~14KB |

The budget is asserted where it applies — the page as it arrives — and the
fully expanded form is held to a stated ceiling rather than pretended about. It
takes four button presses to reach, by somebody deliberately filing a
twenty-five person change.

`max_input_vars` is 1000 and PHP truncates past it in silence (`CLAUDE.md`).
Twenty-five rows of nine fields is 225, plus the header and the token — inside
it by a factor of four, and the reason the form is one page rather than a
growing list.

**Changing the sub-committee re-submits the whole form.** The member list
depends on it, so it has to go back to the server, and going back with a plain
link would throw away twenty-five rows of typing. Both buttons post the same
form; only `action=download` builds a file.

There is no JavaScript, here or anywhere in this application: the host has no
build step, and the `Content-Security-Policy` `render()` sets forbids script
outright.

### 2.5 How the fidelity is held

`app/templates/rcf/styles.xml` is the Rodeo Houston workbook's own
`xl/styles.xml`, shipped **byte for byte** — fourteen fonts, eleven fills,
twenty-six border combinations and ninety-three `cellXfs` that
`RosterChangeForm` addresses by index. Rebuilding that by hand would be
ninety-three chances to be one shade off, and a form that is one shade off is a
form somebody has to check. It is safe to ship: a style sheet holds no cell
content, every colour is an explicit `rgb=` so no theme part is needed, and it
is XML rather than a spreadsheet — `.gitignore` refuses `*.xlsx` and CI fails
on a tracked one, both deliberately.

**Every part a shipped style sheet points at travels with it**, and that rule
was learnt the hard way. `app/templates/rcf/featurePropertyBag.xml` ships
beside it because two of those cell formats index into it (§2.1);
`FormSheet::create()` **refuses** to build a package whose style sheet
references a bag it was not given, so the pair cannot be half-shipped by
accident, and a test asserts that every relationship the generated workbook
declares resolves to a part actually in the archive.

`RosterChangeForm` carries the rest as transcription: every merge, width, row
height, style id and printed label, read out of that workbook's `sheet1.xml`.
`tests/forms_test.php` transcribes the labels, the style ids, the merges and
the widths **a second time**, independently — the discipline
`tests/access_test.php` applies to the permission matrix, and for the same
reason: drift here is invisible until Rodeo Houston queries a form.

A blank form generated by this application is diffed against that workbook
cell by cell — **558 cells, every attribute and the cell TYPE, zero
differences: 71 text, 437 blank and 50 boolean; all sixty merged ranges and
every row attribute identical**, and both shipped parts byte-identical inside
the package.

**"Cell by cell" has to mean every attribute.** The first comparison read the
style id and the value and ignored `t`, and reported 558 cells with zero
differences while all fifty checkbox cells were numbers where the workbook had
booleans — a difference that is invisible in the XML values and unmissable on
the printed page. `tests/forms_test.php` captures the type for that reason and
asserts `t="b"` on all fifty.

One difference is deliberate and is recorded rather than matched: the workbook
declares **fourteen** column runs and the generated form thirteen. The one left
out is `min="1026" max="1026" width="8.5"` with no style — a default-width run
Excel left behind at column AMK, carrying no formatting, and the only reason
that workbook's declared dimension reaches `AMK51`. Reproducing it would mean
copying a quirk without copying the dimension it explains.

**Everything a person typed is written as a string.** `FormSheet` has no
numeric cell writer at all — its one non-string method is `boolean()`, for the
fifty tick boxes — so there is no path by which Customer Number 1234567 becomes
1234567.0, which is the same rule `Spreadsheet::open()` enforces coming the
other way.

The sheet tab is called `Sheet1`, because that is what the tab on their form is
called. A better name would be a visible difference from the form officers
already know, which is the one thing this file may not be.

### 2.6 What lands where

| Form cell | Holds |
| --- | --- |
| `A2` | `Rodeo Express Roster Change Form  - RODEO {show year}`, from the active show year |
| `G4:L4` | the submitting officer, as `Name, Title` — the form asks for both |
| `D5` | the date, as `M/D/YYYY`, written as a string that already reads the way the cell's number format would render it |
| `G5:L5` | the sub-committee, **`Division - Team`** — six columns wide, and the heading names what the whole form is about |
| `B` | `*TYPE` — the code |
| `C` | `ROOKIE` — a **checkbox**: a boolean (`t="b"`), ticked or not, on every row |
| `D` | `MEMBER NAME` — `full_name` as Rodeo Houston spells it, never the preferred name: "Bud" is what an officer calls him and not what is on the membership record this form asks them to change |
| `E` | `HLS&R NO` |
| `F` | `CHANGE/ADD TITLE` |
| `G` | `PREVIOUS TITLE` |
| `H` | `WAIT LIST` — a **checkbox**: a boolean (`t="b"`), ticked or not, on every row |
| `I` | `**REMOVE REASON` — the number |
| `J:K` | `NEW SUB-COMMITTEE (New Team)` — **the team's own name, unprefixed**: the column is thirty characters wide and does not wrap, and the team name is exactly what Rodeo Houston's `Subcommittee 1` column holds. The division belongs on the heading at the top, where there is room for it. |
| `L` | `INTERVIEW REQUIRED or SPONSORED BY` |

Nothing submitted is taken at face value: the sub-committee, the submitter and
the type and reason codes are all looked up in the lists this application
built, so a POST naming a team that was not offered produces no sub-committee
rather than a sub-committee of the caller's choosing. Free text is collapsed,
trimmed and bounded at 120 characters — a title is a title, and a paragraph
pasted into one prints as a grey smear.

The one deliberate exception is the per-row **new sub-committee**, which is a
datalist rather than a select (§2.4) and therefore a text box wearing a list.
A value that matches nothing offered is kept **as typed** rather than dropped:
an older browser shows only the text box, and silently discarding what somebody
wrote into it is how a member gets left off a form.

---

## 3. Import History

### 3.1 The question Rodeo Houston's file cannot answer

Their export is a **snapshot**. It says who is on the committee at the moment
somebody pressed Export and carries nothing at all about how it got that way:
no revision, no effective date, no "changed by". So every question of the form
*when did this change, and which file changed it* had exactly one answer —
keep the old spreadsheets and diff them by hand — and the two that get asked
are the two that matter most:

* **somebody disappeared.** A member the last file did not list is flagged
  dropped (spec-v1 §6.5), and the flag is cleared the moment they reappear.
  The member row therefore answers "are they on the roster today" and can
  never answer "when did they go, and did they come back".
* **somebody changed in a way they were not supposed to.** A Captain arrives
  as a Committee Member; a member is on another team; dues that were `Y` come
  back `N`. Each is one cell of one row of a 1,954-row file, and each is
  invisible until an officer notices.

`import_batch` has answered the aggregate since Phase 2 — rows read, created,
updated, unchanged, dropped, warnings by kind, which requirements moved and by
how many, which teams the file introduced — and `summary_json` has held that
summary since 004. What was missing was somewhere to *read it back*, and the
field-level record to put beside it.

### 3.2 `import_change`, and why it is its own table

One row per changed field per member per import: which batch, which member,
which field, what it was, what it became, and when.

It is **written at apply time**, in the same transaction as the write it
describes. That is the property the whole record rests on: a row in it means
the roster really changed, never that a preview said it would. A file staged
and never applied writes nothing, and can still be discarded — which it could
not be if a permanent record already pointed at it.

The parsed diff it stores already existed, on `import_staged_row`.`changes`,
and that is the wrong place to keep it for two reasons:

* that table is documented as disposable — "a parse of a file, thrown away 24
  hours later", with `ON DELETE CASCADE` to prove it — and a permanent record
  must not be founded on a table that discards itself;
* it sits beside `payload`, the member's whole HLSR-owned record including
  their address, so answering "when did this team change" would mean reading
  and retaining everything else about them too.

It is also **not `audit_log`**. That records what a *person* did and every row
has an actor; this records what a *file* did, the actor is the same for all of
them and is already on `import_batch`.`uploaded_by`, and the interesting column
is the one `audit_log` has no room for — the field name. Keeping 1,954 create
rows out of the audit log is also what keeps the audit log readable.

Four kinds, and the first two are why it exists:

| Kind | Means |
| --- | --- |
| `dropped` | the file did not list them, so they were flagged |
| `returned` | a dropped member appeared again, and was un-flagged |
| `created` | the roster had nobody with this number, and now does |
| `updated` | an HLSR-owned field changed; `field` says which |

**Unchanged records nothing.** A second import of the same roster is ~1,954
unchanged rows, and writing "nothing happened" 1,954 times a month buries the
rows that say something did.

`field` stores the importer's own column name — `title`, `team`, `division`,
`metric:committee_dues` — and is labelled for display in PHP. The stored
vocabulary is therefore equal to the diff's, a label can be improved without
rewriting history, and a column a future phase adds to the import is legible
here the day it lands.

### 3.3 The screen

`/import-history`, Admin, through `Capability::ImportRoster` — the second half
of the same job, so not a seventh capability: whoever may rewrite 1,954 rows
from a file is exactly who needs to see what the last file did.

Three shapes, one screen:

* **Every import.** Applied and stopped-part-way both, newest first, each with
  the summary it wrote when it ran. A file uploaded and never applied changed
  nothing and is not listed — offering one beside imports that really happened
  is how somebody comes to believe a file was applied when it was only read.
* **One import.** Its counts, its stored summary, and what it changed grouped
  by kind and field with a count each — dropped first, because "somebody
  disappeared" is the sentence that brings people here — each group drilling
  down to exactly the people it counted.
* **One member.** Everything every import has ever done to them, newest first,
  each row naming the file that did it. A change with no import beside it is a
  fact with no cause, which is the state this table exists to end.

It is **not scoped**, deliberately. The value of the screen is watching a
member move *between* teams and divisions, and a scoped read would show half
of such a move and hide the other half — worse than not showing it.

The member box resolves a member number outright and refuses a name that
matches more than one person. Names are not unique in this roster (1,951
distinct of 1,954), and attributing one person's history to another is the
same failure the contact import refuses for the same reason (spec-v1 §6.7).

### 3.4 What it must never become

* **Read-only, in every sense.** No POST, no CSRF check, no write path — and a
  test refuses `INSERT`, `UPDATE`, `REPLACE` and `DELETE` in the reader. A
  record somebody can edit answers no question worth asking.
* **It never undoes an import.** A wrong import is fixed by importing the right
  file, which diffs against the roster as it now stands (spec-v1 §6.3). This
  answers *when did that happen*, which is the question being asked.
* **It never records what we own.** Contacts, assignments, grants, scopes,
  progress and `team.area` are ours and no import writes them (`CLAUDE.md`);
  the record of what a file did must not name one either, and a test reads the
  stored field names to hold it to that.

---

## 4. Which team, and where a roster starts

Two screens narrow a roster by team — My Roster Status and Export Roster — and
from this phase both **start narrowed**: a caller who has said nothing sees
the team they are on, with everything they can see one click away.

The reason is different on each screen and both are real. On the dashboard, a
Division Chairman's first screen was 400 rows of somebody else's team; the
roster somebody opens it to work is almost always the one they are on. On the
export, the screen exists to stop a file turning out to hold 1,954 home
addresses when somebody expected 82, and starting narrow makes the safe answer
the one nobody has to choose.

`Rerm\Roster\TeamFilter` resolves it once, for both, and three rules hold it
together.

### 4.1 A choice narrows; it can never widen

Whatever is chosen is ANDed onto `ScopedQuery::forUser()`, never substituted
for it. That is what makes it safe to take team ids straight from a query
string: an id outside the caller's scope intersects nothing and yields an
empty roster, which is the honest answer. Filtering the ids against the
offered options first would be *worse* — a list of out-of-scope ids would
filter down to nothing, and nothing means "every team" one line later.

The options themselves are read through the scope as well, so the picker
cannot offer a team the caller could not have seen anyway.

### 4.2 The absence of a value can only mean one thing

Before the default, an empty team filter meant "everything". With a default it
means "I have not said" — so wanting everything needs a token that survives a
link, a form, a page turn and a bookmark. `team=all` is that token, in both
shapes a query string can spell it (`team=all` from the dashboard's select,
`team[]=all` from the export's checkbox).

Two consequences, and each is a bug that would otherwise be invisible:

* **Every dashboard link carries the selection explicitly**, including when it
  is the default. A link that leaves it out re-derives it at the other end,
  and for somebody who asked for all teams that is their roster silently
  shrinking on the next page turn.
* **The export's download carries it too.** The POST resolves the same input
  through the same call as the screen, so the file holds what the count
  promised. Without the token, a POST carrying no team would come back as "I
  have not chosen" — the screen says 1,247 rows and the file holds 82.

### 4.3 A drill-down suppresses the default, and hides the control

Spec-v1 §7.3's rule is that every figure on the Committee Dashboard equals the
list it drills into. A default quietly ANDed onto a link that already said
`division=` or `contact=never` would break that for exactly the people the
figure counted, and break it invisibly. So while a drill-down is in force
there is no default and no picker; the banner's **Show my whole roster** is
the way out, and the picker is there once it is taken.

One team in scope is not a choice either. An Officer's team **is** their
scope, so they are offered no control and nothing on their screen claims to
have been narrowed.

---

## 5. The shell says which build it is

Two small things, on every screen including the signed-out ones.

**`app.version` in the footer.** There is no build step on this host and
nothing may require one, so there is no tag to read and no file to stamp: it
is a configured constant, edited on purpose. **Minor is the build phase** —
`1.9.0` is the application as Phase 9 left it, `1.10.0` is Phase 10 — so the
footer answers "which build are you looking at" in the vocabulary the specs
already use. It is on `/login` too, because that is exactly where somebody
reporting "it still does the old thing" is standing. `/status` repeats it, for
the case the footer cannot answer: the deploy landed and the page will not
render.

**RESM's "RE" tab icon, byte for byte.** The two applications share a domain,
a palette and, for most people, a phone; a committeeman checking dues has both
open in adjacent tabs, and a tab is sixteen pixels of icon. Two marks there
would read as two organisations rather than one product with two screens,
which is the reasoning the design system already applies to the colours,
applied to the one part of the interface that is visible when the page is not.
`bin/gen-icons.php` is RESM's generator with the PWA sizes removed, so the
mark stays reproducible rather than folklore.

Naming the icon in the page head matters for a second reason that has nothing
to do with branding: an unnamed favicon makes the browser probe the **document
root** for `/favicon.ico`, and the document root is not ours — it is the
domain, where the landing page sits and RESM is served from the directory
beside us.

---

## 6. What the call produced

My Roster Status is the screen an officer works from, and it could say who was
called, when, and by whom — and not the one thing they ring back to find out:
**what the member actually said.** Four chips carried it, per requirement, in
colour; nothing on the row carried it in a word. This phase adds three things
to that screen, and only to that screen.

### 6.1 The title, because a list of calls is a list of people

Each row's name cell now reads `number · title · team`. The title is
**`member.title`, Rodeo Houston's own word** — Captain, Committee Member,
Lifetime Committeemen — rewritten unconditionally by every import (spec-v1
§6.6), and never the level this application derived from it. The two are
different sentences and one of them is ours: `TitleMap` turns `Captain` into
Officer, an Allowed User grant can replace that, and none of it changes what
the roster calls the person. An officer working down a call list wants the
roster's word.

### 6.2 The Result column, derived and never stored

`contact_log` records that a conversation happened; it has no outcome column
and gains none. What a member committed to already lives in
`member_metric.progress`, written by the same `LogContact` call that writes
the contact — so the result is a **pure function of the four effective
statuses already on the row**, computed by `Rerm\Roster\ContactOutcome` and
rendered by `View::outcome()`.

That it is derived from the chips beside it is the whole safety property: the
word cannot claim a commitment none of them shows. A test walks all 2,592
combinations to assert exactly that, and the table itself is re-transcribed in
`tests/status_test.php`, the ritual the permission matrix and the
effective-status table already get.

Five words, of which two are the chips' own so a rename moves both at once:

| Word | When |
| --- | --- |
| **Nothing outstanding** | all four scored metrics Complete — the row's own Fully Complete, reached from the same statuses |
| **Reported Complete** | the member says at least one open requirement is done |
| **Member Handling** | the member says they are taking care of at least one |
| **No commitment yet** | reached this show year, and committed to nothing |
| **No contact yet** | not reached, and committed to nothing — rendered as the em dash the empty cells already use, because the cell beside it says "Never contacted" |

**The word is the FURTHEST the member committed, not the least.** Somebody who
said "dues are paid, I'll get to the background check" reads *Reported
Complete · 1 of 3*. Reporting the worst rung instead would make every partial
answer read as though the call had produced nothing, which is the single
distinction this column exists to draw; the coverage note is what keeps it
honest, and the four chips hold the detail. The note is drawn **only** when
the answer did not cover everything open — a "3 of 3" on every row qualifies
nothing and costs fifty rows of bytes.

**Open means "not Complete", exactly as the cards mean outstanding.** Not
reported is open here. A card's outstanding figure is every effective status
but Complete, and the working list deliberately keeps a member no import has
covered, "so nobody vanishes"; a Result reading *Nothing outstanding* beside
four grey chips would contradict, on the same row, the list that put them
there.

**What was said outranks whether a call was logged.** The two commitment
questions are asked before the contact question, so a member whose progress
says *Reported Complete* can never be labelled *No contact yet* — the one
contradiction this column must not produce. `contacted` then decides only
between the two ways of having committed to nothing, which is the same
question that separates `Contacted` from `Open/No Contact` in spec-v1 §5.4.

### 6.3 The expansion, and what it deliberately leaves out

Under every row is a closed `<details>` holding the show year's whole contact
history — every entry with its type, note, officer and timestamp, newest
first, and "loaded from history" on the ones a spreadsheet brought in (§6.7 of
spec-v1) — and the member's assigned officers. It is the same move View My
Roster makes, on the screen where the calls are actually made, and it opens
with no round trip and no JavaScript.

**It carries less than View My Roster's does, and the difference is the byte
budget rather than an oversight.** That screen's expansion opens with a facts
list: phone, email, division, harassment training. Here every one of those is
either already on the row — the name cell names the title and the team — or
one tap away, because Call, Text and Email are the row's own actions. Fifty
copies of that list measured **~16KB** against spec-v1 §10's 100KB
first-paint budget, and the reference view is one link away at the foot of the
page. Measured at the default page size of fifty rows, with every member
contacted and assigned: **55.6KB before, 84.3KB after**.

Harassment training is left out for a second reason that is not about bytes:
the four cards above the list are exactly the four **scored** metrics, and a
fifth appearing underneath them would be the first place in the application
that scored it.

---

## 7. Find, and log where you found them

Two screens, two small gaps, and each one had the other's answer. My Roster
Status is the working list — the cards, the toggle, the next call first, and
Log contact on every row — and it had no way to find one person in it but to
page. View My Roster is the reference view, and it could find anybody in
scope from three characters, and then offer nothing to do about them but
Call, Text and Email. An officer ringing somebody back was leaving one screen
for the other and carrying a name across by hand. Phase 10.2 closes both gaps
by borrowing, not by inventing.

### 7.1 The search box, on the working list

My Roster Status gains spec-v1 §7.2's search — **name or member number,
matching from the third character**, against preferred name, first name,
last name and member number — as a GET form under the toggle and the team
picker. Three rules, and each is a rule the screen already had:

- **It is the same search.** `RosterPage::searchClause()` and
  `RosterPage::SEARCH_MIN_CHARS` are now public and both screens call them;
  a word finds the same people on either, and the LIKE escaping that keeps
  a member named `100%` findable is spelled once.
- **It narrows the same predicate everything else does.** The clause is
  ANDed onto the `$where` the cards and the list share, so spec-v1 §7.1's
  rule — every figure equals the list filtered to it — survives a search:
  the four cards above a searched list describe exactly the people in it.
  It narrows *within* the toggle, never around it: "Smith" on My members
  finds the Smiths assigned to the caller, and on My team the Smiths in
  scope. It is offered under a Committee Dashboard drill-down too, unlike
  the team picker, because it can only subtract and the term is printed
  beside the box — nothing about the group is altered quietly.
- **It never widens `show`.** A match who is fully complete still falls out
  of the default outstanding-only list, and the list's own empty state says
  so with "show everyone anyway" beside it. Quietly switching to `show=all`
  because a search was typed would be a filter the officer did not set, and
  V2-7's concern — a search that finds nobody must never look like a member
  who is gone — is met by the banner counting the match and the sentence
  saying why the row is not drawn.

The term travels everywhere the toggle does: on every `$href` link, in the
team picker's hidden fields, and in the log-contact sheet's return state,
through `dashboard_return_query()` as bounded text (the rule Designate Users'
own search already used). An officer who finds one member, logs the call and
lands back on fifty rows has lost the thing they typed; a test reads the
rendered screen for the term on both halves of the toggle and in the sheet.

The empty states are ordered so the truest one wins: with a term in the box,
"no members are assigned to you" would be false and "your roster is empty"
would send an officer to an Admin over a search they can clear themselves.
"Nobody matches" comes first, and offers the wider place to look — My team
from My members, all teams from one — and the way out.

### 7.2 Log contact, on the reference view

View My Roster gains **Log contact** as the fourth action on every row, on
exactly My Roster Status's terms: a link that re-renders the page with *this*
row's sheet open (`?log=id`, one row at a time — the sheet is ~1.6KB of
repeated `<option>` text, and a hundred copies would be spec-v1 §10's whole
first-paint budget by themselves), absent on a closed show year, and a
`<tbody id="m…">` anchor the link scrolls to.

**One sheet, one write.** The sheet moved out of `dashboard.php` into
`View::logContactSheet()` and both screens render it, because a sheet that
posted different fields from two screens would be a contact that logs from
one and 404s from the other. Both post to `/log-contact`; `LogContact`
re-checks `Access::allows()` with a Subject built from the member's own row,
whichever screen the form came from, so View My Roster's rows gaining a
write does not relax anything — the guard the route table already carried
and the per-member check the handler already made are what decide it. A
test reads both views and fails on one that builds a `contact_type` select
of its own.

**The 303 comes back to the screen that sent it.** The form carries a
`screen` field — `roster`, or anything else for the dashboard, which is what
every older form says by saying nothing — and `log_contact_act()` picks the
return path from it. View My Roster gets its own whitelist,
`roster_return_query()`: the search term as bounded text, `team[]` as ints,
the sort key from `RosterPage`'s own four, the direction, the page and the
size. Not `log`: the sheet just submitted must not come back open. The flash
that says "Contact with … is logged" is read by the roster screen too, so
the confirmation lands where the officer is.

What it deliberately does not do: put the Result column on View My Roster
(V2-8 stands — the expansion already carries the history the word
summarises), or move the team default there (V2-7 stands, for the reason
above). The reference view is still the reference view; it has simply
stopped being read-only on the one row an officer has just found.

---

## 8. The call loop, and the shell

Phase 10.3 is fifteen changes from a UX review of the build at 10.2, read
against spec-v1 §1.2's two people: the Captain in a parking lot and the
Division Chairman at a desk. Each is HTML, CSS, a query or wording. None is
a script — the CSP still forbids one — a framework, a schema change or a
second layout, and the 100KB first-paint budget is unchanged by every one of
them. The review's own ledger, with the evidence behind each item, is kept
with the session that produced it; what follows is what was decided and why.

### 8.1 The first screen is the first call

On a 360px phone, My Roster Status put the working list under the lede, the
toggle, the team picker with its hint, the search box with its hint, the
banner and four cards each with a legend — roughly two screens of scroll
before "The next calls to make", on the screen spec 1.2 describes as "one
screen: who is outstanding, and a button that dials them".

Below 720px the four cards and the two controls each **fold** behind a 56px
label; above it nothing folds. The fold is a checkbox and a label, not
`<details>`, because `<details>` cannot be open at one width and closed at
another without a script, and a checkbox can — the CSS ignores it above the
breakpoint. Not a byte of the folded content changes. The cards' label is
the four outstanding counts in one line, so the fold is itself the summary;
and the controls' fold is **open whenever something inside it is in force**
— a search term, a chosen team — because a narrowed list must never hide the
control that narrowed it. The lede offers a skip to the list.

The lede also says **how many days the show year has to go**, from
`show_year.ends_on`, which was in the table and on no screen. `View::daysLeft()`
derives it in the display zone; a year with no date says nothing.

### 8.2 A chip's word

Spec 8.3 set one word per status. The owner's renaming at Phase 5 made three
of them three words — Open/No Contact, Reported Complete, Member Handling —
and four such chips with their metric prefixes wrap a phone card to three
lines. `MetricStatus::chipLabel()` is the chip's word: Open, Reported,
Handling, and the same as `label()` for the rest. The full word rides in the
chip's `title` and is spelled everywhere else — legend, popover, Result.
`label()` is still the only place the owner's words are spelled; this is a
shorter form of one vocabulary, not a second one.

### 8.3 A search is words

`RosterPage::searchClause()` tested the whole typed term against four
columns separately, so "John Smith" — the natural thing to type — matched
nothing, on four screens, and the empty state's honest sentence made it read
as "this member is gone". The term is now split on whitespace and commas,
every word must match one of the four columns, and the words are ANDed:
"John Smith", "Smith, John" and "Zeb Fin" find the same person. Designate
Users and Import History, which each carried a copy of the old clause, call
the one clause. Four placeholders per word, six words at most, wildcards
literal in every one.

### 8.4 Call, then log

Spec-v1 §8.4 wanted a Call tap to "offer to log the contact on return", and
without a script the page cannot know a tap happened. The nearest thing:
the open sheet **carries the row's Call, Text and Email inside it**, as its
first and largest targets, on the row's own terms — Text only for a cell
phone, Email only with an address, absent never disabled — so the order
becomes open the sheet, dial from it, return to a page already open on this
row with the form waiting. The sheet's first control takes `autofocus`.

Every write that changes one row **comes back anchored to it**: log-contact
to `#m<id>`, Designate to `#m<id>` with the row still open (the return
carries `member`), Manage Teams to `#t<id>`. `:target` draws a rule down the
row's edge and `scroll-margin-top` keeps it out from under the bar.

### 8.5 The shell

**One notice component.** Fourteen views each spelled six lines, and the same
danger level read Refused, Failed or Stopped by screen. `View::notice()`
renders every notice with one vocabulary — Done, Note, Stopped — and the
layout places it: inside the sticky bar for a signed-in person, with
`role="status"`, so a landing further down the page still shows the word;
at the top of the column for anybody else.

**A nav of the four working screens** — Status, Roster, Assign, Committee —
filtered by capability exactly as the menu filters its tiles, the current
one marked `aria-current`, and **Sign out** beside the name on every screen.
The ad-hoc link rows at the foot of twelve screens are gone. While a
password change is forced the bar offers Sign out and nothing that loops:
the Menu link 303'd straight back. A password change is now confirmed on
landing, as its lede promised.

**A link token.** Action Orange is 4.9:1 on white and 4.1:1 on Dust Light,
under spec-v1 §10's 4.5:1, and the links that matter sit on Dust Light —
menu tiles, empty states, notices, hints. `--link` is `#9C4512` in light
(6.4:1 on white, 5.4:1 on the surface) and Rodeo Orange in dark (5.9:1).
Buttons keep Action Orange, where white text is what is measured.

**`color-scheme: light dark`**, declared in a meta and on `:root`, so a
`<select>`'s drop-down, the date picker, the checkboxes and the scrollbars
draw dark on the dark page; and a `theme-color` per scheme for the phone's
own chrome.

### 8.6 All teams on one page

The Committee Dashboard opens one division and one area at a time, for a
sound byte reason (§7.3). The cost was that "a Division Chairman comparing
25 teams" could never see them side by side. §7.3 measured the fully
expanded tree at 75.4KB, inside the budget, so a single flat level was never
the bytes' problem. **`level=teams`** is every team in scope as one sortable
list: the same nine columns, drill-downs and sort whitelist, the division
and area as words under the name, a team spanning two divisions once per
division exactly as in the tree. The tree stays the default and out of the
URL.

### 8.7 Selected, counted

Spec-v1 §7.4 wanted the sticky bar to read "12 selected"; the CSS said that
was impossible without a script. CSS counters count ticked boxes in document
order, and the bar sits after the table: `counter-reset` on the form,
`counter-increment` on `:checked`, `counter(sel)` in the button. Assign
Officers and Flagged for Purge both read it. `:has()` only dims the button
while nothing is ticked; the server still answers a bare press.

### 8.8 Gating by consequence

Typing `CONFIRM` was required to purge one dropped member, close a year and
carry assignments forward, while applying a 1,954-row import, making a year
active for every officer and re-opening one were each one press. The gating
did not track consequence. Two tiers, applied:

- **Reaches everyone, or is hard to reverse.** Applying an import asks for a
  ticked "I have read the diff above", required by the browser and refused
  again by the handler. Make active and Re-open are a **step**: a link to
  `?confirm=activate&year=` draws a card saying what will happen, and the
  button is on the card. Close and Carry keep their typed word, in place.
- **One person, reversible.** Purge, Restore, Revoke, Reset and Grant stay
  a press. A purge's word is typed on a **second page that names the ticked
  members** and carries their ids, so a wrong word re-renders that page with
  the names still on it — before, it 303'd to a fresh list and forty ticks
  were gone.

### 8.9 One form for the export

The year and the team boxes sat in a GET form under "Update the count", and
Download was a second POST form carrying hidden copies of the *last loaded*
selection: tick two more teams, press Download, get the file for the boxes
as they were before — with no error, on the screen §4 built so that "the
screen says 1,247 rows and the file holds 82" could not happen. One POST
form, two buttons named by action: count re-renders from the posted boxes,
download streams from them. The count card is advisory and says so.

### 8.10 Which of three things "not found" means

One sentence — "There is nothing at this address" — served a real 404, a
capability refusal, a member outside scope, a wrong key, and a roster
screen with no active show year. It was wrong for two of the people reading
it: an Officer who tapped a leadership link, and an Admin on a fresh install
reading it on their own dashboard. The page now says which: a signed-in
capability refusal reads "Not open to your level" with a 403 — the menu
already shows which screens exist, so nothing is given away; no active show
year reads what it is and links an Admin to Show Year; everything else, a
member outside scope and a wrong key included, stays the incurious 404.

---

## 9. The member card, and the rest of the review

Phase 10.4 is the sixteen "next" items from the review Phase 10.3 came
from. The same rules hold: nothing is a script, a framework, a schema
change or a second layout, and the 100KB budget is untouched.

### 9.1 One person, one screen

Spec-v1 §8.2 names "single-member screens" among those that keep the
narrow column, and none existed: every mention of a person was a row, and a
name on Assign Officers, Dropped Members or Designate Users had nowhere to
link. `/member?id=` renders `Rerm\Roster\MemberPage`: name, number, the
imported title and team; Call, Text and Email as 64px targets; the four
chips, harassment training and the Result word; the log-contact form OPEN
on an open year; this show year's contacts; every earlier year's under a
closed `<details>` — OI-12's deferred report, which spec-v1 §5.5 retained
the data for so that it would be a query and not a migration; and who is
assigned. Roughly 5KB against a fifty-row list's fifty-five.

It reads through `ScopedQuery::forUser()` and, failing that,
`droppedForUser()`, so Dropped Members can open the person it wants rung
and the card says they are dropped. Out of scope, purged or missing is
null, and the route answers the same 404 a typed URL would. Every derived
value comes from the functions the lists use — `MetricStatus::derive`,
`ContactOutcome::summarise`, the CELL PHONE rule — and a test holds the
card to the row.

**The way back keeps the list.** Each list links a name with `from=` and
its own return state as `back=`, and the card re-whitelists `back` through
that list's own rule (`dashboard_return_query`, `roster_return_query`)
before it becomes a link. A drill-down's forty people, a search term and a
page survive the trip to the card and back — the same reason every link on
My Roster Status carries them. The log form's `screen=member` returns to
the card with both.

### 9.2 One answer for everything open

The sheet offered one select per open metric, four options each. A member
outstanding on all four who says "I'll sort it all this week" is one
answer. With more than one requirement open, `View::logContactForm()` draws
a radio row — No change · Member Handling · Reported Complete — for
everything still open, and the per-metric selects under a closed
`<details>` for the exceptional case; `LogContact` applies `progress_all`
to every scored metric the roster shows unmet, and a per-metric choice
wins where one was made. Imported Y is never overwritten; one open
requirement is its own select as before.

### 9.3 A text that starts itself

`View::contactLinks()` is now the one place `tel:`, `sms:` and `mailto:`
are built — the CELL PHONE rule in one function rather than four views —
and it fills `contact.sms_body` and `contact.mail_subject` from config with
`{first}`, `{officer}` and `{team}`. `?&body=` is the spelling both iOS and
Android accept. Nothing is sent by this application; the officer's own
phone opens Messages or Mail with the text in place. The mail-safety design
of spec-v1 §3.3a is untouched: no message leaves this host.

### 9.4 Dropped Members can record the answer

The screen exists so an officer can ring somebody and find out whether
they left, and it had no way to write the answer down. Log contact on the
row opens the member card; `ScopedQuery::contactable()` — present or
dropped, never purged, never the system row — is what `LogContact` reads
by, so the contact lands. Scope is still the matrix's question with a
Subject. A row with no way to reach the person now says so.

### 9.5 Installable

`public/manifest.webmanifest`, relative `start_url` and `scope` so the
mount point is not spelled twice, `display: standalone`, and the two sizes
`bin/gen-icons.php` now writes — 192 and 512 — without touching the two
shared icons. `manifest-src 'self'` in the CSP, because `default-src 'none'`
blocked the fetch silently, and `AddType` in `.htaccess`, because a manifest
served as text is no manifest. No service worker: nothing is cached and
nothing can go stale.

### 9.6 Print

`@media print`: the sticky bar, the forms, the action bars and the dial
buttons go; the table layout comes back whatever the width; every
`<details>` opens; black on white. The chips already carry words, so the
page survives monochrome. A Division Chairman prints the roll-up for a
meeting; a Captain prints their twenty names.

### 9.7 One way to write a time

`View::time()` — relative words, the local absolute as the title, the UTC
instant in `datetime` — and `View::timeFull()` for the screens where the
absolute is the point, in one spelling: `7 Sep 2026, 2:14 pm`. Four formats
coexisted, including bare UTC strings in cells; a test reads every view
for one.

### 9.8 The menu, by job

Three groups — Chase, Lead, Administer — each a heading and one 56px link
row per screen with a line on what it is for. Each tile stays one line
with its route, because tests read the menu a line at a time.

### 9.9 The share beside the count

A bar three characters wide carries no scale. Each requirement cell now
prints `12/40 · 30%`, and `by=share` sorts a requirement column by the
share complete rather than the outstanding count — a whitelist of two,
the count still the default, the sort keys unchanged.

### 9.10 The chooser, sorted by work

Assign Officers' team chooser starts on the most unassigned, then the most
re-pointing, then the name, and every column sorts from a whitelist. The
lede that apologised for the alphabet is gone with the alphabet. The
scope-wide "no officer on this team" count left the bucket toggle, where it
read as a bucket of this team; it stays as a column on the chooser and a
section at the foot.

### 9.11 Post, redirect, get

The import, the contact import, forgot, reset and setup re-rendered on
their POST, so a reload re-submitted and the browser asked whether to
resend — on the two screens that write the most rows. Each now redirects:
the imports to `?batch=`, forgot to its two result states, reset to `?done=`
or back to its link with the reason, setup back to itself with the key in
the address, which a POST used to lose. `View::joinNotices()` folds a
handler's several notices into the one the flash holds, at the loudest
level. The contact import keeps one column width for the whole flow.

### 9.12 A download that says it happened

A download streams from a POST and the page cannot change. Export and
Create Forms now open with the caller's most recent download of that kind,
from the audit row written before the body was sent: "Your last export: 82
rows for show year 2026, 3 minutes ago." The Roster Change Form's
truncated sentence names the show year.

### 9.13 The rest

Designate Users renders no disabled button: where a revoke or a reset is
not permitted the sentence stands alone (spec-v1 §8.4, applied). View My
Roster's team filter is a fold of tick boxes, the Export screen's pattern,
rather than a `<select multiple>`. A skip link to `#content`, `aria-sort`
on every sortable header, `aria-current` on every toggle, `scope="col"` on
every header cell, focus rings on links and selects, and no empty
`data-label`.

---

## 10. The rest of the review

Phase 10.5 closes the UX review that began at 10.2: the seven "later" items,
R32 to R38. As before, none of them is a script, a framework, a schema change
or a second layout, and every figure lands on exactly the people it counts.

### 10.1 What the last import did

An officer chasing twenty people for weeks never saw whether it was working:
the only feedback was the next roster, read as a whole. Phase 10's
`import_change` records every metric flip per member per import, so "how
many of mine moved to Complete in the last file" is a count and not a
migration. `Rerm\Roster\SinceImport` answers it three ways — the last
applied batch, the flips to Y per scored metric, and the flips per member —
and every one reads through the caller's predicate over `m`: the scope, the
toggle, a drill-down, a search. The number under a card therefore describes
exactly the people the card counts, the rule every other figure on My Roster
Status obeys (§4.4).

Three rules bound it. A **first appearance is not a flip**: somebody who
arrives with a Y was never chased. A **flip to N is a loss**, never counted as
met. And it reads only what an import wrote — never a contact, a progress
value or an assignment — so it can never claim credit for a call the roster
has not yet confirmed. My Roster Status prints the total in the banner
(`+3 requirements met since the import of 4 Sep`) and each metric's figure
under its card; the Committee Dashboard tallies the per-member flips into a
**Newly met** column per division, area and team, sortable like the rest.
Before the first applied import, nothing is printed rather than a zero.

### 10.2 The import forms

The roster import asked "what this import is" with a bare label above three
radios and a team select beneath all three, as though it applied to each. It
is now one `fieldset` with the question as its `legend`, and the team chooser
sits indented under the Team option it belongs to. The contact import (§8.7)
opened with the column manual and put the form beneath it; the form is now
first — choose the file, the officer and the team — and the manual folds
below under "What the file needs to contain", linked from the file field.
After a discard, or a file that would not stage, the officer and the team come
back chosen: the next file is nearly always for the same pair.

### 10.3 Manage Teams, grouped and findable

Ninety-six rows in a flat list, captioned "grouped by area" of a list that
was not. The rows are now one `<tbody>` per area, named areas first and
`(No area)` last, each under a heading row that counts its teams; and a find
box on the roster's word rule (`RosterPage::searchTokens()`, §7.1) over the
team's name, its area and its division, filtered in PHP over rows already
read. The count of everything is kept for the sentence beside the box, and a
find that lands on nothing says what it looked for.

### 10.4 Import History paged, and the Audit Log asked about one member

The list of imports stopped at fifty with no count and no way past it. It is
now counted and paged on its own `bpage`, so it never collides with a change
list's `page`; a member's history links to their card (§9.1). The Audit Log
gained the question it could not answer — "everything that happened to
1234567" — as a member-number filter that resolves the number to the member
row and, where one exists, the account row, because a purge names the member
and a grant names the account. A number nobody holds matches nothing, not
everything; the filter narrows the others rather than replacing them.

### 10.5 The Roster Change Form's Enter key, and its codes

Enter in any field submits the first submit button in a form, and on the RCF
that was "Load the team", which threw away every row typed so far. A
visually hidden Download button now comes first, `tabindex="-1"` and hidden
from assistive technology, so Enter downloads and a keyboard reaches only
the visible one. The "What the codes mean" legend, shut behind a `<details>`
on every width, follows §8.1's fold: open beside the rows on a desktop, a
56px label on a phone.

### 10.6 The Status page's words

Mail off is the shipped state (`CLAUDE.md`), so the health check says
**Disabled** in green rather than amber; its migration hint names `/setup`
before `php bin/migrate.php`, because this host has no shell; and the page
ends with a way into the application and to Setup instead of nowhere.

---

## 11. Open items

Carried from spec-v1 §12 where they bear on v2, plus those this document
raises.

| # | Question | Assumption today |
| --- | --- | --- |
| V2-1 | Should a produced form be **kept**, rather than only downloaded and logged? A `form_batch` row would answer "what did we send them in March", and the audit row currently answers only "that we sent one". | **Answered by Phase 11 (§12): kept.** Somebody asked the question the log cannot answer — "was an RCF ever submitted for this member, and where did it stop" — within a month of the feature reaching them. `rcf` and `rcf_row` hold what was printed; the file is still unlinked. |
| V2-2 | Should an RCF be able to **apply itself** to the roster once Rodeo Houston has processed it? | No. An import refreshes what Rodeo Houston knows and this application never writes their columns from anywhere else (`CLAUDE.md`). A form is a request, and the next roster import is the answer. |
| V2-3 | Which form is next? | Undecided. The menu at `/forms` is shaped for it. |
| V2-4 | Should the officer lists be **scoped** rather than committee-wide (§2.2)? | Committee-wide, because the sponsor for a new recruit must be a VC or higher and is frequently on another team. Names and titles only. One line to narrow if it is ever unwanted. |
| V2-6 | ROOKIE and WAIT LIST are checkboxes in the cells but the form's printed instructions beside them still say `y/n` and 'Please enter "Yes" or "No"'. Which does Rodeo Houston actually read? | The cells, because that is what their workbook now holds and what a reader ticks. Worth one question to the membership office; if they want text, it is a two-line change and the checkbox formats stay. |
| V2-5 | Should the sub-committee heading at `G5` carry the division (`Division - Team`) or the team alone, as `Subcommittee 1` does? | It carries the division. The field is six columns wide, it names what the whole form is about, and the per-row column — the one Rodeo Houston reads as `Subcommittee 1` — carries the team alone. |
| V2-6 | Should `import_change` be **retained forever**, or aged out with the show year? A full first import writes 1,954 rows and a monthly refresh a few hundred; ten years is comfortably inside a table this shape. | Retained forever, like every other import record. Revisit only if it is measured to be a problem, and a roll-up would then have to keep `dropped` and `returned` in full — they are the reason it exists. |
| V2-8 | Should the **Result** column (§6.2) appear on View My Roster too? | Not yet. That screen is a reference view sorted by name, not a working list, and its expansion already carries the history the word summarises. The column earns its width where the next call is being chosen. Unchanged by §7, which gave that screen the write and not the word. |
| V2-9 | Should a search on My Roster Status (§7.1) **include the complete** automatically, so a found member is always drawn? | No. The banner counts the match and the list's empty state says why the row is not there, with the way to draw it beside the sentence. Widening `show` because a word was typed is a filter the officer did not set. Revisit if the sentence is measured to be missed. |
| V2-7 | Should the team default apply to **View My Roster** as well? Its team filter is Senior Officer and above, and unchanged by this phase. | Not yet. That screen is a search over a roster rather than a working list, and starting it narrowed would make a search that finds nobody look like a member who is gone. |
| OI-12 | Multi-year contact history reporting (spec-v1 §12) | Still deferred; the data is retained unconditionally. `import_change` is the shape the answer will take when it lands. |
| OI-4 | Retention rule for dropped members (spec-v1 §12) | Flag only; an Admin confirms the purge. |
| V2-10 | Should a **Senior Officer** — a Division Vice Chairman — see the forms made by the Officers on their teams, between "mine" and "everyone's" (§12.3)? | Not yet. The request named Admins and Executive Officers, and a Vice Chairman marking their own form sent is the ordinary case. It is one more group on `/rcfs` and one scope predicate when somebody asks. |
| V2-11 | Should the **Division Chairman's numbered form** be generated here, from the tracked lines — pick lines from several officers' forms, number them, download one RCF? The feedback that produced §12 asked exactly this: a forwarded form will not do because theirs must be numbered. | Not yet; the tracking has to exist first, and it now does. The shape is clear — a picker over `rcf_row` where `sent_to_rosters_on IS NULL`, the same writer, the serial written back to every line it took — and it would make `serial` a fact this application produced rather than one it was told. Worth doing when the Division Chairmen say so. |
| V2-12 | Should "in the roster" (§12.5) compare the **value** the import wrote to the value the line asked for — the new title, the new team — rather than the field alone? | Not yet. A title change that landed as a different title is rarer than the spellings differing, and a line that reads "yes" against a different title is checked in one tap on the member card. Revisit if a false "yes" is reported. |

---

## 12. Track RCFs

Phase 11. The first feature to come out of a real user rather than a review,
and the one the v2 handoff said to expect: **"revisit when somebody asks the
question the log cannot answer"** (§11 V2-1). Somebody did, within a month of
Create Forms reaching them.

### 12.1 The question

An RCF travels. A Vice Chairman generates it and emails it to their Division
Chairman; the Division Chairman numbers it — the box at `J1`, "CHANGE FORM #
(DC USE ONLY)" — and sends their own numbered form to Rodeo Houston's
membership office ("Rosters"); Rosters process it, and the next roster
import shows the change. The Chairman is copied on most of it.

The feedback, with names replaced by titles:

> The goal would be to have an easy way to see whether an RCF was submitted
> for a particular member, especially after that member reaches out because
> they haven't received an email to pay dues or complete their background
> check. Right now, when something falls through the cracks, I have to figure
> out what happened and often end up sorting through tons of RCF emails to
> track down the status of one member.

and, on the second half of the same process:

> Some DCs take forever to send in RCFs after the VCs send them in. … each RCF
> sent by the DC has to be numbered, so simply forwarding the VC's RCF won't
> work.

So the thing to build is the pile of emails, sorted: every form this
application produced, who made it, and **where each line of it has got to**
— because the question is asked about a *member*, and a member is a line.

### 12.2 What is kept

Phase 9 built the file, sent it, unlinked it and kept one audit row. Phase 11
keeps the form: `rcf` is one generated form and `rcf_row` one filled line of
it, written in the same request that sends the file, after the file is built
and before its body goes out. A row there means a form really left; a form
that cannot be kept is not sent.

**What is kept is what was PRINTED, not what was picked.** The submitter as
`Name, Title`, the sub-committee as `Division - Team`, every cell of every
filled row exactly as `RosterChangeForm::draw()` wrote it. So a form can be
downloaded again, byte for byte, without the roster having to still say what
it said then — and it will not, if the form was a removal and it worked. A
form is a request; the roster changes because the request was granted, and
the record of the request must not change with it. A test draws the sheet
from the original input and from the kept rows and holds the XML equal, after
changing the member's title in between.

Blank rows are not stored: a three-person form is three rows, at the
positions they were typed in, and regeneration prints blank between them
exactly as the original did. `member_id` is resolved from the number the
officer typed, once, unscoped, so the member card can list the forms about a
person — it is a link, and the card re-checks scope like every other read.

Both tables are **records** in the `CLAUDE.md` sense: no import writes them,
nothing deletes a row, and `tests/admin_test.php`'s list of tables that must
never lose one names both. The file itself is still built in `var/exports`,
unlinked as soon as it is sent, and downloaded by POST (§1.4). Nothing about
"PII leaving the building" is relaxed by keeping the request.

### 12.3 Who sees what

Two groups on one screen, and one new row in the matrix:

| Capability | Minimum level | Scope |
| --- | --- | --- |
| `create_forms` (existing) | Officer | Scoped — and it now also means *may see the forms they made* |
| `view_all_forms` | Executive Officer | **Everywhere** |

`/rcfs` opens on **the caller's own forms**, for anybody who may make one.
Below them, for a holder of `view_all_forms`, **everybody else's**, with who
made each — the request's "show the ones I generate at the top and the ones
I have access to in a subgroup below". `view_all_forms` is the first
capability with an Executive Officer floor and an Everywhere scope, and both
halves are deliberate: the question is committee-wide by nature — it is
asked about a member who fell between a Vice Chairman, a Division Chairman
and Rodeo Houston, and a scope would hide exactly the hand-off that failed —
and Executive Officer is who the forms already pass through, because a
Division Chairman numbers and forwards them. Transcribed a second time in
`tests/access_test.php`, as every row is.

**Whoever may see a form may track it.** A Vice Chairman marks their own
form sent to the Division Chairman; the Division Chairman numbers anybody's
and marks it sent to Rosters. A form the caller may not see is `null` from
`RcfTracking::one()` and the route's 404 — the same answer an out-of-scope
member gets, for the same reason.

`RcfTracking::mayView()` is the whole rule, in one place, so the list, the
form, the search and the member card cannot disagree about it.

### 12.4 The screens, and why they are shaped this way

The request said to spend some time on the UX. These are the decisions.

**The list opens with a search box**, before either group. The question that
brought the screen into being is about one member and is asked with a phone
in one hand; the groups are what you scroll to when you are the Chairman
with a Tuesday to spend. It finds a member by name or number **on any form
the caller may see**, every word landing (§8.3), over the printed name and
the number — the same rule as the roster's search, on the columns this table
has. A newcomer typed in by name is found by that name.

**Each form is a row of fractions.** `RCF #`, `To the DC`, `To Rosters` and
`In the roster` each read *n of m* lines, in a chip whose colour says done,
part done or not started — always with the numbers, never the hue alone
(spec-v1 §8.3). A form whose lines have gone different ways is visible as
such from the list, which is where "where did the breakdown occur" is
answered without opening anything.

**One form is a table of its lines with three controls each**: an RCF number
box and two date boxes. Per LINE and not per form, because the request said
so and because it is right: the Division Chairman bundles lines from several
officers' forms into one numbered form of their own, so the number belongs
to the line, and a form the Division Chairman split in two has two numbers.

**But the ordinary case is that the whole form went at once**, so the table
has an **Every line** row above the lines, and two **today** buttons above
the table:

* A value typed into the Every-line row is written to every line and **wins
  over the lines below it**. Otherwise each line's own field is written as it
  came back, and a date box emptied clears that date. Stated once on the
  screen, once in `RcfTracking::track()`, and held by a test that types both
  at once. The alternative — the line winning — would make the whole-form
  row useless the moment any line carried a value, because every line's box
  arrives filled in with what it already has.
* **"Sent to the Division Chairman today — every line not yet dated"** and
  its Rosters twin date only the lines that have no date on that step. Lines
  already dated keep their day, so the button is safe to press twice and safe
  to press after one line was dated by hand. Each button is its own form, so
  pressing one never submits the table below half filled in. "Today" is
  Houston's today (`app.display_timezone`), not UTC's: after seven in the
  evening those are different days.

**Enter saves.** The tracking form has one submit button, so there is nothing
for the Enter key to reach first (§10.5's lesson).

**Download again** rebuilds the file from the kept rows (§12.2), logs it as
`regenerate_form` — its own verb, so "how many went out" and "how many were
re-sent" are different questions — and counts it on the form's page. A POST,
for the reason the first download is.

**Every line links its member to the member card**, with `from=rcf` and the
form's id as `back`, re-whitelisted through the card's own rule (§9.1), and
**the member card lists every form the member is on** — newest first, each
with its change, who made it, and the four facts — because the question is
asked about a person and that is the person's page. Lines are shown to anyone
who can see the member; a Captain reading that the Vice Chairman sent a form
about one of their people is the point. Each line says whether *this* caller
may open the form it is on, and links only then.

**Create Forms says where the last form went.** §9.12's "Your last form"
sentence now ends in **Track it**, because there is finally somewhere for a
streamed download to lead.

The tables transform at 720px like every other (spec-v1 §8.2): a stacked card
per line on a phone with 56px controls, the paper form's row order at a desk
with the controls at 40px, as the RCF grid already does and for the same
reason. Both screens are asserted under spec-v1 §10's 100KB.

### 12.5 The fourth step is derived, never typed

Generated, to the Division Chairman, to Rosters — and then **in the
roster**, which nobody types. `RcfTracking::landed()` reads it out of
`import_change` (§3) at read time: a line has landed when an applied import
*after the form was generated* says what the line asked for —

| `*TYPE` | landed when `import_change` holds, for that member number |
| --- | --- |
| `A` | `created` or `returned` |
| `R` | `dropped` |
| `T` | `updated` on `title` |
| `S` | `updated` on `team` |
| `S & T` | `updated` on `team` or `title` |

— and the day it landed is the **first** such import. A line with no member
number cannot land, and the screen says so beside it: an addition typed in
by name alone has nothing for the import to be filed under yet.

A stored flag would be somebody's opinion of what the roster says. The roster
says it itself, on exactly the day the import ran, and §3's record was built
to be read this way. One query for however many lines, on every list.

### 12.6 What it never does

* **A form never writes the roster.** §11 V2-2 stands. Nothing reads `rcf`
  to change a member, and the import never writes `rcf`.
* **Nothing deletes a form or a line.** Both are on the protected list.
* **A serial is what somebody typed.** Free text, thirty-two characters,
  bounded rather than refused: the Division Chairmen's numbering is theirs
  (V2-11 is the day it becomes ours).
* **Every tracking change is audited** — `track_form`, one row per save,
  the lines that changed with before and after — and a save that changed
  nothing writes nothing, not even the audit row. Who last tracked a line,
  and when, is also on the line, so the screen can say "tracked by Erin
  Delta, 3 days ago" without a join to a log.

### 12.7 The rest of the feedback

The second paragraph — Division Chairmen forwarding rather than re-typing,
and the numbering that stops them — is V2-11, and this phase is its
prerequisite rather than its answer: the numbered form is produced *from*
tracked lines, and there were no tracked lines. The per-line serial is the
half of it that lands now.
