<?php

namespace Database\Factories;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveApplication> */
class LeaveApplicationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'date_from' => now()->addWeek()->toDateString(),
            'date_to' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'days_with_pay' => 2,
            'days_without_pay' => 0,
            'details' => null,
            'commutation' => 'not_requested',
            'status' => LeaveStatus::Pending,
            'filed_at' => now(),
        ];
    }
}
