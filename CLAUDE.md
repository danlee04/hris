# DTRC HRIS

A Human Resource Information System for a Philippine government hospital.
Phase 1 is complete: the employee master, roles, CSV import and audit trail
(1a), the nine PDS sections (1b), and the CS Form 212 export (1c). Phase 2 —
leave and DTR — has no spec yet.

- Laravel 13 on PHP 8.3, Livewire 4, Flux UI, Tailwind v4, Fortify, MySQL 8
- Roles through `spatie/laravel-permission`: `admin`, `hr`, `employee`
- Local URL: `hris.test` (Laragon), database `hris_db`
- Sibling projects at `C:\laragon\www`: `ipcr-system-laravel` (performance
  management) and `hr_training_system` (legacy raw PHP). The organisational
  schema here is copied from IPCR and must stay column-identical to it;
  `employees.employee_number` is the stable key across both.

## Working agreement

**Never run `git commit` or `git push`.** Dan makes every commit himself.

**Hand him a commit message every third finished piece of work, not every one,
and always exactly one.** One `git add -A` and one subject line covering all
three — a line naming what was edited, no `feat:`/`fix:` prefixes, no metaphors.
Do not split it into one commit per piece even when the three are unrelated; he
consolidates on purpose. Say when a piece is done; just do not end every one
with a commit block.

**English only** in code, comments, commit messages, and every string a user
sees. The conversation is in Taglish; the codebase is not.

**Dan asks for the code to be written for him on this project**, but he still
wants the reasoning for each decision, not just the result.

## Before saying a piece of work is done

```
php artisan test        # all of it, not the file you touched
npm run build           # whenever a Blade view, CSS or JS changed
vendor/bin/pint --dirty
```

Report the real numbers. A test suite that was not run is not a passing suite.

## Security — read this before writing code that touches input

Every item below is a standing requirement on this project, not a suggestion.
This database holds the heaviest concentration of personal information in the
hospital: TIN, SSS, GSIS, PhilHealth, home addresses, dates of birth, and the
answers to PDS items 34–40, which include administrative and criminal cases. It
falls under the Data Privacy Act (RA 10173).

**Authorization**
- Call `$this->authorize()` / `Gate::authorize()` in **every** action that
  touches user-owned data — including Livewire `mount()` **and** every save.
  `mount()` runs once; public properties are rehydrated from the browser on
  every later request, so authorising only on mount protects the first page
  view and nothing after it.
- Ownership belongs in a policy, never in a permission. A permission cannot see
  *which* record is being asked for, and that is how IDOR gets in.
- A record id that travels to the browser comes back as whatever the person on
  the other end wants it to be. Scope every lookup by owner, and **refuse**
  rather than silently skip — a silent skip looks to the user like a save.

**Mass assignment**
- Declare fillable explicitly. This codebase uses Laravel 13's
  `#[Fillable([...])]` attribute; `getFillable()` still reads it. Never
  `$guarded = []`.
- Validate first, then pass only the validated array to `create()`/`update()`.
  Never `$request->all()`.

**XSS**
- `{{ }}` always. `{!! !!}` only for content explicitly sanitised first.
- Never render raw request input in Blade or in a JS template.

**SQL injection**
- Eloquent and the query builder, with bindings. If `whereRaw()` or `DB::raw()`
  is unavoidable, bind: `whereRaw('name = ?', [$name])`. Never concatenate.

**CSRF**
- Laravel's `web` middleware group covers every Livewire request; there is
  nothing to build. Do not add routes to the CSRF exception list. If an
  external webhook ever needs one, verify its signature instead.

**File uploads**
- Validate with `mimes:`/`mimetypes:`, not the extension alone.
- Store outside the public root (`storage/app/private`, which is where Livewire
  already puts temporary uploads) and rename on write.

**Rate limiting**
- `throttle` on login and on password reset. Fortify ships this; do not remove it.

**Environment and deployment**
- `APP_DEBUG=false` in production, always. `php artisan config:cache` so `.env`
  is not read at runtime.
- `.env` stays out of version control. Rotate `APP_KEY` if it ever leaks.
- HTTPS even on the LAN, self-signed if necessary. Over plain HTTP every
  password and every PDS crosses the switch in clear text; "it's only local" is
  not a reason to skip it. **`APP_FORCE_HTTPS=true` is the one switch**: it
  forces the scheme on every generated URL and marks the session cookie
  `Secure`. Leave it off in development — Laragon serves over http, and a
  Secure cookie there is never sent back, which looks exactly like being unable
  to log in.
- Security headers ship in `App\Http\Middleware\SecurityHeaders`, appended to
  every response. See the CSP note below before adding to them.
- `composer audit` before a release. Clean as of 2026-09-07.
- **Back up before anything else.** `php artisan hris:backup` writes one dated
  archive holding the database and the uploaded files. `storage/backups` is
  gitignored, and it has to stay that way: one archive is every PDS in the
  hospital, including the answers to items 34-40, and once committed it is in
  the history for good.

**The CSP has no `script-src`, and that is deliberate**

Livewire ships Alpine, and Alpine evaluates its directives at runtime. The only
script policy this application could actually run under is one carrying
`unsafe-eval` and `unsafe-inline` — which permits exactly what a script policy
exists to forbid, while reading on an audit as though scripts were locked down.
A header claiming a protection it does not provide is worse than its absence,
because it stops anyone looking again.

What is set instead needs no nonce and is not weakened by inline code:
`frame-ancestors 'none'`, `base-uri 'self'`, `form-action 'self'`,
`object-src 'none'`, plus `X-Frame-Options`, `X-Content-Type-Options`,
`Referrer-Policy: same-origin` and a `Permissions-Policy` that turns off the
camera, microphone and location. HSTS is sent only when the request is already
secure; over http every browser ignores it.

Closing the script side properly means threading a nonce through every Livewire
and Flux response. That is its own piece of work, not a line in this file.

**Audit**
- `spatie/laravel-activitylog` records writes on `Employee`. Reads are recorded
  by `App\Services\AuditRecorder` — reading someone else's record is the more
  common abuse, and activitylog cannot see it. In v5 the before/after values
  live in `attribute_changes`, **not** in `properties`.

**Not applicable here, deliberately:** Sanctum, Passport, API tokens and SPA
stateful domains. This system has no API and no SPA; adding one would mean
revisiting this section, not copying an answer into it.

## Conventions

**Livewire 4 single-file components.** Page components live at
`resources/views/pages/<dir>/⚡<name>.blade.php`, are referenced as
`pages::<dir>.<name>`, and are routed with `Route::livewire()`. Class-based
components in `app/Livewire` are for shared concerns, not pages.

**`view` is not available as an action name on an SFC page.** The Livewire 4
compiler appends its own `protected function view($data = [])` to every
generated page class, which silently overrides one of yours — a `wire:click` at
it fails with "Public method [view] not found on component". Name it `open()`.
The same goes for `render`, and for `mount`, `boot`, `hydrate*`, `updated*` and
the rest of the lifecycle hooks, which Livewire refuses to call from the
browser.

**In a namespaced class, `Flux::` is not the Flux facade.** Page components sit
in the root namespace so a bare `Flux::modal()` resolves; inside
`App\Livewire\Concerns` it looks for `App\Livewire\Concerns\Flux`. Write
`\Flux\Flux::modal()`.

**Repeating rows bind `wire:key` to a stable row key, never the array index.**
With an index key, deleting a row in the middle makes the rows below it render
each other's content — the page still looks plausible, which is what makes it
hard to see.

**Flux UI for every control.** `flux:input`, `flux:select`, `flux:button`,
`flux:table`. Do not hand-roll form controls.

**Columns hold their own type.** `date_of_birth` is a date, `height_m` is a
decimal. The legacy system stored `'sss'` in a height column and a 2026 date of
birth because those columns were `varchar`.

## Where things live

| | |
|---|---|
| `app/Services/` | The decisions. `EmployeeImport\*` (parse, validate, write), `AuditRecorder` |
| `app/Enums/` | The vocabulary. `EmploymentStatus`, `OrgPost` |
| `app/Policies/` | Every ownership question |
| `docs/superpowers/specs/` | The design this is built from |
| `docs/superpowers/plans/` | Task-by-task implementation plans |
| `php artisan employees:import <path>` | Bulk employee load. The only import path; the screen was removed |
| `php artisan hris:create-account --role=admin\|hr` | The first way in, and the only way to make an HR account. Registration is closed and no seeder makes one |
| `php artisan leave:open-balances <path> --as-of=` | HR's leave card into the ledger. Header-driven: `employee_number` then one column per ledger. All-or-nothing |
| `php artisan hris:backup` | Database and uploads into one dated zip. Needs `BACKUP_MYSQLDUMP` on Windows — Laragon keeps `mysqldump` off the PATH |

**Spell it `organization`, with a z.** `OrganizationSeeder` set the precedent and
the routes, views and tests follow it. User-facing text says "Organization" too;
one spelling, not two.

**Divisions, sections and positions are never deleted, only deactivated.** The
`employees` foreign keys null on delete, so removing a position would silently
blank it on everyone holding it. `is_active` exists on all three for this.

## The Excel templates

`storage/app/templates/` holds three files. Two are CS Form 6: the original
DTRC form, and `CS Form No. 6 (Application for Leave) DTRC linked.xlsx`, which
is the one the exporter reads.

**Never open the linked copy in Excel.** Its 25 checkboxes carry `<x:FmlaLink>`
cell links injected into `xl/drawings/vmlDrawing1.vml`; saving from Excel drops
them, and the form silently stops being tickable. Edit the original, then:

```
php artisan form6:link
```

It rebuilds the linked copy and prints every link beside the label it sits next
to. Read that table — a link beside the wrong label ticks the wrong box, and
nothing downstream would say so. The linked copy is generated, not maintained.

**Hold the `Spreadsheet` in a variable.**
`IOFactory::load($path)->getSheet(0)` returns a worksheet whose parent is
immediately garbage-collected, and every `getCell()` on it then returns `NULL` —
including cells that plainly hold values. It reads like a damaged file and is
not one.

**Several CS Form 6 captions are RichText**, not strings: `A10`, `E10`, `A69`.
Writing a plain string to them replaces the underlined blanks with flat text.

**The forms export as `.xlsx`, not PDF, and that is a decision.** PhpSpreadsheet's
PDF writers render cell values and styles only; the 25 checkboxes are form
controls, not cells, and every tick would vanish — the type of leave would print
blank on a signed document. A correct PDF needs LibreOffice on the server
(`soffice --headless --convert-to pdf`), which the LAN box does not have. Revisit
that when it does, not before.

## The certificate of employment

`employees/{employee}/certificate` — HR only, and deliberately not the employee
themselves. The paper carries the HR officer's name under it, so letting a
person produce their own hands out a signature; the data being theirs does not
change what the document claims.

**The peso figure is typed at print time and never stored.** Salaries move with
every tranche of the CSC schedule, and an amount kept on the employee record
would quietly print last year's figure on a document going to a bank. The salary
grade comes off the plantilla item and is printed alongside, because that is the
part the system can vouch for.

**It refuses a separated employee.** The system holds `is_active`, not the date
they left, so the certificate could say when they started and never when they
stopped. That paper is a Service Record, which this system does not issue yet.

The letterhead lives in `config/hospital.php`. **`address` and `contact` are
blank** and the header omits the line rather than printing a placeholder — fill
them in before the first certificate leaves the building.

## The CSV import

**The import screen was removed**, along with its Livewire page, route, sidebar
item and tests. Its button never reached the server in Dan's browser — no log
entry, no error, nothing written — while the same code passed every test and
every other Livewire form on the machine worked. It was never diagnosed. Adding
an employee is now a form; a roster still arrives through the CLI.

**`php artisan employees:import <path>` stays**, and with it `EmployeeCsvParser`,
`EmployeeImporter` and `ReferenceData`. That command is what loaded the real 134
employees, and typing thirty-five job orders by hand is not an improvement on
running it once.
