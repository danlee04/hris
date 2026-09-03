<?php

namespace Tests\Feature\EmployeeImport;

use App\Enums\EmploymentStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use App\Services\EmployeeImport\CsvRow;
use App\Services\EmployeeImport\EmployeeImporter;
use App\Services\EmployeeImport\ImportPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EmployeeImporterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $overrides
     * @param  list<string>  $errors
     */
    private function row(int $line, array $overrides = [], array $errors = []): CsvRow
    {
        return new CsvRow($line, array_merge([
            'employee_number' => '2014-0042',
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'suffix' => '',
            'position_title' => 'Statistician II',
            'division_code' => 'ADMIN',
            'section_code' => 'STAT',
            'employment_status' => 'permanent',
            'date_hired' => '2014-06-01',
            'biometric_id' => '1042',
        ], $overrides), $errors);
    }

    private function seedReferenceData(): void
    {
        $division = Division::factory()->create(['code' => 'ADMIN']);
        Section::factory()->create(['code' => 'STAT', 'division_id' => $division->id]);
        Position::factory()->create(['title' => 'Statistician II']);
    }

    public function test_it_creates_an_employee_with_its_references_resolved(): void
    {
        $this->seedReferenceData();

        $created = app(EmployeeImporter::class)->import(new ImportPreview([$this->row(2)]));

        $this->assertSame(1, $created);

        $employee = Employee::firstWhere('employee_number', '2014-0042');

        $this->assertSame('Dela Cruz', $employee->last_name);
        $this->assertSame('STAT', $employee->section->code);
        $this->assertSame('ADMIN', $employee->division->code);
        $this->assertSame('Statistician II', $employee->position->title);
        $this->assertSame(EmploymentStatus::Permanent, $employee->employment_status);
        $this->assertNull($employee->user_id);
    }

    public function test_it_refuses_a_preview_that_has_any_error(): void
    {
        $this->seedReferenceData();

        $preview = new ImportPreview([
            $this->row(2),
            $this->row(3, ['employee_number' => '2015-0100'], ['last_name is required']),
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(EmployeeImporter::class)->import($preview);
    }

    public function test_a_refused_import_writes_nothing_at_all(): void
    {
        // Importing the good half of a file leaves HR with no way to know
        // which half they now have to fix by hand. All or nothing.
        $this->seedReferenceData();

        $preview = new ImportPreview([
            $this->row(2),
            $this->row(3, ['employee_number' => '2015-0100'], ['last_name is required']),
        ]);

        try {
            app(EmployeeImporter::class)->import($preview);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, Employee::count());
    }

    public function test_a_blank_optional_column_becomes_null_not_an_empty_string(): void
    {
        // biometric_id is unique. A second employee carrying '' rather than
        // null would collide, and the failure would surface as an unrelated
        // duplicate-key error halfway through a 500-row import.
        $this->seedReferenceData();

        app(EmployeeImporter::class)->import(new ImportPreview([
            $this->row(2, ['suffix' => '', 'biometric_id' => '', 'date_hired' => '']),
            $this->row(3, ['employee_number' => '2015-0100', 'biometric_id' => '']),
        ]));

        $employee = Employee::firstWhere('employee_number', '2014-0042');

        $this->assertNull($employee->suffix);
        $this->assertNull($employee->biometric_id);
        $this->assertNull($employee->date_hired);
        $this->assertSame(2, Employee::count());
    }

    public function test_an_empty_preview_imports_nobody(): void
    {
        $this->assertSame(0, app(EmployeeImporter::class)->import(new ImportPreview([])));
    }
}
