<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * FSD-104 (V-102) — the gate between an administrator-set password and a
 * usable account.
 *
 * The important property is that the gate is not a hint: an account that has
 * not changed its password cannot read a dashboard, cannot open a record, and
 * cannot even be tested by guessing that nobody would notice. Each route the
 * phase adds is covered by the same middleware, so this file asserts the
 * behaviour once, on the route that exists today.
 */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_account_still_on_its_first_password_is_sent_to_the_change_screen(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('password.change'));
    }

    public function test_the_change_screen_is_reachable_and_the_logout_button_is_too(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->get('/password/change')->assertOk();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_weak_password_is_refused_with_the_documented_rule(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        // Eight characters, all lowercase: fails length, mixed case and digit.
        $response = $this->actingAs($user)->post('/password/change', [
            'password' => 'rahasia1',
            'password_confirmation' => 'rahasia1',
        ]);

        $response->assertSessionHasErrors('password');

        $this->assertTrue($user->refresh()->must_change_password);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_a_password_equal_to_the_email_local_part_is_refused(): void
    {
        $user = User::factory()->mustChangePassword()
            ->create(['email' => 'RnD.Staf1@ppm.test']);

        $this->actingAs($user)->post('/password/change', [
            'password' => 'rnd.staf1',
            'password_confirmation' => 'rnd.staf1',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->refresh()->must_change_password, 'V-102: the address itself is not a secret');
    }

    public function test_a_password_with_a_capital_and_a_digit_but_too_short_is_refused(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->post('/password/change', [
            'password' => 'Rahasia1',
            'password_confirmation' => 'Rahasia1',
        ])->assertSessionHasErrors('password');
    }

    public function test_a_deep_link_survives_the_gate(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        // The shape of an invitation link: a guest aims at a page, signs in,
        // and the gate stands up in between. FSD-104 says the flow continues
        // after the change rather than dumping the person on the dashboard.
        $this->get('/profile')->assertRedirect(route('login'));

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/profile');

        $this->get('/profile')->assertRedirect('/password/change');

        $this->post('/password/change', [
            'password' => 'KemasanBaru2026',
            'password_confirmation' => 'KemasanBaru2026',
        ])->assertRedirect('/profile');

        $this->get('/profile')->assertOk();

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('KemasanBaru2026', $user->password));
    }

    public function test_a_target_on_another_host_is_never_followed(): void
    {
        $user = User::factory()->create();

        // A crafted absolute intended URL must not turn the post-change
        // redirect into an exit from the system.
        $this->actingAs($user)
            ->withSession(['url.intended' => 'http://example.test/steal'])
            ->post('/password/change', [
                'password' => 'KemasanBaru2026',
                'password_confirmation' => 'KemasanBaru2026',
            ])
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_the_mismatched_confirmation_is_refused_rather_than_silently_accepted(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->post('/password/change', [
            'password' => 'KemasanBaru2026',
            'password_confirmation' => 'KemasanBaru2027',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->refresh()->must_change_password);
    }
}
