# HRIS Phase 1b — Personal Data Sheet Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an employee maintain their own CSC Personal Data Sheet, and let HR read and correct anyone's, with every change and every read recorded.

**Architecture:** Eleven tables hanging off `employee_id`, no container. Nine Livewire single-file sections, one per part of the CSC form, each with its own save. Repeating rows are synchronised by id through one shared writer, which is also the single place that stops a tampered row id from reaching another employee's record.

**Tech Stack:** Laravel 13.17, PHP 8.3, Livewire 4.1 (single-file components), Flux UI, Tailwind v4, MySQL 8, PHPUnit 12.5 on in-memory SQLite.

**Spec:** `docs/superpowers/specs/2026-09-03-phase-1-core-and-pds-design.md`

**Builds on:** `docs/superpowers/plans/2026-09-03-phase-1a-foundation.md` (complete)

## Global Constraints

- **English only** in code, comments, commit messages, and every string a user sees.
- **Never run `git commit` or `git push`.** Each task ends with a commit message for the author to run.
- **Livewire 4 single-file components.** Pages live at `resources/views/pages/<dir>/⚡<name>.blade.php`, are referenced as `pages::<dir>.<name>`, and are routed with `Route::livewire()`.
- **Models declare fillable with Laravel 13's `#[Fillable([...])]` attribute**, not `protected $fillable`. `getFillable()` still reads it.
- **Flux UI for every control.** Do not hand-roll inputs.
- **Every route and every Livewire `mount()` touching a PDS passes through `PdsPolicy`.** No exceptions.
- **Repeating rows use `wire:key` bound to a stable row key, never the array index.**
- Tests are PHPUnit classes, methods named `test_snake_case`, using `RefreshDatabase`.
- Run `vendor/bin/pint --dirty` before each commit, and `npm run build` whenever a Blade file changed.

## Why Task 1 is a vertical slice

Phase 1a ended with one unresolved problem: **the Import button does nothing in the author's browser.** The click never reaches the server — no log entry, no error, no row written. The same code passes every test, and the CLI path works.

Every section in this phase is a Livewire form with a Save button, the same shape as that button and as the starter kit's own `⚡profile.blade.php`. If `wire:submit` does not work on these machines, this entire phase is unusable.

So Task 1 delivers **one complete section that saves**, end to end. Open it in a browser and press Save before writing Task 2. If it saves, the pattern is sound and the import bug is something specific to that page. If it does not, stop — the problem is Livewire in the browser, and nothing further in this plan is worth building until it is found.

---

## File Structure

**Migrations** — eleven tables, all keyed on `employee_id`:

```
pds_personal_information   pds_family_background   pds_declarations      (one-to-one)
pds_children               pds_educations          pds_eligibilities
pds_work_experiences       pds_voluntary_works     pds_learning_developments
pds_other_entries          pds_references                                (one-to-many)
```

**Enums** — the vocabulary:
- `app/Enums/CivilStatus.php`
- `app/Enums/Sex.php`
- `app/Enums/EducationLevel.php`
- `app/Enums/LearningDevelopmentType.php`
- `app/Enums/OtherEntryKind.php`

**Models** — one per table, in `app/Models/Pds/`.

**The decisions:**
- `app/Services/Pds/RowWriter.php` — synchronises a repeating section by id, and refuses a row id belonging to anyone else
- `app/Services/Pds/PdsCompleteness.php` — which of the nine sections are done

**Authorization:**
- `app/Policies/PdsPolicy.php`

**Shared Livewire behaviour:**
- `app/Livewire/Concerns/EditsPdsSection.php` — resolves the employee, authorises, and holds the save/redirect shape
- `app/Livewire/Concerns/ManagesRepeatingRows.php` — add row, remove row, stable keys

**Pages:**
```
resources/views/pages/pds/⚡personal-information.blade.php
resources/views/pages/pds/⚡family-background.blade.php
resources/views/pages/pds/⚡education.blade.php
resources/views/pages/pds/⚡eligibility.blade.php
resources/views/pages/pds/⚡work-experience.blade.php
resources/views/pages/pds/⚡voluntary-work.blade.php
resources/views/pages/pds/⚡learning-development.blade.php
resources/views/pages/pds/⚡other-information.blade.php
resources/views/pages/pds/⚡declarations.blade.php
```

**Shared markup:**
- `resources/views/components/pds/section-nav.blade.php` — the nine tabs, each showing whether it is complete
- `resources/views/components/pds/repeater.blade.php` — the add/remove frame around a set of rows

**Routes:** `routes/pds.php`, required from `routes/web.php`.

The sections are separate files rather than one long form because the PDS has roughly 150 fields. One form means a single validation failure at the bottom returns the whole page, and something gets lost.

---

## Task 1: One section, end to end

**Files:**
- Create: `app/Enums/{Sex,CivilStatus}.php`
- Create: `database/migrations/*_create_pds_personal_information_table.php`
- Create: `app/Models/Pds/PersonalInformation.php`
- Create: `database/factories/Pds/PersonalInformationFactory.php`
- Create: `app/Policies/PdsPolicy.php`
- Create: `app/Livewire/Concerns/EditsPdsSection.php`
- Create: `resources/views/pages/pds/⚡personal-information.blade.php`
- Create: `routes/pds.php`
- Modify: `routes/web.php`, `app/Models/Employee.php`, `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/Pds/PdsAuthorizationTest.php`
- Test: `tests/Feature/Pds/PersonalInformationTest.php`

**Interfaces:**
- Consumes: `Employee`, `User::employee()`, the roles from Phase 1a.
- Produces:
  - `Employee::personalInformation(): HasOne`
  - `PdsPolicy` with `view(User, Employee): bool`, `update(User, Employee): bool`
  - `EditsPdsSection` trait exposing `public ?int $employeeId`, `resolveEmployee(): Employee`, `bootSection(?int $employeeId): Employee` and `authoriseSave(): Employee`. It holds no `Employee` property — the model is resolved per request, because a property surviving between requests is a property the browser can rewrite.
  - Named route `pds.personal-information` at `/pds/personal-information`, accepting an optional `?employee=` for HR.

- [ ] **Step 1: Write the failing authorization test**

Create `tests/Feature/Pds/PdsAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature\Pds;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function employeeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Employee::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_an_employee_opens_their_own_pds(): void
    {
        $this->actingAs($this->employeeUser())
            ->get(route('pds.personal-information'))
            ->assertOk();
    }

    public function test_an_employee_cannot_open_another_pds_by_changing_the_url(): void
    {
        // The whole reason this phase has a policy. What is on the other side
        // is a home address, a TIN, and the answers to items 34 to 40.
        $someoneElse = Employee::factory()->create();

        $this->actingAs($this->employeeUser())
            ->get(route('pds.personal-information', ['employee' => $someoneElse->id]))
            ->assertForbidden();
    }

    public function test_a_user_with_no_employee_record_is_refused(): void
    {
        // Every account has a role; not every account is linked to an employee.
        $user = $this->userWithRole('employee');

        $this->actingAs($user)
            ->get(route('pds.personal-information'))
            ->assertForbidden();
    }

    public function test_hr_opens_any_pds(): void
    {
        $someoneElse = Employee::factory()->create();

        $this->actingAs($this->userWithRole('hr'))
            ->get(route('pds.personal-information', ['employee' => $someoneElse->id]))
            ->assertOk();
    }

    public function test_hr_without_an_employee_id_gets_their_own_or_a_refusal(): void
    {
        // An HR user who is also an employee sees their own; one who is not
        // linked to any employee record has nothing to show.
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('pds.personal-information'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('pds.personal-information'))->assertRedirect(route('login'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=PdsAuthorizationTest`
Expected: FAIL with `Route [pds.personal-information] not defined.`

- [ ] **Step 3: Write the two enums**

Create `app/Enums/Sex.php`:

```php
<?php

namespace App\Enums;

/** CS Form 212 item 6. The form offers exactly these two. */
enum Sex: string
{
    case Male = 'male';
    case Female = 'female';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

Create `app/Enums/CivilStatus.php`:

```php
<?php

namespace App\Enums;

/** CS Form 212 item 7, in the order the form prints them. */
enum CivilStatus: string
{
    case Single = 'single';
    case Married = 'married';
    case Widowed = 'widowed';
    case Separated = 'separated';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single',
            self::Married => 'Married',
            self::Widowed => 'Widowed',
            self::Separated => 'Separated',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 4: Write the migration**

`php artisan make:migration create_pds_personal_information_table`, then:

```php
/**
 * CS Form 212 items 1-16. One row per employee.
 *
 * Names live on `employees`, not here — the employee master owns them, and a
 * second copy would drift. This table starts at item 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pds_personal_information', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();

            $table->date('date_of_birth')->nullable();                 // 5
            $table->string('place_of_birth')->nullable();              // 6
            $table->string('sex', 10)->nullable();                     // 7
            $table->string('civil_status', 20)->nullable();            // 8
            $table->string('civil_status_other', 50)->nullable();
            $table->decimal('height_m', 3, 2)->nullable();             // 9  metres
            $table->decimal('weight_kg', 5, 2)->nullable();            // 10 kilograms
            $table->string('blood_type', 10)->nullable();              // 11

            $table->string('gsis_id', 40)->nullable();                 // 12
            $table->string('pagibig_id', 40)->nullable();              // 13
            $table->string('philhealth_no', 40)->nullable();           // 14
            $table->string('sss_no', 40)->nullable();                  // 15
            $table->string('tin_no', 40)->nullable();                  // 16
            $table->string('agency_employee_no', 40)->nullable();
            $table->string('philsys_id', 40)->nullable();

            $table->string('citizenship', 30)->nullable();             // Filipino | Dual Citizenship
            $table->string('dual_citizenship_by', 20)->nullable();     // by birth | by naturalization
            $table->string('dual_citizenship_country', 100)->nullable();

            // Residential address, broken out the way the form breaks it out
            $table->string('res_house_no', 60)->nullable();
            $table->string('res_street', 100)->nullable();
            $table->string('res_subdivision', 100)->nullable();
            $table->string('res_barangay', 100)->nullable();
            $table->string('res_city', 100)->nullable();
            $table->string('res_province', 100)->nullable();
            $table->string('res_zip_code', 10)->nullable();

            $table->boolean('permanent_same_as_residential')->default(false);
            $table->string('perm_house_no', 60)->nullable();
            $table->string('perm_street', 100)->nullable();
            $table->string('perm_subdivision', 100)->nullable();
            $table->string('perm_barangay', 100)->nullable();
            $table->string('perm_city', 100)->nullable();
            $table->string('perm_province', 100)->nullable();
            $table->string('perm_zip_code', 10)->nullable();

            $table->string('telephone_no', 40)->nullable();
            $table->string('mobile_no', 40)->nullable();
            $table->string('email_address')->nullable();

            $table->string('photo_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pds_personal_information');
    }
};
```

**Height and weight are decimals, not strings.** The legacy system stored `'1'` and `'sss'` in those columns because they were `varchar`. A column that cannot hold nonsense will not hold nonsense.

- [ ] **Step 5: Write the model**

Create `app/Models/Pds/PersonalInformation.php`:

```php
<?php

namespace App\Models\Pds;

use App\Enums\CivilStatus;
use App\Enums\Sex;
use App\Models\Employee;
use Database\Factories\Pds\PersonalInformationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'date_of_birth', 'place_of_birth', 'sex', 'civil_status', 'civil_status_other',
    'height_m', 'weight_kg', 'blood_type',
    'gsis_id', 'pagibig_id', 'philhealth_no', 'sss_no', 'tin_no',
    'agency_employee_no', 'philsys_id',
    'citizenship', 'dual_citizenship_by', 'dual_citizenship_country',
    'res_house_no', 'res_street', 'res_subdivision', 'res_barangay',
    'res_city', 'res_province', 'res_zip_code',
    'permanent_same_as_residential',
    'perm_house_no', 'perm_street', 'perm_subdivision', 'perm_barangay',
    'perm_city', 'perm_province', 'perm_zip_code',
    'telephone_no', 'mobile_no', 'email_address', 'photo_path',
])]
class PersonalInformation extends Model
{
    /** @use HasFactory<PersonalInformationFactory> */
    use HasFactory;

    protected $table = 'pds_personal_information';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'sex' => Sex::class,
            'civil_status' => CivilStatus::class,
            'height_m' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'permanent_same_as_residential' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
```

Create `database/factories/Pds/PersonalInformationFactory.php`:

```php
<?php

namespace Database\Factories\Pds;

use App\Enums\CivilStatus;
use App\Enums\Sex;
use App\Models\Employee;
use App\Models\Pds\PersonalInformation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PersonalInformation> */
class PersonalInformationFactory extends Factory
{
    protected $model = PersonalInformation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date_of_birth' => $this->faker->date(),
            'place_of_birth' => $this->faker->city(),
            'sex' => Sex::Female->value,
            'civil_status' => CivilStatus::Single->value,
            'height_m' => 1.60,
            'weight_kg' => 55.0,
            'blood_type' => 'O+',
            'citizenship' => 'Filipino',
            'res_city' => $this->faker->city(),
            'mobile_no' => '09171234567',
            'email_address' => $this->faker->safeEmail(),
        ];
    }
}
```

Add the relation to `app/Models/Employee.php`:

```php
use App\Models\Pds\PersonalInformation;
use Illuminate\Database\Eloquent\Relations\HasOne;

    public function personalInformation(): HasOne
    {
        return $this->hasOne(PersonalInformation::class);
    }
```

- [ ] **Step 6: Write the policy**

Create `app/Policies/PdsPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Ownership is a question about a specific record, so it lives here rather
 * than in a permission — a permission cannot see which PDS is being asked for,
 * and that is how IDOR gets in.
 */
class PdsPolicy
{
    public function view(User $user, Employee $employee): bool
    {
        return $this->owns($user, $employee) || $user->can('pds.view.any');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->owns($user, $employee) || $user->can('pds.edit.any');
    }

    public function export(User $user, Employee $employee): bool
    {
        return $this->owns($user, $employee) || $user->can('pds.export.any');
    }

    /**
     * Every imported employee starts with a null user_id. Comparing two nulls
     * would hand the first unlinked employee's PDS to somebody else.
     */
    private function owns(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null && $employee->user_id === $user->id;
    }
}
```

- [ ] **Step 7: Write the shared section trait**

Create `app/Livewire/Concerns/EditsPdsSection.php`:

```php
<?php

namespace App\Livewire\Concerns;

use App\Models\Employee;

/**
 * Every PDS section answers the same two questions before it renders: whose
 * PDS is this, and may this person see it. Nine sections asking it nine
 * different ways is nine chances to get it wrong once.
 */
trait EditsPdsSection
{
    public ?int $employeeId = null;

    public function resolveEmployee(): Employee
    {
        $employee = $this->employeeId !== null
            ? Employee::findOrFail($this->employeeId)
            : auth()->user()->employee;

        abort_if($employee === null, 403, 'This account is not linked to an employee record.');

        return $employee;
    }

    /** Call this first in mount(). */
    protected function bootSection(?int $employeeId): Employee
    {
        $this->employeeId = $employeeId;

        $employee = $this->resolveEmployee();

        $this->authorize('view', $employee);

        // Keep the resolved id, so a later save cannot silently target a
        // different record than the one that was authorised on mount.
        $this->employeeId = $employee->id;

        return $employee;
    }

    /** Call this first in every save. */
    protected function authoriseSave(): Employee
    {
        $employee = $this->resolveEmployee();

        $this->authorize('update', $employee);

        return $employee;
    }
}
```

**`authoriseSave()` is not redundant with `bootSection()`.** `mount()` runs once; every later request rehydrates `$employeeId` from the browser, where it can be changed. Authorising only on mount protects the first page view and nothing after it.

- [ ] **Step 8: Write the section**

Create `resources/views/pages/pds/⚡personal-information.blade.php`:

```blade
<?php

use App\Enums\CivilStatus;
use App\Enums\Sex;
use App\Livewire\Concerns\EditsPdsSection;
use App\Models\Pds\PersonalInformation;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Personal information')] class extends Component {
    use EditsPdsSection;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?int $employee = null): void
    {
        $record = $this->bootSection($employee)->personalInformation;

        $this->form = array_merge(
            array_fill_keys((new PersonalInformation)->getFillable(), null),
            $record?->only((new PersonalInformation)->getFillable()) ?? [],
        );

        $this->form['date_of_birth'] = $record?->date_of_birth?->format('Y-m-d');
        $this->form['sex'] = $record?->sex?->value;
        $this->form['civil_status'] = $record?->civil_status?->value;
    }

    public function save(): void
    {
        $employee = $this->authoriseSave();

        $validated = $this->validate([
            'form.date_of_birth' => ['nullable', 'date', 'before:today'],
            'form.place_of_birth' => ['nullable', 'string', 'max:255'],
            'form.sex' => ['nullable', Rule::enum(Sex::class)],
            'form.civil_status' => ['nullable', Rule::enum(CivilStatus::class)],
            'form.civil_status_other' => ['nullable', 'string', 'max:50'],
            'form.height_m' => ['nullable', 'numeric', 'between:0.5,2.5'],
            'form.weight_kg' => ['nullable', 'numeric', 'between:20,300'],
            'form.blood_type' => ['nullable', 'string', 'max:10'],
            'form.gsis_id' => ['nullable', 'string', 'max:40'],
            'form.pagibig_id' => ['nullable', 'string', 'max:40'],
            'form.philhealth_no' => ['nullable', 'string', 'max:40'],
            'form.sss_no' => ['nullable', 'string', 'max:40'],
            'form.tin_no' => ['nullable', 'string', 'max:40'],
            'form.agency_employee_no' => ['nullable', 'string', 'max:40'],
            'form.philsys_id' => ['nullable', 'string', 'max:40'],
            'form.citizenship' => ['nullable', 'string', 'max:30'],
            'form.dual_citizenship_by' => ['nullable', 'string', 'max:20'],
            'form.dual_citizenship_country' => ['nullable', 'string', 'max:100'],
            'form.res_house_no' => ['nullable', 'string', 'max:60'],
            'form.res_street' => ['nullable', 'string', 'max:100'],
            'form.res_subdivision' => ['nullable', 'string', 'max:100'],
            'form.res_barangay' => ['nullable', 'string', 'max:100'],
            'form.res_city' => ['nullable', 'string', 'max:100'],
            'form.res_province' => ['nullable', 'string', 'max:100'],
            'form.res_zip_code' => ['nullable', 'string', 'max:10'],
            'form.permanent_same_as_residential' => ['boolean'],
            'form.perm_house_no' => ['nullable', 'string', 'max:60'],
            'form.perm_street' => ['nullable', 'string', 'max:100'],
            'form.perm_subdivision' => ['nullable', 'string', 'max:100'],
            'form.perm_barangay' => ['nullable', 'string', 'max:100'],
            'form.perm_city' => ['nullable', 'string', 'max:100'],
            'form.perm_province' => ['nullable', 'string', 'max:100'],
            'form.perm_zip_code' => ['nullable', 'string', 'max:10'],
            'form.telephone_no' => ['nullable', 'string', 'max:40'],
            'form.mobile_no' => ['nullable', 'string', 'max:40'],
            'form.email_address' => ['nullable', 'email', 'max:255'],
        ])['form'];

        unset($validated['employee_id'], $validated['photo_path']);

        if ($validated['permanent_same_as_residential'] ?? false) {
            foreach (['house_no', 'street', 'subdivision', 'barangay', 'city', 'province', 'zip_code'] as $part) {
                $validated["perm_{$part}"] = $validated["res_{$part}"] ?? null;
            }
        }

        PersonalInformation::updateOrCreate(
            ['employee_id' => $employee->id],
            $validated,
        );

        Flux::toast(variant: 'success', text: __('Personal information saved.'));
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Personal information') }}</flux:heading>
    <flux:subheading>{{ __('CS Form 212, items 1 to 16.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 max-w-3xl space-y-6">
        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="form.date_of_birth" type="date" :label="__('Date of birth')" />
            <flux:input wire:model="form.place_of_birth" :label="__('Place of birth')" />

            <flux:select wire:model="form.sex" :label="__('Sex')" :placeholder="__('Choose')">
                @foreach (App\Enums\Sex::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="form.civil_status" :label="__('Civil status')" :placeholder="__('Choose')">
                @foreach (App\Enums\CivilStatus::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="form.height_m" type="number" step="0.01" :label="__('Height (m)')" />
            <flux:input wire:model="form.weight_kg" type="number" step="0.01" :label="__('Weight (kg)')" />
            <flux:input wire:model="form.blood_type" :label="__('Blood type')" />
            <flux:input wire:model="form.citizenship" :label="__('Citizenship')" />
        </div>

        <flux:separator :text="__('Identification numbers')" />

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="form.gsis_id" :label="__('GSIS ID no.')" />
            <flux:input wire:model="form.pagibig_id" :label="__('PAG-IBIG ID no.')" />
            <flux:input wire:model="form.philhealth_no" :label="__('PhilHealth no.')" />
            <flux:input wire:model="form.sss_no" :label="__('SSS no.')" />
            <flux:input wire:model="form.tin_no" :label="__('TIN no.')" />
            <flux:input wire:model="form.agency_employee_no" :label="__('Agency employee no.')" />
            <flux:input wire:model="form.philsys_id" :label="__('PhilSys ID no.')" />
        </div>

        <flux:separator :text="__('Residential address')" />

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="form.res_house_no" :label="__('House/Block/Lot no.')" />
            <flux:input wire:model="form.res_street" :label="__('Street')" />
            <flux:input wire:model="form.res_subdivision" :label="__('Subdivision/Village')" />
            <flux:input wire:model="form.res_barangay" :label="__('Barangay')" />
            <flux:input wire:model="form.res_city" :label="__('City/Municipality')" />
            <flux:input wire:model="form.res_province" :label="__('Province')" />
            <flux:input wire:model="form.res_zip_code" :label="__('ZIP code')" />
        </div>

        <flux:separator :text="__('Permanent address')" />

        <flux:checkbox
            wire:model.live="form.permanent_same_as_residential"
            :label="__('Same as residential address')"
        />

        <div class="grid gap-6 sm:grid-cols-2" @if ($form['permanent_same_as_residential'] ?? false) inert @endif>
            <flux:input wire:model="form.perm_house_no" :label="__('House/Block/Lot no.')" />
            <flux:input wire:model="form.perm_street" :label="__('Street')" />
            <flux:input wire:model="form.perm_subdivision" :label="__('Subdivision/Village')" />
            <flux:input wire:model="form.perm_barangay" :label="__('Barangay')" />
            <flux:input wire:model="form.perm_city" :label="__('City/Municipality')" />
            <flux:input wire:model="form.perm_province" :label="__('Province')" />
            <flux:input wire:model="form.perm_zip_code" :label="__('ZIP code')" />
        </div>

        <flux:separator :text="__('Contact details')" />

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="form.telephone_no" :label="__('Telephone no.')" />
            <flux:input wire:model="form.mobile_no" :label="__('Mobile no.')" />
            <flux:input wire:model="form.email_address" type="email" :label="__('Email address')" />
        </div>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
```

Add `use Illuminate\Validation\Rule;` to the component's imports.

- [ ] **Step 9: Write the routes**

Create `routes/pds.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('pds/personal-information', 'pages::pds.personal-information')
        ->name('pds.personal-information');
});
```

In `routes/web.php`:

```php
require __DIR__.'/pds.php';
```

- [ ] **Step 10: Write the behaviour test**

Create `tests/Feature/Pds/PersonalInformationTest.php`:

```php
<?php

namespace Tests\Feature\Pds;

use App\Enums\CivilStatus;
use App\Enums\Sex;
use App\Models\Employee;
use App\Models\Pds\PersonalInformation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PersonalInformationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('employee');
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_saving_creates_the_record(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->set('form.date_of_birth', '1990-04-12')
            ->set('form.place_of_birth', 'Surigao City')
            ->set('form.sex', Sex::Female->value)
            ->set('form.civil_status', CivilStatus::Married->value)
            ->set('form.height_m', 1.58)
            ->set('form.weight_kg', 52.4)
            ->set('form.mobile_no', '09171234567')
            ->call('save')
            ->assertHasNoErrors();

        $record = PersonalInformation::firstWhere('employee_id', $this->employee->id);

        $this->assertSame('1990-04-12', $record->date_of_birth->format('Y-m-d'));
        $this->assertSame(Sex::Female, $record->sex);
        $this->assertSame(CivilStatus::Married, $record->civil_status);
        $this->assertSame('1.58', $record->height_m);
    }

    public function test_saving_twice_updates_rather_than_duplicates(): void
    {
        // employee_id is unique on this table; a second insert would throw.
        foreach (['Surigao City', 'Butuan City'] as $place) {
            Livewire::actingAs($this->user)
                ->test('pages::pds.personal-information')
                ->set('form.place_of_birth', $place)
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(1, PersonalInformation::where('employee_id', $this->employee->id)->count());
        $this->assertSame('Butuan City', PersonalInformation::first()->place_of_birth);
    }

    public function test_an_existing_record_is_loaded_into_the_form(): void
    {
        PersonalInformation::factory()->create([
            'employee_id' => $this->employee->id,
            'place_of_birth' => 'Cebu City',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->assertSet('form.place_of_birth', 'Cebu City');
    }

    public function test_ticking_same_as_residential_copies_the_address(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->set('form.res_house_no', '12')
            ->set('form.res_street', 'Rizal Street')
            ->set('form.res_barangay', 'Washington')
            ->set('form.res_city', 'Surigao City')
            ->set('form.res_zip_code', '8400')
            ->set('form.permanent_same_as_residential', true)
            ->call('save')
            ->assertHasNoErrors();

        $record = PersonalInformation::first();

        $this->assertSame('Rizal Street', $record->perm_street);
        $this->assertSame('8400', $record->perm_zip_code);
    }

    public function test_a_birth_date_in_the_future_is_refused(): void
    {
        // The legacy table held a date of birth in 2026. A validated column
        // is the only thing that stops that.
        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->set('form.date_of_birth', now()->addYear()->format('Y-m-d'))
            ->call('save')
            ->assertHasErrors('form.date_of_birth');

        $this->assertSame(0, PersonalInformation::count());
    }

    public function test_a_tampered_employee_id_cannot_redirect_a_save(): void
    {
        // mount() authorised one employee. The property is rehydrated from the
        // browser on every later request, so the save has to ask again.
        $someoneElse = Employee::factory()->create();

        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->set('employeeId', $someoneElse->id)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, PersonalInformation::count());
    }
}
```

- [ ] **Step 11: Run the tests**

```bash
php artisan migrate
php artisan test --filter="PdsAuthorizationTest|PersonalInformationTest"
```

Expected: PASS, 12 tests.

- [ ] **Step 12: Add the sidebar link**

In `resources/views/layouts/app/sidebar.blade.php`, above the Employees entry:

```blade
@if (auth()->user()?->employee)
    <flux:sidebar.item icon="identification" :href="route('pds.personal-information')" :current="request()->routeIs('pds.*')" wire:navigate>
        {{ __('My PDS') }}
    </flux:sidebar.item>
@endif
```

- [ ] **Step 13: Prove it in a browser — do not skip this**

```bash
npm run build
```

Sign in as an account linked to an employee, open **My PDS**, fill in a few fields, and press **Save**.

Expected: a success toast, and the values still there after a refresh.

**If nothing happens, stop here.** The Import button in Phase 1a has the same shape and the same symptom, and the rest of this plan is eight more forms exactly like this one. Report what the browser's Network tab shows for the request rather than continuing.

- [ ] **Step 14: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "Add the PDS personal information section"
```

---

## Task 2: Family background and children

**Files:**
- Create: `database/migrations/*_create_pds_family_background_table.php`
- Create: `database/migrations/*_create_pds_children_table.php`
- Create: `app/Models/Pds/{FamilyBackground,Child}.php`
- Create: `database/factories/Pds/{FamilyBackgroundFactory,ChildFactory}.php`
- Create: `app/Services/Pds/RowWriter.php`
- Create: `app/Livewire/Concerns/ManagesRepeatingRows.php`
- Create: `resources/views/components/pds/repeater.blade.php`
- Create: `resources/views/pages/pds/⚡family-background.blade.php`
- Modify: `routes/pds.php`, `app/Models/Employee.php`
- Test: `tests/Feature/Pds/RowWriterTest.php`
- Test: `tests/Feature/Pds/FamilyBackgroundTest.php`

**Interfaces:**
- Consumes: `EditsPdsSection`, `PdsPolicy`.
- Produces:
  - `RowWriter::sync(string $modelClass, int $employeeId, array $rows): void` — creates, updates, orders and deletes in one pass; throws `AuthorizationException` if a row carries an id belonging to another employee. Task 6 adds a fourth parameter, `array $scope = []`, so one table can hold three independently synchronised lists.
  - `ManagesRepeatingRows` trait: `public array $rows`, `addRow(): void`, `removeRow(int $index): void`, `protected function blankRow(): array`.
  - `Employee::familyBackground(): HasOne`, `Employee::children(): HasMany`.
  - Named route `pds.family-background`.

**This task establishes the repeater pattern that six later sections reuse.** Get it right here.

- [ ] **Step 1: Write the failing RowWriter test**

Create `tests/Feature/Pds/RowWriterTest.php`:

```php
<?php

namespace Tests\Feature\Pds;

use App\Models\Employee;
use App\Models\Pds\Child;
use App\Services\Pds\RowWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RowWriterTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->create();
    }

    public function test_it_creates_rows_in_the_order_they_were_given(): void
    {
        app(RowWriter::class)->sync(Child::class, $this->employee->id, [
            ['id' => null, 'name' => 'Ana', 'date_of_birth' => '2010-01-05'],
            ['id' => null, 'name' => 'Ben', 'date_of_birth' => '2012-06-11'],
        ]);

        $children = Child::where('employee_id', $this->employee->id)->orderBy('sort_order')->get();

        $this->assertSame(['Ana', 'Ben'], $children->pluck('name')->all());
        $this->assertSame([0, 1], $children->pluck('sort_order')->all());
    }

    public function test_it_updates_a_row_that_carries_an_id(): void
    {
        $child = Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ana']);

        app(RowWriter::class)->sync(Child::class, $this->employee->id, [
            ['id' => $child->id, 'name' => 'Ana Marie', 'date_of_birth' => '2010-01-05'],
        ]);

        $this->assertSame(1, Child::count());
        $this->assertSame('Ana Marie', $child->refresh()->name);
    }

    public function test_it_deletes_rows_that_are_no_longer_in_the_list(): void
    {
        $kept = Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ana']);
        Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ben']);

        app(RowWriter::class)->sync(Child::class, $this->employee->id, [
            ['id' => $kept->id, 'name' => 'Ana', 'date_of_birth' => null],
        ]);

        $this->assertSame(1, Child::count());
        $this->assertTrue(Child::first()->is($kept));
    }

    public function test_an_empty_list_removes_everything(): void
    {
        Child::factory()->count(3)->create(['employee_id' => $this->employee->id]);

        app(RowWriter::class)->sync(Child::class, $this->employee->id, []);

        $this->assertSame(0, Child::count());
    }

    public function test_it_refuses_a_row_id_belonging_to_another_employee(): void
    {
        // The row id travels to the browser and comes back. Without this check,
        // editing your own children could rewrite somebody else's.
        $someoneElse = Child::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        $this->expectException(AuthorizationException::class);

        app(RowWriter::class)->sync(Child::class, $this->employee->id, [
            ['id' => $someoneElse->id, 'name' => 'Hijacked', 'date_of_birth' => null],
        ]);
    }

    public function test_a_refused_sync_writes_nothing(): void
    {
        $mine = Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ana']);
        $someoneElse = Child::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        try {
            app(RowWriter::class)->sync(Child::class, $this->employee->id, [
                ['id' => $mine->id, 'name' => 'Changed', 'date_of_birth' => null],
                ['id' => $someoneElse->id, 'name' => 'Hijacked', 'date_of_birth' => null],
            ]);
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame('Ana', $mine->refresh()->name);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=RowWriterTest`
Expected: FAIL with `Class "App\Services\Pds\RowWriter" not found`.

- [ ] **Step 3: Write the two migrations**

`create_pds_family_background_table`:

```php
Schema::create('pds_family_background', function (Blueprint $table) {
    $table->id();
    $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();

    // 17 — spouse
    $table->string('spouse_surname', 100)->nullable();
    $table->string('spouse_first_name', 100)->nullable();
    $table->string('spouse_middle_name', 100)->nullable();
    $table->string('spouse_name_extension', 20)->nullable();
    $table->string('spouse_occupation', 150)->nullable();
    $table->string('spouse_employer', 150)->nullable();
    $table->string('spouse_business_address', 255)->nullable();
    $table->string('spouse_telephone_no', 40)->nullable();

    // 19 — father
    $table->string('father_surname', 100)->nullable();
    $table->string('father_first_name', 100)->nullable();
    $table->string('father_middle_name', 100)->nullable();
    $table->string('father_name_extension', 20)->nullable();

    // 20 — mother, maiden name
    $table->string('mother_surname', 100)->nullable();
    $table->string('mother_first_name', 100)->nullable();
    $table->string('mother_middle_name', 100)->nullable();

    $table->timestamps();
});
```

`create_pds_children_table`:

```php
Schema::create('pds_children', function (Blueprint $table) {
    $table->id();
    $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
    $table->string('name', 200);                       // 18
    $table->date('date_of_birth')->nullable();
    $table->unsignedSmallInteger('sort_order')->default(0);
    $table->timestamps();

    $table->index(['employee_id', 'sort_order']);
});
```

**Every one-to-many PDS table carries `sort_order`.** Rows must print in the order the employee arranged them, not in insertion order.

- [ ] **Step 4: Write the models**

Create `app/Models/Pds/FamilyBackground.php` with `protected $table = 'pds_family_background';` and a `#[Fillable]` listing every column above except `id` and the timestamps, plus `employee(): BelongsTo`.

Create `app/Models/Pds/Child.php`:

```php
<?php

namespace App\Models\Pds;

use App\Models\Employee;
use Database\Factories\Pds\ChildFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'name', 'date_of_birth', 'sort_order'])]
class Child extends Model
{
    /** @use HasFactory<ChildFactory> */
    use HasFactory;

    protected $table = 'pds_children';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
```

Add to `Employee`:

```php
    public function familyBackground(): HasOne
    {
        return $this->hasOne(FamilyBackground::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(Child::class)->orderBy('sort_order');
    }
```

- [ ] **Step 5: Write the RowWriter**

Create `app/Services/Pds/RowWriter.php`:

```php
<?php

namespace App\Services\Pds;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Synchronises one repeating PDS section: creates new rows, updates the ones
 * that came back with an id, orders them as given, and deletes the rest.
 *
 * The row id makes a round trip through the browser, which means it comes back
 * as whatever the person on the other end wants it to be. Everything here is
 * scoped to one employee_id, and a row claiming an id outside that scope is
 * refused rather than quietly ignored — a silent skip would look to the
 * employee like their edit had been saved.
 */
class RowWriter
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws AuthorizationException
     */
    public function sync(string $modelClass, int $employeeId, array $rows): void
    {
        DB::transaction(function () use ($modelClass, $employeeId, $rows) {
            $owned = $modelClass::where('employee_id', $employeeId)->pluck('id')->all();
            $keep = [];

            foreach (array_values($rows) as $position => $row) {
                $id = $row['id'] ?? null;
                unset($row['id']);

                if ($id !== null && ! in_array((int) $id, $owned, true)) {
                    throw new AuthorizationException(
                        'That row does not belong to this employee.'
                    );
                }

                $model = $id !== null
                    ? $modelClass::findOrFail($id)
                    : new $modelClass(['employee_id' => $employeeId]);

                $model->fill($row);
                $model->employee_id = $employeeId;
                $model->sort_order = $position;
                $model->save();

                $keep[] = $model->id;
            }

            $modelClass::where('employee_id', $employeeId)
                ->whereNotIn('id', $keep ?: [0])
                ->delete();
        });
    }
}
```

- [ ] **Step 6: Run the RowWriter test**

Run: `php artisan test --filter=RowWriterTest`
Expected: PASS, 6 tests.

- [ ] **Step 7: Write the repeating-rows trait**

Create `app/Livewire/Concerns/ManagesRepeatingRows.php`:

```php
<?php

namespace App\Livewire\Concerns;

/**
 * Add and remove rows without a page reload — the reason this phase uses
 * Livewire at all.
 *
 * Each row carries a `key`, generated once and never reused. Blade binds
 * wire:key to it rather than to the array index: with an index key, deleting a
 * row in the middle makes every row below it render the one above's content.
 * That is the most common bug in Livewire repeaters and among the hardest to
 * see, because the page looks plausible.
 */
trait ManagesRepeatingRows
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public int $nextKey = 0;

    /** @return array<string, mixed> */
    abstract protected function blankRow(): array;

    public function addRow(): void
    {
        $this->rows[] = array_merge($this->blankRow(), [
            'id' => null,
            'key' => 'row-'.$this->nextKey++,
        ]);
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);

        $this->rows = array_values($this->rows);
    }

    /**
     * @param  iterable<int, \Illuminate\Database\Eloquent\Model>  $records
     * @param  list<string>  $columns
     */
    protected function loadRows(iterable $records, array $columns): void
    {
        $this->rows = [];

        foreach ($records as $record) {
            $row = ['id' => $record->id, 'key' => 'row-'.$this->nextKey++];

            foreach ($columns as $column) {
                $value = $record->{$column};
                $row[$column] = $value instanceof \DateTimeInterface
                    ? $value->format('Y-m-d')
                    : $value;
            }

            $this->rows[] = $row;
        }

        if ($this->rows === []) {
            $this->addRow();
        }
    }

    /** Strips the display-only key before the rows reach RowWriter. */
    protected function rowsForWriting(): array
    {
        return array_map(
            fn (array $row) => array_diff_key($row, ['key' => null]),
            array_values($this->rows)
        );
    }
}
```

- [ ] **Step 8: Write the repeater component**

Create `resources/views/components/pds/repeater.blade.php`:

```blade
@props(['heading', 'addLabel' => __('Add row')])

<div class="space-y-4">
    <flux:heading size="lg">{{ $heading }}</flux:heading>

    {{ $slot }}

    <flux:button type="button" wire:click="addRow" variant="subtle" icon="plus" size="sm">
        {{ $addLabel }}
    </flux:button>
</div>
```

- [ ] **Step 9: Write the section**

Create `resources/views/pages/pds/⚡family-background.blade.php`. The component:

```blade
<?php

use App\Livewire\Concerns\EditsPdsSection;
use App\Livewire\Concerns\ManagesRepeatingRows;
use App\Models\Pds\Child;
use App\Models\Pds\FamilyBackground;
use App\Services\Pds\RowWriter;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Family background')] class extends Component {
    use EditsPdsSection;
    use ManagesRepeatingRows;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(?int $employee = null): void
    {
        $record = $this->bootSection($employee);

        $columns = (new FamilyBackground)->getFillable();

        $this->form = array_merge(
            array_fill_keys($columns, null),
            $record->familyBackground?->only($columns) ?? [],
        );

        $this->loadRows($record->children, ['name', 'date_of_birth']);
    }

    /** @return array<string, mixed> */
    protected function blankRow(): array
    {
        return ['name' => '', 'date_of_birth' => null];
    }

    public function save(): void
    {
        $employee = $this->authoriseSave();

        $validated = $this->validate([
            'form.spouse_surname' => ['nullable', 'string', 'max:100'],
            'form.spouse_first_name' => ['nullable', 'string', 'max:100'],
            'form.spouse_middle_name' => ['nullable', 'string', 'max:100'],
            'form.spouse_name_extension' => ['nullable', 'string', 'max:20'],
            'form.spouse_occupation' => ['nullable', 'string', 'max:150'],
            'form.spouse_employer' => ['nullable', 'string', 'max:150'],
            'form.spouse_business_address' => ['nullable', 'string', 'max:255'],
            'form.spouse_telephone_no' => ['nullable', 'string', 'max:40'],
            'form.father_surname' => ['nullable', 'string', 'max:100'],
            'form.father_first_name' => ['nullable', 'string', 'max:100'],
            'form.father_middle_name' => ['nullable', 'string', 'max:100'],
            'form.father_name_extension' => ['nullable', 'string', 'max:20'],
            'form.mother_surname' => ['nullable', 'string', 'max:100'],
            'form.mother_first_name' => ['nullable', 'string', 'max:100'],
            'form.mother_middle_name' => ['nullable', 'string', 'max:100'],
            'rows.*.name' => ['nullable', 'string', 'max:200'],
            'rows.*.date_of_birth' => ['nullable', 'date', 'before:today'],
        ]);

        $family = $validated['form'];
        unset($family['employee_id']);

        FamilyBackground::updateOrCreate(['employee_id' => $employee->id], $family);

        // A blank row is how an empty repeater renders; it is not an entry.
        $children = array_values(array_filter(
            $this->rowsForWriting(),
            fn (array $row) => trim((string) $row['name']) !== ''
        ));

        app(RowWriter::class)->sync(Child::class, $employee->id, $children);

        $this->loadRows($employee->refresh()->children, ['name', 'date_of_birth']);

        Flux::toast(variant: 'success', text: __('Family background saved.'));
    }
}; ?>
```

And the markup, inside the same file:

```blade
<section class="w-full">
    <flux:heading size="xl">{{ __('Family background') }}</flux:heading>
    <flux:subheading>{{ __('CS Form 212, items 17 to 20.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 max-w-3xl space-y-8">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Spouse') }}</flux:heading>
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="form.spouse_surname" :label="__('Surname')" />
                <flux:input wire:model="form.spouse_first_name" :label="__('First name')" />
                <flux:input wire:model="form.spouse_middle_name" :label="__('Middle name')" />
                <flux:input wire:model="form.spouse_name_extension" :label="__('Name extension (Jr., Sr.)')" />
                <flux:input wire:model="form.spouse_occupation" :label="__('Occupation')" />
                <flux:input wire:model="form.spouse_employer" :label="__('Employer / business name')" />
                <flux:input wire:model="form.spouse_business_address" :label="__('Business address')" />
                <flux:input wire:model="form.spouse_telephone_no" :label="__('Telephone no.')" />
            </div>
        </div>

        <x-pds.repeater :heading="__('Children')" :add-label="__('Add a child')">
            @foreach ($rows as $index => $row)
                <div wire:key="{{ $row['key'] }}" class="flex items-end gap-4">
                    <flux:input class="flex-1" wire:model="rows.{{ $index }}.name" :label="__('Name')" />
                    <flux:input wire:model="rows.{{ $index }}.date_of_birth" type="date" :label="__('Date of birth')" />
                    <flux:button
                        type="button"
                        wire:click="removeRow({{ $index }})"
                        variant="subtle"
                        icon="trash"
                        :aria-label="__('Remove this child')"
                    />
                </div>
            @endforeach
        </x-pds.repeater>

        <div class="space-y-4">
            <flux:heading size="lg">{{ __("Father's name") }}</flux:heading>
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="form.father_surname" :label="__('Surname')" />
                <flux:input wire:model="form.father_first_name" :label="__('First name')" />
                <flux:input wire:model="form.father_middle_name" :label="__('Middle name')" />
                <flux:input wire:model="form.father_name_extension" :label="__('Name extension')" />
            </div>
        </div>

        <div class="space-y-4">
            <flux:heading size="lg">{{ __("Mother's maiden name") }}</flux:heading>
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="form.mother_surname" :label="__('Surname')" />
                <flux:input wire:model="form.mother_first_name" :label="__('First name')" />
                <flux:input wire:model="form.mother_middle_name" :label="__('Middle name')" />
            </div>
        </div>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
```

**`wire:key="{{ $row['key'] }}"`, never `wire:key="{{ $index }}"`.** This is the line the whole trait exists to make possible.

- [ ] **Step 10: Write the section test**

Create `tests/Feature/Pds/FamilyBackgroundTest.php` covering: saving spouse and parent fields; adding two children and seeing both persisted in order; removing the middle child of three and finding the right two left; a blank row not being saved as a child; and the `date_of_birth` validation refusing a future date.

```php
    public function test_removing_the_middle_child_leaves_the_right_two(): void
    {
        // With wire:key bound to the array index instead of a stable key, this
        // is the test that fails — the surviving rows carry each other's data.
        Livewire::actingAs($this->user)
            ->test('pages::pds.family-background')
            ->set('rows.0.name', 'Ana')
            ->call('addRow')
            ->set('rows.1.name', 'Ben')
            ->call('addRow')
            ->set('rows.2.name', 'Carlo')
            ->call('removeRow', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['Ana', 'Carlo'],
            Child::where('employee_id', $this->employee->id)->orderBy('sort_order')->pluck('name')->all()
        );
    }
```

- [ ] **Step 11: Add the route**

In `routes/pds.php`:

```php
Route::livewire('pds/family-background', 'pages::pds.family-background')
    ->name('pds.family-background');
```

- [ ] **Step 12: Run everything and commit**

```bash
php artisan migrate
php artisan test
npm run build
vendor/bin/pint --dirty
git add -A
git commit -m "Add the PDS family background section and the repeating row writer"
```

---

## Tasks 3 to 7: the remaining sections

Each of these follows the pattern Task 2 established: a migration, a model with `#[Fillable]` and `sort_order`, a section using `EditsPdsSection` and `ManagesRepeatingRows`, `RowWriter::sync()` on save, and a test covering save, reorder, remove-the-middle-row, and validation.

**These five tasks are specified here, not scripted.** Every other task in this plan and in Phase 1a carries its code step by step; these carry their columns, enums, routes and rules instead. That is a deliberate exception and it has a cost — whoever implements them has to carry Task 2's pattern in their head rather than read it.

It is written this way because Task 1 is unproven in a browser. Five fully scripted sections is a large amount of Livewire form code committed to a pattern that may not work on these machines at all. **Expand these into full step-by-step tasks once Task 1 saves in a browser** — by then the pattern is known good, and the expansion is mechanical.

**Do not start these until Task 1 has been proven in a browser.**

### Task 3: Education (items 21-26)

`pds_educations`: `employee_id`, `level` (enum), `school_name`, `degree_course`, `period_from` (year), `period_to` (year), `highest_level_units`, `year_graduated`, `honours`, `sort_order`.

`app/Enums/EducationLevel.php`: Elementary, Secondary, Vocational, College, Graduate — with `label()` and `values()`.

**One-to-many, not five fixed rows.** Employees hold two degrees or two master's programmes and the CSC form allows it. The legacy system inserted exactly five blank rows per employee, which is why that table is full of empty records.

Route: `pds.education`.

### Task 4: Civil service eligibility and work experience (items 27-28)

`pds_eligibilities`: `employee_id`, `eligibility`, `rating`, `examination_date`, `examination_place`, `licence_number`, `licence_validity`, `sort_order`.

`pds_work_experiences`: `employee_id`, `date_from`, `date_to`, `position_title`, `department_agency`, `monthly_salary` (decimal 12,2), `salary_grade_step`, `status_of_appointment`, `is_government_service` (boolean), `sort_order`.

Two repeaters on one page. Work experience is the section that overflows the CSC template most often — note it here so Phase 1c's continuation-sheet work has a known first case.

Routes: `pds.eligibility`, `pds.work-experience`.

### Task 5: Voluntary work and learning and development (items 29-30)

`pds_voluntary_works`: `employee_id`, `organisation_name_address`, `date_from`, `date_to`, `number_of_hours`, `position_nature_of_work`, `sort_order`.

`pds_learning_developments`: `employee_id`, `title`, `date_from`, `date_to`, `number_of_hours`, `type` (enum), `conducted_by`, `sort_order`.

`app/Enums/LearningDevelopmentType.php`: Managerial, Supervisory, Technical, Foundation.

Routes: `pds.voluntary-work`, `pds.learning-development`.

### Task 6: Other information (items 31-33)

`pds_other_entries`: `employee_id`, `kind` (enum), `value`, `sort_order`.

`app/Enums/OtherEntryKind.php`: SkillOrHobby, Distinction, Membership.

**One table for all three.** Items 31, 32 and 33 are each an ordered list of single-line text. Three identical tables would mean three copies of the same component, validation and exporter branch; one table with a `kind` enum means one component used three times.

The page renders three repeaters side by side, each filtered to its kind. `RowWriter::sync()` is called once per kind, so it needs a scoping variant — extend it with an optional `array $scope = []` merged into both the ownership query and the delete, then pass `['kind' => OtherEntryKind::SkillOrHobby]`. Add a `RowWriterTest` case proving that syncing one kind leaves the other two untouched.

Route: `pds.other-information`.

### Task 7: Declarations, references and government ID (items 34-42)

`pds_declarations` — one row per employee:

- `q34_related_third_degree` (bool) + `q34_related_third_degree_details`
- `q34_related_fourth_degree` (bool) + `q34_related_fourth_degree_details`
- `q35_administrative_offence` (bool) + details
- `q35_criminally_charged` (bool) + details, `q35_date_filed`, `q35_case_status`
- `q36_convicted` (bool) + details
- `q37_separated_from_service` (bool) + details
- `q38_candidate_in_election` (bool) + details
- `q38_resigned_to_campaign` (bool) + details
- `q39_immigrant_or_permanent_resident` (bool) + details
- `q40_indigenous_group` (bool) + details
- `q40_person_with_disability` (bool) + `q40_pwd_id_no`
- `q40_solo_parent` (bool) + `q40_solo_parent_id_no`
- `government_id_type`, `government_id_number`, `government_id_issued`
- `date_accomplished`

`pds_references`: `employee_id`, `name`, `address`, `telephone_no`, `sort_order`.

Every boolean pairs with a details field that only matters when the answer is yes. Validate it that way: `required_if` on the details when the boolean is true, so an unexplained yes cannot be saved.

Route: `pds.declarations`.

---

## Task 8: The completeness checklist

**Files:**
- Create: `app/Services/Pds/PdsCompleteness.php`
- Create: `resources/views/components/pds/section-nav.blade.php`
- Modify: every section page to render the nav
- Modify: `resources/views/dashboard.blade.php`
- Test: `tests/Feature/Pds/PdsCompletenessTest.php`

**Interfaces:**
- Consumes: every PDS model.
- Produces: `PdsCompleteness::for(Employee $employee): array` returning, for each of the nine sections, its route name, label, and whether it holds anything.

There is no approval gate in this design, which means nothing else tells an employee their PDS is incomplete. This checklist is the only thing that will.

```php
/**
 * @return list<array{key:string, label:string, route:string, complete:bool}>
 */
public function for(Employee $employee): array
```

"Complete" means the section holds at least one saved value — not that every field is filled. A stricter rule would mark almost everyone incomplete forever and the indicator would stop meaning anything.

Tests: an employee with nothing shows nine incomplete; saving personal information marks exactly that one complete; a section with only a blank repeater row stays incomplete.

- [ ] Commit: `git commit -m "Show an employee which PDS sections are still empty"`

---

## Task 9: HR access and read logging

**Files:**
- Modify: `resources/views/pages/employees/⚡index.blade.php` — a link to each employee's PDS
- Modify: `app/Livewire/Concerns/EditsPdsSection.php` — record the read
- Test: `tests/Feature/Pds/PdsReadLoggingTest.php`

`AuditRecorder` was built in Phase 1a with no caller. This is its caller.

In `bootSection()`, after authorising, record the read **only when the reader is not the owner**:

```php
if ($employee->user_id !== auth()->id()) {
    app(AuditRecorder::class)->recordRead($employee, 'Opened '.class_basename(static::class).' of the PDS');
}
```

Logging an employee opening their own PDS would bury the entries that matter under thousands that do not. Edits are rare; reading someone else's record is the more common abuse, and that is what this captures.

Tests: HR opening someone's PDS writes a `read` entry naming them; an employee opening their own writes nothing; the entry appears on `/audit`.

- [ ] Commit: `git commit -m "Record when HR opens somebody else's PDS"`

---

## Phase 1b is done when

1. An employee signs in, opens **My PDS**, and completes all nine sections, each saving on its own.
2. Repeating rows can be added and removed without a page reload, and removing a row from the middle leaves the right ones behind.
3. The dashboard shows which of the nine sections are still empty.
4. HR opens and corrects any employee's PDS from the employee list.
5. Employee A cannot reach employee B's PDS by changing the URL, and cannot reach B's rows by changing a row id — both proven by tests.
6. Every read of somebody else's PDS appears in `/audit`.
7. `php artisan test` passes in full and `npm run build` succeeds.

## Not in Phase 1b

- **The CSC `.xlsx` export.** That is Phase 1c, and it needs the official CS Form 212 template file in hand before its cell mapping can be written.
- **The photograph.** `photo_path` exists on the personal information table and nothing writes to it yet; uploads arrive with the export, which is what needs the image.
- **An employee edit screen for HR.** Still outstanding from Phase 1a.

## Open items

- **The Import button.** Unexplained at the time of writing: the click never reaches the server, no log entry, no error, and the same code passes every test. Task 1 of this plan is deliberately a vertical slice so that this is settled after one section rather than after nine.
