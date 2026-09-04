<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Services\Leave\AccrualPosting;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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
        $this->permanent();

        app(AccrualPosting::class)->post('2026-09');
        $second = app(AccrualPosting::class)->post('2026-09');

        $this->assertSame(0, $second);
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
        $this->expectException(InvalidArgumentException::class);

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
        $this->expectException(InvalidArgumentException::class);

        app(AccrualPosting::class)->postGrants('26');
    }
}
