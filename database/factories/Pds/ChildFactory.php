<?php

namespace Database\Factories\Pds;

use App\Models\Employee;
use App\Models\Pds\Child;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Child> */
class ChildFactory extends Factory
{
    protected $model = Child::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'name' => $this->faker->name(),
            'date_of_birth' => $this->faker->dateTimeBetween('-25 years', '-1 year'),
            'sort_order' => 0,
        ];
    }
}
