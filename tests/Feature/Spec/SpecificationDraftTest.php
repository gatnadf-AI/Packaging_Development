<?php

declare(strict_types=1);

namespace Tests\Feature\Spec;

use App\Domain\Access\ChangeRefused;
use App\Domain\Access\RecordHasBeenChanged;
use App\Domain\MasterData\BranchClass;
use App\Domain\Specification\SpecificationService;
use App\Domain\Specification\SpecificationStatus;
use App\Models\MaterialType;
use App\Models\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\ParameterGroup;
use App\Models\Product;
use App\Models\Specification;
use App\Models\SpecificationSize;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Msg;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * SCR-4.3's authoring half (FSD-404, FSD-406, G-12, TSD-430..432) before the
 * screen that drives it exists.
 *
 * Four readings are pinned here, and all four come from the signed documents
 * rather than from what a form would find convenient.
 *
 * The number is taken when the draft is created, not when it is submitted.
 * FSD-404 describes QC/BP/NNN/YY as the specification's own code, §2.3 lists it
 * among the fields a screen shows rather than accepts, and the allocator's
 * sequence is per year — a document that reached 2027 unsigned would otherwise
 * be numbered out of the order it was started in. The consequence is that
 * SCR-4.3 prints a real code on its first save, and that a draft abandoned in
 * the year it was opened has consumed a number for good (FSD-404: "never reused
 * even when a specification is withdrawn").
 *
 * A revision is a second row under the SAME number (FSD-406: "same number,
 * version suffix"), so the allocator is not consulted a second time and the
 * printed code never changes. TSD T-07 reaches the same shape from the other
 * direction (a cancelled document resumes as "DRAFT (version+1)", both versions
 * linked in history), and revision_of_id is where the two sentences meet.
 *
 * Blocks are replaced, not appended. The migration's four children RESTRICT
 * against their parent instead of cascading precisely because a half-written
 * document is the failure that constraint makes loud, so the editor deletes and
 * re-inserts inside one transaction and the version a reviewer read is the
 * version still on the table afterwards.
 *
 * A draft names its author and nobody else. §7.2's signature row is "Dibuat oleh
 * / Direview oleh / Disetujui oleh", and its rule is that the names come from the
 * recorded decisions — for a document with no decisions, the two review chips
 * have no person to show, and no column here could hold one they typed instead.
 * The chain that fills them is Phase 3.
 */
final class SpecificationDraftTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private PackagingItem $item;

    private Product $product;

    private MaterialType $materialType;

    private Unit $millimetre;

    private Unit $gram;

    private ParameterGroup $fisik;

    private ParameterGroup $organoleptis;

    private User $author;

    private SpecificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // The code printed on the document names the year it was opened in, so
        // the clock is pinned: a test that reads QC/BP/001/26 on a machine whose
        // date has moved to 2027 would fail for the calendar and not for the code.
        $this->travelTo(CarbonImmutable::parse('2026-05-04 09:00:00', 'Asia/Jakarta'));

        $this->seedReferenceData();

        // The reference lists the printed form needs, read from the rows the
        // seeder is trusted to have put there rather than created a second time.
        $this->millimetre = Unit::query()->where('symbol', 'mm')->sole();
        $this->gram = Unit::query()->where('symbol', 'g')->sole();
        $this->fisik = ParameterGroup::query()->where('name', 'Fisik')->sole();
        $this->organoleptis = ParameterGroup::query()->where('name', 'Organoleptis')->sole();
        $this->materialType = MaterialType::query()->where('name', 'PET')->sole();

        $this->item = PackagingItem::query()->create([
            'code' => 'RMPET0001',
            'name' => 'Botol PET 200 ml',
            'category_id' => PackagingCategory::query()->create(['name' => 'Primer'])->getKey(),
            'branch_class' => BranchClass::Both->value,
        ]);

        $this->product = Product::query()->create([
            'code' => 'BRGMIST001',
            'name' => 'Camellia Body Mist 200 ml',
            'brand' => 'Camellia',
        ]);

        // author_id is a person, not a permission: the matrix decides who may
        // reach the screen, and by the time this service is called that has been
        // settled. qc_supervisor is the identity FSD §6 gives specification.manage
        // at FULL, so the test writes as the grid lets that person write.
        $this->author = User::factory()->role('qc_supervisor')->create(['name' => 'Pembuat SBK']);

        $this->service = app(SpecificationService::class);
    }

    public function testADraftTakesItsNumberTheMomentItIsCreated(): void
    {
        $specification = $this->service->createDraft($this->draft(), $this->author);

        $this->assertSame(
            'QC/BP/001/26',
            $specification->number,
            'FSD-404: the draft is numbered the moment it is created, from the sequence of its own year'
        );
        $this->assertSame(1, $specification->version);
        $this->assertSame(SpecificationStatus::Draft, $specification->doc_status);
        $this->assertSame($this->author->getKey(), $specification->author_id);
        $this->assertNull($specification->revision_of_id, 'version 1 revises nothing');
        $this->assertSame(1, $specification->row_version, 'FSD §2.5 puts a token on every editable record, a draft included');

        $this->assertSame(
            2,
            (int) DB::table('numbering_sequences')->where('scope', 'sbk')->value('next_value'),
            'the draft consumed its number: a document abandoned this year must not hand it to the next one (FSD-404)'
        );
    }

    public function testThePrintedCodeIsNotSomethingTheCallerCanType(): void
    {
        // §2.3 lists No. QC/BP/NNN/YY as "(computed)", and TSD-432 says a code is
        // immutable after allocation. A payload that names a number is a caller
        // asking the system to print whatever it was told, which is how two
        // documents end up carrying one code — the collision BR-13 exists to stop
        // would then be written in by hand rather than produced by a race.
        $specification = $this->service->createDraft(
            array_merge($this->draft(), ['number' => 'QC/BP/777/26']),
            $this->author,
        );

        $this->assertSame('QC/BP/001/26', $specification->number);

        $saved = $this->service->saveDraft(
            $specification,
            array_merge($this->draft(), ['number' => 'QC/BP/777/26', 'material_name' => 'Botol PET 250 ml']),
            $this->author,
            $specification->row_version,
        );

        $this->assertSame('QC/BP/001/26', $saved->number, 'TSD-432: a wrong code is corrected by cancelling and re-issuing, never by editing it');
        $this->assertSame('Botol PET 250 ml', $saved->material_name, 'the fields a draft does own still save');
    }

    public function testTheThreeBlocksAreWrittenInTheOrderTheFormPrintsThem(): void
    {
        $specification = $this->service->createDraft($this->draft(), $this->author);

        $this->assertSame(
            [['code' => 'TL', 'sort' => 1], ['code' => 'OD', 'sort' => 2]],
            $specification->sizes()->get(['code', 'sort'])
                ->map(static fn (SpecificationSize $row): array => $row->only(['code', 'sort']))
                ->all(),
            'the sort column is the paper form reading order (TSD §7.2), so it is the payload order and not something a caller has to number'
        );

        $this->assertSame(
            ['Berat botol', 'Warna'],
            $specification->parameters()->pluck('parameter')->all(),
        );

        $this->assertSame(
            [
                ['group' => 'Fisik', 'unit' => 'g', 'is_numeric' => true],
                ['group' => 'Organoleptis', 'unit' => null, 'is_numeric' => false],
            ],
            $specification->parameters()->with(['group', 'unit'])->get()
                ->map(fn ($row): array => [
                    'group' => $row->group?->name,
                    // A measurement names its unit; a judgement has none, which is
                    // the difference §7.2 prints between "compare min/max" and
                    // "answer against criteria".
                    'unit' => $row->unit?->symbol,
                    'is_numeric' => (bool) $row->is_numeric,
                ])
                ->all(),
        );

        $component = $specification->components()->sole();

        $this->assertSame('Botol', $component->component);
        $this->assertSame('PET', $component->material, 'the component block holds the client\'s own wording, not the reference list (TSD §3.3.3)');
        $this->assertSame('100.0000', $component->percent);
    }

    public function testADraftWithNothingInTheBlocksIsStillADraft(): void
    {
        // No rule in the signed documents closes a draft. T-01's "all mandatory
        // fields valid" is the gate on SUBMIT, and the approval engine is Phase 3,
        // so a half-entered SBK that a QC officer saved at lunchtime is a document
        // the system has to keep rather than refuse.
        $specification = $this->service->createDraft(
            ['item_id' => $this->item->getKey(), 'product_id' => $this->product->getKey(), 'material_name' => 'Botol PET 200 ml'],
            $this->author,
        );

        $this->assertSame(0, $specification->sizes()->count());
        $this->assertSame(0, $specification->parameters()->count());
        $this->assertSame(0, $specification->components()->count());
        $this->assertSame(SpecificationStatus::Draft, $specification->doc_status);
    }

    public function testABlockRowTheDatabaseRefusesLeavesNoSpecificationBehind(): void
    {
        $draft = $this->draft();

        // A unit id that is not in the reference list. The point is not the
        // specific column — it is that the parent and its children are one
        // transaction, so a refused child cannot leave a document whose blocks
        // are quietly missing (the defect FR-603 complains about is a count that
        // does not match the rows, and it starts as a half-saved document).
        $draft['sizes'][1]['unit_id'] = 999999;

        try {
            $this->service->createDraft($draft, $this->author);
            $this->fail('a block row pointing at a unit that does not exist was accepted');
        } catch (QueryException $exception) {
            // 1452 is "child row: a foreign key constraint fails". Anything else
            // — 1146's missing table above all — is not the fact under test and
            // would let a broken test look like a passing one.
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1452) {
                throw $exception;
            }

            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DB::table('specifications')->count());
        $this->assertSame(0, DB::table('specification_sizes')->count());
        $this->assertSame(0, DB::table('specification_parameters')->count());
        $this->assertSame(0, DB::table('specification_components')->count());
    }

    public function testSavingADraftReplacesItsBlocksRatherThanAppendingToThem(): void
    {
        $specification = $this->service->createDraft($this->draft(), $this->author);

        $changed = $this->draft();
        $changed['sizes'] = [['code' => 'TH', 'label' => 'Tinggi badan botol', 'nominal' => '118.0', 'unit_id' => $this->millimetre->getKey()]];
        $changed['components'] = [];

        $saved = $this->service->saveDraft($specification, $changed, $this->author, $specification->row_version);

        $this->assertSame(['TH'], $saved->sizes()->pluck('code')->all());
        $this->assertSame(0, $saved->components()->count());

        // The rows the save removed are gone rather than orphaned: RESTRICT keeps
        // a DELETE from taking the parent with it, and a child left pointing at an
        // old version of the block is a row nobody can reach from the screen.
        $this->assertSame(1, DB::table('specification_sizes')->where('specification_id', $specification->getKey())->count());
        $this->assertSame(
            2,
            (int) DB::table('specifications')->where('id', $specification->getKey())->value('row_version'),
            'the parent moved with its blocks, so a second writer holding the old token is refused'
        );
    }

    public function testADraftRefusesToRewriteTheBlocksOfAWinnerItLostTo(): void
    {
        $specification = $this->service->createDraft($this->draft(), $this->author);

        $this->service->saveDraft(
            $specification,
            array_merge($this->draft(), ['material_name' => 'Ditulis lebih dulu']),
            $this->author,
            $specification->row_version,
        );

        $stale = $this->draft();
        $stale['sizes'] = [['code' => 'C', 'nominal' => '140.0', 'unit_id' => $this->millimetre->getKey()]];

        $refused = null;

        try {
            // The token from before the first save landed.
            $this->service->saveDraft($specification->fresh(), $stale, $this->author, 1);
        } catch (RecordHasBeenChanged $exception) {
            $refused = $exception;
        }

        $this->assertInstanceOf(
            RecordHasBeenChanged::class,
            $refused,
            'a save carrying a token the row no longer holds was accepted'
        );

        // The handler in bootstrap/app.php offers "Kirim ulang saya", and the
        // sentence is only honest if the live token travels with the refusal.
        $this->assertSame(2, $refused->currentVersion());

        $loserTried = Specification::query()->findOrFail($specification->getKey());

        $this->assertSame(
            ['TL', 'OD'],
            $loserTried->sizes()->pluck('code')->all(),
            'the refused save must not have replaced the blocks on its way out'
        );

        $this->assertSame('Ditulis lebih dulu', $loserTried->material_name);
    }

    public function testOnlyADraftIsEditableOnThisPath(): void
    {
        $specification = $this->service->createDraft($this->draft(), $this->author);

        // G-12: "Doc status is DRAFT or the user holds an explicit correction
        // right", else MSG-042. The engine that writes any other status is Phase
        // 3, so this row is moved by hand — the same way MatrixAccessExpansionTest
        // writes a PENDING — and what is under test is the editor's own gate.
        DB::table('specifications')->where('id', $specification->getKey())->update(['doc_status' => 'CLOSED']);

        /** @var Specification $locked */
        $locked = Specification::query()->findOrFail($specification->getKey());

        try {
            $this->service->saveDraft($locked, array_merge($this->draft(), ['material_name' => 'Diubah diam-diam']), $this->author, $locked->row_version);
            $this->fail('an approved specification was edited in place');
        } catch (ChangeRefused $refused) {
            $this->assertSame(Msg::get('MSG-042'), $refused->getMessage());
        }

        $this->assertSame(
            'Botol PET 200 ml',
            (string) DB::table('specifications')->where('id', $specification->getKey())->value('material_name'),
        );
    }

    public function testRevisingAnApprovedSpecificationStartsANewVersionUnderTheSameNumber(): void
    {
        $first = $this->service->createDraft($this->draft(), $this->author);

        DB::table('specifications')->where('id', $first->getKey())->update(['doc_status' => 'CLOSED']);

        /** @var Specification $approved */
        $approved = Specification::query()->findOrFail($first->getKey());

        $second = $this->service->revise(
            $approved,
            array_merge($this->draft(), ['material_name' => 'Botol PET 200 ml, leher lebih tinggi', 'change_note' => 'Revisi tinggi leher botol']),
            $this->author,
        );

        $this->assertSame($approved->number, $second->number, 'FSD-406: same number, version suffix');
        $this->assertSame(2, $second->version);
        $this->assertSame(SpecificationStatus::Draft, $second->doc_status, 'a revision restarts its own approval, so it begins as a draft again');
        $this->assertSame($approved->getKey(), $second->revision_of_id, 'T-07: both versions linked in history');
        $this->assertSame('Revisi tinggi leher botol', $second->change_note);

        // The version under review does not move. Prior versions stay retrievable
        // (FSD-406) and every inspection records the exact version it used, so a
        // revision that touched its parent would rewrite what somebody else signed.
        $this->assertSame(
            ['doc_status' => 'CLOSED', 'material_name' => 'Botol PET 200 ml', 'version' => 1],
            (array) DB::table('specifications')->where('id', $approved->getKey())->first(['doc_status', 'material_name', 'version']),
        );

        $this->assertSame(
            2,
            (int) DB::table('numbering_sequences')->where('scope', 'sbk')->value('next_value'),
            'a revision takes no number: the allocator is asked once per document, at its first version'
        );
    }

    public function testARevisionCarriesTheBlocksWithoutNeedingThemResent(): void
    {
        $first = $this->service->createDraft($this->draft(), $this->author);

        DB::table('specifications')->where('id', $first->getKey())->update(['doc_status' => 'CLOSED']);

        /** @var Specification $approved */
        $approved = Specification::query()->findOrFail($first->getKey());

        // The screen's "Buat versi baru" button (MSG-042's action) opens a draft
        // that reads as the document it revises, so a revision whose payload names
        // no blocks is the common case rather than an edge: the parent's rows are
        // copied, and the version that reaches approval is not an empty form.
        $second = $this->service->revise($approved, ['change_note' => 'Perubahan kecil'], $this->author);

        $this->assertSame(
            $approved->sizes()->pluck('code')->all(),
            $second->sizes()->pluck('code')->all(),
        );

        $this->assertSame(
            $approved->parameters()->pluck('parameter')->all(),
            $second->parameters()->pluck('parameter')->all(),
        );

        $this->assertSame(
            $approved->components()->pluck('component')->all(),
            $second->components()->pluck('component')->all(),
        );

        $this->assertSame(
            'Botol PET 200 ml',
            $second->material_name,
            'a revision with nothing said about the header starts from the header it revises'
        );

        // Copied rows are the new version's own rows, not shared ones: the ids
        // differ, and the parent keeps its two sizes when the child changes them.
        $this->assertNotEquals(
            $approved->sizes()->pluck('id')->all(),
            $second->sizes()->pluck('id')->all(),
        );
    }

    public function testTheAuthorStoredIsThePersonWhoWroteThisVersion(): void
    {
        $first = $this->service->createDraft($this->draft(), $this->author);

        DB::table('specifications')->where('id', $first->getKey())->update(['doc_status' => 'CLOSED']);

        $successor = User::factory()->role('qc_manager')->create(['name' => 'Pembuat versi dua']);

        /** @var Specification $approved */
        $approved = Specification::query()->findOrFail($first->getKey());

        $second = $this->service->revise($approved, ['change_note' => 'Diubah orang lain'], $successor);

        $this->assertSame($successor->getKey(), $second->author_id);
        $this->assertSame($this->author->getKey(), $approved->fresh()->author_id);
    }

    public function testADraftNamesNoReviewerAndNoApproverBecauseNoDecisionHasBeenRecorded(): void
    {
        $specification = $this->service->createDraft($this->draft(), $this->author);

        // §7.2: the signature row's names and roles "come from the recorded
        // decisions, never typed on the PDF". A draft has one decision — who wrote
        // it — so the two other chips have nothing to show. This is the schema's
        // own silence, asserted here so that a later column cannot quietly become
        // a box a form types a reviewer's name into.
        $columns = array_map(
            static fn (object $row): string => (string) array_change_key_case((array) $row, CASE_LOWER)['column_name'],
            DB::select(
                "select column_name from information_schema.columns
                  where table_schema = database() and table_name = 'specifications'"
            )
        );

        $typed = array_values(array_filter(
            $columns,
            static fn (string $column): bool => (bool) preg_match('/(review|approv|check)/', $column)
        ));

        $this->assertSame([], $typed, 'a specification column named '.implode(', ', $typed).' would be a signature typed rather than decided');
        $this->assertSame('Pembuat SBK', $specification->author->name);
    }

    public function testEveryAuthoringStepLeavesItsOwnAuditRow(): void
    {
        $specification = $this->service->createDraft($this->draft(), $this->author);

        $saved = $this->service->saveDraft(
            $specification,
            array_merge($this->draft(), ['material_name' => 'Botol PET 220 ml']),
            $this->author,
            $specification->row_version,
        );

        DB::table('specifications')->where('id', $saved->getKey())->update(['doc_status' => 'CLOSED']);

        /** @var Specification $approved */
        $approved = Specification::query()->findOrFail($saved->getKey());

        $this->service->revise($approved, ['change_note' => 'Revisi pertama'], $this->author);

        $actions = DB::table('audit_entries')
            ->where('target_type', Specification::class)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(
            [AuditAction::SpecCreated->value, AuditAction::SpecUpdated->value, AuditAction::SpecRevised->value],
            $actions,
            'the trail must read created, changed, revised — in that order, one row per act (FSD-907, TSD-1101)'
        );

        $update = DB::table('audit_entries')
            ->where('action', AuditAction::SpecUpdated->value)
            ->where('target_id', $saved->getKey())
            ->sole();

        // The blocks are the document, and a trail that only said "changed" would
        // leave a reader unable to tell a renamed material from a rewritten
        // parameter table. TSD-1101 keeps it to what changed: the header column
        // here, and the block counts, never the whole sheet.
        $this->assertSame(
            ['material_name' => 'Botol PET 200 ml'],
            json_decode((string) $update->before, true),
        );

        $this->assertSame(
            ['material_name' => 'Botol PET 220 ml'],
            json_decode((string) $update->after, true),
        );

        $revision = DB::table('audit_entries')
            ->where('action', AuditAction::SpecRevised->value)
            ->sole();

        $this->assertSame(
            $approved->getKey(),
            (int) $revision->target_id,
            'the trail for a revision is read against the version that was superseded, because that is the row a person was looking at'
        );
    }

    /**
     * The payload SCR-4.3 posts: the header of the printed form plus its three
     * repeating blocks, in the order the paper document lists them (FSD §5).
     *
     * @return array<string, mixed>
     */
    private function draft(): array
    {
        return [
            'item_id' => $this->item->getKey(),
            'product_id' => $this->product->getKey(),
            'material_name' => 'Botol PET 200 ml',
            'material_type_id' => $this->materialType->getKey(),
            'sizes' => [
                ['code' => 'TL', 'label' => 'Tinggi total', 'nominal' => '120.5', 'min' => '120.0', 'max' => '121.0', 'unit_id' => $this->millimetre->getKey()],
                ['code' => 'OD', 'label' => 'Diameter luar', 'nominal' => '45.0', 'unit_id' => $this->millimetre->getKey()],
            ],
            'parameters' => [
                [
                    'group_id' => $this->fisik->getKey(),
                    'parameter' => 'Berat botol',
                    'spec_text' => '18 - 20 g',
                    'min' => '18.0',
                    'max' => '20.0',
                    'unit_id' => $this->gram->getKey(),
                    'criteria' => 'Dalam rentang',
                    'is_numeric' => true,
                ],
                [
                    'group_id' => $this->organoleptis->getKey(),
                    'parameter' => 'Warna',
                    'spec_text' => 'Sesuai master',
                    'criteria' => 'Sama dengan contoh',
                    'is_numeric' => false,
                ],
            ],
            'components' => [
                ['component' => 'Botol', 'material' => 'PET', 'percent' => '100'],
            ],
        ];
    }
}
