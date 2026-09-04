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
        // A refusal a person cannot answer is one they will ask about in the
        // corridor instead.
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
        // the order printed on the form means nothing.
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
        // The HR step is held by an office. Which person in it acted is the
        // only thing that names anybody.
        $user = User::factory()->create();
        $application = $this->fileTwoDays();
        $first = $application->currentApproval();

        $this->actingAs($user);

        app(LeaveDecision::class)->act($application, $first, ApprovalAction::Approve, null);

        $this->assertSame($user->id, $first->fresh()->acted_by_user_id);
        $this->assertSame(ApprovalAction::Approve, $first->fresh()->action);
    }

    public function test_an_uncredited_type_approves_with_no_ledger_movement_at_all(): void
    {
        // Maternity leave is a right, not a balance.
        $application = app(LeaveFiler::class)->file($this->applicant, [
            'leave_type_id' => LeaveType::where('code', 'ML')->sole()->id,
            'date_from' => now()->addWeek()->toDateString(),
            'date_to' => now()->addWeek()->addDays(104)->toDateString(),
            'days' => 105,
            'details' => null,
            'commutation' => 'not_requested',
        ]);

        $before = LeaveLedgerEntry::count();

        $this->assertSame(LeaveStatus::Approved, $this->approveAll($application)->status);
        $this->assertSame($before, LeaveLedgerEntry::count());
    }
}
