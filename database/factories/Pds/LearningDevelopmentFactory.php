<?php

namespace Database\Factories\Pds;

use App\Enums\LearningDevelopmentType;
use App\Models\Employee;
use App\Models\Pds\LearningDevelopment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LearningDevelopment> */
class LearningDevelopmentFactory extends Factory
{
    protected $model = LearningDevelopment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'title' => 'Seminar on '.$this->faker->words(3, true),
            'date_from' => $this->faker->dateTimeBetween('-5 years', '-1 year'),
            'date_to' => $this->faker->dateTimeBetween('-1 year'),
            'number_of_hours' => 8,
            'type' => LearningDevelopmentType::Technical->value,
            'conducted_by' => $this->faker->company(),
            'sort_order' => 0,
        ];
    }
}
