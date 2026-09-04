<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\LeaveType;
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

        LeaveType::where('code', 'SPL')->update(['is_active' => false]);

        $ledgers = collect(app(LeaveBalance::class)->for($employee))->pluck('ledger')->all();

        $this->assertNotContains('spl', $ledgers);
    }

    public function test_vacation_appears_once_although_two_types_spend_it(): void
    {
        // Vacation Leave and Mandatory/Forced Leave both draw on the vacation
        // balance. Two cards saying the same number is two numbers to disagree.
        $employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
        ]);

        $ledgers = collect(app(LeaveBalance::class)->for($employee))->pluck('ledger');

        $this->assertSame(1, $ledgers->filter(fn ($ledger) => $ledger === 'vacation')->count());
    }
}
