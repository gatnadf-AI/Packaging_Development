<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * SCR-2.1's nav entry, and the rule the entry has to obey (FSD-101, FSD-614).
 *
 * Package 1.5 builds the whole permission-driven menu; this test pins the one
 * fact that menu will be built on: the client is told which features the resolved
 * grant set allows it to *open*, and is told nothing about the ones it may not.
 *
 * The reason it is asserted now, with one screen, is the row-menu precedent
 * already recorded in TSD-704: a front end that derives permission from a role
 * name answers the question a second time, differently. A nav entry that tests
 * `user.roles.includes('admin')` would disagree with the route middleware the
 * moment the matrix is edited on SCR-2.2, and the disagreement would be invisible
 * until someone clicked the link.
 *
 * What this does NOT prove: that every menu item is classified. That is the
 * Phase 1.6 gate over all 14 identities and every route.
 */
final class NavigationAccessTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_an_actor_with_a_full_grant_is_told_the_feature_is_viewable(): void
    {
        $features = $this->sharedFeatures($this->inertiaAs($this->actor('admin'), '/admin/users'));

        self::assertContains('admin.access', $features, 'the resolved grant set is not shared');
    }

    public function test_an_actor_whose_cell_is_none_is_not_offered_the_feature(): void
    {
        $features = $this->sharedFeatures($this->inertiaAs($this->actor('rnd_staff'), '/dashboard'));

        self::assertNotContains('admin.access', $features);
    }

    public function test_the_shared_set_lists_features_and_never_levels(): void
    {
        // The shared value is a list of viewable feature keys, not the matrix: a
        // screen that can read a level can start deciding buttons from it, which
        // is the second answer TSD-704 forbids. Per-screen buttons come from the
        // screen's own `can` props, computed by the policy.
        $features = $this->sharedFeatures($this->inertiaAs($this->actor('admin'), '/admin/users'));

        self::assertNotEmpty($features);

        foreach ($features as $feature) {
            self::assertIsString($feature);
            self::assertNotContains($feature, ['FULL', 'READ', 'NONE'], 'a level was shared to the client');
        }
    }

    /**
     * The JSON page object, asked for the way the browser asks: without
     * X-Inertia-Version the middleware answers 409 for a client it thinks is
     * stale, and a plain GET returns HTML rather than the props under test.
     */
    private function inertiaAs(User $actor, string $url): TestResponse
    {
        return $this->actingAs($actor)->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ]);
    }

    /**
     * @return list<string>
     */
    private function sharedFeatures(TestResponse $response): array
    {
        $response->assertOk();

        /** @var list<string> $features */
        $features = $response->json('props.auth.features') ?? [];

        return $features;
    }

    private function actor(string $slug): User
    {
        $user = User::factory()->role($slug)->create([
            'department_id' => Department::query()->where('name', 'Packaging Development')->sole()->id,
        ]);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
