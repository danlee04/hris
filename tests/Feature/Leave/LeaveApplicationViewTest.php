<?php

namespace Tests\Feature\Leave;

use App\Enums\ApprovalAction;
use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class LeaveApplicationViewTest extends TestCase
{
    use RefreshDatabase;

    private LeaveApplication $application;

    private User $sectionHeadUser;

    private Employee $sectionHead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        $this->application = LeaveApplication::factory()->create([
            'leave_type_id' => LeaveType::where('code', 'SL')->sole()->id,
            'status' => LeaveStatus::Pending,
            'purpose' => 'Recovering from dengue',
            'details' => ['sick_where' => 'out_patient', 'sick_detail' => 'Dengue'],
        ]);

        $this->sectionHead = Employee::factory()->create();
        $this->sectionHeadUser = User::factory()->create();
        $this->sectionHeadUser->assignRole('employee');
        $this->sectionHead->update(['user_id' => $this->sectionHeadUser->id]);

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
        ]);
    }

    public function test_an_approver_opens_the_application_and_sees_the_purpose(): void
    {
        // Recommending a run of dates without knowing what they are for is
        // what the corridor conversation is currently for.
        Livewire::actingAs($this->sectionHeadUser)
            ->test('pages::leave.approvals')
            ->call('open', $this->application->id)
            ->assertSet('viewingId', $this->application->id)
            ->assertSee('Recovering from dengue');
    }

    public function test_the_view_shows_the_answers_the_form_asks_for(): void
    {
        Livewire::actingAs($this->sectionHeadUser)
            ->test('pages::leave.approvals')
            ->call('open', $this->application->id)
            ->assertSee('Dengue')
            ->assertSee('Out patient');
    }

    public function test_the_view_shows_who_has_acted_and_what_they_said(): void
    {
        $this->application->approvals()->where('sequence', 1)->update([
            'action' => ApprovalAction::Return,
            'remarks' => 'The dates are wrong',
            'acted_at' => now(),
        ]);

        Livewire::actingAs($this->sectionHeadUser)
            ->test('pages::leave.approvals')
            ->call('open', $this->application->id)
            ->assertSee('The dates are wrong');
    }

    public function test_a_stranger_cannot_open_it(): void
    {
        // The id travels to the browser. A sick leave says something about a
        // person's health, and the purpose says more.
        $stranger = User::factory()->create();
        $stranger->assignRole('employee');
        Employee::factory()->create(['user_id' => $stranger->id]);

        Livewire::actingAs($stranger)
            ->test('pages::leave.approvals')
            ->call('open', $this->application->id)
            ->assertForbidden();
    }

    public function test_hr_can_open_any_application(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('hr');

        Livewire::actingAs($hr)
            ->test('pages::leave.approvals')
            ->call('open', $this->application->id)
            ->assertSet('viewingId', $this->application->id);
    }

    public function test_the_applicant_opens_their_own_from_my_leave(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        $this->application->employee->update(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('pages::leave.mine')
            ->call('open', $this->application->id)
            ->assertSee('Recovering from dengue');
    }

    public function test_opening_somebody_elses_application_is_recorded(): void
    {
        // Reading somebody else's leave is recorded, the same as their PDS.
        Livewire::actingAs($this->sectionHeadUser)
            ->test('pages::leave.approvals')
            ->call('open', $this->application->id);

        $this->assertTrue(
            Activity::where('event', 'read')
                ->where('subject_id', $this->application->id)
                ->where('description', 'like', '%Opened%')
                ->exists()
        );
    }
}
