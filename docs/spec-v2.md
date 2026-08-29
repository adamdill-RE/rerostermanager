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

## 3. Open items

Carried from spec-v1 §12 where they bear on v2, plus those this document
raises.

| # | Question | Assumption today |
| --- | --- | --- |
| V2-1 | Should a produced form be **kept**, rather than only downloaded and logged? A `form_batch` row would answer "what did we send them in March", and the audit row currently answers only "that we sent one". | Not kept. The audit row names the actor, the sub-committee and the row count; the file is unlinked. Revisit when somebody asks the question the log cannot answer. |
| V2-2 | Should an RCF be able to **apply itself** to the roster once Rodeo Houston has processed it? | No. An import refreshes what Rodeo Houston knows and this application never writes their columns from anywhere else (`CLAUDE.md`). A form is a request, and the next roster import is the answer. |
| V2-3 | Which form is next? | Undecided. The menu at `/forms` is shaped for it. |
| V2-4 | Should the officer lists be **scoped** rather than committee-wide (§2.2)? | Committee-wide, because the sponsor for a new recruit must be a VC or higher and is frequently on another team. Names and titles only. One line to narrow if it is ever unwanted. |
| V2-6 | ROOKIE and WAIT LIST are checkboxes in the cells but the form's printed instructions beside them still say `y/n` and 'Please enter "Yes" or "No"'. Which does Rodeo Houston actually read? | The cells, because that is what their workbook now holds and what a reader ticks. Worth one question to the membership office; if they want text, it is a two-line change and the checkbox formats stay. |
| V2-5 | Should the sub-committee heading at `G5` carry the division (`Division - Team`) or the team alone, as `Subcommittee 1` does? | It carries the division. The field is six columns wide, it names what the whole form is about, and the per-row column — the one Rodeo Houston reads as `Subcommittee 1` — carries the team alone. |
| OI-12 | Multi-year contact history reporting (spec-v1 §12) | Still deferred; the data is retained unconditionally. The smallest real v2 feature after this one. |
| OI-4 | Retention rule for dropped members (spec-v1 §12) | Flag only; an Admin confirms the purge. |
