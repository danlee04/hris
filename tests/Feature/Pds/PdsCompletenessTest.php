<?php

namespace Tests\Feature\Pds;

use App\Enums\OtherEntryKind;
use App\Models\Employee;
use App\Models\Pds\Child;
use App\Models\Pds\Declaration;
use App\Models\Pds\Education;
use App\Models\Pds\OtherEntry;
use App\Models\Pds\PersonalInformation;
use App\Models\User;
use App\Services\Pds\PdsCompleteness;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PdsCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('employee');
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    }

    private function completeness(): PdsCompleteness
    {
        return app(PdsCompleteness::class);
    }

    /** @return array<string, bool> */
    private function byKey(): array
    {
        return collect($this->completeness()->for($this->employee->fresh()))
            ->pluck('complete', 'key')
            ->all();
    }

    public function test_a_new_employee_has_nine_sections_and_none_of_them_started(): void
    {
        $sections = $this->completeness()->for($this->employee);

        $this->assertCount(9, $sections);
        $this->assertSame(0, $this->completeness()->completedCount($this->employee));
        $this->assertFalse($this->completeness()->isComplete($this->employee));
    }

    public function test_saving_a_section_marks_exactly_that_one(): void
    {
        PersonalInformation::factory()->create([
            'employee_id' => $this->employee->id,
            'place_of_birth' => 'Surigao City',
        ]);

        $sections = $this->byKey();

        $this->assertTrue($sections['personal-information']);
        $this->assertFalse($sections['family-background']);
        $this->assertFalse($sections['education']);
    }

    public function test_a_row_of_nothing_but_nulls_does_not_count(): void
    {
        // updateOrCreate leaves this behind when somebody opens a section and
        // presses Save without typing. It is not an answer.
        PersonalInformation::create(['employee_id' => $this->employee->id]);

        $this->assertFalse($this->byKey()['personal-information']);
    }

    public function test_children_alone_start_the_family_section(): void
    {
        Child::factory()->create(['employee_id' => $this->employee->id]);

        $this->assertTrue($this->byKey()['family-background']);
    }

    public function test_references_alone_start_the_declarations_section(): void
    {
        Declaration::create(['employee_id' => $this->employee->id]);

        $this->assertFalse($this->byKey()['declarations']);

        $this->employee->references()->create(['name' => 'Cecilia Burre']);

        $this->assertTrue($this->byKey()['declarations']);
    }

    public function test_answering_no_still_counts_as_answered(): void
    {
        // Unanswered and "no" are different things. A declaration of all noes
        // is a completed section.
        Declaration::create([
            'employee_id' => $this->employee->id,
            'q36_convicted' => false,
        ]);

        $this->assertTrue($this->byKey()['declarations']);
    }

    public function test_a_repeating_section_needs_one_row(): void
    {
        $this->assertFalse($this->byKey()['education']);

        Education::factory()->create(['employee_id' => $this->employee->id]);

        $this->assertTrue($this->byKey()['education']);
    }

    public function test_other_information_counts_any_of_the_three_lists(): void
    {
        OtherEntry::factory()->create([
            'employee_id' => $this->employee->id,
            'kind' => OtherEntryKind::Membership->value,
        ]);

        $this->assertTrue($this->byKey()['other-information']);
    }

    public function test_the_dashboard_shows_the_checklist(): void
    {
        PersonalInformation::factory()->create(['employee_id' => $this->employee->id]);

        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your Personal Data Sheet')
            ->assertSee('1 of 9 sections started');
    }

    public function test_the_dashboard_survives_an_account_with_no_employee_record(): void
    {
        $orphan = User::factory()->create();
        $orphan->assignRole('admin');

        $this->actingAs($orphan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Your Personal Data Sheet');
    }

    public function test_the_section_tabs_render_on_a_pds_page(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.education')
            ->assertSee('Personal information')
            ->assertSee('Declarations');
    }
}
