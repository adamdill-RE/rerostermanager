# What the Rodeo Houston export actually contains

Measured 2026-08-26 against `Same_Full_Coommittee_Dataset.xls` — one sheet,
`Full Roster`, **1,954 data rows × 33 columns**, described by Rodeo Express as a
representative full-committee extract.

This document exists because the prose specification and this file disagree in
seven measured places, and every one of them would have become a defect. Read
it before writing import, permission or assignment code.

---

## 1. Column inventory

| # | Column | Non-null | Unique | Verdict |
| --- | --- | ---: | ---: | --- |
| 0 | `Title` | 1954 | 15 | **Drives access level.** See §3 |
| 1 | `Customer Number` | 1954 | **1954** | **Natural key.** 6–7 digits |
| 2 | `Name` | 1954 | 1951 | "Last, First M." — redundant |
| 3 | `Full Name` | 1954 | 1947 | display only, **not unique** |
| 4 | `Prefix` | 29 | 16 | ignore |
| 5 | `First Name` | 1954 | 1038 | import |
| 6 | `Last Name` | 1954 | 1223 | import |
| 7 | `Preferred Name` | 980 | 672 | **import — use it in every list** |
| 8 | `Legal Name Verified` | 1954 | 2 | import, not surfaced in v1 |
| 9 | `Subcommittee 1` | 1954 | **96** | **Team** |
| 10 | `Subcommittee 2` | 1902 | 2 | **junk** — `Tba 9` ×1898, `Lifetime` ×4 |
| 11 | `Subcommittee 3` | 1882 | 4 | **Division** — 72 blank |
| 12–15 | `Address` `City` `State` `Zip` | 1954 | — | import; 1946 TX, 8 out of state |
| 16 | `Primary Phone` | 1954 | 1951 | uniform `(555) 555-0100`, 3 shared |
| 17 | `Primary Phone Type` | 1954 | 3 | **gates the `sms:` link.** See §5 |
| 18 | `Primary Email` | **1953** | 1951 | 1 missing, 2 shared |
| 19 | `Show Dues` | 1954 | 2 | **Metric 1 — HLSR Dues** |
| 20 | `Committee Dues` | 1954 | 2 | **Metric 2** |
| 21 | `Indemnity` | 1954 | 2 | **Metric 3** |
| 22 | `Background Check Completed` | 1954 | 2 | **Metric 4** |
| 23 | `Harassment prevention training` | **238** | 2 | **tri-state.** See §6 |
| 24 | `Rookie` | 1954 | 2 | 13 rookies — v2 recruiting |
| 25 | `Badge Released` | 1954 | **1** | dead (all `N`) |
| 26 | `Badge Released Date` | **0** | 0 | **dead — entirely empty** |
| 27 | `Badge Issue Date` | **0** | 0 | **dead — entirely empty** |
| 28 | `Badge Pickup Person` | 972 | 874 | free text, half populated |
| 29 | `Eligible for Service History` | **0** | 0 | **dead — entirely empty** |
| 30 | `Eligibility Updated By` | **0** | 0 | **dead — entirely empty** |
| 31 | `LTC Applied` | 1954 | **1** | dead (all `N`) |
| 32 | `In Other Committees` | 1954 | 2 | 270 `Y` — v2 recruiting |

Six columns carry no information at all in this export. Import them to columns
that exist, surface none of them, and revisit when a future export populates
one. Do not design a screen around a column that has never had a value.

---

## 2. Finding: the natural key is `Customer Number`

The specification calls it "member number". The column is **`Customer Number`**,
and the import must read that header.

- Range 151,696 – 2,089,937. 1,847 seven-digit, 107 six-digit.
- **1,954 distinct values in 1,954 rows** — the only column that is.
- The seeded master admin `987654321` is nine digits, safely outside the
  observed range, so it can never collide with a real member.

Store it as `VARCHAR(32)`. It is an identifier, never arithmetic, and a future
export with a leading zero must round-trip unchanged.

**Do not key on anything else.** `Full Name` has 7 collisions, `Name` has 3,
`Primary Phone` has 3, and `Primary Email` has 2 and is missing once.

---

## 3. Finding: title strings do not match the specification

Actual values and counts:

| Title (exact string) | Count | Level assigned |
| --- | ---: | --- |
| `Committee Member` | 1630 | Member — no login |
| `Lifetime Committeemen` | 115 | Member — no login |
| `Captain` | 82 | Officer |
| `Assistant Captain` | 66 | Officer |
| `Vice Chairman` | 21 | Officer |
| `Division Vice Chairman` | 8 | Senior Officer |
| `Lifetime Vice Presidents` | 8 | Member — no login |
| `Ambassador` | 7 | Senior Officer |
| `Coordinator` | 5 | Senior Officer |
| `Division Chairman` | 4 | Executive Officer |
| `Past Committee Chairman` | 4 | Member — no login |
| `Chairman` | 1 | Executive Officer |
| `Officer in Charge` | 1 | Executive Officer |
| `Vice President` | 1 | Executive Officer |
| `Lifetime Director` | 1 | Member — no login |

Four mismatches against the prose spec, each of which breaks an exact-string
comparison:

1. Spec writes **"Division Chairmen"**; data says `Division Chairman`.
2. Spec writes **"Divisional Vice Chairmen"**; data says `Division Vice Chairman`
   — not "Divisional".
3. Spec writes **"Lifetime Vice President"**; data says `Lifetime Vice
   Presidents`, plural.
4. Spec never mentions **`Lifetime Director`** at all. One person holds it.

And one ambiguity the spec creates itself: **`Coordinator` and `Ambassador`
appear in both the Senior Officer list and the Officer list.** Resolved as
**Senior Officer** — the higher of the two, affecting 12 people. Flagged as
open item OI-2.

**An unrecognised title imports as Member with no login and raises a named
warning.** It never defaults to officer. The spec's rule — "any title other
than Committee Member or Lifetime Committeemen is an officer" — would have
handed accounts to 8 Lifetime Vice Presidents, 4 Past Committee Chairmen and
1 Lifetime Director.

---

## 4. Finding: the topology is four levels, not three, and leadership breaks it

### 4a. Divisions

| `Subcommittee 3` | Members |
| --- | ---: |
| Satellites Division | 690 |
| Bus Ops Division | 675 |
| Logistics Division | 507 |
| *(blank)* | **72** |
| Member Services Division | **10** |

**72 members have no division.** 57 are `Lifetime Committeemen` parked in a
pseudo-team called `Lifetime`; the other **15 are ordinary Committee Members on
real teams** — `610 Parking Team J`, `Reed Road Ticket Team 1`,
`Administration-Support` and eight others. A division-scoped Senior Officer
will not see them. They must appear in an Admin "unplaced members" view and in
an import warning, or 15 people silently fall out of every roll-up.

**Member Services Division is not an operational division.** Its 10 people are
1 Officer in Charge, 8 Lifetime Vice Presidents and 2 Lifetime Committeemen —
**zero Committee Members.** The spec's carve-out ("anyone non-lifetime in
Member Services has Chairman permissions") therefore resolves to exactly one
person, the Officer in Charge, who is already an Executive Officer by title.
**The carve-out is redundant and is not implemented.**

### 4b. Teams span divisions

Seven of the 96 teams appear under more than one division:

| Team | Split |
| --- | --- |
| `Administration-Support` | Logistics 26 · Member Services 8 · none 1 |
| `Bus Ops Team A` | Bus Ops 84 · Logistics 1 |
| `Bus Ops Logistics` | Bus Ops 13 · Logistics 2 |
| `Bus Ops-Early Bird Team 1` | Bus Ops 22 · Logistics 1 |
| `610-Early Bird Parking Team C` | Satellites 18 · Logistics 1 |
| `Special Projects` | Logistics 2 · Member Services 1 |
| `Special Projects-Recruiting` | Logistics 3 · Member Services 1 |

So **`team` is not nested inside `division`.** Division is a property of the
*member*, not of the team. The schema stores both on `member` and the `team`
table's division is its modal value, for display only.

### 4c. Leadership placement is meaningless

`Administration` (4 people) is: the **Chairman** and **all three of the other
Division Chairmen** — and every one of them is filed under **Logistics
Division**. The Vice President is in `Administration-Support`, also Logistics.

**A Division Chairman's own `Subcommittee 3` does not name the division they
run.** This is why access level comes from the title map and only Senior and
Officer levels read the member's own placement. Executive Officers see
everything, so their placement never has to be interpreted.

### 4d. There is a fourth level in the team names

Team names encode an area that has no column of its own, and the area
leadership sits in a team named after the bare area:

| Area team | Roster |
| --- | --- |
| `Reed Road` (3) | Division Vice Chairman + 2 Vice Chairmen |
| `610` (3) | Division Vice Chairman + Vice Chairman + Past Chairman |
| `Emlr` (4) | Division Vice Chairman + 3 Vice Chairmen |
| `Bus Ops` (7) | 2 Division Vice Chairmen + 2 Vice Chairmen + Captain + 2 members |
| `Ost-Smith Lands` (2) | Division Vice Chairman + Vice Chairman |
| `Chuckwagon` (2) | Vice Chairman + Captain |
| `Administration` (4) | Chairman + 3 Division Chairmen |

**The eight Division Vice Chairmen run areas, not divisions.** the Reed Road Division Vice Chairman
runs Reed Road — five parking teams, five ticket teams, four early-bird teams,
roughly 210 people. Division scope shows him all 690 in Satellites, including
610 and Ost-Smith Lands.

**Decision: divisions, as specified.** The over-broad visibility is accepted.
An `area` column is seeded on `team` by prefix heuristic for **dashboard
grouping only** — 96 flat teams is not a legible dashboard — and it is
forbidden from appearing in any access check. `tests/access_test.php` asserts
that. Revisiting this is open item **OI-1**.

---

## 5. Finding: 116 members cannot receive a text

| `Primary Phone Type` | Count |
| --- | ---: |
| `CELL PHONE` | 1838 |
| `HOME` | **111** |
| `BUSINESS` | **5** |

The spec makes tap-to-text a headline feature. Offering it on 116 numbers
produces a message that goes nowhere and an officer who believes they made
contact. **Render `sms:` only when the type is `CELL PHONE`;** otherwise show
the Call action alone with the type as a quiet label.

Every number in this export matches `(NNN) NNN-NNNN` exactly — zero
exceptions — so normalisation is cheap. Keep both forms: the imported string,
which is what the member recognises, and E.164 behind the link.

**One member has no email at all** (E. Example, `7000005`, Bus Ops Team A).
Import must not reject the row, the Email action must be absent rather than
broken, and password recovery must fail with an explanation rather than a
silent no-op.

**Two emails are shared by four people:**

| Address | Members |
| --- | --- |
| `member-a@example.com` | 7000001 A. Example (Ambassador) · 7000002 B. Example (Committee Member) |
| `member-b@example.com` | 7000003 C. Example · 7000004 D. Example (Lifetime Committeemen) |

This is the case the spec's recovery-email wording exists for, and it is real
but small. The email must name the member number it applies to, in the subject
and the first line of the body.

---

## 6. Finding: harassment training is a fifth, tri-state metric

| Value | Count |
| --- | ---: |
| *(blank)* | **1716** |
| `Y` | 146 |
| `N` | 92 |

88% blank. **Blank is not N** — it means the field was never reported, and
scoring it as a failure would show a committee at 7% compliance on something
nobody is tracking yet.

Imported and displayed as "Not reported / Complete / Outstanding". **Excluded
from the four-metric completion percentage** and from every dashboard total.
Promoting it to a scored metric is open item **OI-3**.

---

## 7. Finding: the assignment model does not fit the roster

The spec says: *"In our model every team has officers (generally 2, sometimes
3) assigned to each committeeman."* Officer counts per team, using the
title map from §3:

| Officers on the team | Teams |
| ---: | ---: |
| **0** | **7** |
| **1** | **34** |
| 2 | 34 |
| 3 | 9 |
| 4 | 3 |
| 5 | 2 |
| 6 | 4 |
| 7 | 2 |
| 20 | 1 |

**41 of 96 teams cannot supply two same-team officers. 432 members sit on
them.** The seven with none: `Administration-Office/It Team 5`, `Lifetime`,
`Bus Ops Safety`, `Bus Ops Logistics`, `E&M Special Projects`, `Special
Projects-Recruiting`, `Special Projects-Social Media`.

Worst member-to-officer ratios:

| Team | Members | Officers | Ratio |
| --- | ---: | ---: | ---: |
| `Bus Ops Team A` | 82 | 3 | 27.3 |
| `Reed Road-Early Bird Team 2` | 23 | 1 | 23.0 |
| `Reed Road Parking Team E` | 22 | 1 | 22.0 |
| `Reed Road-Early Bird Team 1` | 19 | 1 | 19.0 |
| `Emlr Team B` | 36 | 2 | 18.0 |

**Decision: assignment stays same-team.** Members on teams that cannot cover
them are not silently dropped — they land in an explicit **"No officer on this
team"** bucket on the Assign screen and are counted on the Committee Dashboard,
so leadership sees the gap as a number rather than discovering it in March.

Two consequences for the Assign screen, both non-negotiable:

- **It must be bulk.** One officer covering 27 people cannot be built one
  dropdown at a time, and `max_input_vars` is 1000 — an 85-person team posting
  three officer slots each is 255 inputs plus overhead, which is survivable
  only because it is chunked. Design for select-many-then-assign-one-officer,
  not per-member selects.
- **The outlier is `Administration-Support`** — 20 "officers" over 15 members,
  because it is where 8 Lifetime Vice Presidents, 3 Past Chairmen and 5
  Ambassadors are parked. Under the §3 title map only the 5 Ambassadors and 1
  Coordinator are assignable. The title map is what makes this team sane.

---

## 8. Finding: the workload is real

Percentage of the committee outstanding on each metric:

| Metric | `N` | `Y` | Outstanding |
| --- | ---: | ---: | ---: |
| Committee Dues | 1272 | 682 | **65.1%** |
| Background Check | 1246 | 708 | **63.8%** |
| Indemnity | 994 | 960 | 50.9% |
| HLSR Dues | 976 | 978 | 49.9% |

By division, outstanding Committee Dues: Bus Ops **479**, Satellites **474**,
Logistics 273. Bus Ops and Satellites are roughly twice as far behind as
Logistics on every metric.

Design consequences:

- A "show me everyone outstanding" view returns **~1,200 rows**. It must
  paginate or window — rendering 1,200 stacked mobile cards is not a page.
- The default filter is **outstanding-only**, not everyone. The 682 who have
  paid are not the working set.
- Sort order matters more than filtering: never-contacted first, then oldest
  contact, so the top of the list is always the next call to make.

---

## 9. Open items

| # | Question | Assumed for v1 |
| --- | --- | --- |
| **OI-1** | Should Senior Officers scope to their area rather than their whole division? | Division, as specified. `area` exists for grouping only. |
| **OI-2** | Are `Coordinator` and `Ambassador` Senior Officers or Officers? The spec lists both in both. | Senior Officer. 12 people affected. |
| **OI-3** | Should harassment training become a fifth scored metric? | No. Imported and shown, excluded from scoring. |
| **OI-4** | What is the retention rule for a member flagged absent by a complete roster? | Flagged, never auto-deleted. Admin confirms a purge as a separate logged action. |
| **OI-5** | Do the 72 division-less members belong somewhere, or is blank correct? | Blank is a real bucket. Surfaced to Admins, warned on import. |
| **OI-6** | Is `Badge Pickup Person` (972 values) operationally useful? | Imported, not surfaced. |
| **OI-7** | Does a "team roster" import name its team in the file, or is it chosen in the UI? | Chosen in the UI and confirmed against the file's contents. |
