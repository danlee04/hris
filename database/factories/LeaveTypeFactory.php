<?php

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveType> */
class LeaveTypeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->words(2, true).' Leave',
            'legal_basis' => null,
            'ledger' => null,
            'accrual_days_per_month' => null,
            'grant_days_per_year' => null,
            'notice_days' => null,
            'max_consecutive_days' => null,
            'applies_to' => ['permanent', 'coterminous'],
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /** A type that spends a balance. */
    public function credited(string $ledger = 'vacation'): static
    {
        return $this->state(fn () => ['ledger' => $ledger]);
    }
}
