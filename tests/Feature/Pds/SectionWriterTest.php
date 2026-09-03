<?php

namespace Tests\Feature\Pds;

use App\Enums\EducationLevel;
use App\Enums\OtherEntryKind;
use App\Models\Employee;
use App\Models\Pds\Child;
use App\Models\Pds\Education;
use App\Models\Pds\Eligibility;
use App\Models\Pds\LearningDevelopment;
use App\Models\Pds\OtherEntry;
use App\Models\Pds\VoluntaryWork;
use App\Models\Pds\WorkExperience;
use App\Services\Pds\PdsExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class SectionWriterTest extends TestCase
{
    use RefreshDatabase;

    private function exported(Employee $employee): Spreadsheet
    {
        return IOFactory::load(app(PdsExporter::class)->export($employee));
    }

    private function cell(Spreadsheet $book, string $sheet, string $reference): mixed
    {
        return $book->getSheetByName($sheet)->getCell($reference)->getValue();
    }

    public function test_work_experience_fills_its_rows_in_order(): void
    {
        $employee = Employee::factory()->create();

        foreach (['First post', 'Second post', 'Third post'] as $i => $title) {
            WorkExperience::factory()->create([
                'employee_id' => $employee->id,
                'position_title' => $title,
                'sort_order' => $i,
            ]);
        }

        $book = $this->exported($employee);

        $this->assertSame('First post', $this->cell($book, 'C2', 'D18'));
        $this->assertSame('Second post', $this->cell($book, 'C2', 'D19'));
        $this->assertSame('Third post', $this->cell($book, 'C2', 'D20'));
    }

    public function test_a_post_still_held_leaves_the_end_date_blank(): void
    {
        // The form prints PRESENT there. A blank cell is what the employee
        // fills in by hand; an invented word would be a claim we did not make.
        $employee = Employee::factory()->create();

        WorkExperience::factory()->create([
            'employee_id' => $employee->id,
            'position_title' => 'Current post',
            'date_to' => null,
        ]);

        $this->assertNull($this->cell($this->exported($employee), 'C2', 'C18'));
    }

    public function test_government_service_prints_y_or_n(): void
    {
        // The column header asks the question outright: "GOV'T SERVICE (Y/ N)".
        $employee = Employee::factory()->create();

        WorkExperience::factory()->create([
            'employee_id' => $employee->id,
            'position_title' => 'Government post',
            'is_government_service' => true,
            'sort_order' => 0,
        ]);
        WorkExperience::factory()->create([
            'employee_id' => $employee->id,
            'position_title' => 'Private post',
            'is_government_service' => false,
            'sort_order' => 1,
        ]);

        $book = $this->exported($employee);

        $this->assertSame('Y', $this->cell($book, 'C2', 'M18'));
        $this->assertSame('N', $this->cell($book, 'C2', 'M19'));
    }

    public function test_rows_beyond_the_printed_page_go_to_the_continuation_sheet(): void
    {
        // Page 2 holds 24. Work experience is the section that overflows most.
        $employee = Employee::factory()->create();

        foreach (range(1, 26) as $i) {
            WorkExperience::factory()->create([
                'employee_id' => $employee->id,
                'position_title' => "Post {$i}",
                'sort_order' => $i,
            ]);
        }

        $book = $this->exported($employee);

        $this->assertSame('Post 24', $this->cell($book, 'C2', 'D41'));
        $this->assertSame('Post 25', $this->cell($book, 'C6_Work Exp cont.', 'D7'));
        $this->assertSame('Post 26', $this->cell($book, 'C6_Work Exp cont.', 'D8'));
    }

    public function test_a_section_that_fits_leaves_its_continuation_sheet_empty(): void
    {
        $employee = Employee::factory()->create();

        WorkExperience::factory()->count(2)->create(['employee_id' => $employee->id]);

        $this->assertNull($this->cell($this->exported($employee), 'C6_Work Exp cont.', 'D7'));
    }

    public function test_the_continuation_sheet_may_use_different_columns(): void
    {
        // A child's date of birth is in M on page 1 and in L on C7. Assuming
        // they match would print the date under the wrong heading.
        $employee = Employee::factory()->create();

        foreach (range(1, 15) as $i) {
            Child::factory()->create([
                'employee_id' => $employee->id,
                'name' => "Child {$i}",
                'date_of_birth' => '2010-01-05',
                'sort_order' => $i,
            ]);
        }

        $book = $this->exported($employee);

        $this->assertSame('Child 1', $this->cell($book, 'C1', 'I37'));
        $this->assertSame('05/01/2010', $this->cell($book, 'C1', 'M37'));
        $this->assertSame('Child 14', $this->cell($book, 'C7_Family Background cont. ', 'I4'));
        $this->assertSame('05/01/2010', $this->cell($book, 'C7_Family Background cont. ', 'L4'));
    }

    public function test_eligibility_uses_its_own_continuation_columns(): void
    {
        // The licence number is in L on page 2 and in J on C9.
        $employee = Employee::factory()->create();

        foreach (range(1, 9) as $i) {
            Eligibility::factory()->create([
                'employee_id' => $employee->id,
                'eligibility' => "Eligibility {$i}",
                'license_number' => "LIC-{$i}",
                'sort_order' => $i,
            ]);
        }

        $book = $this->exported($employee);

        $this->assertSame('LIC-7', $this->cell($book, 'C2', 'L11'));
        $this->assertSame('LIC-8', $this->cell($book, 'C9_Elig cont.', 'J5'));
    }

    public function test_education_uses_one_row_per_level(): void
    {
        $employee = Employee::factory()->create();

        Education::factory()->create([
            'employee_id' => $employee->id,
            'level' => EducationLevel::Elementary->value,
            'school_name' => 'Alegria Elementary School',
        ]);
        Education::factory()->create([
            'employee_id' => $employee->id,
            'level' => EducationLevel::College->value,
            'school_name' => 'Surigao State College',
        ]);

        $book = $this->exported($employee);

        $this->assertSame('Alegria Elementary School', $this->cell($book, 'C1', 'D55'));
        $this->assertSame('Surigao State College', $this->cell($book, 'C1', 'D58'));
        $this->assertNull($this->cell($book, 'C1', 'D56'));
    }

    public function test_a_second_degree_at_the_same_level_goes_to_the_continuation_sheet(): void
    {
        // The printed form has one row per level. A second master's is not
        // dropped and does not overwrite the first.
        $employee = Employee::factory()->create();

        Education::factory()->create([
            'employee_id' => $employee->id,
            'level' => EducationLevel::Graduate->value,
            'school_name' => 'First university',
            'sort_order' => 0,
        ]);
        Education::factory()->create([
            'employee_id' => $employee->id,
            'level' => EducationLevel::Graduate->value,
            'school_name' => 'Second university',
            'sort_order' => 1,
        ]);

        $book = $this->exported($employee);

        $this->assertSame('First university', $this->cell($book, 'C1', 'D59'));
        $this->assertSame('Second university', $this->cell($book, 'C8_Educ. Background cont.', 'D6'));
        $this->assertSame('Graduate Studies', $this->cell($book, 'C8_Educ. Background cont.', 'A6'));
    }

    public function test_the_three_other_information_lists_stay_in_their_own_columns(): void
    {
        $employee = Employee::factory()->create();

        OtherEntry::factory()->create([
            'employee_id' => $employee->id,
            'kind' => OtherEntryKind::SkillOrHobby->value,
            'value' => 'Photography',
        ]);
        OtherEntry::factory()->create([
            'employee_id' => $employee->id,
            'kind' => OtherEntryKind::Distinction->value,
            'value' => 'Employee of the Year',
        ]);
        OtherEntry::factory()->create([
            'employee_id' => $employee->id,
            'kind' => OtherEntryKind::Membership->value,
            'value' => 'Philippine Computer Society',
        ]);

        $book = $this->exported($employee);

        $this->assertSame('Photography', $this->cell($book, 'C3', 'A39'));
        $this->assertSame('Employee of the Year', $this->cell($book, 'C3', 'C39'));
        $this->assertSame('Philippine Computer Society', $this->cell($book, 'C3', 'I39'));
    }

    public function test_each_other_information_list_overflows_on_its_own(): void
    {
        // Seven rows print. A long list of hobbies must not push the first
        // membership onto a continuation sheet.
        $employee = Employee::factory()->create();

        foreach (range(1, 9) as $i) {
            OtherEntry::factory()->create([
                'employee_id' => $employee->id,
                'kind' => OtherEntryKind::SkillOrHobby->value,
                'value' => "Skill {$i}",
                'sort_order' => $i,
            ]);
        }

        OtherEntry::factory()->create([
            'employee_id' => $employee->id,
            'kind' => OtherEntryKind::Membership->value,
            'value' => 'Only membership',
        ]);

        $book = $this->exported($employee);

        $this->assertSame('Skill 7', $this->cell($book, 'C3', 'A45'));
        $this->assertSame('Skill 8', $this->cell($book, 'C11_Other Info cont.', 'A4'));
        $this->assertSame('Only membership', $this->cell($book, 'C3', 'I39'));
    }

    public function test_learning_and_development_lands_on_page_three(): void
    {
        $employee = Employee::factory()->create();

        LearningDevelopment::factory()->create([
            'employee_id' => $employee->id,
            'title' => 'Basic Life Support Training',
            'number_of_hours' => 24,
        ]);

        $book = $this->exported($employee);

        $this->assertSame('Basic Life Support Training', $this->cell($book, 'C3', 'A5'));
        $this->assertSame('24', $this->cell($book, 'C3', 'G5'));
    }

    public function test_voluntary_work_lands_on_page_three(): void
    {
        $employee = Employee::factory()->create();

        VoluntaryWork::factory()->create([
            'employee_id' => $employee->id,
            'organization_name_address' => 'Philippine Red Cross, Surigao City',
        ]);

        $this->assertSame(
            'Philippine Red Cross, Surigao City',
            $this->cell($this->exported($employee), 'C3', 'A27')
        );
    }
}
