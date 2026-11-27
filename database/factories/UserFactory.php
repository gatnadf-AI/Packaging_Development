<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Draws the fixture addresses, so the next one cannot repeat a previous one.
     */
    protected static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => $this->corporateAddress(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'department_id' => null,
            'phone' => null,
            // A fresh factory user is a person who can act: an inactive account
            // is a deliberate state, never an accident of the fixture.
            'is_active' => true,
            // Seeded accounts force the change; fixtures do not, so a feature
            // test can walk past the gate without faking three requests.
            'must_change_password' => false,
            'last_login_at' => null,
            'locale' => 'id',
            'row_version' => 1,
        ];
    }

    /**
     * A fixture address that can actually sign in (V-101).
     *
     * Two things are being held at once here. The domain is read from the same
     * configuration the login and account screens enforce, because a fixture on
     * example.com cannot pass its own actor through a door the application
     * guards, and a suite that sets a different list gets fixtures that match
     * it. The local part is a counter rather than faker's unique() pool because
     * the pool is process-wide and shared state is the kind of thing that makes
     * a green suite turn red in an unrelated file at 3 a.m.; an office reading
     * "pengguna12@ppm.test" in a test report loses nothing.
     */
    private function corporateAddress(): string
    {
        /** @var list<string> $domains */
        $domains = (array) config('access.corporate_email_domains');
        $domain = $domains === [] ? 'example.test' : $domains[0];

        return 'pengguna'.(++static::$sequence).'@'.$domain;
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * FSD-201/202: deactivation removes the person from every queue and blocks
     * login. Tests that assert that behaviour ask for it by name.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'must_change_password' => true,
        ]);
    }

    public function inDepartment(?Department $department = null): static
    {
        return $this->state(fn (array $attributes) => [
            'department_id' => $department?->id ?? Department::factory(),
        ]);
    }

    /**
     * Attach a business role after the row exists (TSD §5).
     */
    public function role(string $slug): static
    {
        return $this->afterCreating(function (User $user) use ($slug): void {
            $user->syncRoles([$slug]);
        });
    }

    /**
     * Q-F6: a person holding two roles. The union grants their rights; the
     * segregation rules still apply.
     *
     * @param  list<string>  $slugs
     */
    public function roles(array $slugs): static
    {
        return $this->afterCreating(function (User $user) use ($slugs): void {
            $user->syncRoles($slugs);
        });
    }

    /**
     * A user who has already signed in once, so `must_change_password` logic is
     * the only thing standing between them and the dashboard.
     */
    public function seen(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_login_at' => now()->subDays(2),
        ]);
    }
}
