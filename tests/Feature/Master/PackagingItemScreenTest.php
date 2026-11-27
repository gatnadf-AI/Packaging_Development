<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Models\MaterialType;
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
 * SCR-4.2 — the packaging item list (FSD-401, 402, 403).
 *
 * Three readings of the requirement are pinned here, and one absence is asserted
 * on purpose.
 *
 * The row says what a pack is in words: the category name, the material name, the
 * branch classification (§2.2 forbids the machine value, and `A` on its own is one).
 * The two count columns the FSD also names — Spesifikasi and Standar — are not in
 * this file, because the tables that would answer them arrive with the SBK
 * (Phase 2d) and the standard (Phase 5). SCR-4.2's own sentence explains why they
 * wait: the prototype's defect was a count reading 0 while the specification held
 * 77 components, and a 0 typed in because nothing exists yet is that same defect
 * written deliberately.
 *
 * The absence is the delete. FSD-402 says an item is deactivated rather than
 * deleted and keeps printing, so there is no verb on this list that removes a
 * row, and a test that asserts the route table has none is cheaper than the
 * support ticket for an item that vanished from a history.
 *
 * Search is over Kode and Nama item, the two columns the screen marks (S), which
 * is asserted by searching a category name as well: a search that quietly grew to
 * cover the joined labels is a search nobody can predict.
 */
final class PackagingItemScreenTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private User $keeper;

    private PackagingCategory $primer;

    private PackagingCategory $sekunder;

    private ?Product $subject = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->keeper = User::factory()->role('rnd_staff')->create(['name' => 'Penjaga master']);

        $this->primer = PackagingCategory::query()->create(['name' => 'Primer']);
        $this->sekunder = PackagingCategory::query()->create(['name' => 'Sekunder']);
    }

    public function testTheItemListViewSaysWhatEachPackIsInWordsRatherThanInKeys(): void
    {
        $pet = MaterialType::query()->where('name', 'PET')->sole();

        $bottle = $this->item('RMPET0101', 'Botol PET 250 ml', $this->primer, 'A', $pet);
        $carton = $this->item('RMLPC0794', 'Carton INNER', $this->sekunder, 'BOTH');

        $this->actingAs($this->keeper)
            ->get('/master/items')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Items')
                ->where('screen', 'SCR-4.2')
                ->where('items.total', 2)
                ->where('items.data.0.id', $bottle->getKey())
                ->where('items.data.0.code', 'RMPET0101')
                ->where('items.data.0.category', 'Primer')
                ->where('items.data.0.material_type', 'PET')
                ->where('items.data.0.branch_class', 'A')
                ->where('items.data.0.branch_label', 'Utama')
                ->where('items.data.0.is_active', true)
                ->where('items.data.1.id', $carton->getKey())
                ->where('items.data.1.material_type', null)
                ->where('items.data.1.branch_label', 'Keduanya')
                ->where('columns.searchable', ['code', 'name'])
                ->where('columns.sortable', ['code', 'name'])
                ->where('can.write', true)
            );
    }

    public function testTheItemCountIsTheRegisterItselfRatherThanASecondNumberKeptBesideTheItem(): void
    {
        // FSD §5's SCR-4.2 line names a "Spesifikasi (link count)" column, and
        // FR-603 is the reason it is asked of the database on every read instead
        // of stored next to the item: the reviewed prototype printed a count that
        // had drifted from the rows behind it. A count and the page its link
        // opens are therefore one assertion here, not two tests that can each
        // pass against a different truth.
        //
        // What is counted is the register's own rows. A revised SBK is a second
        // row under the same number and §9 says both versions are listed, so a
        // figure of "documents" would print 1 beside a link that shows 2 — the
        // same defect, counted the other way round.
        $bottle = $this->item('RMPET0101', 'Botol PET 250 ml', $this->primer, 'A');
        $carton = $this->item('RMLPC0794', 'Carton INNER', $this->sekunder, 'BOTH');
        $untouched = $this->item('RMLDR0777', 'Sachet aluminium', $this->sekunder, 'B');

        $this->sheet($bottle, 'QC/BP/011/26', 1);
        $this->sheet($bottle, 'QC/BP/011/26', 2);
        $this->sheet($carton, 'QC/BP/012/26', 1);

        $this->actingAs($this->keeper)
            ->get('/master/items')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.code', 'RMPET0101')
                ->where('items.data.0.specification_count', 2)
                ->where('items.data.1.code', 'RMLPC0794')
                ->where('items.data.1.specification_count', 1)
                // §2.2's "zero that means something": an item nobody has written a
                // sheet about reads 0 with its unit, and that 0 is true rather
                // than a placeholder — which is the distinction FR-603 draws.
                ->where('items.data.2.code', 'RMLDR0777')
                ->where('items.data.2.specification_count', 0)
            );

        $this->assertSame(2, $this->registerRowsFor($bottle), 'the count and the register it links to disagree');
        $this->assertSame(1, $this->registerRowsFor($carton), 'the count and the register it links to disagree');
        $this->assertSame(0, $this->registerRowsFor($untouched), 'an empty link is not an empty register');
    }

    public function testSearchRunsOverTheTwoMarkedColumnsAndOverNothingElse(): void
    {
        $this->item('RMPET0102', 'Botol PET 500 ml', $this->primer, 'A');
        $this->item('RMLPC0800', 'Carton luar', $this->sekunder, 'B');

        $this->assertSame(1, $this->rowCount('?q=botol'), 'search lost the name column');
        $this->assertSame(1, $this->rowCount('?q=rmlpc'), 'search lost the code column');

        // Category is a joined label, not one of the (S) columns. A search that
        // answers for it would be a second vocabulary the screen has not promised.
        $this->assertSame(0, $this->rowCount('?q=primer'));
    }

    public function testAnItemCannotBeFiledUnderNoCategoryOrUnderOneThatDoesNotExist(): void
    {
        $this->actingAs($this->keeper)
            ->post('/master/items', ['code' => 'RMNEW0001', 'name' => 'Tanpa kategori', 'branch_class' => 'A'])
            ->assertSessionHasErrors(['category_id' => Msg::get('MSG-030')]);

        $this->actingAs($this->keeper)
            ->post('/master/items', [
                'code' => 'RMNEW0001',
                'name' => 'Kategori karangan',
                'category_id' => 9999,
                'branch_class' => 'A',
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseMissing('packaging_items', ['code' => 'RMNEW0001']);
    }

    public function testACodeAnotherItemAlreadyCarriesIsRefusedUnderTheCodeField(): void
    {
        $this->item('RMLPC0794', 'Carton INNER', $this->primer, 'B');

        $this->actingAs($this->keeper)
            ->post('/master/items', [
                'code' => 'rmlpc0794',
                'name' => 'Carton INNER duplikat',
                'category_id' => $this->sekunder->getKey(),
                'branch_class' => 'B',
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(
            1,
            DB::table('packaging_items')->whereRaw('lower(code) = ?', ['rmlpc0794'])->count(),
            'FSD §2.3 makes the code unique; a case-folded twin would be found by nobody but the database'
        );
    }

    public function testABranchClassificationOutsideTheThreeTheWorkflowHasIsRefused(): void
    {
        $this->actingAs($this->keeper)
            ->post('/master/items', [
                'code' => 'RMNEW0002',
                'name' => 'Item cabang empat',
                'category_id' => $this->primer->getKey(),
                'branch_class' => 'C',
            ])
            ->assertSessionHasErrors(['branch_class' => Msg::get('MSG-031', [
                'field' => 'Klasifikasi cabang',
                'rule' => 'bukan pilihan yang tersedia',
            ])]);

        $this->assertDatabaseMissing('packaging_items', ['code' => 'RMNEW0002']);
    }

    public function testTheMaterialIsChosenFromTheReferenceListRatherThanTyped(): void
    {
        $this->actingAs($this->keeper)
            ->post('/master/items', [
                'code' => 'RMNEW0003',
                'name' => 'Bahan karangan',
                'category_id' => $this->primer->getKey(),
                'branch_class' => 'A',
                'material_type_id' => 4242,
            ])
            ->assertSessionHasErrors('material_type_id');

        $this->actingAs($this->keeper)
            ->get('/master/items')
            ->assertInertia(fn (Assert $page) => $page->has('reference.material_types'));
    }

    public function testMovingAnItemToAnotherCategoryIsAuditedAgainstTheCategoryItLeft(): void
    {
        $item = $this->item('RMLPC0801', 'Partisi', $this->primer, 'B');

        $this->actingAs($this->keeper)
            ->patch("/master/items/{$item->getKey()}", [
                'code' => $item->code,
                'name' => $item->name,
                'category_id' => $this->sekunder->getKey(),
                'branch_class' => 'B',
                'row_version' => 1,
            ])
            ->assertRedirect();

        $this->assertSame(
            $this->sekunder->getKey(),
            (int) PackagingItem::query()->findOrFail($item->getKey())->category_id
        );

        $entry = DB::table('audit_entries')->where('action', AuditAction::ItemUpdated->value)->sole();

        $this->assertSame(
            $this->primer->getKey(),
            (int) json_decode((string) $entry->before, true)['category_id']
        );
    }

    public function testAnItemIsRetiredRatherThanRemovedAndStaysInItsOwnHistory(): void
    {
        $item = $this->item('RMLPC0802', 'Tutup botol', $this->primer, 'A');

        $this->actingAs($this->keeper)
            ->patch("/master/items/{$item->getKey()}/active", [
                'is_active' => 0,
                'row_version' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('packaging_items', ['id' => $item->getKey(), 'is_active' => 0]);

        // FSD-402: an inactive item is out of what a new record picks, but the
        // list is where an administrator finds the row again, so it stays here.
        $this->assertSame(0, $this->rowCount('?status=active'));
        $this->assertSame(1, $this->rowCount('?status=inactive'));

        $this->assertSame(
            AuditAction::ItemDeactivated->value,
            DB::table('audit_entries')->orderByDesc('id')->value('action')
        );
    }

    public function testThisListHasNoWayToDeleteARow(): void
    {
        // The four verbs the screen does answer, named first: an assertion about
        // an absent route is only worth anything over a list that exists.
        foreach (['master.items.index', 'master.items.store', 'master.items.update', 'master.items.active'] as $name) {
            $this->assertTrue(
                RouteFacade::has($name),
                "the item screen offers no {$name}, so the delete check below is green without having looked at anything"
            );
        }

        $removals = array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            static fn (Route $route): bool => in_array('DELETE', $route->methods(), true)
                && str_starts_with($route->uri(), 'master/items')
        ));

        $this->assertSame(
            [],
            $removals,
            'FSD-402 retires an item and keeps every record: a delete verb on this list is a bug with a route name'
        );
    }

    public function testAStaleItemSaveIsRefusedWithMsg020AndWritesNothing(): void
    {
        $item = $this->item('RMLPC0803', 'Sleeve', $this->primer, 'A');

        $this->actingAs($this->keeper)
            ->patch("/master/items/{$item->getKey()}", [
                'code' => $item->code,
                'name' => 'Nama yang ditimpa',
                'category_id' => $this->primer->getKey(),
                'branch_class' => 'A',
                'row_version' => 4,
            ])
            ->assertRedirect();

        $this->assertSame(Msg::get('MSG-020'), $this->errorFor('row_version'));
        $this->assertSame('Sleeve', PackagingItem::query()->findOrFail($item->getKey())->name);
    }

    private function rowCount(string $query): int
    {
        // The page props the screen is handed, read off the render rather than
        // through a browser: §2.4's contract is about what the server filtered,
        // and the number a person counts in the footer is this one.
        $page = $this->actingAs($this->keeper)->get('/master/items'.$query)->viewData('page');

        return (int) $page['props']['items']['total'];
    }

    private function item(string $code, string $name, PackagingCategory $category, string $branch, ?MaterialType $material = null): PackagingItem
    {
        return PackagingItem::query()->create([
            'code' => $code,
            'name' => $name,
            'category_id' => $category->getKey(),
            'material_type_id' => $material?->getKey(),
            'branch_class' => $branch,
        ]);
    }

    /**
     * A row of the specification register, written the way the schema holds it
     * rather than through the authoring service: this list only ever asks how
     * many point at the item, and a count test that needed the SBK's own rules
     * would be testing the wrong module.
     */
    private function sheet(PackagingItem $item, string $number, int $version): Specification
    {
        if ($this->subject === null) {
            $this->subject = Product::query()->create([
                'code' => 'BRGMIST001',
                'name' => 'Camellia Body Mist 200 ml',
            ]);
        }

        return Specification::query()->create([
            'number' => $number,
            'version' => $version,
            'item_id' => $item->getKey(),
            'product_id' => $this->subject->getKey(),
            'material_name' => $item->name,
            'author_id' => $this->keeper->getKey(),
        ]);
    }

    private function registerRowsFor(PackagingItem $item): int
    {
        $page = $this->actingAs($this->keeper)
            ->get('/spec?item='.$item->getKey())
            ->viewData('page');

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
