<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Models\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\Product;
use App\Models\Specification;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Msg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * SCR-4.5 — the product master (FSD-409, FSD-812).
 *
 * What the signed documents actually say about this screen is one URL and two
 * sentences: FSD §5's area F4 line names SCR-4.5 as `/master/products`, FSD-409
 * makes the list the thing a specification and a design review pick a product
 * name from, and FSD-812 adds that a product cannot be deactivated while it is
 * referenced by an open assignment. There is no column table, no (S) markers and
 * no default sort for this screen anywhere in the FSD, so the list contract §2.4
 * supplies them and the choices are recorded in the README rather than presented
 * as though the document had made them.
 *
 * Three readings are pinned here.
 *
 * The row keeps the words the schema was built to hold. TSD §3.3.2 gives products
 * `brand`, `variant` and `pack_size` as nullable columns, and a nullable column
 * rendered as a blank box reads like a missing value rather than as a value the
 * client never recorded — so "belum dicatat" is the screen's job, and what is
 * never the screen's job is inventing the value.
 *
 * An empty field is the same fact as a null one. The three optional columns have
 * no third state worth keeping: a row whose variant is two spaces is a row that a
 * later reader will compare against nothing, and §2.2's rule against machine
 * values cuts both ways.
 *
 * The absence is the delete, again. FSD-812 speaks only of deactivating, and
 * G-13's "deletion is not, once referenced" is a rule about an assignment that
 * this schema cannot yet describe — a specification can name a product since
 * Phase 2d, but neither sentence's trigger word is "specification" — so this list
 * has no DELETE verb, which is the same shape SCR-4.2 has and for the same
 * reason: retirement is the act that replaces removing a master row here.
 */
final class ProductScreenTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private User $owner;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        // FSD §6 gives Master produk an F to Mkt and to R&D Staff and an R to
        // everyone else but BOD, so the writer here is a marketing account rather
        // than the R&D one the two packaging screens used. The grid, not the
        // module, chose that.
        $this->owner = User::factory()->role('mkt')->create(['name' => 'Pemilik master produk']);
        $this->reader = User::factory()->role('pd_staff')->create(['name' => 'Pembaca master produk']);
    }

    public function testTheProductListViewCarriesTheBrandVariantAndPackSizeTheSchemaHolds(): void
    {
        $mist = $this->product('BRGMIST001', 'Camellia Body Mist 200 ml', 'Camellia', 'Mawar', '200 ml');
        $plain = $this->product('BRGSHC0002', 'Sabun batang', null, null, null);

        $this->actingAs($this->owner)
            ->get('/master/products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Products')
                ->where('screen', 'SCR-4.5')
                ->where('items.total', 2)
                ->where('items.data.0.id', $mist->getKey())
                ->where('items.data.0.code', 'BRGMIST001')
                ->where('items.data.0.name', 'Camellia Body Mist 200 ml')
                ->where('items.data.0.brand', 'Camellia')
                ->where('items.data.0.variant', 'Mawar')
                ->where('items.data.0.pack_size', '200 ml')
                ->where('items.data.0.is_active', true)
                ->where('items.data.0.row_version', 1)
                ->where('items.data.1.id', $plain->getKey())
                ->where('items.data.1.brand', null)
                ->where('items.data.1.variant', null)
                ->where('items.data.1.pack_size', null)
                // §2.4's contract is answered by the server rather than by the
                // page: what may be searched, sorted and paged is one list.
                ->where('columns.searchable', ['code', 'name'])
                ->where('columns.sortable', ['code', 'name'])
                ->where('columns.defaultSort', ['column' => 'name', 'direction' => 'asc'])
                ->where('can.write', true)
            );
    }

    public function testSearchRunsOverTheTwoIdentityColumnsAndOverNothingElse(): void
    {
        $this->product('BRG0000001', 'Minyak rambut zaitun', 'Zaitun Emas', null, '100 ml');
        $this->product('BRG0000002', 'Losion tubuh', 'Sutra', 'Aloe', '250 ml');

        $this->assertSame(1, $this->rowCount('?q=zaitun'), 'search lost the name column');
        $this->assertSame(1, $this->rowCount('?q=brg0000002'), 'search lost the code column');

        // Brand and pack size are on the row but are not searched: the FSD marks
        // no columns for this screen, so the two identity columns are the whole
        // promise, and a search that grew to cover every printed label is a
        // second vocabulary the screen has not made.
        $this->assertSame(0, $this->rowCount('?q=sutra'));
        $this->assertSame(0, $this->rowCount('?q=250'));
    }

    public function testAProductIsRecordedFromItsCodeAndNameAlone(): void
    {
        $this->actingAs($this->owner)
            ->post('/master/products', ['code' => 'BRGNEW001', 'name' => 'Bedak tabur'])
            ->assertRedirect();

        /** @var Product $product */
        $product = Product::query()->where('code', 'BRGNEW001')->sole();

        $this->assertSame('Bedak tabur', $product->name);
        $this->assertNull($product->brand);
        $this->assertNull($product->variant);
        $this->assertNull($product->pack_size);
        $this->assertTrue($product->is_active);

        $this->assertSame(
            AuditAction::ProductCreated->value,
            DB::table('audit_entries')->orderByDesc('id')->value('action')
        );
    }

    public function testAnEmptyOptionalFieldIsStoredAsNothingRatherThanAsASpace(): void
    {
        $this->actingAs($this->owner)
            ->post('/master/products', [
                'code' => 'BRGNEW002',
                'name' => 'Masker wajah',
                'brand' => '   ',
                'variant' => '',
                'pack_size' => '  ',
            ])
            ->assertRedirect();

        $row = DB::table('products')->where('code', 'BRGNEW002')->sole();

        // A form that posts a subset or a space must not leave a value that reads
        // as a real answer to the next person, and must not be a third state
        // between "recorded" and "not recorded".
        $this->assertNull($row->brand);
        $this->assertNull($row->variant);
        $this->assertNull($row->pack_size);
    }

    public function testACodeAnotherProductAlreadyCarriesIsRefusedUnderTheCodeField(): void
    {
        $this->product('BRG0000010', 'Krim tangan', 'Sutra', null, '60 g');

        $this->actingAs($this->owner)
            ->post('/master/products', ['code' => 'brg0000010', 'name' => 'Krim tangan varian lain'])
            ->assertSessionHasErrors('code');

        $this->assertSame(
            1,
            DB::table('products')->whereRaw('lower(code) = ?', ['brg0000010'])->count(),
            'FSD §2.3 makes the code the printed identity; a case-folded twin is found by nobody but the database'
        );
        $this->assertDatabaseMissing('products', ['name' => 'Krim tangan varian lain']);
    }

    public function testANewProductWithoutANameIsRefusedUnderItsOwnField(): void
    {
        $this->actingAs($this->owner)
            ->post('/master/products', ['code' => 'BRGNEW003', 'name' => '  '])
            ->assertSessionHasErrors(['name' => Msg::get('MSG-030')]);

        $this->assertDatabaseMissing('products', ['code' => 'BRGNEW003']);
    }

    public function testEditingAProductPutsTheValueItLeftInTheTrail(): void
    {
        $product = $this->product('BRG0000011', 'Sampo herbal', null, '900 ml', null);

        $this->actingAs($this->owner)
            ->patch("/master/products/{$product->getKey()}", [
                'code' => 'BRG0000011',
                'name' => 'Sampo herbal jeruk',
                'brand' => 'Sutra',
                'variant' => 'Jeruk',
                'pack_size' => '900 ml',
                'row_version' => $product->row_version,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        /** @var Product $saved */
        $saved = Product::query()->findOrFail($product->getKey());

        $this->assertSame('Sampo herbal jeruk', $saved->name);
        $this->assertSame('Jeruk', $saved->variant);

        $entry = DB::table('audit_entries')->where('action', AuditAction::ProductUpdated->value)->sole();

        $before = json_decode((string) $entry->before, true);
        $after = json_decode((string) $entry->after, true);

        $this->assertSame('Sampo herbal', $before['name']);
        $this->assertSame('Sutra', $after['brand']);

        // The code was posted back unchanged, and TSD-1101 narrows the trail to
        // the changed columns: a row that recorded every field on every save is a
        // history strip nobody reads.
        $this->assertArrayNotHasKey('code', $after);
        $this->assertArrayNotHasKey('code', $before);
    }

    public function testAProductThatOnlyChangedItsOptionalFieldsStillAuditsThoseFields(): void
    {
        $product = $this->product('BRG0000012', 'Deo spray', null, null, null);

        $this->actingAs($this->owner)
            ->patch("/master/products/{$product->getKey()}", [
                'code' => 'BRG0000012',
                'name' => 'Deo spray',
                'brand' => 'Sutra',
                'variant' => '',
                'pack_size' => '',
                'row_version' => $product->row_version,
            ])
            ->assertRedirect();

        $entry = DB::table('audit_entries')->where('action', AuditAction::ProductUpdated->value)->sole();

        $this->assertSame(
            ['brand' => 'Sutra'],
            json_decode((string) $entry->after, true),
            'the trail says what became known, which for a product is usually only its brand'
        );
    }

    public function testAStaleProductSaveIsRefusedWithMsg020AndWritesNothing(): void
    {
        $product = $this->product('BRG0000013', 'Lip balm', 'Camellia', 'Cermin', '4 g');

        $this->actingAs($this->owner)
            ->patch("/master/products/{$product->getKey()}", [
                'code' => 'BRG0000013',
                'name' => 'Nama yang ditimpa',
                'brand' => 'Camellia',
                'variant' => 'Cermin',
                'pack_size' => '4 g',
                'row_version' => 7,
            ])
            ->assertRedirect();

        $this->assertSame(Msg::get('MSG-020'), $this->errorFor('row_version'));
        $this->assertSame(
            'Lip balm',
            (string) DB::table('products')->where('id', $product->getKey())->value('name'),
            'MSG-020 refuses the save; a silent overwrite is the behaviour §2.5 forbids in as many words'
        );
    }

    public function testARetiredProductStaysReadableAndNothingBlocksItsRetirementYet(): void
    {
        // FSD-812 refuses to deactivate a product "while referenced by an open
        // assignment", and G-13 says the same thing for master data in general:
        // the trigger word in both is assignment. Phase 2d changed half of the
        // premise of this test — specifications now carry a product_id, so a
        // product can be named by a document that exists — without changing the
        // other half: a specification is not an assignment. The retirement stays
        // unblocked because the rule's own condition is still unmet, which is why
        // the tripwire is written against that condition and not against every
        // foreign key into this table. It is the assignment row that would make
        // the refusal computable, and this assertion fails the day it lands.
        $assignmentTables = array_map(
            static fn (object $row): string => (string) array_change_key_case((array) $row, CASE_LOWER)['table_name'],
            DB::select(
                "select table_name from information_schema.tables
                  where table_schema = database() and table_name = 'assignments'"
            )
        );

        $this->assertSame(
            [],
            $assignmentTables,
            'assignments now exist, so FSD-812\'s "referenced by an open assignment" is a question that can be asked and the refusal belongs in ProductService::setActive'
        );

        $product = $this->product('BRG0000014', 'Minyak telon', 'Zaitun Emas', null, '120 ml');

        $this->actingAs($this->owner)
            ->patch("/master/products/{$product->getKey()}/active", [
                'is_active' => 0,
                'row_version' => $product->row_version,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $product->getKey(), 'is_active' => 0]);

        $this->assertSame(0, $this->rowCount('?status=active'));
        $this->assertSame(1, $this->rowCount('?status=inactive'));

        // The other half of the same sentence, and now provably the half that
        // does not need an assignment: a specification written against a retired
        // product keeps reading its name, because the row survives its own
        // retirement and the foreign key points at it, not at a copy of it. This
        // is what "feeds specifications" (FSD-812) means for a document already
        // written — FSD-402 says the same of an inactive item, that it prints.
        $specification = Specification::query()->create([
            'number' => 'QC/BP/090/26',
            'version' => 1,
            'item_id' => PackagingItem::query()->create([
                'code' => 'RMKRT0900',
                'name' => 'Karton inner',
                'category_id' => PackagingCategory::query()->create(['name' => 'Sekunder'])->getKey(),
                'branch_class' => 'B',
            ])->getKey(),
            'product_id' => $product->getKey(),
            'material_name' => 'Karton inner 200x100x50',
            'author_id' => $this->owner->getKey(),
        ]);

        $this->assertSame(
            'Minyak telon',
            $specification->fresh()->product->name,
            'a retired product that a specification describes stopped being readable, which is the one thing FSD-812\'s deactivation-not-deletion guarantees against'
        );

        $this->assertSame(
            AuditAction::ProductDeactivated->value,
            DB::table('audit_entries')->orderByDesc('id')->value('action')
        );

        $this->actingAs($this->owner)
            ->patch("/master/products/{$product->getKey()}/active", [
                'is_active' => 1,
                'row_version' => Product::query()->findOrFail($product->getKey())->row_version,
            ])
            ->assertRedirect();

        $this->assertSame(1, $this->rowCount('?status=active'));
    }

    public function testThisListHasNoWayToDeleteARow(): void
    {
        // The four verbs the screen does answer, named first: an assertion about
        // an absent route is only worth anything over a list that exists.
        foreach ([
            'master.products.index',
            'master.products.store',
            'master.products.update',
            'master.products.active',
        ] as $name) {
            $this->assertTrue(
                RouteFacade::has($name),
                "the product screen offers no {$name}, so the delete check below is green without having looked at anything"
            );
        }

        $removals = array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            static fn (Route $route): bool => in_array('DELETE', $route->methods(), true)
                && str_starts_with($route->uri(), 'master/products')
        ));

        $this->assertSame(
            [],
            $removals,
            'FSD-812 names deactivation as the act for a product: a delete verb on this list is a bug with a route name'
        );
    }

    public function testAnAccountThatOnlyReadsTheListIsNotHandedItsWriteRoutes(): void
    {
        $product = $this->product('BRG0000015', 'Tisu basah', 'Sutra', null, '50 pcs');

        $this->actingAs($this->reader)->get('/master/products')->assertOk();

        foreach ([
            ['post', '/master/products'],
            ['patch', "/master/products/{$product->getKey()}"],
            ['patch', "/master/products/{$product->getKey()}/active"],
        ] as [$verb, $uri]) {
            $response = $this->actingAs($this->reader)->json($verb, $uri, []);

            $response->assertRedirect(route('dashboard'));
            $this->assertSame(
                Msg::get('MSG-010'),
                session('error'),
                "pd_staff holds master.product at READ and was let attempt {$verb} {$uri}"
            );
        }

        $this->assertDatabaseHas('products', ['id' => $product->getKey()]);
    }

    public function testAnAccountWhoseCellIsNoneIsTurnedAwayFromTheScreenEntirely(): void
    {
        $outsider = User::factory()->role('bod')->create(['name' => 'Komisaris']);

        $this->actingAs($outsider)
            ->get('/master/products')
            ->assertRedirect(route('dashboard'));

        $this->assertSame(Msg::get('MSG-010'), session('error'));
    }

    private function product(
        string $code,
        string $name,
        ?string $brand = null,
        ?string $variant = null,
        ?string $packSize = null,
    ): Product {
        // refresh(): row_version is the schema's default rather than a fillable
        // field, and a form is handed the token the row actually carries.
        return Product::query()
            ->create([
                'code' => $code,
                'name' => $name,
                'brand' => $brand,
                'variant' => $variant,
                'pack_size' => $packSize,
            ])
            ->refresh();
    }

    private function rowCount(string $query): int
    {
        $page = $this->actingAs($this->owner)->get('/master/products'.$query)->viewData('page');

        return (int) $page['props']['items']['total'];
    }

    /**
     * @return array<string, string>
     */
    private function errorFor(string $field): string
    {
        $messages = app('session')->get('errors')?->getBag('default')->get($field) ?? [];

        $this->assertNotEmpty($messages, "nothing was refused under {$field}");

        return (string) $messages[0];
    }
}
