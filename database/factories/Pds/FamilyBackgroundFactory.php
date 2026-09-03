<?php

namespace Database\Factories\Pds;

use App\Models\Employee;
use App\Models\Pds\FamilyBackground;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FamilyBackground> */
class FamilyBackgroundFactory extends Factory
{
    protected $model = FamilyBackground::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'father_surname' => $this->faker->lastName(),
            'father_first_name' => $this->faker->firstNameMale(),
            'mother_surname' => $this->faker->lastName(),
            'mother_first_name' => $this->faker->firstNameFemale(),
        ];
    }
}
