# Phase 2a-2: Leave Applications and Approvals — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An employee files for leave, the credits are held the moment they do, and the application walks a chain derived from where they sit in the organisational chart.

**Architecture:** `LeaveRoute` builds the chain from the org chart rather than from a stored template. `LeaveFiler` writes the application, its approvals and its holds in one transaction. `LeaveDecision` applies an approver's action and moves the ledger. Both go through `LeaveLedger`, which stays the only class that writes an entry. Two screens: My leave for everyone, Approvals for whoever holds a step.

**Tech Stack:** Laravel 13 / PHP 8.3, Livewire 4 single-file components, Flux UI, Tailwind v4, MySQL 8, `spatie/laravel-permission`, PHPUnit 12 on in-memory SQLite.

**Spec:** `docs/superpowers/specs/2026-09-04-phase-2a-leave-design.md`

**Depends on:** Phase 2a-1 (`docs/superpowers/plans/2026-09-04-phase-2a-1-leave-credits.md`), complete. `LeaveType`, `LeaveLedgerEntry`, `LeaveLedger`, `LeaveBalance` and `AccrualPosting` exist and are tested.

## Global Constraints

Copied from `CLAUDE.md` and the spec. Every task's requirements implicitly include this section.

- **Never run `git commit` or `git push`.** Dan commits. Each task ends by handing him one commit message.
- **English only** in code, comments and every user-facing string.
- Verification before any task is called done: `php artisan test` (all of it), `npm run build` (whenever a Blade view changed), `vendor/bin/pint --dirty`. Report the real numbers.
- **`authorize()` in Livewire `mount()` AND again in every save**, and in every method reached by a `wire:click` or a dispatched event. `mount()` runs once; public properties are rehydrated from the browser on every later request.
- **Ownership belongs in a policy, never a permission.**
- **Refuse rather than silently skip.**
- Models declare `#[Fillable([...])]`. Validate first, pass only the validated array to `create()`/`update()`.
- Livewire 4 single-file pages at `resources/views/pages/<dir>/⚡<name>.blade.php`, referenced as `pages::<dir>.<name>`, routed with `Route::livewire()`.
- Flux UI for every control. Repeating rows bind `wire:key` to a stable key, never `$index`.
- **`LeaveLedger` remains the only class that writes a ledger entry.** Nothing in this plan writes `LeaveLedgerEntry::create()` directly.
- Reading somebody else's leave is recorded through `App\Services\AuditRecorder`, the same as their PDS. A sick leave says something about a person's health.
- Tests are PHPUnit classes extending `Tests\TestCase` with `RefreshDatabase`. PHPUnit 12 ignores `@dataProvider`.

---

## Task 1: The approval route

**Files:**
- Create: `app/Enums/LeaveStep.php`
- Create: `app/Services/Leave/LeaveRoute.php`
- Create: `app/Services/Leave/RouteStep.php`
- Test: `tests/Feature/Leave/LeaveRouteTest.php`

**Interfaces:**
- Consumes: `Employee`, `Section`, `Division` (Phase 1a). The heads are already columns: `sections.section_head_employee_id`, `divisions.division_head_employee_id`, `employees.is_chief_of_hospital`.
- Produces: `LeaveStep` enum (`SectionHead`, `Hr`, `DivisionHead`, `Chief`); `RouteStep` readonly DTO with `step: LeaveStep` and `approver: ?Employee`; `LeaveRoute::for(Employee $applicant): array` returning `list<RouteStep>`, throwing `ValidationException` when a step that applies has nobody to sign it.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/LeaveRouteTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveStep;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Section;
use App\Services\Leave\LeaveRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveRouteTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private Section $section;

    private Employee $divisionHead;

    private Employee $sectionHead;

    private Employee $chief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->division = Division::factory()->create();
        $this->section = Section::factory()->create(['division_id' => $this->division->id]);

        $this->chief = Employee::factory()->create([
            'is_chief_of_hospital' => true,
            'section_id' => null,
            'division_id' => null,
        ]);

        $this->divisionHead = Employee::factory()->create([
            'section_id' => null,
            'division_id' => $this->division->id,
        ]);

        $this->sectionHead = Employee::factory()->create(['section_id' => $this->section->id]);

        $this->division->update(['division_head_employee_id' => $this->divisionHead->id]);
        $this->section->update(['section_head_employee_id' => $this->sectionHead->id]);
    }

    /** @return list<string> */
    private function stepsFor(Employee $applicant): array
    {
        return array_map(
            fn ($step) => $step->step->value,
            app(LeaveRoute::class)->for($applicant)
        );
    }

    public function test_staff_go_through_all_four(): void
    {
        $staff = Employee::factory()->create(['section_id' => $this->section->id]);

        $this->assertSame(
            ['section_head', 'hr', 'division_head', 'chief'],
            $this->stepsFor($staff)
        );
    }

    public function test_a_section_head_does_not_recommend_their_own_leave(): void
    {
        $this->assertSame(
            ['hr', 'division_head', 'chief'],
            $this->stepsFor($this->sectionHead)
        );
    }

    public function test_a_division_head_skips_both_steps_below_them(): void
    {
        // A division head sits in no section, so there is no section head step
        // to remove — and they cannot recommend their own leave.
        $this->assertSame(['hr', 'chief'], $this->stepsFor($this->divisionHead));
    }

    public function test_the_chief_is_left_with_hr(): void
    {
        $this->assertSame(['hr'], $this->stepsFor($this->chief));
    }

    public function test_the_named_approvers_are_the_ones_from_the_chart(): void
    {
        $staff = Employee::factory()->create(['section_id' => $this->section->id]);

        $route = app(LeaveRoute::class)->for($staff);

        $this->assertSame($this->sectionHead->id, $route[0]->approver->id);
        // HR is an office, not a person: whoever holds leave.manage acts.
        $this->assertNull($route[1]->approver);
        $this->assertSame($this->divisionHead->id, $route[2]->approver->id);
        $this->assertSame($this->chief->id, $route[3]->approver->id);
    }

    public function test_a_section_with_no_head_refuses_the_filing_by_name(): void
    {
        // Skipping a step nobody filled would produce an application that
        // reached the Chief without a recommendation and looked complete on the
        // way. On a signed document that is worse than a refusal.
        $this->section->update(['section_head_employee_id' => null]);

        $staff = Employee::factory()->create(['section_id' => $this->section->id]);

        try {
            app(LeaveRoute::class)->for($staff);
            $this->fail('The route should have been refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($this->section->name, $e->validator->errors()->first());
        }
    }

    public function test_a_division_with_no_head_refuses_the_filing_by_name(): void
    {
        $this->division->update(['division_head_employee_id' => null]);

        $staff = Employee::factory()->create(['section_id' => $this->section->id]);

        $this->expectException(ValidationException::class);

        app(LeaveRoute::class)->for($staff);
    }

    public function test_no_chief_on_record_refuses_the_filing(): void
    {
        $this->chief->update(['is_chief_of_hospital' => false]);

        $staff = Employee::factory()->create(['section_id' => $this->section->id]);

        $this->expectException(ValidationException::class);

        app(LeaveRoute::class)->for($staff);
    }

    public function test_an_employee_in_no_section_and_no_division_still_reaches_hr_and_the_chief(): void
    {
        // The import left some records without a placement. They can still file.
        $unplaced = Employee::factory()->create(['section_id' => null, 'division_id' => null]);

        $this->assertSame(['hr', 'chief'], $this->stepsFor($unplaced));
    }

    public function test_a_head_who_leads_the_division_their_section_sits_in_is_removed_once(): void
    {
        // A section head promoted to division head while still holding the
        // section would otherwise be asked to sign twice.
        $this->division->update(['division_head_employee_id' => $this->sectionHead->id]);

        $this->assertSame(['hr', 'chief'], $this->stepsFor($this->sectionHead));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=LeaveRouteTest`
Expected: FAIL — `Class "App\Enums\LeaveStep" not found`.

- [ ] **Step 3: The step vocabulary**

`app/Enums/LeaveStep.php`:

```php
<?php

namespace App\Enums;

/**
 * The four signatures on a leave application, in the order they are collected.
 *
 * Three of them are people, read from the organisational chart. HR is an
 * office: whoever holds leave.manage acts, and the person who actually pressed
 * the button is recorded. An office does not go on leave.
 */
enum LeaveStep: string
{
    case SectionHead = 'section_head';
    case Hr = 'hr';
    case DivisionHead = 'division_head';
    case Chief = 'chief';

    public function label(): string
    {
        return match ($this) {
            self::SectionHead => 'Section head',
            self::Hr => 'Human Resource',
            self::DivisionHead => 'Division head',
            self::Chief => 'Chief of Hospital',
        };
    }

    /** What this step does to the application, in the words on CS Form 6. */
    public function action(): string
    {
        return match ($this) {
            self::SectionHead => 'Initials',
            self::Hr => 'Certifies the leave credits',
            self::DivisionHead => 'Recommends',
            self::Chief => 'Approves',
        };
    }
}
```

- [ ] **Step 4: The step DTO**

`app/Services/Leave/RouteStep.php`:

```php
<?php

namespace App\Services\Leave;

use App\Enums\LeaveStep;
use App\Models\Employee;

/**
 * One signature on one application.
 *
 * `approver` is null for the HR step and only for the HR step: that one is held
 * by an office rather than a person.
 */
readonly class RouteStep
{
    public function __construct(
        public LeaveStep $step,
        public ?Employee $approver,
    ) {}
}
```

- [ ] **Step 5: The route**

`app/Services/Leave/LeaveRoute.php`:

```php
<?php

namespace App\Services\Leave;

use App\Enums\LeaveStep;
use App\Models\Employee;
use Illuminate\Validation\ValidationException;

/**
 * The chain an application walks, derived from the organisational chart.
 *
 * The HR office described four routes; they are one rule. The full chain is
 * section head, HR, division head, Chief, and every step the applicant
 * themselves would sign is removed. Nobody recommends their own leave.
 *
 * Nothing is stored. `sections.section_head_employee_id`,
 * `divisions.division_head_employee_id` and `employees.is_chief_of_hospital`
 * are the chart, and a second table of approvers would drift away from it.
 */
class LeaveRoute
{
    /** @return list<RouteStep> */
    public function for(Employee $applicant): array
    {
        $applicant->loadMissing(['section.division', 'division']);

        $division = $applicant->section?->division ?? $applicant->division;

        $candidates = [
            // A step applies only if the applicant sits in that unit. A
            // division head is in no section, so there is no section head above
            // them to skip.
            [LeaveStep::SectionHead, $applicant->section !== null, fn () => $applicant->section?->head,
                fn () => __('No section head is recorded for :name.', ['name' => $applicant->section?->name])],

            [LeaveStep::Hr, true, fn () => null, fn () => ''],

            [LeaveStep::DivisionHead, $division !== null, fn () => $division?->head,
                fn () => __('No division head is recorded for :name.', ['name' => $division?->name])],

            [LeaveStep::Chief, true, fn () => Employee::where('is_chief_of_hospital', true)->first(),
                fn () => __('No Chief of Hospital is recorded.')],
        ];

        $route = [];

        foreach ($candidates as [$step, $applies, $resolve, $message]) {
            if (! $applies) {
                continue;
            }

            if ($step === LeaveStep::Hr) {
                $route[] = new RouteStep($step, null);

                continue;
            }

            $approver = $resolve();

            if ($approver === null) {
                // Refused, not skipped. An application that reached the Chief
                // without a recommendation would look complete on the way.
                throw ValidationException::withMessages([
                    'leave_type_id' => $message().' '.__('Ask HR to set one before filing.'),
                ]);
            }

            // Nobody signs their own leave — including a section head who also
            // leads the division, who would otherwise be asked twice.
            if ($approver->id === $applicant->id) {
                continue;
            }

            $route[] = new RouteStep($step, $approver);
        }

        return $route;
    }
}
```

- [ ] **Step 6: The head relationships**

`Section::head()` and `Division::head()` already exist from Phase 1a. Confirm with
`grep -n "function head" app/Models/Section.php app/Models/Division.php`; if either is missing, add:

```php
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'section_head_employee_id');
    }
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=LeaveRouteTest`
Expected: PASS, 10 tests. Then `php artisan test` and `vendor/bin/pint --dirty`.

- [ ] **Step 8: Hand Dan the commit message**

```
Derive the leave approval route from the organizational chart
```

---

## Task 2: The application and its approvals

**Files:**
- Create: `database/migrations/2026_09_05_100000_create_leave_applications_table.php`
- Create: `database/migrations/2026_09_05_100100_create_leave_approvals_table.php`
- Create: `database/migrations/2026_09_05_100200_add_leave_application_id_to_leave_ledger_entries.php`
- Create: `app/Enums/LeaveStatus.php`
- Create: `app/Enums/ApprovalAction.php`
- Create: `app/Models/LeaveApplication.php`
- Create: `app/Models/LeaveApproval.php`
- Create: `database/factories/LeaveApplicationFactory.php`
- Modify: `app/Models/LeaveLedgerEntry.php`
- Test: `tests/Feature/Leave/LeaveApplicationModelTest.php`

**Interfaces:**
- Consumes: `LeaveType`, `Employee`, `LeaveStep` (Task 1).
- Produces: `LeaveStatus` (`Pending`, `Approved`, `Disapproved`, `Returned`, `Cancelled`); `ApprovalAction` (`Approve`, `Disapprove`, `Return`); `LeaveApplication` with `approvals()`, `type()`, `employee()`, `ledgerEntries()`, `currentApproval()`, `isPending()`; `LeaveApproval` with `application()`, `approver()`, `actedBy()`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/LeaveApplicationModelTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\ApprovalAction;
use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveApplicationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_current_approval_is_the_first_one_nobody_has_acted_on(): void
    {
        $application = LeaveApplication::factory()->create(['status' => LeaveStatus::Pending]);

        foreach ([LeaveStep::SectionHead, LeaveStep::Hr, LeaveStep::DivisionHead] as $i => $step) {
            LeaveApproval::create([
                'leave_application_id' => $application->id,
                'sequence' => $i + 1,
                'step' => $step,
            ]);
        }

        $application->approvals()->where('sequence', 1)->update([
            'action' => ApprovalAction::Approve,
            'acted_at' => now(),
        ]);

        $this->assertSame(LeaveStep::Hr, $application->fresh()->currentApproval()?->step);
    }

    public function test_an_application_nobody_is_waiting_on_has_no_current_approval(): void
    {
        $application = LeaveApplication::factory()->create(['status' => LeaveStatus::Approved]);

        $this->assertNull($application->currentApproval());
    }

    public function test_the_days_columns_hold_halves(): void
    {
        // CSC leave is filed in half days, and a column that rounded would
        // quietly turn half a day into none or one.
        $application = LeaveApplication::factory()->create([
            'days' => 2.5,
            'days_with_pay' => 1.5,
            'days_without_pay' => 1,
        ]);

        $this->assertSame(2.5, $application->fresh()->days);
        $this->assertSame(1.5, $application->fresh()->days_with_pay);
    }

    public function test_the_details_are_json_not_one_text_box(): void
    {
        // CS Form 6 item 6.B asks different questions per type: within the
        // Philippines or abroad, in hospital or out patient with the illness
        // named, the purpose of a study leave.
        $application = LeaveApplication::factory()->create([
            'details' => ['sick_where' => 'out_patient', 'sick_illness' => 'Dengue'],
        ]);

        $this->assertSame('Dengue', $application->fresh()->details['sick_illness']);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=LeaveApplicationModelTest`
Expected: FAIL — `Class "App\Enums\ApprovalAction" not found`.

- [ ] **Step 3: The two enums**

`app/Enums/LeaveStatus.php`:

```php
<?php

namespace App\Enums;

enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Disapproved = 'disapproved';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Disapproved => 'Disapproved',
            self::Returned => 'Returned for correction',
            self::Cancelled => 'Cancelled',
        };
    }

    /** The colour a Flux badge uses for it. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'green',
            self::Disapproved => 'red',
            self::Returned => 'orange',
            self::Cancelled => 'zinc',
        };
    }

    /** Credits are held only while one of these is true. */
    public function holdsCredits(): bool
    {
        return $this === self::Pending;
    }
}
```

`app/Enums/ApprovalAction.php`:

```php
<?php

namespace App\Enums;

enum ApprovalAction: string
{
    case Approve = 'approve';
    case Disapprove = 'disapprove';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Approved',
            self::Disapprove => 'Disapproved',
            self::Return => 'Returned for correction',
        };
    }
}
```

- [ ] **Step 4: The migrations**

`database/migrations/2026_09_05_100000_create_leave_applications_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One leave application, in the shape of CS Form 6.
 *
 * The paid and unpaid days are stored rather than computed on the way out. They
 * are decided against the balance at the moment of filing, and item 7.C of the
 * form prints them; recomputing later against a balance that has moved would
 * print a different form than the one that was signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();

            $table->date('date_from');
            $table->date('date_to');

            // Halves, because CSC leave is filed in half days.
            $table->decimal('days', 5, 2);
            $table->decimal('days_with_pay', 5, 2)->default(0);
            $table->decimal('days_without_pay', 5, 2)->default(0);

            // Item 6.B: the answers differ by type, so this is not one text box.
            $table->json('details')->nullable();

            // Item 6.D.
            $table->string('commutation', 20)->default('not_requested');

            $table->string('status', 20)->default('pending');
            $table->timestamp('filed_at')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_applications');
    }
};
```

`database/migrations/2026_09_05_100100_create_leave_approvals_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signatures, written when the application is filed.
 *
 * `approver_employee_id` names the person who holds that step at that moment.
 * If the section head changes next week, the person who signed last week is
 * still the person recorded as having signed. It is null for the HR step and
 * only for the HR step: that one is held by an office, and whoever acts is
 * recorded in `acted_by_user_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('sequence');
            $table->string('step', 20);

            $table->foreignId('approver_employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->string('action', 20)->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();

            $table->timestamps();

            $table->unique(['leave_application_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approvals');
    }
};
```

`database/migrations/2026_09_05_100200_add_leave_application_id_to_leave_ledger_entries.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a hold, a release and a commit back to the application that caused them.
 *
 * The ledger is the answer to "where did my credits go". Without this the
 * answer is "a hold", which is not an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->foreignId('leave_application_id')->nullable()->after('period')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['leave_application_id']);
            $table->dropColumn('leave_application_id');
        });
    }
};
```

- [ ] **Step 5: The models**

`app/Models/LeaveApplication.php`:

```php
<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use Database\Factories\LeaveApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_id', 'leave_type_id', 'date_from', 'date_to', 'days',
    'days_with_pay', 'days_without_pay', 'details', 'commutation',
    'status', 'filed_at', 'decided_at',
])]
class LeaveApplication extends Model
{
    /** @use HasFactory<LeaveApplicationFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'days' => 'float',
            'days_with_pay' => 'float',
            'days_without_pay' => 'float',
            'details' => 'array',
            'status' => LeaveStatus::class,
            'filed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveApproval::class)->orderBy('sequence');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    /** The step it is sitting on, or null when nobody is waiting on it. */
    public function currentApproval(): ?LeaveApproval
    {
        if ($this->status !== LeaveStatus::Pending) {
            return null;
        }

        return $this->approvals()->whereNull('acted_at')->orderBy('sequence')->first();
    }

    public function isPending(): bool
    {
        return $this->status === LeaveStatus::Pending;
    }

    /** Nobody has acted yet, so the applicant may still take it back. */
    public function isUntouched(): bool
    {
        return $this->isPending() && ! $this->approvals()->whereNotNull('acted_at')->exists();
    }
}
```

`app/Models/LeaveApproval.php`:

```php
<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use App\Enums\LeaveStep;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'leave_application_id', 'sequence', 'step', 'approver_employee_id',
    'action', 'remarks', 'acted_by_user_id', 'acted_at',
])]
class LeaveApproval extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step' => LeaveStep::class,
            'action' => ApprovalAction::class,
            'acted_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }

    /** Null for the HR step, which is held by an office rather than a person. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
```

- [ ] **Step 6: The factory**

`database/factories/LeaveApplicationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveApplication> */
class LeaveApplicationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'date_from' => now()->addWeek()->toDateString(),
            'date_to' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'days_with_pay' => 2,
            'days_without_pay' => 0,
            'details' => null,
            'commutation' => 'not_requested',
            'status' => LeaveStatus::Pending,
            'filed_at' => now(),
        ];
    }
}
```

- [ ] **Step 7: Tie the ledger entry to its application**

In `app/Models/LeaveLedgerEntry.php`, add `'leave_application_id'` to the `#[Fillable]` list and add:

```php
    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter=LeaveApplicationModelTest`
Expected: PASS, 4 tests. Then `php artisan test`, `php artisan migrate`, `vendor/bin/pint --dirty`.

- [ ] **Step 9: Hand Dan the commit message**

```
Add the leave application and approval tables
```

---

## Task 3: Filing

**Files:**
- Modify: `app/Services/Leave/LeaveLedger.php`
- Create: `app/Services/Leave/LeaveFiler.php`
- Test: `tests/Feature/Leave/LeaveFilerTest.php`

**Interfaces:**
- Consumes: `LeaveRoute` (Task 1), the models (Task 2), `LeaveLedger` and `LeaveBalance` (Phase 2a-1).
- Produces: `LeaveLedger::hold(...)` and `LeaveLedger::releaseFor(LeaveApplication $application): int`; `LeaveFiler::file(Employee $applicant, array $attributes): LeaveApplication` and `LeaveFiler::refile(LeaveApplication $application, array $attributes): LeaveApplication`.

`$attributes` keys: `leave_type_id`, `date_from`, `date_to`, `days`, `details`, `commutation`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/LeaveFilerTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveLedgerKind;
use App\Enums\LeaveStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\Section;
use App\Services\Leave\LeaveFiler;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveFilerTest extends TestCase
{
    use RefreshDatabase;

    private Employee $applicant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);

        $division = Division::factory()->create();
        $section = Section::factory()->create(['division_id' => $division->id]);

        $division->update(['division_head_employee_id' => Employee::factory()->create()->id]);
        $section->update(['section_head_employee_id' => Employee::factory()->create()->id]);
        Employee::factory()->create(['is_chief_of_hospital' => true]);

        $this->applicant = Employee::factory()->create(['section_id' => $section->id]);
    }

    /** @return array<string, mixed> */
    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'leave_type_id' => LeaveType::where('code', 'VL')->sole()->id,
            'date_from' => now()->addWeek()->toDateString(),
            'date_to' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'details' => null,
            'commutation' => 'not_requested',
        ], $overrides);
    }

    public function test_filing_writes_the_application_its_approvals_and_its_hold(): void
    {
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes());

        $this->assertSame(LeaveStatus::Pending, $application->status);
        $this->assertCount(4, $application->approvals);
        $this->assertSame(2.0, $application->days_with_pay);
        $this->assertSame(0.0, $application->days_without_pay);

        // The hold is what stops the next application seeing ten.
        $this->assertSame(8.0, app(LeaveLedger::class)->balance($this->applicant, 'vacation'));
    }

    public function test_the_hold_names_the_application_that_caused_it(): void
    {
        // The ledger is the answer to "where did my credits go". "A hold" is
        // not an answer.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes());

        $hold = LeaveLedgerEntry::where('kind', LeaveLedgerKind::Hold)->sole();

        $this->assertSame($application->id, $hold->leave_application_id);
        $this->assertSame(-2.0, $hold->days);
    }

    public function test_a_second_application_is_measured_against_what_is_left(): void
    {
        // Ten credits, three applications of eight. Without holds all three are
        // measured against ten, all three print as fully paid, and the hospital
        // pays 24 days out of a 10-day balance.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $first = app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 8]));
        $second = app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 8]));
        $third = app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 8]));

        $this->assertSame(8.0, $first->days_with_pay);
        $this->assertSame(0.0, $first->days_without_pay);

        $this->assertSame(2.0, $second->days_with_pay);
        $this->assertSame(6.0, $second->days_without_pay);

        $this->assertSame(0.0, $third->days_with_pay);
        $this->assertSame(8.0, $third->days_without_pay);
    }

    public function test_insufficient_credits_never_refuse_a_filing(): void
    {
        // Refusing would push leave without pay out of the system, which is the
        // one place it must not be, because it is the part that changes pay.
        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 3]));

        $this->assertSame(LeaveStatus::Pending, $application->status);
        $this->assertSame(3.0, $application->days_without_pay);
    }

    public function test_a_type_with_no_ledger_holds_nothing_and_is_fully_paid(): void
    {
        // Maternity leave is a right, not a balance.
        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'leave_type_id' => LeaveType::where('code', 'ML')->sole()->id,
            'days' => 105,
        ]));

        $this->assertSame(105.0, $application->days_with_pay);
        $this->assertSame(0.0, $application->days_without_pay);
        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_a_type_this_employment_status_cannot_file_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'leave_type_id' => LeaveType::where('code', 'WELLNESS')->sole()->id,
        ]));
    }

    public function test_too_little_notice_is_refused(): void
    {
        // Wellness Leave needs five days.
        $jobOrder = Employee::factory()->create([
            'employment_status' => \App\Enums\EmploymentStatus::JobOrder->value,
            'section_id' => $this->applicant->section_id,
        ]);

        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($jobOrder, $this->attributes([
            'leave_type_id' => LeaveType::where('code', 'WELLNESS')->sole()->id,
            'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
            'days' => 1,
        ]));
    }

    public function test_more_than_the_maximum_consecutive_days_is_refused(): void
    {
        $jobOrder = Employee::factory()->create([
            'employment_status' => \App\Enums\EmploymentStatus::JobOrder->value,
            'section_id' => $this->applicant->section_id,
        ]);

        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($jobOrder, $this->attributes([
            'leave_type_id' => LeaveType::where('code', 'WELLNESS')->sole()->id,
            'date_from' => now()->addWeeks(2)->toDateString(),
            'date_to' => now()->addWeeks(2)->addDays(5)->toDateString(),
            'days' => 4,
        ]));
    }

    public function test_a_date_range_that_runs_backwards_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'date_from' => now()->addWeek()->addDays(3)->toDateString(),
            'date_to' => now()->addWeek()->toDateString(),
        ]));
    }

    public function test_zero_days_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 0]));
    }

    public function test_a_missing_section_head_refuses_the_filing_and_writes_nothing(): void
    {
        // The route refuses, and the transaction takes the application with it.
        Section::where('id', $this->applicant->section_id)->update(['section_head_employee_id' => null]);

        try {
            app(LeaveFiler::class)->file($this->applicant, $this->attributes());
            $this->fail('The filing should have been refused.');
        } catch (ValidationException) {
            //
        }

        $this->assertDatabaseCount('leave_applications', 0);
        $this->assertDatabaseCount('leave_ledger_entries', 0);
    }

    public function test_refiling_a_returned_application_replaces_its_approvals_and_hold(): void
    {
        // A corrected application is a different one: the dates may have moved,
        // and a recommendation given for one set of dates does not carry.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 2]));
        $application->update(['status' => LeaveStatus::Returned]);
        app(LeaveLedger::class)->releaseFor($application);

        $refiled = app(LeaveFiler::class)->refile($application, $this->attributes(['days' => 4]));

        $this->assertSame(LeaveStatus::Pending, $refiled->status);
        $this->assertCount(4, $refiled->approvals()->get());
        $this->assertSame(6.0, app(LeaveLedger::class)->balance($this->applicant, 'vacation'));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=LeaveFilerTest`
Expected: FAIL — `Class "App\Services\Leave\LeaveFiler" not found`.

- [ ] **Step 3: Teach the ledger about holds**

In `app/Services/Leave/LeaveLedger.php`, add:

```php
    /**
     * Reserves credits for a pending application.
     *
     * Held at filing rather than at approval, so the next application sees what
     * is actually left. Three eight-day applications against ten credits would
     * otherwise each be measured against ten.
     */
    public function hold(LeaveApplication $application, string $ledger, float $days): ?LeaveLedgerEntry
    {
        if ($days <= 0) {
            return null;
        }

        return $this->write(
            $application->employee,
            $ledger,
            LeaveLedgerKind::Hold,
            -$days,
            null,
            null,
            __('Held for the application filed on :date', ['date' => now()->format('d/m/Y')]),
            $application,
        );
    }

    /**
     * Gives back what an application is holding: it was disapproved, returned
     * or cancelled.
     *
     * @return int how many entries were released
     */
    public function releaseFor(LeaveApplication $application): int
    {
        $holds = LeaveLedgerEntry::where('leave_application_id', $application->id)
            ->where('kind', LeaveLedgerKind::Hold)
            ->get();

        $released = LeaveLedgerEntry::where('leave_application_id', $application->id)
            ->where('kind', LeaveLedgerKind::Release)
            ->count();

        if ($released > 0) {
            // Already given back. Releasing twice would invent credits.
            return 0;
        }

        foreach ($holds as $hold) {
            $this->write(
                $application->employee,
                $hold->ledger,
                LeaveLedgerKind::Release,
                -$hold->days,
                null,
                null,
                __('Released'),
                $application,
            );
        }

        return $holds->count();
    }

    /**
     * The application was approved. The hold is released and the days are
     * committed in its place.
     *
     * Both entries are written rather than the hold being rewritten, because a
     * row whose meaning changed after the fact cannot be read in order.
     */
    public function commitFor(LeaveApplication $application): int
    {
        $holds = LeaveLedgerEntry::where('leave_application_id', $application->id)
            ->where('kind', LeaveLedgerKind::Hold)
            ->get();

        foreach ($holds as $hold) {
            $this->write($application->employee, $hold->ledger, LeaveLedgerKind::Release,
                -$hold->days, null, null, __('Released on approval'), $application);

            $this->write($application->employee, $hold->ledger, LeaveLedgerKind::Commit,
                $hold->days, null, null, __('Used'), $application);
        }

        return $holds->count();
    }
```

and widen `write()` to carry the application:

```php
    private function write(
        Employee $employee,
        string $ledger,
        LeaveLedgerKind $kind,
        float $days,
        ?Carbon $on,
        ?string $period,
        string $description,
        ?LeaveApplication $application = null,
    ): LeaveLedgerEntry {
        return DB::transaction(fn () => LeaveLedgerEntry::create([
            'employee_id' => $employee->id,
            'ledger' => $ledger,
            'kind' => $kind->value,
            'days' => $days,
            'effective_date' => $on ?? now(),
            'period' => $period,
            'leave_application_id' => $application?->id,
            'description' => $description,
            'created_by_user_id' => auth()->id(),
        ]));
    }
```

Add `use App\Models\LeaveApplication;` at the top.

- [ ] **Step 4: The filer**

`app/Services/Leave/LeaveFiler.php`:

```php
<?php

namespace App\Services\Leave;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a filled form into an application, its chain and its held credits.
 *
 * All three in one transaction. An application with no approvals is stuck; a
 * hold with no application is credits nobody can get back.
 */
class LeaveFiler
{
    public function __construct(
        private readonly LeaveRoute $route,
        private readonly LeaveLedger $ledger,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function file(Employee $applicant, array $attributes): LeaveApplication
    {
        return $this->write($applicant, $attributes, null);
    }

    /**
     * A returned application, corrected and sent again. The chain starts over:
     * the dates may have moved, and a recommendation given for one set of dates
     * does not carry to another.
     *
     * @param array<string, mixed> $attributes
     */
    public function refile(LeaveApplication $application, array $attributes): LeaveApplication
    {
        return $this->write($application->employee, $attributes, $application);
    }

    /** @param array<string, mixed> $attributes */
    private function write(Employee $applicant, array $attributes, ?LeaveApplication $existing): LeaveApplication
    {
        $type = LeaveType::findOrFail($attributes['leave_type_id']);

        $this->assertAllowed($applicant, $type, $attributes);

        return DB::transaction(function () use ($applicant, $attributes, $type, $existing) {
            // Refused before anything is written: the route throws when a step
            // that applies has nobody to sign it.
            $route = $this->route->for($applicant);

            $split = $this->split($applicant, $type, (float) $attributes['days']);

            $values = [
                'employee_id' => $applicant->id,
                'leave_type_id' => $type->id,
                'date_from' => $attributes['date_from'],
                'date_to' => $attributes['date_to'],
                'days' => (float) $attributes['days'],
                'days_with_pay' => $split['paid'],
                'days_without_pay' => $split['unpaid'],
                'details' => $attributes['details'] ?? null,
                'commutation' => $attributes['commutation'] ?? 'not_requested',
                'status' => LeaveStatus::Pending,
                'filed_at' => now(),
                'decided_at' => null,
            ];

            if ($existing) {
                $existing->update($values);
                $existing->approvals()->delete();
                $application = $existing->fresh();
            } else {
                $application = LeaveApplication::create($values);
            }

            foreach ($route as $index => $step) {
                $application->approvals()->create([
                    'sequence' => $index + 1,
                    'step' => $step->step->value,
                    'approver_employee_id' => $step->approver?->id,
                ]);
            }

            if ($type->isCredited() && $split['paid'] > 0) {
                $this->ledger->hold($application, $type->ledger, $split['paid']);
            }

            return $application->fresh(['approvals', 'type']);
        });
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{paid: float, unpaid: float}
     */
    private function split(Employee $applicant, LeaveType $type, float $days): array
    {
        if (! $type->isCredited()) {
            // Maternity leave is a right, not a balance.
            return ['paid' => $days, 'unpaid' => 0.0];
        }

        // The balance already has every pending hold subtracted from it, which
        // is what makes this split right rather than optimistic.
        $available = max(0, $this->ledger->balance($applicant, $type->ledger));

        $paid = min($days, $available);

        return ['paid' => $paid, 'unpaid' => round($days - $paid, 2)];
    }

    /** @param array<string, mixed> $attributes */
    private function assertAllowed(Employee $applicant, LeaveType $type, array $attributes): void
    {
        $errors = [];

        if (! $type->is_active || ! in_array($applicant->employment_status?->value, $type->applies_to, true)) {
            $errors['leave_type_id'] = __(':type is not available to :status staff.', [
                'type' => $type->name,
                'status' => $applicant->employment_status?->label() ?? __('this'),
            ]);
        }

        $from = Carbon::parse($attributes['date_from']);
        $to = Carbon::parse($attributes['date_to']);

        if ($to->lt($from)) {
            $errors['date_to'] = __('The last day is before the first.');
        }

        if ((float) $attributes['days'] <= 0) {
            $errors['days'] = __('Say how many days this is for.');
        }

        if ($type->notice_days !== null && $from->startOfDay()->lt(now()->addDays($type->notice_days)->startOfDay())) {
            $errors['date_from'] = __(':type needs :days days of notice.', [
                'type' => $type->name,
                'days' => $type->notice_days,
            ]);
        }

        // Counted on the calendar, which is what "consecutive" means on the
        // form — three consecutive days is not three working days spread over
        // a fortnight.
        if ($type->max_consecutive_days !== null && $from->diffInDays($to) + 1 > $type->max_consecutive_days) {
            $errors['date_to'] = __(':type allows at most :days consecutive days.', [
                'type' => $type->name,
                'days' => $type->max_consecutive_days,
            ]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=LeaveFilerTest`
Expected: PASS, 12 tests. Then `php artisan test` and `vendor/bin/pint --dirty`.

- [ ] **Step 6: Hand Dan the commit message**

```
File a leave application with its approval chain and held credits
```

---

## Task 4: Deciding

**Files:**
- Create: `app/Services/Leave/LeaveDecision.php`
- Test: `tests/Feature/Leave/LeaveDecisionTest.php`

**Interfaces:**
- Consumes: the models (Task 2), `LeaveLedger` (Task 3's additions).
- Produces: `LeaveDecision::act(LeaveApplication $application, LeaveApproval $approval, ApprovalAction $action, ?string $remarks): LeaveApplication` and `LeaveDecision::cancel(LeaveApplication $application): LeaveApplication`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/LeaveDecisionTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\ApprovalAction;
use App\Enums\LeaveLedgerKind;
use App\Enums\LeaveStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\Section;
use App\Models\User;
use App\Services\Leave\LeaveDecision;
use App\Services\Leave\LeaveFiler;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Employee $applicant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);

        $division = Division::factory()->create();
        $section = Section::factory()->create(['division_id' => $division->id]);

        $division->update(['division_head_employee_id' => Employee::factory()->create()->id]);
        $section->update(['section_head_employee_id' => Employee::factory()->create()->id]);
        Employee::factory()->create(['is_chief_of_hospital' => true]);

        $this->applicant = Employee::factory()->create(['section_id' => $section->id]);

        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);
    }

    private function fileTwoDays(): LeaveApplication
    {
        return app(LeaveFiler::class)->file($this->applicant, [
            'leave_type_id' => LeaveType::where('code', 'VL')->sole()->id,
            'date_from' => now()->addWeek()->toDateString(),
            'date_to' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'details' => null,
            'commutation' => 'not_requested',
        ]);
    }

    private function approveAll(LeaveApplication $application): LeaveApplication
    {
        $this->actingAs(User::factory()->create());

        while ($approval = $application->fresh()->currentApproval()) {
            $application = app(LeaveDecision::class)->act(
                $application->fresh(),
                $approval,
                ApprovalAction::Approve,
                null
            );
        }

        return $application;
    }

    public function test_approving_one_step_advances_to_the_next(): void
    {
        $application = $this->fileTwoDays();
        $first = $application->currentApproval();

        $this->actingAs(User::factory()->create());

        $after = app(LeaveDecision::class)->act($application, $first, ApprovalAction::Approve, null);

        $this->assertSame(LeaveStatus::Pending, $after->status);
        $this->assertSame('hr', $after->currentApproval()->step->value);
        $this->assertNotNull($first->fresh()->acted_at);
    }

    public function test_the_last_approval_approves_the_application_and_commits_the_credits(): void
    {
        $application = $this->approveAll($this->fileTwoDays());

        $this->assertSame(LeaveStatus::Approved, $application->status);
        $this->assertNotNull($application->decided_at);

        // The hold is released and the days committed in its place, so the
        // balance lands where the hold already put it.
        $this->assertSame(8.0, app(LeaveLedger::class)->balance($this->applicant, 'vacation'));
        $this->assertSame(1, LeaveLedgerEntry::where('kind', LeaveLedgerKind::Commit)->count());
        $this->assertSame(1, LeaveLedgerEntry::where('kind', LeaveLedgerKind::Release)->count());
    }

    public function test_disapproving_ends_it_and_gives_the_credits_back(): void
    {
        $application = $this->fileTwoDays();

        $this->assertSame(8.0, app(LeaveLedger::class)->balance($this->applicant, 'vacation'));

        $this->actingAs(User::factory()->create());

        $after = app(LeaveDecision::class)->act(
            $application,
            $application->currentApproval(),
            ApprovalAction::Disapprove,
            'Needed on duty'
        );

        $this->assertSame(LeaveStatus::Disapproved, $after->status);
        $this->assertSame(10.0, app(LeaveLedger::class)->balance($this->applicant, 'vacation'));
    }

    public function test_disapproving_without_a_reason_is_refused(): void
    {
        // A refusal a person cannot answer is a refusal they will ask about in
        // the corridor instead.
        $application = $this->fileTwoDays();

        $this->actingAs(User::factory()->create());

        $this->expectException(ValidationException::class);

        app(LeaveDecision::class)->act(
            $application,
            $application->currentApproval(),
            ApprovalAction::Disapprove,
            '  '
        );
    }

    public function test_returning_gives_the_credits_back_and_leaves_it_editable(): void
    {
        $application = $this->fileTwoDays();

        $this->actingAs(User::factory()->create());

        $after = app(LeaveDecision::class)->act(
            $application,
            $application->currentApproval(),
            ApprovalAction::Return,
            'The dates are wrong'
        );

        $this->assertSame(LeaveStatus::Returned, $after->status);
        $this->assertSame(10.0, app(LeaveLedger::class)->balance($this->applicant, 'vacation'));
    }

    public function test_acting_on_a_step_that_is_not_the_current_one_is_refused(): void
    {
        // The division head cannot sign before the section head has. Otherwise
        // the order on the form means nothing.
        $application = $this->fileTwoDays();
        $third = $application->approvals()->where('sequence', 3)->sole();

        $this->actingAs(User::factory()->create());

        $this->expectException(ValidationException::class);

        app(LeaveDecision::class)->act($application, $third, ApprovalAction::Approve, null);
    }

    public function test_acting_twice_on_the_same_step_is_refused(): void
    {
        $application = $this->fileTwoDays();
        $first = $application->currentApproval();

        $this->actingAs(User::factory()->create());

        app(LeaveDecision::class)->act($application, $first, ApprovalAction::Approve, null);

        $this->expectException(ValidationException::class);

        app(LeaveDecision::class)->act($application->fresh(), $first->fresh(), ApprovalAction::Approve, null);
    }

    public function test_an_approved_application_cannot_be_acted_on_again(): void
    {
        $application = $this->approveAll($this->fileTwoDays());

        $this->expectException(ValidationException::class);

        app(LeaveDecision::class)->act(
            $application,
            $application->approvals()->first(),
            ApprovalAction::Disapprove,
            'Changed my mind'
        );
    }

    public function test_the_credits_are_released_once_however_many_times_it_ends(): void
    {
        // Releasing twice would invent credits out of nothing.
        $application = $this->fileTwoDays();

        $this->actingAs(User::factory()->create());

        app(LeaveDecision::class)->act(
            $application,
            $application->currentApproval(),
            ApprovalAction::Disapprove,
            'No'
        );

        app(LeaveLedger::class)->releaseFor($application->fresh());

        $this->assertSame(10.0, app(LeaveLedger::class)->balance($this->applicant, 'vacation'));
    }

    public function test_the_applicant_cancels_before_anyone_has_acted(): void
    {
        $application = $this->fileTwoDays();

        $after = app(LeaveDecision::class)->cancel($application);

        $this->assertSame(LeaveStatus::Cancelled, $after->status);
        $this->assertSame(10.0, app(LeaveLedger::class)->balance($this->applicant, 'vacation'));
    }

    public function test_the_applicant_cannot_cancel_once_somebody_has_signed(): void
    {
        // Withdrawing after a recommendation is a decision for the person who
        // gave it, not for the applicant.
        $application = $this->fileTwoDays();

        $this->actingAs(User::factory()->create());

        app(LeaveDecision::class)->act(
            $application,
            $application->currentApproval(),
            ApprovalAction::Approve,
            null
        );

        $this->expectException(ValidationException::class);

        app(LeaveDecision::class)->cancel($application->fresh());
    }

    public function test_the_action_records_who_took_it(): void
    {
        $user = User::factory()->create();
        $application = $this->fileTwoDays();
        $first = $application->currentApproval();

        $this->actingAs($user);

        app(LeaveDecision::class)->act($application, $first, ApprovalAction::Approve, null);

        $this->assertSame($user->id, $first->fresh()->acted_by_user_id);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=LeaveDecisionTest`
Expected: FAIL — `Class "App\Services\Leave\LeaveDecision" not found`.

- [ ] **Step 3: Write the service**

`app/Services/Leave/LeaveDecision.php`:

```php
<?php

namespace App\Services\Leave;

use App\Enums\ApprovalAction;
use App\Enums\LeaveStatus;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What an approver's action does to an application and to the ledger.
 *
 * Whether this person may act at all is a policy question, asked before this is
 * reached. What is asked here is whether the application is in a state where
 * anybody may act.
 */
class LeaveDecision
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    public function act(
        LeaveApplication $application,
        LeaveApproval $approval,
        ApprovalAction $action,
        ?string $remarks,
    ): LeaveApplication {
        $this->assertActionable($application, $approval);

        if ($action !== ApprovalAction::Approve && trim((string) $remarks) === '') {
            // A refusal a person cannot answer is one they will ask about in
            // the corridor instead.
            throw ValidationException::withMessages([
                'remarks' => __('Say why. The applicant sees this.'),
            ]);
        }

        return DB::transaction(function () use ($application, $approval, $action, $remarks) {
            $approval->update([
                'action' => $action->value,
                'remarks' => $remarks === null ? null : trim($remarks),
                'acted_by_user_id' => auth()->id(),
                'acted_at' => now(),
            ]);

            $application = $application->fresh();

            if ($action === ApprovalAction::Disapprove) {
                return $this->end($application, LeaveStatus::Disapproved);
            }

            if ($action === ApprovalAction::Return) {
                return $this->end($application, LeaveStatus::Returned);
            }

            // Approved. If somebody is still waiting on it, it stays pending.
            if ($application->currentApproval() !== null) {
                return $application;
            }

            $this->ledger->commitFor($application);

            $application->update([
                'status' => LeaveStatus::Approved,
                'decided_at' => now(),
            ]);

            return $application->fresh();
        });
    }

    /** The applicant taking it back, before anybody has signed. */
    public function cancel(LeaveApplication $application): LeaveApplication
    {
        if (! $application->isUntouched()) {
            throw ValidationException::withMessages([
                'status' => __('Somebody has already acted on this. Ask them to return or disapprove it.'),
            ]);
        }

        return DB::transaction(fn () => $this->end($application, LeaveStatus::Cancelled));
    }

    private function end(LeaveApplication $application, LeaveStatus $status): LeaveApplication
    {
        // The credits go back whatever the reason it ended.
        $this->ledger->releaseFor($application);

        $application->update([
            'status' => $status,
            'decided_at' => now(),
        ]);

        return $application->fresh();
    }

    private function assertActionable(LeaveApplication $application, LeaveApproval $approval): void
    {
        $current = $application->currentApproval();

        if ($current === null) {
            throw ValidationException::withMessages([
                'status' => __('This application has already been decided.'),
            ]);
        }

        // The division head cannot sign before the section head has, or the
        // order printed on the form means nothing.
        if ($current->id !== $approval->id) {
            throw ValidationException::withMessages([
                'status' => __('This application is waiting on :step.', [
                    'step' => $current->step->label(),
                ]),
            ]);
        }
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=LeaveDecisionTest`
Expected: PASS, 12 tests. Then `php artisan test` and `vendor/bin/pint --dirty`.

- [ ] **Step 5: Hand Dan the commit message**

```
Apply an approver's decision and move the held credits
```

---

## Task 5: Who may act

**Files:**
- Create: `app/Policies/LeaveApplicationPolicy.php`
- Test: `tests/Feature/Leave/LeaveApplicationPolicyTest.php`

**Interfaces:**
- Consumes: the models (Task 2), `User::employee()`.
- Produces: `LeaveApplicationPolicy` with `view`, `act`, `cancel`, `refile`. Registered by Laravel's convention (`App\Policies\LeaveApplicationPolicy` for `App\Models\LeaveApplication`); no manual registration needed.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/LeaveApplicationPolicyTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveApplicationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private LeaveApplication $application;

    private Employee $sectionHead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->application = LeaveApplication::factory()->create(['status' => LeaveStatus::Pending]);
        $this->sectionHead = Employee::factory()->create();

        LeaveApproval::create([
            'leave_application_id' => $this->application->id,
            'sequence' => 1,
            'step' => LeaveStep::SectionHead,
            'approver_employee_id' => $this->sectionHead->id,
        ]);

        LeaveApproval::create([
            'leave_application_id' => $this->application->id,
            'sequence' => 2,
            'step' => LeaveStep::Hr,
            'approver_employee_id' => null,
        ]);
    }

    private function userFor(Employee $employee): User
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_the_named_approver_may_act_on_their_step(): void
    {
        $user = $this->userFor($this->sectionHead);

        $this->assertTrue($user->can('act', $this->application));
    }

    public function test_somebody_elses_section_head_may_not(): void
    {
        // This is the whole reason acting is a policy and not a permission: a
        // permission cannot see which application is being asked about.
        $other = Employee::factory()->create();

        $this->assertFalse($this->userFor($other)->can('act', $this->application));
    }

    public function test_hr_may_act_only_when_the_hr_step_is_the_current_one(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('hr');

        // The section head has not signed yet.
        $this->assertFalse($hr->can('act', $this->application));

        $this->application->approvals()->where('sequence', 1)->update(['acted_at' => now()]);

        $this->assertTrue($hr->fresh()->can('act', $this->application->fresh()));
    }

    public function test_the_named_approver_may_not_act_out_of_turn(): void
    {
        $this->application->approvals()->where('sequence', 1)->update(['acted_at' => now()]);

        $this->assertFalse($this->userFor($this->sectionHead)->can('act', $this->application->fresh()));
    }

    public function test_nobody_acts_on_a_decided_application(): void
    {
        $this->application->update(['status' => LeaveStatus::Approved]);

        $this->assertFalse($this->userFor($this->sectionHead)->can('act', $this->application->fresh()));
    }

    public function test_the_applicant_sees_their_own_application(): void
    {
        $applicant = $this->application->employee;

        $this->assertTrue($this->userFor($applicant)->can('view', $this->application));
    }

    public function test_a_stranger_does_not_see_it(): void
    {
        // A sick leave says something about a person's health.
        $stranger = Employee::factory()->create();

        $this->assertFalse($this->userFor($stranger)->can('view', $this->application));
    }

    public function test_an_approver_on_the_chain_sees_it(): void
    {
        $this->assertTrue($this->userFor($this->sectionHead)->can('view', $this->application));
    }

    public function test_hr_sees_every_application(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('hr');

        $this->assertTrue($hr->can('view', $this->application));
    }

    public function test_only_the_applicant_cancels_and_only_while_untouched(): void
    {
        $applicant = $this->application->employee;

        $this->assertTrue($this->userFor($applicant)->can('cancel', $this->application));

        $this->application->approvals()->where('sequence', 1)->update(['acted_at' => now()]);

        $this->assertFalse($this->userFor($applicant)->fresh()->can('cancel', $this->application->fresh()));
    }

    public function test_hr_cannot_cancel_somebody_elses_application(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('hr');

        $this->assertFalse($hr->can('cancel', $this->application));
    }

    public function test_only_the_applicant_refiles_and_only_when_it_was_returned(): void
    {
        $applicant = $this->application->employee;
        $user = $this->userFor($applicant);

        $this->assertFalse($user->can('refile', $this->application));

        $this->application->update(['status' => LeaveStatus::Returned]);

        $this->assertTrue($user->fresh()->can('refile', $this->application->fresh()));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=LeaveApplicationPolicyTest`
Expected: FAIL — the abilities are denied because no policy exists.

- [ ] **Step 3: Write the policy**

`app/Policies/LeaveApplicationPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\LeaveApplication;
use App\Models\User;

/**
 * Who may do what to one application.
 *
 * Acting is a policy and not a permission because the question is "are you the
 * approver of THIS application's CURRENT step". A permission cannot see which
 * application is being asked about, and that is how one section head ends up
 * approving another division's leave.
 */
class LeaveApplicationPolicy
{
    /**
     * A sick leave says something about a person's health. The applicant, the
     * people on its chain, and HR.
     */
    public function view(User $user, LeaveApplication $application): bool
    {
        if ($user->can('leave.manage')) {
            return true;
        }

        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return false;
        }

        return $application->employee_id === $employeeId
            || $application->approvals()->where('approver_employee_id', $employeeId)->exists();
    }

    /** The approver of the step it is sitting on, right now. */
    public function act(User $user, LeaveApplication $application): bool
    {
        $current = $application->currentApproval();

        if ($current === null) {
            return false;
        }

        // HR is an office, not a person: whoever holds leave.manage acts, and
        // the person who pressed the button is recorded on the approval.
        if ($current->step === LeaveStep::Hr) {
            return $user->can('leave.manage');
        }

        return $current->approver_employee_id !== null
            && $current->approver_employee_id === $user->employee?->id;
    }

    /**
     * The applicant, and only before anybody has signed. Withdrawing after a
     * recommendation is a decision for the person who gave it.
     */
    public function cancel(User $user, LeaveApplication $application): bool
    {
        return $application->employee_id === $user->employee?->id
            && $application->isUntouched();
    }

    /** The applicant, and only on one that was sent back to them. */
    public function refile(User $user, LeaveApplication $application): bool
    {
        return $application->employee_id === $user->employee?->id
            && $application->status === LeaveStatus::Returned;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=LeaveApplicationPolicyTest`
Expected: PASS, 12 tests. Then `php artisan test` and `vendor/bin/pint --dirty`.

- [ ] **Step 5: Hand Dan the commit message**

```
Add the leave application policy
```

---

## Task 6: My leave

**Files:**
- Create: `resources/views/pages/leave/⚡mine.blade.php`
- Modify: `routes/leave.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/Leave/MyLeaveScreenTest.php`

**Interfaces:**
- Consumes: `LeaveBalance`, `LeaveFiler`, `LeaveDecision`, `LeaveType`, the policy.
- Produces: route `leave.mine` at `leave/mine`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/MyLeaveScreenTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\Section;
use App\Models\User;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyLeaveScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Employee $applicant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        $division = Division::factory()->create();
        $section = Section::factory()->create(['division_id' => $division->id]);

        $division->update(['division_head_employee_id' => Employee::factory()->create()->id]);
        $section->update(['section_head_employee_id' => Employee::factory()->create()->id]);
        Employee::factory()->create(['is_chief_of_hospital' => true]);

        $this->user = User::factory()->create();
        $this->user->assignRole('employee');

        $this->applicant = Employee::factory()->create([
            'section_id' => $section->id,
            'user_id' => $this->user->id,
        ]);

        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);
    }

    public function test_an_employee_files_leave(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('startApplying')
            ->set('form.leave_type_id', LeaveType::where('code', 'VL')->sole()->id)
            ->set('form.date_from', now()->addWeek()->toDateString())
            ->set('form.date_to', now()->addWeek()->addDay()->toDateString())
            ->set('form.days', 2)
            ->call('file')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leave_applications', [
            'employee_id' => $this->applicant->id,
            'days_with_pay' => 2,
        ]);
    }

    public function test_the_balance_shown_already_has_the_holds_taken_out(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('startApplying')
            ->set('form.leave_type_id', LeaveType::where('code', 'VL')->sole()->id)
            ->set('form.date_from', now()->addWeek()->toDateString())
            ->set('form.date_to', now()->addWeek()->addDay()->toDateString())
            ->set('form.days', 2)
            ->call('file')
            ->assertViewHas('balances', fn ($balances) => collect($balances)
                ->firstWhere('ledger', 'vacation')['days'] === 8.0);
    }

    public function test_the_type_list_holds_only_what_this_status_may_file(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->assertViewHas('types', fn ($types) => ! $types->pluck('code')->contains('WELLNESS'));
    }

    public function test_an_employee_sees_only_their_own_applications(): void
    {
        LeaveApplication::factory()->create(['days' => 9]);

        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->assertViewHas('applications', fn ($applications) => $applications->total() === 0);
    }

    public function test_the_applicant_cancels_an_untouched_application(): void
    {
        $application = LeaveApplication::factory()->create([
            'employee_id' => $this->applicant->id,
            'status' => LeaveStatus::Pending,
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('cancel', $application->id);

        $this->assertSame(LeaveStatus::Cancelled, $application->fresh()->status);
    }

    public function test_the_applicant_cannot_cancel_somebody_elses(): void
    {
        $someoneElse = LeaveApplication::factory()->create(['status' => LeaveStatus::Pending]);

        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('cancel', $someoneElse->id)
            ->assertForbidden();

        $this->assertSame(LeaveStatus::Pending, $someoneElse->fresh()->status);
    }

    public function test_an_account_with_no_employee_record_is_refused(): void
    {
        $orphan = User::factory()->create();
        $orphan->assignRole('employee');

        $this->actingAs($orphan)->get(route('leave.mine'))->assertForbidden();
    }

    public function test_a_missing_section_head_says_so_instead_of_failing_quietly(): void
    {
        Section::where('id', $this->applicant->section_id)->update(['section_head_employee_id' => null]);

        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('startApplying')
            ->set('form.leave_type_id', LeaveType::where('code', 'VL')->sole()->id)
            ->set('form.date_from', now()->addWeek()->toDateString())
            ->set('form.date_to', now()->addWeek()->addDay()->toDateString())
            ->set('form.days', 2)
            ->call('file')
            ->assertHasErrors('form.leave_type_id');

        $this->assertDatabaseCount('leave_applications', 0);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=MyLeaveScreenTest`
Expected: FAIL — `Route [leave.mine] not defined`.

- [ ] **Step 3: The route**

In `routes/leave.php`, inside the group, above the `{employee}` route:

```php
    Route::livewire('leave/mine', 'pages::leave.mine')->name('leave.mine');
```

- [ ] **Step 4: The screen**

`resources/views/pages/leave/⚡mine.blade.php`:

```php
<?php

use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveDecision;
use App\Services\Leave\LeaveFiler;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('My leave')] class extends Component {
    use WithPagination;

    /** @var array<string, mixed> */
    public array $form = [];

    /** The application being corrected after it was returned, if any. */
    public ?int $refilingId = null;

    public function mount(): void
    {
        // Not a policy question: this screen is about the person signed in, and
        // an account with no employee record has nothing to show.
        abort_if($this->applicant() === null, 403, 'This account is not linked to an employee record.');

        $this->emptyForm();
    }

    public function startApplying(): void
    {
        $this->refilingId = null;
        $this->emptyForm();
        $this->resetValidation();

        Flux::modal('leave-form')->show();
    }

    public function startRefiling(int $id): void
    {
        $application = LeaveApplication::findOrFail($id);

        $this->authorize('refile', $application);

        $this->refilingId = $application->id;

        $this->form = [
            'leave_type_id' => $application->leave_type_id,
            'date_from' => $application->date_from->toDateString(),
            'date_to' => $application->date_to->toDateString(),
            'days' => $application->days,
            'commutation' => $application->commutation,
            'details' => $application->details ?? [],
        ];

        $this->resetValidation();

        Flux::modal('leave-form')->show();
    }

    public function file(): void
    {
        $applicant = $this->applicant();

        abort_if($applicant === null, 403);

        $filer = app(LeaveFiler::class);

        try {
            if ($this->refilingId) {
                $application = LeaveApplication::findOrFail($this->refilingId);

                // refilingId came back from the browser, so it is asked about
                // again rather than trusted.
                $this->authorize('refile', $application);

                $filer->refile($application, $this->form);
            } else {
                $filer->file($applicant, $this->form);
            }
        } catch (ValidationException $e) {
            // The services speak in their own field names. Show their words
            // against this form's fields rather than inventing a second set.
            foreach ($e->validator->errors()->messages() as $field => $messages) {
                $this->addError("form.{$field}", $messages[0]);
            }

            return;
        }

        $this->refilingId = null;
        $this->emptyForm();

        Flux::modal('leave-form')->close();

        Flux::toast(variant: 'success', text: __('Application filed.'));
    }

    public function cancel(int $id): void
    {
        $application = LeaveApplication::findOrFail($id);

        $this->authorize('cancel', $application);

        app(LeaveDecision::class)->cancel($application);

        Flux::toast(variant: 'success', text: __('Application cancelled.'));
    }

    private function applicant(): ?Employee
    {
        return auth()->user()?->employee;
    }

    private function emptyForm(): void
    {
        $this->form = [
            'leave_type_id' => null,
            'date_from' => null,
            'date_to' => null,
            'days' => null,
            'commutation' => 'not_requested',
            'details' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $applicant = $this->applicant();

        return [
            'balances' => $applicant ? app(LeaveBalance::class)->for($applicant) : [],
            'types' => $applicant?->employment_status
                ? LeaveType::availableTo($applicant->employment_status)->get()
                : collect(),
            'applications' => LeaveApplication::query()
                ->where('employee_id', $applicant?->id)
                ->with(['type', 'approvals.approver'])
                ->latest('id')
                ->paginate(10),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('My leave') }}</flux:heading>
            <flux:subheading>{{ __('What you hold, and what you have asked for.') }}</flux:subheading>
        </div>

        <flux:button wire:click="startApplying" variant="primary" icon="plus" size="sm">
            {{ __('Apply for leave') }}
        </flux:button>
    </div>

    {{-- The balance already has every pending hold taken out of it, which is
         what makes it the number worth showing. --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($balances as $balance)
            <flux:card wire:key="balance-{{ $balance['ledger'] }}">
                <flux:subheading>{{ $balance['label'] }}</flux:subheading>
                <flux:heading size="xl">{{ number_format($balance['days'], 2) }}</flux:heading>
            </flux:card>
        @endforeach
    </div>

    <flux:table class="mt-8" :paginate="$applications">
        <flux:table.columns>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Dates') }}</flux:table.column>
            <flux:table.column>{{ __('Days') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Waiting on') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($applications as $application)
                <flux:table.row wire:key="application-{{ $application->id }}">
                    <flux:table.cell class="font-medium">{{ $application->type->name }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $application->date_from->format('d/m/Y') }} –
                        {{ $application->date_to->format('d/m/Y') }}
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ number_format($application->days, 2) }}
                        @if ($application->days_without_pay > 0)
                            <flux:text class="text-xs">
                                {{ __(':days without pay', ['days' => number_format($application->days_without_pay, 2)]) }}
                            </flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$application->status->color()">
                            {{ $application->status->label() }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        @php $current = $application->currentApproval(); @endphp
                        {{ $current?->approver?->fullName() ?? $current?->step->label() ?? '—' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-3 text-sm">
                            @can('cancel', $application)
                                <flux:link href="#" wire:click.prevent="cancel({{ $application->id }})">
                                    {{ __('Cancel') }}
                                </flux:link>
                            @endcan

                            @can('refile', $application)
                                <flux:link href="#" wire:click.prevent="startRefiling({{ $application->id }})">
                                    {{ __('Correct and send again') }}
                                </flux:link>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">
                        {{ __('You have not applied for leave.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="leave-form" class="w-full md:max-w-2xl">
        <form wire:submit="file" class="space-y-6">
            <flux:heading size="lg">
                {{ $refilingId ? __('Correct and send again') : __('Apply for leave') }}
            </flux:heading>

            <flux:select wire:model="form.leave_type_id" :label="__('Type of leave')" :placeholder="__('Choose')">
                @foreach ($types as $type)
                    <flux:select.option wire:key="type-{{ $type->id }}" value="{{ $type->id }}">
                        {{ $type->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid gap-6 sm:grid-cols-3">
                <flux:input wire:model="form.date_from" type="date" :label="__('First day')" />
                <flux:input wire:model="form.date_to" type="date" :label="__('Last day')" />
                <flux:input
                    wire:model="form.days"
                    type="number"
                    step="0.5"
                    :label="__('Working days')"
                    :description="__('Half days allowed.')"
                />
            </div>

            <flux:select wire:model="form.commutation" :label="__('Commutation')">
                <flux:select.option value="not_requested">{{ __('Not requested') }}</flux:select.option>
                <flux:select.option value="requested">{{ __('Requested') }}</flux:select.option>
            </flux:select>

            <flux:error name="form.leave_type_id" />
            <flux:error name="form.date_from" />
            <flux:error name="form.date_to" />
            <flux:error name="form.days" />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('File it') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
```

- [ ] **Step 5: The sidebar entry**

In `resources/views/layouts/app/sidebar.blade.php`, next to My PDS:

```blade
                    @if (auth()->user()?->employee)
                        <flux:sidebar.item icon="calendar" :href="route('leave.mine')" :current="request()->routeIs('leave.mine')" wire:navigate>
                            {{ __('My leave') }}
                        </flux:sidebar.item>
                    @endif
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=MyLeaveScreenTest` — expected PASS, 8 tests.

Then `php artisan test`, `npm run build`, `vendor/bin/pint --dirty`.

- [ ] **Step 7: Hand Dan the commit message**

```
Add the My leave screen
```

---

## Task 7: The approvals queue

**Files:**
- Create: `resources/views/pages/leave/⚡approvals.blade.php`
- Modify: `routes/leave.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/Leave/ApprovalsScreenTest.php`

**Interfaces:**
- Consumes: `LeaveDecision`, the policy, `AuditRecorder`.
- Produces: route `leave.approvals` at `leave/approvals`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leave/ApprovalsScreenTest.php`:

```php
<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ApprovalsScreenTest extends TestCase
{
    use RefreshDatabase;

    private LeaveApplication $application;

    private User $sectionHeadUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->application = LeaveApplication::factory()->create(['status' => LeaveStatus::Pending]);

        $sectionHead = Employee::factory()->create();
        $this->sectionHeadUser = User::factory()->create();
        $this->sectionHeadUser->assignRole('employee');
        $sectionHead->update(['user_id' => $this->sectionHeadUser->id]);

        LeaveApproval::create([
            'leave_application_id' => $this->application->id,
            'sequence' => 1,
            'step' => LeaveStep::SectionHead,
            'approver_employee_id' => $sectionHead->id,
        ]);

        LeaveApproval::create([
            'leave_application_id' => $this->application->id,
            'sequence' => 2,
            'step' => LeaveStep::Hr,
        ]);
    }

    public function test_the_queue_holds_what_is_waiting_on_this_person(): void
    {
        Livewire::actingAs($this->sectionHeadUser)
            ->test('pages::leave.approvals')
            ->assertViewHas('applications', fn ($applications) => $applications->total() === 1);
    }

    public function test_the_queue_is_empty_for_somebody_further_down_the_chain(): void
    {
        // The HR step is not the current one yet.
        $hr = User::factory()->create();
        $hr->assignRole('hr');

        Livewire::actingAs($hr)
            ->test('pages::leave.approvals')
            ->assertViewHas('applications', fn ($applications) => $applications->total() === 0);
    }

    public function test_it_reaches_hr_once_the_section_head_has_signed(): void
    {
        $this->application->approvals()->where('sequence', 1)->update(['acted_at' => now()]);

        $hr = User::factory()->create();
        $hr->assignRole('hr');

        Livewire::actingAs($hr)
            ->test('pages::leave.approvals')
            ->assertViewHas('applications', fn ($applications) => $applications->total() === 1);
    }

    public function test_approving_advances_the_application(): void
    {
        Livewire::actingAs($this->sectionHeadUser)
            ->test('pages::leave.approvals')
            ->call('approve', $this->application->id);

        $this->assertSame('hr', $this->application->fresh()->currentApproval()->step->value);
    }

    public function test_disapproving_needs_a_reason(): void
    {
        Livewire::actingAs($this->sectionHeadUser)
            ->test('pages::leave.approvals')
            ->set('remarks', '')
            ->call('disapprove', $this->application->id)
            ->assertHasErrors();

        $this->assertSame(LeaveStatus::Pending, $this->application->fresh()->status);
    }

    public function test_somebody_who_does_not_hold_the_step_is_refused(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('employee');
        Employee::factory()->create(['user_id' => $stranger->id]);

        Livewire::actingAs($stranger)
            ->test('pages::leave.approvals')
            ->call('approve', $this->application->id)
            ->assertForbidden();
    }

    public function test_opening_the_queue_records_the_read(): void
    {
        // Reading somebody else's leave is recorded, the same as their PDS.
        $this->actingAs($this->sectionHeadUser)->get(route('leave.approvals'))->assertOk();

        $this->assertTrue(
            Activity::where('event', 'read')->where('description', 'like', '%leave%')->exists()
        );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --filter=ApprovalsScreenTest`
Expected: FAIL — `Route [leave.approvals] not defined`.

- [ ] **Step 3: The route**

In `routes/leave.php`, inside the group:

```php
    Route::livewire('leave/approvals', 'pages::leave.approvals')->name('leave.approvals');
```

- [ ] **Step 4: The screen**

`resources/views/pages/leave/⚡approvals.blade.php`:

```php
<?php

use App\Enums\ApprovalAction;
use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use App\Services\AuditRecorder;
use App\Services\Leave\LeaveDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Approvals')] class extends Component {
    use WithPagination;

    public string $remarks = '';

    public function mount(): void
    {
        // Reading somebody else's leave is recorded, the same as their PDS.
        // One row per application rather than one for the page, because the
        // question an auditor asks is "who saw THIS application", and a row
        // naming only the queue cannot answer it.
        //
        // On mount, not in with(): with() runs on every keystroke, and an audit
        // trail nobody can read is the same as none.
        foreach ($this->waiting()->get() as $application) {
            if ($application->employee_id !== auth()->user()?->employee?->id) {
                app(AuditRecorder::class)->recordRead(
                    $application,
                    'Read a leave application in the approvals queue'
                );
            }
        }
    }

    public function approve(int $id): void
    {
        $this->decide($id, ApprovalAction::Approve);
    }

    public function disapprove(int $id): void
    {
        $this->decide($id, ApprovalAction::Disapprove);
    }

    public function returnForCorrection(int $id): void
    {
        $this->decide($id, ApprovalAction::Return);
    }

    private function decide(int $id, ApprovalAction $action): void
    {
        $application = LeaveApplication::findOrFail($id);

        // The id came from the browser. Whether this person holds the step it
        // is sitting on right now is the whole question.
        $this->authorize('act', $application);

        try {
            app(LeaveDecision::class)->act(
                $application,
                $application->currentApproval(),
                $action,
                $this->remarks ?: null,
            );
        } catch (ValidationException $e) {
            $this->addError('remarks', $e->validator->errors()->first());

            return;
        }

        $this->reset('remarks');

        Flux::toast(variant: 'success', text: __('Recorded.'));
    }

    /**
     * The applications whose *current* step belongs to this person.
     *
     * Two steps rather than one correlated subquery: find the first unsigned
     * approval of every pending application, then keep the ones that are this
     * person's. Written the plain way because a query nobody can read is a
     * query nobody can fix, and this list is at most a few hundred rows.
     */
    private function waiting(): Builder
    {
        $employeeId = auth()->user()?->employee?->id;
        $isHr = auth()->user()?->can('leave.manage') ?? false;

        $currentApprovalIds = LeaveApproval::query()
            ->whereNull('acted_at')
            ->whereHas('application', fn ($q) => $q->where('status', LeaveStatus::Pending))
            ->orderBy('sequence')
            ->get(['id', 'leave_application_id', 'sequence', 'step', 'approver_employee_id'])
            ->groupBy('leave_application_id')
            ->map(fn ($approvals) => $approvals->sortBy('sequence')->first())
            ->filter(fn ($approval) => $approval->approver_employee_id !== null
                ? $approval->approver_employee_id === $employeeId
                : ($isHr && $approval->step === LeaveStep::Hr))
            ->pluck('leave_application_id');

        return LeaveApplication::query()
            ->whereIn('id', $currentApprovalIds)
            ->with(['employee', 'type', 'approvals.approver'])
            ->latest('filed_at');
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return ['applications' => $this->waiting()->paginate(10)];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Approvals') }}</flux:heading>
    <flux:subheading>{{ __('Applications waiting on you.') }}</flux:subheading>

    <flux:input
        wire:model="remarks"
        class="mt-6 max-w-xl"
        :label="__('Remarks')"
        :description="__('Required when you disapprove or return. The applicant sees this.')"
    />

    <flux:error name="remarks" />

    <flux:table class="mt-6" :paginate="$applications">
        <flux:table.columns>
            <flux:table.column>{{ __('Employee') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Dates') }}</flux:table.column>
            <flux:table.column>{{ __('Days') }}</flux:table.column>
            <flux:table.column>{{ __('Your step') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($applications as $application)
                <flux:table.row wire:key="approval-{{ $application->id }}">
                    <flux:table.cell class="font-medium">{{ $application->employee->fullName() }}</flux:table.cell>
                    <flux:table.cell>{{ $application->type->name }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $application->date_from->format('d/m/Y') }} –
                        {{ $application->date_to->format('d/m/Y') }}
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ number_format($application->days, 2) }}
                        @if ($application->days_without_pay > 0)
                            <flux:text class="text-xs">
                                {{ __(':days without pay', ['days' => number_format($application->days_without_pay, 2)]) }}
                            </flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $application->currentApproval()?->step->action() }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-3 text-sm">
                            <flux:link href="#" wire:click.prevent="approve({{ $application->id }})">
                                {{ __('Approve') }}
                            </flux:link>
                            <flux:link href="#" wire:click.prevent="returnForCorrection({{ $application->id }})">
                                {{ __('Return') }}
                            </flux:link>
                            <flux:link href="#" wire:click.prevent="disapprove({{ $application->id }})">
                                {{ __('Disapprove') }}
                            </flux:link>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">
                        {{ __('Nothing is waiting on you.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
```

- [ ] **Step 5: The sidebar entry**

```blade
                    @if (auth()->user()?->employee || auth()->user()?->can('leave.manage'))
                        <flux:sidebar.item icon="inbox" :href="route('leave.approvals')" :current="request()->routeIs('leave.approvals')" wire:navigate>
                            {{ __('Approvals') }}
                        </flux:sidebar.item>
                    @endif
```

- [ ] **Step 6: Run everything**

Run: `php artisan test --filter=ApprovalsScreenTest` — expected PASS, 7 tests.

Then `php artisan test`, `npm run build`, `vendor/bin/pint --dirty`. Report the real numbers.

`waiting()` is called twice per page load — once by `mount()` for the audit rows and once by `with()` for the table. That is deliberate: the audit has to name the applications actually shown, and sharing a cached list between a mount and a render that may be a request apart would record what was there a moment ago.

- [ ] **Step 7: Hand Dan the commit message**

```
Add the leave approvals queue
```

---

## What this plan does not build

Phase 2a-3 fills the DTRC CS Form 6 template — `Form6Exporter`, the cell map in `config/form6_template.php`, and the download. The template is in `storage/app/templates/` with its checkboxes linked; the mapping is written against the linked copy.

Two things still have to come from the HR office, and neither blocks this plan:

- **Opening balances** for all 134 employees. Until they exist every balance reads zero, every application files as leave without pay, and the paid/unpaid split — which this plan builds and tests — is never exercised with real numbers.
- **Whether the day count should follow a shift roster.** The count is a proposal the applicant and HR can both change, and the true answer needs the DTR, which is Phase 2b.
