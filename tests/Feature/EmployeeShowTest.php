<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeShowTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->employee = Employee::factory()->create(['last_name' => 'Guico']);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_hr_sees_the_employee_and_the_pds_actions(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.show', ['employee' => $this->employee->id]))
            ->assertOk()
            ->assertSee('Guico')
            ->assertSee(route('pds.export', ['employee' => $this->employee->id]), escape: false)
            ->assertSee(route('pds.personal-information', ['employee' => $this->employee->id]), escape: false);
    }

    public function test_an_employee_sees_their_own_page(): void
    {
        $user = $this->userWithRole('employee');
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('employees.show', ['employee' => $own->id]))
            ->assertOk()
            ->assertSee(route('pds.export', ['employee' => $own->id]), escape: false);
    }

    public function test_an_employee_cannot_see_somebody_elses_page(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get(route('employees.show', ['employee' => $this->employee->id]))
            ->assertForbidden();
    }

    public function test_a_viewer_without_the_pds_ability_is_not_offered_the_download(): void
    {
        // The card is guarded by viewPds, not by whether the page loaded. A
        // download link rendered for somebody who would be refused is a link
        // that teaches them the URL.
        $viewer = $this->userWithRole('employee');
        $viewer->givePermissionTo('employees.view');

        $this->actingAs($viewer)
            ->get(route('employees.show', ['employee' => $this->employee->id]))
            ->assertOk()
            ->assertDontSee(route('pds.export', ['employee' => $this->employee->id]), escape: false);
    }

    public function test_the_page_offers_hr_the_edit_modal(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.show', ['employee' => $this->employee->id]))
            ->assertOk()
            ->assertSee('edit-employee');
    }

    public function test_an_employee_is_not_offered_the_edit_modal_on_their_own_page(): void
    {
        $user = $this->userWithRole('employee');
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('employees.show', ['employee' => $own->id]))
            ->assertOk()
            ->assertDontSee('edit-employee');
    }
}
