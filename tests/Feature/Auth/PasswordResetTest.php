<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TSD-1104 (FSD-104) — the reset flow, the one door here that an outsider can
 * knock on without an account.
 *
 * Three properties are worth naming in tests: the answer cannot reveal whether
 * the address exists, a token is valid for sixty minutes and one use only, and
 * the password it installs is held to the same rule as every other writer.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'KemasanBaru2026';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_the_forgot_password_screen_renders(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_a_known_address_is_sent_a_link(): void
    {
        $user = User::factory()->create();

        $this->from(route('password.request'))->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_answer_is_the_same_whether_or_not_the_address_exists(): void
    {
        $user = User::factory()->create();

        $known = $this->from(route('password.request'))
            ->post('/forgot-password', ['email' => $user->email]);

        $statusKnown = session('status');

        $unknown = $this->from(route('password.request'))
            ->post('/forgot-password', ['email' => 'tidak.ada@ppm.test']);

        $statusUnknown = session('status');

        foreach ([$known, $unknown] as $response) {
            $response->assertRedirect(route('password.request'));
            $response->assertSessionHasNoErrors();
        }

        $this->assertSame(trans('passwords.neutral'), $statusKnown);
        $this->assertSame($statusKnown, $statusUnknown);
        $this->assertStringNotContainsString($user->email, (string) $statusUnknown);

        // One notification for the real account, none for the other question.
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_a_malformed_address_is_a_form_error_rather_than_an_answer(): void
    {
        session()->forget('status');

        $this->from(route('password.request'))
            ->post('/forgot-password', ['email' => 'bukan-email'])
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('password.request'));

        $this->assertNull(session('status'), 'A rejected form must not flash the neutral sentence, or the two answers become one.');
    }

    public function test_the_reset_screen_renders_with_the_token_from_the_notification(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) {
            $this->get('/reset-password/'.$notification->token)->assertOk();

            return true;
        });
    }

    public function test_a_valid_token_installs_a_compliant_password_and_is_then_gone(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

            $this->assertSame(trans('passwords.reset'), session('status'));

            return true;
        });

        $user->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_the_reset_response_never_names_the_account(): void
    {
        $user = User::factory()->create(['name' => 'RnD Staf Satu']);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

            foreach (session('errors')?->getBags() ?? [] as $bag) {
                foreach ($bag->all() as $message) {
                    $this->assertStringNotContainsString($user->email, $message);
                    $this->assertStringNotContainsString($user->name, $message);
                }
            }

            return true;
        });
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'below ten characters' => ['Kemasan26'],
            'no uppercase' => ['kemasanbaru2026'],
            'no digit' => ['KemasanBaruHelai'],
        ];
    }

    #[DataProvider('weakPasswords')]
    public function test_a_valid_token_does_not_bypass_the_strength_rule(string $password): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, $password) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertSessionHasErrors('password');

            return true;
        });

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_a_token_expires_after_sixty_minutes(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->travel(61)->minutes();

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertSessionHasErrors('email');

            $this->assertTrue(Hash::check('password', $user->refresh()->password));

            return true;
        });
    }

    public function test_a_token_is_single_use(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertSessionHasNoErrors();

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'LapisKedua2026',
                'password_confirmation' => 'LapisKedua2026',
            ])->assertSessionHasErrors('email');

            $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
            $this->assertFalse(Hash::check('LapisKedua2026', $user->password));

            return true;
        });
    }

    public function test_a_token_for_one_address_does_not_work_for_another(): void
    {
        $target = User::factory()->create();
        $other = User::factory()->create();

        $this->post('/forgot-password', ['email' => $target->email]);

        Notification::assertSentTo($target, ResetPassword::class, function (ResetPassword $notification) use ($target, $other) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $other->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertSessionHasErrors('email');

            $this->assertTrue(Hash::check('password', $other->refresh()->password));
            $this->assertTrue(Hash::check('password', $target->refresh()->password));

            return true;
        });
    }

    public function test_a_reset_changes_the_secret_and_nothing_else_about_the_account(): void
    {
        $user = User::factory()->inDepartment()->seen()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

            return true;
        });

        $user->refresh();

        // A reset is not a deactivation, a role change, or a first-login gate.
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->last_login_at);

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertRedirect(route('dashboard', absolute: false));
    }
}
