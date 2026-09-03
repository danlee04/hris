<?php

namespace Database\Factories\Pds;

use App\Models\Employee;
use App\Models\Pds\WorkExperience;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkExperience> */
class WorkExperienceFactory extends Factory
{
    protected $model = WorkExperience::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date_from' => $this->faker->dateTimeBetween('-15 years', '-5 years'),
            'date_to' => $this->faker->dateTimeBetween('-4 years', '-1 year'),
            'position_title' => $this->faker->jobTitle(),
            'department_agency' => $this->faker->company(),
            'monthly_salary' => 25000,
            'salary_grade_step' => '11-1',
            'status_of_appointment' => 'Permanent',
            'is_government_service' => true,
            'sort_order' => 0,
        ];
    }
}
