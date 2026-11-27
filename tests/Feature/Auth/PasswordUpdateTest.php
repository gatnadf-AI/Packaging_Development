<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * FSD-104 / V-102 — the voluntary change on the profile screen.
 *
 * The point of these tests is that this door is as strong as the forced one:
 * same rule set, same refusal of a weak or identity-shaped password, and the
 * current secret still has to be produced before the new one is written.
 */
class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'KemasanBaru2026';

    public function test_a_compliant_password_replaces_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_the_current_password_has_to_be_produced_first(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_the_profile_door_is_as_strong_as_the_forced_one(): void
    {
        $user = User::factory()->create();

        $weak = [
            'kemasanbaru2026' => 'mixed case',
            'KemasanBaruHelai' => 'a digit',
            'Kemasan26' => 'ten characters',
        ];

        foreach ($weak as $password => $clause) {
            $this->actingAs($user)
                ->from('/profile')
                ->put('/password', [
                    'current_password' => 'password',
                    'password' => $password,
                    'password_confirmation' => $password,
                ])
                ->assertSessionHasErrors('password');

            $this->assertTrue(Hash::check('password', $user->refresh()->password), $clause);
        }
    }

    public function test_a_password_that_is_the_email_local_part_is_refused_here_too(): void
    {
        $user = User::factory()->create([
            'email' => 'rndstaf1satu2026@ppm.test',
        ]);

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'RndStaf1Satu2026',
                'password_confirmation' => 'RndStaf1Satu2026',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => 'KemasanBaru2027',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_the_voluntary_change_keeps_the_session_that_made_it(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        // Carried by hand: the test client does not replay a response cookie,
        // and without it the following PUT would speak from a session that owns
        // no row, so the comparison below would prove nothing.
        $ownId = (string) app('session.store')->getId();
        $this->withCookies([config('session.cookie') => $ownId]);

        $before = DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();
        $this->assertNotEmpty($before, 'A real login should leave a claimed session row.');
        $this->assertSame([$ownId], $before);

        $this->put('/password', [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $after = DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();

        // The forced change closes the other sessions because the previous
        // secret was known to somebody else. This one is the user's own
        // decision, taken from inside the session; revoking the devices that
        // are not present would be a side effect nobody asked for.
        $this->assertSame($before, $after);

        $this->get('/dashboard')->assertOk();
    }
}
