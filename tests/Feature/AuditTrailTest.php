<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditRecorder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_editing_an_employee_records_the_old_and_the_new_value(): void
    {
        $hr = $this->userWithRole('hr');
        $this->actingAs($hr);

        $employee = Employee::factory()->create(['last_name' => 'Dela Cruz']);
        $employee->update(['last_name' => 'Dela Cruz-Reyes']);

        $activity = Activity::where('subject_type', Employee::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($hr->id, $activity->causer_id);
        $this->assertSame('Dela Cruz', $activity->attribute_changes['old']['last_name']);
        $this->assertSame('Dela Cruz-Reyes', $activity->attribute_changes['attributes']['last_name']);
    }

    public function test_only_the_changed_field_is_recorded(): void
    {
        // Without logOnlyDirty every save writes all fourteen columns and the
        // log becomes unreadable inside a month.
        $this->actingAs($this->userWithRole('hr'));

        $employee = Employee::factory()->create();
        $employee->update(['last_name' => 'Changed']);

        $activity = Activity::where('event', 'updated')->latest('id')->first();

        $this->assertSame(['last_name'], array_keys($activity->attribute_changes['attributes']));
    }

    public function test_a_save_that_changes_nothing_writes_no_entry(): void
    {
        $this->actingAs($this->userWithRole('hr'));

        $employee = Employee::factory()->create();
        $before = Activity::count();

        $employee->update(['last_name' => $employee->last_name]);

        $this->assertSame($before, Activity::count());
    }

    public function test_deleting_an_employee_is_recorded(): void
    {
        $this->actingAs($this->userWithRole('hr'));

        Employee::factory()->create()->delete();

        $this->assertTrue(Activity::where('event', 'deleted')->exists());
    }

    public function test_a_read_is_recorded_with_its_causer(): void
    {
        $hr = $this->userWithRole('hr');
        $this->actingAs($hr);

        $employee = Employee::factory()->create();

        app(AuditRecorder::class)->recordRead($employee, 'Opened the employee record');

        $activity = Activity::where('event', 'read')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($employee->id, $activity->subject_id);
        $this->assertSame($hr->id, $activity->causer_id);
        $this->assertSame('Opened the employee record', $activity->description);
    }

    public function test_an_employee_cannot_open_the_audit_log(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get(route('audit.index'))
            ->assertForbidden();
    }

    public function test_hr_can_open_the_audit_log(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('audit.index'))
            ->assertOk();
    }

    public function test_the_log_shows_who_changed_what(): void
    {
        $hr = $this->userWithRole('hr');
        $hr->update(['name' => 'Cecilia Burre']);
        $this->actingAs($hr);

        Employee::factory()->create(['last_name' => 'Dela Cruz'])
            ->update(['last_name' => 'Dela Cruz-Reyes']);

        $this->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Cecilia Burre')
            ->assertSee('Dela Cruz-Reyes');
    }
}
