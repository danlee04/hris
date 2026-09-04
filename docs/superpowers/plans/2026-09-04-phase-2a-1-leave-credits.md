# Phase 2a-1: Leave Types and Credits — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the leave vocabulary and the credit ledger, so that before any application can spend a credit, HR can see, enter and correct a balance.

**Architecture:** A `leave_types` table holds each type's own rules rather than an enum. An append-only `leave_ledger_entries` table is the single source of every balance — there is no balance column anywhere. `LeaveLedger` is the only class that writes entries; `LeaveBalance` is the only class that reads totals; `AccrualPosting` posts a month idempotently. Three screens sit on top: leave types for the admin, one employee's ledger for HR, and the monthly posting for HR.

**Tech Stack:** Laravel 13 / PHP 8.3, Livewire 4 single-file components, Flux UI, Tailwind v4, MySQL 8, `spatie/laravel-permission`, PHPUnit 12 on in-memory SQLite.

**Spec:** `docs/superpowers/specs/2026-09-04-phase-2a-leave-design.md`

## Global Constraints

Copied from `CLAUDE.md` and the spec. Every task's requirements implicitly include this section.

- **Never run `git commit` or `git push`.** Dan commits. Each task ends by handing him one commit message.
- **English only** in code, comments and every user-facing string.
- Verification before any task is called done: `php artisan test` (all of it), `npm run build` (whenever a Blade view changed), `vendor/bin/pint --dirty`. Report the real numbers.
- **`authorize()` in Livewire `mount()` AND again in every save.** `mount()` runs once; public properties are rehydrated from the browser on every later request.
- **Ownership belongs in a policy, never a permission.** A permission cannot see which record is being asked about.
- **Refuse rather than silently skip.** A silent skip looks to the user like a save.
- Models declare `#[Fillable([...])]`. Never `$guarded = []`. Validate first, pass only the validated array to `create()`/`update()`.
- Livewire 4 single-file pages at `resources/views/pages/<dir>/⚡<name>.blade.php`, referenced as `pages::<dir>.<name>`, routed with `Route::livewire()`.
- Flux UI for every control. Repeating rows bind `wire:key` to a stable key, never `$index`.
- Spell it **`leave`**, and spell organisation-style words with a **z** (`organization`), per CLAUDE.md.
- Tests are PHPUnit classes extending `Tests\TestCase`, using `RefreshDatabase`. PHPUnit 12 ignores `@dataProvider`; use `#[DataProvider]` or a loop.
- Nothing in this plan touches `leave_applications`. `hold`, `release` and `commit` entries belong to Phase 2a-2; the `kind` vocabulary permits them from the start, and nothing writes them yet.

---

## Task 1: The leave type vocabulary

**Files:**
- Create: `app/Enums/LeaveLedgerKind.php`
- Create: `database/migrations/2026_09_04_100000_create_leave_types_table.php`
- Create: `app/Models/LeaveType.php`
- Create: `database/factories/LeaveTypeFactory.php`
- Create: `database/seeders/LeaveTypeSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Leave/LeaveTypeTest.php`

**Interfaces:**
- Consumes: `App\Enums\EmploymentStatus` (Phase 1a) — `permanent`, `job_order`, `contract_of_service`, `coterminous`.
- Produces: `LeaveType` with `code`, `name`, `ledger`, `accrual_days_per_month`, `grant_days_per_year`, `notice_days`, `max_consecutive_days`, `applies_to` (array cast), `is_active`; `LeaveType::availableTo(EmploymentStatus $status): Builder` scope; `LeaveType::isCredited(): bool`. `LeaveLedgerKind` enum with `Opening`, `Accrual`, `Grant`, `Hold`, `Release`, `Commit`, `Adjustment`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/LeaveTypeTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Models\LeaveType;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_produces_the_thirteen_types_on_the_form_plus_wellness(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $this->assertSame(14, LeaveType::count());

        // The three found by reading the template rather than the issuances.
        $this->assertDatabaseHas('leave_types', ['code' => 'VAWC']);
        $this->assertDatabaseHas('leave_types', ['code' => 'SLBW']);
        $this->assertDatabaseHas('leave_types', ['code' => 'ADOPTION']);
    }

    public function test_wellness_leave_carries_the_hospitals_own_rules(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $wellness = LeaveType::where('code', 'WELLNESS')->sole();

        $this->assertSame('wellness', $wellness->ledger);
        $this->assertSame(5, $wellness->grant_days_per_year);
        $this->assertSame(5, $wellness->notice_days);
        $this->assertSame(3, $wellness->max_consecutive_days);
        $this->assertSame(['job_order', 'contract_of_service'], $wellness->applies_to);
    }

    public function test_a_job_order_sees_wellness_and_nothing_else(): void
    {
        // Job order and contract of service earn no statutory credits. If this
        // ever returns Vacation Leave, 37 people are being offered days that
        // do not exist.
        $this->seed(LeaveTypeSeeder::class);

        $codes = LeaveType::availableTo(EmploymentStatus::JobOrder)->pluck('code')->all();

        $this->assertSame(['WELLNESS'], $codes);
    }

    public function test_a_permanent_employee_does_not_see_wellness(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $codes = LeaveType::availableTo(EmploymentStatus::Permanent)->pluck('code')->all();

        $this->assertNotContains('WELLNESS', $codes);
        $this->assertContains('VL', $codes);
        $this->assertCount(13, $codes);
    }

    public function test_a_retired_type_is_not_offered(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        LeaveType::where('code', 'VL')->update(['is_active' => false]);

        $this->assertNotContains(
            'VL',
            LeaveType::availableTo(EmploymentStatus::Permanent)->pluck('code')->all()
        );
    }

    public function test_only_vacation_and_sick_accrue_monthly(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $accruing = LeaveType::whereNotNull('accrual_days_per_month')
            ->pluck('accrual_days_per_month', 'code')
            ->all();

        $this->assertEquals(['VL' => '1.25', 'SL' => '1.25'], $accruing);
    }

    public function test_a_type_without_a_ledger_is_not_credited(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $this->assertTrue(LeaveType::where('code', 'VL')->sole()->isCredited());
        $this->assertFalse(LeaveType::where('code', 'ML')->sole()->isCredited());
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=LeaveTypeTest`
Expected: FAIL — `Class "App\Models\LeaveType" not found`.

- [ ] **Step 3: The ledger kind enum**

`app/Enums/LeaveLedgerKind.php`:

```php
<?php

namespace App\Enums;

/**
 * Why a ledger entry exists.
 *
 * Hold, Release and Commit are written by Phase 2a-2, when there is an
 * application to hold credits for. They are named here because the vocabulary
 * is one thing, and a kind added later would mean a migration to widen a column
 * that could have been wide from the start.
 */
enum LeaveLedgerKind: string
{
    case Opening = 'opening';
    case Accrual = 'accrual';
    case Grant = 'grant';
    case Hold = 'hold';
    case Release = 'release';
    case Commit = 'commit';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::Accrual => 'Monthly accrual',
            self::Grant => 'Yearly grant',
            self::Hold => 'Held for an application',
            self::Release => 'Released',
            self::Commit => 'Used',
            self::Adjustment => 'Adjustment',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 4: The migration**

`database/migrations/2026_09_04_100000_create_leave_types_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The leave vocabulary, as a table rather than an enum.
 *
 * Every type carries its own rules and not just a number of days: how much
 * notice it needs, how many consecutive days it allows, which employment
 * statuses may file it. Wellness Leave is the reason — it exists at DTRC and
 * in no CSC issuance, and a rule set the hospital invents cannot live in code
 * that only a developer can change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();          // "VL", "WELLNESS"
            $table->string('name');
            $table->string('legal_basis')->nullable();     // printed on the form

            // Which balance it draws on. Null means the type is applied for and
            // approved but spends nothing: maternity, study, adoption.
            $table->string('ledger', 20)->nullable();

            $table->decimal('accrual_days_per_month', 5, 2)->nullable();
            $table->unsignedSmallInteger('grant_days_per_year')->nullable();
            $table->unsignedSmallInteger('notice_days')->nullable();
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();

            // The employment statuses that may file it.
            $table->json('applies_to');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
```

- [ ] **Step 5: The model**

`app/Models/LeaveType.php`:

```php
<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'name', 'legal_basis', 'ledger', 'accrual_days_per_month',
    'grant_days_per_year', 'notice_days', 'max_consecutive_days',
    'applies_to', 'sort_order', 'is_active',
])]
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'applies_to' => 'array',
            'grant_days_per_year' => 'integer',
            'notice_days' => 'integer',
            'max_consecutive_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** A type with no ledger is applied for and approved but spends nothing. */
    public function isCredited(): bool
    {
        return $this->ledger !== null;
    }

    /**
     * What this employment status may file. Job order and contract of service
     * staff earn no statutory credits; offering them Vacation Leave would offer
     * 37 people days that do not exist.
     */
    public function scopeAvailableTo(Builder $query, EmploymentStatus $status): void
    {
        $query->where('is_active', true)
            ->whereJsonContains('applies_to', $status->value)
            ->orderBy('sort_order');
    }
}
```

- [ ] **Step 6: The factory**

`database/factories/LeaveTypeFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveType> */
class LeaveTypeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->words(2, true).' Leave',
            'legal_basis' => null,
            'ledger' => null,
            'accrual_days_per_month' => null,
            'grant_days_per_year' => null,
            'notice_days' => null,
            'max_consecutive_days' => null,
            'applies_to' => ['permanent', 'coterminous'],
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /** A type that spends a balance. */
    public function credited(string $ledger = 'vacation'): static
    {
        return $this->state(fn () => ['ledger' => $ledger]);
    }
}
```

- [ ] **Step 7: The seeder**

`database/seeders/LeaveTypeSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * The thirteen types printed on DTRC's CS Form No. 6 (Revised 2020), in the
 * order they appear on it, plus Wellness Leave.
 *
 * Wellness has no box on the form; it prints on the "Others:" line. It is the
 * hospital's own grant to job order and contract of service staff, who earn no
 * statutory credits at all.
 */
class LeaveTypeSeeder extends Seeder
{
    private const REGULAR = ['permanent', 'coterminous'];

    private const CASUAL = ['job_order', 'contract_of_service'];

    public function run(): void
    {
        $types = [
            ['VL', 'Vacation Leave', 'Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292', 'vacation', '1.25', null],
            ['FL', 'Mandatory/Forced Leave', 'Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292', 'vacation', null, null],
            ['SL', 'Sick Leave', 'Sec. 43, Rule XVI, Omnibus Rules Implementing E.O. No. 292', 'sick', '1.25', null],
            ['ML', 'Maternity Leave', 'R.A. No. 11210 / IRR issued by CSC, DOLE and SSS', null, null, null],
            ['PL', 'Paternity Leave', 'R.A. No. 8187 / CSC MC No. 71, s. 1998, as amended', null, null, null],
            ['SPL', 'Special Privilege Leave', 'Sec. 21, Rule XVI, Omnibus Rules Implementing E.O. No. 292', 'spl', null, 3],
            ['SOLO', 'Solo Parent Leave', 'RA No. 8972 / CSC MC No. 8, s. 2004', 'solo_parent', null, 7],
            ['STUDY', 'Study Leave', 'Sec. 68, Rule XVI, Omnibus Rules Implementing E.O. No. 292', null, null, null],
            ['VAWC', '10-Day VAWC Leave', 'RA No. 9262 / CSC MC No. 15, s. 2005', null, null, null],
            ['REHAB', 'Rehabilitation Privilege', 'Sec. 55, Rule XVI, Omnibus Rules Implementing E.O. No. 292', null, null, null],
            ['SLBW', 'Special Leave Benefits for Women', 'RA No. 9710 / CSC MC No. 25, s. 2010', null, null, null],
            ['CALAMITY', 'Special Emergency (Calamity) Leave', 'CSC MC No. 2, s. 2012, as amended', null, null, null],
            ['ADOPTION', 'Adoption Leave', 'R.A. No. 8552', null, null, null],
        ];

        foreach ($types as $order => [$code, $name, $basis, $ledger, $accrual, $grant]) {
            LeaveType::updateOrCreate(['code' => $code], [
                'name' => $name,
                'legal_basis' => $basis,
                'ledger' => $ledger,
                'accrual_days_per_month' => $accrual,
                'grant_days_per_year' => $grant,
                'applies_to' => self::REGULAR,
                'sort_order' => $order + 1,
            ]);
        }

        LeaveType::updateOrCreate(['code' => 'WELLNESS'], [
            'name' => 'Wellness Leave',
            'legal_basis' => 'DTRC policy',
            'ledger' => 'wellness',
            'grant_days_per_year' => 5,
            'notice_days' => 5,
            'max_consecutive_days' => 3,
            'applies_to' => self::CASUAL,
            'sort_order' => 90,
        ]);
    }
}
```

- [ ] **Step 8: Register the seeder**

In `database/seeders/DatabaseSeeder.php`, inside `run()`, after `OrganizationSeeder`:

```php
$this->call(LeaveTypeSeeder::class);
```

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter=LeaveTypeTest`
Expected: PASS, 7 tests.

Then the whole suite: `php artisan test`, and `vendor/bin/pint --dirty`.

- [ ] **Step 10: Hand Dan the commit message**

```
Add the leave type table and seed the CS Form 6 types
```

---

## Task 2: The ledger and its only writer

**Files:**
- Create: `database/migrations/2026_09_04_100100_create_leave_ledger_entries_table.php`
- Create: `app/Models/LeaveLedgerEntry.php`
- Create: `database/factories/LeaveLedgerEntryFactory.php`
- Create: `app/Services/Leave/LeaveLedger.php`
- Test: `tests/Feature/Leave/LeaveLedgerTest.php`

**Interfaces:**
- Consumes: `Employee` (Phase 1a), `LeaveLedgerKind` (Task 1).
- Produces: `LeaveLedger::open(Employee $employee, string $ledger, float $days, ?Carbon $on = null): LeaveLedgerEntry`, `::accrue(Employee $employee, string $ledger, float $days, string $period): ?LeaveLedgerEntry`, `::grant(Employee $employee, string $ledger, float $days, string $period): ?LeaveLedgerEntry`, `::adjust(Employee $employee, string $ledger, float $days, string $reason): LeaveLedgerEntry`. All record `created_by_user_id` from `auth()->id()`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/LeaveLedgerTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use App\Services\Leave\LeaveLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private LeaveLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->create();
        $this->ledger = app(LeaveLedger::class);
    }

    public function test_an_opening_balance_is_the_balance(): void
    {
        $this->ledger->open($this->employee, 'vacation', 12.5);

        $this->assertSame(12.5, $this->ledger->balance($this->employee, 'vacation'));
    }

    public function test_two_ledgers_do_not_mix(): void
    {
        // Vacation and sick are separate balances on the same form, and an
        // entry landing in the wrong one would still add up.
        $this->ledger->open($this->employee, 'vacation', 10);
        $this->ledger->open($this->employee, 'sick', 4);

        $this->assertSame(10.0, $this->ledger->balance($this->employee, 'vacation'));
        $this->assertSame(4.0, $this->ledger->balance($this->employee, 'sick'));
    }

    public function test_two_employees_do_not_mix(): void
    {
        $other = Employee::factory()->create();

        $this->ledger->open($this->employee, 'vacation', 10);

        $this->assertSame(0.0, $this->ledger->balance($other, 'vacation'));
    }

    public function test_an_accrual_adds_to_the_balance(): void
    {
        $this->ledger->open($this->employee, 'vacation', 10);
        $this->ledger->accrue($this->employee, 'vacation', 1.25, '2026-09');

        $this->assertSame(11.25, $this->ledger->balance($this->employee, 'vacation'));
    }

    public function test_the_same_period_cannot_accrue_twice(): void
    {
        // Posting a month is a button somebody will press twice. The second
        // press must write nothing rather than hand out a second 1.25.
        $this->ledger->accrue($this->employee, 'vacation', 1.25, '2026-09');
        $second = $this->ledger->accrue($this->employee, 'vacation', 1.25, '2026-09');

        $this->assertNull($second);
        $this->assertSame(1.25, $this->ledger->balance($this->employee, 'vacation'));
        $this->assertSame(1, LeaveLedgerEntry::count());
    }

    public function test_a_different_period_accrues_again(): void
    {
        $this->ledger->accrue($this->employee, 'vacation', 1.25, '2026-09');
        $this->ledger->accrue($this->employee, 'vacation', 1.25, '2026-10');

        $this->assertSame(2.5, $this->ledger->balance($this->employee, 'vacation'));
    }

    public function test_a_yearly_grant_is_keyed_on_the_year(): void
    {
        $this->ledger->grant($this->employee, 'wellness', 5, '2026');

        $this->assertNull($this->ledger->grant($this->employee, 'wellness', 5, '2026'));
        $this->assertSame(5.0, $this->ledger->balance($this->employee, 'wellness'));

        $this->ledger->grant($this->employee, 'wellness', 5, '2027');

        $this->assertSame(10.0, $this->ledger->balance($this->employee, 'wellness'));
    }

    public function test_an_adjustment_can_go_either_way_and_keeps_its_reason(): void
    {
        $this->ledger->open($this->employee, 'vacation', 10);
        $this->ledger->adjust($this->employee, 'vacation', -2, 'Corrected from the 2025 spreadsheet');

        $this->assertSame(8.0, $this->ledger->balance($this->employee, 'vacation'));

        $entry = LeaveLedgerEntry::latest('id')->first();

        $this->assertSame('Corrected from the 2025 spreadsheet', $entry->description);
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        // An unexplained change to somebody's leave balance is the entry a
        // person will ask about a year later. It does not get to be silent.
        $this->expectException(ValidationException::class);

        $this->ledger->adjust($this->employee, 'vacation', -2, '   ');
    }

    public function test_a_second_opening_balance_is_refused(): void
    {
        // The opening balance is what was carried in from the spreadsheet. A
        // second one is a correction, and a correction is an adjustment with a
        // reason attached.
        $this->ledger->open($this->employee, 'vacation', 10);

        $this->expectException(ValidationException::class);

        $this->ledger->open($this->employee, 'vacation', 12);
    }

    public function test_every_entry_records_who_wrote_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->ledger->open($this->employee, 'vacation', 10);

        $this->assertSame($user->id, LeaveLedgerEntry::sole()->created_by_user_id);
    }

    public function test_entries_are_never_updated_only_added(): void
    {
        // The ledger is the answer to "where did my credits go". An entry that
        // changed after the fact cannot answer it.
        $this->ledger->open($this->employee, 'vacation', 10);
        $this->ledger->adjust($this->employee, 'vacation', 3, 'Awarded');

        $this->assertSame(2, LeaveLedgerEntry::count());
        $this->assertSame(13.0, $this->ledger->balance($this->employee, 'vacation'));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=LeaveLedgerTest`
Expected: FAIL — `Class "App\Services\Leave\LeaveLedger" not found`.

- [ ] **Step 3: The migration**

`database/migrations/2026_09_04_100100_create_leave_ledger_entries_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every movement of every leave credit, append-only.
 *
 * There is no balance column on employees or anywhere else. A balance is
 * SUM(days). A stored balance and its entries eventually disagree, and nothing
 * in the system can say which of the two is right.
 *
 * `leave_application_id` is added in Phase 2a-2, along with the holds it
 * belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('ledger', 20);            // vacation, sick, spl, solo_parent, wellness
            $table->string('kind', 20);              // LeaveLedgerKind
            $table->decimal('days', 6, 2);           // signed: negative takes credits away
            $table->date('effective_date');

            // '2026-09' for a monthly accrual, '2026' for a yearly grant. This
            // is what makes posting idempotent, so the column is part of a
            // unique index rather than a note.
            $table->string('period', 7)->nullable();

            $table->string('description')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'ledger']);
            $table->unique(['employee_id', 'ledger', 'kind', 'period'], 'leave_ledger_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger_entries');
    }
};
```

Note on the unique index: MySQL and SQLite both treat NULLs as distinct, so entries with a null `period` — adjustments, holds, opening balances — never collide with each other. Only the keyed kinds are constrained, which is exactly the intent.

- [ ] **Step 4: The model**

`app/Models/LeaveLedgerEntry.php`:

```php
<?php

namespace App\Models;

use App\Enums\LeaveLedgerKind;
use Database\Factories\LeaveLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'ledger', 'kind', 'days', 'effective_date',
    'period', 'description', 'created_by_user_id',
])]
class LeaveLedgerEntry extends Model
{
    /** @use HasFactory<LeaveLedgerEntryFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => LeaveLedgerKind::class,
            'days' => 'float',
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
```

- [ ] **Step 5: The factory**

`database/factories/LeaveLedgerEntryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\LeaveLedgerKind;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveLedgerEntry> */
class LeaveLedgerEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'ledger' => 'vacation',
            'kind' => LeaveLedgerKind::Opening->value,
            'days' => 10,
            'effective_date' => now(),
            'period' => null,
            'description' => null,
            'created_by_user_id' => null,
        ];
    }
}
```

- [ ] **Step 6: The writer**

`app/Services/Leave/LeaveLedger.php`:

```php
<?php

namespace App\Services\Leave;

use App\Enums\LeaveLedgerKind;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only class that writes a ledger entry.
 *
 * Balances stay correct because there is exactly one place that can change
 * them. Every other part of the leave system asks this one, and this one is
 * tested.
 */
class LeaveLedger
{
    /** A balance is the sum of its entries. Nothing stores it. */
    public function balance(Employee $employee, string $ledger): float
    {
        return (float) LeaveLedgerEntry::where('employee_id', $employee->id)
            ->where('ledger', $ledger)
            ->sum('days');
    }

    /**
     * What HR carried in from the spreadsheet. Once only — a second one is a
     * correction, and a correction is an adjustment, which has to say why.
     */
    public function open(Employee $employee, string $ledger, float $days, ?Carbon $on = null): LeaveLedgerEntry
    {
        $exists = LeaveLedgerEntry::where('employee_id', $employee->id)
            ->where('ledger', $ledger)
            ->where('kind', LeaveLedgerKind::Opening)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'days' => __('An opening balance is already recorded. Use an adjustment to correct it.'),
            ]);
        }

        return $this->write($employee, $ledger, LeaveLedgerKind::Opening, $days, $on, null,
            __('Opening balance'));
    }

    /**
     * One month's credits. Returns null when the month is already posted,
     * which is what makes the posting button safe to press twice.
     */
    public function accrue(Employee $employee, string $ledger, float $days, string $period): ?LeaveLedgerEntry
    {
        return $this->writeOnce($employee, $ledger, LeaveLedgerKind::Accrual, $days, $period,
            __('Credits for :period', ['period' => $period]));
    }

    /** One year's grant. Same idempotency, keyed on the year. */
    public function grant(Employee $employee, string $ledger, float $days, string $period): ?LeaveLedgerEntry
    {
        return $this->writeOnce($employee, $ledger, LeaveLedgerKind::Grant, $days, $period,
            __('Grant for :period', ['period' => $period]));
    }

    /** A correction, which must say what it is correcting. */
    public function adjust(Employee $employee, string $ledger, float $days, string $reason): LeaveLedgerEntry
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => __('Say why the balance is being adjusted.'),
            ]);
        }

        return $this->write($employee, $ledger, LeaveLedgerKind::Adjustment, $days, null, null, trim($reason));
    }

    private function writeOnce(
        Employee $employee,
        string $ledger,
        LeaveLedgerKind $kind,
        float $days,
        string $period,
        string $description,
    ): ?LeaveLedgerEntry {
        $exists = LeaveLedgerEntry::where('employee_id', $employee->id)
            ->where('ledger', $ledger)
            ->where('kind', $kind)
            ->where('period', $period)
            ->exists();

        if ($exists) {
            return null;
        }

        // The check above loses a race; the unique index does not. Two people
        // pressing Post at the same moment must not produce two accruals.
        try {
            return $this->write($employee, $ledger, $kind, $days, null, $period, $description);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return null;
        }
    }

    private function write(
        Employee $employee,
        string $ledger,
        LeaveLedgerKind $kind,
        float $days,
        ?Carbon $on,
        ?string $period,
        string $description,
    ): LeaveLedgerEntry {
        return DB::transaction(fn () => LeaveLedgerEntry::create([
            'employee_id' => $employee->id,
            'ledger' => $ledger,
            'kind' => $kind->value,
            'days' => $days,
            'effective_date' => $on ?? now(),
            'period' => $period,
            'description' => $description,
            'created_by_user_id' => auth()->id(),
        ]));
    }
}
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=LeaveLedgerTest`
Expected: PASS, 12 tests.

Then `php artisan test` and `vendor/bin/pint --dirty`.

- [ ] **Step 8: Hand Dan the commit message**

```
Add the leave credit ledger and the single class that writes it
```

---

## Task 3: Reading balances

**Files:**
- Create: `app/Services/Leave/LeaveBalance.php`
- Test: `tests/Feature/Leave/LeaveBalanceTest.php`

**Interfaces:**
- Consumes: `LeaveLedger` (Task 2), `LeaveType` (Task 1), `Employee`, `EmploymentStatus`.
- Produces: `LeaveBalance::for(Employee $employee): array` — a list of `['ledger' => string, 'label' => string, 'days' => float]`, one per ledger the employee's employment status can actually use; `LeaveBalance::of(Employee $employee, string $ledger): float`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/LeaveBalanceTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);
    }

    public function test_a_permanent_employee_has_the_four_regular_ledgers(): void
    {
        $employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
        ]);

        $ledgers = collect(app(LeaveBalance::class)->for($employee))->pluck('ledger')->all();

        $this->assertSame(['vacation', 'sick', 'spl', 'solo_parent'], $ledgers);
    }

    public function test_a_job_order_has_only_wellness(): void
    {
        // Showing a job order a vacation balance of zero invites the question
        // of how to fill it, and the answer is that they never can.
        $employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::JobOrder->value,
        ]);

        $ledgers = collect(app(LeaveBalance::class)->for($employee))->pluck('ledger')->all();

        $this->assertSame(['wellness'], $ledgers);
    }

    public function test_a_balance_with_no_entries_is_zero_not_missing(): void
    {
        $employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
        ]);

        $vacation = collect(app(LeaveBalance::class)->for($employee))->firstWhere('ledger', 'vacation');

        $this->assertSame(0.0, $vacation['days']);
    }

    public function test_the_balance_reflects_the_ledger(): void
    {
        $employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
        ]);

        app(LeaveLedger::class)->open($employee, 'vacation', 15);
        app(LeaveLedger::class)->adjust($employee, 'vacation', -2, 'Corrected');

        $this->assertSame(13.0, app(LeaveBalance::class)->of($employee, 'vacation'));
    }

    public function test_a_retired_type_takes_its_ledger_off_the_list(): void
    {
        $employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
        ]);

        \App\Models\LeaveType::where('code', 'SPL')->update(['is_active' => false]);

        $ledgers = collect(app(LeaveBalance::class)->for($employee))->pluck('ledger')->all();

        $this->assertNotContains('spl', $ledgers);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=LeaveBalanceTest`
Expected: FAIL — `Class "App\Services\Leave\LeaveBalance" not found`.

- [ ] **Step 3: Write the service**

`app/Services/Leave/LeaveBalance.php`:

```php
<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveType;

/**
 * Which balances an employee actually has, and what they hold.
 *
 * The list comes from the leave types their employment status may file, so a
 * job order is never shown a vacation balance of zero. A zero they can never
 * fill reads as something to ask HR about.
 */
class LeaveBalance
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    /** @return list<array{ledger: string, label: string, days: float}> */
    public function for(Employee $employee): array
    {
        $status = $employee->employment_status;

        if ($status === null) {
            return [];
        }

        return LeaveType::availableTo($status)
            ->whereNotNull('ledger')
            ->get()
            ->unique('ledger')          // Vacation and Mandatory share one balance.
            ->values()
            ->map(fn (LeaveType $type) => [
                'ledger' => $type->ledger,
                'label' => $this->label($type->ledger),
                'days' => $this->ledger->balance($employee, $type->ledger),
            ])
            ->all();
    }

    public function of(Employee $employee, string $ledger): float
    {
        return $this->ledger->balance($employee, $ledger);
    }

    /**
     * The balance is named for itself, not for the type that spends it.
     * Mandatory/Forced Leave draws on the vacation balance; calling that
     * balance "Mandatory" would be wrong on the form and in the head.
     */
    private function label(string $ledger): string
    {
        return match ($ledger) {
            'vacation' => __('Vacation'),
            'sick' => __('Sick'),
            'spl' => __('Special Privilege'),
            'solo_parent' => __('Solo Parent'),
            'wellness' => __('Wellness'),
            default => $ledger,
        };
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=LeaveBalanceTest`
Expected: PASS, 5 tests. Then `php artisan test` and `vendor/bin/pint --dirty`.

- [ ] **Step 5: Hand Dan the commit message**

```
Add the leave balance reader
```

---

## Task 4: Posting a month of credits

**Files:**
- Create: `app/Services/Leave/AccrualPosting.php`
- Test: `tests/Feature/Leave/AccrualPostingTest.php`

**Interfaces:**
- Consumes: `LeaveLedger` (Task 2), `LeaveType` (Task 1), `Employee`.
- Produces: `AccrualPosting::preview(string $period): array` — a list of `['employee' => Employee, 'ledger' => string, 'days' => float, 'already_posted' => bool]`; `AccrualPosting::post(string $period): int` returning how many entries were written; `AccrualPosting::previewGrants(string $year): array` and `AccrualPosting::postGrants(string $year): int` in the same shapes, for the yearly grants.
- Also adds `LeaveLedger::hasAccrued()` and `LeaveLedger::hasGranted()` to the class written in Task 2.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/AccrualPostingTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Services\Leave\AccrualPosting;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccrualPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);
    }

    private function permanent(): Employee
    {
        return Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
            'is_active' => true,
        ]);
    }

    public function test_posting_writes_vacation_and_sick_for_a_permanent_employee(): void
    {
        $employee = $this->permanent();

        $written = app(AccrualPosting::class)->post('2026-09');

        $this->assertSame(2, $written);
        $this->assertSame(1.25, app(LeaveLedger::class)->balance($employee, 'vacation'));
        $this->assertSame(1.25, app(LeaveLedger::class)->balance($employee, 'sick'));
    }

    public function test_a_job_order_accrues_nothing(): void
    {
        Employee::factory()->create([
            'employment_status' => EmploymentStatus::JobOrder->value,
        ]);

        $this->assertSame(0, app(AccrualPosting::class)->post('2026-09'));
        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_an_inactive_employee_accrues_nothing(): void
    {
        // Someone who has left keeps their record and their balance; they do
        // not keep earning.
        Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
            'is_active' => false,
        ]);

        $this->assertSame(0, app(AccrualPosting::class)->post('2026-09'));
    }

    public function test_posting_the_same_month_twice_writes_once(): void
    {
        // This is a button. Somebody will press it twice, or two people will
        // press it. Neither may hand out a second 1.25.
        $employee = $this->permanent();

        app(AccrualPosting::class)->post('2026-09');
        $second = app(AccrualPosting::class)->post('2026-09');

        $this->assertSame(0, $second);
        $this->assertSame(1.25, app(AccrualPosting::class)->preview('2026-09')[0]['days']);
        $this->assertSame(2, LeaveLedgerEntry::count());
    }

    public function test_the_next_month_posts_again(): void
    {
        $employee = $this->permanent();

        app(AccrualPosting::class)->post('2026-09');
        app(AccrualPosting::class)->post('2026-10');

        $this->assertSame(2.5, app(LeaveLedger::class)->balance($employee, 'vacation'));
    }

    public function test_the_preview_says_who_has_already_been_posted(): void
    {
        $this->permanent();

        $before = app(AccrualPosting::class)->preview('2026-09');
        $this->assertFalse($before[0]['already_posted']);

        app(AccrualPosting::class)->post('2026-09');

        $after = app(AccrualPosting::class)->preview('2026-09');
        $this->assertTrue($after[0]['already_posted']);
    }

    public function test_the_preview_writes_nothing(): void
    {
        $this->permanent();

        app(AccrualPosting::class)->preview('2026-09');

        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_a_malformed_period_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AccrualPosting::class)->post('September 2026');
    }

    public function test_the_yearly_grants_are_posted_separately(): void
    {
        // SPL, Solo Parent and Wellness are granted once a year, not accrued
        // monthly. Without this, a job order never has a Wellness credit to
        // spend and the whole type is decoration.
        $permanent = $this->permanent();
        $jobOrder = Employee::factory()->create([
            'employment_status' => EmploymentStatus::JobOrder->value,
        ]);

        app(AccrualPosting::class)->postGrants('2026');

        $ledger = app(LeaveLedger::class);

        $this->assertSame(3.0, $ledger->balance($permanent, 'spl'));
        $this->assertSame(7.0, $ledger->balance($permanent, 'solo_parent'));
        $this->assertSame(0.0, $ledger->balance($permanent, 'wellness'));

        $this->assertSame(5.0, $ledger->balance($jobOrder, 'wellness'));
        $this->assertSame(0.0, $ledger->balance($jobOrder, 'spl'));
    }

    public function test_granting_the_same_year_twice_grants_once(): void
    {
        $employee = $this->permanent();

        app(AccrualPosting::class)->postGrants('2026');
        $second = app(AccrualPosting::class)->postGrants('2026');

        $this->assertSame(0, $second);
        $this->assertSame(3.0, app(LeaveLedger::class)->balance($employee, 'spl'));
    }

    public function test_a_malformed_year_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AccrualPosting::class)->postGrants('26');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=AccrualPostingTest`
Expected: FAIL — `Class "App\Services\Leave\AccrualPosting" not found`.

- [ ] **Step 3: Write the service**

`app/Services/Leave/AccrualPosting.php`:

```php
<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveType;
use InvalidArgumentException;

/**
 * A month of credits, posted by hand.
 *
 * This is a button rather than a scheduled job. The LAN server has no
 * guaranteed cron, and a scheduler that quietly fails to run produces a month
 * of missing credits nobody notices until somebody files against them. A button
 * that was not pressed is visible on the screen that shows the last posted
 * month.
 */
class AccrualPosting
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    /** @return list<array{employee: Employee, ledger: string, days: float, already_posted: bool}> */
    public function preview(string $period): array
    {
        $this->assertPeriod($period);

        $rows = [];

        foreach ($this->accruingTypes() as $type) {
            foreach ($this->eligible($type) as $employee) {
                $rows[] = [
                    'employee' => $employee,
                    'ledger' => $type->ledger,
                    'days' => (float) $type->accrual_days_per_month,
                    'already_posted' => $this->ledger->hasAccrued($employee, $type->ledger, $period),
                ];
            }
        }

        return $rows;
    }

    /** @return int how many entries were written */
    public function post(string $period): int
    {
        $this->assertPeriod($period);

        $written = 0;

        foreach ($this->accruingTypes() as $type) {
            foreach ($this->eligible($type) as $employee) {
                $entry = $this->ledger->accrue(
                    $employee,
                    $type->ledger,
                    (float) $type->accrual_days_per_month,
                    $period
                );

                $written += $entry === null ? 0 : 1;
            }
        }

        return $written;
    }

    /**
     * The yearly grants: SPL 3, Solo Parent 7, Wellness 5. Separate from the
     * monthly accrual because they are a different event with a different key,
     * and because a job order's only credit arrives this way.
     *
     * @return int how many entries were written
     */
    public function postGrants(string $year): int
    {
        $this->assertYear($year);

        $written = 0;

        foreach ($this->grantingTypes() as $type) {
            foreach ($this->eligible($type) as $employee) {
                $entry = $this->ledger->grant(
                    $employee,
                    $type->ledger,
                    (float) $type->grant_days_per_year,
                    $year
                );

                $written += $entry === null ? 0 : 1;
            }
        }

        return $written;
    }

    /** @return list<array{employee: Employee, ledger: string, days: float, already_posted: bool}> */
    public function previewGrants(string $year): array
    {
        $this->assertYear($year);

        $rows = [];

        foreach ($this->grantingTypes() as $type) {
            foreach ($this->eligible($type) as $employee) {
                $rows[] = [
                    'employee' => $employee,
                    'ledger' => $type->ledger,
                    'days' => (float) $type->grant_days_per_year,
                    'already_posted' => $this->ledger->hasGranted($employee, $type->ledger, $year),
                ];
            }
        }

        return $rows;
    }

    /** @return \Illuminate\Support\Collection<int, LeaveType> */
    private function accruingTypes()
    {
        return LeaveType::where('is_active', true)
            ->whereNotNull('accrual_days_per_month')
            ->whereNotNull('ledger')
            ->orderBy('sort_order')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, LeaveType> */
    private function grantingTypes()
    {
        return LeaveType::where('is_active', true)
            ->whereNotNull('grant_days_per_year')
            ->whereNotNull('ledger')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Employee>
     */
    private function eligible(LeaveType $type)
    {
        return Employee::query()
            ->active()
            ->whereIn('employment_status', $type->applies_to)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    private function assertPeriod(string $period): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException("A period is YYYY-MM; got [{$period}].");
        }
    }

    private function assertYear(string $year): void
    {
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            throw new InvalidArgumentException("A year is YYYY; got [{$year}].");
        }
    }
}
```

- [ ] **Step 4: Add the lookups the previews need**

`AccrualPosting` calls two methods that do not exist yet. Add them to `app/Services/Leave/LeaveLedger.php`:

```php
    /** Has this month already been posted for this employee and ledger? */
    public function hasAccrued(Employee $employee, string $ledger, string $period): bool
    {
        return $this->hasPosted($employee, $ledger, LeaveLedgerKind::Accrual, $period);
    }

    /** Has this year's grant already been given? */
    public function hasGranted(Employee $employee, string $ledger, string $year): bool
    {
        return $this->hasPosted($employee, $ledger, LeaveLedgerKind::Grant, $year);
    }

    private function hasPosted(Employee $employee, string $ledger, LeaveLedgerKind $kind, string $period): bool
    {
        return LeaveLedgerEntry::where('employee_id', $employee->id)
            ->where('ledger', $ledger)
            ->where('kind', $kind)
            ->where('period', $period)
            ->exists();
    }
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=AccrualPostingTest`
Expected: PASS, 11 tests. Then `php artisan test` and `vendor/bin/pint --dirty`.

- [ ] **Step 6: Hand Dan the commit message**

```
Add the monthly leave accrual posting
```

---

## Task 5: Permissions and the leave types screen

**Files:**
- Modify: `database/seeders/RoleSeeder.php`
- Create: `routes/leave.php`
- Modify: `routes/web.php`
- Create: `resources/views/pages/leave/⚡types.blade.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/Leave/LeaveTypeScreenTest.php`
- Test: modify `tests/Feature/RolesTest.php`

**Interfaces:**
- Consumes: `LeaveType` (Task 1).
- Produces: permissions `leave.manage` (hr, admin) and `leave.types.manage` (admin); routes `leave.types`, later joined by `leave.ledger` and `leave.accrual`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/LeaveTypeScreenTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeaveTypeScreenTest extends TestCase
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

    public function test_an_admin_adds_a_leave_type(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->call('add')
            ->set('code', 'WELLNESS')
            ->set('name', 'Wellness Leave')
            ->set('ledger', 'wellness')
            ->set('grantDaysPerYear', 5)
            ->set('noticeDays', 5)
            ->set('maxConsecutiveDays', 3)
            ->set('appliesTo', ['job_order', 'contract_of_service'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leave_types', ['code' => 'WELLNESS', 'ledger' => 'wellness']);
    }

    public function test_a_type_must_say_who_may_file_it(): void
    {
        // A type nobody can file is a row that looks like a policy and grants
        // nothing.
        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->set('code', 'X')
            ->set('name', 'Something')
            ->set('appliesTo', [])
            ->call('save')
            ->assertHasErrors('appliesTo');
    }

    public function test_two_types_cannot_share_a_code(): void
    {
        LeaveType::factory()->create(['code' => 'VL']);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->set('code', 'VL')
            ->set('name', 'Vacation Leave')
            ->set('appliesTo', ['permanent'])
            ->call('save')
            ->assertHasErrors('code');
    }

    public function test_add_after_edit_starts_from_an_empty_form(): void
    {
        // One modal serves both jobs. Without the reset, Add after Edit
        // overwrites the row last opened.
        $type = LeaveType::factory()->create(['name' => 'Study Leave']);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->call('edit', $type->id)
            ->assertSet('editingId', $type->id)
            ->call('add')
            ->assertSet('editingId', null)
            ->assertSet('code', '');
    }

    public function test_a_type_is_retired_not_deleted(): void
    {
        $type = LeaveType::factory()->create();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->call('toggleActive', $type->id);

        $this->assertFalse($type->fresh()->is_active);
        $this->assertDatabaseCount('leave_types', 1);
    }

    public function test_hr_cannot_reach_the_leave_types_screen(): void
    {
        // HR maintains balances and applications. The vocabulary itself is
        // org.manage territory: admin.
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('leave.types'))
            ->assertForbidden();
    }

    public function test_an_employee_cannot_reach_the_leave_types_screen(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get(route('leave.types'))
            ->assertForbidden();
    }

    public function test_a_save_re_asks_instead_of_trusting_mount(): void
    {
        $admin = $this->userWithRole('admin');

        $component = Livewire::actingAs($admin)->test('pages::leave.types');

        $admin->removeRole('admin');

        $component->set('code', 'X')
            ->set('name', 'Something')
            ->set('appliesTo', ['permanent'])
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseCount('leave_types', 0);
    }

    public function test_the_table_paginates(): void
    {
        LeaveType::factory()->count(16)->create();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->assertViewHas('types', fn ($types) => $types->count() === 15 && $types->total() === 16);
    }
}
```

Add to `tests/Feature/RolesTest.php`, inside the existing HR and admin assertions:

```php
    public function test_the_leave_permissions_land_on_the_right_roles(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $hr = \Spatie\Permission\Models\Role::findByName('hr');
        $admin = \Spatie\Permission\Models\Role::findByName('admin');

        $this->assertTrue($hr->hasPermissionTo('leave.manage'));
        $this->assertFalse($hr->hasPermissionTo('leave.types.manage'));

        $this->assertTrue($admin->hasPermissionTo('leave.manage'));
        $this->assertTrue($admin->hasPermissionTo('leave.types.manage'));
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter="LeaveTypeScreenTest|RolesTest"`
Expected: FAIL — `Route [leave.types] not defined` and the permission assertions fail.

- [ ] **Step 3: Add the permissions**

In `database/seeders/RoleSeeder.php`, add to `PERMISSIONS`:

```php
        'leave.manage',
        'leave.types.manage',
```

and to `HR_PERMISSIONS`:

```php
        'leave.manage',
```

`leave.types.manage` is deliberately absent from HR, the same way `org.manage` is: HR maintains people and their balances, the admin maintains the vocabulary.

- [ ] **Step 4: The routes**

`routes/leave.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('leave/types', 'pages::leave.types')->name('leave.types');
});
```

In `routes/web.php`, beside the others:

```php
require __DIR__.'/leave.php';
```

- [ ] **Step 5: The screen**

`resources/views/pages/leave/⚡types.blade.php`. It follows the organization screens exactly: a modal for both Add and Edit, pagination, retire rather than delete.

```php
<?php

use App\Enums\EmploymentStatus;
use App\Models\LeaveType;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Leave types')] class extends Component {
    use WithPagination;

    /** Null while adding, the id of the row being corrected otherwise. */
    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $legalBasis = '';

    public ?string $ledger = null;

    public ?string $accrualDaysPerMonth = null;

    public ?int $grantDaysPerYear = null;

    public ?int $noticeDays = null;

    public ?int $maxConsecutiveDays = null;

    /** @var list<string> */
    public array $appliesTo = [];

    public function mount(): void
    {
        // Nobody owns the leave vocabulary, so there is no ownership question
        // and no policy. The permission is the whole answer.
        $this->authorize('leave.types.manage');
    }

    public function add(): void
    {
        $this->authorize('leave.types.manage');

        // The same modal serves both jobs, so it has to be emptied on the way
        // in. Without this, Add after Edit overwrites the row last opened.
        $this->resetForm();

        Flux::modal('leave-type-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('leave.types.manage');

        $type = LeaveType::findOrFail($id);

        $this->resetValidation();

        $this->editingId = $type->id;
        $this->code = $type->code;
        $this->name = $type->name;
        $this->legalBasis = (string) $type->legal_basis;
        $this->ledger = $type->ledger;
        $this->accrualDaysPerMonth = $type->accrual_days_per_month;
        $this->grantDaysPerYear = $type->grant_days_per_year;
        $this->noticeDays = $type->notice_days;
        $this->maxConsecutiveDays = $type->max_consecutive_days;
        $this->appliesTo = $type->applies_to;

        Flux::modal('leave-type-form')->show();
    }

    public function save(): void
    {
        // Every request after mount() carries whatever the browser sends,
        // including editingId. The save asks again.
        $this->authorize('leave.types.manage');

        $validated = $this->validate([
            'code' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('leave_types', 'code')->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'legalBasis' => ['nullable', 'string', 'max:255'],
            'ledger' => ['nullable', Rule::in(['vacation', 'sick', 'spl', 'solo_parent', 'wellness'])],
            'accrualDaysPerMonth' => ['nullable', 'numeric', 'min:0', 'max:31'],
            'grantDaysPerYear' => ['nullable', 'integer', 'min:0', 'max:365'],
            'noticeDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'maxConsecutiveDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            // A type nobody may file is a row that looks like a policy and
            // grants nothing.
            'appliesTo' => ['required', 'array', 'min:1'],
            'appliesTo.*' => [Rule::enum(EmploymentStatus::class)],
        ]);

        $attributes = [
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'legal_basis' => $validated['legalBasis'] ?: null,
            'ledger' => $validated['ledger'] ?: null,
            'accrual_days_per_month' => $validated['accrualDaysPerMonth'] ?: null,
            'grant_days_per_year' => $validated['grantDaysPerYear'],
            'notice_days' => $validated['noticeDays'],
            'max_consecutive_days' => $validated['maxConsecutiveDays'],
            'applies_to' => $validated['appliesTo'],
        ];

        if ($this->editingId) {
            LeaveType::findOrFail($this->editingId)->update($attributes);
        } else {
            LeaveType::create($attributes);
        }

        // Validation throws before this line, so a modal that closes is a modal
        // whose contents were written.
        $this->resetForm();

        Flux::modal('leave-type-form')->close();

        Flux::toast(variant: 'success', text: __('Leave type saved.'));
    }

    /**
     * There is no delete. Applications point at a type for years, and removing
     * one would leave them naming nothing.
     */
    public function toggleActive(int $id): void
    {
        $this->authorize('leave.types.manage');

        $type = LeaveType::findOrFail($id);

        $type->update(['is_active' => ! $type->is_active]);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'legalBasis', 'ledger',
            'accrualDaysPerMonth', 'grantDaysPerYear', 'noticeDays',
            'maxConsecutiveDays', 'appliesTo',
        ]);
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'types' => LeaveType::orderBy('sort_order')->orderBy('code')->paginate(15),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Leave types') }}</flux:heading>
            <flux:subheading>
                {{ __('Each type carries its own rules, not just a number of days.') }}
            </flux:subheading>
        </div>

        <flux:button wire:click="add" variant="primary" icon="plus" size="sm">
            {{ __('Add a leave type') }}
        </flux:button>
    </div>

    <flux:table class="mt-6" :paginate="$types">
        <flux:table.columns>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Balance') }}</flux:table.column>
            <flux:table.column>{{ __('Rules') }}</flux:table.column>
            <flux:table.column>{{ __('Who may file') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($types as $type)
                <flux:table.row wire:key="leave-type-{{ $type->id }}">
                    <flux:table.cell class="font-medium">{{ $type->code }}</flux:table.cell>
                    <flux:table.cell>{{ $type->name }}</flux:table.cell>
                    <flux:table.cell>{{ $type->ledger ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="text-sm">
                        @if ($type->accrual_days_per_month)
                            {{ __(':days/month', ['days' => $type->accrual_days_per_month]) }}<br>
                        @endif
                        @if ($type->grant_days_per_year)
                            {{ __(':days/year', ['days' => $type->grant_days_per_year]) }}<br>
                        @endif
                        @if ($type->notice_days)
                            {{ __(':days days notice', ['days' => $type->notice_days]) }}<br>
                        @endif
                        @if ($type->max_consecutive_days)
                            {{ __('max :days consecutive', ['days' => $type->max_consecutive_days]) }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm">
                        {{ collect($type->applies_to)
                            ->map(fn ($value) => App\Enums\EmploymentStatus::from($value)->label())
                            ->join(', ') }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$type->is_active ? 'green' : 'zinc'">
                            {{ $type->is_active ? __('Active') : __('Retired') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-3 text-sm">
                            <flux:link href="#" wire:click.prevent="edit({{ $type->id }})">
                                {{ __('Edit') }}
                            </flux:link>
                            <flux:link href="#" wire:click.prevent="toggleActive({{ $type->id }})">
                                {{ $type->is_active ? __('Retire') : __('Restore') }}
                            </flux:link>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center">
                        {{ __('No leave types yet. Run php artisan db:seed to load the CS Form 6 list.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="leave-type-form" class="w-full md:max-w-2xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingId ? __('Edit leave type') : __('Add a leave type') }}
            </flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="code" :label="__('Code')" placeholder="VL" />
                <flux:input wire:model="name" :label="__('Name')" placeholder="Vacation Leave" />
            </div>

            <flux:input
                wire:model="legalBasis"
                :label="__('Legal basis')"
                :description="__('Printed on CS Form 6 beside the name.')"
            />

            <flux:select
                wire:model="ledger"
                :label="__('Balance it draws on')"
                :placeholder="__('None — approved but spends nothing')"
            >
                <flux:select.option value="vacation">{{ __('Vacation') }}</flux:select.option>
                <flux:select.option value="sick">{{ __('Sick') }}</flux:select.option>
                <flux:select.option value="spl">{{ __('Special Privilege') }}</flux:select.option>
                <flux:select.option value="solo_parent">{{ __('Solo Parent') }}</flux:select.option>
                <flux:select.option value="wellness">{{ __('Wellness') }}</flux:select.option>
            </flux:select>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="accrualDaysPerMonth" type="number" step="0.01" :label="__('Days earned per month')" />
                <flux:input wire:model="grantDaysPerYear" type="number" :label="__('Days granted per year')" />
                <flux:input wire:model="noticeDays" type="number" :label="__('Days of notice required')" />
                <flux:input wire:model="maxConsecutiveDays" type="number" :label="__('Maximum consecutive days')" />
            </div>

            <flux:checkbox.group wire:model="appliesTo" :label="__('Who may file it')">
                @foreach (App\Enums\EmploymentStatus::cases() as $status)
                    <flux:checkbox :value="$status->value" :label="$status->label()" />
                @endforeach
            </flux:checkbox.group>

            <flux:error name="appliesTo" />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">
                    {{ $editingId ? __('Save') : __('Add') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
```

- [ ] **Step 6: The sidebar entry**

In `resources/views/layouts/app/sidebar.blade.php`, after the Organization item:

```blade
                    @can('leave.types.manage')
                        <flux:sidebar.item icon="calendar-days" :href="route('leave.types')" :current="request()->routeIs('leave.types')" wire:navigate>
                            {{ __('Leave types') }}
                        </flux:sidebar.item>
                    @endcan
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter="LeaveTypeScreenTest|RolesTest"`
Expected: PASS.

Then `php artisan test`, `npm run build`, `vendor/bin/pint --dirty`.

- [ ] **Step 8: Hand Dan the commit message**

```
Add the leave permissions and the leave types screen
```

---

## Task 6: One employee's ledger

**Files:**
- Create: `resources/views/pages/leave/⚡ledger.blade.php`
- Modify: `routes/leave.php`
- Modify: `resources/views/pages/employees/⚡index.blade.php`
- Test: `tests/Feature/Leave/LeaveLedgerScreenTest.php`

**Interfaces:**
- Consumes: `LeaveLedger`, `LeaveBalance` (Tasks 2 and 3), `Employee`.
- Produces: route `leave.ledger` at `leave/ledger/{employee}`, reached from a **Leave** link in the employee list.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/LeaveLedgerScreenTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeaveLedgerScreenTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        $this->employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_hr_enters_an_opening_balance(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $this->employee])
            ->set('ledger', 'vacation')
            ->set('days', '12.5')
            ->call('openBalance')
            ->assertHasNoErrors();

        $this->assertSame(12.5, app(LeaveLedger::class)->balance($this->employee, 'vacation'));
    }

    public function test_a_second_opening_balance_is_refused_with_a_message(): void
    {
        app(LeaveLedger::class)->open($this->employee, 'vacation', 10);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $this->employee])
            ->set('ledger', 'vacation')
            ->set('days', '12')
            ->call('openBalance')
            ->assertHasErrors('days');

        $this->assertSame(10.0, app(LeaveLedger::class)->balance($this->employee, 'vacation'));
    }

    public function test_hr_adjusts_a_balance_with_a_reason(): void
    {
        app(LeaveLedger::class)->open($this->employee, 'vacation', 10);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $this->employee])
            ->set('ledger', 'vacation')
            ->set('days', '-2')
            ->set('reason', 'Corrected from the 2025 spreadsheet')
            ->call('adjust')
            ->assertHasNoErrors();

        $this->assertSame(8.0, app(LeaveLedger::class)->balance($this->employee, 'vacation'));
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $this->employee])
            ->set('ledger', 'vacation')
            ->set('days', '-2')
            ->set('reason', '')
            ->call('adjust')
            ->assertHasErrors('reason');

        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_an_employee_cannot_reach_anybodys_ledger_including_their_own(): void
    {
        // Reading a balance is not the same as changing one, and this screen
        // only changes. What an employee sees is My leave, in Phase 2a-2.
        $user = $this->userWithRole('employee');
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('leave.ledger', ['employee' => $own->id]))
            ->assertForbidden();
    }

    public function test_a_tampered_employee_id_cannot_redirect_a_write(): void
    {
        $hr = $this->userWithRole('hr');

        $component = Livewire::actingAs($hr)
            ->test('pages::leave.ledger', ['employee' => $this->employee]);

        $hr->removeRole('hr');

        $component->set('ledger', 'vacation')
            ->set('days', '10')
            ->call('openBalance')
            ->assertForbidden();

        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_the_entries_are_listed_newest_first(): void
    {
        app(LeaveLedger::class)->open($this->employee, 'vacation', 10);
        app(LeaveLedger::class)->adjust($this->employee, 'vacation', 3, 'Awarded');

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $this->employee])
            ->assertViewHas('entries', fn ($entries) => $entries->first()->description === 'Awarded');
    }

    public function test_the_employee_list_offers_hr_a_leave_link(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee(route('leave.ledger', ['employee' => $this->employee->id]), escape: false);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=LeaveLedgerScreenTest`
Expected: FAIL — `Route [leave.ledger] not defined`.

- [ ] **Step 3: The route**

In `routes/leave.php`, inside the group:

```php
    Route::livewire('leave/ledger/{employee}', 'pages::leave.ledger')->name('leave.ledger');
```

- [ ] **Step 4: The screen**

`resources/views/pages/leave/⚡ledger.blade.php`:

```php
<?php

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveLedger;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Leave ledger')] class extends Component {
    public int $employeeId;

    public string $ledger = '';

    public string $days = '';

    public string $reason = '';

    public function mount(Employee $employee): void
    {
        $this->authorize('leave.manage');

        $this->employeeId = $employee->id;
    }

    public function openBalance(): void
    {
        // mount() ran once. employeeId is rehydrated from the browser on every
        // later request, so the write asks again.
        $this->authorize('leave.manage');

        $this->validate([
            'ledger' => ['required', 'string'],
            'days' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            app(LeaveLedger::class)->open($this->subject(), $this->ledger, (float) $this->days);
        } catch (ValidationException $e) {
            // The service refuses a second opening balance. Show its words
            // rather than a generic failure; they say what to do instead.
            $this->addError('days', $e->validator->errors()->first());

            return;
        }

        $this->reset(['days', 'reason']);

        Flux::toast(variant: 'success', text: __('Opening balance recorded.'));
    }

    public function adjust(): void
    {
        $this->authorize('leave.manage');

        $this->validate([
            'ledger' => ['required', 'string'],
            'days' => ['required', 'numeric'],
            // An unexplained change to somebody's leave balance is the entry a
            // person will ask about a year later.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        app(LeaveLedger::class)->adjust($this->subject(), $this->ledger, (float) $this->days, $this->reason);

        $this->reset(['days', 'reason']);

        Flux::toast(variant: 'success', text: __('Adjustment recorded.'));
    }

    private function subject(): Employee
    {
        return Employee::findOrFail($this->employeeId);
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $employee = $this->subject();

        return [
            'employee' => $employee,
            'balances' => app(LeaveBalance::class)->for($employee),
            'entries' => LeaveLedgerEntry::where('employee_id', $employee->id)
                ->with('createdBy')
                ->latest('id')
                ->paginate(25),
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ $employee->fullName() }}</flux:heading>
    <flux:subheading>
        {{ __('Every movement of every credit. Entries are added, never changed.') }}
    </flux:subheading>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($balances as $balance)
            <flux:card wire:key="balance-{{ $balance['ledger'] }}">
                <flux:subheading>{{ $balance['label'] }}</flux:subheading>
                <flux:heading size="xl">{{ number_format($balance['days'], 2) }}</flux:heading>
            </flux:card>
        @endforeach
    </div>

    <flux:card class="mt-8 max-w-3xl space-y-6">
        <flux:heading size="lg">{{ __('Record an entry') }}</flux:heading>

        <div class="grid gap-6 sm:grid-cols-3">
            <flux:select wire:model="ledger" :label="__('Balance')" :placeholder="__('Choose')">
                @foreach ($balances as $balance)
                    <flux:select.option wire:key="option-{{ $balance['ledger'] }}" value="{{ $balance['ledger'] }}">
                        {{ $balance['label'] }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="days"
                type="number"
                step="0.25"
                :label="__('Days')"
                :description="__('Negative takes credits away.')"
            />

            <flux:input wire:model="reason" :label="__('Reason')" :description="__('Adjustments only.')" />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button wire:click="openBalance" variant="primary">
                {{ __('Record the opening balance') }}
            </flux:button>

            <flux:button wire:click="adjust" variant="filled">
                {{ __('Record an adjustment') }}
            </flux:button>
        </div>

        <flux:text class="text-sm">
            {{ __('An opening balance is what was carried in from the spreadsheet, and is recorded once. Everything after it is an adjustment, and an adjustment says why.') }}
        </flux:text>
    </flux:card>

    <flux:table class="mt-8" :paginate="$entries">
        <flux:table.columns>
            <flux:table.column>{{ __('Date') }}</flux:table.column>
            <flux:table.column>{{ __('Balance') }}</flux:table.column>
            <flux:table.column>{{ __('Kind') }}</flux:table.column>
            <flux:table.column>{{ __('Days') }}</flux:table.column>
            <flux:table.column>{{ __('Reason') }}</flux:table.column>
            <flux:table.column>{{ __('Recorded by') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($entries as $entry)
                <flux:table.row wire:key="entry-{{ $entry->id }}">
                    <flux:table.cell>{{ $entry->effective_date->format('d/m/Y') }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->ledger }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->kind->label() }}</flux:table.cell>
                    <flux:table.cell class="font-medium">{{ number_format($entry->days, 2) }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->description }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->createdBy?->name }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">
                        {{ __('No entries yet. Record the opening balance to start.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
```

- [ ] **Step 5: The link from the employee list**

In `resources/views/pages/employees/⚡index.blade.php`, inside the actions cell, after the Edit link:

```blade
                            @can('leave.manage')
                                <flux:link
                                    :href="route('leave.ledger', ['employee' => $employee->id])"
                                    wire:navigate
                                >
                                    {{ __('Leave') }}
                                </flux:link>
                            @endcan
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=LeaveLedgerScreenTest`
Expected: PASS, 8 tests.

Then `php artisan test`, `npm run build`, `vendor/bin/pint --dirty`.

- [ ] **Step 7: Hand Dan the commit message**

```
Add the leave ledger screen with opening balances and adjustments
```

---

## Task 7: Posting the month on screen

**Files:**
- Create: `resources/views/pages/leave/⚡accrual.blade.php`
- Modify: `routes/leave.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/Leave/AccrualScreenTest.php`

**Interfaces:**
- Consumes: `AccrualPosting` (Task 4).
- Produces: route `leave.accrual` at `leave/accrual`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/AccrualScreenTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccrualScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_hr_posts_a_month(): void
    {
        Employee::factory()->create(['employment_status' => EmploymentStatus::Permanent->value]);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('period', '2026-09')
            ->call('post');

        $this->assertSame(2, LeaveLedgerEntry::count());
    }

    public function test_posting_twice_writes_once(): void
    {
        // The whole reason this is a button and not a schedule is that a human
        // can see what happened. What they must not see is a second 1.25.
        Employee::factory()->create(['employment_status' => EmploymentStatus::Permanent->value]);

        $component = Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('period', '2026-09');

        $component->call('post');
        $component->call('post');

        $this->assertSame(2, LeaveLedgerEntry::count());
    }

    public function test_the_preview_writes_nothing(): void
    {
        Employee::factory()->create(['employment_status' => EmploymentStatus::Permanent->value]);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('period', '2026-09')
            ->assertViewHas('rows', fn ($rows) => count($rows) === 2);

        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_a_malformed_period_is_refused_before_it_reaches_the_service(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('period', 'September')
            ->call('post')
            ->assertHasErrors('period');
    }

    public function test_an_employee_cannot_reach_the_posting_screen(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get(route('leave.accrual'))
            ->assertForbidden();
    }

    public function test_a_post_re_asks_instead_of_trusting_mount(): void
    {
        Employee::factory()->create(['employment_status' => EmploymentStatus::Permanent->value]);

        $hr = $this->userWithRole('hr');

        $component = Livewire::actingAs($hr)
            ->test('pages::leave.accrual')
            ->set('period', '2026-09');

        $hr->removeRole('hr');

        $component->call('post')->assertForbidden();

        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_hr_posts_the_yearly_grants(): void
    {
        // A job order's Wellness credit arrives only this way.
        $jobOrder = Employee::factory()->create([
            'employment_status' => EmploymentStatus::JobOrder->value,
        ]);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('year', '2026')
            ->call('postGrants');

        $this->assertSame(
            5.0,
            app(\App\Services\Leave\LeaveLedger::class)->balance($jobOrder, 'wellness')
        );
    }

    public function test_a_malformed_year_is_refused_before_it_reaches_the_service(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('year', '26')
            ->call('postGrants')
            ->assertHasErrors('year');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=AccrualScreenTest`
Expected: FAIL — `Route [leave.accrual] not defined`.

- [ ] **Step 3: The route**

In `routes/leave.php`, inside the group, **above** the `{employee}` route so no wildcard swallows it:

```php
    Route::livewire('leave/accrual', 'pages::leave.accrual')->name('leave.accrual');
```

- [ ] **Step 4: The screen**

`resources/views/pages/leave/⚡accrual.blade.php`:

```php
<?php

use App\Services\Leave\AccrualPosting;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Post leave credits')] class extends Component {
    public string $period = '';

    public string $year = '';

    public function mount(): void
    {
        $this->authorize('leave.manage');

        // Last month, because that is the one being closed.
        $this->period = now()->subMonth()->format('Y-m');
        $this->year = now()->format('Y');
    }

    public function post(): void
    {
        // mount() ran once. The period comes back from the browser on every
        // later request, so the post asks again — and validates again.
        $this->authorize('leave.manage');

        $this->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [
            'period.regex' => __('A period is a year and a month, like 2026-09.'),
        ]);

        $written = app(AccrualPosting::class)->post($this->period);

        Flux::toast(
            variant: $written > 0 ? 'success' : 'warning',
            text: $written > 0
                ? __(':count entries posted.', ['count' => $written])
                : __('Nothing to post. This month is already recorded.'),
        );
    }

    public function postGrants(): void
    {
        $this->authorize('leave.manage');

        $this->validate([
            'year' => ['required', 'regex:/^\d{4}$/'],
        ], [
            'year.regex' => __('A year is four digits, like 2026.'),
        ]);

        $written = app(AccrualPosting::class)->postGrants($this->year);

        Flux::toast(
            variant: $written > 0 ? 'success' : 'warning',
            text: $written > 0
                ? __(':count grants posted.', ['count' => $written])
                : __('Nothing to post. This year is already granted.'),
        );
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $posting = app(AccrualPosting::class);

        return [
            // The previews write nothing. They are what a person reads before
            // pressing a button that changes 194 balances.
            'rows' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period) === 1
                ? $posting->preview($this->period)
                : [],
            'grantRows' => preg_match('/^\d{4}$/', $this->year) === 1
                ? $posting->previewGrants($this->year)
                : [],
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Post leave credits') }}</flux:heading>
    <flux:subheading>
        {{ __('1.25 vacation and 1.25 sick per month, for active permanent and co-terminous staff.') }}
    </flux:subheading>

    <flux:card class="mt-6 max-w-xl space-y-6">
        <flux:input
            wire:model.live="period"
            :label="__('Month')"
            placeholder="2026-09"
            :description="__('A year and a month, like 2026-09.')"
        />

        <flux:callout icon="information-circle">
            {{ __('Pressing this twice is safe. A month already recorded is not recorded again.') }}
        </flux:callout>

        <flux:button wire:click="post" variant="primary">{{ __('Post the credits') }}</flux:button>
    </flux:card>

    {{--
        The yearly grants are a different event with a different key. Wellness
        Leave arrives only this way, so a job order with no grant posted has
        nothing to file against and the type is decoration.
    --}}
    <flux:card class="mt-6 max-w-xl space-y-6">
        <flux:heading size="lg">{{ __('Yearly grants') }}</flux:heading>
        <flux:subheading>
            {{ __('Special Privilege 3, Solo Parent 7, Wellness 5. Once a year, per person.') }}
        </flux:subheading>

        <flux:input wire:model.live="year" :label="__('Year')" placeholder="2026" />

        <flux:button wire:click="postGrants" variant="primary">{{ __('Post the grants') }}</flux:button>

        @if ($grantRows !== [])
            <flux:text class="text-sm">
                {{ __(':total to post, :done already granted.', [
                    'total' => collect($grantRows)->where('already_posted', false)->count(),
                    'done' => collect($grantRows)->where('already_posted', true)->count(),
                ]) }}
            </flux:text>
        @endif
    </flux:card>

    <flux:table class="mt-8">
        <flux:table.columns>
            <flux:table.column>{{ __('Employee') }}</flux:table.column>
            <flux:table.column>{{ __('Balance') }}</flux:table.column>
            <flux:table.column>{{ __('Days') }}</flux:table.column>
            <flux:table.column>{{ __('State') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($rows as $row)
                <flux:table.row wire:key="accrual-{{ $row['employee']->id }}-{{ $row['ledger'] }}">
                    <flux:table.cell class="font-medium">{{ $row['employee']->fullName() }}</flux:table.cell>
                    <flux:table.cell>{{ $row['ledger'] }}</flux:table.cell>
                    <flux:table.cell>{{ number_format($row['days'], 2) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$row['already_posted'] ? 'zinc' : 'green'">
                            {{ $row['already_posted'] ? __('Already posted') : __('Will be posted') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="text-center">
                        {{ __('Nobody accrues credits for that month.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
```

- [ ] **Step 5: The sidebar entry**

In `resources/views/layouts/app/sidebar.blade.php`, before the Leave types item:

```blade
                    @can('leave.manage')
                        <flux:sidebar.item icon="calculator" :href="route('leave.accrual')" :current="request()->routeIs('leave.accrual')" wire:navigate>
                            {{ __('Post leave credits') }}
                        </flux:sidebar.item>
                    @endcan
```

- [ ] **Step 6: Run everything**

Run: `php artisan test --filter=AccrualScreenTest` — expected PASS, 8 tests.

If `flux:checkbox.group` in Task 5 is not available in this Flux edition, replace it with a `flux:select` carrying `multiple`; the property is a plain array either way and no test changes.

Then the full verification: `php artisan test`, `npm run build`, `vendor/bin/pint --dirty`. Report the real numbers.

- [ ] **Step 7: Hand Dan the commit message**

```
Add the monthly leave credit posting screen
```

---

## What this plan does not build

Phase 2a-2 adds `leave_applications`, `leave_approvals`, the `leave_application_id` column on the ledger, `LeaveRoute`, `LeaveFiler`, `LeaveDecision`, the hold / release / commit entries, and the My leave and Approvals screens. Phase 2a-3 adds the CS Form 6 export against the linked template.

Two things must come from the HR office before this phase is usable rather than merely finished: the **opening balances** for all 134 employees, and confirmation of **whether the yearly grants reset or accumulate** — SPL is use-it-or-lose-it under the Omnibus Rules, and Wellness Leave is the hospital's own to decide. The ledger records grants per year either way; what changes is whether an unspent year is cleared.
