<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Models\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Msg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * SCR-4.1 — the packaging category list (FSD-401, with G-13 and MSG-070).
 *
 * The screen is small and the requirement on it is one sentence, so the reason
 * this file exists at all is the half of that sentence that is easy to build
 * wrongly: "editing a category name is allowed; deletion is not, once
 * referenced". Two verbs that look alike in a CRUD scaffold are two different
 * acts here, and the refusal is not a permission answer — an account with the
 * cell at FULL may delete, and still may not delete a category three items are
 * filed under.
 *
 * The count is the part that can only be got right once. TSD §3.5 says the
 * pre-check returns MSG-070 with the live referencing count and that "the count
 * is the same subquery the list column uses, so the number shown and the number
 * blocking are identical". A screen that shows 3 and refuses with 4 is the
 * reviewed prototype's defect in a new costume, so the two are asserted against
 * each other rather than each against a constant.
 *
 * The FR-603 half — "a zero that means something is rendered as the number with
 * its unit" — is why the count column is here at all: the prototype's tables
 * read 0 while the data held rows. The specification and standard counts of
 * SCR-4.2's row wait for the tables that would answer them (Phase 2d/2e), and
 * printing 0 for a specification table that does not exist would be the same
 * defect written on purpose.
 */
final class PackagingCategoryScreenTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private User $keeper;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        // FSD §6 gives master.category at FULL to rnd_staff, rnd_manager and
        // admin, and at READ to most of the rest — this is R&D's list, which the
        // grid keeps open for anybody who has to pick from it.
        $this->keeper = User::factory()->role('rnd_staff')->create(['name' => 'Penjaga master']);
        $this->reader = User::factory()->role('pd_staff')->create(['name' => 'Pembaca master']);
    }

    public function testTheScreenListsEveryCategoryWithTheItemCountTakenFromLiveRows(): void
    {
        $used = $this->category('Primer', 'Yang langsung membungkus produk');
        $this->item('RMLPC0794', 'Carton INNER', $used, 'B');
        $this->item('RMLPC0795', 'Partisi inner', $used, 'B');
        $this->item('RMLPC0796', 'Label utama', $used, 'A');
        $empty = $this->category('Sekunder');

        // §2.4's default order is the name column, ascending: Primer then
        // Sekunder. The empty category is the row that proves the count is live —
        // a count that came from anywhere else would not know it is zero.
        $this->actingAs($this->keeper)
            ->get('/master/categories')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Categories')
                ->where('screen', 'SCR-4.1')
                ->where('categories.total', 2)
                ->where('categories.data.0.id', $used->getKey())
                ->where('categories.data.0.name', 'Primer')
                ->where('categories.data.0.item_count', 3)
                ->where('categories.data.0.row_version', 1)
                ->where('categories.data.1.id', $empty->getKey())
                ->where('categories.data.1.item_count', 0)
                ->where('can.write', true)
            );
    }

    public function testTheNumberThatBlocksTheDeleteIsTheNumberTheListJustShowed(): void
    {
        $category = $this->category('Primer');

        foreach (['RMLPC0801', 'RMLPC0802'] as $code) {
            $this->item($code, 'Item '.$code, $category, 'A');
        }

        $shown = $this->actingAs($this->keeper)
            ->get('/master/categories')
            ->viewData('page')['props']['categories']['data'][0]['item_count'];

        $this->assertSame(2, $shown, 'the list column is not the number the delete is about');

        $this->actingAs($this->keeper)->delete("/master/categories/{$category->getKey()}");

        $this->assertSame(
            Msg::get('MSG-070', ['n' => $shown]),
            $this->errorFor('id'),
            'the refusal quoted a different count than the row the operator was looking at'
        );
    }

    public function testACategoryStillHoldingItemsIsNotDeletedAndEveryItemKeepsItsCategory(): void
    {
        $category = $this->category('Sekunder');
        $item = $this->item('RMMBX0012', 'Master box', $category, 'BOTH');

        $this->actingAs($this->keeper)
            ->delete("/master/categories/{$category->getKey()}")
            ->assertRedirect();

        $this->assertDatabaseHas('packaging_categories', ['id' => $category->getKey()]);
        $this->assertSame(
            $category->getKey(),
            (int) DB::table('packaging_items')->where('id', $item->getKey())->value('category_id'),
            'a refused delete must not have touched the rows that were the reason'
        );
        $this->assertSame(
            0,
            DB::table('audit_entries')->where('action', AuditAction::CategoryDeleted->value)->count()
        );
    }

    public function testAnEmptyCategoryIsDeletedAndTheDeletionIsLeftInTheTrail(): void
    {
        $category = $this->category('Tersier', 'Karton luar');

        $this->actingAs($this->keeper)
            ->delete("/master/categories/{$category->getKey()}")
            ->assertRedirect();

        $this->assertDatabaseMissing('packaging_categories', ['id' => $category->getKey()]);

        $entry = DB::table('audit_entries')
            ->where('action', AuditAction::CategoryDeleted->value)
            ->where('target_id', $category->getKey())
            ->sole();

        $this->assertSame(PackagingCategory::class, $entry->target_type);
        $this->assertSame('Tersier', json_decode((string) $entry->before, true)['name']);
        $this->assertSame($this->keeper->getKey(), (int) $entry->actor_id);
    }

    public function testRenamingACategoryKeepsItsItemsAndPutsTheOldSpellingInTheTrail(): void
    {
        $category = $this->category('Kemasan primer');
        $item = $this->item('RMPET0001', 'Botol PET 250 ml', $category, 'A');

        $this->actingAs($this->keeper)
            ->patch("/master/categories/{$category->getKey()}", [
                'name' => 'Primer',
                'description' => 'Yang menyentuh produk',
                'row_version' => 1,
            ])
            ->assertRedirect();

        $fresh = PackagingCategory::query()->findOrFail($category->getKey());

        $this->assertSame('Primer', $fresh->name);
        $this->assertSame('Yang menyentuh produk', $fresh->description);
        $this->assertSame(2, $fresh->row_version);
        $this->assertSame(
            $category->getKey(),
            (int) PackagingItem::query()->findOrFail($item->getKey())->category_id
        );

        $entry = DB::table('audit_entries')
            ->where('action', AuditAction::CategoryUpdated->value)
            ->sole();

        $this->assertSame(
            ['name' => 'Kemasan primer', 'description' => null],
            json_decode((string) $entry->before, true)
        );
    }

    public function testAStaleCategorySaveIsRefusedWithMsg020AndWritesNothing(): void
    {
        $category = $this->category('Primer');

        $this->actingAs($this->keeper)
            ->patch("/master/categories/{$category->getKey()}", [
                'name' => 'Ditimpa dari formulir yang tertinggal',
                'row_version' => 7,
            ])
            ->assertRedirect();

        $this->assertSame(Msg::get('MSG-020'), $this->errorFor('row_version'));
        $this->assertSame('Primer', PackagingCategory::query()->findOrFail($category->getKey())->name);
    }

    public function testACategoryNameThatDuplicatesAnotherIsRefusedUnderItsOwnField(): void
    {
        $this->category('Primer');

        $this->actingAs($this->keeper)
            ->post('/master/categories', ['name' => ' Primer '])
            ->assertSessionHasErrors('name');

        $this->assertSame(
            1,
            DB::table('packaging_categories')->where('name', 'Primer')->count(),
            'the trimmed value is the one the unique name is checked against'
        );
    }

    public function testRetiringACategoryLeavesItsItemsReadableAndTakesItOutOfThePicker(): void
    {
        $category = $this->category('Sekunder');
        $this->item('RMLPC0810', 'Carton luar', $category, 'B');

        $this->actingAs($this->keeper)
            ->patch("/master/categories/{$category->getKey()}", [
                'name' => 'Sekunder',
                'is_active' => 0,
                'row_version' => 1,
            ])
            ->assertRedirect();

        $this->assertFalse((bool) PackagingCategory::query()->findOrFail($category->getKey())->is_active);

        // FSD-401's list keeps retired rows: an item filed under the old category
        // has to be able to say which one it is. The picker half is the option
        // list the item screen is handed, and a retired category must not be one
        // of the choices a new item can be filed under.
        $this->actingAs($this->keeper)
            ->get('/master/categories')
            ->assertInertia(fn (Assert $page) => $page->where('categories.total', 1));

        $this->assertNotContains(
            $category->getKey(),
            array_column(
                $this->actingAs($this->keeper)->get('/master/items')->viewData('page')['props']['reference']['categories'],
                'id'
            ),
            'a retired category is still offered where a new item is filed'
        );

        $this->assertSame(
            AuditAction::CategoryDeactivated->value,
            DB::table('audit_entries')->orderByDesc('id')->value('action')
        );
    }

    public function testAnAccountThatOnlyReadsTheListIsNotHandedItsWriteRoutes(): void
    {
        $category = $this->category('Primer');

        $this->actingAs($this->reader)->get('/master/categories')->assertOk();

        foreach ([
            ['post', '/master/categories'],
            ['patch', "/master/categories/{$category->getKey()}"],
            ['delete', "/master/categories/{$category->getKey()}"],
        ] as [$verb, $uri]) {
            $response = $this->actingAs($this->reader)->json($verb, $uri, []);

            $response->assertRedirect(route('dashboard'));
            $this->assertSame(
                Msg::get('MSG-010'),
                session('error'),
                "pd_staff holds master.category at READ and was let attempt {$verb} {$uri}"
            );
        }

        $this->assertDatabaseHas('packaging_categories', ['id' => $category->getKey()]);
    }

    public function testAnAccountWhoseCellIsNoneIsTurnedAwayFromTheScreenEntirely(): void
    {
        $outsider = User::factory()->role('bod')->create(['name' => 'Komisaris']);

        $this->actingAs($outsider)
            ->get('/master/categories')
            ->assertRedirect(route('dashboard'));

        $this->assertSame(Msg::get('MSG-010'), session('error'));
    }

    public function testBothMasterScreensAreOfferedToReadersAndNeitherToThoseWhoseCellIsNone(): void
    {
        $this->actingAs($this->reader)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('nav.0.group', 'Master data')
                ->where('nav.0.items.0.screen', 'SCR-4.1')
                ->where('nav.0.items.1.screen', 'SCR-4.2')
            );

        $this->actingAs(User::factory()->role('bod')->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('nav', []));
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

    private function category(string $name, ?string $description = null): PackagingCategory
    {
        return PackagingCategory::query()->create([
            'name' => $name,
            'description' => $description,
        ]);
    }

    private function item(string $code, string $name, PackagingCategory $category, string $branch): PackagingItem
    {
        return PackagingItem::query()->create([
            'code' => $code,
            'name' => $name,
            'category_id' => $category->getKey(),
            'branch_class' => $branch,
        ]);
    }
}
