<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'employee_number' => $this->faker->unique()->numerify('20##-####'),
            'first_name' => $this->faker->firstName(),
            'middle_name' => $this->faker->lastName(),
            'last_name' => $this->faker->lastName(),
            'suffix' => null,
            'position_id' => Position::factory(),
            'section_id' => Section::factory(),
            'division_id' => null,
            'is_chief_of_hospital' => false,
            'date_hired' => $this->faker->dateTimeBetween('-15 years'),
            'employment_status' => EmploymentStatus::Permanent->value,
            'biometric_id' => $this->faker->unique()->numerify('####'),
            'is_active' => true,
        ];
    }
}
