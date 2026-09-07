<?php

namespace Tests\Feature\Employees;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IssueLoginsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_it_builds_the_address_from_the_first_and_last_name(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Danly',
            'last_name' => 'Madelo',
            'user_id' => null,
        ]);

        $this->artisan('employees:issue-logins', ['--force' => true])->assertSuccessful();

        $this->assertSame('danlymadelo@gmail.com', $employee->fresh()->user->email);
    }

    public function test_it_drops_spaces_punctuation_and_accents(): void
    {
        // "Mary Jane" and "Lao Guico" are two words each, and O'Brien has an
        // apostrophe. None of them can appear in the local part of an address.
        $employees = collect([
            ['Mary Jane', 'Lao Guico', 'maryjanelaoguico@gmail.com'],
            ['Ma. Concepción', "O'Brien-Cruz", 'maconcepcionobriencruz@gmail.com'],
        ])->map(fn ($row) => [
            Employee::factory()->create(['first_name' => $row[0], 'last_name' => $row[1], 'user_id' => null]),
            $row[2],
        ]);

        $this->artisan('employees:issue-logins', ['--force' => true])->assertSuccessful();

        foreach ($employees as [$employee, $expected]) {
            $this->assertSame($expected, $employee->fresh()->user->email);
        }
    }

    public function test_two_people_with_the_same_name_do_not_get_the_same_address(): void
    {
        // In a hospital of 134 this is not a corner case. Without it the second
        // Juan Cruz collides with the first and the whole run stops.
        Employee::factory()->count(2)->create([
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'user_id' => null,
        ]);

        $this->artisan('employees:issue-logins', ['--force' => true])->assertSuccessful();

        $emails = User::pluck('email')->sort()->values()->all();

        $this->assertSame(['juancruz2@gmail.com', 'juancruz@gmail.com'], $emails);
    }

    public function test_it_steps_around_an_address_that_is_already_taken(): void
    {
        User::factory()->create(['email' => 'juancruz@gmail.com']);

        $employee = Employee::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'user_id' => null,
        ]);

        $this->artisan('employees:issue-logins', ['--force' => true])->assertSuccessful();

        $this->assertSame('juancruz2@gmail.com', $employee->fresh()->user->email);
    }

    public function test_everybody_has_to_choose_their_own_password_before_going_anywhere(): void
    {
        // The whole reason a shared temporary password is survivable: such an
        // account reaches the change-password page and nothing else.
        $employee = Employee::factory()->create(['user_id' => null]);

        $this->artisan('employees:issue-logins', ['--force' => true])->assertSuccessful();

        $user = $employee->fresh()->user;

        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertTrue($user->hasRole('employee'));
    }

    public function test_it_leaves_alone_anybody_who_already_has_a_login(): void
    {
        $existing = User::factory()->create(['email' => 'someone@dtrc.local']);
        $employee = Employee::factory()->create(['user_id' => $existing->id]);

        $this->artisan('employees:issue-logins', ['--force' => true])->assertSuccessful();

        $this->assertSame('someone@dtrc.local', $employee->fresh()->user->email);
        $this->assertSame(1, User::count());
    }

    public function test_it_leaves_out_employees_who_are_no_longer_here(): void
    {
        Employee::factory()->create(['user_id' => null, 'is_active' => false]);

        $this->artisan('employees:issue-logins', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_it_says_so_when_there_is_nobody_left_to_issue(): void
    {
        $this->artisan('employees:issue-logins', ['--force' => true])
            ->expectsOutputToContain('Everybody already has a login')
            ->assertSuccessful();
    }

    public function test_the_temporary_password_can_be_something_else(): void
    {
        $employee = Employee::factory()->create(['user_id' => null]);

        $this->artisan('employees:issue-logins', ['--password' => 'dtrc-2026', '--force' => true])
            ->assertSuccessful();

        $this->assertTrue(Hash::check('dtrc-2026', $employee->fresh()->user->password));
    }

    public function test_it_asks_before_writing(): void
    {
        Employee::factory()->create(['user_id' => null]);

        $this->artisan('employees:issue-logins')
            ->expectsConfirmation('Issue 1 login?', 'no')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_it_writes_the_slips_to_a_file_when_asked(): void
    {
        // 130 rows scrolling past in a terminal is not a list anybody can hand
        // out. The file is what gets printed and cut up.
        $path = sys_get_temp_dir().'/hris-slips-'.uniqid().'.csv';

        Employee::factory()->create([
            'first_name' => 'Danly',
            'last_name' => 'Madelo',
            'employee_number' => '2022-0012',
            'user_id' => null,
        ]);

        $this->artisan('employees:issue-logins', ['--slips' => $path, '--force' => true])
            ->assertSuccessful();

        $slips = file_get_contents($path);

        $this->assertStringContainsString('2022-0012', $slips);
        $this->assertStringContainsString('danlymadelo@gmail.com', $slips);
        $this->assertStringContainsString('password', $slips);

        unlink($path);
    }
}
