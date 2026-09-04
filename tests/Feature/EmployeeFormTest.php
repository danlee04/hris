<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class EmployeeFormTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->employee = Employee::factory()->create([
            'employee_number' => '2008-0142',
            'last_name' => 'Guico',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_hr_corrects_a_name(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.last_name', 'Lao Guico')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Lao Guico', $this->employee->fresh()->last_name);
    }

    public function test_hr_adds_an_employee(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form')
            ->set('form.employee_number', '2026-0001')
            ->set('form.first_name', 'Ana')
            ->set('form.last_name', 'Reyes')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employees', [
            'employee_number' => '2026-0001',
            'last_name' => 'Reyes',
            // The column defaults to these; the blank form has to agree with it
            // rather than write a second, quieter default of its own.
            'employment_status' => 'permanent',
            'is_active' => true,
        ]);
    }

    public function test_adding_lands_on_the_record_just_created(): void
    {
        // Staying on employees/create makes the next Save look like a second
        // person, and the unique employee number is the only thing that would
        // say otherwise.
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form')
            ->set('form.employee_number', '2026-0001')
            ->set('form.first_name', 'Ana')
            ->set('form.last_name', 'Reyes')
            ->call('save')
            ->assertRedirect(route('employees.show', Employee::where('employee_number', '2026-0001')->sole()));
    }

    public function test_a_new_employee_cannot_take_an_existing_number(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form')
            ->set('form.employee_number', '2008-0142')
            ->set('form.first_name', 'Ana')
            ->set('form.last_name', 'Reyes')
            ->call('save')
            ->assertHasErrors('form.employee_number');

        $this->assertDatabaseCount('employees', 1);
    }

    public function test_a_new_employee_cannot_be_a_second_chief_of_hospital(): void
    {
        // The incumbent check has to survive a null id, which is the case the
        // edit screen never exercises.
        Employee::factory()->create(['is_chief_of_hospital' => true, 'last_name' => 'Delos Santos']);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form')
            ->set('form.employee_number', '2026-0001')
            ->set('form.first_name', 'Ana')
            ->set('form.last_name', 'Reyes')
            ->set('form.is_chief_of_hospital', true)
            ->call('save')
            ->assertHasErrors('form.is_chief_of_hospital');

        $this->assertDatabaseMissing('employees', ['employee_number' => '2026-0001']);
    }

    public function test_an_employee_cannot_open_the_add_screen(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get(route('employees.create'))
            ->assertForbidden();
    }

    public function test_the_list_carries_the_add_modal_for_hr_only(): void
    {
        // The modal holds a live component, so an employee seeing it would mean
        // the form was mounted for them regardless of what the button says.
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('add-employee');

        $viewer = $this->userWithRole('employee');
        $viewer->givePermissionTo('employees.view');

        $this->actingAs($viewer)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee('add-employee');
    }

    public function test_the_add_form_still_has_its_own_page(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.create'))
            ->assertOk();
    }

    public function test_opening_a_row_for_editing_re_asks_instead_of_trusting_mount(): void
    {
        // The id arrives from the browser on a request of its own, long after
        // mount() ran. An ability withdrawn in between has to stop the form
        // from loading somebody's record into it.
        $hr = $this->userWithRole('hr');

        $component = Livewire::actingAs($hr)->test('pages::employees.form', ['inModal' => true]);

        $hr->removeRole('hr');

        $component->call('startEditing', $this->employee->id)->assertForbidden();
    }

    public function test_editing_loads_the_row_that_was_asked_for(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['inModal' => true])
            ->call('startEditing', $this->employee->id)
            ->assertSet('employeeId', $this->employee->id)
            ->assertSet('form.last_name', 'Guico');
    }

    public function test_add_after_edit_starts_from_an_empty_form(): void
    {
        // One modal serves both jobs. Without the reset, Add carries the last
        // employee's id and quietly overwrites them instead of creating one.
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['inModal' => true])
            ->call('startEditing', $this->employee->id)
            ->call('startAdding')
            ->assertSet('employeeId', null)
            ->assertSet('form.last_name', null);
    }

    public function test_the_save_re_asks_instead_of_trusting_mount(): void
    {
        // mount() runs once. Everything after it is a fresh request carrying
        // whatever the browser sends, so an ability withdrawn in between has to
        // stop the save — not just the next page view.
        $hr = $this->userWithRole('hr');

        $component = Livewire::actingAs($hr)
            ->test('pages::employees.form', ['employee' => $this->employee]);

        $hr->removeRole('hr');

        $component->set('form.last_name', 'Changed')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Guico', $this->employee->fresh()->last_name);
    }

    public function test_the_employee_number_must_stay_unique(): void
    {
        Employee::factory()->create(['employee_number' => '2010-0001']);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.employee_number', '2010-0001')
            ->call('save')
            ->assertHasErrors('form.employee_number');
    }

    public function test_keeping_its_own_number_is_not_a_duplicate(): void
    {
        // The uniqueness rule has to ignore the record being edited, or nobody
        // could ever save a form they only half changed.
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.first_name', 'Corrected')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_a_section_from_another_division_drags_its_division_along(): void
    {
        // The section is the real assignment; the division select only narrows
        // the list. However the two arrive, the section wins, so they cannot be
        // saved disagreeing with each other.
        $section = Section::factory()->create();
        $elsewhere = Division::factory()->create();

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.division_id', $elsewhere->id)
            ->set('form.section_id', $section->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($section->division_id, $this->employee->fresh()->division_id);
    }

    public function test_the_section_decides_the_division(): void
    {
        // Whatever the form said, a section carries its own division. Otherwise
        // the two columns disagree and every report built on either is wrong.
        $section = Section::factory()->create();

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.division_id', $section->division_id)
            ->set('form.section_id', $section->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($section->division_id, $this->employee->fresh()->division_id);
    }

    public function test_changing_the_division_clears_the_section(): void
    {
        $section = Section::factory()->create();
        $other = Division::factory()->create();

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.division_id', $section->division_id)
            ->set('form.section_id', $section->id)
            ->set('form.division_id', $other->id)
            ->assertSet('form.section_id', null);
    }

    public function test_a_second_chief_of_hospital_is_refused_by_name(): void
    {
        $incumbent = Employee::factory()->create([
            'is_chief_of_hospital' => true,
            'last_name' => 'Delos Santos',
            'first_name' => 'Maria',
        ]);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.is_chief_of_hospital', true)
            ->call('save')
            ->assertHasErrors('form.is_chief_of_hospital')
            ->assertSee($incumbent->fullName());

        $this->assertFalse($this->employee->fresh()->is_chief_of_hospital);
    }

    public function test_the_chief_may_save_their_own_record_again(): void
    {
        $this->employee->update(['is_chief_of_hospital' => true]);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.first_name', 'Corrected')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_a_biometric_id_belonging_to_somebody_else_is_refused(): void
    {
        // The real 134-row import died mid-write on exactly this collision.
        Employee::factory()->create(['biometric_id' => '77']);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.biometric_id', '77')
            ->call('save')
            ->assertHasErrors('form.biometric_id');
    }

    public function test_a_future_hire_date_is_refused(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.date_hired', now()->addYear()->format('Y-m-d'))
            ->call('save')
            ->assertHasErrors('form.date_hired');
    }

    public function test_an_edit_is_written_to_the_audit_log(): void
    {
        $hr = $this->userWithRole('hr');

        Livewire::actingAs($hr)
            ->test('pages::employees.form', ['employee' => $this->employee])
            ->set('form.employment_status', EmploymentStatus::Coterminous->value)
            ->call('save');

        $activity = Activity::where('subject_id', $this->employee->id)->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($hr->id, $activity->causer_id);
        // In activitylog v5 the before/after values live here, not in properties.
        $this->assertSame(
            EmploymentStatus::Coterminous->value,
            $activity->attribute_changes['attributes']['employment_status'] ?? null
        );
    }

    public function test_the_list_offers_hr_view_and_edit_but_not_the_pds(): void
    {
        // Taking somebody's whole record out of the system is a deliberate act.
        // A Download link in a list of 134 rows is an easy one.
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee(route('employees.show', ['employee' => $this->employee->id]), escape: false)
            ->assertSee('edit-employee')
            ->assertDontSee(route('pds.export', ['employee' => $this->employee->id]), escape: false)
            ->assertDontSee(route('pds.personal-information', ['employee' => $this->employee->id]), escape: false);
    }
}
