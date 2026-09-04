<?php

namespace Tests\Feature\Pds;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PdsReadLoggingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->owner = User::factory()->create();
        $this->owner->assignRole('employee');
        $this->employee = Employee::factory()->create(['user_id' => $this->owner->id]);
    }

    private function userWithRole(string $role, string $name = 'HR Officer'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    /** @return Collection<int, Activity> */
    private function reads()
    {
        return Activity::where('event', 'read')->get();
    }

    public function test_hr_opening_somebody_elses_pds_is_recorded(): void
    {
        $hr = $this->userWithRole('hr', 'Cecilia Burre');

        $this->actingAs($hr)
            ->get(route('pds.personal-information', ['employee' => $this->employee->id]))
            ->assertOk();

        $activity = $this->reads()->first();

        $this->assertNotNull($activity);
        $this->assertSame($hr->id, $activity->causer_id);
        $this->assertSame($this->employee->id, $activity->subject_id);
        $this->assertSame(Employee::class, $activity->subject_type);
        $this->assertStringContainsString('personal information', $activity->description);
    }

    public function test_the_entry_names_the_section_that_was_opened(): void
    {
        $hr = $this->userWithRole('hr');

        $this->actingAs($hr)
            ->get(route('pds.work-experience', ['employee' => $this->employee->id]))
            ->assertOk();

        $this->assertStringContainsString('work experience', $this->reads()->first()->description);
    }

    public function test_an_employee_opening_their_own_pds_is_not_recorded(): void
    {
        // Logging this would bury the entries that matter under thousands that
        // do not.
        $this->actingAs($this->owner)
            ->get(route('pds.personal-information'))
            ->assertOk();

        $this->assertCount(0, $this->reads());
    }

    public function test_each_section_a_reader_opens_is_recorded_separately(): void
    {
        $hr = $this->userWithRole('hr');

        foreach (['pds.personal-information', 'pds.education', 'pds.declarations'] as $route) {
            $this->actingAs($hr)
                ->get(route($route, ['employee' => $this->employee->id]))
                ->assertOk();
        }

        $this->assertCount(3, $this->reads());
    }

    public function test_a_refused_read_records_nothing(): void
    {
        // The policy runs first. A 403 is not a read.
        $intruder = $this->userWithRole('employee', 'Nosy Colleague');
        Employee::factory()->create(['user_id' => $intruder->id]);

        $this->actingAs($intruder)
            ->get(route('pds.personal-information', ['employee' => $this->employee->id]))
            ->assertForbidden();

        $this->assertCount(0, $this->reads());
    }

    public function test_the_read_appears_on_the_audit_log(): void
    {
        $hr = $this->userWithRole('hr', 'Cecilia Burre');

        $this->actingAs($hr)->get(route('pds.personal-information', ['employee' => $this->employee->id]));

        $this->actingAs($hr)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Cecilia Burre')
            ->assertSee('read');
    }

    public function test_the_way_into_a_pds_is_the_employees_own_page(): void
    {
        // Not the list. Reading somebody else's PDS is recorded, and a link in
        // a list of 134 rows makes it a click rather than a decision.
        $hr = $this->userWithRole('hr');
        $link = route('pds.personal-information', ['employee' => $this->employee->id]);

        $this->actingAs($hr)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee($link, escape: false);

        $this->actingAs($hr)
            ->get(route('employees.show', ['employee' => $this->employee->id]))
            ->assertOk()
            ->assertSee($link, escape: false);
    }
}
