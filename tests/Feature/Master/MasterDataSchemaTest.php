<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Models\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\Product;
use Database\Seeders\UnitsGroupsMethodsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * TSD-PDS-003 §3.3.2 — the master-data half of FSD area F4, tested at the
 * database rather than the screen.
 *
 * These are the rules that must hold no matter which screen writes the row, and
 * FSD-401/402/403 make three of them properties of the schema rather than of a
 * form: an item belongs to exactly one category, a category that is referenced
 * cannot be removed, and a deactivated item keeps existing. A screen can forget
 * a check; a RESTRICT foreign key cannot be talked past by a request body, which
 * is why G-13's "otherwise deactivate" is written here as well as in the
 * service that will read it back as MSG-070.
 *
 * The reference lists are asserted as seeded data because FSD-408 makes the six
 * parameter groups the vocabulary the 77-component set is described with: a
 * missing group is not a shorter list, it is a specification that cannot be
 * written down.
 */
final class MasterDataSchemaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function testACategoryNameIsUniqueBecauseItIsTheVocabularyReportsGroupBy(): void
    {
        $this->expectException(QueryException::class);

        PackagingCategory::query()->create(['name' => 'Primer']);
        PackagingCategory::query()->create(['name' => 'Primer', 'description' => 'Yang menyentuh produk']);
    }

    public function testACategoryWithItemsUnderItCannotBeRemovedEvenBySql(): void
    {
        $category = PackagingCategory::query()->create(['name' => 'Sekunder']);
        PackagingItem::query()->create([
            'code' => 'RMLPC0794',
            'name' => 'Carton INNER',
            'category_id' => $category->getKey(),
            'branch_class' => 'B',
        ]);

        $this->expectException(QueryException::class);

        // FSD-401: "deletion is not, once referenced". The screen will say
        // MSG-070 with a live count before anyone reaches this, and this is the
        // half that stays true when a screen forgets to ask.
        DB::table('packaging_categories')->where('id', $category->getKey())->delete();
    }

    public function testAnItemMustNameTheOneCategoryItBelongsTo(): void
    {
        $this->expectException(QueryException::class);

        PackagingItem::query()->create([
            'code' => 'RMLPC0795',
            'name' => 'Partisi inner',
            'category_id' => null,
            'branch_class' => 'B',
        ]);
    }

    public function testTwoItemsCannotShareOneCode(): void
    {
        $category = PackagingCategory::query()->create(['name' => 'Tersier']);

        PackagingItem::query()->create([
            'code' => 'RMMBX0012',
            'name' => 'Master box',
            'category_id' => $category->getKey(),
            'branch_class' => 'BOTH',
        ]);

        $this->expectException(QueryException::class);

        PackagingItem::query()->create([
            'code' => 'RMMBX0012',
            'name' => 'Master box varian lain',
            'category_id' => $category->getKey(),
            'branch_class' => 'A',
        ]);
    }

    public function testTheBranchClassificationAcceptsOnlyTheThreeNamedValues(): void
    {
        $category = PackagingCategory::query()->create(['name' => 'Primer']);

        foreach (['A', 'B', 'BOTH'] as $class) {
            PackagingItem::query()->create([
                'code' => 'RM'.$class.'0001',
                'name' => 'Item '.ucfirst(strtolower($class)),
                'category_id' => $category->getKey(),
                'branch_class' => $class,
            ]);
        }

        $this->expectException(QueryException::class);

        // FSD-403: the classification seeds a picker and is never a gate, so a
        // fourth value would be a branch the workflow does not have.
        PackagingItem::query()->create([
            'code' => 'RMC00001',
            'name' => 'Item dengan cabang yang tidak ada',
            'category_id' => $category->getKey(),
            'branch_class' => 'C',
        ]);
    }

    public function testEveryEditableMasterRowCarriesItsOwnVersionToken(): void
    {
        $category = PackagingCategory::query()->create(['name' => 'Primer']);
        $item = PackagingItem::query()->create([
            'code' => 'RMLPC0796',
            'name' => 'Label',
            'category_id' => $category->getKey(),
            'branch_class' => 'A',
        ]);
        $product = Product::query()->create(['code' => 'FGBMA2001', 'name' => 'Camellia Body Mist']);

        // FSD §2.5 puts a version token on every editable record, and §3.3.3
        // names it on specifications. A reference list without one can only be
        // overwritten silently, which is the behaviour MSG-020 exists to refuse.
        // The token is read back rather than taken from the model: the schema's
        // default is what a create writes (RowVersion::initial()), and a form
        // that could set it is a form that could pre-empt somebody else's save.
        foreach ([$category, $item, $product] as $row) {
            $stored = $row->newQuery()->whereKey($row->getKey())->value('row_version');

            $this->assertSame(1, (int) $stored, $row::class.' was created off a different token than a stale save expects');
        }
    }

    public function testTheSixParameterGroupsAreSeededInTheOrderTheDocumentShowsThem(): void
    {
        $this->seed(UnitsGroupsMethodsSeeder::class);

        $this->assertSame(
            ['Organoleptis', 'Fisik', 'Kimia', 'Mikrobiologi', 'Fungsi', 'Tampilan'],
            DB::table('parameter_groups')->orderBy('sort')->pluck('name')->all(),
            'FSD SCR-4.3 and §7.2 both state the groups "in the observed order", so the order is part of the printed form'
        );
    }

    public function testTheSeededUnitsAreTheOnesTheSourceColumnsAreWrittenIn(): void
    {
        $this->seed(UnitsGroupsMethodsSeeder::class);

        $units = DB::table('units')->orderBy('symbol')->pluck('kind', 'symbol')->all();

        // TSD §3.1: a unit is a foreign key and never free text, because the
        // inspection form compares a measured value against it. The four symbols
        // are the ones the client's own master-spec columns are labelled with
        // (mm, gram, ml) plus the count a component list needs.
        $this->assertSame(['g' => 'mass', 'ml' => 'volume', 'mm' => 'length', 'pcs' => 'count'], $units);
    }

    public function testTheMaterialTypesTheRequirementsNameExistAsDataNotAsCode(): void
    {
        $this->seed(UnitsGroupsMethodsSeeder::class);

        $names = DB::table('material_types')->pluck('name')->all();

        // FR-605 gives these four as the examples a pack's material type is
        // recorded with; FSD-408 makes the list extensible because the real
        // vocabulary is longer than any example.
        foreach (['Kertas Karton', 'PET', 'PP - LDPE', 'PP White'] as $name) {
            $this->assertContains($name, $names, "FR-605 names {$name} and it is not seeded");
        }
    }

    public function testTheCategoryModelReachesItsItemsThroughTheColumnTheSchemaHas(): void
    {
        // The screen counts through a correlated subquery it writes itself, so a
        // relation pointing at a column this schema never had stayed invisible
        // until somebody used it. Phase 2c reads items off the category, which is
        // the first caller — and a relation that throws is worse than no relation,
        // because the throw arrives in whichever screen happens to trust it.
        $category = PackagingCategory::query()->create(['name' => 'Primer']);

        foreach (['RMLPC0798', 'RMLPC0799'] as $code) {
            PackagingItem::query()->create([
                'code' => $code,
                'name' => 'Item di bawah kategori',
                'category_id' => $category->getKey(),
                'branch_class' => 'A',
            ]);
        }

        $this->assertSame(
            ['RMLPC0798', 'RMLPC0799'],
            $category->items()->orderBy('code')->pluck('code')->all()
        );

        $this->assertSame(
            2,
            (int) PackagingCategory::query()->withCount('items')->find($category->getKey())->items_count
        );
    }

    public function testAnItemKeepsItsHistoryWhenItIsDeactivated(): void
    {
        $category = PackagingCategory::query()->create(['name' => 'Primer']);
        $item = PackagingItem::query()->create([
            'code' => 'RMLPC0797',
            'name' => 'Tutup botol',
            'category_id' => $category->getKey(),
            'branch_class' => 'A',
        ]);

        $item->forceFill(['is_active' => false])->save();

        // FSD-402: "inactive items stop appearing in new assignment pickers but
        // keep every historical record and print correctly". The row surviving
        // its own deactivation is the whole requirement; the picker half is a
        // query that has to be able to find it again.
        $this->assertNotNull(DB::table('packaging_items')->where('id', $item->getKey())->first());
        $this->assertNull(PackagingItem::query()->active()->find($item->getKey()));
    }
}
