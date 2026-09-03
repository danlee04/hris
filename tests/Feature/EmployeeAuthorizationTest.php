<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_an_employee_cannot_open_the_employee_list(): void
    {
        $user = $this->userWithRole('employee');
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get(route('employees.index'))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('employees.index'))->assertRedirect(route('login'));
    }

    public function test_hr_can_open_the_employee_list(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.index'))
            ->assertOk();
    }

    public function test_the_list_shows_employees_and_finds_them_by_search(): void
    {
        Employee::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan']);
        Employee::factory()->create(['last_name' => 'Bautista', 'first_name' => 'Maria']);

        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.index', ['search' => 'Dela']))
            ->assertOk()
            ->assertSee('Dela Cruz, Juan')
            ->assertDontSee('Bautista, Maria');
    }

    public function test_an_employee_reaches_their_own_record(): void
    {
        $user = $this->userWithRole('employee');
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->can('view', $own));
    }

    public function test_an_employee_cannot_reach_another_record(): void
    {
        // This is the IDOR case. It must fail.
        $user = $this->userWithRole('employee');
        Employee::factory()->create(['user_id' => $user->id]);
        $someoneElse = Employee::factory()->create();

        $this->assertFalse($user->can('view', $someoneElse));
    }

    public function test_an_unlinked_employee_record_belongs_to_nobody(): void
    {
        // user_id is null on every imported row. A null must never match a
        // null and hand one person somebody else's record.
        $user = $this->userWithRole('employee');
        $orphan = Employee::factory()->create(['user_id' => null]);

        $this->assertFalse($user->can('view', $orphan));
    }

    public function test_hr_reaches_any_record(): void
    {
        $hr = $this->userWithRole('hr');
        $someoneElse = Employee::factory()->create();

        $this->assertTrue($hr->can('view', $someoneElse));
        $this->assertTrue($hr->can('update', $someoneElse));
    }

    public function test_an_employee_cannot_edit_even_their_own_record(): void
    {
        // The employee master is HR's. What an employee edits is their PDS,
        // which is a different question answered by a different policy.
        $user = $this->userWithRole('employee');
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->assertFalse($user->can('update', $own));
    }

    public function test_only_an_admin_issues_accounts(): void
    {
        $this->assertFalse($this->userWithRole('hr')->can('issueAccount', Employee::class));
        $this->assertTrue($this->userWithRole('admin')->can('issueAccount', Employee::class));
    }

    public function test_hr_can_import_but_an_employee_cannot(): void
    {
        $this->assertTrue($this->userWithRole('hr')->can('import', Employee::class));
        $this->assertFalse($this->userWithRole('employee')->can('import', Employee::class));
    }
}
