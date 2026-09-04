<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrganizationScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_an_admin_adds_a_position(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.positions')
            ->set('title', 'Nurse II')
            ->set('itemNumber', 'DTRC-N2-001')
            ->set('salaryGrade', 16)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('positions', ['title' => 'Nurse II', 'salary_grade' => 16]);
    }

    public function test_the_form_empties_itself_after_adding(): void
    {
        // Otherwise the next Add silently edits the row just created.
        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.positions')
            ->set('title', 'Nurse II')
            ->call('save')
            ->assertSet('title', '')
            ->assertSet('editingId', null);
    }

    public function test_add_after_edit_starts_from_an_empty_form(): void
    {
        // One modal serves both jobs. Without the reset, Add after Edit still
        // carries editingId and quietly overwrites the row last opened instead
        // of creating one.
        $position = Position::factory()->create(['title' => 'Nurse II']);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.positions')
            ->call('edit', $position->id)
            ->assertSet('editingId', $position->id)
            ->call('add')
            ->assertSet('editingId', null)
            ->assertSet('title', '')
            ->set('title', 'Nurse III')
            ->call('save');

        $this->assertSame('Nurse II', $position->fresh()->title);
        $this->assertDatabaseHas('positions', ['title' => 'Nurse III']);
    }

    public function test_the_position_table_paginates(): void
    {
        Position::factory()->count(16)->create();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.positions')
            ->assertViewHas('positions', fn ($positions) => $positions->count() === 15
                && $positions->total() === 16);
    }

    public function test_a_salary_grade_outside_the_ssl_range_is_refused(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.positions')
            ->set('title', 'Nurse II')
            ->set('salaryGrade', 330)
            ->call('save')
            ->assertHasErrors('salaryGrade');
    }

    public function test_editing_a_position_keeps_its_own_item_number(): void
    {
        $position = Position::factory()->create(['item_number' => 'DTRC-001']);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.positions')
            ->call('edit', $position->id)
            ->set('title', 'Nurse III')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Nurse III', $position->fresh()->title);
    }

    public function test_two_positions_cannot_share_an_item_number(): void
    {
        Position::factory()->create(['item_number' => 'DTRC-001']);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.positions')
            ->set('title', 'Nurse II')
            ->set('itemNumber', 'DTRC-001')
            ->call('save')
            ->assertHasErrors('itemNumber');
    }

    public function test_retiring_a_position_does_not_touch_the_people_holding_it(): void
    {
        // The employees foreign key nulls on delete, so a real delete would
        // quietly blank the position of everyone in it. Retiring is the whole
        // reason there is no delete button.
        $position = Position::factory()->create();
        $employee = Employee::factory()->create(['position_id' => $position->id]);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.positions')
            ->call('toggleActive', $position->id);

        $this->assertFalse($position->fresh()->is_active);
        $this->assertSame($position->id, $employee->fresh()->position_id);
    }

    public function test_an_admin_adds_a_division_with_a_head(): void
    {
        $head = Employee::factory()->create();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.divisions')
            ->set('name', 'Medical Division')
            ->set('code', 'MED')
            ->set('headEmployeeId', $head->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('divisions', [
            'name' => 'Medical Division',
            'code' => 'MED',
            'division_head_employee_id' => $head->id,
        ]);
    }

    public function test_a_blank_code_is_stored_as_null(): void
    {
        // The column is unique and nullable. Two empty strings would collide
        // with each other; two nulls do not.
        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.divisions')
            ->set('name', 'First')
            ->call('save');

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.divisions')
            ->set('name', 'Second')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('divisions', ['name' => 'Second', 'code' => null]);
    }

    public function test_a_section_must_name_its_division(): void
    {
        Division::factory()->create();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.sections')
            ->set('name', 'Statistics Unit')
            ->call('save')
            ->assertHasErrors('divisionId');
    }

    public function test_an_admin_adds_a_section(): void
    {
        $division = Division::factory()->create();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.sections')
            ->set('divisionId', $division->id)
            ->set('name', 'Statistics Unit')
            ->set('code', 'STAT')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sections', [
            'name' => 'Statistics Unit',
            'division_id' => $division->id,
        ]);
    }

    public function test_editing_a_section_moves_it_between_divisions(): void
    {
        $section = Section::factory()->create();
        $elsewhere = Division::factory()->create();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::organization.sections')
            ->call('edit', $section->id)
            ->set('divisionId', $elsewhere->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($elsewhere->id, $section->fresh()->division_id);
    }

    public function test_only_an_admin_reaches_the_org_chart(): void
    {
        // HR maintains people. Changing the chart itself is org.manage, which
        // the HR role deliberately does not carry. All three screens, because a
        // permission added to two of them is the kind of gap nothing else shows.
        foreach (['divisions', 'sections', 'positions'] as $screen) {
            $this->actingAs($this->userWithRole('hr'))
                ->get(route("organization.{$screen}"))
                ->assertForbidden();

            $this->actingAs($this->userWithRole('employee'))
                ->get(route("organization.{$screen}"))
                ->assertForbidden();

            $this->actingAs($this->userWithRole('admin'))
                ->get(route("organization.{$screen}"))
                ->assertOk();
        }
    }

    public function test_a_save_re_asks_instead_of_trusting_mount(): void
    {
        $admin = $this->userWithRole('admin');

        $component = Livewire::actingAs($admin)->test('pages::organization.positions');

        $admin->removeRole('admin');

        $component->set('title', 'Nurse II')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseCount('positions', 0);
    }

    public function test_the_sidebar_offers_the_org_chart_only_to_an_admin(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('dashboard'))
            ->assertSee(route('organization.divisions'), escape: false);

        $this->actingAs($this->userWithRole('hr'))
            ->get(route('dashboard'))
            ->assertDontSee(route('organization.divisions'), escape: false);
    }
}
