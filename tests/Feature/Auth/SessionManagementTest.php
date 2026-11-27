<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Access\SessionInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FSD-105 (and the FSD-104 and FSD-202 consequences of it) — the session list
 * a person sees is theirs, live, and only theirs, and the row they are typing
 * on can be seen but not closed.
 *
 * A real login is used rather than actingAs() wherever the current session
 * matters: only the login listener stamps user_id onto the session row, so it
 * is the only way to get a fixture that looks like production. And because the
 * test client does not replay cookies, the login helper hands the session
 * cookie back to the harness — otherwise every following request would arrive
 * from a device that has no row at all, which is precisely the case this screen
 * must refuse.
 */
class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_ID = 'other-device-session-id';

    /**
     * The id of the session this test is speaking from, once a login happened.
     *
     * Laravel's test client does not replay a response's session cookie, so
     * every request after the login would otherwise open a brand new session
     * whose id no one claimed — and "the device in use" would then be a row
     * that does not exist, which is not what a browser ever sends. Carrying the
     * id forward is what makes the current-session rules testable at all.
     */
    private string $ownId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('database', config('session.driver'), 'These tests are only meaningful against the sessions table.');
    }

    private function login(User $user): void
    {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->ownId = (string) app('session.store')->getId();

        $this->withCookies([config('session.cookie') => $this->ownId]);

        // The login listener has to have stamped this very id onto the table;
        // that is the whole basis of the list screen in FSD-105.
        $this->assertSame([$this->ownId], $this->claimedIds($user));
    }

    /**
     * @return list<string>
     */
    private function claimedIds(User $user): array
    {
        return DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedSession(int $userId, string $id, array $attributes = []): void
    {
        DB::table('sessions')->insert(array_merge([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'payload' => 'a:1:{s:6:"_token";s:10:"seededtoken";}',
            'last_activity' => time(),
        ], $attributes));
    }

    private function currentSessionId(User $user): string
    {
        $this->assertSame(
            [$this->ownId],
            $this->claimedIds($user),
            'A single login should leave exactly one claimed session row, and it should be the one this request speaks from.'
        );

        return $this->ownId;
    }

    /**
     * Which row the screen itself calls "this device", read out of the props.
     *
     * @return list<string>
     */
    private function markedCurrent(mixed $response): array
    {
        $sessions = $response->viewData('page')['props']['sessions'] ?? [];

        return array_column(array_filter($sessions, fn (array $s) => (bool) $s['is_current']), 'id');
    }

    /**
     * The ids the screen actually offers, read out of the Inertia props rather
     * than out of the table: the requirement is about the list, and reading the
     * table would let a controller that filtered nothing still pass.
     *
     * @return list<string>
     */
    private function listedIds(mixed $response): array
    {
        $page = $response->viewData('page');

        return array_column($page['props']['sessions'] ?? [], 'id');
    }

    public function test_a_login_leaves_one_claimed_session_row(): void
    {
        $user = User::factory()->create();

        $this->login($user);

        $current = $this->currentSessionId($user);

        $this->assertNotEmpty($current);
        $this->assertSame(
            (int) $user->id,
            (int) DB::table('sessions')->where('id', $current)->value('user_id')
        );
    }

    public function test_the_list_shows_own_live_sessions_and_marks_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->login($user);
        $current = $this->currentSessionId($user);

        $this->seedSession($user->id, self::OTHER_ID, ['last_activity' => time() - 300]);

        $this->get('/profile/sessions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Sessions')
                ->has('sessions', 2)
                ->where('idleMinutes', 30)
                ->where('warnMinutes', 25)
            );

        $response = $this->get('/profile/sessions');

        $listed = $this->listedIds($response);

        $this->assertContains($current, $listed);
        $this->assertContains(self::OTHER_ID, $listed);

        // Exactly one row may be marked as the device in use, because the
        // delete button has to be disabled on it and nowhere else.
        $this->assertSame([$current], $this->markedCurrent($response));
    }

    public function test_a_session_beyond_the_lifetime_is_not_offered_as_active(): void
    {
        $user = User::factory()->create();

        $this->login($user);
        $current = $this->currentSessionId($user);

        // 31 idle minutes against a 30-minute lifetime: the authenticator would
        // not accept this cookie, so the screen must not call it open.
        $this->seedSession($user->id, self::OTHER_ID, ['last_activity' => time() - 31 * 60]);

        $listed = $this->listedIds($this->get('/profile/sessions'));

        $this->assertNotContains(self::OTHER_ID, $listed);
        $this->assertSame([$current], $listed);
    }

    public function test_another_accounts_session_is_never_listed(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();

        $this->login($user);
        $current = $this->currentSessionId($user);
        $this->seedSession($colleague->id, self::OTHER_ID);

        $response = $this->get('/profile/sessions');
        $response->assertOk();

        $this->assertNotContains(self::OTHER_ID, $this->listedIds($response));
        $this->assertSame([$current], $this->listedIds($response));

        $this->assertDatabaseHas('sessions', ['id' => self::OTHER_ID, 'user_id' => $colleague->id]);
    }

    public function test_another_device_can_be_closed_and_the_closing_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->login($user);
        $this->seedSession($user->id, self::OTHER_ID);

        $this->delete('/profile/sessions/'.self::OTHER_ID)
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('sessions', ['id' => self::OTHER_ID]);

        // FSD-105 asks "who revoked, when", so the delete has to leave a row
        // behind that names the account that asked for it.
        $entry = DB::table('audit_entries')->where('action', 'session.revoked')->sole();

        $this->assertSame((int) $user->id, (int) $entry->actor_id);
        $this->assertSame(User::class, $entry->target_type);
        $this->assertSame((int) $user->id, (int) $entry->target_id);
        $this->assertSame(
            ['session_id' => self::OTHER_ID],
            json_decode($entry->after, true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function test_the_device_in_use_cannot_be_closed_from_itself(): void
    {
        $user = User::factory()->create();

        $this->login($user);
        $current = $this->currentSessionId($user);

        $this->delete('/profile/sessions/'.$current)
            ->assertRedirect()
            ->assertSessionHasErrors('session');

        $this->assertDatabaseHas('sessions', ['id' => $current]);

        // A refusal that changed nothing is not a decision, so it must not
        // appear in the history an auditor reads.
        $this->assertSame(0, DB::table('audit_entries')->where('action', 'session.revoked')->count());
    }

    public function test_closing_someone_elses_session_is_refused_with_the_same_answer_as_a_missing_one(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();

        $this->login($user);
        $this->seedSession($colleague->id, self::OTHER_ID);

        $this->delete('/profile/sessions/'.self::OTHER_ID)
            ->assertRedirect()
            ->assertSessionHasErrors('session');

        $this->assertDatabaseHas('sessions', ['id' => self::OTHER_ID, 'user_id' => $colleague->id]);

        // The screen must not become a way to discover which ids exist.
        $this->delete('/profile/sessions/this-id-does-not-exist')
            ->assertSessionHasErrors('session');
    }

    public function test_a_forced_password_change_closes_every_other_session(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        // A reset-password login is a session on the old secret; the forced
        // change is where that knowledge has to stop being enough.
        $this->login($user);

        $current = $this->currentSessionId($user);
        $this->seedSession($user->id, self::OTHER_ID);

        $this->get('/dashboard')->assertRedirect('/password/change');

        $this->post('/password/change', [
            'password' => 'KemasanBaru2026',
            'password_confirmation' => 'KemasanBaru2026',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseMissing('sessions', ['id' => self::OTHER_ID]);
        $this->assertDatabaseHas('sessions', ['id' => $current]);

        // The forced change is the moment an administrator's knowledge of the
        // old secret stops being enough, so it has to be the moment that is
        // written down.
        $entry = DB::table('audit_entries')->where('action', 'session.terminated')->sole();
        $after = json_decode((string) $entry->after, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame((int) $user->id, (int) $entry->actor_id);
        $this->assertSame(1, $after['count']);
        $this->assertSame('password_changed', $after['reason']);
    }

    public function test_the_keep_alive_ping_answers_with_the_timings_the_shell_uses(): void
    {
        // FSD-103 / MSG-004: "Perpanjang" only counts if the server agrees, and
        // the browser needs the new deadline back rather than recomputing its
        // own idea of the lifetime.
        $user = User::factory()->create();

        $this->login($user);

        $response = $this->post('/session/extend');

        $response->assertOk();

        $body = $response->json();

        $this->assertTrue($body['ok']);
        $this->assertSame(30, $body['idle_minutes']);
        $this->assertSame(25, $body['warn_minutes']);
        $this->assertNotEmpty($body['expires_at']);

        // The ping has to be a real touch: the row's last_activity moved, so
        // the next request is inside the window the authenticator applies.
        $touched = DB::table('sessions')->where('user_id', $user->id)->value('last_activity');

        $this->assertGreaterThanOrEqual(time() - 5, (int) $touched);
    }

    public function test_the_keep_alive_ping_cannot_extend_a_session_that_is_gone(): void
    {
        $this->post('/session/extend')->assertRedirect(route('login'));
    }

    public function test_terminating_an_account_removes_every_one_of_its_sessions(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();
        $admin = User::factory()->create();

        $this->login($user);
        $this->seedSession($user->id, self::OTHER_ID);
        $this->seedSession($colleague->id, 'colleague-session-id');

        $removed = $this->app->make(SessionInventory::class)->terminateAllFor($user, $admin);

        $this->assertSame(2, $removed);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'colleague-session-id']);

        // The actor is whoever asked for the termination, not the person whose
        // sessions went with it, and the count is the answer to "how many".
        $entry = DB::table('audit_entries')->where('action', 'session.terminated')->sole();

        $this->assertSame((int) $admin->id, (int) $entry->actor_id);
        $this->assertSame((int) $user->id, (int) $entry->target_id);
        $this->assertSame(
            ['count' => 2, 'reason' => 'account_terminated'],
            json_decode((string) $entry->after, true, flags: JSON_THROW_ON_ERROR)
        );
    }
}
