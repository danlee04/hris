<?php

namespace Tests\Feature\EmployeeImport;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use App\Services\EmployeeImport\EmployeeCsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeCsvParserTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, implode(',', EmployeeCsvParser::COLUMNS)."\n".$body);

        return $path;
    }

    private function seedReferenceData(): void
    {
        $division = Division::factory()->create(['code' => 'ADMIN']);
        Section::factory()->create(['code' => 'STAT', 'division_id' => $division->id]);
        Position::factory()->create(['title' => 'Statistician II']);
    }

    public function test_a_clean_row_parses_without_errors(): void
    {
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
        ));

        $this->assertFalse($preview->hasErrors());
        $this->assertCount(1, $preview->validRows());
        $this->assertSame('Dela Cruz', $preview->rows[0]->data['last_name']);
        $this->assertSame(2, $preview->rows[0]->lineNumber);
    }

    public function test_a_missing_required_field_is_reported_with_its_line(): void
    {
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
        ));

        $this->assertTrue($preview->hasErrors());
        $this->assertSame(2, $preview->invalidRows()[0]->lineNumber);
        $this->assertContains('last_name is required', $preview->invalidRows()[0]->errors);
    }

    public function test_an_unknown_division_code_is_reported(): void
    {
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,NOPE,STAT,permanent,2014-06-01,1042'
        ));

        $this->assertContains('division_code [NOPE] does not exist', $preview->invalidRows()[0]->errors);
    }

    public function test_an_unknown_position_title_is_reported(): void
    {
        // A plantilla position carries an item number and a salary grade that a
        // title alone cannot supply. Inventing one silently is worse than
        // refusing the row.
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Chief Astronaut,ADMIN,STAT,permanent,2014-06-01,1042'
        ));

        $this->assertContains(
            'position_title [Chief Astronaut] does not exist',
            $preview->invalidRows()[0]->errors
        );
    }

    public function test_an_employee_number_already_in_the_database_is_reported(): void
    {
        $this->seedReferenceData();
        Employee::factory()->create(['employee_number' => '2014-0042']);

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,'
        ));

        $this->assertContains('employee_number [2014-0042] already exists', $preview->invalidRows()[0]->errors);
    }

    public function test_a_soft_deleted_employee_still_holds_its_number(): void
    {
        // The unique index does not care that the row is soft deleted. If the
        // parser passed this, the import would die on a duplicate key.
        $this->seedReferenceData();
        Employee::factory()->create(['employee_number' => '2014-0042'])->delete();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,'
        ));

        $this->assertContains('employee_number [2014-0042] already exists', $preview->invalidRows()[0]->errors);
    }

    public function test_an_employee_number_repeated_inside_the_file_is_reported(): void
    {
        // The database cannot catch this one — neither row is written yet.
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            "2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,\n".
            '2014-0042,Maria,Reyes,Bautista,,Statistician II,ADMIN,STAT,permanent,2015-01-05,'
        ));

        $this->assertContains('employee_number [2014-0042] is repeated on line 2', $preview->rows[1]->errors);
        $this->assertTrue($preview->rows[0]->isValid());
    }

    public function test_an_unknown_employment_status_is_reported(): void
    {
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,casual,2014-06-01,'
        ));

        // The message lists labels, not enum values — it is read by whoever has
        // to go and fix the spreadsheet.
        $this->assertContains(
            'employment_status [casual] is not one of: Permanent, Job Order, Contract of Service, Co-terminous',
            $preview->invalidRows()[0]->errors
        );
    }

    public function test_a_position_title_matches_whatever_case_hr_typed(): void
    {
        // The plantilla says MEDICAL OFFICER III; a roster typed by a person
        // says Medical Officer III. Both are the same position.
        Division::factory()->create(['code' => 'ADMIN']);
        Section::factory()->create(['code' => 'STAT']);
        Position::factory()->create(['title' => 'MEDICAL OFFICER III']);

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Medical Officer III,ADMIN,STAT,permanent,2014-06-01,'
        ));

        $this->assertFalse($preview->hasErrors());
    }

    public function test_an_employment_status_matches_its_label_as_well_as_its_value(): void
    {
        // A spreadsheet exported by a person says "Contract of Service", not
        // "contract_of_service". Refusing that is a defect in the importer.
        $this->seedReferenceData();

        foreach (['Contract of Service', 'contract_of_service', 'Co-terminous', 'COTERMINOUS'] as $i => $written) {
            $preview = app(EmployeeCsvParser::class)->parse($this->csv(
                "2014-004{$i},Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,{$written},2014-06-01,"
            ));

            $this->assertFalse($preview->hasErrors(), "[{$written}] should have been accepted");
        }
    }

    public function test_a_wrong_header_is_rejected_outright(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, "name,email\nJuan,juan@example.com");

        $preview = app(EmployeeCsvParser::class)->parse($path);

        $this->assertTrue($preview->hasErrors());
        $this->assertSame(1, $preview->rows[0]->lineNumber);
        $this->assertStringContainsString('header does not match', $preview->rows[0]->errors[0]);
    }

    public function test_a_short_row_is_padded_rather_than_exploding(): void
    {
        // Excel drops trailing empty columns on export. This is the single most
        // common shape of a real file.
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT'
        ));

        $this->assertFalse($preview->hasErrors());
        $this->assertSame('', $preview->rows[0]->data['biometric_id']);
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->seedReferenceData();

        $preview = app(EmployeeCsvParser::class)->parse($this->csv(
            '  2014-0042 , Juan ,Santos, Dela Cruz ,,Statistician II,ADMIN,STAT,permanent,2014-06-01,'
        ));

        $this->assertFalse($preview->hasErrors());
        $this->assertSame('2014-0042', $preview->rows[0]->data['employee_number']);
        $this->assertSame('Dela Cruz', $preview->rows[0]->data['last_name']);
    }
}
