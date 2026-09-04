<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_administrator_who_can_sign_in(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('hris:create-admin', ['--name' => 'Dan Madelo', '--email' => 'admin@dtrc.local'])
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm the password', 'correct-horse-battery')
            ->assertSuccessful();

        $user = User::where('email', 'admin@dtrc.local')->sole();

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->can('employees.manage'));

        // Without this the account never gets past the `verified` middleware,
        // and there is no mail server on the LAN to release it.
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->must_change_password);
    }

    public function test_the_password_must_be_typed_twice_the_same_way(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('hris:create-admin', ['--name' => 'Dan', '--email' => 'admin@dtrc.local'])
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm the password', 'correct-horse-batteru')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('hris:create-admin', ['--name' => 'Dan', '--email' => 'admin@dtrc.local'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm the password', 'short')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_an_address_that_is_already_taken(): void
    {
        $this->seed(RoleSeeder::class);

        User::factory()->create(['email' => 'admin@dtrc.local']);

        $this->artisan('hris:create-admin', ['--name' => 'Dan', '--email' => 'admin@dtrc.local'])
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm the password', 'correct-horse-battery')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_it_refuses_to_run_before_the_roles_are_seeded(): void
    {
        // An admin role created on the fly would carry no permissions: an
        // account that signs in and can do nothing.
        // It stops before asking for anything, so there is nothing to type.
        $this->artisan('hris:create-admin', ['--name' => 'Dan', '--email' => 'admin@dtrc.local'])
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_password_is_hashed_and_not_stored_as_typed(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('hris:create-admin', ['--name' => 'Dan', '--email' => 'admin@dtrc.local'])
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm the password', 'correct-horse-battery')
            ->assertSuccessful();

        $this->assertNotSame(
            'correct-horse-battery',
            User::where('email', 'admin@dtrc.local')->sole()->password
        );
    }
}
