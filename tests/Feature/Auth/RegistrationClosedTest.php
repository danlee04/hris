<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Anyone on the hospital LAN could otherwise create an account and reach the
 * inside of the system. What this system will hold is TIN, home address, and
 * the answers to PDS items 34-40. Registration closes before any of that is
 * built; an admin issues every login by hand.
 */
class RegistrationClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));
    }

    public function test_the_registration_endpoint_cannot_be_reached(): void
    {
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }

    public function test_no_account_was_created(): void
    {
        $this->post('/register', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'walkin@example.com']);
    }

    public function test_the_login_page_still_renders(): void
    {
        // The login page linked to route('register'). If that link survives,
        // this page throws and nobody can sign in at all.
        $this->get(route('login'))->assertOk();
    }
}
