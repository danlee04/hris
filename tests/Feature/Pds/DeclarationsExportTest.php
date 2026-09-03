<?php

namespace Tests\Feature\Pds;

use App\Models\Employee;
use App\Models\Pds\Declaration;
use App\Models\Pds\Reference;
use App\Services\Pds\PdsExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class DeclarationsExportTest extends TestCase
{
    use RefreshDatabase;

    private function page4(Employee $employee): Worksheet
    {
        return IOFactory::load(app(PdsExporter::class)->export($employee))->getSheetByName('C4');
    }

    public function test_a_yes_ticks_yes_and_leaves_no_alone(): void
    {
        $employee = Employee::factory()->create();

        Declaration::factory()->create([
            'employee_id' => $employee->id,
            'q36_convicted' => true,
            'q36_details' => 'Traffic violation, 2015',
        ]);

        $sheet = $this->page4($employee);

        $this->assertTrue($sheet->getCell('H23')->getValue());
        $this->assertFalse($sheet->getCell('J23')->getValue());
        $this->assertSame('Traffic violation, 2015', $sheet->getCell('I25')->getValue());
    }

    public function test_a_no_ticks_no(): void
    {
        $employee = Employee::factory()->create();

        Declaration::factory()->create([
            'employee_id' => $employee->id,
            'q36_convicted' => false,
        ]);

        $sheet = $this->page4($employee);

        $this->assertFalse($sheet->getCell('H23')->getValue());
        $this->assertTrue($sheet->getCell('J23')->getValue());
    }

    public function test_an_unanswered_question_ticks_neither_box(): void
    {
        // Unanswered and "no" are different things on a form signed under
        // penalty of perjury. Turning the first into the second would put
        // words in the employee's mouth.
        $employee = Employee::factory()->create();

        Declaration::create([
            'employee_id' => $employee->id,
            'q36_convicted' => null,
        ]);

        $sheet = $this->page4($employee);

        $this->assertFalse($sheet->getCell('H23')->getValue());
        $this->assertFalse($sheet->getCell('J23')->getValue());
    }

    public function test_every_question_reaches_its_own_pair_of_boxes(): void
    {
        // Twelve questions, twelve pairs. A pair copied from the row above
        // would tick the wrong question and nothing else would show it.
        $employee = Employee::factory()->create();

        $answers = [];
        foreach (array_keys(config('pds_template.declarations.questions')) as $i => $field) {
            $answers[$field] = $i % 2 === 0;
        }

        Declaration::create(['employee_id' => $employee->id] + $answers);

        $sheet = $this->page4($employee);

        foreach (config('pds_template.declarations.questions') as $field => $boxes) {
            $expected = $answers[$field];

            $this->assertSame($expected, $sheet->getCell($boxes['yes'])->getValue(), "{$field} yes box");
            $this->assertSame(! $expected, $sheet->getCell($boxes['no'])->getValue(), "{$field} no box");
        }
    }

    public function test_the_criminal_case_details_land_beside_their_question(): void
    {
        $employee = Employee::factory()->create();

        Declaration::factory()->create([
            'employee_id' => $employee->id,
            'q35_criminally_charged' => true,
            'q35_criminal_details' => 'Dismissed for lack of merit',
            'q35_date_filed' => '2015-06-01',
            'q35_case_status' => 'Dismissed',
        ]);

        $sheet = $this->page4($employee);

        $this->assertSame('Dismissed for lack of merit', $sheet->getCell('L19')->getValue());
        $this->assertSame('01/06/2015', $sheet->getCell('L20')->getValue());
        $this->assertSame('Dismissed', $sheet->getCell('L21')->getValue());
    }

    public function test_the_government_id_lands_on_page_four(): void
    {
        $employee = Employee::factory()->create();

        Declaration::factory()->create([
            'employee_id' => $employee->id,
            'government_id_type' => 'Passport',
            'government_id_number' => 'P1234567A',
            'government_id_issued' => '2022-01-04, Manila',
            'date_accomplished' => '2026-09-03',
        ]);

        $sheet = $this->page4($employee);

        $this->assertSame('Passport', $sheet->getCell('D61')->getValue());
        $this->assertSame('P1234567A', $sheet->getCell('D62')->getValue());
        $this->assertSame('03/09/2026', $sheet->getCell('G64')->getValue());
    }

    public function test_three_references_print_and_a_fourth_does_not(): void
    {
        // The form gives three rows and no continuation sheet for them.
        $employee = Employee::factory()->create();

        foreach (range(1, 4) as $i) {
            Reference::factory()->create([
                'employee_id' => $employee->id,
                'name' => "Reference {$i}",
                'sort_order' => $i,
            ]);
        }

        $sheet = $this->page4($employee);

        $this->assertSame('Reference 1', $sheet->getCell('A52')->getValue());
        $this->assertSame('Reference 3', $sheet->getCell('A54')->getValue());

        // Row 55 is where item 42 begins, so "not printed" cannot be asserted
        // by looking at one cell — the fourth must be absent from the page.
        $printed = [];
        foreach ($sheet->getRowIterator(50, 60) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $printed[] = (string) $cell->getValue();
            }
        }

        $this->assertNotContains('Reference 4', $printed);
    }
}
