<?php

namespace Tests\Feature\Pds;

use App\Models\Employee;
use App\Models\Pds\Declaration;
use App\Models\Pds\Reference;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeclarationsTest extends TestCase
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

    public function test_answering_no_to_everything_saves(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.declarations')
            ->set('form.q34_related_third_degree', false)
            ->set('form.q35_criminally_charged', false)
            ->set('form.date_accomplished', '2026-09-03')
            ->call('save')
            ->assertHasNoErrors();

        $record = Declaration::firstWhere('employee_id', $this->employee->id);

        $this->assertFalse($record->q34_related_third_degree);
        $this->assertSame('2026-09-03', $record->date_accomplished->format('Y-m-d'));
    }

    public function test_an_unexplained_yes_is_refused(): void
    {
        // A document signed under penalty of perjury. "Yes" with nothing after
        // it is not an answer.
        Livewire::actingAs($this->user)
            ->test('pages::pds.declarations')
            ->set('form.q36_convicted', true)
            ->call('save')
            ->assertHasErrors('form.q36_details');

        $this->assertSame(0, Declaration::count());
    }

    public function test_a_yes_with_details_is_saved(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.declarations')
            ->set('form.q35_criminally_charged', true)
            ->set('form.q35_criminal_details', 'Dismissed for lack of merit')
            ->set('form.q35_date_filed', '2015-06-01')
            ->set('form.q35_case_status', 'Dismissed')
            ->call('save')
            ->assertHasNoErrors();

        $record = Declaration::first();

        $this->assertTrue($record->q35_criminally_charged);
        $this->assertSame('Dismissed', $record->q35_case_status);
        $this->assertSame('2015-06-01', $record->q35_date_filed->format('Y-m-d'));
    }

    public function test_every_question_that_carries_details_requires_them(): void
    {
        // Reads the same map the form and the validation read, so a question
        // added later cannot quietly skip this.
        foreach (Declaration::DETAILS_REQUIRED_BY as $question => $details) {
            Livewire::actingAs($this->user)
                ->test('pages::pds.declarations')
                ->set("form.{$question}", true)
                ->call('save')
                ->assertHasErrors("form.{$details}");
        }

        $this->assertSame(0, Declaration::count());
    }

    public function test_an_unanswered_question_stays_null(): void
    {
        // Unanswered and "no" are different things on this form, and the
        // completeness check has to tell them apart.
        Livewire::actingAs($this->user)
            ->test('pages::pds.declarations')
            ->set('form.q34_related_third_degree', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Declaration::first()->q40_solo_parent);
    }

    public function test_saving_twice_updates_rather_than_duplicates(): void
    {
        foreach (['Dismissed', 'Acquitted'] as $status) {
            Livewire::actingAs($this->user)
                ->test('pages::pds.declarations')
                ->set('form.q35_criminally_charged', true)
                ->set('form.q35_criminal_details', 'A case')
                ->set('form.q35_case_status', $status)
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(1, Declaration::count());
        $this->assertSame('Acquitted', Declaration::first()->q35_case_status);
    }

    public function test_three_reference_rows_are_offered_and_blank_ones_are_not_saved(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.declarations')
            ->assertCount('references', 3)
            ->set('references.0.name', 'Cecilia Burre')
            ->set('references.0.address', 'Surigao City')
            ->set('references.0.telephone_no', '09171234567')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Reference::where('employee_id', $this->employee->id)->count());
        $this->assertSame('Cecilia Burre', Reference::first()->name);
    }

    public function test_references_keep_their_order(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::pds.declarations')
            ->set('references.0.name', 'First')
            ->set('references.1.name', 'Second')
            ->set('references.2.name', 'Third')
            ->call('removeReference', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['First', 'Third'],
            Reference::where('employee_id', $this->employee->id)->orderBy('sort_order')->pluck('name')->all()
        );
    }

    public function test_a_tampered_reference_row_id_is_refused(): void
    {
        $theirs = Reference::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'name' => 'Theirs',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.declarations')
            ->set('references.0.id', $theirs->id)
            ->set('references.0.name', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Theirs', $theirs->refresh()->name);
    }

    public function test_an_existing_declaration_is_loaded_into_the_form(): void
    {
        Declaration::factory()->create([
            'employee_id' => $this->employee->id,
            'q40_solo_parent' => true,
            'q40_solo_parent_id_no' => 'SP-0042',
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::pds.declarations')
            ->assertSet('form.q40_solo_parent', true)
            ->assertSet('form.q40_solo_parent_id_no', 'SP-0042');
    }
}
