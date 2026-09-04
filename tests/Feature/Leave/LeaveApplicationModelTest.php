<?php

namespace Tests\Feature\Leave;

use App\Enums\ApprovalAction;
use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use App\Models\LeaveLedgerEntry;
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

    public function test_an_application_is_untouched_until_somebody_acts(): void
    {
        // This is what decides whether the applicant may still take it back.
        $application = LeaveApplication::factory()->create(['status' => LeaveStatus::Pending]);

        LeaveApproval::create([
            'leave_application_id' => $application->id,
            'sequence' => 1,
            'step' => LeaveStep::SectionHead,
        ]);

        $this->assertTrue($application->isUntouched());

        $application->approvals()->where('sequence', 1)->update(['acted_at' => now()]);

        $this->assertFalse($application->fresh()->isUntouched());
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

    public function test_a_ledger_entry_can_name_the_application_that_caused_it(): void
    {
        // The ledger is the answer to "where did my credits go". "A hold" is
        // not an answer.
        $application = LeaveApplication::factory()->create();

        $entry = LeaveLedgerEntry::factory()->create([
            'employee_id' => $application->employee_id,
            'leave_application_id' => $application->id,
        ]);

        $this->assertSame($application->id, $entry->fresh()->application->id);
    }
}
