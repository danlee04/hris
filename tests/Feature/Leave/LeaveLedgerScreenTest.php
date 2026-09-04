<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeaveLedgerScreenTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        $this->employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent->value,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_hr_enters_an_opening_balance(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $this->employee])
            ->set('ledger', 'vacation')
            ->set('days', '12.5')
            ->call('openBalance')
            ->assertHasNoErrors();

        $this->assertSame(12.5, app(LeaveLedger::class)->balance($this->employee, 'vacation'));
    }

    public function test_a_second_opening_balance_is_refused_with_a_message(): void
    {
        app(LeaveLedger::class)->open($this->employee, 'vacation', 10);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $this->employee])
            ->set('ledger', 'vacation')
            ->set('days', '12')
            ->call('openBalance')
            ->assertHasErrors('days');

        $this->assertSame(10.0, app(LeaveLedger::class)->balance($this->employee, 'vacation'));
    }

    public function test_hr_adjusts_a_balance_with_a_reason(): void
    {
        app(LeaveLedger::class)->open($this->employee, 'vacation', 10);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $this->employee])
            ->set('ledger', 'vacation')
            ->set('days', '-2')
            ->set('reason', 'Corrected from the 2025 spreadsheet')
            ->call('adjust')
            ->assertHasNoErrors();

        $this->assertSame(8.0, app(LeaveLedger::class)->balance($this->employee, 'vacation'));
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $this->employee])
            ->set('ledger', 'vacation')
            ->set('days', '-2')
            ->set('reason', '')
            ->call('adjust')
            ->assertHasErrors('reason');

        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_a_ledger_the_employee_cannot_hold_is_refused(): void
    {
        // A job order has no vacation balance. Writing one would produce a
        // number nothing on their form can ever spend.
        $jobOrder = Employee::factory()->create([
            'employment_status' => EmploymentStatus::JobOrder->value,
        ]);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $jobOrder])
            ->set('ledger', 'vacation')
            ->set('days', '10')
            ->call('openBalance')
            ->assertHasErrors('ledger');

        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_an_employee_cannot_reach_anybodys_ledger_including_their_own(): void
    {
        // Reading a balance is not the same as changing one, and this screen
        // only changes. What an employee sees is My leave, in Phase 2a-2.
        $user = $this->userWithRole('employee');
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('leave.ledger', ['employee' => $own->id]))
            ->assertForbidden();
    }

    public function test_a_write_re_asks_instead_of_trusting_mount(): void
    {
        $hr = $this->userWithRole('hr');

        $component = Livewire::actingAs($hr)
            ->test('pages::leave.ledger', ['employee' => $this->employee]);

        $hr->removeRole('hr');

        $component->set('ledger', 'vacation')
            ->set('days', '10')
            ->call('openBalance')
            ->assertForbidden();

        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_the_entries_are_listed_newest_first(): void
    {
        app(LeaveLedger::class)->open($this->employee, 'vacation', 10);
        app(LeaveLedger::class)->adjust($this->employee, 'vacation', 3, 'Awarded');

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.ledger', ['employee' => $this->employee])
            ->assertViewHas('entries', fn ($entries) => $entries->first()->description === 'Awarded');
    }

    public function test_the_employee_list_offers_hr_a_leave_link(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee(route('leave.ledger', ['employee' => $this->employee->id]), escape: false);
    }
}
