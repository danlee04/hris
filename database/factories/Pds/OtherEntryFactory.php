<?php

namespace Database\Factories\Pds;

use App\Enums\OtherEntryKind;
use App\Models\Employee;
use App\Models\Pds\OtherEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OtherEntry> */
class OtherEntryFactory extends Factory
{
    protected $model = OtherEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'kind' => OtherEntryKind::SkillOrHobby->value,
            'value' => $this->faker->words(3, true),
            'sort_order' => 0,
        ];
    }
}
