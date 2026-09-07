<?php

namespace Tests\Feature\Dashboard;

use App\Models\Division;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role, bool $linked = false): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if ($linked) {
            Employee::factory()->create(['user_id' => $user->id]);
        }

        return $user;
    }

    public function test_hr_sees_the_hospital_figures(): void
    {
        $division = Division::factory()->create(['name' => 'Nursing Services']);
        $section = Section::factory()->create(['division_id' => $division->id]);
        Employee::factory()->count(2)->create(['section_id' => $section->id]);

        $this->actingAs($this->userWithRole('hr'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Employees on the roster')
            ->assertSee('Have no leave credits')
            ->assertSee('Where people work')
            ->assertSee('Nursing Services')
            ->assertSee('How they are appointed');
    }

    public function test_an_employee_is_not_shown_the_hospital_figures(): void
    {
        // A count of who has no login is not an employee's business, and the
        // page must not run the query either.
        Employee::factory()->count(2)->create();

        $this->actingAs($this->userWithRole('employee', linked: true))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Employees on the roster')
            ->assertDontSee('Cannot sign in yet')
            ->assertDontSee('Where people work');
    }

    public function test_an_employee_sees_their_own_balance_and_the_quick_doors(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $this->actingAs($this->userWithRole('employee', linked: true))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your leave')
            ->assertSee('Go to')
            ->assertSee('File leave')
            ->assertSee('My PDS');
    }

    public function test_an_approver_sees_what_is_waiting_on_them(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $head = $this->userWithRole('employee', linked: true);
        $section = Section::factory()->create(['section_head_employee_id' => $head->employee->id]);
        $applicant = Employee::factory()->create(['section_id' => $section->id]);

        $application = LeaveApplication::factory()->create([
            'employee_id' => $applicant->id,
            'leave_type_id' => LeaveType::where('code', 'VL')->sole()->id,
        ]);

        $application->approvals()->create([
            'sequence' => 1,
            'step' => 'section_head',
            'approver_employee_id' => $head->employee->id,
        ]);

        $this->actingAs($head)
            ->get(route('dashboard'))
            ->assertOk()
            // The page leads with the sentence, not with a figure: what an
            // approver opens the system to learn is whether anything needs
            // them, and "1" does not say that.
            ->assertSee('One application needs your signature.')
            ->assertSee($applicant->fullName());
    }

    public function test_a_person_with_an_empty_queue_is_told_so_plainly(): void
    {
        $this->actingAs($this->userWithRole('employee', linked: true))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nothing needs your signature.');
    }

    public function test_the_curve_waits_until_there_is_something_to_draw(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nobody has filed leave yet. The curve starts with the first application.');
    }

    public function test_the_curve_is_drawn_from_the_first_application_onward(): void
    {
        // Rendered against real data rather than left dormant: a chart nobody
        // draws for six months is a chart nobody knows is right.
        $this->seed(LeaveTypeSeeder::class);

        $employee = Employee::factory()->create();
        $type = LeaveType::where('code', 'VL')->sole();

        foreach ([3, 0] as $monthsAgo) {
            LeaveApplication::factory()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'filed_at' => now()->subMonths($monthsAgo),
            ]);
        }

        $this->actingAs($this->userWithRole('hr'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Nobody has filed leave yet.')
            ->assertSee('Leave applications filed each month')
            // A cubic path, which is what a curve is. A polyline here would
            // mean the smoothing quietly stopped being applied.
            ->assertSee('<path d="M ', escape: false);
    }

    public function test_an_account_with_no_employee_record_still_loads(): void
    {
        // The admin account belongs to nobody. Every personal panel has to be
        // absent rather than empty.
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Your leave')
            ->assertDontSee('What you have asked for');
    }
}
