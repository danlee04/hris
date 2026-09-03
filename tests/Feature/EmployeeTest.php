<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_employee_exists_without_a_login(): void
    {
        // The CSV import runs before any account is issued. This must hold.
        $employee = Employee::factory()->create(['user_id' => null]);

        $this->assertNull($employee->user);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'user_id' => null]);
    }

    public function test_a_login_belongs_to_exactly_one_employee(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->refresh()->employee->is($employee));

        $this->expectException(QueryException::class);

        Employee::factory()->create(['user_id' => $user->id]);
    }

    public function test_an_employee_number_is_unique(): void
    {
        Employee::factory()->create(['employee_number' => '2014-0042']);

        $this->expectException(QueryException::class);

        Employee::factory()->create(['employee_number' => '2014-0042']);
    }

    public function test_an_employee_is_soft_deleted(): void
    {
        // Records hang off this row for years. Never hard-delete.
        $employee = Employee::factory()->create();

        $employee->delete();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_the_employment_status_comes_back_as_an_enum(): void
    {
        $employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::JobOrder->value,
        ]);

        $this->assertSame(EmploymentStatus::JobOrder, $employee->refresh()->employment_status);
        $this->assertSame('Job Order', $employee->employment_status->label());
    }

    public function test_the_full_name_reads_surname_first(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'suffix' => 'Jr.',
        ]);

        $this->assertSame('Dela Cruz, Juan S. Jr.', $employee->fullName());
    }

    public function test_the_full_name_survives_a_missing_middle_name(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Maria',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'suffix' => null,
        ]);

        $this->assertSame('Reyes, Maria', $employee->fullName());
    }

    public function test_a_division_head_points_back_at_an_employee(): void
    {
        $employee = Employee::factory()->create();
        $division = $employee->section->division;

        $division->update(['division_head_employee_id' => $employee->id]);

        $this->assertTrue($division->refresh()->head->is($employee));
    }
}
