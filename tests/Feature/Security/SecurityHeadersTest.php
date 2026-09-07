<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_carries_the_headers(): void
    {
        // On the login page above all: it is the one screen a stranger on the
        // LAN can reach without an account.
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_the_page_cannot_be_framed(): void
    {
        // Two ways of saying it, because the old header is what older browsers
        // read and frame-ancestors is what the current ones read. A PDS inside
        // an invisible frame on another page is how clickjacking starts.
        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_the_policy_pins_down_where_a_form_can_post_and_what_the_base_url_is(): void
    {
        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_the_policy_does_not_claim_to_restrict_scripts(): void
    {
        // Livewire ships Alpine, and Alpine evaluates its expressions at
        // runtime, so any script-src this app could actually run under would
        // need unsafe-eval and unsafe-inline. Such a policy blocks nothing and
        // reads on a report as though scripts were locked down. Better to have
        // no script-src than a false one.
        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('script-src', $csp);
        $this->assertStringNotContainsString('default-src', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_the_camera_and_microphone_are_turned_off(): void
    {
        $policy = $this->get(route('login'))->headers->get('Permissions-Policy');

        $this->assertStringContainsString('camera=()', $policy);
        $this->assertStringContainsString('microphone=()', $policy);
        $this->assertStringContainsString('geolocation=()', $policy);
    }

    public function test_it_does_not_promise_https_over_a_plain_connection(): void
    {
        // Sent over http, HSTS is ignored by every browser. Sending it anyway
        // would leave a header on the response that looks like protection and
        // is not.
        //
        // The scheme is written out rather than taken from route(): with
        // APP_FORCE_HTTPS set in the environment every generated URL is https,
        // and this test would pass or fail on a setting that has nothing to do
        // with what it is checking.
        $this->get('http://hris.test'.route('login', absolute: false))
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_it_promises_https_once_the_connection_is_secure(): void
    {
        $response = $this->get('https://hris.test'.route('login', absolute: false));

        $this->assertStringContainsString('max-age=', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_the_headers_reach_a_signed_in_page_too(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
