<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\MatrixResolver;
use App\Domain\Access\PermissionSet;
use App\Domain\Specification\SpecificationStatus;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\Product;
use App\Models\Specification;
use App\Models\User;
use App\Support\Msg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * TSD §15 Phase 1's exit check: "matrix test green for all 14 identities".
 *
 * The other Phase 1 tests each prove one screen's half of FSD-101. This is the
 * statement of the whole: every route that carries a `feature:` cell, walked by
 * every identity the grid defines, with the expected answer read out of the
 * resolved permission set rather than written down here. Nothing in this file
 * names a role and what it may open — that sentence would be a third copy of the
 * matrix, and a third copy is how the reviewed prototype's menus came to
 * disagree with its guards.
 *
 * The routes arrive by interrogation (RouteFacade::getRoutes()) rather than by a
 * list, so a screen added in Phase 2 is walked the moment its route is written,
 * and the one test that guards against an empty walk is
 * testTheWalkCoversEveryGuardedRouteOfPhaseOne: a loop over nothing is green, and
 * a green gate that checked nothing is worse than a red one because it is
 * believed.
 *
 * Writes are asserted at the gate, not through the controller. An identity below
 * the cell required never reaches a form request, so the only thing an empty
 * payload can prove is that the refusal was the gate's MSG-010 and not a
 * validation error or a successful save — and that is the half the grid owns.
 */
final class GateOneMatrixRouteTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private User $subject;

    private Department $department;

    private Announcement $announcement;

    private PackagingCategory $category;

    private PackagingItem $item;

    private Product $product;

    private Specification $specification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        // The rows the parameterised routes bind to. They belong to nobody in
        // particular: the gate is decided before a policy is consulted. What
        // they do have to be is present each time the gate is asked, because
        // SubstituteBindings runs ahead of the `feature:` middleware — an
        // identity that holds the cell deletes the row, and the identities
        // below it would then be answered the router's 404 rather than the
        // gate's MSG-010. Hence restoreTargets(), and hence a URI that is
        // bound at the moment of the call rather than once for the walk.
        $this->subject = User::factory()->role('pd_staff')->create(['name' => 'Orang yang dinilai']);
        $this->department = Department::query()->orderBy('id')->firstOrFail();

        $this->announcement = $this->newAnnouncement();

        // Phase 2a's two master lists, one of them kept empty on purpose:
        // FSD-401 lets a FULL holder delete a category only while nothing points
        // at it, and the walk sends the DELETE verb the same way it sends every
        // other one. The item therefore hangs off its own category rather than
        // sharing this one, so the row the gate deletes is a row the gate is
        // allowed to delete.
        $this->category = $this->newEmptyCategory();

        $this->item = PackagingItem::query()->create([
            'code' => 'RMGATE0001',
            'name' => 'Item gerbang',
            'category_id' => PackagingCategory::query()->create(['name' => 'Kategori item gerbang'])->getKey(),
            'branch_class' => 'A',
        ]);

        // SCR-4.5's row. Unlike the category, it never needs restoring: this
        // screen has no DELETE verb, and the empty payload the walk posts is
        // refused by the form request before ProductService is constructed, so
        // the row survives all fourteen identities unchanged.
        $this->product = Product::query()->create([
            'code' => 'BRGGATE001',
            'name' => 'Produk gerbang',
        ]);

        // SCR-4.3's sheet, written straight to the table rather than through
        // SpecificationService: the gate asks only what the door answers, and a
        // draft is the row the walk needs. The number is typed rather than
        // allocated because allocation belongs to the domain's own test —
        // SpecificationDraftTest — and an allocated code here would put the
        // sequence under a test that never means to move it. Like the product, it
        // needs no restoring: this screen has no DELETE verb either, and an empty
        // payload dies in the form request before the domain is constructed.
        $this->specification = Specification::query()->create([
            'number' => 'QC/BP/900/26',
            'version' => 1,
            'item_id' => $this->item->getKey(),
            'product_id' => $this->product->getKey(),
            'material_name' => 'Spesifikasi gerbang',
            'effective_date' => '2026-05-01',
            'doc_status' => SpecificationStatus::Draft,
            'author_id' => $this->subject->getKey(),
        ]);
    }

    public function testTheWalkCoversEveryScreenTheApplicationHasRoutesFor(): void
    {
        $guards = $this->guardedRoutes();

        $this->assertNotEmpty($guards, 'an empty walk is green without having checked anything');

        // Every screen that exists, by the name the shell links it under. A
        // screen whose routes lost their `feature:` middleware would otherwise
        // drop out of this gate quietly, which is why the list grows the day a
        // screen is added rather than at the end of a phase.
        foreach (['admin.users', 'admin.matrix', 'admin.announcements', 'admin.audit', 'master.categories', 'master.items', 'master.products', 'spec'] as $prefix) {
            $covered = array_filter(
                $guards,
                static fn (array $guard): bool => str_starts_with($guard['name'], $prefix)
            );

            $this->assertNotEmpty($covered, "the gate walks no route under {$prefix}, so it cannot answer for that screen");
        }

        // Every guarded route names a cell the grid actually has, which is the
        // precondition of the whole test: a key nobody can grant would make the
        // refusal below correct for the wrong reason.
        $known = DB::table('features')->pluck('key')->all();

        foreach ($guards as $guard) {
            $this->assertContains(
                $guard['feature'],
                $known,
                "{$guard['name']} is gated on {$guard['feature']}, a key FSD §6 does not have"
            );
        }
    }

    public function testEveryIdentityReachesEveryReadableRouteTheGridGrantsThem(): void
    {
        $reads = array_values(array_filter(
            $this->guardedRoutes(),
            static fn (array $guard): bool => $guard['method'] === 'GET'
        ));

        $this->assertNotEmpty($reads);

        foreach ($this->identities() as $slug) {
            $set = $this->setFor([$slug]);
            $user = User::factory()->role($slug)->create();

            foreach ($reads as $guard) {
                $held = $guard['level'] === 'FULL' ? $set->canAct($guard['feature']) : $set->canView($guard['feature']);

                $response = $this->actingAs($user)->get($this->bind($guard['uri']));

                if ($held) {
                    $this->assertSame(
                        200,
                        $response->getStatusCode(),
                        "{$slug} holds {$guard['feature']} at {$guard['level']} and was answered {$response->getStatusCode()} on {$guard['name']}"
                    );

                    continue;
                }

                $response->assertRedirect(route('dashboard'));
                $this->assertSame(
                    Msg::get('MSG-010'),
                    session('error'),
                    "{$slug} reached {$guard['name']} without the cell it does not hold"
                );
            }
        }
    }

    public function testAnIdentityBelowACellIsRefusedItsWriteRoutesBeforeAnyControllerRuns(): void
    {
        $writes = array_values(array_filter(
            $this->guardedRoutes(),
            static fn (array $guard): bool => $guard['method'] !== 'GET'
        ));

        $this->assertNotEmpty($writes, 'Phase 1 has four screens that write, so a gate with no write to check has lost them');

        foreach ($this->identities() as $slug) {
            $set = $this->setFor([$slug]);
            $user = User::factory()->role($slug)->create();

            foreach ($writes as $guard) {
                $held = $guard['level'] === 'FULL' ? $set->canAct($guard['feature']) : $set->canView($guard['feature']);

                // No payload: what is under test is the door, not what is behind
                // it. A holder is let through and then stopped by their own form
                // request, which is why the two answers are distinguishable.
                $this->restoreTargets();

                $response = $this->actingAs($user)->json($guard['method'], $this->bind($guard['uri']), []);

                if (! $held) {
                    $response->assertRedirect(route('dashboard'));
                    $this->assertSame(
                        Msg::get('MSG-010'),
                        session('error'),
                        "{$slug} was allowed to attempt {$guard['name']} on {$guard['level']}"
                    );

                    continue;
                }

                $this->assertNull(
                    session('error'),
                    "{$slug} holds {$guard['feature']} at {$guard['level']} and was refused {$guard['name']} with the gate's sentence"
                );
            }
        }
    }

    public function testARefusalIsWrittenToTheAccessLogWithTheGrantsThatAnsweredIt(): void
    {
        // §5.3's evidence half: the log row has to say which cell was consulted
        // and what it held, because "the grid refused them" is only auditable if
        // the grid at that moment is recorded.
        $staff = User::factory()->role('qa_supervisor')->create();

        $this->actingAs($staff)->get('/admin/users')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->get('/admin/audit')->assertRedirect(route('dashboard'));

        $rows = DB::table('access_logs')->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['GET admin/users', 'GET admin/audit'], $rows->pluck('route')->all());

        foreach ($rows as $row) {
            $snapshot = json_decode($row->grant_snapshot, true);

            $this->assertSame('READ_DENIED', $row->kind);
            $this->assertSame(['qa_supervisor'], $snapshot['roles']);
            $this->assertSame('admin.access', $snapshot['feature']);
            $this->assertSame('NONE', $snapshot['held']);
        }
    }

    public function testTheWalkWouldNoticeTheGridMoving(): void
    {
        // The anti-vacuous half of the gate. Both directions are moved with the
        // same statement an administrator can make on SCR-2.3, so if either
        // answer were hard-coded the loop above would stay green after a change
        // that actually opens or closes a screen.
        $before = $this->answersFor('pd_manager');

        $this->assertSame(
            ['open' => false, 'act' => false],
            $before,
            'pd_manager holds admin.access at NONE in FSD §6, so the walk must start by refusing it'
        );

        DB::table('feature_grants')
            ->where('role_slug', 'pd_manager')
            ->where('feature_key', 'admin.access')
            ->update(['level' => 'READ']);

        MatrixResolver::reset();

        $this->assertSame(['open' => true, 'act' => false], $this->answersFor('pd_manager'));

        // And the FULL gate closes with the cell that demands it: lowering the
        // administrator's own cell is what makes the writes refuse a person who
        // could publish a moment ago.
        DB::table('feature_grants')
            ->where('role_slug', 'admin')
            ->where('feature_key', 'admin.access')
            ->update(['level' => 'READ']);

        MatrixResolver::reset();

        $this->assertSame(['open' => true, 'act' => false], $this->answersFor('admin'));

        $administrator = User::factory()->role('admin')->create();

        $this->actingAs($administrator)->get('/admin/matrix')->assertOk();

        $this->actingAs($administrator)
            ->patch('/admin/matrix/cell')
            ->assertRedirect(route('dashboard'));

        $this->assertSame(Msg::get('MSG-010'), session('error'));
    }

    public function testAnIdentityHoldingTwoRolesIsAnsweredTheUnionRatherThanTheBetterOfTheTwo(): void
    {
        // Q-F6 lets one person hold several identities, and TSD-502 makes FULL
        // imply READ. The union is what a route sees, so it is what the gate owes
        // the test: a holder of a NONE cell and a READ cell reaches the READ route.
        DB::table('feature_grants')
            ->where('role_slug', 'pd_manager')
            ->where('feature_key', 'admin.access')
            ->update(['level' => 'READ']);

        MatrixResolver::reset();

        $person = User::factory()->roles(['mkt', 'pd_manager'])->create();

        $this->assertSame(
            ['mkt', 'pd_manager'],
            $this->setFor(['mkt', 'pd_manager'])->roleSlugs()
        );

        $this->actingAs($person)->get('/admin/audit')->assertOk();
        $this->actingAs($person)->get('/admin/users')->assertRedirect(route('dashboard'));
    }

    /**
     * What one identity's cell answers for admin.access, through the same
     * resolver a request runs through.
     *
     * @return array{open: bool, act: bool}
     */
    private function answersFor(string $slug): array
    {
        $set = $this->setFor([$slug]);

        return ['open' => $set->canView('admin.access'), 'act' => $set->canAct('admin.access')];
    }

    /**
     * @param  list<string>  $slugs
     */
    private function setFor(array $slugs): PermissionSet
    {
        return app(MatrixResolver::class)->forRoleSlugs($slugs);
    }

    /**
     * @return list<string>
     */
    private function identities(): array
    {
        $slugs = DB::table('roles')->orderBy('name')->pluck('name')->all();

        $this->assertCount(
            14,
            $slugs,
            'FSD §6 defines fourteen identities; a gate that walked fewer would report green for the ones it skipped'
        );

        return $slugs;
    }

    /**
     * Every route carrying a `feature:` middleware, with its URI left as the
     * template it is on the route table. The caller binds it at the moment of
     * the request, because a write walk changes which rows exist.
     *
     * @return list<array{name: string, method: string, uri: string, feature: string, level: string}>
     */
    private function guardedRoutes(): array
    {
        $guards = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $feature = null;
            $level = null;

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'feature:')) {
                    continue;
                }

                [$feature, $level] = array_pad(explode(',', substr($middleware, strlen('feature:')), 2), 2, '');
            }

            if ($feature === null) {
                continue;
            }

            foreach ($this->verbs($route) as $verb) {
                $guards[] = [
                    'name' => (string) $route->getName(),
                    'method' => $verb,
                    'uri' => $route->uri(),
                    'feature' => $feature,
                    // The middleware's own default, so a route that writes
                    // `feature:key` alone is walked as the FULL gate it runs as.
                    'level' => $level === '' ? 'FULL' : $level,
                ];
            }
        }

        return $guards;
    }

    /**
     * One call per route, not per method it also answers: HEAD and OPTIONS are
     * the same decision through the same middleware.
     *
     * @return list<string>
     */
    private function verbs(Route $route): array
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

        return $methods === [] ? ['GET'] : $methods;
    }

    /**
     * The URI with its parameters filled from the fixtures created for this test.
     *
     * A named parameter that this file does not know about is a failure rather
     * than a skipped route: an unbound `{id}` would be sent literally, the
     * middleware would answer, and the walk would report on a URL no person can
     * type.
     */
    private function bind(string $uri): string
    {
        $fixtures = [
            'user' => (string) $this->subject->getKey(),
            'department' => (string) $this->department->getKey(),
            'announcement' => (string) $this->announcement->getKey(),
            'category' => (string) $this->category->getKey(),
            'item' => (string) $this->item->getKey(),
            'product' => (string) $this->product->getKey(),
            'specification' => (string) $this->specification->getKey(),
        ];

        return (string) preg_replace_callback(
            '/\{(\w+)(\?)?\}/',
            function (array $match) use ($fixtures, $uri): string {
                $this->assertArrayHasKey(
                    $match[1],
                    $fixtures,
                    "the gate walks {$uri}, whose parameter {$match[1]} has no fixture"
                );

                return $fixtures[$match[1]];
            },
            $uri
        );
    }

    /**
     * A notice that has not gone up yet.
     *
     * FSD-208 gives a publish-at, and the domain's own rule is that a record
     * which nobody could have seen is the one the DELETE verb is for. A live
     * announcement would answer the holder with ChangeRefused instead, which is
     * the announcement screen's rule and not this gate's: the walk would then be
     * reading a refusal from behind the door as though it came from the door.
     */
    private function newAnnouncement(): Announcement
    {
        return Announcement::query()->create([
            'title' => 'Pengumuman uji gerbang',
            'body' => 'Baris ini ada supaya rute berparameter punya sesuatu untuk diikat.',
            'audience' => ['type' => 'all'],
            'publish_at' => now()->addHour(),
            'expire_at' => null,
            'created_by' => $this->subject->getKey(),
        ]);
    }

    /**
     * A category nothing points at, which is the only kind FSD-401 lets a FULL
     * holder delete.
     */
    private function newEmptyCategory(): PackagingCategory
    {
        return PackagingCategory::query()->create(['name' => 'Kategori gerbang']);
    }

    /**
     * The walked targets, put back when an earlier identity's write removed one.
     *
     * Without this the walk is not one question asked fourteen times but a
     * sequence: the first FULL holder deletes the row, and everyone below the
     * cell is then answered by the route binder before the middleware has a
     * chance to refuse them.
     */
    private function restoreTargets(): void
    {
        if (! Announcement::whereKey($this->announcement->getKey())->exists()) {
            $this->announcement = $this->newAnnouncement();
        }

        if (! PackagingCategory::whereKey($this->category->getKey())->exists()) {
            $this->category = $this->newEmptyCategory();
        }
    }
}
