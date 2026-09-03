<?php

namespace Database\Factories\Pds;

use App\Models\Employee;
use App\Models\Pds\Eligibility;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Eligibility> */
class EligibilityFactory extends Factory
{
    protected $model = Eligibility::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'eligibility' => 'Career Service Professional',
            'rating' => '85.50',
            'examination_date' => $this->faker->dateTimeBetween('-20 years', '-1 year'),
            'examination_place' => $this->faker->city(),
            'sort_order' => 0,
        ];
    }
}
