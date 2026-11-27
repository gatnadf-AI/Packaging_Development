<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * TSD-1107 — the headers the security section promises, asserted as response
 * facts rather than as a middleware that exists.
 *
 * The CSP is production-only (the Vite dev server legitimately loads scripts
 * from a second origin), so it gets its own case with the environment forced,
 * and an explicit assertion that it is absent in development instead of a
 * silently skipped check.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_the_three_always_on_headers_are_on_every_page(): void
    {
        foreach (['/login', '/forgot-password'] as $path) {
            $response = $this->get($path);

            $response->assertOk();
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'same-origin');
        }
    }

    public function test_the_content_policy_arrives_with_a_production_build(): void
    {
        $this->app->instance('env', 'production');

        $policy = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertIsString($policy);

        foreach (["default-src 'self'", "frame-ancestors 'none'", "form-action 'self'"] as $rule) {
            $this->assertStringContainsString($rule, $policy);
        }

        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringNotContainsString('bunny.net', $policy);
    }

    public function test_the_content_policy_is_not_claimed_in_development(): void
    {
        $this->assertSame('testing', app()->environment());

        $this->get('/login')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_a_secure_request_also_carries_the_transport_policy(): void
    {
        // The absolute https URL, not an HTTPS server variable: Request::create()
        // derives that flag from the URI and drops anything passed in $server,
        // so the URL is the only honest way to say "this arrived over TLS".
        $response = $this->get('https://localhost/login');

        $this->assertTrue($this->app['request']->isSecure(), 'the request has to actually be secure');

        $response->assertHeader(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains'
        );
    }

    public function test_an_insecure_request_does_not_promise_hsts(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_csrf_token_is_available_to_the_inertia_page(): void
    {
        // The React side reads the meta tag; without it every Inertia POST has
        // to borrow a token from a previous response.
        $this->get('/login')
            ->assertSee('name="csrf-token"', false);
    }
}
