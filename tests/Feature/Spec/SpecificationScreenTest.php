<?php

declare(strict_types=1);

namespace Tests\Feature\Spec;

use App\Domain\MasterData\BranchClass;
use App\Domain\Specification\SpecificationService;
use App\Models\MaterialType;
use App\Models\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\ParameterGroup;
use App\Models\Product;
use App\Models\Specification;
use App\Models\Unit;
use App\Models\User;
use App\Support\Msg;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * SCR-4.3's two screens — /spec and /spec/{id} — against FSD-404, FSD-406, G-12
 * and §2.4/§2.5's list and form contracts.
 *
 * The service was proven in SpecificationDraftTest; what is under test here is
 * what only a screen can get wrong: which fields a person can reach, which ones
 * the server refuses to read from a payload, and what the answer looks like when
 * a save loses.
 *
 * Two absences are asserted as loudly as the presence of anything, because both
 * are requirements stated as things that must not happen. There is no DELETE verb
 * under /spec — FSD-404's number is never reused and the withdrawal that retires a
 * specification is the approval engine's act, so this screen has no way to remove a
 * sheet. And the register column FSD §9 calls "approvers" is not rendered, because
 * no column in the schema can name an approver yet (SpecificationDraftTest reads
 * that off information_schema); printing the author under that heading would be the
 * machine value §2.2 refuses, in sentence form.
 *
 * The blocks are replaced whole, so a row the form leaves unnamed stops existing.
 * That is why the refusals below assert that NOTHING was saved rather than that the
 * bad row was skipped: MSG-030's "submit with gaps" and half a printed document
 * cannot both be true.
 */
final class SpecificationScreenTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private Unit $millimetre;

    private Unit $gram;

    private ParameterGroup $fisik;

    private ParameterGroup $organoleptis;

    private MaterialType $pet;

    private PackagingItem $item;

    private Product $product;

    private User $author;

    private User $reader;

    private User $refused;

    private SpecificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // The printed code names the year the sheet was opened in, so the clock is
        // pinned rather than read from the machine it runs on.
        $this->travelTo(CarbonImmutable::parse('2026-05-04 09:00:00', 'Asia/Jakarta'));

        $this->seedReferenceData();

        $this->millimetre = Unit::query()->where('symbol', 'mm')->sole();
        $this->gram = Unit::query()->where('symbol', 'g')->sole();
        $this->fisik = ParameterGroup::query()->where('name', 'Fisik')->sole();
        $this->organoleptis = ParameterGroup::query()->where('name', 'Organoleptis')->sole();
        $this->pet = MaterialType::query()->where('name', 'PET')->sole();

        $this->item = PackagingItem::query()->create([
            'code' => 'RMPET0001',
            'name' => 'Botol PET 200 ml',
            'category_id' => PackagingCategory::query()->create(['name' => 'Primer'])->getKey(),
            'branch_class' => BranchClass::Both->value,
        ]);

        $this->product = Product::query()->create([
            'code' => 'BRGMIST001',
            'name' => 'Camellia Body Mist 200 ml',
        ]);

        // FSD §6 gives specification.manage an F to qc_supervisor, qc_manager and
        // qa_manager, an R to nine more identities and a B to scm_staff and BOD.
        // One of each, and the grid chose them.
        $this->author = User::factory()->role('qc_supervisor')->create(['name' => 'Pembuat SBK']);
        $this->reader = User::factory()->role('pd_staff')->create(['name' => 'Pembaca SBK']);
        $this->refused = User::factory()->role('bod')->create(['name' => 'Bukan pemegang sel']);

        $this->service = app(SpecificationService::class);
    }

    public function testTheListOpensForTheCellThatMayOnlyLook(): void
    {
        $specification = $this->sheet();

        $this->actingAs($this->reader)
            ->get('/spec')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Spec/List')
                ->where('screen', 'SCR-4.3')
                ->where('items.total', 1)
                ->where('items.data.0.id', $specification->getKey())
                ->where('can.write', false)
            );
    }

    public function testTheListRefusesAnAccountWhoseCellIsNone(): void
    {
        $this->actingAs($this->refused)->get('/spec')
            ->assertRedirect(route('dashboard'));

        $this->assertSame(Msg::get('MSG-010'), session('error'));
    }

    public function testTheRowCarriesTheRegisterColumnsTheSchemaCanFillAndNoOther(): void
    {
        $specification = $this->sheet();

        $this->actingAs($this->author)
            ->get('/spec')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data.0', fn (Assert $row) => $row
                    ->where('id', $specification->getKey())
                    ->where('number', 'QC/BP/001/26')
                    ->where('version', 1)
                    // §2.2.1: a status prints as its Indonesian word, never as the column value.
                    ->where('status', 'DRAFT')
                    ->where('status_label', 'Draft')
                    ->where('item_code', 'RMPET0001')
                    ->where('item_name', 'Botol PET 200 ml')
                    ->where('effective_date', '2026-05-01')
                    ->where('row_version', 1)
                    ->etc()
                )
                // FSD §9's register names an approver per row. Nothing in this schema
                // can answer that yet, so the column is absent rather than filled with
                // the author: the two are different people and only one is recorded.
                ->missing('items.data.0.approver')
                ->missing('items.data.0.approver_name')
                ->where('columns.searchable', ['number', 'item_name'])
                ->where('columns.sortable', ['number', 'item_name', 'version', 'doc_status', 'effective_date'])
            );
    }

    public function testTheListFiltersByCategoryStatusAndEffectiveDate(): void
    {
        $other = PackagingCategory::query()->create(['name' => 'Sekunder']);

        // Two documents, one in each category and one in each of the two states
        // this filter list can name. The draft has to be made here rather than
        // assumed from another test: a register with one row in it answers every
        // filter with either 1 or 0, and the pair cannot tell a working filter
        // from one that quietly matches nothing.
        $this->sheet();

        $second = $this->sheet('Sachet aluminium 10 g', $other->getKey());

        DB::table('specifications')->where('id', $second->getKey())->update(['doc_status' => 'CLOSED']);

        $this->assertSame(1, $this->rowCount('?category='.(string) $other->getKey()), 'the category filter is not the item category');
        $this->assertSame(1, $this->rowCount('?status=CLOSED'), 'the status filter does not speak the column words');
        $this->assertSame(1, $this->rowCount('?status=DRAFT'), 'the status filter lost the draft');
        $this->assertSame(2, $this->rowCount('?date_from=2026-05-01&date_to=2026-05-31'), 'the window is not over the effective date');
        $this->assertSame(0, $this->rowCount('?date_from=2026-06-01'), 'a window with nothing inside it still answered with rows');

        // A word the column does not hold is not a filter: it answers as though
        // nothing had been asked, not as though nothing matched.
        $this->assertSame(2, $this->rowCount('?status=APPROVED'), 'the approval engine vocabulary leaked into a refused filter');
    }

    public function testTheRegisterCanBeNarrowedToOneItemAndRefusesToGuessAtOne(): void
    {
        // SCR-4.2's "Spesifikasi (link count)" column is a link, and the address
        // behind it is this list with one filter set. The number beside an item
        // and the rows behind its link have to be the same question asked twice,
        // or FR-603's defect comes back wearing a hyperlink — which is why the
        // pair is asserted together here and in PackagingItemScreenTest.
        //
        // The filter is the item's id and not its name, and the second row below
        // is filed under an item with the same words in it on purpose: a name
        // filter would answer 2 for both, and 2 is not what the link promised.
        $twin = PackagingItem::query()->create([
            'code' => 'RMPET0002',
            'name' => 'Botol PET 200 ml',
            'category_id' => $this->item->category_id,
            'branch_class' => BranchClass::Both->value,
        ]);

        $this->sheet();

        $second = $this->sheet();
        DB::table('specifications')->where('id', $second->getKey())->update(['item_id' => $twin->getKey()]);

        $this->assertSame(1, $this->rowCount('?item='.$this->item->getKey()), 'the item filter is not on item_id');
        $this->assertSame(1, $this->rowCount('?item='.$twin->getKey()), 'the item filter matched a name rather than the row');

        // An item that no sheet names answers with an empty register, not with
        // the whole one: a filter nobody recognises is not a filter that passed.
        $this->assertSame(0, $this->rowCount('?item=999999'), 'a filter with no rows behind it answered with rows');

        // …while a value that cannot be an id at all was never a filter, and the
        // screen says so by showing both rows and an empty box.
        $this->actingAs($this->author)
            ->get('/spec?item=tidak-angka')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.total', 2)
                ->where('filters.item', '')
            );
    }

    public function testANewSheetMayStillBeWrittenAboutAnItemTheMasterHasRetired(): void
    {
        // FSD-402's sentence is "inactive items stop appearing in new assignment
        // pickers", and a specification sheet is not an assignment. Two readings
        // had to be weighed, and the one that was not taken would have been a
        // rule the signed documents do not state: hiding a retired item would
        // also break the revision that has to name it again, because MSG-042
        // opens the new sheet as the document it replaces. So one list, retired
        // rows included, is the decision — recorded in the README and pinned
        // here rather than left to whichever screen builds its own options.
        DB::table('packaging_items')->where('id', $this->item->getKey())->update(['is_active' => 0]);

        $this->actingAs($this->author)
            ->get('/spec/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reference.items.0.name', 'Botol PET 200 ml'));

        $this->actingAs($this->author)
            ->post('/spec', $this->payload())
            ->assertRedirect();

        /** @var Specification $specification */
        $specification = Specification::query()->sole();

        $this->assertSame($this->item->getKey(), (int) $specification->item_id, 'a retired item lost its sheet');
    }

    public function testTheListSearchesTheNumberAndTheItemNameAndNothingElse(): void
    {
        $this->sheet();

        $this->assertSame(1, $this->rowCount('?q=qc/bp/001'), 'search lost the printed code');
        $this->assertSame(1, $this->rowCount('?q=botol pet'), 'search lost the item name');

        // Jenis bahan is printed on the sheet but is not part of the promise: the
        // pair every other list in this application searches is the code and the
        // name, and widening it is a result count nobody can predict.
        $this->assertSame(0, $this->rowCount('?q=hdpe'));
    }

    public function testTheDocumentOpensWithItsHeaderAndItsThreeBlocks(): void
    {
        $specification = $this->sheet();

        $this->actingAs($this->author)
            ->get('/spec/'.$specification->getKey())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Spec/Document')
                ->where('screen', 'SCR-4.3')
                ->where('specification.id', $specification->getKey())
                ->where('specification.number', 'QC/BP/001/26')
                ->where('specification.version', 1)
                ->where('specification.doc_status', 'DRAFT')
                ->where('specification.doc_status_label', 'Draft')
                ->where('specification.item_id', $this->item->getKey())
                ->where('specification.product_id', $this->product->getKey())
                ->where('specification.effective_date', '2026-05-01')
                // §5's signature row: Dibuat oleh is the only chip a draft can fill.
                ->where('specification.author.name', 'Pembuat SBK')
                ->where('specification.reviewer', null)
                ->where('specification.approver', null)
                ->where('specification.row_version', 1)
                // The blocks arrive in the order the sheet prints them.
                ->where('specification.sizes.0.code', 'TL')
                ->where('specification.sizes.1.code', 'OD')
                ->where('specification.parameters.0.parameter', 'Warna')
                ->where('specification.parameters.1.parameter', 'Berat botol')
                ->where('specification.components.0.percent', '100.0000')
                ->where('can.write', true)
                ->where('can.editable', true)
                ->where('can.revise', false)
                // The pickers are the master lists the form chooses from, so the
                // page never has to invent a vocabulary of its own.
                ->has('reference.items')
                ->has('reference.products')
                ->has('reference.material_types')
                ->has('reference.units')
                ->has('reference.methods')
                ->has('reference.parameter_groups')
            );
    }

    public function testAReaderOpensTheSameSheetWithNothingTypedIntoIt(): void
    {
        $specification = $this->sheet();

        $this->actingAs($this->reader)
            ->get('/spec/'.$specification->getKey())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Spec/Document')
                ->where('can.write', false)
                ->where('can.editable', false)
                ->where('can.revise', false)
                // §2.4's read-only view is the same layout, not a summary of it.
                ->where('specification.sizes.0.code', 'TL')
            );
    }

    public function testABlankSheetIsOpenedWithoutConsumingANumber(): void
    {
        $this->actingAs($this->author)
            ->get('/spec/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Spec/Document')
                ->where('specification', null)
                ->where('can.create', true)
                ->where('can.editable', true)
            );

        // The number is allocated when the draft is CREATED, which the screen's
        // first save is (§2.3 computes it): opening a blank sheet has to leave the
        // sequence untouched, or every abandoned click on "SBK baru" burns a code.
        $this->assertSame(0, DB::table('specifications')->count());
        $this->assertSame(
            0,
            DB::table('numbering_sequences')->where('scope', 'sbk')->count(),
            'the blank sheet had already taken a number from the year'
        );
    }

    public function testTheFirstSaveOpensTheSheetWithItsNumberAlreadyOnIt(): void
    {
        $this->actingAs($this->author)
            ->post('/spec', $this->payload())
            ->assertRedirect();

        /** @var Specification $specification */
        $specification = Specification::query()->sole();

        $this->assertSame('QC/BP/001/26', $specification->number);
        $this->assertSame(1, $specification->version);
        $this->assertSame('DRAFT', $specification->doc_status->value);
        $this->assertSame($this->author->getKey(), $specification->author_id);

        $this->assertSame(['TL', 'OD'], $specification->sizes()->orderBy('sort')->pluck('code')->all());
        $this->assertSame(['Warna', 'Berat botol'], $specification->parameters()->orderBy('sort')->pluck('parameter')->all());
        $this->assertSame(['Botol'], $specification->components()->orderBy('sort')->pluck('component')->all());

        $this->actingAs($this->author)->get('/spec/'.$specification->getKey())->assertOk();
    }

    public function testTheSheetDoesNotTakeItsNumberItsVersionOrItsStatusFromTheForm(): void
    {
        // TSD-432 makes an allocated code immutable and §4.1 makes doc_status the
        // engine's column. Neither is a box on the form, so neither is a payload key.
        $this->actingAs($this->author)
            ->post('/spec', $this->payload() + [
                'number' => 'QC/BP/777/26',
                'version' => 9,
                'doc_status' => 'CLOSED',
            ])
            ->assertRedirect();

        /** @var Specification $specification */
        $specification = Specification::query()->sole();

        $this->assertSame('QC/BP/001/26', $specification->number, 'a payload typed its own printed code');
        $this->assertSame(1, $specification->version);
        $this->assertSame('DRAFT', $specification->doc_status->value, 'a draft approved itself through a form field');

        $this->assertSame(
            2,
            (int) DB::table('numbering_sequences')->where('scope', 'sbk')->value('next_value')
        );
    }

    public function testAHeaderFieldLeftBlankComesBackUnderThatField(): void
    {
        $payload = $this->payload();
        unset($payload['material_name']);

        $this->actingAs($this->author)
            ->from('/spec/create')
            ->post('/spec', $payload)
            ->assertRedirect('/spec/create')
            ->assertSessionHasErrors(['material_name' => Msg::get('MSG-030')]);

        $this->assertSame(0, DB::table('specifications')->count(), 'a sheet with a gap in it was saved anyway');
    }

    public function testABlockRowWithNoCodeIsRefusedBeforeAnyOfTheSheetIsSaved(): void
    {
        $payload = $this->payload();
        $payload['sizes'][1]['code'] = '';

        $this->actingAs($this->author)
            ->from('/spec/create')
            ->post('/spec', $payload)
            ->assertSessionHasErrors(['sizes.1.code' => Msg::get('MSG-030')]);

        // The blocks are the document: a save that skipped the bad row would leave
        // a sheet whose printed order has a hole in it.
        $this->assertSame(0, DB::table('specifications')->count());
        $this->assertSame(0, DB::table('specification_sizes')->count());
        $this->assertSame(0, DB::table('specification_components')->count());
    }

    public function testAPickerIdThatDoesNotExistIsRefusedRatherThanThrownAtTheDatabase(): void
    {
        $payload = $this->payload();
        $payload['sizes'][0]['unit_id'] = 999999;

        $this->actingAs($this->author)
            ->post('/spec', $payload)
            ->assertSessionHasErrors('sizes.0.unit_id');

        $this->assertSame(0, DB::table('specifications')->count());
    }

    public function testSavingAgainstAStaleTokenIsRefusedWithTheCataloguesSentence(): void
    {
        $specification = $this->sheet();

        // Somebody else saved first, and the token the form was opened with is
        // now a version that no longer exists.
        $this->service->saveDraft($specification, $this->payload(), $this->author, $specification->row_version);

        $payload = $this->payload();
        $payload['material_name'] = 'Botol PET 210 ml';

        $this->actingAs($this->author)
            ->from('/spec/'.$specification->getKey())
            ->patch('/spec/'.$specification->getKey(), $payload + ['row_version' => 1])
            ->assertSessionHasErrors(['row_version' => Msg::get('MSG-020')]);

        $this->assertSame(
            'Botol PET 200 ml',
            (string) DB::table('specifications')->where('id', $specification->getKey())->value('material_name'),
            'the loser wrote over the winner'
        );
    }

    public function testALockedDocumentRefusesTheSaveAndPointsAtANewVersion(): void
    {
        $specification = $this->sheet();

        DB::table('specifications')->where('id', $specification->getKey())->update(['doc_status' => 'CLOSED']);

        /** @var Specification $locked */
        $locked = Specification::query()->findOrFail($specification->getKey());

        // G-12's locked half, in the catalogue's own sentence: MSG-042 names the
        // field that raised it, which for a document-level refusal is the row.
        $this->actingAs($this->author)
            ->from('/spec/'.$locked->getKey())
            ->patch('/spec/'.$locked->getKey(), $this->payload() + ['row_version' => $locked->row_version])
            ->assertSessionHasErrors(['id' => Msg::get('MSG-042')]);

        $this->actingAs($this->author)
            ->get('/spec/'.$locked->getKey())
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.editable', false)
                // MSG-042's own action is "Buat versi baru", so the screen that
                // refused the save has to offer the one act that is allowed.
                ->where('can.revise', true)
            );
    }

    public function testRevisingFromTheScreenStartsTheNextVersionUnderTheSameNumber(): void
    {
        $specification = $this->sheet();

        DB::table('specifications')->where('id', $specification->getKey())->update(['doc_status' => 'CLOSED']);

        $payload = $this->payload();
        $payload['material_name'] = 'Botol PET 220 ml';

        $this->actingAs($this->author)
            ->post('/spec/'.$specification->getKey().'/revise', $payload)
            ->assertRedirect();

        /** @var Specification $revision */
        $revision = Specification::query()->where('version', 2)->sole();

        $this->assertSame('QC/BP/001/26', $revision->number, 'FSD-406: the code on paper does not move');
        $this->assertSame('DRAFT', $revision->doc_status->value);
        $this->assertSame($specification->getKey(), $revision->revision_of_id);
        $this->assertSame('Botol PET 220 ml', $revision->material_name);

        // A revision takes no number of its own: the allocator is asked once per
        // document, at its first version.
        $this->assertSame(
            2,
            (int) DB::table('numbering_sequences')->where('scope', 'sbk')->value('next_value')
        );
    }

    public function testThisScreenHasNoWayToDeleteASpecification(): void
    {
        foreach (['spec.index', 'spec.show', 'spec.store', 'spec.update', 'spec.revise'] as $name) {
            $this->assertTrue(
                RouteFacade::has($name),
                "the specification screen offers no {$name}, so the delete check below is green without having looked at anything"
            );
        }

        $removals = array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            static fn (Route $route): bool => in_array('DELETE', $route->methods(), true)
                && str_starts_with($route->uri(), 'spec')
        ));

        $this->assertSame(
            [],
            $removals,
            "FSD-404 refuses to reuse a number and withdrawal is the engine's act: a delete verb here would remove history"
        );
    }

    public function testTheMenuOffersThisScreenToTheCellThatOpensIt(): void
    {
        // Compared against route('spec.index') rather than against '/spec'
        // because the shell is handed the URL, not a path: Navigation builds its
        // hrefs with route(), and a test that re-typed the path would be a second
        // vocabulary that drifts the day APP_URL carries a port or a prefix.
        $this->assertContains(
            route('spec.index'),
            $this->menuHrefs($this->author),
            'the SBK screen is not on the menu its cell opens'
        );

        $this->assertNotContains(
            route('spec.index'),
            $this->menuHrefs($this->refused),
            'BOD was offered a door the grid closes'
        );
    }

    /**
     * The hrefs the shell hands the front end, in menu order.
     *
     * Read from `nav` rather than from a per-page menu prop because FSD-101's
     * menu is shared by the shell (HandleInertiaRequests), not composed by the
     * screen: a screen that could build its own list is the second vocabulary
     * FSD-614 forbids.
     *
     * @return list<string>
     */
    private function menuHrefs(User $user): array
    {
        $page = $this->actingAs($user)->get('/dashboard')->viewData('page');

        $this->assertArrayHasKey('nav', $page['props'], 'the shell is not being handed a menu at all');

        $hrefs = [];

        foreach ($page['props']['nav'] as $group) {
            foreach ($group['items'] as $item) {
                $hrefs[] = $item['href'];
            }
        }

        return $hrefs;
    }

    /**
     * A sheet written through the service, so the screens are tested against a
     * document the domain actually produces rather than a hand-typed row.
     */
    private function sheet(?string $materialName = null, ?int $categoryId = null): Specification
    {
        $payload = $this->payload();

        if ($materialName !== null) {
            $payload['material_name'] = $materialName;
        }

        if ($categoryId !== null) {
            $payload['item_id'] = PackagingItem::query()->create([
                'code' => 'SAKAL0001',
                'name' => $materialName,
                'category_id' => $categoryId,
                'branch_class' => BranchClass::Both->value,
            ])->getKey();
        }

        return $this->service->createDraft($payload, $this->author);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'item_id' => $this->item->getKey(),
            'product_id' => $this->product->getKey(),
            'material_name' => 'Botol PET 200 ml',
            'material_type_id' => $this->pet->getKey(),
            'effective_date' => '2026-05-01',
            'sizes' => [
                ['code' => 'TL', 'label' => 'Tinggi leher', 'nominal' => '12.5', 'min' => '12.0', 'max' => '13.0', 'unit_id' => $this->millimetre->getKey(), 'method_id' => null],
                ['code' => 'OD', 'label' => 'Diameter luar', 'nominal' => '30.0', 'min' => null, 'max' => null, 'unit_id' => $this->millimetre->getKey(), 'method_id' => null],
            ],
            'parameters' => [
                ['group_id' => $this->organoleptis->getKey(), 'parameter' => 'Warna', 'spec_text' => 'Bening', 'min' => null, 'max' => null, 'unit_id' => null, 'method_id' => null, 'criteria' => 'Tidak ada bintik', 'is_numeric' => false],
                ['group_id' => $this->fisik->getKey(), 'parameter' => 'Berat botol', 'spec_text' => null, 'min' => '11.5', 'max' => '12.5', 'unit_id' => $this->gram->getKey(), 'method_id' => null, 'criteria' => 'Dalam rentang', 'is_numeric' => true],
            ],
            'components' => [
                ['component' => 'Botol', 'material' => 'PET', 'percent' => '100', 'note' => null],
            ],
        ];
    }

    private function rowCount(string $query): int
    {
        $page = $this->actingAs($this->author)->get('/spec'.$query)->viewData('page');

        return (int) $page['props']['items']['total'];
    }
}
