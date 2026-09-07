<?php

namespace Tests\Feature\Employees;

use App\Models\Employee;
use App\Models\Position;
use App\Services\Employees\CertificateOfEmployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CertificateOfEmploymentTest extends TestCase
{
    use RefreshDatabase;

    private function certificate(): CertificateOfEmployment
    {
        return app(CertificateOfEmployment::class);
    }

    public function test_it_names_the_person_the_way_a_signed_document_does(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Mary Jane',
            'middle_name' => 'Estrada',
            'last_name' => 'Lao Guico',
            'credentials' => null,
            'date_hired' => '2019-01-03',
        ]);

        $data = $this->certificate()->for($employee);

        $this->assertSame('MARY JANE E. LAO GUICO', $data['name']);
        $this->assertSame('3 January 2019', $data['since']);
    }

    public function test_it_prints_the_salary_grade_when_nobody_typed_an_amount(): void
    {
        // The grade is the part the system can vouch for: it comes off the
        // plantilla item, not off somebody's memory.
        $employee = Employee::factory()->create([
            'position_id' => Position::factory()->create(['salary_grade' => 16])->id,
        ]);

        $this->assertSame('Salary Grade 16', $this->certificate()->for($employee)['salary']);
    }

    public function test_a_typed_amount_prints_beside_the_grade(): void
    {
        $employee = Employee::factory()->create([
            'position_id' => Position::factory()->create(['salary_grade' => 16])->id,
        ]);

        $this->assertSame(
            '₱39,672.00 per month (Salary Grade 16)',
            $this->certificate()->for($employee, '39672')['salary']
        );
    }

    public function test_it_says_nothing_about_pay_when_there_is_nothing_to_say(): void
    {
        // Silence rather than a zero. A certificate reading "₱0.00 per month"
        // is worse than one that does not mention pay at all.
        $employee = Employee::factory()->create([
            'position_id' => Position::factory()->create(['salary_grade' => null])->id,
        ]);

        $this->assertNull($this->certificate()->for($employee)['salary']);
    }

    public function test_the_closing_line_has_a_default_and_takes_a_replacement(): void
    {
        $employee = Employee::factory()->create();

        $this->assertSame(
            'whatever legal purpose it may serve',
            $this->certificate()->for($employee)['purpose']
        );

        $this->assertSame(
            'her loan application with Land Bank of the Philippines',
            $this->certificate()->for($employee, null, ' her loan application with Land Bank of the Philippines ')['purpose']
        );
    }

    public function test_it_refuses_a_separated_employee(): void
    {
        // The system holds is_active, not the date they left. The certificate
        // would read as current employment for somebody who resigned last year,
        // and the hospital would have signed it.
        $employee = Employee::factory()->create(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->certificate()->for($employee);
    }

    public function test_it_refuses_a_record_with_no_hire_date(): void
    {
        $employee = Employee::factory()->create(['date_hired' => null]);

        $this->expectException(ValidationException::class);

        $this->certificate()->for($employee);
    }

    public function test_it_signs_with_the_hr_officer_on_the_employee_master(): void
    {
        // The same person whose name prints on CS Form 6. Two sources for one
        // signature is two names that can disagree.
        Employee::factory()->create([
            'first_name' => 'Mary Jane',
            'middle_name' => 'Estrada',
            'last_name' => 'Lao Guico',
            'is_hr_officer' => true,
        ]);

        $employee = Employee::factory()->create();

        $this->assertSame('MARY JANE E. LAO GUICO', $this->certificate()->for($employee)['signatory']);
    }
}
