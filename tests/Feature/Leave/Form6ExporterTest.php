<?php

namespace Tests\Feature\Leave;

use App\Enums\ApprovalAction;
use App\Enums\EmploymentStatus;
use App\Enums\LeaveStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Section;
use App\Models\User;
use App\Services\Leave\Form6Exporter;
use App\Services\Leave\LeaveDecision;
use App\Services\Leave\LeaveFiler;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class Form6ExporterTest extends TestCase
{
    use RefreshDatabase;

    private Employee $applicant;

    private Employee $divisionHead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);

        $division = Division::factory()->create(['name' => 'Medical Division']);
        $section = Section::factory()->create(['division_id' => $division->id, 'name' => 'Nursing Unit']);

        $this->divisionHead = Employee::factory()->create([
            'last_name' => 'Delos Santos',
            'first_name' => 'Maria',
        ]);

        $division->update(['division_head_employee_id' => $this->divisionHead->id]);
        $section->update(['section_head_employee_id' => Employee::factory()->create()->id]);
        Employee::factory()->create(['is_chief_of_hospital' => true]);

        $this->applicant = Employee::factory()->create([
            'section_id' => $section->id,
            'last_name' => 'Guico',
            'first_name' => 'Ana',
            'position_id' => Position::factory()->create(['title' => 'Nurse II', 'salary_grade' => 16])->id,
        ]);

        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);
        app(LeaveLedger::class)->open($this->applicant, 'sick', 4);
    }

    /** @param  array<string, mixed>  $overrides */
    private function file(array $overrides = []): LeaveApplication
    {
        return app(LeaveFiler::class)->file($this->applicant, array_merge([
            'leave_type_id' => LeaveType::where('code', 'VL')->sole()->id,
            'date_from' => '2026-10-05',
            'date_to' => '2026-10-06',
            'days' => 2,
            'details' => null,
            'commutation' => 'not_requested',
        ], $overrides));
    }

    private function sheet(LeaveApplication $application): Worksheet
    {
        // Held in a variable: a worksheet whose parent has been collected
        // returns null for every cell, which reads like a damaged file.
        $book = IOFactory::load(app(Form6Exporter::class)->export($application));

        return $book->getSheetByName('CS Form No. 6, Rev 2020 1 of 2');
    }

    public function test_the_identity_block_is_filled(): void
    {
        $sheet = $this->sheet($this->file());

        $this->assertSame('Nursing Unit', $sheet->getCell('B9')->getValue());
        $this->assertStringContainsString('Guico', (string) $sheet->getCell('E9')->getValue());
        $this->assertStringContainsString('Nurse II', (string) $sheet->getCell('E10')->getValue());
    }

    public function test_the_caption_survives_the_value_written_into_it(): void
    {
        // Seven fields have no cell of their own, so the caption is overwritten
        // whole. Losing the caption would leave a bare date on the form.
        $sheet = $this->sheet($this->file());

        $this->assertStringContainsString('DATE OF FILING', (string) $sheet->getCell('A10')->getValue());
        $this->assertStringContainsString(now()->format('d/m/Y'), (string) $sheet->getCell('A10')->getValue());
    }

    public function test_the_salary_prints_as_a_grade_not_as_a_figure(): void
    {
        // The system holds a salary grade, not a peso figure, and "16" in a
        // field labelled SALARY reads as sixteen pesos.
        $sheet = $this->sheet($this->file());

        $this->assertStringContainsString('SG 16', (string) $sheet->getCell('J10')->getValue());
    }

    public function test_the_type_of_leave_is_ticked_and_nothing_else_is(): void
    {
        $sheet = $this->sheet($this->file());

        $this->assertTrue($sheet->getCell('R15')->getValue());   // Vacation
        $this->assertFalse($sheet->getCell('R19')->getValue());  // Sick
        $this->assertFalse($sheet->getCell('R21')->getValue());  // Maternity
    }

    public function test_wellness_leave_prints_on_the_others_line(): void
    {
        // It has no box on this form. Leaving it unticked and unnamed would
        // print a leave application that does not say what leave it is for.
        $jobOrder = Employee::factory()->create([
            'employment_status' => EmploymentStatus::JobOrder->value,
            'section_id' => $this->applicant->section_id,
        ]);

        $application = app(LeaveFiler::class)->file($jobOrder, [
            'leave_type_id' => LeaveType::where('code', 'WELLNESS')->sole()->id,
            'date_from' => now()->addWeeks(2)->toDateString(),
            'date_to' => now()->addWeeks(2)->addDay()->toDateString(),
            'days' => 2,
            'details' => null,
            'commutation' => 'not_requested',
        ]);

        $sheet = $this->sheet($application);

        $this->assertStringContainsString('Wellness', (string) $sheet->getCell('B45')->getValue());
    }

    public function test_the_leftover_sample_values_are_written_over(): void
    {
        // The template ships holding a 1 in C49 and a date serial in C52.
        $sheet = $this->sheet($this->file(['days' => 3]));

        $this->assertStringContainsString('3', (string) $sheet->getCell('C49')->getValue());
        $this->assertStringContainsString('05/10/2026', (string) $sheet->getCell('C52')->getValue());
        $this->assertStringNotContainsString('46210', (string) $sheet->getCell('C52')->getValue());
    }

    public function test_the_certification_grid_holds_the_balances_at_filing(): void
    {
        $sheet = $this->sheet($this->file());

        // Ten earned, two on this application, eight left.
        $this->assertSame('10.00', (string) $sheet->getCell('D60')->getValue());
        $this->assertSame('2.00', (string) $sheet->getCell('D61')->getValue());
        $this->assertSame('8.00', (string) $sheet->getCell('D62')->getValue());

        // Sick is untouched by a vacation application.
        $this->assertSame('4.00', (string) $sheet->getCell('E60')->getValue());
        $this->assertSame('0.00', (string) $sheet->getCell('E61')->getValue());
        $this->assertSame('4.00', (string) $sheet->getCell('E62')->getValue());
    }

    public function test_the_paid_and_unpaid_split_reaches_item_7c(): void
    {
        // Twelve days against ten credits.
        $sheet = $this->sheet($this->file(['days' => 12, 'date_to' => '2026-10-16']));

        $this->assertStringContainsString('10.00', (string) $sheet->getCell('C66')->getValue());
        $this->assertStringContainsString('days with pay', (string) $sheet->getCell('C66')->getValue());
        $this->assertStringContainsString('2.00', (string) $sheet->getCell('C67')->getValue());
    }

    public function test_the_commutation_is_ticked(): void
    {
        $sheet = $this->sheet($this->file(['commutation' => 'requested']));

        $this->assertTrue($sheet->getCell('T51')->getValue());
        $this->assertFalse($sheet->getCell('T49')->getValue());
    }

    public function test_the_sick_leave_detail_is_ticked_and_named(): void
    {
        $sheet = $this->sheet($this->file([
            'leave_type_id' => LeaveType::where('code', 'SL')->sole()->id,
            'details' => ['sick_where' => 'out_patient', 'sick_detail' => 'Dengue'],
        ]));

        $this->assertTrue($sheet->getCell('T25')->getValue());
        $this->assertFalse($sheet->getCell('T23')->getValue());
        $this->assertStringContainsString('Dengue', (string) $sheet->getCell('I27')->getValue());
    }

    public function test_a_vacation_destination_lands_beside_the_box_it_belongs_to(): void
    {
        // The vacation block has no blank line of its own, so the text goes
        // into the caption beside the box that was ticked. Writing it under the
        // sick-leave question would print a destination as an illness.
        $sheet = $this->sheet($this->file([
            'details' => ['vacation_where' => 'abroad', 'vacation_detail' => 'Singapore'],
        ]));

        $this->assertStringContainsString('Singapore', (string) $sheet->getCell('I19')->getValue());
        $this->assertStringContainsString('Abroad', (string) $sheet->getCell('I19')->getValue());
        $this->assertStringNotContainsString('Singapore', (string) $sheet->getCell('I27')->getValue());
    }

    public function test_the_recorded_hr_officer_is_named_not_the_account_that_acted(): void
    {
        // The HR account can be shared, a stand-in, an administrator covering a
        // vacancy. The name printed under "Human Resource Development Officer"
        // must not change because of any of that.
        $officer = Employee::factory()->create([
            'is_hr_officer' => true,
            'last_name' => 'Lao Guico',
            'first_name' => 'Mary Jane',
        ]);

        $application = $this->file();

        $this->actingAs(User::factory()->create(['name' => 'Human Resource']));

        while ($approval = $application->fresh()->currentApproval()) {
            $application = app(LeaveDecision::class)->act(
                $application->fresh(), $approval, ApprovalAction::Approve, null
            );
        }

        $sheet = $this->sheet($application);

        $this->assertStringContainsString('Lao Guico', (string) $sheet->getCell('C63')->getValue());
        $this->assertStringNotContainsString('Human Resource', (string) $sheet->getCell('C63')->getValue());
        $this->assertSame($officer->id, $officer->fresh()->id);
    }

    public function test_with_no_officer_recorded_the_account_that_acted_is_named(): void
    {
        // Something is better than a blank line above a title, and it is still
        // the truth about who acted.
        $application = $this->file();

        $this->actingAs(User::factory()->create(['name' => 'Ruth Cuizon']));

        while ($approval = $application->fresh()->currentApproval()) {
            $application = app(LeaveDecision::class)->act(
                $application->fresh(), $approval, ApprovalAction::Approve, null
            );
        }

        $this->assertStringContainsString('Ruth Cuizon', (string) $this->sheet($application)->getCell('C63')->getValue());
    }

    public function test_a_pending_application_names_nobody_and_ticks_no_recommendation(): void
    {
        // Nobody has signed. Printing a name would put words in their mouth.
        $sheet = $this->sheet($this->file());

        $this->assertNull($sheet->getCell('C63')->getValue());
        $this->assertNull($sheet->getCell('I63')->getValue());
        $this->assertFalse($sheet->getCell('T57')->getValue());
    }

    public function test_an_approved_application_names_who_signed(): void
    {
        $application = $this->file();

        $this->actingAs(User::factory()->create(['name' => 'Ruth Cuizon']));

        while ($approval = $application->fresh()->currentApproval()) {
            $application = app(LeaveDecision::class)->act(
                $application->fresh(), $approval, ApprovalAction::Approve, null
            );
        }

        $sheet = $this->sheet($application);

        $this->assertSame(LeaveStatus::Approved, $application->status);
        $this->assertStringContainsString('Ruth Cuizon', (string) $sheet->getCell('C63')->getValue());
        $this->assertStringContainsString('Delos Santos', (string) $sheet->getCell('I63')->getValue());
        $this->assertTrue($sheet->getCell('T57')->getValue());
    }

    public function test_a_disapproved_application_ticks_disapproval_and_prints_the_reason(): void
    {
        $application = $this->file();

        $this->actingAs(User::factory()->create());

        $application = app(LeaveDecision::class)->act(
            $application,
            $application->currentApproval(),
            ApprovalAction::Disapprove,
            'Needed on duty'
        );

        $sheet = $this->sheet($application);

        $this->assertTrue($sheet->getCell('T59')->getValue());
        $this->assertStringContainsString('Needed on duty', (string) $sheet->getCell('I60')->getValue());
    }

    public function test_the_chief_who_approved_is_named_on_the_signature_line(): void
    {
        // The Chief is who approves it. A form that does not say who approved
        // it is a form the next office sends back.
        $chief = Employee::where('is_chief_of_hospital', true)->sole();
        $chief->update(['last_name' => 'Bautista', 'first_name' => 'Jose']);

        $application = $this->file();

        $this->actingAs(User::factory()->create());

        while ($approval = $application->fresh()->currentApproval()) {
            $application = app(LeaveDecision::class)->act(
                $application->fresh(), $approval, ApprovalAction::Approve, null
            );
        }

        $sheet = $this->sheet($application);

        $this->assertStringContainsString('Bautista', (string) $sheet->getCell('A69')->getValue());
        // The title under the line stays: it is what the signature means.
        $this->assertStringContainsString('Chief of Hospital', (string) $sheet->getCell('A69')->getValue());
    }

    public function test_an_unapproved_form_leaves_the_chiefs_line_empty(): void
    {
        // Printing a name beside an unsigned line says somebody approved it.
        $sheet = $this->sheet($this->file());

        $this->assertStringNotContainsString('Bautista', (string) $sheet->getCell('A69')->getValue());
        $this->assertStringContainsString('_____', (string) $sheet->getCell('A69')->getValue());
    }

    public function test_the_applicants_signature_line_is_left_blank(): void
    {
        // Nobody signs for the applicant, and the system did not watch them
        // sign. That line is theirs to fill with a pen.
        $sheet = $this->sheet($this->file());

        $this->assertStringNotContainsString('Guico', (string) $sheet->getCell('G52')->getValue());
    }

    public function test_the_template_is_never_written_over(): void
    {
        $before = md5_file(config('form6_template.path'));

        app(Form6Exporter::class)->export($this->file());

        $this->assertSame($before, md5_file(config('form6_template.path')));
    }

    public function test_the_filename_names_the_person_and_the_dates(): void
    {
        $name = app(Form6Exporter::class)->filename($this->file());

        $this->assertStringContainsString('GUICO', $name);
        $this->assertStringEndsWith('.xlsx', $name);
    }
}
