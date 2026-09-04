<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeaveTypeScreenTest extends TestCase
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

    public function test_an_admin_adds_a_leave_type(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->call('add')
            ->set('code', 'WELLNESS')
            ->set('name', 'Wellness Leave')
            ->set('ledger', 'wellness')
            ->set('grantDaysPerYear', 5)
            ->set('noticeDays', 5)
            ->set('maxConsecutiveDays', 3)
            ->set('appliesTo', ['job_order', 'contract_of_service'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leave_types', ['code' => 'WELLNESS', 'ledger' => 'wellness']);
    }

    public function test_a_type_must_say_who_may_file_it(): void
    {
        // A type nobody can file is a row that looks like a policy and grants
        // nothing.
        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->set('code', 'X')
            ->set('name', 'Something')
            ->set('appliesTo', [])
            ->call('save')
            ->assertHasErrors('appliesTo');
    }

    public function test_two_types_cannot_share_a_code(): void
    {
        LeaveType::factory()->create(['code' => 'VL']);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->set('code', 'VL')
            ->set('name', 'Vacation Leave')
            ->set('appliesTo', ['permanent'])
            ->call('save')
            ->assertHasErrors('code');
    }

    public function test_add_after_edit_starts_from_an_empty_form(): void
    {
        // One modal serves both jobs. Without the reset, Add after Edit
        // overwrites the row last opened.
        $type = LeaveType::factory()->create(['name' => 'Study Leave']);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->call('edit', $type->id)
            ->assertSet('editingId', $type->id)
            ->call('add')
            ->assertSet('editingId', null)
            ->assertSet('code', '');
    }

    public function test_a_type_is_retired_not_deleted(): void
    {
        $type = LeaveType::factory()->create();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->call('toggleActive', $type->id);

        $this->assertFalse($type->fresh()->is_active);
        $this->assertDatabaseCount('leave_types', 1);
    }

    public function test_hr_cannot_reach_the_leave_types_screen(): void
    {
        // HR maintains balances and applications. The vocabulary itself is
        // admin territory, the same way the org chart is.
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('leave.types'))
            ->assertForbidden();
    }

    public function test_an_employee_cannot_reach_the_leave_types_screen(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get(route('leave.types'))
            ->assertForbidden();
    }

    public function test_a_save_re_asks_instead_of_trusting_mount(): void
    {
        $admin = $this->userWithRole('admin');

        $component = Livewire::actingAs($admin)->test('pages::leave.types');

        $admin->removeRole('admin');

        $component->set('code', 'X')
            ->set('name', 'Something')
            ->set('appliesTo', ['permanent'])
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseCount('leave_types', 0);
    }

    public function test_the_table_paginates(): void
    {
        LeaveType::factory()->count(16)->create();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test('pages::leave.types')
            ->assertViewHas('types', fn ($types) => $types->count() === 15 && $types->total() === 16);
    }
}
