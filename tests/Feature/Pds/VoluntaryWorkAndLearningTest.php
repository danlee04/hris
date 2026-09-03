<?php

namespace Tests\Feature\Pds;

use App\Enums\LearningDevelopmentType;
use App\Models\Employee;
use App\Models\Pds\LearningDevelopment;
use App\Models\Pds\VoluntaryWork;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VoluntaryWorkAndLearningTest extends TestCase
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
    // Voluntary work — item 29
    // -----------------------------------------------------------------

    public function test_saving_stores_voluntary_work(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.voluntary-work')
            ->set('rows.0.organization_name_address', 'Philippine Red Cross, Surigao City')
            ->set('rows.0.date_from', '2019-06-01')
            ->set('rows.0.date_to', '2019-12-31')
            ->set('rows.0.number_of_hours', 120)
            ->set('rows.0.position_nature_of_work', 'Volunteer first aider')
            ->call('save')
            ->assertHasNoErrors();

        $record = VoluntaryWork::firstWhere('employee_id', $this->employee->id);

        $this->assertSame('Philippine Red Cross, Surigao City', $record->organization_name_address);
        $this->assertSame(120, $record->number_of_hours);
    }

    public function test_an_end_date_before_the_start_date_is_refused(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.voluntary-work')
            ->set('rows.0.organization_name_address', 'Somewhere')
            ->set('rows.0.date_from', '2020-01-01')
            ->set('rows.0.date_to', '2019-01-01')
            ->call('save')
            ->assertHasErrors('rows.0.date_to');

        $this->assertSame(0, VoluntaryWork::count());
    }

    public function test_a_blank_voluntary_work_row_is_not_saved(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.voluntary-work')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, VoluntaryWork::count());
    }

    public function test_a_tampered_voluntary_work_row_id_is_refused(): void
    {
        $theirs = VoluntaryWork::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'organization_name_address' => 'Theirs',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.voluntary-work')
            ->set('rows.0.id', $theirs->id)
            ->set('rows.0.organization_name_address', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Theirs', $theirs->refresh()->organization_name_address);
    }

    // -----------------------------------------------------------------
    // Learning and development — item 30
    // -----------------------------------------------------------------

    public function test_saving_stores_an_intervention_with_its_type(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.learning-development')
            ->set('rows.0.title', 'Basic Life Support Training')
            ->set('rows.0.date_from', '2023-02-06')
            ->set('rows.0.date_to', '2023-02-08')
            ->set('rows.0.number_of_hours', 24)
            ->set('rows.0.type', LearningDevelopmentType::Technical->value)
            ->set('rows.0.conducted_by', 'Philippine Heart Association')
            ->call('save')
            ->assertHasNoErrors();

        $record = LearningDevelopment::firstWhere('employee_id', $this->employee->id);

        $this->assertSame(LearningDevelopmentType::Technical, $record->type);
        $this->assertSame(24, $record->number_of_hours);
        $this->assertSame('Philippine Heart Association', $record->conducted_by);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.learning-development')
            ->set('rows.0.title', 'Something')
            ->set('rows.0.type', 'executive')
            ->call('save')
            ->assertHasErrors('rows.0.type');

        $this->assertSame(0, LearningDevelopment::count());
    }

    public function test_interventions_keep_their_order_when_one_is_removed(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.learning-development')
            ->set('rows.0.title', 'First')
            ->call('addRow')
            ->set('rows.1.title', 'Second')
            ->call('addRow')
            ->set('rows.2.title', 'Third')
            ->call('removeRow', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['First', 'Third'],
            LearningDevelopment::where('employee_id', $this->employee->id)
                ->orderBy('sort_order')->pluck('title')->all()
        );
    }

    public function test_a_negative_number_of_hours_is_refused(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.learning-development')
            ->set('rows.0.title', 'Something')
            ->set('rows.0.number_of_hours', -8)
            ->call('save')
            ->assertHasErrors('rows.0.number_of_hours');

        $this->assertSame(0, LearningDevelopment::count());
    }

    public function test_a_tampered_learning_row_id_is_refused(): void
    {
        $theirs = LearningDevelopment::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'title' => 'Theirs',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.learning-development')
            ->set('rows.0.id', $theirs->id)
            ->set('rows.0.title', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Theirs', $theirs->refresh()->title);
    }
}
