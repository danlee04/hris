<?php

namespace Tests\Feature\Pds;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function employeeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('employee');
        Employee::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_an_employee_opens_their_own_pds(): void
    {
        $this->actingAs($this->employeeUser())
            ->get(route('pds.personal-information'))
            ->assertOk();
    }

    public function test_an_employee_cannot_open_another_pds_by_changing_the_url(): void
    {
        // The whole reason this phase has a policy. What is on the other side
        // is a home address, a TIN, and the answers to items 34 to 40.
        $someoneElse = Employee::factory()->create();

        $this->actingAs($this->employeeUser())
            ->get(route('pds.personal-information', ['employee' => $someoneElse->id]))
            ->assertForbidden();
    }

    public function test_a_user_with_no_employee_record_is_refused(): void
    {
        // Every account has a role; not every account is linked to an employee.
        $this->actingAs($this->userWithRole('employee'))
            ->get(route('pds.personal-information'))
            ->assertForbidden();
    }

    public function test_hr_opens_any_pds(): void
    {
        $someoneElse = Employee::factory()->create();

        $this->actingAs($this->userWithRole('hr'))
            ->get(route('pds.personal-information', ['employee' => $someoneElse->id]))
            ->assertOk();
    }

    public function test_an_unlinked_employee_record_belongs_to_nobody(): void
    {
        // Every imported employee starts with a null user_id. A null must never
        // match a null and hand one person somebody else's PDS.
        $orphan = Employee::factory()->create(['user_id' => null]);

        $this->actingAs($this->employeeUser())
            ->get(route('pds.personal-information', ['employee' => $orphan->id]))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('pds.personal-information'))->assertRedirect(route('login'));
    }
}
