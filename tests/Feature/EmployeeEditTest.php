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

class EmployeeEditTest extends TestCase
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
            ->test('pages::employees.edit', ['employee' => $this->employee])
            ->set('form.last_name', 'Lao Guico')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Lao Guico', $this->employee->fresh()->last_name);
    }

    public function test_an_employee_cannot_open_the_edit_screen(): void
    {
        // The employee master belongs to HR. What the person maintains is their
        // PDS, which is a different question with its own policy.
        $user = $this->userWithRole('employee');
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('employees.edit', ['employee' => $own->id]))
            ->assertForbidden();
    }

    public function test_the_save_re_asks_instead_of_trusting_mount(): void
    {
        // mount() runs once. Everything after it is a fresh request carrying
        // whatever the browser sends, so an ability withdrawn in between has to
        // stop the save — not just the next page view.
        $hr = $this->userWithRole('hr');

        $component = Livewire::actingAs($hr)
            ->test('pages::employees.edit', ['employee' => $this->employee]);

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
            ->test('pages::employees.edit', ['employee' => $this->employee])
            ->set('form.employee_number', '2010-0001')
            ->call('save')
            ->assertHasErrors('form.employee_number');
    }

    public function test_keeping_its_own_number_is_not_a_duplicate(): void
    {
        // The uniqueness rule has to ignore the record being edited, or nobody
        // could ever save a form they only half changed.
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.edit', ['employee' => $this->employee])
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
            ->test('pages::employees.edit', ['employee' => $this->employee])
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
            ->test('pages::employees.edit', ['employee' => $this->employee])
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
            ->test('pages::employees.edit', ['employee' => $this->employee])
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
            ->test('pages::employees.edit', ['employee' => $this->employee])
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
            ->test('pages::employees.edit', ['employee' => $this->employee])
            ->set('form.first_name', 'Corrected')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_a_biometric_id_belonging_to_somebody_else_is_refused(): void
    {
        // The real 134-row import died mid-write on exactly this collision.
        Employee::factory()->create(['biometric_id' => '77']);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.edit', ['employee' => $this->employee])
            ->set('form.biometric_id', '77')
            ->call('save')
            ->assertHasErrors('form.biometric_id');
    }

    public function test_a_future_hire_date_is_refused(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.edit', ['employee' => $this->employee])
            ->set('form.date_hired', now()->addYear()->format('Y-m-d'))
            ->call('save')
            ->assertHasErrors('form.date_hired');
    }

    public function test_an_edit_is_written_to_the_audit_log(): void
    {
        $hr = $this->userWithRole('hr');

        Livewire::actingAs($hr)
            ->test('pages::employees.edit', ['employee' => $this->employee])
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

    public function test_the_list_offers_hr_an_edit_link(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee(route('employees.edit', ['employee' => $this->employee->id]), escape: false);
    }
}
