<?php

namespace Tests\Feature\Pds;

use App\Enums\CivilStatus;
use App\Enums\Sex;
use App\Models\Employee;
use App\Models\Pds\PersonalInformation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PersonalInformationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('employee');
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_saving_creates_the_record(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->set('form.date_of_birth', '1990-04-12')
            ->set('form.place_of_birth', 'Surigao City')
            ->set('form.sex', Sex::Female->value)
            ->set('form.civil_status', CivilStatus::Married->value)
            ->set('form.height_m', 1.58)
            ->set('form.weight_kg', 52.4)
            ->set('form.mobile_no', '09171234567')
            ->call('save')
            ->assertHasNoErrors();

        $record = PersonalInformation::firstWhere('employee_id', $this->employee->id);

        $this->assertSame('1990-04-12', $record->date_of_birth->format('Y-m-d'));
        $this->assertSame(Sex::Female, $record->sex);
        $this->assertSame(CivilStatus::Married, $record->civil_status);
        $this->assertSame('1.58', $record->height_m);
    }

    public function test_saving_twice_updates_rather_than_duplicates(): void
    {
        // employee_id is unique on this table; a second insert would throw.
        foreach (['Surigao City', 'Butuan City'] as $place) {
            Livewire::actingAs($this->user)
                ->test('pages::pds.personal-information')
                ->set('form.place_of_birth', $place)
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(1, PersonalInformation::where('employee_id', $this->employee->id)->count());
        $this->assertSame('Butuan City', PersonalInformation::first()->place_of_birth);
    }

    public function test_an_existing_record_is_loaded_into_the_form(): void
    {
        PersonalInformation::factory()->create([
            'employee_id' => $this->employee->id,
            'place_of_birth' => 'Cebu City',
            'date_of_birth' => '1988-11-02',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->assertSet('form.place_of_birth', 'Cebu City')
            ->assertSet('form.date_of_birth', '1988-11-02')
            ->assertSet('form.sex', Sex::Female->value);
    }

    public function test_ticking_same_as_residential_copies_the_address(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->set('form.res_house_no', '12')
            ->set('form.res_street', 'Rizal Street')
            ->set('form.res_barangay', 'Washington')
            ->set('form.res_city', 'Surigao City')
            ->set('form.res_zip_code', '8400')
            ->set('form.permanent_same_as_residential', true)
            ->call('save')
            ->assertHasNoErrors();

        $record = PersonalInformation::first();

        $this->assertSame('Rizal Street', $record->perm_street);
        $this->assertSame('8400', $record->perm_zip_code);
    }

    public function test_a_birth_date_in_the_future_is_refused(): void
    {
        // The legacy table held a date of birth in 2026. A validated column is
        // the only thing that stops that.
        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->set('form.date_of_birth', now()->addYear()->format('Y-m-d'))
            ->call('save')
            ->assertHasErrors('form.date_of_birth');

        $this->assertSame(0, PersonalInformation::count());
    }

    public function test_an_impossible_height_is_refused(): void
    {
        // The legacy height column was a varchar and held '1' and 'sss'.
        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->set('form.height_m', 17)
            ->call('save')
            ->assertHasErrors('form.height_m');

        $this->assertSame(0, PersonalInformation::count());
    }

    public function test_a_tampered_employee_id_cannot_redirect_a_save(): void
    {
        // mount() authorised one employee. The property is rehydrated from the
        // browser on every later request, so the save has to ask again.
        $someoneElse = Employee::factory()->create();

        Livewire::actingAs($this->user)
            ->test('pages::pds.personal-information')
            ->set('employeeId', $someoneElse->id)
            ->set('form.place_of_birth', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, PersonalInformation::count());
    }

    public function test_hr_can_correct_somebody_elses_record(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('hr');

        Livewire::actingAs($hr)
            ->withQueryParams(['employee' => $this->employee->id])
            ->test('pages::pds.personal-information')
            ->set('form.place_of_birth', 'Corrected by HR')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            'Corrected by HR',
            PersonalInformation::firstWhere('employee_id', $this->employee->id)->place_of_birth
        );
    }
}
