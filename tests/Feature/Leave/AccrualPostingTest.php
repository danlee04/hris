<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Enums\LeaveLedgerKind;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Services\Leave\AccrualPosting;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

    public function test_a_grant_that_does_not_carry_over_forfeits_last_years_leftovers(): void
    {
        // Special Privilege Leave is three days a year, forfeited if unused.
        // Granting on top of last year's leftovers would hand out six.
        $employee = $this->permanent();

        app(AccrualPosting::class)->postGrants('2026');
        app(AccrualPosting::class)->postGrants('2027');

        $this->assertSame(3.0, app(LeaveLedger::class)->balance($employee, 'spl'));
    }

    public function test_the_forfeit_is_an_entry_of_its_own(): void
    {
        // "Where did my three days go" is a question somebody asks in
        // February. A silent reset has nothing to answer with.
        $employee = $this->permanent();

        app(AccrualPosting::class)->postGrants('2026');
        app(AccrualPosting::class)->postGrants('2027');

        $forfeit = LeaveLedgerEntry::where('employee_id', $employee->id)
            ->where('kind', LeaveLedgerKind::Expiry)
            ->where('ledger', 'spl')
            ->sole();

        $this->assertSame(-3.0, $forfeit->days);
        $this->assertSame('2027', $forfeit->period);
    }

    public function test_a_grant_that_carries_over_keeps_last_years_leftovers(): void
    {
        $employee = $this->permanent();

        LeaveType::where('code', 'SPL')->update(['grant_carries_over' => true]);

        app(AccrualPosting::class)->postGrants('2026');
        app(AccrualPosting::class)->postGrants('2027');

        $this->assertSame(6.0, app(LeaveLedger::class)->balance($employee, 'spl'));
    }

    public function test_a_partly_used_grant_forfeits_only_what_is_left(): void
    {
        $employee = $this->permanent();

        app(AccrualPosting::class)->postGrants('2026');
        app(LeaveLedger::class)->adjust($employee, 'spl', -2, 'Took two days');

        app(AccrualPosting::class)->postGrants('2027');

        $this->assertSame(3.0, app(LeaveLedger::class)->balance($employee, 'spl'));
    }

    public function test_undoing_a_month_removes_what_it_wrote(): void
    {
        // The wrong month typed. Deleting rather than reversing, because an
        // accrual for a month nobody meant to post is a mistake in the
        // recording, not something that happened.
        $employee = $this->permanent();

        app(AccrualPosting::class)->post('2026-09');
        $removed = app(AccrualPosting::class)->undo('2026-09');

        $this->assertSame(2, $removed);
        $this->assertSame(0.0, app(LeaveLedger::class)->balance($employee, 'vacation'));
        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_undoing_leaves_every_other_month_alone(): void
    {
        $employee = $this->permanent();

        app(AccrualPosting::class)->post('2026-09');
        app(AccrualPosting::class)->post('2026-10');
        app(AccrualPosting::class)->undo('2026-09');

        $this->assertSame(1.25, app(LeaveLedger::class)->balance($employee, 'vacation'));
    }

    public function test_undoing_leaves_the_opening_balance_alone(): void
    {
        // The opening balance has no period, so it is not part of any posting.
        $employee = $this->permanent();

        app(LeaveLedger::class)->open($employee, 'vacation', 10);
        app(AccrualPosting::class)->post('2026-09');
        app(AccrualPosting::class)->undo('2026-09');

        $this->assertSame(10.0, app(LeaveLedger::class)->balance($employee, 'vacation'));
    }

    public function test_a_month_can_be_posted_again_after_it_is_undone(): void
    {
        $employee = $this->permanent();

        app(AccrualPosting::class)->post('2026-09');
        app(AccrualPosting::class)->undo('2026-09');

        $this->assertSame(2, app(AccrualPosting::class)->post('2026-09'));
        $this->assertSame(1.25, app(LeaveLedger::class)->balance($employee, 'vacation'));
    }

    public function test_undoing_refuses_when_the_credits_have_been_spent(): void
    {
        // Nothing spends credits until Phase 2a-2, but by then this is the
        // difference between an undo and somebody owing days they have taken.
        $employee = $this->permanent();

        app(AccrualPosting::class)->post('2026-09');
        app(LeaveLedger::class)->adjust($employee, 'vacation', -1, 'Took a day');

        try {
            app(AccrualPosting::class)->undo('2026-09');
            $this->fail('The undo should have been refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already been used', $e->validator->errors()->first());
        }

        // The whole undo is rolled back, not half of it.
        $this->assertSame(0.25, app(LeaveLedger::class)->balance($employee, 'vacation'));
        $this->assertSame(1.25, app(LeaveLedger::class)->balance($employee, 'sick'));
    }

    public function test_undoing_a_year_takes_back_the_forfeit_too(): void
    {
        // Undoing the grant without the forfeit would leave the balance
        // cleared and nothing to show for it.
        $employee = $this->permanent();

        app(AccrualPosting::class)->postGrants('2026');
        app(AccrualPosting::class)->postGrants('2027');
        app(AccrualPosting::class)->undoGrants('2027');

        $this->assertSame(3.0, app(LeaveLedger::class)->balance($employee, 'spl'));
    }

    public function test_undoing_a_month_that_was_never_posted_removes_nothing(): void
    {
        $this->permanent();

        $this->assertSame(0, app(AccrualPosting::class)->undo('2026-09'));
    }
}
