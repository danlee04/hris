<?php

namespace Database\Factories\Pds;

use App\Models\Employee;
use App\Models\Pds\VoluntaryWork;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VoluntaryWork> */
class VoluntaryWorkFactory extends Factory
{
    protected $model = VoluntaryWork::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'organization_name_address' => $this->faker->company().', '.$this->faker->city(),
            'date_from' => $this->faker->dateTimeBetween('-10 years', '-2 years'),
            'date_to' => $this->faker->dateTimeBetween('-1 year'),
            'number_of_hours' => 40,
            'position_nature_of_work' => 'Volunteer',
            'sort_order' => 0,
        ];
    }
}
