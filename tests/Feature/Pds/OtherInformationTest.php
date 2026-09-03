<?php

namespace Tests\Feature\Pds;

use App\Enums\OtherEntryKind;
use App\Models\Employee;
use App\Models\Pds\OtherEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OtherInformationTest extends TestCase
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

    /** @return list<string> */
    private function valuesOf(OtherEntryKind $kind): array
    {
        return OtherEntry::where('employee_id', $this->employee->id)
            ->ofKind($kind)
            ->orderBy('sort_order')
            ->pluck('value')
            ->all();
    }

    public function test_the_three_lists_are_saved_independently(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.other-information')
            ->set('lists.skill_hobby.0.value', 'Photography')
            ->set('lists.distinction.0.value', 'Employee of the Year 2022')
            ->set('lists.membership.0.value', 'Philippine Computer Society')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['Photography'], $this->valuesOf(OtherEntryKind::SkillOrHobby));
        $this->assertSame(['Employee of the Year 2022'], $this->valuesOf(OtherEntryKind::Distinction));
        $this->assertSame(['Philippine Computer Society'], $this->valuesOf(OtherEntryKind::Membership));
    }

    public function test_emptying_one_list_leaves_the_other_two_alone(): void
    {
        // This is what RowWriter's scope exists for. Without it, saving with an
        // empty skills list would delete every distinction and membership too.
        OtherEntry::factory()->create([
            'employee_id' => $this->employee->id,
            'kind' => OtherEntryKind::SkillOrHobby->value,
            'value' => 'Photography',
        ]);
        OtherEntry::factory()->create([
            'employee_id' => $this->employee->id,
            'kind' => OtherEntryKind::Distinction->value,
            'value' => 'Employee of the Year',
        ]);
        OtherEntry::factory()->create([
            'employee_id' => $this->employee->id,
            'kind' => OtherEntryKind::Membership->value,
            'value' => 'Philippine Computer Society',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.other-information')
            ->set('lists.skill_hobby.0.value', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([], $this->valuesOf(OtherEntryKind::SkillOrHobby));
        $this->assertSame(['Employee of the Year'], $this->valuesOf(OtherEntryKind::Distinction));
        $this->assertSame(['Philippine Computer Society'], $this->valuesOf(OtherEntryKind::Membership));
    }

    public function test_a_list_keeps_the_order_it_was_entered(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.other-information')
            ->set('lists.skill_hobby.0.value', 'Photography')
            ->call('addRow', 'skill_hobby')
            ->set('lists.skill_hobby.1.value', 'Chess')
            ->call('addRow', 'skill_hobby')
            ->set('lists.skill_hobby.2.value', 'Running')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['Photography', 'Chess', 'Running'],
            $this->valuesOf(OtherEntryKind::SkillOrHobby)
        );
    }

    public function test_removing_the_middle_entry_leaves_the_right_two(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.other-information')
            ->set('lists.skill_hobby.0.value', 'Photography')
            ->call('addRow', 'skill_hobby')
            ->set('lists.skill_hobby.1.value', 'Chess')
            ->call('addRow', 'skill_hobby')
            ->set('lists.skill_hobby.2.value', 'Running')
            ->call('removeRow', 'skill_hobby', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['Photography', 'Running'], $this->valuesOf(OtherEntryKind::SkillOrHobby));
    }

    public function test_blank_rows_are_not_saved(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.other-information')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, OtherEntry::count());
    }

    public function test_whitespace_only_is_not_an_entry(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.other-information')
            ->set('lists.skill_hobby.0.value', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, OtherEntry::count());
    }

    public function test_existing_entries_are_loaded_into_the_right_list(): void
    {
        OtherEntry::factory()->create([
            'employee_id' => $this->employee->id,
            'kind' => OtherEntryKind::Membership->value,
            'value' => 'Philippine Nurses Association',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.other-information')
            ->assertSet('lists.membership.0.value', 'Philippine Nurses Association')
            ->assertSet('lists.skill_hobby.0.value', '');
    }

    public function test_a_tampered_row_id_is_refused(): void
    {
        $theirs = OtherEntry::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'kind' => OtherEntryKind::SkillOrHobby->value,
            'value' => 'Theirs',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.other-information')
            ->set('lists.skill_hobby.0.id', $theirs->id)
            ->set('lists.skill_hobby.0.value', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Theirs', $theirs->refresh()->value);
    }

    public function test_a_row_id_from_another_list_of_the_same_employee_is_refused(): void
    {
        // The scope narrows ownership to one kind, so a distinction's id
        // offered to the skills list is out of scope and refused.
        $distinction = OtherEntry::factory()->create([
            'employee_id' => $this->employee->id,
            'kind' => OtherEntryKind::Distinction->value,
            'value' => 'Employee of the Year',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.other-information')
            ->set('lists.skill_hobby.0.id', $distinction->id)
            ->set('lists.skill_hobby.0.value', 'Moved')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Employee of the Year', $distinction->refresh()->value);
        $this->assertSame(OtherEntryKind::Distinction, $distinction->kind);
    }
}
