<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolvePermissionSet;
use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * F2 — the enforcement point has to see the person who actually logged in.
 *
 * Every permission test in this suite so far opens with actingAs(), which puts
 * the account on the guard before the request is built. That is the right
 * shorthand for asking "what does this cell say", and it is the wrong shorthand
 * for asking "does the route let this person through": the request that arrives
 * through a session cookie resolves its user at a different moment, and a
 * permission set that was resolved too early is simply absent, which the
 * deny-by-default middleware reads as NONE (TSD-503). The screen then answers
 * MSG-010 to an administrator whose grid cell says FULL, and the suite says
 * nothing, because in the suite nobody ever arrived through a session.
 *
 * So this file logs in the way a browser does — the form, the cookie, then the
 * guarded URL — and asks for the screen. It is the smallest version of the
 * Phase 1.6 gate, written here because the defect it caught was found by hand in
 * a browser and no amount of matrix-cell testing would have named it. The last
 * test says where the decision actually lives, in the order of the middleware
 * stack, because these three would go green again for the wrong reason if that
 * order moved.
 */
final class SessionResolvedAccessTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function testAGuardedScreenOpensForThePersonWhoLoggedInThroughTheForm(): void
    {
        $this->signInAsAdmin();

        $this->get('/admin/matrix')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Admin/Matrix')
                    ->where('screen', 'SCR-2.3')
                    ->has('matrix.roles')
            );
    }

    /**
     * The same session reaches the trail: SCR-2.5 is guarded by the same cell as
     * the grid, and an audit viewer that only opens for a test harness is not a
     * viewer the administrator in TSD §2.5's sentence can use.
     */
    public function testTheAuditTrailOpensThroughTheSameSession(): void
    {
        $this->signInAsAdmin();

        $this->get('/admin/audit')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Admin/Audit')
                    ->where('screen', 'SCR-2.5')
                    ->has('audit.data')
                    ->has('columns.perPageOptions')
            );
    }

    /**
     * The other half, so the fix is not "always resolve somebody": a guest has no
     * cell to be refused and no trail to be denied, and the login screen is the
     * one page that must keep answering without a permission set at all.
     */
    public function testASessionThatNeverLoggedInIsStillDirectedToTheLoginScreen(): void
    {
        $this->get('/admin/audit')->assertRedirect('/login');

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('Auth/Login'));
    }

    /**
     * The login form and the guarded screen are two requests to one browser, and
     * one PHP process to a test. Without this line the guard built for the login
     * request is still holding the user when the next request begins, so a
     * permission set resolved before the session has been read still looks
     * correct here and is empty in a browser. Clearing the resolved guards is the
     * smallest way to make the second request start as cold as a page load does.
     */
    /**
     * The invariant the three tests above depend on, stated where it is actually
     * decided. ResolvePermissionSet reads $request->user(), which is only a
     * answer once the cookies are decrypted, the session is started and the guard
     * has identified the account — so it belongs after all three, and before
     * HandleInertiaRequests, which is the reason the set has to exist at all.
     *
     * Pinned as a pipeline assertion rather than as a request because a test
     * cannot reproduce the failure it prevents: in a test process the session
     * store from the login request is still the one the next request reads, so a
     * resolver running before StartSession still finds somebody. A browser does
     * not get to keep that warmth, and answered MSG-010 on a screen its own grid
     * cell grants FULL.
     */
    public function testThePermissionSetIsResolvedOnceTheRequestKnowsWhoItIsTalkingTo(): void
    {
        $pipeline = $this->pipelineFor('/admin/matrix');

        $resolve = $this->positionOf($pipeline, ResolvePermissionSet::class);
        $this->assertNotNull($resolve, 'nothing resolves the permission set for a guarded route');

        $this->assertGreaterThan(
            $this->positionOf($pipeline, StartSession::class),
            $resolve,
            'the set is resolved before the session is started, so a real session reads as a guest'
        );

        $this->assertGreaterThan(
            $this->positionOf($pipeline, Authenticate::class),
            $resolve,
            'the set is resolved before the account has been identified'
        );

        $this->assertLessThan(
            $this->positionOf($pipeline, HandleInertiaRequests::class),
            $resolve,
            'the screen is handed a set that has not been resolved yet'
        );
    }

    /**
     * @return list<string>
     */
    private function pipelineFor(string $path): array
    {
        $request = Request::create($path, 'GET');

        $this->app->instance('request', $request);

        return array_values(array_map(
            static fn (string $middleware): string => explode(':', $middleware, 2)[0],
            $this->app['router']->gatherRouteMiddleware($this->app['router']->getRoutes()->match($request))
        ));
    }

    /**
     * @param  list<string>  $pipeline
     */
    private function positionOf(array $pipeline, string $middleware): ?int
    {
        $index = array_search($middleware, $pipeline, true);

        return $index === false ? null : $index;
    }

    private function signInAsAdmin(): void
    {
        $admin = User::factory()->role('admin')->create([
            'name' => 'Penjaga Sistem',
            'department_id' => Department::query()
                ->where('name', 'Packaging Development')
                ->sole()
                ->id,
        ]);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->app['auth']->forgetGuards();
    }
}
