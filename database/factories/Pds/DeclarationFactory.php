<?php

namespace Database\Factories\Pds;

use App\Models\Employee;
use App\Models\Pds\Declaration;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Declaration> */
class DeclarationFactory extends Factory
{
    protected $model = Declaration::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'q34_related_third_degree' => false,
            'q34_related_fourth_degree' => false,
            'q35_administrative_offense' => false,
            'q35_criminally_charged' => false,
            'q36_convicted' => false,
            'q37_separated_from_service' => false,
            'q38_candidate_in_election' => false,
            'q38_resigned_to_campaign' => false,
            'q39_immigrant_or_permanent_resident' => false,
            'q40_indigenous_group' => false,
            'q40_person_with_disability' => false,
            'q40_solo_parent' => false,
        ];
    }
}
