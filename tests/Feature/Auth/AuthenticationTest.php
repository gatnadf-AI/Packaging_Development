<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * FSD-101 with FSD-201, FSD-202 and TSD-1105.
 *
 * The login screen is the only way in, so these tests are about what the door
 * refuses as much as what it accepts: no self-service account, no session for a
 * deactivated person, one sentence for both kinds of wrong credential, and a
 * session row that knows whose it is — which is what makes the sessions screen
 * in FSD-105 possible at all.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_there_is_no_self_service_registration(): void
    {
        // FSD-201: accounts are created by an administrator, so the route that
        // would let anyone make one must not exist, not merely be unlinked.
        $this->post('/register', [
            'name' => 'Siapa saja',
            'email' => 'siapa.saja@example.test',
            'password' => 'RahasiaKuat1',
            'password_confirmation' => 'RahasiaKuat1',
        ])->assertNotFound();

        $this->get('/register')->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'siapa.saja@example.test']);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_a_successful_login_records_the_time_and_owns_its_session_row(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertNotNull($user->refresh()->last_login_at);

        // TSD-1103 with FSD-105: without this column the "your other sessions"
        // list would have to decrypt every row on the host to answer the question.
        $this->assertSame(
            1,
            DB::table('sessions')->where('user_id', $user->id)->count(),
            'the session row has to be stamped at login, not at the end of the request'
        );
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();

        // MSG-001: one sentence, and it never says which half was wrong.
        $response->assertSessionHasErrors([
            'email' => 'Email atau password tidak sesuai.',
        ]);
    }

    public function test_an_unknown_address_and_a_wrong_password_answer_identically(): void
    {
        // Both addresses are on a corporate domain: V-101 refuses an address
        // outside the list with its own sentence before any credential is looked
        // at, and that is a statement about the domain rather than about whether
        // this person has an account. The enumeration question this test asks is
        // only the one inside the domain, so both sides of it stay inside.
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'salah']);
        $known = $this->loginErrors();

        $this->post('/login', ['email' => 'tidak.ada@ppm.test', 'password' => 'salah']);
        $unknown = $this->loginErrors();

        $this->assertSame($known, $unknown, 'the login screen must not be a directory of accounts');
        $this->assertSame(['Email atau password tidak sesuai.'], $known, 'MSG-001 verbatim');
    }

    public function test_a_deactivated_account_is_refused_even_with_the_right_password(): void
    {
        // FSD-202: is_active blocks login. The wording is the ordinary failure,
        // so a disabled account cannot be confirmed by probing it.
        $user = User::factory()->inactive()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_an_open_session_ends_when_the_account_is_deactivated(): void
    {
        // The thirty-minute expiry is not a security boundary; an administrator
        // turning an account off expects it to stop, not to fade out.
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Akun Anda sudah tidak aktif. Hubungi administrator.');

        $this->assertGuest();
    }

    public function test_five_failures_then_the_right_answer_is_still_locked_out(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'salah'.$attempt]);
        }

        // TSD-1105: ten minutes, and a lockout that accepts the correct
        // credential during the window would not be a lockout.
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();

        $messages = $this->loginErrors();

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('menit', $messages[0]);
        $this->assertNotSame('Email atau password tidak sesuai.', $messages[0]);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_the_address_is_lowercased_and_trimmed_before_the_lookup(): void
    {
        // V-101. The stored address is lowercase; a person typing it with the
        // caps lock on, or with a space from a copied email, is the same person.
        $user = User::factory()->create(['email' => 'rina.kartika@ppm.test']);

        $this->post('/login', [
            'email' => "  Rina.Kartika@PPM.TEST \n",
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_password_is_taken_exactly_as_it_was_typed(): void
    {
        // V-101 normalises the address so that one person is one identity. The
        // secret is the opposite: its case is kept, its spaces are kept, and a
        // guess that differs by either is simply wrong. The framework's trimming
        // middleware is written to leave password fields alone, which is what
        // makes that symmetric — a password set with a space in it can only be
        // typed back with that space in it.
        $user = User::factory()->create(['password' => 'Kemasan 2026']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Kemasan 2026'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        foreach (['kemasan 2026', 'Kemasan2026', ' Kemasan 2026', 'Kemasan 2026 '] as $guess) {
            $this->post('/logout');
            $this->flushSession();

            $this->post('/login', ['email' => $user->email, 'password' => $guess])
                ->assertSessionHasErrors('email');

            $this->assertGuest();
        }
    }

    public function test_a_privileged_account_does_not_get_a_persistent_session(): void
    {
        // V-101 field note: 30 days, disabled when the role holds admin.access.
        // A stolen laptop with an administrator's cookie on it is the exact
        // thing this rule is written against, so the refusal is server-side and
        // not a checkbox the browser hides.
        $this->seedReferenceData();

        $cases = [
            'admin' => User::factory()->role('admin')->create(),
            'rnd_staff' => User::factory()->role('rnd_staff')->create(),
        ];

        foreach ($cases as $slug => $user) {
            $response = $this->post('/login', [
                'email' => $user->email,
                'password' => 'password',
                'remember' => true,
            ])->assertRedirect(route('dashboard', absolute: false));

            // The observable fact is the recaller cookie: that is what a browser
            // keeps and what makes a session survive a restart. The remember
            // token in the table is not a signal here — the framework only
            // mints one when the account has none, so a ticking box can leave it
            // exactly as it was.
            $setCookie = implode("\n", $response->headers->all()['set-cookie'] ?? []);

            if ($slug === 'admin') {
                $this->assertStringNotContainsString(
                    'remember_web_',
                    $setCookie,
                    'admin.access must not persist a session'
                );
            } else {
                $this->assertStringContainsString(
                    'remember_web_',
                    $setCookie,
                    'a ticked box should be honoured for everyone else'
                );
            }

            $this->post('/logout');
            $this->flushSession();
        }
    }

    public function test_an_expired_session_is_announced_rather_than_implied(): void
    {
        // MSG-005. The idle timer in the shell sends the visit here with the
        // flag; "Anda sudah keluar" (the logout message) would be the wrong
        // sentence for a session that ran out on its own.
        $response = $this->get('/login?expired=1');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('expired', true));

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('expired', false));
    }

    /**
     * @return list<string>
     */
    private function loginErrors(): array
    {
        return app('session')->get('errors')?->getBag('default')->get('email') ?? [];
    }
}
