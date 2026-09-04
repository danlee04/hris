# HRIS — Phase 2a: Leave

## Context

Phase 1 delivered the employee master, the three roles, the audit trail, the
nine PDS sections and the CS Form 212 export. The organisational chart —
divisions, sections, positions, and the head of each — is maintained on screen
and holds real data: 134 employees across four divisions.

Phase 2 was scoped in the Phase 1 spec as "Leave and DTR". Those are two
subsystems, not one. Leave is an application, an approval chain and a credit
ledger. DTR is reading an Excel export from a biometric device and counting
hours. They meet at exactly one point: an approved leave explains an absence in
the DTR. This spec covers **leave only**. DTR is Phase 2b and gets its own
spec, written after this one ships.

Leave is first because it carries a signed form, a chain of approvers and a
balance the hospital argues about. The DTR is still assembled by hand today and
can wait; a leave application cannot be filed twice against the same credits
without someone losing days.

## Problem

Leave at DTRC is paper. An application is typed, printed, and walked from desk
to desk. The credit balance lives in a spreadsheet the HR office maintains, and
the number written on the form is copied from it by hand. Three things go wrong:

- **The balance is a copy.** Two applications filed the same week are both
  checked against the same figure, and the second one overdraws.
- **The chain is invisible.** Nobody can say where a form is without walking to
  the next desk.
- **The accrual is manual.** 1.25 vacation and 1.25 sick credits per employee
  per month, 97 employees, computed in a spreadsheet.

## Scope

**In:** leave types and their rules, the credit ledger, the application, the
derived approval chain, leave without pay, the monthly accrual posting, and the
CS Form 6 export on DTRC's own letterhead.

**Out and deliberately so:**

- **DTR, biometric imports and holidays.** Phase 2b.
- **Supporting document uploads** (medical certificates, birth certificates).
  Uploads bring the whole file-upload section of CLAUDE.md with them —
  `mimes:`/`mimetypes:` validation, storage outside the public root, renaming on
  write. That is its own piece of work and it is not what makes leave usable.
- **Terminal leave and monetisation.** These are computed once, when a person
  leaves, and they need rules this spec has not established.
- **The effect of leave without pay on future accrual.** Under the Omnibus
  Rules, extended LWOP reduces credits earned. It is rare at DTRC and belongs
  with terminal leave.
- **Notifications by email.** There is no mail server on the hospital LAN. The
  approval queue is the notification.

## The approval chain

The chain depends on where the applicant sits in the organisational chart. The
HR office described four cases:

| Applicant             | Route                                              |
| --------------------- | -------------------------------------------------- |
| Staff                 | Section head → HR → Division head → Chief          |
| Section head          | HR → Division head → Chief                         |
| Division head         | HR → Chief                                         |
| Chief of Hospital     | HR                                                 |

These are one rule, not four. The full route is
`[SECTION_HEAD, HR, DIVISION_HEAD, CHIEF]`, and every step the applicant
themselves would sign is removed. Nobody recommends their own leave.

`App\Services\Leave\LeaveRoute` builds the list. It reads
`sections.section_head_employee_id`, `divisions.division_head_employee_id` and
`employees.is_chief_of_hospital` — all three already exist and are maintained on
the Organization screens. There is no separate table of approvers to drift out
of date.

### A missing signatory refuses the filing

If an applicant's section has no head recorded, the application **cannot be
filed**, and the message names what is missing: "No section head is recorded for
the Statistics Unit. Ask HR to set one before filing." It is not skipped.

Skipping a step nobody filled would produce an application that reached the
Chief without a recommendation and looked complete on the way. That is the
silent skip CLAUDE.md forbids, and it is worse here than elsewhere because the
output is a signed document.

### The approver is frozen at filing

`leave_approvals` rows are written when the application is filed, each naming
the employee who holds that step *at that moment*. If the section head changes
next week, the person who signed last week is still the person recorded as
having signed. An approver resolved at display time would rewrite history every
time the chart changed.

### What an approver can do

| Action         | Effect                                                                      |
| -------------- | --------------------------------------------------------------------------- |
| **Approve**    | Records the action and advances to the next step. The last step approves the application. |
| **Disapprove** | Ends the application. A reason is required. Held credits are released.       |
| **Return**     | Sends it back to the applicant to correct. Held credits are released. Resubmitting starts the chain again from the first step. |

Returning restarts the chain rather than resuming it because the corrected
application is a different one: dates may have moved, and a recommendation given
for one set of dates does not carry to another.

The applicant may **cancel** their own application while it is still pending and
**nobody has acted on it**. Once a step has approved, withdrawing is a decision
for the person who signed, not for the applicant, and it happens through
Disapprove with a remark. Cancelling releases the held credits.

## Leave types

Every leave type carries its own rules, not just a number of days. A type is a
row, so the next policy the hospital adopts is a row too.

| Column                    | Meaning                                                                 |
| ------------------------- | ----------------------------------------------------------------------- |
| `code`, `name`            | `VL`, "Vacation Leave"                                                  |
| `ledger`                  | Which balance it draws on: `vacation`, `sick`, `spl`, `solo_parent`, `wellness`, or null for uncredited types |
| `accrual_days_per_month`  | 1.25 for VL and SL; null for everything else                            |
| `grant_days_per_year`     | 3 for SPL, 7 for Solo Parent, 5 for Wellness; null where there is no annual grant |
| `notice_days`             | Days of advance notice required. 5 for Wellness; null where none         |
| `max_consecutive_days`    | 3 for Wellness; null where unlimited                                     |
| `applies_to`              | The employment statuses that may file it                                |
| `is_active`               | Retired rather than deleted, as everywhere else in this codebase        |

Seeded types:

| Type                          | Ledger        | Applies to                          | Notes                          |
| ----------------------------- | ------------- | ----------------------------------- | ------------------------------ |
| Vacation Leave                | `vacation`    | permanent, coterminous              | 1.25/month                     |
| Sick Leave                    | `sick`        | permanent, coterminous              | 1.25/month                     |
| Mandatory/Forced Leave        | `vacation`    | permanent, coterminous              | Draws on the vacation balance  |
| Special Privilege Leave       | `spl`         | permanent, coterminous              | 3 days granted yearly          |
| Solo Parent Leave             | `solo_parent` | permanent, coterminous              | 7 days granted yearly          |
| Maternity Leave               | none          | permanent, coterminous              | 105 days, RA 11210             |
| Paternity Leave               | none          | permanent, coterminous              | 7 days, RA 8187                |
| Study Leave                   | none          | permanent, coterminous              |                                |
| 10-Day VAWC Leave             | none          | permanent, coterminous              | RA 9262                        |
| Rehabilitation Privilege      | none          | permanent, coterminous              |                                |
| Special Leave Benefits for Women | none       | permanent, coterminous              | RA 9710                        |
| Special Emergency (Calamity)  | none          | permanent, coterminous              |                                |
| Adoption Leave                | none          | permanent, coterminous              | RA 8552                        |
| **Wellness Leave**            | `wellness`    | job_order, contract_of_service      | 5 days yearly, 5 days' notice, 3 consecutive maximum |

The first thirteen are the thirteen printed on the form, in the order they
appear on it. VAWC, Special Leave Benefits for Women and Adoption Leave were
missing from the first draft of this spec and were found by reading the actual
template — which is the argument for reading the file rather than the issuances.

Wellness Leave has no box of its own on CS Form 6; it prints on the **Others:**
line, which is where a leave the hospital grants and the CSC does not belongs.

Wellness Leave is DTRC's own, not the Omnibus Rules'. Job order and contract of
service staff earn no statutory leave credits; the hospital grants them this.
It is the reason leave types are a table: a rule set that exists in one hospital
and nowhere in the CSC issuances cannot live in an enum.

Types with no ledger are applied for and approved like any other, and consume no
balance.

## The ledger

One table, `leave_ledger_entries`, append-only. A balance is
`SUM(days) WHERE employee_id = ? AND ledger = ?`. There is no balance column
anywhere, because a stored balance and its entries eventually disagree and
nothing says which one is right.

| `kind`       | Sign     | Written when                                            |
| ------------ | -------- | ------------------------------------------------------- |
| `opening`    | positive | HR enters the balance carried in from the spreadsheet   |
| `accrual`    | positive | HR posts a month                                        |
| `grant`      | positive | The yearly SPL / Solo Parent / Wellness grant           |
| `hold`       | negative | An application is filed                                 |
| `release`    | positive | It is disapproved, returned, cancelled — or approved    |
| `commit`     | negative | It is finally approved                                  |
| `adjustment` | either   | HR corrects something, with a reason                    |

**Holds are what make the paid/unpaid split right.** An employee with 10
vacation credits who files three eight-day applications on the same morning:
the first is fully paid and holds 8; the second sees a balance of 2 and is split
2 paid, 6 unpaid; the third is unpaid entirely. Without holds all three would be
measured against 10, all three would print as fully paid, and the hospital would
pay 24 days out of a 10-day balance. Nothing would show it until someone
recomputed the ledger by hand.

Note that the split is what changes, not whether the application is accepted.
Insufficient credits never refuse a filing — see *Leave without pay* below.

**Final approval writes both a release and a commit.** The net is the same as
converting the hold, and the trail reads in order: held on filing, released and
committed on approval. A hold silently rewritten into a commit would leave a row
whose meaning changed after the fact.

Every entry carries `leave_application_id` where one applies, `created_by`, and a
description. The ledger is the answer to "where did my credits go", and it has
to answer without interpretation.

## Leave without pay

An application for more days than the balance covers is not refused. It is
split: `days_with_pay` up to the available balance, `days_without_pay` for the
rest. Only the paid portion touches the ledger.

This is what happens on paper, and payroll needs the unpaid figure. Refusing the
application would push leave without pay back out of the system, which is the
one place it must not be, because it is the part that changes someone's pay.

The split is computed at filing against the balance at that moment and stored on
the application. It is recomputed if the application is returned and resubmitted.

## Counting days

`days` is a decimal supporting halves, because CSC leave is filed in half days.

The system proposes a count — the weekdays between the two dates — and **the
applicant and HR can both change it**. It is a proposal, not a computation.

There is no holidays table in this phase. Hospital staff work shifts; the true
number of working days for a nurse on a rotating roster is not Monday to Friday,
and the only thing that knows their actual schedule is the DTR, which is Phase
2b. Computing a confident wrong number and printing it on a signed form is worse
than proposing one and letting the two people who know correct it.

## Screens

| Screen                 | Who      | What                                                                       |
| ---------------------- | -------- | -------------------------------------------------------------------------- |
| **My leave**           | everyone | Balances per ledger, the list of their own applications and their state, Apply in a modal, and Cancel on one nobody has acted on yet |
| **Approvals**          | anyone holding a step | The applications waiting on this person right now, with Approve / Disapprove / Return |
| **Leave ledger**       | HR       | One employee's entries, the opening balance, and adjustments with a reason  |
| **Post monthly credits** | HR     | Preview of who will receive credits for a month, then write                 |
| **Leave types**        | admin    | The table above, edited in a modal, retired rather than deleted             |

Applications are downloadable as CS Form 6 from the application itself.

The **Approvals** screen is one screen for all four kinds of approver. Which
applications appear is a query — the applications whose current step names you —
not four different pages.

**Posting a month is idempotent.** Pressing it twice posts nothing the second
time, because the entries are keyed on employee, ledger and period. It is a
button rather than a scheduled job: the LAN server has no guaranteed cron, and a
scheduler that silently fails to run produces a month of missing credits that
nobody notices until somebody files.

## Authorization

Two new permissions:

- `leave.manage` — HR: the ledger, adjustments, posting credits. Granted to
  `hr` and `admin`.
- `leave.types.manage` — the leave type table. Granted to `admin`, alongside
  `org.manage`.

Filing leave needs no permission: every employee with a linked record may file
their own.

**Approving is a policy, not a permission.** `LeaveApplicationPolicy::act()`
asks whether this user is the frozen approver of this application's *current*
step. A permission cannot see which application is being asked about, and that
is exactly how one section head would end up approving another division's leave.

Everything CLAUDE.md requires applies unchanged: `authorize()` in `mount()` and
again in every save; ownership in the policy; validated arrays only into
`create()` and `update()`; `#[Fillable]` on every model.

Leave records are personal information under RA 10173 — a sick leave says
something about a person's health. Reading somebody else's application is
recorded through `AuditRecorder`, the same as reading their PDS. Writes are
recorded by activitylog.

## CS Form 6 export

The output is **CS Form No. 6, Revised 2020, on DTRC's own letterhead** —
document code HRM-07, Revision 1, effective 3 June 2026. It is in
`storage/app/templates/`. Note the revision: the PDS is the 2026 form, this is
the 2020 one, and its list of leave types is not the same.

The Phase 1 export engine is reused as it stands: `TemplateMap`, `CellWriter`,
`SectionWriter` and the config-file cell map. `App\Services\Leave\Form6Exporter`
fills the template the way `PdsExporter` does — the template is loaded, written
to in memory, and saved elsewhere. It is never saved over itself.

### The checkboxes had to be linked first

The template carries 25 checkboxes and **not one `<x:FmlaLink>`**. In the PDS,
every checkbox names a cell, and ticking one is writing `TRUE` to that cell.
Here there was no cell behind any of them, and PhpSpreadsheet can neither create
a form control nor tick one.

A generated copy,
`CS Form No. 6 (Application for Leave) DTRC linked.xlsx`, gives each checkbox a
linked cell in column **R** (the thirteen leave types, at the row of their label)
or column **T** (the details, the commutation and the recommendation). Both
columns sit outside the print area, `$A$2:$J$69`. The original file is kept
beside it, untouched, as the thing to fall back to.

### What the form holds that the first draft of this spec did not

- **6.D Commutation** — Requested / Not Requested. A field on the application.
- **6.B Details of leave**, which are per-type and not one free-text box:
  within the Philippines / abroad for vacation and SPL; in hospital / out
  patient with the illness named for sick leave; the illness for Special Leave
  Benefits for Women; master's completion, board review or another purpose for
  study leave; and monetisation and terminal leave as their own boxes.
- **7.A Certification of leave credits** prints only vacation and sick: total
  earned, less this application, balance, "as of" a date. The ledger answers all
  four.
- **7.C Approved for** prints `___ days with pay` and `___ days without pay`,
  which is the paid/unpaid split this spec already carries, in the hospital's
  own words.

### Three signatures on a four-step chain

The form has three signature areas: the HR certification (7.A), the Division
Head's recommendation (7.B) and the Chief's approval (7.C). **The section head
has no box.** They initial by hand beside the Division Head's signature, without
a printed name.

So the section head's step is real, is recorded, and is visible on screen and in
the audit trail, and the exporter prints nothing for it. The step is not removed
just because the paper has no room for it — it is the recommendation the
hospital actually requires.

Names and dates of the printed approvers are filled. The signature spaces are
left blank; the form is printed and signed by hand, the same decision the PDS
export made.

The template also ships with leftover sample values — `C49` holds `1` and `C52`
a date serial. The exporter writes over both; a test asserts they are gone.

## Data model

```
leave_types
  id, code (unique), name, ledger (nullable), accrual_days_per_month (nullable),
  grant_days_per_year (nullable), notice_days (nullable),
  max_consecutive_days (nullable), applies_to (json), is_active, timestamps

leave_ledger_entries
  id, employee_id, ledger, kind, days (decimal 6,2 signed),
  effective_date, period (nullable, 'YYYY-MM' for accrual idempotency),
  leave_application_id (nullable), description, created_by_user_id, timestamps
  unique (employee_id, ledger, kind, period) where period is not null
  index (employee_id, ledger)

leave_applications
  id, employee_id, leave_type_id, date_from, date_to,
  days (decimal 5,2), days_with_pay (decimal 5,2), days_without_pay (decimal 5,2),
  details (json), commutation (enum: requested, not_requested),
  status, current_step (nullable), filed_at, decided_at (nullable),
  timestamps, softDeletes
  index (employee_id, status)

leave_approvals
  id, leave_application_id, sequence, step, approver_employee_id,
  action (nullable), remarks (nullable), acted_by_user_id (nullable),
  acted_at (nullable), timestamps
  unique (leave_application_id, sequence)
```

`status`: `pending`, `approved`, `disapproved`, `returned`, `cancelled`.
`step`: `section_head`, `hr`, `division_head`, `chief`.

## Services

| Class                                     | Responsibility                                                  |
| ----------------------------------------- | ---------------------------------------------------------------- |
| `App\Services\Leave\LeaveRoute`           | The ordered steps for an applicant, and the employee holding each |
| `App\Services\Leave\LeaveBalance`         | Balances per ledger; what an application may draw                |
| `App\Services\Leave\LeaveLedger`          | Writes entries. The only writer. hold / release / commit / accrue / grant / adjust |
| `App\Services\Leave\LeaveFiler`           | Validates a filing against its type's rules, splits paid and unpaid, writes the application, its approvals and its holds — in one transaction |
| `App\Services\Leave\LeaveDecision`        | Applies an approver's action: advances, ends, or returns, and moves the ledger |
| `App\Services\Leave\AccrualPosting`       | Previews and posts a month, idempotently                         |
| `App\Services\Leave\Form6Exporter`        | Fills the DTRC CS Form 6 template                                |

`LeaveLedger` being the only writer is the point. Balances stay correct because
there is one place that can change them, and it is tested.

## Testing

Following the Phase 1 spec's rule that three things earn tests:

- **The route.** All four applicant kinds, and the refusal when a head is
  missing. This is where a wrong answer sends a document to the wrong person.
- **The ledger.** The overdraw that holds prevent; release on disapprove,
  return and cancel; that a released application restores exactly what it held;
  that posting a month twice writes once.
- **Authorization.** The wrong approver, the right approver at the wrong step,
  one employee reaching another's application, and the re-authorisation on
  every save.
- **The exporter.** Specific cells hold specific values, once the template is
  in hand.

## Definition of done

1. An employee sees their balances, files an application, and watches it move
   through the chain.
2. Two applications that together exceed the balance are both accepted, and the
   second one's excess days are recorded as leave without pay.
3. Each of the four approver kinds sees only what waits on them, and can
   approve, disapprove and return.
4. HR posts a month's credits, sees who received them, and pressing it again
   changes nothing.
5. HR enters opening balances and can correct one with a reason that stays in
   the ledger.
6. An approved application downloads as CS Form 6 on the hospital's letterhead.
7. `php artisan test` passes in full and `npm run build` succeeds.

## Implementation shape

This spec is larger than one plan. It breaks into three, each of which produces
working software on its own:

| Plan   | Delivers                                                                  |
| ------ | ------------------------------------------------------------------------- |
| **2a-1** | Leave types, the ledger, balances, opening entries, the accrual posting screen. HR can see and correct a balance before anything can spend one. |
| **2a-2** | The application, `LeaveRoute`, the approval chain, holds and the paid/unpaid split, My leave and Approvals. |
| **2a-3** | The CS Form 6 export. Blocked until the DTRC template file is in hand.   |

The order matters: a ledger that cannot be read is a ledger nobody will trust
with an application, and an application that cannot be approved cannot be
printed.

## Open items

- **The linked template needs one human check.** Open
  `CS Form No. 6 (Application for Leave) DTRC linked.xlsx` in Excel, tick a few
  boxes, and confirm `TRUE` appears in the matching cell in column R or T. The
  mapping was derived from the drawing anchors and verified against the label
  beside each one, but only Excel can say whether Excel agrees.
- **Opening balances** for all 134 employees. Until they are entered, every
  balance reads zero and every application is leave without pay. Bulk entry is
  worth a CLI command in the shape of `employees:import` if HR has the figures
  in a spreadsheet.
- **Whether Mandatory/Forced Leave needs its own five-day tracking** separate
  from the vacation balance it draws on. Confirm with HR.
- **Whether the yearly grants reset or accumulate.** SPL is use-it-or-lose-it
  under the Omnibus Rules. Wellness Leave is DTRC's own and the hospital decides.
