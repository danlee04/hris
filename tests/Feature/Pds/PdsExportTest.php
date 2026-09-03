<?php

namespace Tests\Feature\Pds;

use App\Enums\CivilStatus;
use App\Enums\Sex;
use App\Models\Employee;
use App\Models\Pds\FamilyBackground;
use App\Models\Pds\PersonalInformation;
use App\Services\Pds\PdsExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class PdsExportTest extends TestCase
{
    use RefreshDatabase;

    private function exportedSheet(Employee $employee, string $name = 'C1'): Worksheet
    {
        $path = app(PdsExporter::class)->export($employee);

        return IOFactory::load($path)->getSheetByName($name);
    }

    public function test_the_name_lands_in_the_right_cells(): void
    {
        // The reason this whole phase has tests. Nothing else can catch a wrong
        // cell reference on a form of 150 fields — the result still looks like
        // a filled PDS.
        $employee = Employee::factory()->create([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'suffix' => 'Jr.',
        ]);

        $sheet = $this->exportedSheet($employee);

        $this->assertSame('Dela Cruz', $sheet->getCell('D10')->getValue());
        $this->assertSame('Juan', $sheet->getCell('D11')->getValue());
        $this->assertSame('Santos', $sheet->getCell('D12')->getValue());
        $this->assertSame('Jr.', $sheet->getCell('N11')->getValue());
    }

    public function test_dates_print_the_way_the_form_asks_for_them(): void
    {
        // Every date field on this form is captioned (dd/mm/yyyy). Written as a
        // spreadsheet date serial it would render in the reader's locale, and
        // 04/12 is a different day in two of them.
        $employee = Employee::factory()->create();

        PersonalInformation::factory()->create([
            'employee_id' => $employee->id,
            'date_of_birth' => '1990-04-12',
        ]);

        $this->assertSame('12/04/1990', $this->exportedSheet($employee)->getCell('D13')->getValue());
    }

    public function test_sex_and_civil_status_tick_a_box_rather_than_writing_words(): void
    {
        // These are Excel form controls linked to a cell holding TRUE or FALSE.
        // Writing "Female" into E16 would replace the control's value and leave
        // the box empty on the printed page.
        $employee = Employee::factory()->create();

        PersonalInformation::factory()->create([
            'employee_id' => $employee->id,
            'sex' => Sex::Female->value,
            'civil_status' => CivilStatus::Married->value,
        ]);

        $sheet = $this->exportedSheet($employee);

        $this->assertTrue($sheet->getCell('E16')->getValue(), 'Female should be ticked');
        $this->assertFalse($sheet->getCell('D16')->getValue(), 'Male should stay unticked');
        $this->assertTrue($sheet->getCell('E17')->getValue(), 'Married should be ticked');
        $this->assertFalse($sheet->getCell('D17')->getValue(), 'Single should stay unticked');
    }

    public function test_a_solo_parent_ticks_other_and_writes_the_word(): void
    {
        // The printed form offers Single, Married, Widowed, Separated and
        // Other/s. There is no Solo Parent box.
        $employee = Employee::factory()->create();

        PersonalInformation::factory()->create([
            'employee_id' => $employee->id,
            'civil_status' => CivilStatus::SoloParent->value,
        ]);

        $sheet = $this->exportedSheet($employee);

        $this->assertTrue($sheet->getCell('D20')->getValue(), 'Other/s should be ticked');
        $this->assertSame('Solo Parent', $sheet->getCell('E20')->getValue());
    }

    public function test_citizenship_ticks_filipino_or_dual(): void
    {
        $employee = Employee::factory()->create();

        PersonalInformation::factory()->create([
            'employee_id' => $employee->id,
            'citizenship' => 'Dual Citizenship',
            'dual_citizenship_country' => 'Canada',
        ]);

        $sheet = $this->exportedSheet($employee);

        $this->assertTrue($sheet->getCell('K13')->getValue(), 'Dual should be ticked');
        $this->assertFalse($sheet->getCell('J13')->getValue(), 'Filipino should stay unticked');
        $this->assertSame('Canada', $sheet->getCell('L16')->getValue());
    }

    public function test_an_unanswered_question_leaves_every_box_unticked(): void
    {
        // Unanswered and "no" are different things, and a blank PDS must not
        // come back with Male and Single already ticked.
        $employee = Employee::factory()->create();

        PersonalInformation::create(['employee_id' => $employee->id]);

        $sheet = $this->exportedSheet($employee);

        foreach (['D16', 'E16', 'D17', 'E17', 'D18', 'E19', 'D20', 'J13', 'K13'] as $box) {
            $this->assertFalse($sheet->getCell($box)->getValue(), "[{$box}] should be unticked");
        }
    }

    public function test_the_address_fields_do_not_drift_by_a_row(): void
    {
        // The captions on this form sit below their boxes. Reading a caption
        // and writing beside it puts every address field one row out, and the
        // form still looks complete.
        $employee = Employee::factory()->create();

        PersonalInformation::factory()->create([
            'employee_id' => $employee->id,
            'res_house_no' => '12',
            'res_street' => 'Rizal Street',
            'res_subdivision' => 'Villa Alegre',
            'res_barangay' => 'Washington',
            'res_city' => 'Surigao City',
            'res_province' => 'Surigao del Norte',
            'res_zip_code' => '8400',
        ]);

        $sheet = $this->exportedSheet($employee);

        $this->assertSame('12', $sheet->getCell('I17')->getValue());
        $this->assertSame('Rizal Street', $sheet->getCell('L17')->getValue());
        $this->assertSame('Villa Alegre', $sheet->getCell('I19')->getValue());
        $this->assertSame('Washington', $sheet->getCell('L19')->getValue());
        $this->assertSame('Surigao City', $sheet->getCell('I22')->getValue());
        $this->assertSame('Surigao del Norte', $sheet->getCell('L22')->getValue());
        $this->assertSame('8400', $sheet->getCell('I24')->getValue());
    }

    public function test_the_identification_numbers_land_in_their_own_cells(): void
    {
        $employee = Employee::factory()->create();

        PersonalInformation::factory()->create([
            'employee_id' => $employee->id,
            'umid_id' => 'UMID-0001',
            'pagibig_id' => 'PAGIBIG-0002',
            'philhealth_no' => 'PH-0003',
            'philsys_id' => 'PCN-0004',
            'tin_no' => 'TIN-0005',
            'agency_employee_no' => 'EMP-0006',
        ]);

        $sheet = $this->exportedSheet($employee);

        $this->assertSame('UMID-0001', $sheet->getCell('D27')->getValue());
        $this->assertSame('PAGIBIG-0002', $sheet->getCell('D29')->getValue());
        $this->assertSame('PH-0003', $sheet->getCell('D31')->getValue());
        $this->assertSame('PCN-0004', $sheet->getCell('D32')->getValue());
        $this->assertSame('TIN-0005', $sheet->getCell('D33')->getValue());
        $this->assertSame('EMP-0006', $sheet->getCell('D34')->getValue());
    }

    public function test_the_family_background_lands_on_page_one(): void
    {
        $employee = Employee::factory()->create();

        FamilyBackground::factory()->create([
            'employee_id' => $employee->id,
            'spouse_surname' => 'Reyes',
            'spouse_first_name' => 'Ana',
            'father_surname' => 'Madelo',
            'mother_surname' => 'Espina',
        ]);

        $sheet = $this->exportedSheet($employee);

        $this->assertSame('Reyes', $sheet->getCell('D36')->getValue());
        $this->assertSame('Ana', $sheet->getCell('D37')->getValue());
        $this->assertSame('Madelo', $sheet->getCell('D44')->getValue());
        $this->assertSame('Espina', $sheet->getCell('D48')->getValue());
    }

    public function test_an_employee_with_no_pds_still_exports(): void
    {
        // HR asks for a blank form with the name filled in. This must not throw.
        $employee = Employee::factory()->create(['last_name' => 'Bautista']);

        $sheet = $this->exportedSheet($employee);

        $this->assertSame('Bautista', $sheet->getCell('D10')->getValue());
        $this->assertNull($sheet->getCell('D13')->getValue());
    }

    public function test_the_template_itself_is_never_written_to(): void
    {
        // PhpSpreadsheet loads into memory, but a save that ever pointed at the
        // source would destroy the one file this phase cannot regenerate.
        $template = config('pds_template.path');
        $before = md5_file($template);

        app(PdsExporter::class)->export(Employee::factory()->create());

        $this->assertSame($before, md5_file($template));
    }

    public function test_the_filename_carries_the_name_and_the_date(): void
    {
        $employee = Employee::factory()->create([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
        ]);

        $this->assertSame(
            'PDS_DELA_CRUZ_JUAN_'.now()->format('Y-m-d').'.xlsx',
            app(PdsExporter::class)->filename($employee)
        );
    }
}
