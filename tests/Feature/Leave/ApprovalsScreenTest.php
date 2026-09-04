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

    private function hr(): User
    {
        $user = User::factory()->create();
        $user->assignRole('hr');

        return $user;
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
        Livewire::actingAs($this->hr())
            ->test('pages::leave.approvals')
            ->assertViewHas('applications', fn ($applications) => $applications->total() === 0);
    }

    public function test_it_reaches_hr_once_the_section_head_has_signed(): void
    {
        $this->application->approvals()->where('sequence', 1)->update(['acted_at' => now()]);

        Livewire::actingAs($this->hr())
            ->test('pages::leave.approvals')
            ->assertViewHas('applications', fn ($applications) => $applications->total() === 1);
    }

    public function test_a_decided_application_leaves_every_queue(): void
    {
        $this->application->update(['status' => LeaveStatus::Disapproved]);

        Livewire::actingAs($this->sectionHeadUser)
            ->test('pages::leave.approvals')
            ->assertViewHas('applications', fn ($applications) => $applications->total() === 0);
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
            ->assertHasErrors('remarks');

        $this->assertSame(LeaveStatus::Pending, $this->application->fresh()->status);
    }

    public function test_disapproving_with_a_reason_ends_it(): void
    {
        Livewire::actingAs($this->sectionHeadUser)
            ->test('pages::leave.approvals')
            ->set('remarks', 'Needed on duty')
            ->call('disapprove', $this->application->id);

        $this->assertSame(LeaveStatus::Disapproved, $this->application->fresh()->status);
    }

    public function test_somebody_who_does_not_hold_the_step_is_refused(): void
    {
        // The id travels to the browser. Whether this person holds the step it
        // is sitting on right now is the whole question.
        $stranger = User::factory()->create();
        $stranger->assignRole('employee');
        Employee::factory()->create(['user_id' => $stranger->id]);

        Livewire::actingAs($stranger)
            ->test('pages::leave.approvals')
            ->call('approve', $this->application->id)
            ->assertForbidden();

        $this->assertSame(LeaveStatus::Pending, $this->application->fresh()->status);
    }

    public function test_hr_cannot_act_before_its_turn_even_from_the_url(): void
    {
        Livewire::actingAs($this->hr())
            ->test('pages::leave.approvals')
            ->call('approve', $this->application->id)
            ->assertForbidden();
    }

    public function test_opening_the_queue_records_the_read(): void
    {
        // Reading somebody else's leave is recorded, the same as their PDS.
        $this->actingAs($this->sectionHeadUser)->get(route('leave.approvals'))->assertOk();

        $activity = Activity::where('event', 'read')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($this->application->id, $activity->subject_id);
        $this->assertSame($this->sectionHeadUser->id, $activity->causer_id);
    }

    public function test_reading_your_own_application_is_not_recorded(): void
    {
        // An approver who is also the applicant reads nothing of anybody's but
        // their own, and a log full of that is a log nobody reads.
        $own = LeaveApplication::factory()->create([
            'employee_id' => $this->sectionHeadUser->employee->id,
            'status' => LeaveStatus::Pending,
        ]);

        LeaveApproval::create([
            'leave_application_id' => $own->id,
            'sequence' => 1,
            'step' => LeaveStep::SectionHead,
            'approver_employee_id' => $this->sectionHeadUser->employee->id,
        ]);

        $this->actingAs($this->sectionHeadUser)->get(route('leave.approvals'));

        $this->assertSame(
            0,
            Activity::where('event', 'read')->where('subject_id', $own->id)->count()
        );
    }
}
