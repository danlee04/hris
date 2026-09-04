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

    public function test_an_account_with_no_employee_record_never_acts(): void
    {
        // A null employee id must not match a null approver id. That is the
        // same trap the employee policy names: two nulls comparing equal hands
        // somebody a record that is not theirs.
        $orphan = User::factory()->create();
        $orphan->assignRole('employee');

        $this->assertFalse($orphan->can('act', $this->application));
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
