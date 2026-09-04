<?php

namespace Tests\Feature\Pds;

use App\Models\Employee;
use App\Models\Pds\PersonalInformation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PdsDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->owner = User::factory()->create();
        $this->owner->assignRole('employee');
        $this->employee = Employee::factory()->create(['user_id' => $this->owner->id]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_an_employee_opens_their_own_download_page(): void
    {
        $this->actingAs($this->owner)->get(route('pds.export'))->assertOk();
    }

    public function test_an_employee_cannot_open_another_download_page(): void
    {
        $someoneElse = Employee::factory()->create();

        $this->actingAs($this->owner)
            ->get(route('pds.export', ['employee' => $someoneElse->id]))
            ->assertForbidden();
    }

    public function test_hr_can_open_anyones_download_page(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('pds.export', ['employee' => $this->employee->id]))
            ->assertOk();
    }

    public function test_downloading_returns_the_workbook(): void
    {
        PersonalInformation::factory()->create(['employee_id' => $this->employee->id]);

        $response = Livewire::actingAs($this->owner)
            ->test('pages::pds.export')
            ->call('download');

        $response->assertFileDownloaded();
    }

    public function test_a_tampered_employee_id_cannot_redirect_a_download(): void
    {
        // mount() authorised one employee. The property is rehydrated from the
        // browser on every later request, so the download asks again.
        $someoneElse = Employee::factory()->create();

        Livewire::actingAs($this->owner)
            ->test('pages::pds.export')
            ->set('employeeId', $someoneElse->id)
            ->call('download')
            ->assertForbidden();
    }

    public function test_downloading_somebody_elses_pds_is_recorded(): void
    {
        // The whole record leaves the system in one file. That is worth more of
        // a record than reading one section on screen.
        $hr = $this->userWithRole('hr');

        Livewire::actingAs($hr)
            ->withQueryParams(['employee' => $this->employee->id])
            ->test('pages::pds.export')
            ->call('download');

        $activity = Activity::where('event', 'read')
            ->where('description', 'like', '%Downloaded%')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($hr->id, $activity->causer_id);
        $this->assertSame($this->employee->id, $activity->subject_id);
    }

    public function test_downloading_your_own_pds_is_not_recorded(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::pds.export')
            ->call('download');

        $this->assertSame(
            0,
            Activity::where('description', 'like', '%Downloaded%')->count()
        );
    }

    public function test_an_incomplete_pds_warns_but_still_downloads(): void
    {
        // HR asks for a half-filled PDS often enough — for a promotion paper,
        // for a personnel action. Refusing would send them back to retyping it.
        $this->actingAs($this->owner)
            ->get(route('pds.export'))
            ->assertOk()
            ->assertSee('sections are still empty');

        Livewire::actingAs($this->owner)
            ->test('pages::pds.export')
            ->call('download')
            ->assertFileDownloaded();
    }

    public function test_the_download_is_offered_on_the_employees_own_page_not_the_list(): void
    {
        // Taking somebody's whole record out of the system is a deliberate act,
        // and a link in a list of 134 rows is an easy one. Reaching the page
        // means HR has already said whose record they meant.
        $hr = $this->userWithRole('hr');
        $link = route('pds.export', ['employee' => $this->employee->id]);

        $this->actingAs($hr)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee($link, escape: false);

        $this->actingAs($hr)
            ->get(route('employees.show', ['employee' => $this->employee->id]))
            ->assertOk()
            ->assertSee($link, escape: false);
    }

    public function test_the_download_button_sits_at_the_top_of_every_section(): void
    {
        // It used to be the last tab. An employee filling in their PDS wants
        // the file, not the last tab.
        $this->actingAs($this->owner)
            ->get(route('pds.personal-information'))
            ->assertSee('Download PDS')
            // Ahead of the tab bar, which is what "at the top" has to mean.
            ->assertSeeInOrder(['Download PDS', 'Education']);
    }

    public function test_the_download_button_carries_the_employee_hr_is_looking_at(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('pds.personal-information', ['employee' => $this->employee->id]))
            ->assertSee(route('pds.export', ['employee' => $this->employee->id]), escape: false);
    }
}
