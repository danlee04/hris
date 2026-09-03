<?php

namespace Database\Factories\Pds;

use App\Models\Employee;
use App\Models\Pds\Reference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reference> */
class ReferenceFactory extends Factory
{
    protected $model = Reference::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'name' => $this->faker->name(),
            'address' => $this->faker->address(),
            'telephone_no' => '09171234567',
            'sort_order' => 0,
        ];
    }
}
