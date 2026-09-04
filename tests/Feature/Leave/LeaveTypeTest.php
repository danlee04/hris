<?php

namespace Tests\Feature\Leave;

use App\Enums\EmploymentStatus;
use App\Models\LeaveType;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_produces_the_thirteen_types_on_the_form_plus_wellness(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $this->assertSame(14, LeaveType::count());

        // The three found by reading the template rather than the issuances.
        $this->assertDatabaseHas('leave_types', ['code' => 'VAWC']);
        $this->assertDatabaseHas('leave_types', ['code' => 'SLBW']);
        $this->assertDatabaseHas('leave_types', ['code' => 'ADOPTION']);
    }

    public function test_wellness_leave_carries_the_hospitals_own_rules(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $wellness = LeaveType::where('code', 'WELLNESS')->sole();

        $this->assertSame('wellness', $wellness->ledger);
        $this->assertSame(5, $wellness->grant_days_per_year);
        $this->assertSame(5, $wellness->notice_days);
        $this->assertSame(3, $wellness->max_consecutive_days);
        $this->assertSame(['job_order', 'contract_of_service'], $wellness->applies_to);
    }

    public function test_a_job_order_sees_wellness_and_nothing_else(): void
    {
        // Job order and contract of service earn no statutory credits. If this
        // ever returns Vacation Leave, 37 people are being offered days that
        // do not exist.
        $this->seed(LeaveTypeSeeder::class);

        $codes = LeaveType::availableTo(EmploymentStatus::JobOrder)->pluck('code')->all();

        $this->assertSame(['WELLNESS'], $codes);
    }

    public function test_a_permanent_employee_does_not_see_wellness(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $codes = LeaveType::availableTo(EmploymentStatus::Permanent)->pluck('code')->all();

        $this->assertNotContains('WELLNESS', $codes);
        $this->assertContains('VL', $codes);
        $this->assertCount(13, $codes);
    }

    public function test_a_retired_type_is_not_offered(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        LeaveType::where('code', 'VL')->update(['is_active' => false]);

        $this->assertNotContains(
            'VL',
            LeaveType::availableTo(EmploymentStatus::Permanent)->pluck('code')->all()
        );
    }

    public function test_only_vacation_and_sick_accrue_monthly(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $accruing = LeaveType::whereNotNull('accrual_days_per_month')
            ->pluck('accrual_days_per_month', 'code')
            ->all();

        $this->assertEquals(['VL' => '1.25', 'SL' => '1.25'], $accruing);
    }

    public function test_a_type_without_a_ledger_is_not_credited(): void
    {
        $this->seed(LeaveTypeSeeder::class);

        $this->assertTrue(LeaveType::where('code', 'VL')->sole()->isCredited());
        $this->assertFalse(LeaveType::where('code', 'ML')->sole()->isCredited());
    }
}
