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

**Never run `git commit` or `git push`.** Dan makes every commit himself. When a
piece of work is finished, hand him one commit message covering it — a subject
line naming what was edited, no `feat:`/`fix:` prefixes, no metaphors.

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
  not a reason to skip it.
- Secure and HttpOnly cookies. Security headers (CSP, X-Frame-Options) before
  the system carries real records.
- `composer audit` before a release.

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
| `php artisan employees:import <path>` | Bulk employee load; same parser and importer as the screen |
| `php artisan hris:create-admin` | The first way in on a fresh install. Registration is closed and no seeder makes an admin |

**Spell it `organization`, with a z.** `OrganizationSeeder` set the precedent and
the routes, views and tests follow it. User-facing text says "Organization" too;
one spelling, not two.

**Divisions, sections and positions are never deleted, only deactivated.** The
`employees` foreign keys null on delete, so removing a position would silently
blank it on everyone holding it. `is_active` exists on all three for this.

## Known open problem

**The Import button does nothing in Dan's browser.** The click never reaches the
server: no log entry, no error, nothing written. The same code passes every test
and the CLI command works. Unresolved. Every PDS section is a Livewire form of
the same shape, so Phase 1b Task 1 is deliberately a single vertical slice —
prove one Save in a browser before building eight more.
