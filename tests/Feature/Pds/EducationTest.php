<?php

namespace Tests\Feature\Pds;

use App\Enums\EducationLevel;
use App\Models\Employee;
use App\Models\Pds\Education;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EducationTest extends TestCase
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

    public function test_saving_stores_an_entry_with_its_level(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.education')
            ->set('rows.0.level', EducationLevel::College->value)
            ->set('rows.0.school_name', 'Surigao State College of Technology')
            ->set('rows.0.degree_course', 'BS Information Technology')
            ->set('rows.0.period_from', 2018)
            ->set('rows.0.period_to', 2022)
            ->set('rows.0.year_graduated', 2022)
            ->set('rows.0.honors', 'Cum Laude')
            ->call('save')
            ->assertHasNoErrors();

        $record = Education::firstWhere('employee_id', $this->employee->id);

        $this->assertSame(EducationLevel::College, $record->level);
        $this->assertSame('BS Information Technology', $record->degree_course);
        $this->assertSame(2022, $record->year_graduated);
    }

    public function test_an_employee_may_hold_two_degrees_at_the_same_level(): void
    {
        // The legacy system inserted exactly five blank rows per employee, one
        // per level, which is why that table is full of empty records. The CSC
        // form allows more than one entry per level and so does this.
        Livewire::actingAs($this->user)
            ->test('pages::pds.education')
            ->set('rows.0.level', EducationLevel::Graduate->value)
            ->set('rows.0.school_name', 'First university')
            ->call('addRow')
            ->set('rows.1.level', EducationLevel::Graduate->value)
            ->set('rows.1.school_name', 'Second university')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Education::where('level', EducationLevel::Graduate)->count());
    }

    public function test_removing_the_middle_entry_leaves_the_right_two(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.education')
            ->set('rows.0.school_name', 'Elementary school')
            ->call('addRow')
            ->set('rows.1.school_name', 'High school')
            ->call('addRow')
            ->set('rows.2.school_name', 'College')
            ->call('removeRow', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['Elementary school', 'College'],
            Education::where('employee_id', $this->employee->id)->orderBy('sort_order')->pluck('school_name')->all()
        );
    }

    public function test_a_blank_row_is_not_saved(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.education')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, Education::count());
    }

    public function test_a_year_outside_a_plausible_range_is_refused(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.education')
            ->set('rows.0.school_name', 'Somewhere')
            ->set('rows.0.year_graduated', 1750)
            ->call('save')
            ->assertHasErrors('rows.0.year_graduated');

        $this->assertSame(0, Education::count());
    }

    public function test_an_unknown_level_is_refused(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.education')
            ->set('rows.0.school_name', 'Somewhere')
            ->set('rows.0.level', 'post-doctoral')
            ->call('save')
            ->assertHasErrors('rows.0.level');

        $this->assertSame(0, Education::count());
    }

    public function test_existing_entries_are_loaded_in_order(): void
    {
        Education::factory()->create([
            'employee_id' => $this->employee->id,
            'school_name' => 'Elementary school',
            'sort_order' => 0,
        ]);
        Education::factory()->create([
            'employee_id' => $this->employee->id,
            'school_name' => 'High school',
            'sort_order' => 1,
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.education')
            ->assertSet('rows.0.school_name', 'Elementary school')
            ->assertSet('rows.1.school_name', 'High school');
    }

    public function test_a_tampered_row_id_is_refused(): void
    {
        $theirs = Education::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'school_name' => 'Theirs',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.education')
            ->set('rows.0.id', $theirs->id)
            ->set('rows.0.school_name', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Theirs', $theirs->refresh()->school_name);
    }
}
