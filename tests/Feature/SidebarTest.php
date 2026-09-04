<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarTest extends TestCase
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

    public function test_an_employee_sees_only_their_own_group(): void
    {
        // A heading with nothing under it tells somebody there is a door they
        // cannot open.
        $this->actingAs($this->userWithRole('employee', linked: true))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Mine')
            ->assertSee('My PDS')
            ->assertDontSee('Human resource')
            ->assertDontSee('Setup');
    }

    public function test_hr_sees_their_group_but_not_setup(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Human resource')
            ->assertSee('Post leave credits')
            ->assertSee('Audit log')
            // Organization, leave types and issuing logins are the admin's.
            ->assertDontSee('Setup');
    }

    public function test_an_admin_sees_setup(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Setup')
            ->assertSee('Organization')
            ->assertSee('Leave types')
            ->assertSee('Issue a login');
    }

    public function test_an_account_with_no_employee_record_is_not_offered_my_pds(): void
    {
        // Those screens are about a person, and this account is not one.
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('My PDS')
            ->assertDontSee('My leave');
    }

    public function test_hr_without_an_employee_record_still_reaches_approvals(): void
    {
        // The HR step is held by an office, so the queue has to be there even
        // when the account belongs to nobody.
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Approvals');
    }
}
