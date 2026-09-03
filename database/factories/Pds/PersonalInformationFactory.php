<?php

namespace Database\Factories\Pds;

use App\Enums\CivilStatus;
use App\Enums\Sex;
use App\Models\Employee;
use App\Models\Pds\PersonalInformation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PersonalInformation> */
class PersonalInformationFactory extends Factory
{
    protected $model = PersonalInformation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date_of_birth' => $this->faker->dateTimeBetween('-60 years', '-20 years'),
            'place_of_birth' => $this->faker->city(),
            'sex' => Sex::Female->value,
            'civil_status' => CivilStatus::Single->value,
            'height_m' => 1.60,
            'weight_kg' => 55.0,
            'blood_type' => 'O+',
            'citizenship' => 'Filipino',
            'res_city' => $this->faker->city(),
            'mobile_no' => '09171234567',
            'email_address' => $this->faker->safeEmail(),
        ];
    }
}
