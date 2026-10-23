# RCF Requests User Guide

## How it works

A roster change is requested once, approved up a chain, and printed once on
the Division Chairman's Roster Change Form (RCF). Nobody retypes it.

1. A Vice Chairman submits a request on **Create RCF Requests**.
2. It goes to their approver, then up the chain to the top.
3. The person at the top turns approved requests into RCFs on **Export
   Approved Requests to RCF Forms**, then downloads and sends them.
4. **Track RCFs** shows every form and every line, and the HLSR import
   that fulfilled it.

An Administrator draws the chain on **Create Approval Flow**. No request is
ever routed to a default. Nothing here edits the roster; the next HLSR
import is the answer.

```
Vice Chairman ──submits──► Division Vice Chairman ──approves──► Division Chairman
      ▲                          │                                        │
      └── returned with a note ──┘                       everything approved, 25 lines a form
                                                                          │
Division Chairman ──sends──► Rodeo Houston ──fulfils──► Roster import  ◄──┘
Export to RCF Forms                                     "In the roster"
```

## Vice Chairman: requesting a change

**Menu → RCFs → Create RCF Requests**

1. Check the card at the top. It names your approver. If it says *Nobody is
   set as your approver yet*, ask an Administrator before you continue.
2. Choose the sub-committee and press **Load this sub-committee**.
3. Fill one row per change. Existing member: enter the HLS&R number and the
   name fills in. New member: enter both.
4. Press **Submit these requests**.

**Your requests**, below the grid, shows where each one stands:

| Status | Meaning | You |
| --- | --- | --- |
| Waiting for approval | With the named approver | **Withdraw** if no longer wanted |
| Approved — awaiting a form | Fully approved, not yet printed | Wait |
| On a form | Sent to HLSR on form #N | Wait |
| In the roster | HLSR's import recorded the change | Done |
| Returned | Declined, with a note | Fix it and submit a new request |
| Withdrawn | You took it back | — |

## Division Vice Chairman: approving

**Menu → RCFs → Approve RCF Requests**

1. **Waiting for you** lists requests oldest first, all ticked. Untick any
   you are not deciding now.
2. **If approved** on each row says where it goes next: *then to [name]*, or
   *then ready for you to generate* if you are the last approver.
3. Press **Approve the ticked requests**, or type a note and press **Return
   the ticked requests with this note**. A return needs a note.

**Decided by you** shows your last fifty decisions and where each stands
now.

- You cannot edit a request. Return it with a note instead.
- Your own requests go to your approver, like anyone else's.
- Nobody is emailed. Check the **Waiting for you** count when you sign in.

## Division Chairman: top of the chain

### Approve

Same screen as above. Every row says *then ready for you to generate*. Your
own requests are approved the moment you submit them.

### Export to forms

**Menu → RCFs → Export Approved Requests to RCF Forms**

1. Pick **Division** (one form per division, the default) or **Team**, then
   **Regroup**.
2. Review the forms as they will print, 25 lines each. Untick a line to
   hold it back.
3. Press **Generate N forms from the ticked lines**. The forms are kept
   under your name, dated today.

### Download, number, send

One form opens on its own page. Several are listed under **Forms you have
generated**, each with a **Download** button. On the form's page:

- **Download the form**
- Enter the **RCF number** and **Save the tracking**
- Press **To the Division Chairman today** and **To Rosters today** when it
  goes
- **In the roster** fills in by itself from the next import

### Void a test run

On the form's page, open **Void this form**, give a reason, and confirm. The
form and its requests disappear for everyone; nothing is deleted. Only a
consolidated form can be voided, by the DC who generated it or an
Administrator.

## Track RCFs: everyone

**Menu → RCFs → Track RCFs**

- **Find a member on any form** by name or HLS&R number.
- **RCF requests not yet on a form**, and whom each is waiting on.
- **Every change requested**, line by line, with the import that fulfilled
  it. Opens on the unfulfilled lines.
- **Your RCFs**, and for Executive Officers **Everyone else's RCFs**.

Open any form to download it again or update its tracking.

## Administrator

### Create Approval Flow

**Menu → Administer → Create Approval Flow.** One row per account at Senior
Officer and above. Press **Change**, pick one, **Save**:

- **Approved by [name]**: their requests wait on that account.
- **Nobody — top of the chain**: their requests are approved on submission,
  and they generate the forms.
- **Not set**: their requests are refused.

A typical division: VC → DVC → DC, with the DC set to top of the chain. The
DC's own row must be set, or they cannot submit. A loop is refused.

### Sign In As

**Menu → Administer → Sign In As.** Run the app as another account to walk
the chain end to end. Press **Back to** in the bar to return. Everything is
audited as you. Void any form you generate this way.

### Upload RCFs

From the upload card on **Track RCFs**, upload `.xls` or `.xlsx` forms that
arrived by email. They are tracked like every other form.

## Quick reference

| Role | Request | Approve | Export to forms | Track |
| --- | --- | --- | --- | --- |
| Captain | No (use Create Forms) | No | No | Own forms |
| Vice Chairman | Yes | If drawn as an approver | If top of the chain | Own |
| Division Vice Chairman | Yes | Yes | If top of the chain | Own |
| Division Chairman | Yes, auto-approved | Yes, the last word | Yes | All |
| Administrator | Yes | As drawn | Yes, and void any | All, plus upload |

Who approves whom is set on Create Approval Flow, not by title.

**Words on the screen**

- **Request**: one member, one change; one line of a future RCF.
- **Top of the chain**: the account nobody approves. They generate the forms.
- **Return** / **Withdraw**: ends a request. Both stay on the record.
- **Generate**: keeps the forms. Download from the form's page.
- **In the roster**: HLSR's import recorded the change. The only green.
- **Void**: hides a consolidated test form from everyone. Nothing is deleted.

**Codes on the form**

| Type | Meaning |
| --- | --- |
| A | Addition |
| R | Remove |
| T | Title Change |
| S | Sub-Committee (Team) Change |
| S & T | Team Change and Title Change |

Remove reasons: 1) Deceased Member, 2) Did Not Meet Requirements,
3) Leadership Recommendation, 4) Member Resigned, 5) No Response to
Communications, 6) No Show for All Assignments.
