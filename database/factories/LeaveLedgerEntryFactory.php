<?php

namespace Database\Factories;

use App\Enums\LeaveLedgerKind;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveLedgerEntry> */
class LeaveLedgerEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'ledger' => 'vacation',
            'kind' => LeaveLedgerKind::Opening->value,
            'days' => 10,
            'effective_date' => now(),
            'period' => null,
            'description' => null,
            'created_by_user_id' => null,
        ];
    }
}
