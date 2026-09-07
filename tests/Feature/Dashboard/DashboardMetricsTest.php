<?php

namespace Tests\Feature\Dashboard;

use App\Enums\EmploymentStatus;
use App\Enums\LeaveStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\Section;
use App\Models\User;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\Leave\LeaveLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function metrics(): DashboardMetrics
    {
        return app(DashboardMetrics::class);
    }

    public function test_it_counts_a_division_through_the_section_when_the_employee_has_no_division(): void
    {
        // The real import sets section_id and leaves division_id null: the
        // section is the assignment and the division is where the section
        // sits. Counting division_id alone would report an empty hospital.
        $division = Division::factory()->create(['name' => 'Nursing Services']);
        $section = Section::factory()->create(['division_id' => $division->id]);

        Employee::factory()->count(3)->create([
            'section_id' => $section->id,
            'division_id' => null,
        ]);

        $counts = collect($this->metrics()->headcountByDivision())
            ->pluck('count', 'label');

        $this->assertSame(3, $counts['Nursing Services']);
    }

    public function test_it_names_the_employees_who_sit_under_no_division(): void
    {
        // A silent omission would make the bars add up to less than the
        // headcount card beside them, and nothing would say why.
        Employee::factory()->create(['section_id' => null, 'division_id' => null]);

        $counts = collect($this->metrics()->headcountByDivision())
            ->pluck('count', 'label');

        $this->assertSame(1, $counts['Unassigned']);
    }

    public function test_it_leaves_out_inactive_employees(): void
    {
        $division = Division::factory()->create(['name' => 'Finance']);
        $section = Section::factory()->create(['division_id' => $division->id]);

        Employee::factory()->create(['section_id' => $section->id, 'is_active' => true]);
        Employee::factory()->create(['section_id' => $section->id, 'is_active' => false]);

        $counts = collect($this->metrics()->headcountByDivision())->pluck('count', 'label');

        $this->assertSame(1, $counts['Finance']);
        $this->assertSame(1, $this->metrics()->activeEmployees());
    }

    public function test_it_groups_the_workforce_by_employment_status(): void
    {
        Employee::factory()->count(2)->create(['employment_status' => EmploymentStatus::Permanent->value]);
        Employee::factory()->create(['employment_status' => EmploymentStatus::JobOrder->value]);

        $counts = collect($this->metrics()->headcountByStatus())->pluck('count', 'label');

        $this->assertSame(2, $counts['Permanent']);
        $this->assertSame(1, $counts['Job Order']);
    }

    public function test_it_counts_the_employees_whose_ledger_is_empty(): void
    {
        // This is the number that has to reach zero before the leave module is
        // anything but a form: an employee with no credits files leave without
        // pay and nothing warns anybody.
        $withCredits = Employee::factory()->create();
        $withNothing = Employee::factory()->create();

        app(LeaveLedger::class)->open($withCredits, 'vacation', 10);

        $this->assertSame(1, $this->metrics()->withoutLeaveCredits());
        $this->assertNotNull($withNothing);
    }

    public function test_it_counts_the_employees_who_cannot_sign_in(): void
    {
        Employee::factory()->create(['user_id' => User::factory()->create()->id]);
        Employee::factory()->count(2)->create(['user_id' => null]);

        $this->assertSame(2, $this->metrics()->withoutLogin());
    }

    public function test_the_leave_trend_is_a_full_run_of_months_even_where_nothing_was_filed(): void
    {
        // A month with no applications is a month with a zero, not a month the
        // axis skips. Skipping it would compress the gap and draw a rise that
        // did not happen.
        $employee = Employee::factory()->create();
        $type = LeaveType::factory()->create();

        LeaveApplication::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'status' => LeaveStatus::Approved,
            'filed_at' => now()->subMonths(2)->startOfMonth()->addDay(),
        ]);

        $trend = $this->metrics()->leaveTrend(6);

        $this->assertCount(6, $trend);
        $this->assertSame(1, collect($trend)->sum('count'));
        $this->assertSame(now()->format('M'), last($trend)['label']);
    }

    public function test_the_trend_waits_for_something_to_draw(): void
    {
        // An empty chart is not a quiet chart. With nothing filed at all there
        // is no curve to read, and a sentence saying so is the honest thing to
        // put in its place.
        $this->assertFalse($this->metrics()->hasLeaveToShow());

        $employee = Employee::factory()->create();
        $type = LeaveType::factory()->create();

        LeaveApplication::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'filed_at' => now()->subMonths(2),
        ]);

        $this->assertTrue($this->metrics()->hasLeaveToShow());
    }

    public function test_an_application_older_than_the_window_does_not_wake_the_trend(): void
    {
        // It would draw six flat months and claim they were the record.
        $employee = Employee::factory()->create();

        LeaveApplication::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::factory()->create()->id,
            'filed_at' => now()->subYear(),
        ]);

        $this->assertFalse($this->metrics()->hasLeaveToShow());
    }
}
