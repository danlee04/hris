<?php

namespace Tests\Feature\Pds;

use App\Models\Employee;
use App\Models\Pds\Child;
use App\Models\Pds\FamilyBackground;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FamilyBackgroundTest extends TestCase
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

    public function test_saving_stores_the_spouse_and_parents(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.family-background')
            ->set('form.spouse_surname', 'Reyes')
            ->set('form.spouse_first_name', 'Ana')
            ->set('form.father_surname', 'Madelo')
            ->set('form.mother_surname', 'Espina')
            ->call('save')
            ->assertHasNoErrors();

        $record = FamilyBackground::firstWhere('employee_id', $this->employee->id);

        $this->assertSame('Reyes', $record->spouse_surname);
        $this->assertSame('Madelo', $record->father_surname);
        $this->assertSame('Espina', $record->mother_surname);
    }

    public function test_children_are_saved_in_the_order_they_were_entered(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.family-background')
            ->set('rows.0.name', 'Ana')
            ->set('rows.0.date_of_birth', '2010-01-05')
            ->call('addRow')
            ->set('rows.1.name', 'Ben')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['Ana', 'Ben'],
            Child::where('employee_id', $this->employee->id)->orderBy('sort_order')->pluck('name')->all()
        );
    }

    public function test_removing_the_middle_child_leaves_the_right_two(): void
    {
        // With wire:key bound to the array index instead of a stable key, this
        // is the test that fails — the surviving rows carry each other's data.
        Livewire::actingAs($this->user)
            ->test('pages::pds.family-background')
            ->set('rows.0.name', 'Ana')
            ->call('addRow')
            ->set('rows.1.name', 'Ben')
            ->call('addRow')
            ->set('rows.2.name', 'Carlo')
            ->call('removeRow', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['Ana', 'Carlo'],
            Child::where('employee_id', $this->employee->id)->orderBy('sort_order')->pluck('name')->all()
        );
    }

    public function test_a_blank_row_is_not_saved_as_a_child(): void
    {
        // An empty repeater renders one blank row so there is something to type
        // into. That is not an entry.
        Livewire::actingAs($this->user)
            ->test('pages::pds.family-background')
            ->set('form.father_surname', 'Madelo')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, Child::count());
    }

    public function test_existing_children_are_loaded_into_the_form(): void
    {
        Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ana', 'sort_order' => 0]);
        Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ben', 'sort_order' => 1]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.family-background')
            ->assertSet('rows.0.name', 'Ana')
            ->assertSet('rows.1.name', 'Ben');
    }

    public function test_editing_an_existing_child_updates_rather_than_duplicates(): void
    {
        $child = Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ana']);

        Livewire::actingAs($this->user)
            ->test('pages::pds.family-background')
            ->set('rows.0.name', 'Ana Marie')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Child::count());
        $this->assertSame('Ana Marie', $child->refresh()->name);
    }

    public function test_a_child_born_in_the_future_is_refused(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.family-background')
            ->set('rows.0.name', 'Ana')
            ->set('rows.0.date_of_birth', now()->addYear()->format('Y-m-d'))
            ->call('save')
            ->assertHasErrors('rows.0.date_of_birth');

        $this->assertSame(0, Child::count());
    }

    public function test_a_tampered_child_row_id_is_refused(): void
    {
        // The row id round-trips through the browser. RowWriter is what stops
        // it from reaching somebody else's record.
        $theirs = Child::factory()->create(['employee_id' => Employee::factory()->create()->id, 'name' => 'Ana']);

        Livewire::actingAs($this->user)
            ->test('pages::pds.family-background')
            ->set('rows.0.id', $theirs->id)
            ->set('rows.0.name', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Ana', $theirs->refresh()->name);
    }
}
