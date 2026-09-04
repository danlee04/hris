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

    private function vacation(): int
    {
        return LeaveType::where('code', 'VL')->sole()->id;
    }

    public function test_an_employee_files_leave(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('startApplying')
            ->set('form.leave_type_id', $this->vacation())
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
            ->set('form.leave_type_id', $this->vacation())
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
        // The id travels to the browser and comes back as whatever was sent.
        $someoneElse = LeaveApplication::factory()->create(['status' => LeaveStatus::Pending]);

        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('cancel', $someoneElse->id)
            ->assertForbidden();

        $this->assertSame(LeaveStatus::Pending, $someoneElse->fresh()->status);
    }

    public function test_the_applicant_cannot_refile_somebody_elses(): void
    {
        $someoneElse = LeaveApplication::factory()->create(['status' => LeaveStatus::Returned]);

        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('startRefiling', $someoneElse->id)
            ->assertForbidden();
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
            ->set('form.leave_type_id', $this->vacation())
            ->set('form.date_from', now()->addWeek()->toDateString())
            ->set('form.date_to', now()->addWeek()->addDay()->toDateString())
            ->set('form.days', 2)
            ->call('file')
            ->assertHasErrors('form.leave_type_id');

        $this->assertDatabaseCount('leave_applications', 0);
    }

    public function test_a_refused_filing_leaves_the_modal_open_with_the_typing_intact(): void
    {
        // Validation that closes the modal throws away everything the person
        // typed, and they cannot see what was wrong with it either.
        Livewire::actingAs($this->user)
            ->test('pages::leave.mine')
            ->call('startApplying')
            ->set('form.leave_type_id', $this->vacation())
            ->set('form.date_from', now()->addWeek()->addDays(3)->toDateString())
            ->set('form.date_to', now()->addWeek()->toDateString())
            ->set('form.days', 2)
            ->call('file')
            ->assertHasErrors('form.date_to')
            ->assertSet('form.days', 2);
    }
}
