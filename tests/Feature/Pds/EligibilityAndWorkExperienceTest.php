<?php

namespace Tests\Feature\Pds;

use App\Models\Employee;
use App\Models\Pds\Eligibility;
use App\Models\Pds\WorkExperience;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EligibilityAndWorkExperienceTest extends TestCase
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

    // -----------------------------------------------------------------
    // Eligibility — item 27
    // -----------------------------------------------------------------

    public function test_an_employee_may_hold_several_eligibilities(): void
    {
        // The legacy system kept one free-text eligibility on the employee row,
        // so nobody could hold both a PRC licence and a CSC eligibility.
        Livewire::actingAs($this->user)
            ->test('pages::pds.eligibility')
            ->set('rows.0.eligibility', 'Career Service Professional')
            ->set('rows.0.rating', '85.50')
            ->set('rows.0.examination_date', '2015-03-15')
            ->call('addRow')
            ->set('rows.1.eligibility', 'PRC Licence — Nurse')
            ->set('rows.1.license_number', '0123456')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Eligibility::where('employee_id', $this->employee->id)->count());
    }

    public function test_a_licence_that_has_already_expired_is_accepted(): void
    {
        // An expired licence is exactly what HR needs to see, not something to
        // refuse. There is deliberately no date rule on this field.
        Livewire::actingAs($this->user)
            ->test('pages::pds.eligibility')
            ->set('rows.0.eligibility', 'PRC Licence')
            ->set('rows.0.license_validity', '2019-01-31')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            '2019-01-31',
            Eligibility::first()->license_validity->format('Y-m-d')
        );
    }

    public function test_an_examination_in_the_future_is_refused(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.eligibility')
            ->set('rows.0.eligibility', 'Career Service Professional')
            ->set('rows.0.examination_date', now()->addYear()->format('Y-m-d'))
            ->call('save')
            ->assertHasErrors('rows.0.examination_date');

        $this->assertSame(0, Eligibility::count());
    }

    public function test_a_blank_eligibility_row_is_not_saved(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.eligibility')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, Eligibility::count());
    }

    public function test_a_tampered_eligibility_row_id_is_refused(): void
    {
        $theirs = Eligibility::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'eligibility' => 'Theirs',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.eligibility')
            ->set('rows.0.id', $theirs->id)
            ->set('rows.0.eligibility', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Theirs', $theirs->refresh()->eligibility);
    }

    // -----------------------------------------------------------------
    // Work experience — item 28
    // -----------------------------------------------------------------

    public function test_a_position_still_held_is_saved_with_no_end_date(): void
    {
        // The form prints "PRESENT" there. A date column cannot hold that word,
        // and storing it as text is how the legacy tables became unqueryable.
        Livewire::actingAs($this->user)
            ->test('pages::pds.work-experience')
            ->set('rows.0.position_title', 'Computer Programmer I')
            ->set('rows.0.department_agency', 'DOH Treatment and Rehabilitation Centre')
            ->set('rows.0.date_from', '2018-03-04')
            ->set('rows.0.monthly_salary', 25000)
            ->set('rows.0.is_government_service', true)
            ->call('save')
            ->assertHasNoErrors();

        $record = WorkExperience::firstWhere('employee_id', $this->employee->id);

        $this->assertNull($record->date_to);
        $this->assertTrue($record->isCurrent());
        $this->assertTrue($record->is_government_service);
        $this->assertSame('25000.00', $record->monthly_salary);
    }

    public function test_an_end_date_before_the_start_date_is_refused(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.work-experience')
            ->set('rows.0.position_title', 'Nurse I')
            ->set('rows.0.date_from', '2020-01-01')
            ->set('rows.0.date_to', '2019-01-01')
            ->call('save')
            ->assertHasErrors('rows.0.date_to');

        $this->assertSame(0, WorkExperience::count());
    }

    public function test_positions_keep_the_order_they_were_entered(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.work-experience')
            ->set('rows.0.position_title', 'First post')
            ->call('addRow')
            ->set('rows.1.position_title', 'Second post')
            ->call('addRow')
            ->set('rows.2.position_title', 'Third post')
            ->call('removeRow', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['First post', 'Third post'],
            WorkExperience::where('employee_id', $this->employee->id)
                ->orderBy('sort_order')->pluck('position_title')->all()
        );
    }

    public function test_a_negative_salary_is_refused(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.work-experience')
            ->set('rows.0.position_title', 'Nurse I')
            ->set('rows.0.monthly_salary', -1)
            ->call('save')
            ->assertHasErrors('rows.0.monthly_salary');

        $this->assertSame(0, WorkExperience::count());
    }

    public function test_a_tampered_work_experience_row_id_is_refused(): void
    {
        $theirs = WorkExperience::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'position_title' => 'Theirs',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.work-experience')
            ->set('rows.0.id', $theirs->id)
            ->set('rows.0.position_title', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Theirs', $theirs->refresh()->position_title);
    }
}
