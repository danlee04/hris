<?php

namespace Database\Factories\Pds;

use App\Enums\EducationLevel;
use App\Models\Employee;
use App\Models\Pds\Education;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Education> */
class EducationFactory extends Factory
{
    protected $model = Education::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'level' => EducationLevel::College->value,
            'school_name' => $this->faker->company().' College',
            'degree_course' => 'BS '.$this->faker->word(),
            'period_from' => 2010,
            'period_to' => 2014,
            'year_graduated' => 2014,
            'sort_order' => 0,
        ];
    }
}
