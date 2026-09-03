<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class IssueAccountTest extends TestCase
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

    public function test_hr_cannot_open_the_issue_account_screen(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.issue-account'))
            ->assertForbidden();
    }

    public function test_an_admin_can_open_the_issue_account_screen(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('employees.issue-account'))
            ->assertOk();
    }

    public function test_an_admin_issues_a_login_linked_to_the_employee(): void
    {
        $employee = Employee::factory()->create(['user_id' => null]);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::employees.issue-account')
            ->set('employeeId', $employee->id)
            ->set('email', 'juan@example.com')
            ->set('temporaryPassword', 'temporary-password')
            ->call('issue')
            ->assertHasNoErrors();

        $employee->refresh();

        $this->assertNotNull($employee->user_id);
        $this->assertSame('juan@example.com', $employee->user->email);
        $this->assertTrue($employee->user->hasRole('employee'));
        $this->assertTrue($employee->user->must_change_password);
        $this->assertTrue(Hash::check('temporary-password', $employee->user->password));
    }

    public function test_an_employee_who_already_has_a_login_is_refused(): void
    {
        $employee = Employee::factory()->create(['user_id' => User::factory()->create()->id]);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::employees.issue-account')
            ->set('employeeId', $employee->id)
            ->set('email', 'second@example.com')
            ->set('temporaryPassword', 'temporary-password')
            ->call('issue')
            ->assertHasErrors('employeeId');

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $employee = Employee::factory()->create(['user_id' => null]);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::employees.issue-account')
            ->set('employeeId', $employee->id)
            ->set('email', 'taken@example.com')
            ->set('temporaryPassword', 'temporary-password')
            ->call('issue')
            ->assertHasErrors('email');

        $this->assertNull($employee->refresh()->user_id);
    }

    public function test_an_employee_who_must_change_their_password_is_sent_to_the_security_page(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole('employee');

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('security.edit'));
    }

    public function test_the_password_confirmation_step_stays_reachable(): void
    {
        // Without this the redirect bounces forever: security.edit sits behind
        // Fortify's password.confirm, which is itself a page the middleware
        // would otherwise send back to security.edit.
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole('employee');

        $this->actingAs($user)->get(route('password.confirm'))->assertOk();
    }

    public function test_an_employee_who_has_changed_their_password_reaches_the_dashboard(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('employee');

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_changing_the_password_clears_the_flag(): void
    {
        // Without this the employee changes their password and is still held
        // on the security page forever.
        $user = User::factory()->create([
            'password' => 'temporary-password',
            'must_change_password' => true,
        ]);
        $user->assignRole('employee');

        Livewire::actingAs($user)
            ->test('pages::settings.security')
            ->set('current_password', 'temporary-password')
            ->set('password', 'a-password-of-their-own')
            ->set('password_confirmation', 'a-password-of-their-own')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertFalse($user->refresh()->must_change_password);
        $this->assertTrue(Hash::check('a-password-of-their-own', $user->password));
    }
}
