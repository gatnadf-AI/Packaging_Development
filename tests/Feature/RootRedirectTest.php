<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FSD-201 (FR-101) — the system has no public landing page and no public door.
 *
 * The root address is the one URL everyone types, so what it does is a
 * statement about the product: a visitor is pointed at the login screen, an
 * account is pointed at its own workspace, and an account still locked to its
 * first password is pointed at the change screen rather than past it.
 */
final class RootRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_is_sent_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_there_is_no_public_welcome_page(): void
    {
        $this->get('/welcome')->assertNotFound();
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
        $this->get('/email/verify')->assertNotFound();
    }

    public function test_an_account_is_sent_to_its_own_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_an_account_locked_to_its_first_password_does_not_get_past_the_gate(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard', absolute: false));
        $this->get('/dashboard')->assertRedirect('/password/change');
    }

    public function test_a_deactivated_account_losing_its_session_cannot_reach_anything(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('auth.account_inactive'));

        $this->assertGuest();
        $this->get('/profile')->assertRedirect(route('login'));
    }
}
