<?php

namespace Tests\Feature\Leave;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Section;
use App\Services\Leave\LeaveRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveRouteTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private Section $section;

    private Employee $divisionHead;

    private Employee $sectionHead;

    private Employee $chief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->division = Division::factory()->create();
        $this->section = Section::factory()->create(['division_id' => $this->division->id]);

        $this->chief = Employee::factory()->create([
            'is_chief_of_hospital' => true,
            'section_id' => null,
            'division_id' => null,
        ]);

        $this->divisionHead = Employee::factory()->create([
            'section_id' => null,
            'division_id' => $this->division->id,
        ]);

        $this->sectionHead = Employee::factory()->create(['section_id' => $this->section->id]);

        $this->division->update(['division_head_employee_id' => $this->divisionHead->id]);
        $this->section->update(['section_head_employee_id' => $this->sectionHead->id]);
    }

    /** @return list<string> */
    private function stepsFor(Employee $applicant): array
    {
        return array_map(
            fn ($step) => $step->step->value,
            app(LeaveRoute::class)->for($applicant)
        );
    }

    public function test_staff_go_through_all_four(): void
    {
        $staff = Employee::factory()->create(['section_id' => $this->section->id]);

        $this->assertSame(
            ['section_head', 'hr', 'division_head', 'chief'],
            $this->stepsFor($staff)
        );
    }

    public function test_a_section_head_does_not_recommend_their_own_leave(): void
    {
        $this->assertSame(
            ['hr', 'division_head', 'chief'],
            $this->stepsFor($this->sectionHead)
        );
    }

    public function test_a_division_head_skips_both_steps_below_them(): void
    {
        // A division head sits in no section, so there is no section head step
        // to remove — and they cannot recommend their own leave.
        $this->assertSame(['hr', 'chief'], $this->stepsFor($this->divisionHead));
    }

    public function test_the_chief_is_left_with_hr(): void
    {
        $this->assertSame(['hr'], $this->stepsFor($this->chief));
    }

    public function test_the_named_approvers_are_the_ones_from_the_chart(): void
    {
        $staff = Employee::factory()->create(['section_id' => $this->section->id]);

        $route = app(LeaveRoute::class)->for($staff);

        $this->assertSame($this->sectionHead->id, $route[0]->approver->id);
        // HR is an office, not a person: whoever holds leave.manage acts.
        $this->assertNull($route[1]->approver);
        $this->assertSame($this->divisionHead->id, $route[2]->approver->id);
        $this->assertSame($this->chief->id, $route[3]->approver->id);
    }

    public function test_a_section_with_no_head_refuses_the_filing_by_name(): void
    {
        // Skipping a step nobody filled would produce an application that
        // reached the Chief without a recommendation and looked complete on the
        // way. On a signed document that is worse than a refusal.
        $this->section->update(['section_head_employee_id' => null]);

        $staff = Employee::factory()->create(['section_id' => $this->section->id]);

        try {
            app(LeaveRoute::class)->for($staff);
            $this->fail('The route should have been refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($this->section->name, $e->validator->errors()->first());
        }
    }

    public function test_a_division_with_no_head_refuses_the_filing_by_name(): void
    {
        $this->division->update(['division_head_employee_id' => null]);

        $staff = Employee::factory()->create(['section_id' => $this->section->id]);

        $this->expectException(ValidationException::class);

        app(LeaveRoute::class)->for($staff);
    }

    public function test_no_chief_on_record_refuses_the_filing(): void
    {
        $this->chief->update(['is_chief_of_hospital' => false]);

        $staff = Employee::factory()->create(['section_id' => $this->section->id]);

        $this->expectException(ValidationException::class);

        app(LeaveRoute::class)->for($staff);
    }

    public function test_an_employee_in_no_section_and_no_division_still_reaches_hr_and_the_chief(): void
    {
        // The import left some records without a placement. They can still file.
        $unplaced = Employee::factory()->create(['section_id' => null, 'division_id' => null]);

        $this->assertSame(['hr', 'chief'], $this->stepsFor($unplaced));
    }

    public function test_a_head_who_leads_the_division_their_section_sits_in_is_removed_once(): void
    {
        // A section head promoted to division head while still holding the
        // section would otherwise be asked to sign twice.
        $this->division->update(['division_head_employee_id' => $this->sectionHead->id]);

        $this->assertSame(['hr', 'chief'], $this->stepsFor($this->sectionHead));
    }
}
