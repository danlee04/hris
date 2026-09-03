<?php

namespace Database\Factories;

use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Position> */
class PositionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => $this->faker->unique()->jobTitle(),
            'item_number' => 'OSEC-DOHB-'.$this->faker->unique()->numerify('####-####'),
            'salary_grade' => $this->faker->numberBetween(1, 33),
            'is_active' => true,
        ];
    }
}
