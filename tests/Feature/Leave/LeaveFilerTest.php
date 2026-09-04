<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Enums\LeaveLedgerKind;
use App\Enums\LeaveStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\Section;
use App\Services\Leave\LeaveFiler;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveFilerTest extends TestCase
{
    use RefreshDatabase;

    private Employee $applicant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);

        $division = Division::factory()->create();
        $section = Section::factory()->create(['division_id' => $division->id]);

        $division->update(['division_head_employee_id' => Employee::factory()->create()->id]);
        $section->update(['section_head_employee_id' => Employee::factory()->create()->id]);
        Employee::factory()->create(['is_chief_of_hospital' => true]);

        $this->applicant = Employee::factory()->create(['section_id' => $section->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'leave_type_id' => LeaveType::where('code', 'VL')->sole()->id,
            'date_from' => now()->addWeek()->toDateString(),
            'date_to' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'details' => null,
            'commutation' => 'not_requested',
        ], $overrides);
    }

    public function test_filing_writes_the_application_its_approvals_and_its_hold(): void
    {
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes());

        $this->assertSame(LeaveStatus::Pending, $application->status);
        $this->assertCount(4, $application->approvals);
        $this->assertSame(2.0, $application->days_with_pay);
        $this->assertSame(0.0, $application->days_without_pay);

        // The hold is what stops the next application seeing ten.
        $this->assertSame(8.0, app(LeaveLedger::class)->balance($this->applicant, 'vacation'));
    }

    public function test_the_hold_names_the_application_that_caused_it(): void
    {
        // The ledger is the answer to "where did my credits go". "A hold" is
        // not an answer.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes());

        $hold = LeaveLedgerEntry::where('kind', LeaveLedgerKind::Hold)->sole();

        $this->assertSame($application->id, $hold->leave_application_id);
        $this->assertSame(-2.0, $hold->days);
    }

    public function test_a_second_application_is_measured_against_what_is_left(): void
    {
        // Ten credits, three applications of eight. Without holds all three are
        // measured against ten, all three print as fully paid, and the hospital
        // pays 24 days out of a 10-day balance.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $first = app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 8]));
        $second = app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 8]));
        $third = app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 8]));

        $this->assertSame(8.0, $first->days_with_pay);
        $this->assertSame(0.0, $first->days_without_pay);

        $this->assertSame(2.0, $second->days_with_pay);
        $this->assertSame(6.0, $second->days_without_pay);

        $this->assertSame(0.0, $third->days_with_pay);
        $this->assertSame(8.0, $third->days_without_pay);
    }

    public function test_insufficient_credits_never_refuse_a_filing(): void
    {
        // Refusing would push leave without pay out of the system, which is the
        // one place it must not be, because it is the part that changes pay.
        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 3]));

        $this->assertSame(LeaveStatus::Pending, $application->status);
        $this->assertSame(3.0, $application->days_without_pay);
    }

    public function test_a_type_with_no_ledger_holds_nothing_and_is_fully_paid(): void
    {
        // Maternity leave is a right, not a balance.
        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'leave_type_id' => LeaveType::where('code', 'ML')->sole()->id,
            'days' => 105,
            'date_to' => now()->addWeek()->addDays(104)->toDateString(),
        ]));

        $this->assertSame(105.0, $application->days_with_pay);
        $this->assertSame(0.0, $application->days_without_pay);
        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_a_type_this_employment_status_cannot_file_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'leave_type_id' => LeaveType::where('code', 'WELLNESS')->sole()->id,
        ]));
    }

    public function test_too_little_notice_is_refused(): void
    {
        // Wellness Leave needs five days.
        $jobOrder = Employee::factory()->create([
            'employment_status' => EmploymentStatus::JobOrder->value,
            'section_id' => $this->applicant->section_id,
        ]);

        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($jobOrder, $this->attributes([
            'leave_type_id' => LeaveType::where('code', 'WELLNESS')->sole()->id,
            'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
            'days' => 1,
        ]));
    }

    public function test_more_than_the_maximum_consecutive_days_is_refused(): void
    {
        $jobOrder = Employee::factory()->create([
            'employment_status' => EmploymentStatus::JobOrder->value,
            'section_id' => $this->applicant->section_id,
        ]);

        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($jobOrder, $this->attributes([
            'leave_type_id' => LeaveType::where('code', 'WELLNESS')->sole()->id,
            'date_from' => now()->addWeeks(2)->toDateString(),
            'date_to' => now()->addWeeks(2)->addDays(5)->toDateString(),
            'days' => 4,
        ]));
    }

    public function test_a_leave_type_that_does_not_exist_is_refused_as_validation(): void
    {
        // findOrFail here surfaced as a 404 page. A leave type id arrives from
        // the browser like everything else, and a wrong one is a bad answer to
        // a question, not a missing page.
        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'leave_type_id' => 99999,
        ]));
    }

    public function test_no_leave_type_at_all_is_refused_as_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'leave_type_id' => null,
        ]));
    }

    public function test_a_date_range_that_runs_backwards_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'date_from' => now()->addWeek()->addDays(3)->toDateString(),
            'date_to' => now()->addWeek()->toDateString(),
        ]));
    }

    public function test_zero_days_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 0]));
    }

    public function test_a_missing_section_head_refuses_the_filing_and_writes_nothing(): void
    {
        // The route refuses, and the transaction takes the application with it.
        Section::where('id', $this->applicant->section_id)->update(['section_head_employee_id' => null]);

        try {
            app(LeaveFiler::class)->file($this->applicant, $this->attributes());
            $this->fail('The filing should have been refused.');
        } catch (ValidationException) {
            //
        }

        $this->assertDatabaseCount('leave_applications', 0);
        $this->assertDatabaseCount('leave_ledger_entries', 0);
    }

    public function test_refiling_a_returned_application_replaces_its_approvals_and_hold(): void
    {
        // A corrected application is a different one: the dates may have moved,
        // and a recommendation given for one set of dates does not carry.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes(['days' => 2]));
        $application->update(['status' => LeaveStatus::Returned]);
        app(LeaveLedger::class)->releaseFor($application);

        $refiled = app(LeaveFiler::class)->refile($application, $this->attributes(['days' => 4]));

        $this->assertSame(LeaveStatus::Pending, $refiled->status);
        $this->assertCount(4, $refiled->approvals()->get());
        $this->assertSame(6.0, app(LeaveLedger::class)->balance($this->applicant, 'vacation'));
    }

    public function test_refiling_does_not_leave_the_old_approvals_behind(): void
    {
        // Four stale rows plus four fresh ones would make the chain eight long,
        // and the first unsigned step would be one nobody is waiting on.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes());
        $application->update(['status' => LeaveStatus::Returned]);
        app(LeaveLedger::class)->releaseFor($application);

        app(LeaveFiler::class)->refile($application, $this->attributes());

        $this->assertDatabaseCount('leave_approvals', 4);
    }

    public function test_the_details_are_kept_as_given(): void
    {
        // Item 6.B asks a different question of each type. A single free-text
        // box could not fill the boxes the form actually prints.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'details' => ['vacation_where' => 'within_philippines', 'vacation_detail' => 'Surigao City'],
        ]));

        $this->assertSame('within_philippines', $application->details['vacation_where']);
        $this->assertSame('Surigao City', $application->details['vacation_detail']);
    }

    public function test_details_that_are_not_on_the_form_are_dropped(): void
    {
        // The array comes from the browser. Anything can be in it, and it is
        // written to a column that ends up on a signed document.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'details' => ['vacation_where' => 'abroad', 'injected' => 'anything'],
        ]));

        $this->assertArrayNotHasKey('injected', $application->details);
        $this->assertSame('abroad', $application->details['vacation_where']);
    }

    public function test_empty_details_are_stored_as_null_not_as_an_empty_array(): void
    {
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'details' => ['vacation_where' => '', 'sick_detail' => '   '],
        ]));

        $this->assertNull($application->details);
    }

    public function test_the_details_survive_a_refiling(): void
    {
        // A returned application is corrected, not retyped from nothing.
        app(LeaveLedger::class)->open($this->applicant, 'vacation', 10);

        $application = app(LeaveFiler::class)->file($this->applicant, $this->attributes([
            'details' => ['vacation_where' => 'abroad', 'vacation_detail' => 'Singapore'],
        ]));

        $application->update(['status' => LeaveStatus::Returned]);
        app(LeaveLedger::class)->releaseFor($application);

        $refiled = app(LeaveFiler::class)->refile($application, $this->attributes([
            'details' => ['vacation_where' => 'abroad', 'vacation_detail' => 'Singapore'],
        ]));

        $this->assertSame('Singapore', $refiled->details['vacation_detail']);
    }
}
