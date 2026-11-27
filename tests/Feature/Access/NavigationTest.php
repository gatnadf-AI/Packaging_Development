<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\MatrixResolver;
use App\Domain\Access\PermissionSet;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * FSD §2.3's app shell, first half (FSD-101, FSD-614).
 *
 * "Menu renders only permitted entries" is the same sentence as "direct URL to a
 * denied route yields MSG-010" seen from the other end, and the failure mode the
 * requirement is written against is a menu that was compiled by hand: the grid
 * changes, the menu does not, and the screen now offers a door that answers
 * MSG-010. So the menu is computed from the resolved permission set here, in the
 * application, over a closed list of the screens that exist — and the invariant
 * tests below check it against the routes' own `feature:` middleware rather than
 * against a second transcription of the same fact.
 *
 * The catalogue names a level because a screen's route demands one: SCR-2.1 is
 * opened by a FULL holder while SCR-2.3 and SCR-2.5 are opened by READ. That is
 * not a menu decision, it is the gate, and the test that reads it back off the
 * route list is what keeps the two from drifting into disagreeing.
 *
 * What is deliberately not in here: Profil and Sesi aktif stay in the account
 * dropdown. They are not feature-gated — every authenticated account holds them
 * — and FSD-614 forbids a screen adding its own role test, not a shell listing a
 * route that has no cell to consult.
 */
final class NavigationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function testTheMenuIsTheGridSeenFromTheSideOfSomebodyOpeningScreens(): void
    {
        $items = $this->itemsFor(User::factory()->role('admin')->create());

        $this->assertSame(
            [
                ['label' => 'User', 'pattern' => 'admin.users.*', 'screen' => 'SCR-2.1'],
                ['label' => 'Permission', 'pattern' => 'admin.matrix.*', 'screen' => 'SCR-2.3'],
                ['label' => 'Announcement', 'pattern' => 'admin.announcements.*', 'screen' => 'SCR-2.4'],
                ['label' => 'Audit Log', 'pattern' => 'admin.audit.*', 'screen' => 'SCR-2.5'],
            ],
            array_map(
                static fn (array $item): array => [
                    'label' => $item['label'],
                    'pattern' => $item['pattern'],
                    'screen' => $item['screen'],
                ],
                $items['Administration']['items']
            )
        );

        $this->assertSame('User Management', $items['Administration']['label']);
    }

    public function testTheMasterDataEntriesAreOfferedUnderTheWordsTheOwnerUses(): void
    {
        $items = $this->itemsFor(User::factory()->role('admin')->create());

        $this->assertSame(
            ['Kategori Bahan Kemas', 'Item Bahan Kemas', 'Master Product', 'Spesifikasi Bahan Pengemas'],
            array_column($items['Master data']['items'], 'label'),
            'the master lists are named from the catalogue, so a label that drifted here has drifted in the menu every account reads'
        );

        $this->assertSame('Master Data', $items['Master data']['label']);
    }

    public function testAReadOnlyHolderIsOfferedTheScreensTheyCanActuallyOpen(): void
    {
        // The grid answers for itself: lowering the cell is what makes READ
        // somebody's highest level, rather than a second vocabulary the menu
        // keeps next to it.
        DB::table('feature_grants')
            ->where('feature_key', 'admin.access')
            ->update(['level' => 'READ']);

        $items = $this->itemsFor(User::factory()->role('admin')->create());

        $this->assertSame(
            ['Permission', 'Announcement', 'Audit Log'],
            array_column($items['Administration']['items'], 'label'),
            'a menu entry for a route that would answer MSG-010 is the defect FSD-101 exists to prevent'
        );
    }

    public function testNobodyIsHandedAGroupHeaderWithNothingUnderIt(): void
    {
        $groups = Navigation::for(PermissionSet::empty());

        $this->assertSame([], $groups, 'an empty group is a heading over an empty box');
    }

    public function testEveryMenuEntryIsGuardedByTheCellItClaims(): void
    {
        $guards = $this->routeGates();

        foreach (Navigation::CATALOGUE as $entry) {
            $route = Route::getRoutes()->getByName($entry['route']);

            $this->assertNotNull($route, "the menu points at a route that does not exist: {$entry['route']}");

            $this->assertSame(
                ['feature' => $entry['feature'], 'level' => $entry['level']],
                $guards[$entry['route']] ?? null,
                "the menu promises {$entry['route']} is reached with {$entry['feature']},{$entry['level']} but the route says otherwise"
            );
        }
    }

    public function testEveryMenuEntryNamesAFeatureTheGridKnows(): void
    {
        $known = DB::table('features')->pluck('key')->all();

        foreach (Navigation::CATALOGUE as $entry) {
            $this->assertContains(
                $entry['feature'],
                $known,
                "{$entry['route']} is gated on a feature key FSD §6 does not have, so no cell can ever grant it"
            );
        }
    }

    public function testTheShellIsHandedTheSameTreeRatherThanAListItHasToFilter(): void
    {
        /** @var User $admin */
        $admin = User::factory()->role('admin')->create();

        $expected = Navigation::for(app(MatrixResolver::class)->forUser($admin));

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('nav', $expected));
    }

    public function testTheShellAsksTheQuestionOnceRatherThanLeavingItToEveryPage(): void
    {
        // The identity the grid gives nothing to. It used to be qc_supervisor,
        // and Phase 2a's master-data cell moved them: FSD §6 gives them
        // master.category at READ because a QC supervisor picks from the
        // packaging lists, so they are now offered a menu. What is under test is
        // "a shell that was handed no permitted cell draws no group", and bod —
        // NONE on every screen this phase has routes for — is the identity that
        // still answers that question.
        /** @var User $outsider */
        $outsider = User::factory()->role('bod')->create();

        $this->actingAs($outsider)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('nav', []));
    }

    /**
     * The gate each catalogued route carries, read off the route list.
     *
     * @return array<string, array{feature: string, level: string}>
     */
    private function routeGates(): array
    {
        $gates = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'feature:')) {
                    continue;
                }

                [$feature, $level] = array_pad(explode(',', substr($middleware, strlen('feature:')), 2), 2, '');

                // The name the shell would link is the name the test compares, and
                // an entry-level route (users/{user}) inherits its group's cell.
                $gates[$route->getName()] ??= ['feature' => $feature, 'level' => $level];
            }
        }

        return $gates;
    }

    /**
     * The tree one account is offered, keyed by the catalogue's group.
     *
     * @return array<string, array{label: string, items: list<array<string, mixed>>}>
     */
    private function itemsFor(User $user): array
    {
        $keyed = [];

        foreach (Navigation::for(app(MatrixResolver::class)->forUser($user)) as $group) {
            $keyed[$group['group']] = $group;
        }

        return $keyed;
    }
}
