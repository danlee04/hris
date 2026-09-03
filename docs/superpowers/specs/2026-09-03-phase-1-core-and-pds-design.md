# HRIS — Phase 1: Core and Personal Data Sheet

Date: 2026-09-03
Status: Approved for planning

## Context

The DTRC's HR office runs on two systems today, neither of which holds a
complete employee record.

- `ipcr-system-laravel` — Laravel 13 / PHP 8.3, Blade with Alpine and Tailwind
  v4, `spatie/laravel-permission`. It carries a well-formed organizational
  schema (`positions`, `designations`, `divisions`, `sections`, `employees`,
  `employee_designations`) and the Performance Management pillar.
- `hr_training_system` — raw PHP, no framework. It holds ~130 real employee
  rows and reference data for divisions, sections, eligibilities and employment
  statuses, plus training and LDNA records. Its `employee_pds` and
  `employee_education` tables contain only test rows, on a flattened schema
  that covers less than half of the CSC form.

Neither system can produce a Personal Data Sheet. HR maintains 201 files on
paper, and every PDS is retyped by hand in Excel.

## Problem

Phase 1 delivers the missing foundation: a single employee record that an
employee maintains themselves, and a Personal Data Sheet that exports to the
official CSC form without retyping.

## Roadmap

The HRIS is scoped around two frameworks that are often confused, and both are
in play:

- **CSC PRIME-HRM four core HR systems** — Recruitment/Selection/Placement,
  Learning and Development, Performance Management, Rewards and Recognition.
  This is the Civil Service Commission's accreditation framework. It measures
  HR _processes_, not software.
- **Day-to-day HR operations** — 201 records, attendance, leave. None of these
  appear in PRIME-HRM, and the HR office cannot function without them.

The project serves both, operations first:

| Phase | Delivers                                               |
| ----- | ------------------------------------------------------ |
| **1** | Core, employee master, PDS, CSC export — **this spec** |
| 2     | Leave and DTR                                          |
| 3     | Recruitment, Selection and Placement                   |
| 4     | Rewards and Recognition (PRAISE)                       |

Performance Management stays in `ipcr-system-laravel`. Learning and Development
stays in `hr_training_system` until a later phase replaces it.

## Stack

Created with `laravel new hris` using the Livewire starter kit:

|                         |                                                      |
| ----------------------- | ---------------------------------------------------- |
| Laravel 13.17 / PHP 8.3 | Matches `ipcr-system-laravel`                        |
| Livewire 4.1            | Repeating rows and forms                             |
| Laravel Fortify         | Authentication (registration, login, password reset) |
| Flux                    | UI component library shipped with the starter kit    |
| Tailwind v4             | Matches `ipcr-system-laravel`                        |
| PHPUnit                 | Matches `ipcr-system-laravel`                        |
| MySQL 8                 | Database driver for session, cache and queue         |

To be added:

| Package                      | For                                       |
| ---------------------------- | ----------------------------------------- |
| `spatie/laravel-permission`  | Roles and permissions                     |
| `spatie/laravel-activitylog` | Change and access audit trail             |
| `phpoffice/phpspreadsheet`   | Filling the official CSC `.xlsx` template |

Deliberately excluded: queue workers, Horizon, Redis, a REST API,
multi-tenancy. At 100–500 users on a LAN, `.xlsx` generation runs synchronously
in one to two seconds.

## Relationship to `ipcr-system-laravel`

The HRIS is a **separate application with a separate database**. It copies the
organizational foundation from IPCR rather than sharing it.

The cost of that choice is two employee masters that will drift apart. The
mitigation is fixed in the schema now, while both are empty:

- The copied tables keep **identical column names and types**. Not similar —
  identical.
- `employees.employee_number` is the **stable key across both systems**.

A future reconciliation is then a straight column mapping rather than a
rewrite. This is cheap today and expensive to retrofit.

**The HRIS is the system of record for employee and organizational data.**

### Copied without modification

`positions`, `designations`, `divisions`, `sections`, `employees`,
`employee_designations`, the `spatie/laravel-permission` tables, the
`EmploymentStatus` and `OrgPost` enums, the `Employee` and organizational
models, and `OrgDeletionGuard`.

These are settled, tested and working. Do not redesign them.

### One added column

`employees.biometric_id` — nullable, unique. Needed by the Phase 2 DTR import.
Added now, while the table is empty.

## Users and roles

`User` and `Employee` are separate records. `employees.user_id` is nullable and
unique: an employee record exists before a login is issued, which is what makes
the CSV import possible before anyone can sign in.

| Role       | Can                                                                                                                                      |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `employee` | View and edit **their own** PDS. Export their own PDS.                                                                                   |
| `hr`       | View all employees and all PDS records. Edit the employee master. Correct any PDS. Import employees. Export any PDS. Read the audit log. |
| `admin`    | Everything in `hr`, plus user creation and deactivation, role assignment, organizational structure, and system settings.                 |

HR may edit another employee's PDS. This is required — some employees will not
maintain it, and errors need correcting. The audit trail is what makes it safe,
and it replaces the approval gate that this design deliberately omits.

### Permissions

Roles are bundles of permissions; code checks permissions, never role names.

```
employees.view    employees.manage   employees.import
pds.view.any      pds.edit.any       pds.export.any
org.manage        users.manage       roles.manage       audit.view
```

There is deliberately **no `pds.view.own` permission**. Ownership is a question
about a specific record, so it belongs in a policy:

```php
// PdsPolicy::view()
return $employee->user_id === $user->id || $user->can('pds.view.any');
```

A permission named `.own` cannot see which record is being requested, which is
precisely how IDOR gets in.

**Every PDS and Employee route passes through a policy, without exception.**
Livewire components call `$this->authorize()` in `mount()`. Reading another
employee's PDS by changing an ID in the URL is the most obvious attack on this
system; it must be closed by a policy and covered by a test.

### Roles added in later phases

`supervisor` (leave approval, Phase 2), `hrmpsb` (selection board, Phase 3),
`praise_committee` (Phase 4). Each is a new role with new permissions — no
structural change.

## Data model: the Personal Data Sheet

CS Form No. 212, Revised 2017. Eleven tables, each hanging directly off
`employee_id`. There is no container table: there is exactly one PDS per
employee, so a container would add a join to every query and buy nothing.

### One-to-one with employee

| Table                      | CSC items                                                                                                                                                                                                         |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pds_personal_information` | 1–16 — names, birth, sex, civil status, height, weight, blood type, GSIS, PAG-IBIG, PhilHealth, SSS, PhilSys, TIN, agency employee number, citizenship, residential and permanent address, contact details, photo |
| `pds_family_background`    | 17, 19, 20 — spouse, father, mother (maiden name)                                                                                                                                                                 |
| `pds_declarations`         | 34–40, each a boolean plus a details field; 42 government ID; date accomplished                                                                                                                                   |

### One-to-many

| Table                       | CSC item | Notes                                                                                                                 |
| --------------------------- | -------- | --------------------------------------------------------------------------------------------------------------------- |
| `pds_children`              | 18       | Name and date of birth                                                                                                |
| `pds_educations`            | 21–26    | `level` enum: Elementary, Secondary, Vocational, College, Graduate                                                    |
| `pds_eligibilities`         | 27       | Eligibility, rating, exam date and place, license number and validity                                                 |
| `pds_work_experiences`      | 28       | Inclusive dates, position, agency, monthly salary, salary grade and step, appointment status, government service flag |
| `pds_voluntary_works`       | 29       | Organization, dates, hours, nature of work                                                                            |
| `pds_learning_developments` | 30       | `type` enum: Managerial, Supervisory, Technical, Foundation                                                           |
| `pds_other_entries`         | 31–33    | `kind` enum: skill_hobby, distinction, membership                                                                     |
| `pds_references`            | 41       | Three entries                                                                                                         |

### Three decisions worth recording

**Items 31, 32 and 33 share one table.** All three are an ordered list of
single-line text. Three identical tables would mean three copies of the same
Livewire component, validation rules and exporter branch. One table with a
`kind` enum means one reusable component.

**`pds_educations` is one-to-many, not five fixed rows.** Employees hold two
degrees or two master's programs, and the CSC form allows it. The legacy system
inserted exactly five blank rows per employee, which is why that table is full
of empty records.

**Every one-to-many table carries `sort_order`.** Rows print in the sequence
the employee arranged them, not in insertion order.

## PDS editing

Nine Livewire components, one per section of the form, each with its own save:

```
app/Livewire/Pds/
  PersonalInformation    FamilyBackground       Education
  Eligibility            WorkExperience         VoluntaryWork
  LearningDevelopment    OtherInformation       Declarations
```

`Declarations` covers page 4 in full — items 34–40, the three references (41)
and the government ID (42).

The PDS has roughly 150 fields. A single form means one validation failure at
the bottom returns the whole page, and something gets lost.

**Repeating rows must use `wire:key` bound to the record ID, never the array
index.** With an index key, deleting a middle row makes the remaining rows
render each other's content. This is the most common bug in Livewire repeaters
and among the hardest to trace.

**Explicit save per section, no autosave.** The PDS is a legal document, signed
under penalty of perjury. Autosave records unfinished thinking as fact. Each
section gets a Save button and a warning when navigating away dirty.

**A completeness checklist on the employee dashboard.** There is no approval
gate, so nothing else tells an employee their PDS is incomplete. The checklist
shows which of the nine sections are done.

**Validation runs on save, not per keystroke.** Across 150 fields, live
validation is noise and extra requests.

## CSC export

```
storage/app/templates/CS_Form_212_revised2017.xlsx   official CSC file
config/pds_template.php                              field to cell mapping
app/Services/Pds/PdsExporter.php                     orchestration
```

**The entire cell mapping lives in one config file.**

```php
'personal_information' => [
    'surname'       => 'C10',
    'first_name'    => 'C11',
    'date_of_birth' => 'C13',
],
```

Scattering `setCellValue('C10', ...)` through the exporter turns every CSC
revision into a codebase-wide search. In one config file it is a single file to
open, readable side by side with the printed form.

**Overflow produces a continuation sheet.** The template has a fixed number of
rows per section. When an employee has more entries than fit, the exporter adds
a worksheet titled for that section and marked `(Continuation)`. The CSC accepts
continuation sheets, and agencies already do this on paper. Inserting rows into
the template instead would shift every cell reference below the insertion point
and break the mapping.

**The photograph is embedded** at 4.5cm × 3.5cm. **Signature and thumbmark are
left blank** — the form is printed and signed by hand. This is standard agency
practice, and it avoids the question of what a stored signature means legally.

Filename: `PDS_DELACRUZ_JUAN_2026-09-03.xlsx`. Generated synchronously and
streamed to the browser.

## Employee import

A three-step CSV import for `admin`: upload, then **preview**, then write.

The preview shows what the importer read and every error it found, by row —
unknown division on row 14, duplicate employee number on row 22. **No import
writes directly.** A wrong column mapping running silently across 500 rows is
not recoverable.

The import creates `Employee` records only. **Account creation is separate**:
an admin chooses who gets a login and sets a temporary password that must be
changed at first sign-in. Many employees will not need an account immediately.

This is a permanent tool, not a one-time script. New hires, retirements and
reorganizations all come through it.

## Security and privacy

This database becomes the heaviest concentration of personal information in the
hospital: TIN, SSS, GSIS, PhilHealth, PhilSys, home address, date of birth,
spouse and children's names, and the answers to items 34–40, which include
administrative and criminal cases. It falls under the **Data Privacy Act
(RA 10173)**.

- **HTTPS on the LAN**, even with a self-signed certificate. Over plain HTTP
  every password and every PDS crosses the network switch in clear text. "It is
  only local" is not a reason to skip this.
- **A policy on every route.** `pds.view.any` belongs to `hr` and `admin` only.
- **Log access, not only changes.** Record when an HR user opens someone else's
  PDS. Edits are rare; reading is not, and reading is the more common abuse.
- **Encrypted, off-machine backups.** A `mysqldump` sitting on the same server
  is not a backup.
- **No column-level encryption** of TIN or GSIS. The application holds the key,
  so an attacker who reaches the application reaches the data anyway; the only
  thing actually lost is the ability to search and index. It is not worth it.

CSRF protection is automatic — Laravel's `web` middleware group covers every
Livewire request. Nothing to build.

## Deployment

|                       |                                                                                                                |
| --------------------- | -------------------------------------------------------------------------------------------------------------- |
| Development           | Laragon, `hris.test`, MySQL 8                                                                                  |
| Production            | LAN server: nginx or Apache, PHP-FPM 8.3, MySQL 8, self-signed HTTPS                                           |
| Session, cache, queue | Database driver throughout                                                                                     |
| Deploy                | `git pull` → `composer install --no-dev -o` → `php artisan migrate` → `npm run build` → `php artisan optimize` |
| Backup                | Nightly `mysqldump`, encrypted, copied off the machine                                                         |

## Testing

Three things earn tests. The rest does not.

- **The exporter.** Given a fully populated PDS, assert that specific cells hold
  specific values. Nothing else can catch a wrong cell reference on a page full
  of text.
- **Authorization.** Employee A cannot reach employee B's PDS by changing the
  URL. One test per policy method.
- **Per-section save.** Each section saves its own fields and leaves the others
  untouched.

Exhaustive per-field validation tests are not worth their maintenance cost.

## Definition of done

1. An employee signs in, sees their own PDS, completes all nine sections, and
   sees the completeness checklist.
2. An employee downloads their PDS as a CSC `.xlsx` with correct values in the
   correct cells, with a continuation sheet when entries overflow.
3. HR can view and correct any employee's PDS, and every change and every read
   is recorded.
4. An admin imports employees from CSV, with a preview before anything is
   written.
5. Employee A cannot reach employee B's PDS — proven by a test.
6. `php artisan test` passes in full and `npm run build` succeeds.

## Not in Phase 1

Leave, DTR, recruitment, rewards and recognition, and any integration with
`ipcr-system-laravel` or `hr_training_system`. Each gets its own spec.

## Open items

- **The official CSC template file** must be obtained from the CSC website and
  placed at `storage/app/templates/`. The cell mapping cannot be written until
  the exact file in use is in hand.
- **Row capacity per section** in that template determines when a continuation
  sheet is triggered. To be read off the file during implementation.
