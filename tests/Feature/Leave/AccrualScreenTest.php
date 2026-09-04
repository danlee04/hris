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

class AccrualScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_hr_posts_a_month(): void
    {
        Employee::factory()->create(['employment_status' => EmploymentStatus::Permanent->value]);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('period', '2026-09')
            ->call('post');

        $this->assertSame(2, LeaveLedgerEntry::count());
    }

    public function test_posting_twice_writes_once(): void
    {
        // The whole reason this is a button and not a schedule is that a human
        // can see what happened. What they must not see is a second 1.25.
        Employee::factory()->create(['employment_status' => EmploymentStatus::Permanent->value]);

        $component = Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('period', '2026-09');

        $component->call('post');
        $component->call('post');

        $this->assertSame(2, LeaveLedgerEntry::count());
    }

    public function test_the_preview_writes_nothing(): void
    {
        Employee::factory()->create(['employment_status' => EmploymentStatus::Permanent->value]);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('period', '2026-09')
            ->assertViewHas('rows', fn ($rows) => count($rows) === 2);

        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_a_malformed_period_is_refused_before_it_reaches_the_service(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('period', 'September')
            ->call('post')
            ->assertHasErrors('period');
    }

    public function test_a_malformed_period_does_not_break_the_page(): void
    {
        // with() runs on every request, including the one where the field is
        // half typed. A service that throws on "2026-0" would take the screen
        // down while somebody was still typing the month.
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('period', '2026-0')
            ->assertViewHas('rows', []);
    }

    public function test_an_employee_cannot_reach_the_posting_screen(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get(route('leave.accrual'))
            ->assertForbidden();
    }

    public function test_a_post_re_asks_instead_of_trusting_mount(): void
    {
        Employee::factory()->create(['employment_status' => EmploymentStatus::Permanent->value]);

        $hr = $this->userWithRole('hr');

        $component = Livewire::actingAs($hr)
            ->test('pages::leave.accrual')
            ->set('period', '2026-09');

        $hr->removeRole('hr');

        $component->call('post')->assertForbidden();

        $this->assertSame(0, LeaveLedgerEntry::count());
    }

    public function test_hr_posts_the_yearly_grants(): void
    {
        // A job order's Wellness credit arrives only this way.
        $jobOrder = Employee::factory()->create([
            'employment_status' => EmploymentStatus::JobOrder->value,
        ]);

        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('year', '2026')
            ->call('postGrants');

        $this->assertSame(5.0, app(LeaveLedger::class)->balance($jobOrder, 'wellness'));
    }

    public function test_a_malformed_year_is_refused_before_it_reaches_the_service(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::leave.accrual')
            ->set('year', '26')
            ->call('postGrants')
            ->assertHasErrors('year');
    }
}
