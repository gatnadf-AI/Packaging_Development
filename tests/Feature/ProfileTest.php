<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * SCR-1.3 — what the account owns and what it does not (FSD-201, FSD-202,
 * FSD-106 / OQ-03).
 *
 * The interesting assertions are the negative ones: the address cannot be
 * moved by the person who holds it, and the page cannot delete the account,
 * because a document's author has to survive the author's departure.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profile_page_shows_the_account_and_its_ownable_fields(): void
    {
        $user = User::factory()->inDepartment()->create(['phone' => '021 555 1234']);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Edit')
                ->has('account', 6)
                ->where('account.email', $user->email)
                ->where('account.name', $user->name)
                ->where('account.phone', '021 555 1234')
                ->where('account.locale', 'id')
                ->whereNotNull('account.department')
                ->has('languages', 2)
                ->where('sessionLifetimeMinutes', 30)
                ->whereNotNull('passwordRules')
            );
    }

    public function test_name_phone_and_language_are_written(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => 'Rina Kartika',
                'phone' => '+62 811 2233 4455',
                'locale' => 'en',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Rina Kartika', $user->name);
        $this->assertSame('+62 811 2233 4455', $user->phone);
        $this->assertSame('en', $user->locale);
    }

    public function test_the_language_choice_changes_the_messages_the_server_produces(): void
    {
        $user = User::factory()->create(['locale' => 'id']);

        $this->actingAs($user)->get('/profile');
        $this->assertSame('id', App::getLocale());

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Rina', 'phone' => null, 'locale' => 'en'])
            ->assertSessionHasNoErrors();

        $this->assertSame('en', $user->refresh()->locale);

        // FSD-106: the preference decides the interface. The write above still
        // spoke Indonesian, because the stored value was read before it changed;
        // from the next request on, the sentences follow the account.
        $this->actingAs($user)
            ->patch('/profile', ['name' => '', 'phone' => null, 'locale' => 'en'])
            ->assertSessionHasErrors('name');

        $this->assertStringContainsString(
            'field is required',
            (string) session('errors')->first('name')
        );

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Rina', 'phone' => null, 'locale' => 'id'])
            ->assertSessionHasNoErrors();

        $this->assertSame('id', $user->refresh()->locale);

        $this->actingAs($user)
            ->patch('/profile', ['name' => '', 'phone' => null, 'locale' => 'id'])
            ->assertSessionHasErrors('name');

        $this->assertStringContainsString(
            'wajib diisi',
            (string) session('errors')->first('name')
        );
    }

    public function test_the_email_address_is_not_writable_from_this_screen(): void
    {
        $user = User::factory()->create(['email' => 'rina@ppm.test']);

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => 'Rina Kartika',
                'phone' => null,
                'locale' => 'id',
                'email' => 'orang.lain@ppm.test',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('rina@ppm.test', $user->refresh()->email);
    }

    public function test_a_malformed_phone_number_is_refused(): void
    {
        $user = User::factory()->create(['phone' => '021 555 1234']);

        foreach (['bukan nomor', '021-555', '+62 (811) 2233'] as $phone) {
            $this->actingAs($user)
                ->patch('/profile', ['name' => 'Rina', 'phone' => $phone, 'locale' => 'id'])
                ->assertSessionHasErrors('phone');
        }

        $this->assertSame('021 555 1234', $user->refresh()->phone);
    }

    public function test_an_unsupported_language_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Rina', 'phone' => null, 'locale' => 'de'])
            ->assertSessionHasErrors('locale');

        $this->assertSame('id', $user->refresh()->locale);
    }

    public function test_the_account_cannot_be_deleted_from_here(): void
    {
        $user = User::factory()->create();

        // FSD-202 ends access by deactivating. A self-service delete would take
        // the author out of every document they signed.
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])
            ->assertStatus(405);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    public function test_the_blank_name_is_refused_rather_than_stored(): void
    {
        $user = User::factory()->create(['name' => 'Rina Kartika']);

        $this->actingAs($user)
            ->patch('/profile', ['name' => '   ', 'phone' => null, 'locale' => 'id'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Rina Kartika', $user->refresh()->name);
    }

    public function test_the_phone_number_can_be_removed_again(): void
    {
        $user = User::factory()->create(['phone' => '021 555 1234']);

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Rina', 'phone' => '', 'locale' => 'id'])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->phone);
    }
}
