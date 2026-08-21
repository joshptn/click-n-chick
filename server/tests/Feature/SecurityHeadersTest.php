<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Production security headers on API responses.
 *
 * The SPA carries its own Content-Security-Policy - it is the thing that loads
 * scripts, fonts and images. The API's job is narrower and stricter: it returns
 * JSON, so it declares that nothing may be loaded from it at all.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_the_baseline_headers(): void
    {
        $response = $this->getJson('/api/foods')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
    }

    public function test_the_api_denies_every_content_source(): void
    {
        $csp = $this->getJson('/api/foods')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("form-action 'none'", $csp);
    }

    /** Error responses are responses too - and are the ones that echo input. */
    public function test_error_responses_are_covered(): void
    {
        $response = $this->getJson('/api/user')->assertUnauthorized();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_authenticated_responses_are_covered(): void
    {
        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/user')
            ->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    /**
     * HSTS over plain http would be ignored by browsers anyway, and emitting it
     * in local development pins the developer's own machine to https for a
     * year - a genuinely painful thing to undo.
     */
    public function test_hsts_is_absent_over_plain_http(): void
    {
        $this->getJson('/api/foods')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_present_over_https(): void
    {
        $response = $this->getJson('https://localhost/api/foods')->assertOk();

        $this->assertStringContainsString(
            'max-age=31536000',
            (string) $response->headers->get('Strict-Transport-Security')
        );
    }

    /** The stock welcome page is not JSON and must not get the API's policy. */
    public function test_the_non_api_root_is_not_given_the_api_policy(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }
}
