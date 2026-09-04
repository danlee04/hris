<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignatureNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_name_prints_given_name_first_and_in_capitals(): void
    {
        // The list reads "LAO GUICO, MARY JANE E."; a signature line does not.
        $employee = Employee::factory()->create([
            'first_name' => 'Mary Jane',
            'middle_name' => 'Estrada',
            'last_name' => 'Lao Guico',
            'suffix' => null,
            'credentials' => null,
        ]);

        $this->assertSame('MARY JANE E. LAO GUICO', $employee->signatureName());

        // fullName() keeps whatever HR typed; only the signature line is
        // capitalised, because that is a convention of the form and not of the
        // record.
        $this->assertSame('Lao Guico, Mary Jane E.', $employee->fullName());
    }

    public function test_the_credentials_follow_the_name_after_a_comma(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Kathleen',
            'middle_name' => 'Lopez',
            'last_name' => 'Onde',
            'credentials' => 'MM-PA',
        ]);

        $this->assertSame('KATHLEEN L. ONDE, MM-PA', $employee->signatureName());
    }

    public function test_several_credentials_are_kept_as_written(): void
    {
        // Free text on purpose: PhD, RN and MM-PA do not agree on capitals, and
        // a list of accepted degrees maintained in code would be wrong within
        // a year.
        $employee = Employee::factory()->create([
            'first_name' => 'Edhel',
            'middle_name' => 'Santos',
            'last_name' => 'Miro',
            'credentials' => 'MD, DPCAM, MM-PA',
        ]);

        $this->assertSame('EDHEL S. MIRO, MD, DPCAM, MM-PA', $employee->signatureName());
    }

    public function test_a_name_extension_stays_with_the_name_and_before_the_credentials(): void
    {
        // Jr. is part of the name. MD is not, and the CSC forms give them
        // different boxes.
        $employee = Employee::factory()->create([
            'first_name' => 'Juan',
            'middle_name' => 'Dela Cruz',
            'last_name' => 'Santos',
            'suffix' => 'Jr.',
            'credentials' => 'MD',
        ]);

        $this->assertSame('JUAN D. SANTOS JR., MD', $employee->signatureName());
    }

    public function test_a_missing_middle_name_leaves_no_stray_initial(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Ana',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'suffix' => null,
            'credentials' => null,
        ]);

        $this->assertSame('ANA REYES', $employee->signatureName());
    }
}
