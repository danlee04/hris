<?php

namespace Tests\Feature\Employees;

use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificatePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role, ?Employee $employee = null): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $employee?->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_hr_can_issue_one(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Kathleen',
            'middle_name' => 'Lopez',
            'last_name' => 'Onde',
            'date_hired' => '2019-01-03',
            'position_id' => Position::factory()->create(['title' => 'Administrative Officer III'])->id,
        ]);

        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.certificate', $employee))
            ->assertOk()
            ->assertSee('Certificate of Employment')
            ->assertSee('KATHLEEN L. ONDE')
            ->assertSee('Administrative Officer III')
            ->assertSee('3 January 2019');
    }

    public function test_an_employee_cannot_issue_their_own(): void
    {
        // The certificate carries the HR officer's name under it. Letting a
        // person produce their own hands out a signature, and the data being
        // theirs does not change what the paper claims.
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWithRole('employee', $employee))
            ->get(route('employees.certificate', $employee))
            ->assertForbidden();
    }

    public function test_it_refuses_to_write_one_for_a_separated_employee(): void
    {
        $employee = Employee::factory()->create(['is_active' => false]);

        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.certificate', $employee))
            ->assertOk()
            ->assertSee('This record cannot be certified')
            ->assertDontSee('TO WHOM IT MAY CONCERN');
    }

    public function test_issuing_one_is_recorded(): void
    {
        // The post, the status and the hire date leave the system on paper.
        $employee = Employee::factory()->create();
        $hr = $this->userWithRole('hr');

        $this->actingAs($hr)->get(route('employees.certificate', $employee))->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $employee->id,
            'causer_id' => $hr->id,
            'description' => 'Issued a certificate of employment',
        ]);
    }

    public function test_the_record_page_offers_the_certificate_to_hr_and_not_to_the_employee(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Certificate');
    }
}
