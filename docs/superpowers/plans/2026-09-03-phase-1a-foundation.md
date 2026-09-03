# HRIS Phase 1a — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the employee master, its authorization layer, and the tools that populate it, so that the Personal Data Sheet has something to hang off.

**Architecture:** The organizational schema is copied from `ipcr-system-laravel` with identical column names and types, consolidated into fresh migrations. Roles come from `spatie/laravel-permission`, but ownership questions live in policies, never in permissions. Employees enter through a CSV import that previews before it writes; logins are issued separately by an admin. Every change to an employee is recorded by `spatie/laravel-activitylog`.

**Tech Stack:** Laravel 13.17, PHP 8.3, Livewire 4.1 (single-file components), Flux UI, Tailwind v4, Fortify, MySQL 8, PHPUnit 12.5 on in-memory SQLite.

**Spec:** `docs/superpowers/specs/2026-09-03-phase-1-core-and-pds-design.md`

## Global Constraints

- **English only** in code, comments, commit messages, and every string a user sees. The conversation may be in Taglish; the codebase is not.
- **Never run `git commit` or `git push` on the author's behalf.** Each task ends with a commit step written out for the author to run themselves.
- **Copied schema stays identical.** Column names and types for `positions`, `designations`, `divisions`, `sections`, `employees` and `employee_designations` must match `ipcr-system-laravel` exactly. `employees.employee_number` is the stable key across both systems.
- **Livewire 4 single-file components.** Page components live at `resources/views/pages/<dir>/⚡<name>.blade.php`, are referenced as `pages::<dir>.<name>`, and are routed with `Route::livewire()`. This is the convention the starter kit established; follow it.
- **Flux UI for all form controls** (`flux:input`, `flux:select`, `flux:button`, `flux:table`). Do not hand-roll inputs.
- **Every route touching an Employee passes through a policy.** No exceptions.
- **Tests are PHPUnit classes** in `tests/Feature` or `tests/Unit`, methods named `test_snake_case`, using `RefreshDatabase`.
- Run `vendor/bin/pint --dirty` before each commit.

---

## File Structure

**Enums** — the vocabulary, one file each:
- `app/Enums/EmploymentStatus.php` — how the hospital engages a person
- `app/Enums/OrgPost.php` — where someone sits in the org chart

**Models** — thin, relationships only:
- `app/Models/{Position,Designation,Division,Section,Employee,EmployeeDesignation}.php`
- `app/Models/User.php` — modified: `HasRoles`, `employee()` relation

**Policies** — every ownership question:
- `app/Policies/EmployeePolicy.php`

**Services** — the decisions:
- `app/Services/EmployeeImport/CsvRow.php` — one parsed line and its errors
- `app/Services/EmployeeImport/ImportPreview.php` — the whole parsed file
- `app/Services/EmployeeImport/EmployeeCsvParser.php` — reads and validates, writes nothing
- `app/Services/EmployeeImport/EmployeeImporter.php` — the only thing that writes
- `app/Services/AuditRecorder.php` — records reads, which activitylog does not

**Pages** — Livewire single-file components:
- `resources/views/pages/employees/⚡index.blade.php`
- `resources/views/pages/employees/⚡import.blade.php`
- `resources/views/pages/employees/⚡issue-account.blade.php`
- `resources/views/pages/audit/⚡index.blade.php`

**Routes:**
- `routes/employees.php` — new, required from `routes/web.php`

The parser is split from the importer deliberately. The parser is pure — a file path in, a preview out, nothing written — which makes it testable without a database and makes "preview before write" structurally impossible to bypass.

---

## Task 1: Roles and permissions

**Files:**
- Modify: `composer.json` (via composer require)
- Create: `database/seeders/RoleSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/RolesTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `RoleSeeder::PERMISSIONS` (`list<string>`), roles `employee`, `hr`, `admin` on the `web` guard. `User` gains `hasRole(string): bool`, `can(string): bool`, `assignRole(string): self` from the `HasRoles` trait.

- [ ] **Step 1: Install the package**

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

Expected: a new `2026_..._create_permission_tables.php` migration runs, creating `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/RolesTest.php`:

```php
<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_the_employee_role_holds_no_permissions(): void
    {
        // Ownership is a policy question, not a permission. An employee reaches
        // their own record through EmployeePolicy, never through a grant.
        $this->assertTrue(Role::findByName('employee')->permissions->isEmpty());
    }

    public function test_hr_can_reach_every_pds_but_cannot_manage_roles(): void
    {
        $hr = Role::findByName('hr');

        $this->assertTrue($hr->hasPermissionTo('pds.view.any'));
        $this->assertTrue($hr->hasPermissionTo('employees.import'));
        $this->assertFalse($hr->hasPermissionTo('roles.manage'));
        $this->assertFalse($hr->hasPermissionTo('org.manage'));
    }

    public function test_admin_holds_every_permission(): void
    {
        $admin = Role::findByName('admin');

        foreach (RoleSeeder::PERMISSIONS as $permission) {
            $this->assertTrue(
                $admin->hasPermissionTo($permission),
                "admin is missing [{$permission}]"
            );
        }
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --filter=RolesTest`
Expected: FAIL with `Class "Database\Seeders\RoleSeeder" not found`.

- [ ] **Step 4: Add the trait to User**

In `app/Models/User.php`, add the import and the trait:

```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;
```

Keep whatever traits the starter kit already put there; add `HasRoles` to the list.

- [ ] **Step 5: Write the seeder**

Create `database/seeders/RoleSeeder.php`:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Three roles. Ownership is deliberately absent from this list: an employee
 * reaching their own record is a question about a specific record, which a
 * permission cannot see. That belongs in EmployeePolicy.
 */
class RoleSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'employees.view',
        'employees.manage',
        'employees.import',
        'pds.view.any',
        'pds.edit.any',
        'pds.export.any',
        'org.manage',
        'users.manage',
        'roles.manage',
        'audit.view',
    ];

    /** @var list<string> */
    public const HR_PERMISSIONS = [
        'employees.view',
        'employees.manage',
        'employees.import',
        'pds.view.any',
        'pds.edit.any',
        'pds.export.any',
        'audit.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate('employee', 'web')->syncPermissions([]);
        Role::findOrCreate('hr', 'web')->syncPermissions(self::HR_PERMISSIONS);
        Role::findOrCreate('admin', 'web')->syncPermissions(self::PERMISSIONS);
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=RolesTest`
Expected: PASS, 3 tests.

- [ ] **Step 7: Register the seeder**

In `database/seeders/DatabaseSeeder.php`, inside `run()`:

```php
$this->call(RoleSeeder::class);
```

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add composer.json composer.lock config/permission.php database/migrations database/seeders app/Models/User.php tests/Feature/RolesTest.php
git commit -m "Add the three roles and their permissions"
```

---

## Task 2: Organizational schema

**Files:**
- Create: `app/Enums/OrgPost.php`
- Create: `database/migrations/*_create_positions_table.php`
- Create: `database/migrations/*_create_designations_table.php`
- Create: `database/migrations/*_create_divisions_table.php`
- Create: `database/migrations/*_create_sections_table.php`
- Create: `app/Models/{Position,Designation,Division,Section}.php`
- Create: `database/factories/{PositionFactory,DivisionFactory,SectionFactory}.php`
- Create: `database/seeders/OrganizationSeeder.php`
- Test: `tests/Feature/OrganizationTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `Division::sections(): HasMany`, `Section::division(): BelongsTo`, `Position` with `title`/`item_number`/`salary_grade`, `Designation` with `title`/`division_id`/`section_id`. The head columns (`divisions.division_head_employee_id`, `sections.section_head_employee_id`) are added in Task 3, after `employees` exists.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OrganizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Position;
use App\Models\Section;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_division_holds_its_sections(): void
    {
        $division = Division::factory()->create(['name' => 'Medical Division']);
        Section::factory()->count(3)->create(['division_id' => $division->id]);

        $this->assertCount(3, $division->refresh()->sections);
        $this->assertSame('Medical Division', $division->sections->first()->division->name);
    }

    public function test_a_division_with_sections_cannot_be_deleted(): void
    {
        // restrictOnDelete: losing a division would orphan every employee under it.
        $division = Division::factory()->create();
        Section::factory()->create(['division_id' => $division->id]);

        $this->expectException(QueryException::class);

        $division->delete();
    }

    public function test_a_plantilla_item_number_is_unique(): void
    {
        Position::factory()->create(['item_number' => 'OSEC-DOHB-NUR1-314-2014']);

        $this->expectException(QueryException::class);

        Position::factory()->create(['item_number' => 'OSEC-DOHB-NUR1-314-2014']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=OrganizationTest`
Expected: FAIL with `Class "App\Models\Division" not found`.

- [ ] **Step 3: Write the enum**

Create `app/Enums/OrgPost.php`:

```php
<?php

namespace App\Enums;

/**
 * Where a person sits in the org chart. Placement rules:
 *   Rank & file / Section Head -> section_id (the division comes from the section)
 *   Division Head              -> division_id only, no section
 *   Chief of Hospital          -> neither, is_chief_of_hospital = true
 */
enum OrgPost: string
{
    case RankAndFile      = 'rank_and_file';
    case SectionHead      = 'section_head';
    case DivisionHead     = 'division_head';
    case ChiefOfHospital  = 'chief_of_hospital';

    public function label(): string
    {
        return match ($this) {
            self::RankAndFile     => 'Rank and File',
            self::SectionHead     => 'Section Head',
            self::DivisionHead    => 'Division Head',
            self::ChiefOfHospital => 'Chief of Hospital',
        };
    }
}
```

- [ ] **Step 4: Write the four migrations**

Run `php artisan make:migration create_positions_table` (and the same for designations, divisions, sections), then fill each `up()`.

`create_positions_table`:

```php
Schema::create('positions', function (Blueprint $table) {
    $table->id();
    $table->string('title');                                 // "Statistician II"
    $table->string('item_number', 50)->nullable()->unique(); // plantilla item no.
    $table->unsignedTinyInteger('salary_grade')->nullable();
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->index('is_active');
});
```

`create_divisions_table`:

```php
Schema::create('divisions', function (Blueprint $table) {
    $table->id();
    $table->string('name');                            // "Medical Division"
    $table->string('code', 20)->nullable()->unique();  // "MED"
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

`create_sections_table` — must run after divisions, so give it a later timestamp:

```php
Schema::create('sections', function (Blueprint $table) {
    $table->id();
    $table->foreignId('division_id')
        ->constrained()
        ->cascadeOnUpdate()
        ->restrictOnDelete();                          // a division with sections cannot be deleted
    $table->string('name');                            // "Statistics Unit"
    $table->string('code', 20)->nullable()->unique();  // "STAT"
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

`create_designations_table` — must run after divisions and sections:

```php
Schema::create('designations', function (Blueprint $table) {
    $table->id();
    $table->string('title');                 // "OIC - Budget Officer"
    $table->text('description')->nullable();
    $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->index('is_active');
});
```

`ipcr-system-laravel` reached this shape across two migrations, adding the division and section columns later. This is a fresh database, so they are consolidated into one — the resulting columns are identical, which is what the spec requires.

- [ ] **Step 5: Write the four models**

`app/Models/Position.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'item_number', 'salary_grade', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
```

`app/Models/Division.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Division extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
```

`app/Models/Section.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    use HasFactory;

    protected $fillable = ['division_id', 'name', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
```

`app/Models/Designation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Designation extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'division_id', 'section_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
```

- [ ] **Step 6: Write the factories**

`database/factories/DivisionFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DivisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true).' Division',
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'is_active' => true,
        ];
    }
}
```

`database/factories/SectionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Division;
use Illuminate\Database\Eloquent\Factories\Factory;

class SectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'division_id' => Division::factory(),
            'name' => $this->faker->unique()->words(2, true).' Unit',
            'code' => strtoupper($this->faker->unique()->lexify('????')),
            'is_active' => true,
        ];
    }
}
```

`database/factories/PositionFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => $this->faker->unique()->jobTitle(),
            'item_number' => 'OSEC-DOHB-'.$this->faker->unique()->numerify('####-####'),
            'salary_grade' => $this->faker->numberBetween(1, 33),
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 7: Run the migration and the test**

```bash
php artisan migrate
php artisan test --filter=OrganizationTest
```

Expected: PASS, 3 tests.

- [ ] **Step 8: Write the reference-data seeder**

There is no organizational management screen in this plan — it is not in the Phase 1 definition of done. Divisions, sections and positions are seeded, and the HR office's real list replaces the examples below.

Create `database/seeders/OrganizationSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Position;
use App\Models\Section;
use Illuminate\Database\Seeder;

/**
 * Reference data. Replace the rows below with the hospital's real
 * organizational chart and plantilla before the first import.
 */
class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $divisions = [
            'ADMIN' => 'Administrative Division',
            'MED'   => 'Medical Division',
        ];

        foreach ($divisions as $code => $name) {
            Division::firstOrCreate(['code' => $code], ['name' => $name]);
        }

        $sections = [
            'STAT' => ['ADMIN', 'Statistics Unit'],
            'NURS' => ['MED', 'Nursing Service'],
        ];

        foreach ($sections as $code => [$divisionCode, $name]) {
            Section::firstOrCreate(['code' => $code], [
                'division_id' => Division::where('code', $divisionCode)->value('id'),
                'name' => $name,
            ]);
        }

        $positions = [
            ['Statistician II', 'OSEC-DOHB-STAT2-97-2014', 15],
            ['Nurse I', 'OSEC-DOHB-NUR1-314-2014', 15],
        ];

        foreach ($positions as [$title, $itemNumber, $salaryGrade]) {
            Position::firstOrCreate(['item_number' => $itemNumber], [
                'title' => $title,
                'salary_grade' => $salaryGrade,
            ]);
        }
    }
}
```

Register it in `DatabaseSeeder::run()` after `RoleSeeder`:

```php
$this->call(OrganizationSeeder::class);
```

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add app/Enums app/Models database/migrations database/factories database/seeders tests/Feature/OrganizationTest.php
git commit -m "Add the organizational schema copied from the IPCR system"
```

---

## Task 3: The employee master

**Files:**
- Create: `app/Enums/EmploymentStatus.php`
- Create: `database/migrations/*_create_employees_table.php`
- Create: `database/migrations/*_add_head_columns_to_org_tables.php`
- Create: `database/migrations/*_create_employee_designations_table.php`
- Create: `app/Models/Employee.php`
- Create: `app/Models/EmployeeDesignation.php`
- Create: `database/factories/EmployeeFactory.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/Division.php`, `app/Models/Section.php`
- Test: `tests/Feature/EmployeeTest.php`

**Interfaces:**
- Consumes: `Position`, `Division`, `Section`, `Designation` from Task 2.
- Produces: `Employee` with `$fillable` covering `user_id`, `employee_number`, `first_name`, `middle_name`, `last_name`, `suffix`, `position_id`, `section_id`, `division_id`, `is_chief_of_hospital`, `date_hired`, `employment_status`, `biometric_id`, `is_active`. Relations: `Employee::user(): BelongsTo`, `Employee::position(): BelongsTo`, `Employee::section(): BelongsTo`, `Employee::division(): BelongsTo`, `Employee::designations(): BelongsToMany`. Reverse: `User::employee(): HasOne`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EmployeeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_employee_exists_without_a_login(): void
    {
        // The CSV import runs before any account is issued. This must hold.
        $employee = Employee::factory()->create(['user_id' => null]);

        $this->assertNull($employee->user);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'user_id' => null]);
    }

    public function test_a_login_belongs_to_exactly_one_employee(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->refresh()->employee->is($employee));

        $this->expectException(QueryException::class);

        Employee::factory()->create(['user_id' => $user->id]);
    }

    public function test_an_employee_number_is_unique(): void
    {
        Employee::factory()->create(['employee_number' => '2014-0042']);

        $this->expectException(QueryException::class);

        Employee::factory()->create(['employee_number' => '2014-0042']);
    }

    public function test_an_employee_is_soft_deleted(): void
    {
        // Records hang off this row for years. Never hard-delete.
        $employee = Employee::factory()->create();

        $employee->delete();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EmployeeTest`
Expected: FAIL with `Class "App\Models\Employee" not found`.

- [ ] **Step 3: Write the enum**

Create `app/Enums/EmploymentStatus.php`:

```php
<?php

namespace App\Enums;

/**
 * How the hospital engages a person. Only these three — the full CSC
 * vocabulary carries statuses the hospital does not hire under, and every
 * one of them is a wrong answer sitting in a dropdown waiting to be picked.
 */
enum EmploymentStatus: string
{
    case Permanent         = 'permanent';
    case JobOrder          = 'job_order';
    case ContractOfService = 'contract_of_service';

    /** Written the way it appears on the appointment paper. */
    public function label(): string
    {
        return match ($this) {
            self::Permanent         => 'Permanent',
            self::JobOrder          => 'Job Order',
            self::ContractOfService => 'Contract of Service',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 4: Write the three migrations**

`create_employees_table`:

```php
Schema::create('employees', function (Blueprint $table) {
    $table->id();

    // Login account. Nullable because an employee record can exist before an account is issued.
    $table->foreignId('user_id')->nullable()->unique()
        ->constrained()->nullOnDelete();

    $table->string('employee_number', 50)->unique();
    $table->string('first_name');
    $table->string('middle_name')->nullable();
    $table->string('last_name');
    $table->string('suffix', 20)->nullable();          // Jr., III

    // Exactly ONE plantilla position
    $table->foreignId('position_id')->nullable()
        ->constrained()->nullOnDelete();

    $table->foreignId('section_id')->nullable()
        ->constrained()->nullOnDelete();
    $table->foreignId('division_id')->nullable()
        ->constrained()->nullOnDelete();

    $table->boolean('is_chief_of_hospital')->default(false);

    $table->date('date_hired')->nullable();
    $table->string('employment_status', 30)->default('permanent');

    // Matches an employee to their row in the biometric device export (Phase 2).
    $table->string('biometric_id', 50)->nullable()->unique();

    $table->boolean('is_active')->default(true);

    $table->timestamps();
    $table->softDeletes();   // never hard-delete: records hang off this for years

    $table->index(['last_name', 'first_name']);
    $table->index('is_active');
    $table->index('is_chief_of_hospital');
});
```

`add_head_columns_to_org_tables` — must run after `employees`:

```php
public function up(): void
{
    Schema::table('divisions', function (Blueprint $table) {
        $table->foreignId('division_head_employee_id')->nullable()->after('code')
            ->constrained('employees')->nullOnDelete();
    });

    Schema::table('sections', function (Blueprint $table) {
        $table->foreignId('section_head_employee_id')->nullable()->after('code')
            ->constrained('employees')->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('divisions', function (Blueprint $table) {
        $table->dropConstrainedForeignId('division_head_employee_id');
    });

    Schema::table('sections', function (Blueprint $table) {
        $table->dropConstrainedForeignId('section_head_employee_id');
    });
}
```

`create_employee_designations_table`:

```php
Schema::create('employee_designations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
    $table->foreignId('designation_id')->constrained()->restrictOnDelete();
    $table->date('start_date')->nullable();
    $table->date('end_date')->nullable();               // null = still current
    $table->string('order_reference')->nullable();      // Office Order / Special Order no.
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['employee_id', 'designation_id', 'start_date'], 'emp_desig_unique');
    $table->index(['employee_id', 'is_active']);
});
```

- [ ] **Step 5: Write the models**

Create `app/Models/Employee.php`:

```php
<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'employee_number',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'position_id',
        'section_id',
        'division_id',
        'is_chief_of_hospital',
        'date_hired',
        'employment_status',
        'biometric_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_chief_of_hospital' => 'boolean',
            'is_active'            => 'boolean',
            'date_hired'           => 'date',
            'employment_status'    => EmploymentStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Exactly ONE plantilla position. */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** Every designation, including ones that have ended. */
    public function designations(): BelongsToMany
    {
        return $this->belongsToMany(Designation::class, 'employee_designations')
            ->withPivot(['start_date', 'end_date', 'order_reference', 'is_active'])
            ->withTimestamps();
    }

    /** Surname first, the way HR reads a list. */
    public function fullName(): string
    {
        $name = "{$this->last_name}, {$this->first_name}";

        if ($this->middle_name) {
            $name .= ' '.mb_substr($this->middle_name, 0, 1).'.';
        }

        return $this->suffix ? "{$name} {$this->suffix}" : $name;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
```

Create `app/Models/EmployeeDesignation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDesignation extends Model
{
    protected $fillable = [
        'employee_id',
        'designation_id',
        'start_date',
        'end_date',
        'order_reference',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date'   => 'date',
            'is_active'  => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
}
```

- [ ] **Step 6: Add the reverse relations**

In `app/Models/User.php`:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }
```

In `app/Models/Division.php`:

```php
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'division_head_employee_id');
    }
```

Add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` to `Division`, and add `'division_head_employee_id'` to its `$fillable`.

In `app/Models/Section.php`:

```php
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'section_head_employee_id');
    }
```

Add `'section_head_employee_id'` to `Section`'s `$fillable`.

- [ ] **Step 7: Write the factory**

Create `database/factories/EmployeeFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Models\Position;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => null,
            'employee_number' => $this->faker->unique()->numerify('20##-####'),
            'first_name' => $this->faker->firstName(),
            'middle_name' => $this->faker->lastName(),
            'last_name' => $this->faker->lastName(),
            'suffix' => null,
            'position_id' => Position::factory(),
            'section_id' => Section::factory(),
            'division_id' => null,
            'is_chief_of_hospital' => false,
            'date_hired' => $this->faker->dateTimeBetween('-15 years'),
            'employment_status' => EmploymentStatus::Permanent->value,
            'biometric_id' => $this->faker->unique()->numerify('####'),
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 8: Run the migration and the test**

```bash
php artisan migrate
php artisan test --filter=EmployeeTest
```

Expected: PASS, 4 tests.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add app database/migrations database/factories tests/Feature/EmployeeTest.php
git commit -m "Add the employee master and its designation pivot"
```

---

## Task 4: Close public registration

**Files:**
- Modify: `config/fortify.php:164`
- Modify: `resources/views/pages/auth/login.blade.php`
- Test: `tests/Feature/Auth/RegistrationClosedTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: the named route `register` no longer exists. Anything referencing it must guard with `Route::has('register')`.

Anyone on the hospital LAN can currently create an account and reach the inside of the system. The records this system will hold are TIN, home address, and the answers to PDS items 34–40. Registration closes before any of that is built.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/RegistrationClosedTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RegistrationClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));
    }

    public function test_the_registration_endpoint_cannot_be_reached(): void
    {
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }

    public function test_no_account_was_created(): void
    {
        $this->post('/register', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'walkin@example.com']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=RegistrationClosedTest`
Expected: FAIL — `Route::has('register')` is currently true and `/register` returns 200.

- [ ] **Step 3: Remove the feature**

In `config/fortify.php`, delete the `Features::registration(),` line from the `features` array. Leave `resetPasswords`, `emailVerification`, `twoFactorAuthentication` and `passkeys` in place.

- [ ] **Step 4: Remove the link from the login page**

In `resources/views/pages/auth/login.blade.php`, delete lines 54–57 exactly:

```blade
        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </div>
```

If it is left in place, the page throws `Route [register] not defined` and nobody can log in.

- [ ] **Step 5: Remove the view binding**

In `app/Providers/FortifyServiceProvider.php`, delete line 52:

```php
Fortify::registerView(fn () => view('pages::auth.register'));
```

The view is deleted in Step 7 below; a binding pointing at a file that no longer exists is a trap for whoever re-enables the feature. Leave `Fortify::createUsersUsing(CreateNewUser::class)` on line 40 alone — it is inert while the feature is off, and it is what an admin-side account creator would reuse.

- [ ] **Step 6: Run the whole suite**

Run: `php artisan test`
Expected: PASS. `tests/Feature/Auth/RegistrationTest.php` reports as **skipped** — the starter kit's `TestCase::skipUnlessFortifyHas()` handles it, so that file needs no edit. Do not delete it; it documents the feature and will come back if the decision is ever reversed.

- [ ] **Step 7: Delete the registration page view**

```bash
git rm resources/views/pages/auth/register.blade.php
```

Nothing routes to it now. Leaving it invites someone to wire it back up.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add config/fortify.php app/Providers/FortifyServiceProvider.php resources/views/pages/auth tests/Feature/Auth/RegistrationClosedTest.php
git commit -m "Close public registration"
```

---

## Task 5: The employee policy and the HR employee list

**Files:**
- Create: `app/Policies/EmployeePolicy.php`
- Create: `resources/views/pages/employees/⚡index.blade.php`
- Create: `routes/employees.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/EmployeeAuthorizationTest.php`

**Interfaces:**
- Consumes: `Employee`, `User::employee()`, the roles from Task 1.
- Produces: `EmployeePolicy` with `viewAny(User): bool`, `view(User, Employee): bool`, `update(User, Employee): bool`, `import(User): bool`, `issueAccount(User): bool`. Named route `employees.index` at `/employees`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EmployeeAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_an_employee_cannot_open_the_employee_list(): void
    {
        $user = $this->userWithRole('employee');
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get(route('employees.index'))->assertForbidden();
    }

    public function test_hr_can_open_the_employee_list(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.index'))
            ->assertOk();
    }

    public function test_an_employee_reaches_their_own_record(): void
    {
        $user = $this->userWithRole('employee');
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->can('view', $own));
    }

    public function test_an_employee_cannot_reach_another_record(): void
    {
        // This is the IDOR case. It must fail.
        $user = $this->userWithRole('employee');
        Employee::factory()->create(['user_id' => $user->id]);
        $someoneElse = Employee::factory()->create();

        $this->assertFalse($user->can('view', $someoneElse));
    }

    public function test_hr_reaches_any_record(): void
    {
        $hr = $this->userWithRole('hr');
        $someoneElse = Employee::factory()->create();

        $this->assertTrue($hr->can('view', $someoneElse));
        $this->assertTrue($hr->can('update', $someoneElse));
    }

    public function test_hr_cannot_issue_accounts(): void
    {
        $this->assertFalse($this->userWithRole('hr')->can('issueAccount', Employee::class));
        $this->assertTrue($this->userWithRole('admin')->can('issueAccount', Employee::class));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EmployeeAuthorizationTest`
Expected: FAIL with `Route [employees.index] not defined`.

- [ ] **Step 3: Write the policy**

Create `app/Policies/EmployeePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Ownership lives here, not in a permission. A permission cannot see which
 * record is being requested, which is exactly how IDOR gets in.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->owns($user, $employee) || $user->can('employees.view');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can('employees.manage');
    }

    public function import(User $user): bool
    {
        return $user->can('employees.import');
    }

    public function issueAccount(User $user): bool
    {
        return $user->can('users.manage');
    }

    private function owns(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null && $employee->user_id === $user->id;
    }
}
```

Laravel discovers policies in `app/Policies` by model name; no registration is needed.

- [ ] **Step 4: Write the page component**

Create `resources/views/pages/employees/⚡index.blade.php`:

```blade
<?php

use App\Models\Employee;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Employees')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Employee::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'employees' => Employee::query()
                ->with(['position', 'section.division'])
                ->when($this->search !== '', function ($query) {
                    $term = '%'.$this->search.'%';
                    $query->where(function ($q) use ($term) {
                        $q->where('last_name', 'like', $term)
                            ->orWhere('first_name', 'like', $term)
                            ->orWhere('employee_number', 'like', $term);
                    });
                })
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->paginate(25),
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Employees') }}</flux:heading>

    <div class="mt-6 max-w-sm">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :placeholder="__('Search by name or employee number')"
            icon="magnifying-glass"
        />
    </div>

    <flux:table class="mt-6" :paginate="$employees">
        <flux:table.columns>
            <flux:table.column>{{ __('Employee No.') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Position') }}</flux:table.column>
            <flux:table.column>{{ __('Section') }}</flux:table.column>
            <flux:table.column>{{ __('Account') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($employees as $employee)
                <flux:table.row :key="$employee->id">
                    <flux:table.cell>{{ $employee->employee_number }}</flux:table.cell>
                    <flux:table.cell>{{ $employee->fullName() }}</flux:table.cell>
                    <flux:table.cell>{{ $employee->position?->title }}</flux:table.cell>
                    <flux:table.cell>{{ $employee->section?->name }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($employee->user_id)
                            <flux:badge color="green" size="sm">{{ __('Issued') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('None') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
```

`$this->authorize()` in `mount()` is what turns the policy into a 403. Without it the policy exists and protects nothing.

- [ ] **Step 5: Write the routes**

Create `routes/employees.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('employees', 'pages::employees.index')->name('employees.index');
});
```

In `routes/web.php`, add below the existing require:

```php
require __DIR__.'/employees.php';
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=EmployeeAuthorizationTest`
Expected: PASS, 6 tests.

- [ ] **Step 7: Add the sidebar link**

In `resources/views/layouts/app/sidebar.blade.php`, inside the existing navlist, add an entry that hides itself from anyone who cannot open it:

```blade
@can('viewAny', App\Models\Employee::class)
    <flux:navlist.item icon="users" :href="route('employees.index')" :current="request()->routeIs('employees.*')" wire:navigate>
        {{ __('Employees') }}
    </flux:navlist.item>
@endcan
```

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Policies routes resources/views tests/Feature/EmployeeAuthorizationTest.php
git commit -m "Add the employee policy and the HR employee list"
```

---

## Task 6: The CSV parser

**Files:**
- Create: `app/Services/EmployeeImport/CsvRow.php`
- Create: `app/Services/EmployeeImport/ImportPreview.php`
- Create: `app/Services/EmployeeImport/EmployeeCsvParser.php`
- Test: `tests/Feature/EmployeeImport/EmployeeCsvParserTest.php`

**Interfaces:**
- Consumes: `Employee`, `Division`, `Section`, `Position`, `EmploymentStatus`.
- Produces:
  - `CsvRow` — readonly, with `int $lineNumber`, `array<string,string> $data`, `list<string> $errors`, and `isValid(): bool`.
  - `ImportPreview` — readonly, with `list<CsvRow> $rows`, `validRows(): list<CsvRow>`, `invalidRows(): list<CsvRow>`, `hasErrors(): bool`.
  - `EmployeeCsvParser::COLUMNS` (`list<string>`) and `EmployeeCsvParser::parse(string $path): ImportPreview`.

The parser writes nothing. That is the whole point: preview-before-write is enforced by the shape of the code, not by remembering to click the right button.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EmployeeImport/EmployeeCsvParserTest.php`:

```php
<?php

namespace Tests\Feature\EmployeeImport;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use App\Services\EmployeeImport\EmployeeCsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeCsvParserTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, implode(',', EmployeeCsvParser::COLUMNS)."\n".$body);

        return $path;
    }

    private function seedReferenceData(): void
    {
        $division = Division::factory()->create(['code' => 'ADMIN']);
        Section::factory()->create(['code' => 'STAT', 'division_id' => $division->id]);
        Position::factory()->create(['title' => 'Statistician II']);
    }

    public function test_a_clean_row_parses_without_errors(): void
    {
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
        ));

        $this->assertFalse($preview->hasErrors());
        $this->assertCount(1, $preview->validRows());
        $this->assertSame('Dela Cruz', $preview->rows[0]->data['last_name']);
        $this->assertSame(2, $preview->rows[0]->lineNumber);
    }

    public function test_a_missing_required_field_is_reported_with_its_line(): void
    {
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
        ));

        $this->assertTrue($preview->hasErrors());
        $this->assertSame(2, $preview->invalidRows()[0]->lineNumber);
        $this->assertContains('last_name is required', $preview->invalidRows()[0]->errors);
    }

    public function test_an_unknown_division_code_is_reported(): void
    {
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,NOPE,STAT,permanent,2014-06-01,1042'
        ));

        $this->assertContains('division_code [NOPE] does not exist', $preview->invalidRows()[0]->errors);
    }

    public function test_an_employee_number_already_in_the_database_is_reported(): void
    {
        $this->seedReferenceData();
        Employee::factory()->create(['employee_number' => '2014-0042']);

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,'
        ));

        $this->assertContains('employee_number [2014-0042] already exists', $preview->invalidRows()[0]->errors);
    }

    public function test_an_employee_number_repeated_inside_the_file_is_reported(): void
    {
        // The database cannot catch this one — neither row is written yet.
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            "2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,\n".
            '2014-0042,Maria,Reyes,Bautista,,Statistician II,ADMIN,STAT,permanent,2015-01-05,'
        ));

        $this->assertContains('employee_number [2014-0042] is repeated on line 2', $preview->rows[1]->errors);
    }

    public function test_an_unknown_employment_status_is_reported(): void
    {
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,casual,2014-06-01,'
        ));

        $this->assertContains(
            'employment_status [casual] is not one of: permanent, job_order, contract_of_service',
            $preview->invalidRows()[0]->errors
        );
    }

    public function test_a_wrong_header_is_rejected_outright(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, "name,email\nJuan,juan@example.com");

        $preview = app(EmployeeCsvParser::class)->parse($path);

        $this->assertTrue($preview->hasErrors());
        $this->assertSame(1, $preview->rows[0]->lineNumber);
        $this->assertStringContainsString('header does not match', $preview->rows[0]->errors[0]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EmployeeCsvParserTest`
Expected: FAIL with `Class "App\Services\EmployeeImport\EmployeeCsvParser" not found`.

- [ ] **Step 3: Write the two value objects**

Create `app/Services/EmployeeImport/CsvRow.php`:

```php
<?php

namespace App\Services\EmployeeImport;

/** One line of the uploaded file, and whatever is wrong with it. */
final readonly class CsvRow
{
    /**
     * @param  array<string,string>  $data
     * @param  list<string>  $errors
     */
    public function __construct(
        public int $lineNumber,
        public array $data,
        public array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
```

Create `app/Services/EmployeeImport/ImportPreview.php`:

```php
<?php

namespace App\Services\EmployeeImport;

/** Everything the parser read, with nothing written. */
final readonly class ImportPreview
{
    /** @param  list<CsvRow>  $rows */
    public function __construct(public array $rows) {}

    /** @return list<CsvRow> */
    public function validRows(): array
    {
        return array_values(array_filter($this->rows, fn (CsvRow $row) => $row->isValid()));
    }

    /** @return list<CsvRow> */
    public function invalidRows(): array
    {
        return array_values(array_filter($this->rows, fn (CsvRow $row) => ! $row->isValid()));
    }

    public function hasErrors(): bool
    {
        return $this->invalidRows() !== [];
    }
}
```

- [ ] **Step 4: Write the parser**

Create `app/Services/EmployeeImport/EmployeeCsvParser.php`:

```php
<?php

namespace App\Services\EmployeeImport;

use App\Enums\EmploymentStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use SplFileObject;

/**
 * Reads an uploaded CSV and says what is wrong with it. Writes nothing —
 * that is EmployeeImporter's job, and it only accepts what came from here.
 */
class EmployeeCsvParser
{
    /** @var list<string> */
    public const COLUMNS = [
        'employee_number',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'position_title',
        'division_code',
        'section_code',
        'employment_status',
        'date_hired',
        'biometric_id',
    ];

    /** @var list<string> */
    private const REQUIRED = [
        'employee_number',
        'first_name',
        'last_name',
    ];

    public function parse(string $path): ImportPreview
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $header = $file->fgetcsv();

        if ($header !== self::COLUMNS) {
            return new ImportPreview([new CsvRow(1, [], [
                'The header does not match the expected columns: '.implode(', ', self::COLUMNS),
            ])]);
        }

        $divisions = Division::pluck('id', 'code')->all();
        $sections = Section::pluck('id', 'code')->all();
        $positions = Position::pluck('id', 'title')->all();
        $takenNumbers = Employee::withTrashed()->pluck('employee_number')->all();

        $rows = [];
        $seen = [];
        $lineNumber = 1;

        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $lineNumber++;

            if ($values === [null] || $values === false) {
                continue;
            }

            $data = array_combine(
                self::COLUMNS,
                array_map(
                    fn ($value) => trim((string) $value),
                    array_pad(array_slice($values, 0, count(self::COLUMNS)), count(self::COLUMNS), '')
                )
            );

            $errors = $this->errorsFor($data, $divisions, $sections, $positions, $takenNumbers, $seen);

            if ($data['employee_number'] !== '' && ! isset($seen[$data['employee_number']])) {
                $seen[$data['employee_number']] = $lineNumber;
            }

            $rows[] = new CsvRow($lineNumber, $data, $errors);
        }

        return new ImportPreview($rows);
    }

    /**
     * @param  array<string,string>  $data
     * @param  array<string,int>  $divisions
     * @param  array<string,int>  $sections
     * @param  array<string,int>  $positions
     * @param  list<string>  $takenNumbers
     * @param  array<string,int>  $seen
     * @return list<string>
     */
    private function errorsFor(
        array $data,
        array $divisions,
        array $sections,
        array $positions,
        array $takenNumbers,
        array $seen,
    ): array {
        $errors = [];

        foreach (self::REQUIRED as $column) {
            if ($data[$column] === '') {
                $errors[] = "{$column} is required";
            }
        }

        $number = $data['employee_number'];

        if ($number !== '' && in_array($number, $takenNumbers, true)) {
            $errors[] = "employee_number [{$number}] already exists";
        }

        if ($number !== '' && isset($seen[$number])) {
            $errors[] = "employee_number [{$number}] is repeated on line {$seen[$number]}";
        }

        if ($data['division_code'] !== '' && ! isset($divisions[$data['division_code']])) {
            $errors[] = "division_code [{$data['division_code']}] does not exist";
        }

        if ($data['section_code'] !== '' && ! isset($sections[$data['section_code']])) {
            $errors[] = "section_code [{$data['section_code']}] does not exist";
        }

        if ($data['position_title'] !== '' && ! isset($positions[$data['position_title']])) {
            $errors[] = "position_title [{$data['position_title']}] does not exist";
        }

        if ($data['employment_status'] !== ''
            && ! in_array($data['employment_status'], EmploymentStatus::values(), true)) {
            $errors[] = "employment_status [{$data['employment_status']}] is not one of: "
                .implode(', ', EmploymentStatus::values());
        }

        if ($data['date_hired'] !== '' && strtotime($data['date_hired']) === false) {
            $errors[] = "date_hired [{$data['date_hired']}] is not a date";
        }

        return $errors;
    }
}
```

A position, division or section must already exist — the importer never creates them. A plantilla position carries an item number and a salary grade that a title alone cannot supply, and inventing one silently is worse than refusing the row. Reference data comes from `OrganizationSeeder`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=EmployeeCsvParserTest`
Expected: PASS, 7 tests.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/EmployeeImport tests/Feature/EmployeeImport
git commit -m "Add the employee CSV parser"
```

---

## Task 7: The importer and its screen

**Files:**
- Create: `app/Services/EmployeeImport/EmployeeImporter.php`
- Create: `resources/views/pages/employees/⚡import.blade.php`
- Modify: `routes/employees.php`
- Test: `tests/Feature/EmployeeImport/EmployeeImporterTest.php`
- Test: `tests/Feature/EmployeeImport/ImportScreenTest.php`

**Interfaces:**
- Consumes: `ImportPreview`, `CsvRow`, `EmployeeCsvParser::COLUMNS`, `EmployeePolicy::import()`.
- Produces: `EmployeeImporter::import(ImportPreview $preview): int` returning the number of employees created, and throwing `InvalidArgumentException` when the preview has any error. Named route `employees.import`.

**The import is all-or-nothing.** If any row has an error, nothing is written. Importing the good half of a file leaves HR with no way to know which half they now have to fix by hand.

- [ ] **Step 1: Write the failing importer test**

Create `tests/Feature/EmployeeImport/EmployeeImporterTest.php`:

```php
<?php

namespace Tests\Feature\EmployeeImport;

use App\Enums\EmploymentStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use App\Services\EmployeeImport\CsvRow;
use App\Services\EmployeeImport\EmployeeImporter;
use App\Services\EmployeeImport\ImportPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EmployeeImporterTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string,string> $overrides */
    private function row(int $line, array $overrides = [], array $errors = []): CsvRow
    {
        return new CsvRow($line, array_merge([
            'employee_number' => '2014-0042',
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'suffix' => '',
            'position_title' => 'Statistician II',
            'division_code' => 'ADMIN',
            'section_code' => 'STAT',
            'employment_status' => 'permanent',
            'date_hired' => '2014-06-01',
            'biometric_id' => '1042',
        ], $overrides), $errors);
    }

    private function seedReferenceData(): void
    {
        $division = Division::factory()->create(['code' => 'ADMIN']);
        Section::factory()->create(['code' => 'STAT', 'division_id' => $division->id]);
        Position::factory()->create(['title' => 'Statistician II']);
    }

    public function test_it_creates_an_employee_with_its_references_resolved(): void
    {
        $this->seedReferenceData();

        $created = app(EmployeeImporter::class)->import(new ImportPreview([$this->row(2)]));

        $this->assertSame(1, $created);

        $employee = Employee::firstWhere('employee_number', '2014-0042');

        $this->assertSame('Dela Cruz', $employee->last_name);
        $this->assertSame('STAT', $employee->section->code);
        $this->assertSame('ADMIN', $employee->division->code);
        $this->assertSame('Statistician II', $employee->position->title);
        $this->assertSame(EmploymentStatus::Permanent, $employee->employment_status);
        $this->assertNull($employee->user_id);
    }

    public function test_it_refuses_a_preview_that_has_any_error(): void
    {
        $this->seedReferenceData();

        $preview = new ImportPreview([
            $this->row(2),
            $this->row(3, ['employee_number' => '2015-0100'], ['last_name is required']),
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(EmployeeImporter::class)->import($preview);
    }

    public function test_a_refused_import_writes_nothing_at_all(): void
    {
        $this->seedReferenceData();

        $preview = new ImportPreview([
            $this->row(2),
            $this->row(3, ['employee_number' => '2015-0100'], ['last_name is required']),
        ]);

        try {
            app(EmployeeImporter::class)->import($preview);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, Employee::count());
    }

    public function test_a_blank_optional_column_becomes_null_not_an_empty_string(): void
    {
        $this->seedReferenceData();

        app(EmployeeImporter::class)->import(new ImportPreview([
            $this->row(2, ['suffix' => '', 'biometric_id' => '', 'date_hired' => '']),
        ]));

        $employee = Employee::firstWhere('employee_number', '2014-0042');

        $this->assertNull($employee->suffix);
        $this->assertNull($employee->biometric_id);
        $this->assertNull($employee->date_hired);
    }
}
```

The last test matters more than it looks. `biometric_id` is unique — a second employee with `''` rather than `null` would collide, and the failure would surface as an unrelated duplicate-key error halfway through a 500-row import.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EmployeeImporterTest`
Expected: FAIL with `Class "App\Services\EmployeeImport\EmployeeImporter" not found`.

- [ ] **Step 3: Write the importer**

Create `app/Services/EmployeeImport/EmployeeImporter.php`:

```php
<?php

namespace App\Services\EmployeeImport;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only thing that writes employees. It accepts an ImportPreview and
 * nothing else, so nothing reaches the database without having been parsed
 * and shown first.
 */
class EmployeeImporter
{
    /**
     * @return int the number of employees created
     *
     * @throws InvalidArgumentException when any row in the preview has an error
     */
    public function import(ImportPreview $preview): int
    {
        if ($preview->hasErrors()) {
            throw new InvalidArgumentException(
                'This file still has '.count($preview->invalidRows()).' row(s) with errors.'
            );
        }

        $divisions = Division::pluck('id', 'code')->all();
        $sections = Section::pluck('id', 'code')->all();
        $positions = Position::pluck('id', 'title')->all();

        return DB::transaction(function () use ($preview, $divisions, $sections, $positions) {
            $created = 0;

            foreach ($preview->rows as $row) {
                $data = $row->data;

                Employee::create([
                    'employee_number' => $data['employee_number'],
                    'first_name' => $data['first_name'],
                    'middle_name' => $this->nullIfBlank($data['middle_name']),
                    'last_name' => $data['last_name'],
                    'suffix' => $this->nullIfBlank($data['suffix']),
                    'position_id' => $positions[$data['position_title']] ?? null,
                    'division_id' => $divisions[$data['division_code']] ?? null,
                    'section_id' => $sections[$data['section_code']] ?? null,
                    'employment_status' => $data['employment_status'] ?: 'permanent',
                    'date_hired' => $this->nullIfBlank($data['date_hired']),
                    'biometric_id' => $this->nullIfBlank($data['biometric_id']),
                    'is_active' => true,
                ]);

                $created++;
            }

            return $created;
        });
    }

    private function nullIfBlank(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=EmployeeImporterTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Write the failing screen test**

Create `tests/Feature/EmployeeImport/ImportScreenTest.php`:

```php
<?php

namespace Tests\Feature\EmployeeImport;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use App\Models\User;
use App\Services\EmployeeImport\EmployeeCsvParser;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ImportScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $division = Division::factory()->create(['code' => 'ADMIN']);
        Section::factory()->create(['code' => 'STAT', 'division_id' => $division->id]);
        Position::factory()->create(['title' => 'Statistician II']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function upload(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'employees.csv',
            implode(',', EmployeeCsvParser::COLUMNS)."\n".$body
        );
    }

    public function test_an_employee_cannot_open_the_import_screen(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $this->actingAs($user)->get(route('employees.import'))->assertForbidden();
    }

    public function test_uploading_shows_a_preview_and_writes_nothing(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
            ))
            ->assertSet('previewRows.0.line', 2)
            ->assertSet('previewRows.0.name', 'Dela Cruz, Juan')
            ->assertSet('previewRows.0.errors', [])
            ->assertSet('validCount', 1)
            ->assertSet('errorCount', 0);

        $this->assertSame(0, Employee::count());
    }

    public function test_committing_a_clean_preview_writes_the_employees(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
            ))
            ->call('commit')
            ->assertHasNoErrors();

        $this->assertSame(1, Employee::count());
        $this->assertDatabaseHas('employees', ['employee_number' => '2014-0042']);
    }

    public function test_a_preview_with_errors_cannot_be_committed(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                '2014-0042,Juan,Santos,,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
            ))
            ->call('commit')
            ->assertHasErrors('file');

        $this->assertSame(0, Employee::count());
    }

    public function test_a_row_that_became_a_duplicate_after_the_preview_is_caught_at_commit(): void
    {
        // Someone else imported the same employee between the preview and the
        // confirmation. The commit re-parses, so it is caught.
        $component = Livewire::actingAs($this->admin())
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
            ))
            ->assertSet('errorCount', 0);

        Employee::factory()->create(['employee_number' => '2014-0042']);

        $component->call('commit')->assertHasErrors('file');

        $this->assertSame(1, Employee::count());
    }
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `php artisan test --filter=ImportScreenTest`
Expected: FAIL with `Route [employees.import] not defined`.

- [ ] **Step 7: Write the import screen**

Create `resources/views/pages/employees/⚡import.blade.php`:

```blade
<?php

use App\Models\Employee;
use App\Services\EmployeeImport\CsvRow;
use App\Services\EmployeeImport\EmployeeCsvParser;
use App\Services\EmployeeImport\EmployeeImporter;
use App\Services\EmployeeImport\ImportPreview;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Import employees')] class extends Component {
    use WithFileUploads;

    public $file = null;

    /**
     * Flattened for display only. A Livewire public property must survive a
     * round trip through JSON, so ImportPreview and CsvRow cannot live here —
     * Livewire supports scalars, arrays, Collections and Eloquent models, and
     * throws on a plain PHP object. The value objects stay inside the methods.
     *
     * @var list<array{line:int,employee_number:string,name:string,errors:list<string>}>
     */
    public array $previewRows = [];

    public int $validCount = 0;

    public int $errorCount = 0;

    public ?int $imported = null;

    public function mount(): void
    {
        $this->authorize('import', Employee::class);
    }

    /** Parsing happens the moment a file arrives. Nothing is written. */
    public function updatedFile(): void
    {
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $this->imported = null;
        $this->show(app(EmployeeCsvParser::class)->parse($this->file->getRealPath()));
    }

    public function commit(): void
    {
        $this->authorize('import', Employee::class);

        if ($this->file === null) {
            $this->addError('file', __('Upload a file first.'));

            return;
        }

        // Re-parse rather than trusting the preview. Between the upload and
        // this click, someone else may have imported one of these employee
        // numbers — and the preview cannot know that.
        $preview = app(EmployeeCsvParser::class)->parse($this->file->getRealPath());

        if ($preview->hasErrors()) {
            $this->show($preview);
            $this->addError('file', __('Fix every row listed below before importing.'));

            return;
        }

        $this->imported = app(EmployeeImporter::class)->import($preview);

        $this->reset('file', 'previewRows', 'validCount', 'errorCount');
    }

    private function show(ImportPreview $preview): void
    {
        $this->previewRows = array_map(fn (CsvRow $row) => [
            'line' => $row->lineNumber,
            'employee_number' => $row->data['employee_number'] ?? '',
            'name' => trim(($row->data['last_name'] ?? '').', '.($row->data['first_name'] ?? ''), ', '),
            'errors' => $row->errors,
        ], $preview->rows);

        $this->validCount = count($preview->validRows());
        $this->errorCount = count($preview->invalidRows());
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Import employees') }}</flux:heading>
    <flux:subheading>
        {{ __('Upload a CSV. Nothing is written until you review the preview and confirm.') }}
    </flux:subheading>

    <div class="mt-6 max-w-xl">
        <flux:input type="file" wire:model="file" :label="__('CSV file')" accept=".csv" />
        <flux:error name="file" />

        <flux:text class="mt-2 text-sm">
            {{ __('Columns, in order:') }}
            <code>{{ implode(', ', App\Services\EmployeeImport\EmployeeCsvParser::COLUMNS) }}</code>
        </flux:text>
    </div>

    @if ($imported !== null)
        <flux:callout class="mt-6" variant="success" icon="check-circle">
            {{ __(':count employees imported.', ['count' => $imported]) }}
        </flux:callout>
    @endif

    @if ($previewRows !== [])
        <div class="mt-8">
            <flux:heading size="lg">
                {{ __(':valid ready, :invalid with errors', [
                    'valid' => $validCount,
                    'invalid' => $errorCount,
                ]) }}
            </flux:heading>

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('Line') }}</flux:table.column>
                    <flux:table.column>{{ __('Employee No.') }}</flux:table.column>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Problems') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($previewRows as $row)
                        <flux:table.row wire:key="row-{{ $row['line'] }}">
                            <flux:table.cell>{{ $row['line'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['employee_number'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($row['errors'] === [])
                                    <flux:badge color="green" size="sm">{{ __('OK') }}</flux:badge>
                                @else
                                    <ul class="text-sm text-red-600 dark:text-red-400">
                                        @foreach ($row['errors'] as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <flux:button
                class="mt-6"
                variant="primary"
                wire:click="commit"
                :disabled="$errorCount > 0"
            >
                {{ __('Import :count employees', ['count' => $validCount]) }}
            </flux:button>
        </div>
    @endif
</section>
```

The button is disabled when the preview has errors, and `commit()` checks again on the server. The disabled attribute is a courtesy; the server-side check is the rule.

- [ ] **Step 8: Add the route**

In `routes/employees.php`, inside the existing group:

```php
Route::livewire('employees/import', 'pages::employees.import')->name('employees.import');
```

Put it **above** any route with a wildcard segment, or `import` will be swallowed as an employee id later.

- [ ] **Step 9: Run the test to verify it passes**

Run: `php artisan test --filter=ImportScreenTest`
Expected: PASS, 5 tests.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/EmployeeImport resources/views/pages/employees routes/employees.php tests/Feature/EmployeeImport
git commit -m "Add the employee import screen with a preview step"
```

---

## Task 8: Issuing logins

**Files:**
- Create: `database/migrations/*_add_must_change_password_to_users_table.php`
- Create: `app/Http/Middleware/EnsurePasswordHasBeenChanged.php`
- Create: `resources/views/pages/employees/⚡issue-account.blade.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/employees.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/IssueAccountTest.php`

**Interfaces:**
- Consumes: `Employee`, `EmployeePolicy::issueAccount()`.
- Produces: `users.must_change_password` (boolean, default false), `EnsurePasswordHasBeenChanged` appended to the `web` middleware group, named route `employees.issue-account`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/IssueAccountTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class IssueAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_hr_cannot_open_the_issue_account_screen(): void
    {
        $user = User::factory()->create();
        $user->assignRole('hr');

        $this->actingAs($user)->get(route('employees.issue-account'))->assertForbidden();
    }

    public function test_an_admin_issues_a_login_linked_to_the_employee(): void
    {
        $employee = Employee::factory()->create(['user_id' => null]);

        Livewire::actingAs($this->admin())
            ->test('pages::employees.issue-account')
            ->set('employeeId', $employee->id)
            ->set('email', 'juan@example.com')
            ->set('temporaryPassword', 'temporary-password')
            ->call('issue')
            ->assertHasNoErrors();

        $employee->refresh();

        $this->assertNotNull($employee->user_id);
        $this->assertSame('juan@example.com', $employee->user->email);
        $this->assertTrue($employee->user->hasRole('employee'));
        $this->assertTrue($employee->user->must_change_password);
        $this->assertTrue(Hash::check('temporary-password', $employee->user->password));
    }

    public function test_an_employee_who_must_change_their_password_is_sent_to_the_security_page(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole('employee');

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('security.edit'));
    }

    public function test_the_password_change_page_itself_is_reachable(): void
    {
        // Without this the redirect bounces forever: security.edit sits behind
        // password.confirm, which is itself a page the middleware would block.
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole('employee');

        $this->actingAs($user)->get(route('password.confirm'))->assertOk();
    }

    public function test_an_employee_who_has_changed_their_password_reaches_the_dashboard(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('employee');

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=IssueAccountTest`
Expected: FAIL with `Route [employees.issue-account] not defined`.

- [ ] **Step 3: Add the column**

`php artisan make:migration add_must_change_password_to_users_table`, then:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->boolean('must_change_password')->default(false)->after('password');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('must_change_password');
    });
}
```

In `app/Models/User.php`, add `'must_change_password'` to `$fillable` and `'must_change_password' => 'boolean'` to `casts()`.

- [ ] **Step 4: Write the middleware**

Create `app/Http/Middleware/EnsurePasswordHasBeenChanged.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An admin issues a temporary password. Until it is replaced, that account
 * goes nowhere but the page where it can be replaced.
 */
class EnsurePasswordHasBeenChanged
{
    /**
     * The password form lives on settings/security, which itself sits behind
     * Fortify's password.confirm. Every one of these has to stay reachable or
     * the redirect loops.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->must_change_password) {
            return $next($request);
        }

        // Livewire's update endpoint carries every form submission on the page,
        // the password form included. Redirecting it would make the change
        // impossible to complete.
        if ($request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        return redirect()->route('security.edit')
            ->with('status', __('Please choose your own password before continuing.'));
    }
}
```

Letting every Livewire request through is a real, if narrow, hole: someone holding a temporary password could still drive a Livewire component they can already reach. It is accepted here because the alternative — matching Livewire's update endpoint by URI — is worse: that path carries a per-application random prefix (`livewire-011b3858/update` in this installation), so it is not something to hard-code.

Register it in `bootstrap/app.php`, inside `->withMiddleware()`:

```php
$middleware->appendToGroup('web', \App\Http\Middleware\EnsurePasswordHasBeenChanged::class);
```

- [ ] **Step 5: Clear the flag when the password changes**

This installation has no `app/Actions/Fortify/UpdateUserPassword.php` — only `CreateNewUser.php` and `ResetUserPassword.php`. The password change lives in the starter kit's own component, in `updatePassword()` inside `resources/views/pages/settings/⚡security.blade.php`. Find this:

```php
        Auth::user()->update([
            'password' => $validated['password'],
        ]);
```

and make it:

```php
        Auth::user()->update([
            'password' => $validated['password'],
            'must_change_password' => false,
        ]);
```

Without this, an employee changes their password and is still held on the security page forever. `User` casts `password` as `hashed`, so the plain value passed to `update()` is hashed on the way in — do not add a second `Hash::make()`.

Do the same in `app/Actions/Fortify/ResetUserPassword.php`, in its `reset()` method, so that a forgotten temporary password also clears the flag.

- [ ] **Step 6: Write the screen**

Create `resources/views/pages/employees/⚡issue-account.blade.php`:

```blade
<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Issue a login')] class extends Component {
    public ?int $employeeId = null;

    public string $email = '';

    public string $temporaryPassword = '';

    public function mount(): void
    {
        $this->authorize('issueAccount', Employee::class);
    }

    public function issue(): void
    {
        $this->authorize('issueAccount', Employee::class);

        $this->validate([
            'employeeId' => [
                'required',
                Rule::exists('employees', 'id')->whereNull('user_id')->whereNull('deleted_at'),
            ],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'temporaryPassword' => ['required', 'string', 'min:8'],
        ], [
            'employeeId.exists' => __('That employee does not exist, or already has a login.'),
        ]);

        DB::transaction(function () {
            $employee = Employee::findOrFail($this->employeeId);

            $user = User::create([
                'name' => $employee->fullName(),
                'email' => $this->email,
                'password' => Hash::make($this->temporaryPassword),
                'must_change_password' => true,
            ]);

            $user->assignRole('employee');

            $employee->update(['user_id' => $user->id]);
        });

        $this->reset(['employeeId', 'email', 'temporaryPassword']);

        session()->flash('status', __('Login issued.'));
    }

    public function with(): array
    {
        return [
            'employees' => Employee::query()
                ->whereNull('user_id')
                ->active()
                ->orderBy('last_name')
                ->get(),
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Issue a login') }}</flux:heading>
    <flux:subheading>
        {{ __('The employee must replace this password the first time they sign in.') }}
    </flux:subheading>

    <x-auth-session-status class="mt-4" :status="session('status')" />

    <form wire:submit="issue" class="mt-6 flex max-w-xl flex-col gap-6">
        <flux:select wire:model="employeeId" :label="__('Employee')" :placeholder="__('Choose an employee')">
            @foreach ($employees as $employee)
                <flux:select.option :key="$employee->id" value="{{ $employee->id }}">
                    {{ $employee->employee_number }} — {{ $employee->fullName() }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="email" type="email" :label="__('Email address')" required />

        <flux:input
            wire:model="temporaryPassword"
            type="text"
            :label="__('Temporary password')"
            :description="__('Write this down and hand it over in person. It is shown only once.')"
            required
            viewable
        />

        <flux:button type="submit" variant="primary" class="self-start">
            {{ __('Issue login') }}
        </flux:button>
    </form>
</section>
```

The temporary password is a plain text field, deliberately. Whoever issues it has to read it off the screen to hand over, and masking it only produces typos.

- [ ] **Step 7: Add the route**

In `routes/employees.php`:

```php
Route::livewire('employees/issue-account', 'pages::employees.issue-account')->name('employees.issue-account');
```

- [ ] **Step 8: Run the tests**

```bash
php artisan migrate
php artisan test --filter=IssueAccountTest
```

Expected: PASS, 5 tests.

- [ ] **Step 9: Run the whole suite**

Run: `php artisan test`
Expected: everything passes. The new middleware sits on the whole `web` group, so a break here shows up in the starter kit's own tests — that is the point of running all of it.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty
git add app database/migrations bootstrap/app.php resources/views/pages/employees routes/employees.php tests/Feature/IssueAccountTest.php
git commit -m "Let an admin issue logins with a temporary password"
```

---

## Task 9: The audit trail

**Files:**
- Modify: `composer.json` (via composer require)
- Modify: `app/Models/Employee.php`
- Create: `app/Services/AuditRecorder.php`
- Create: `resources/views/pages/audit/⚡index.blade.php`
- Modify: `routes/employees.php`
- Test: `tests/Feature/AuditTrailTest.php`

**Interfaces:**
- Consumes: `Employee`, the `audit.view` permission.
- Produces: `Employee` logs its own changes through `LogsActivity`. `AuditRecorder::recordRead(Model $subject, string $description): void` records a read. Named route `audit.index`.

This is what replaces the approval gate the design deliberately left out. Changes and reads are both recorded — reading is the more common abuse, and `activitylog` does not capture it on its own.

- [ ] **Step 1: Install the package**

```bash
composer require spatie/laravel-activitylog
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/AuditTrailTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditRecorder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_editing_an_employee_records_the_old_and_the_new_value(): void
    {
        $hr = $this->userWithRole('hr');
        $this->actingAs($hr);

        $employee = Employee::factory()->create(['last_name' => 'Dela Cruz']);
        $employee->update(['last_name' => 'Dela Cruz-Reyes']);

        $activity = Activity::where('subject_type', Employee::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($hr->id, $activity->causer_id);
        $this->assertSame('Dela Cruz', $activity->properties['old']['last_name']);
        $this->assertSame('Dela Cruz-Reyes', $activity->properties['attributes']['last_name']);
    }

    public function test_a_read_is_recorded_with_its_causer(): void
    {
        $hr = $this->userWithRole('hr');
        $this->actingAs($hr);

        $employee = Employee::factory()->create();

        app(AuditRecorder::class)->recordRead($employee, 'Opened the employee record');

        $activity = Activity::where('event', 'read')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($employee->id, $activity->subject_id);
        $this->assertSame($hr->id, $activity->causer_id);
        $this->assertSame('Opened the employee record', $activity->description);
    }

    public function test_an_employee_cannot_open_the_audit_log(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get(route('audit.index'))
            ->assertForbidden();
    }

    public function test_hr_can_open_the_audit_log(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('audit.index'))
            ->assertOk();
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter=AuditTrailTest`
Expected: FAIL — no activity is recorded, and `Route [audit.index] not defined`.

- [ ] **Step 4: Log changes on Employee**

In `app/Models/Employee.php`, add the trait and its configuration:

```php
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Employee extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    // ... existing $fillable and casts() ...

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
```

`logOnlyDirty()` keeps the log to what actually changed. Without it every save writes all fourteen columns and the log becomes unreadable within a month.

- [ ] **Step 5: Write the recorder**

Create `app/Services/AuditRecorder.php`:

```php
<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * activitylog records writes on its own. Reads it cannot see — and reading
 * someone else's record is the more common abuse, so it is recorded here.
 */
class AuditRecorder
{
    public function recordRead(Model $subject, string $description): void
    {
        activity()
            ->performedOn($subject)
            ->causedBy(auth()->user())
            ->event('read')
            ->log($description);
    }
}
```

- [ ] **Step 6: Write the audit log screen**

Create `resources/views/pages/audit/⚡index.blade.php`:

```blade
<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

new #[Title('Audit log')] class extends Component {
    use WithPagination;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('audit.view'), 403);
    }

    public function with(): array
    {
        return [
            'activities' => Activity::query()
                ->with('causer')
                ->latest('id')
                ->paginate(50),
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Audit log') }}</flux:heading>
    <flux:subheading>{{ __('Every change and every read, most recent first.') }}</flux:subheading>

    <flux:table class="mt-6" :paginate="$activities">
        <flux:table.columns>
            <flux:table.column>{{ __('When') }}</flux:table.column>
            <flux:table.column>{{ __('Who') }}</flux:table.column>
            <flux:table.column>{{ __('Event') }}</flux:table.column>
            <flux:table.column>{{ __('Subject') }}</flux:table.column>
            <flux:table.column>{{ __('Changed') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($activities as $activity)
                <flux:table.row wire:key="activity-{{ $activity->id }}">
                    <flux:table.cell>{{ $activity->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                    <flux:table.cell>{{ $activity->causer?->name ?? __('System') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm">{{ $activity->event }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ class_basename($activity->subject_type) }} #{{ $activity->subject_id }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm">
                        @foreach ($activity->properties['attributes'] ?? [] as $field => $value)
                            <div>
                                <span class="font-medium">{{ $field }}</span>:
                                <span class="text-zinc-500">{{ $activity->properties['old'][$field] ?? '—' }}</span>
                                →
                                <span>{{ $value }}</span>
                            </div>
                        @endforeach
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
```

- [ ] **Step 7: Add the route**

In `routes/employees.php`, inside the existing group:

```php
Route::livewire('audit', 'pages::audit.index')->name('audit.index');
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test --filter=AuditTrailTest`
Expected: PASS, 4 tests.

- [ ] **Step 9: Add the sidebar link**

In `resources/views/layouts/app/sidebar.blade.php`:

```blade
@can('audit.view')
    <flux:navlist.item icon="clipboard-document-list" :href="route('audit.index')" :current="request()->routeIs('audit.*')" wire:navigate>
        {{ __('Audit log') }}
    </flux:navlist.item>
@endcan
```

- [ ] **Step 10: Run everything and commit**

```bash
php artisan test
npm run build
vendor/bin/pint --dirty
git add composer.json composer.lock app config database resources routes tests
git commit -m "Record every employee change and read in an audit log"
```

---

## Phase 1a is done when

1. `php artisan test` passes in full, and `tests/Feature/Auth/RegistrationTest.php` reports as skipped.
2. `npm run build` succeeds.
3. `/register` returns 404 and no link to it survives anywhere in the UI.
4. An admin uploads a CSV, sees a per-line preview with errors named, and nothing is written until they confirm.
5. An admin issues a login; that person is held on the profile page until they replace the temporary password.
6. An employee opening `/employees` gets a 403; HR gets the list.
7. Every employee change appears in `/audit` with the old and the new value and the name of whoever made it.

## What Phase 1a deliberately does not build

- **Screens for divisions, sections and positions.** Reference data is seeded. Organizational management is not in the Phase 1 definition of done and gets its own spec.
- **Editing an employee through the UI.** The policy and the audit trail are in place for it; the screen comes with Phase 1b, alongside the PDS.
- **Anything touching the PDS.** Eleven tables and nine sections, in Phase 1b.
