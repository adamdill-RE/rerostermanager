# RCF Requests User Guide

How a roster change is asked for, approved and tracked in Rodeo Express
Roster Management (RERM). This chapter covers the four screens under
**RCFs** on the menu and the Administrator's screens behind them.

## How it works

A roster change is asked for once, approved up a chain, and printed once on
the Division Chairman's Roster Change Form (RCF). Nobody retypes it.

1. A **Vice Chairman** asks for one change at a time on **Create RCF Requests**.
2. Each request waits on the requester's **approver**, then on that
   approver's approver, until it reaches the **top of the chain**.
3. The person at the top uses **Export Approved Requests to RCF Forms** to
   put everything they approved onto one or more RCFs, then downloads and
   sends them.
4. **Track RCFs** shows every form and every line, and the roster import in
   which Rodeo Houston (HLSR) fulfilled it.

The chain itself is drawn by an Administrator on **Create Approval Flow**.
Nothing is routed to a default: an officer whose approver is not set is told
so and cannot submit.

Nothing here ever edits the roster directly. A request is a request, and the
next roster import from HLSR is the answer.

```
Vice Chairman ──submits──► Division Vice Chairman ──approves──► Division Chairman
Create RCF Requests        Approve RCF Requests                 Final approval: top of the chain
      ▲                          │                                        │
      └── returned with a note ──┘                    everything approved, 25 lines a form
                                                                          │
Division Chairman ──sends──► Rodeo Houston ──fulfils──► Roster import  ◄──┘
Export to RCF Forms, download   Numbered form processed   Request shows "In the roster"
```

## Vice Chairman (VC): asking for a change

You ask for one change at a time, and the screen tells you where each
request has got to.

**Screen:** Menu → RCFs → **Create RCF Requests**

1. Read the card at the top, *Where your requests go*. It names your
   approver. If it says *Nobody is set as your approver yet*, stop and ask an
   Administrator to set it on Create Approval Flow. Nothing you type can be
   submitted until then.
2. Choose the **sub-committee** the changes are about and press **Load this
   sub-committee**. It is written on every request, because the finished
   form is headed with it.
3. Fill one row per change in the RCF grid. The columns are the paper form's
   own: Type, RE rookie, HLS&R no., Member name, Change/add title, Previous
   title, Wait list, Remove reason, New sub-committee, Interview or sponsor.
   - **Existing member:** type or pick their **HLS&R number** and the roster
     fills in their name. You can also type their name exactly as the list
     shows it and the number fills in.
   - **New member not on the roster yet:** type both the number and the name.
   - Blank rows are ignored. Open *What the codes mean* at the foot of the
     page for the Type codes and the six Remove reasons.
4. Press **Submit these requests**. Every filled row becomes one request,
   logged under your name.

**Your requests** below the grid lists everything you have asked for, newest
first, with a status chip and a one-line trail of every hand it has passed
through.

| Chip | What it means | What you do |
| --- | --- | --- |
| Waiting for approval | Waiting on the named approver | Nothing, or **Withdraw** it if you no longer want it |
| Approved — awaiting a form | Every approver has said yes; not yet on a form | Nothing |
| On a form | On form #N, not yet in the roster | Nothing; HLSR has it |
| In the roster | HLSR's import recorded the change | Done |
| Returned | An approver said no, with a note | Read the note, fix it, submit a new request |
| Withdrawn | You took it back | Nothing |

A returned or withdrawn request stays on your list as the record of what was
asked. You cannot edit it in place; submit a corrected one.

## Division Vice Chairman (DVC): approving in the middle of the chain

You decide the requests that are routed to you and pass them up. You see
only the requests the flow sends you.

**Screen:** Menu → RCFs → **Approve RCF Requests**. The tab *Waiting for
you* carries a count.

1. **Waiting for you** lists the requests, oldest first. Every row starts
   ticked. Untick any you are not deciding today.
2. Read each row: who asked, the sub-committee, the member, the change (was
   → becomes), and **If approved**, which says where it goes next. *then to
   Carl Chair* means you are a middle approver. *then ready for you to
   generate* means you are the last word.
3. Press **Approve the ticked requests** to send them on. Or type a note and
   press **Return the ticked requests with this note**. A return without a
   note is refused, because the officer who asked reads it.

**Decided by you** below shows the last fifty you approved or returned, with
where each is now and the full trail, so you can see when a request you
passed up became a form and when HLSR fulfilled it.

Three things to know:

- You cannot edit a line before approving it. If the title or team is wrong,
  return it with a note and the VC resubmits.
- You can also submit requests of your own on **Create RCF Requests**. They
  go to *your* approver, as the flow says.
- Nobody is emailed. Check the *Waiting for you* count when you sign in.

## Division Chairman (DC): top of the chain

You give the final approval and turn everything approved into the numbered
RCF that goes to Rodeo Houston. Three screens, in order.

### 1. Approve

**Approve RCF Requests** works exactly as it does for a DVC (above). Every
row waiting on you says *then ready for you to generate*, because you are
the last word. Approve or return with a note.

Your own requests on **Create RCF Requests** are approved the moment you
submit them, and appear straight on the next screen.

### 2. Export to forms

**Screen:** Menu → RCFs → **Export Approved Requests to RCF Forms**. The tab
*Ready to generate* carries a count.

1. Choose how to cut them under *One form per*: **Division** (the default:
   one form headed with the division, whichever teams the lines came from)
   or **Team** (one form per team). Press **Regroup** to see the cut change.
2. Read the forms as they will print. Each heading says *Form 1 of 2* and
   the sub-committee that goes at the top. A form holds 25 lines, so a
   bigger group becomes two forms. Untick a line to leave it for a later
   form.
3. Press **Generate N forms from the ticked lines**. The forms are kept
   under your name as submitter, dated today, for the active show year.
   Every request taken is marked *On a form*.

### 3. Download, number, send

One form generated lands on its own page. Several land under **Forms you
have generated**, each with a **Download** button.

On the form's own page:

- **Download the form.** A filled-in form is personal data, so it is a
  button rather than a link, and each download is logged.
- Type your **RCF number** on the every-line row, or per line, and **Save
  the tracking**.
- Press **To the Division Chairman today** and **To Rosters today** when the
  form goes. Each dates every line not yet dated.
- **In the roster** is never typed. It fills itself in from the next roster
  import.

### Voiding a test run

If a consolidated form was a test and never sent, open its page and use the
**Void this form** fold at the bottom. A note is required. The form and its
requests leave every screen for everybody in the chain; nothing is deleted,
and the Audit Log still names it. Only the DC who generated it or an
Administrator can void, and only a consolidated form. The requests do not
come back; ask again through the grid if the change is still wanted.

## Tracking RCFs: every level

"Was an RCF ever submitted for this member, and where did it stop" is one
screen.

**Screen:** Menu → RCFs → **Track RCFs**

- **Find a member on any form.** Type a name or HLS&R number. Every form
  line naming them is listed, with where it got to.
- **RCF requests not yet on a form.** Every pending or approved request you
  may see, and whom it is waiting on.
- **Every change requested.** Line by line: RCF date, member, was, becomes,
  RCF number, and the import in which HLSR fulfilled it. It opens on the
  lines not yet fulfilled, which is the list to chase.
- **Your RCFs.** Forms you made or generated, newest first, with how many
  lines have gone to the DC, to Rosters, and into the roster.
- **Everyone else's RCFs.** Executive Officers (Chairman, Vice President,
  Division Chairmen) see every form in the committee.

Open any form to see its lines, download it again, and update its tracking.
A member's own card (from View My Roster) also lists every RCF line about
them.

Who sees a request: the person who asked, the account it is waiting on,
anyone who acted on it, the final approver, and Executive Officers. Who sees
a form: whoever made it, and Executive Officers. Forms arriving by email are
uploaded by an Administrator (below) and tracked here beside the rest.

## Administrator: drawing the chain and keeping the record

An Administrator draws who approves whom, and can walk the whole chain alone
to check it.

### Create Approval Flow

**Screen:** Menu → Administer → **Create Approval Flow**. Every active
account at Senior Officer and above has one row, with its placement, who
approves it, and the chain in names (→ Bob → Carl).

1. Press **Change** on a row and pick one of three states, then **Save**.
   - **Approved by** somebody: their requests wait on that account.
   - **Nobody — top of the chain**: their requests are approved on
     submission, and they generate the forms.
   - **Not set**: their requests are refused with a sentence naming this
     screen.
2. Draw the final approver's own row. Being somebody's approver places
   nobody: a Division Chairman the VCs route to still needs their own row
   set to **top of the chain**, or they can approve everyone's requests and
   submit none. The screen flags this row: *approves N — set to top of the
   chain?*
3. A save that would make a loop is refused by name and writes nothing.

A typical division: each VC → approved by their DVC; each DVC → approved by
the DC; the DC → top of the chain. An Admin with no row is treated as top of
the chain.

### Sign In As

**Screen:** Menu → Administer → **Sign In As**. Pick any active non-Admin
account and the application runs as them: their menu, their requests, their
approvals. Use it to walk a request VC → DVC → DC and generate a form, then
**Back to** your own login from the bar. Every step is audited as you,
acting as them. A form generated this way is a test run: void it from its
page afterwards.

### Upload RCFs

Forms that arrived by email, made by hand rather than here, are added to the
record from the upload card on **Track RCFs**. Choose one or more `.xls` or
`.xlsx` files; each is read by its labels, shown back with anything it had
to reshape, and kept with the DC's number from the *CHANGE FORM #* box.
Duplicates of a kept form are refused. From then on they are tracked line by
line like every other form.

### Voiding

An Administrator can void any consolidated form, not only their own, from
the form's page. Forms made on Create Forms or uploaded are never voidable:
they went out, and the record stands.

## Quick reference

| Role | Create RCF Requests | Approve RCF Requests | Export Approved Requests to RCF Forms | Track RCFs | Create Approval Flow |
| --- | --- | --- | --- | --- | --- |
| Captain / Assistant Captain | No (use Create Forms) | No | No | Own forms | No |
| Vice Chairman | Yes | Only if drawn as someone's approver | Only if top of the chain | Own forms and requests | No |
| Division Vice Chairman | Yes | Yes, what is routed to them | Only if top of the chain | Own forms and requests | No |
| Division Chairman | Yes, auto-approved when top of the chain | Yes, the last word | Yes | Every form | No |
| Chairman / Vice President | Yes | As drawn | As drawn | Every form | No |
| Administrator | Yes | As drawn | Yes, and void any | Every form, plus upload | Yes, plus Sign In As |

Who actually approves whom is decided by the Approval Flow an Administrator
draws, not by title. A VC drawn as a peer's approver approves them; a DC
whose own row is not set cannot submit.

**Words on the screen**

- **Request** is one line of a future RCF: one member, one change.
- **Approver** is the account that signs off your requests. **Top of the
  chain** is the account nobody approves, who generates the forms.
- **Return** ends a request with a note. **Withdraw** is the requester
  taking it back. Both stay on the record.
- **Generate** keeps the forms; it does not download. Download from the
  form's page or the list.
- **In the roster** means HLSR's import recorded the change. It is the only
  green a request gets.
- **Void** hides a consolidated test form and its requests from everybody.
  Nothing is ever deleted.

**Codes on the form**

| Type | Meaning |
| --- | --- |
| A | Addition |
| R | Remove |
| T | Title Change |
| S | Sub-Committee Change (Team Change) |
| S & T | Sub-Committee Change and Title Change |

Remove reasons: 1) Deceased Member, 2) Did Not Meet Requirements,
3) Leadership Recommendation (contact Division Chairman), 4) Member Resigned,
5) No Response to Communications, 6) No Show for All Assignments.
