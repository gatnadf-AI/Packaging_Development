<?php

declare(strict_types=1);

namespace Tests\Feature\Spec;

use App\Domain\Specification\SpecificationStatus;
use App\Models\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\Product;
use App\Models\Specification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * SCR-4.3's tables (TSD-PDS-003 §3.3.3), tested at the database.
 *
 * §3.3.3 is a list of "columns of consequence" rather than a DDL listing, so
 * these tests split into two kinds and say which kind each one is. Where the
 * signed text states a constraint — `uq(number, version)`, "number sequence per
 * year, never reused", the four children of a specification, the six groups a
 * parameter belongs to — the constraint is asserted because the document
 * demanded it. Where only a column is named, the test asserts the weaker thing
 * the column exists for (a foreign key rejects a row that invents a unit rather
 * than choosing one) and the README records the reading that turned a named
 * column into a NOT NULL.
 *
 * One absence is asserted on purpose: the dimension code is not checked against
 * the five codes §5 lists in a parenthesis. TSD §3.3.3 repeats that list without
 * calling it closed, and Phase 2e imports the client's real workbook into this
 * table — a check invented from an illustrative enumeration would refuse real
 * rows on the strength of a document that never said "only these".
 *
 * Nothing here is the screen's business. A specification cannot be reached by a
 * request in this file, which is the point: the numbering, the versioning and
 * the RESTRICT edges have to hold before a form exists to forget them.
 */
final class SpecificationSchemaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private PackagingItem $item;

    private Product $product;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        // A specification is always about something, so the two records it
        // points at are built here rather than looked for in a seeder: §3.6
        // seeds reference lists and nothing else, and a fixture that borrowed a
        // seeder row would silently break the day that list was edited.
        $category = PackagingCategory::query()->create(['name' => 'Primer']);

        $this->item = PackagingItem::query()->create([
            'code' => 'RMPET0001',
            'name' => 'Botol PET 200 ml',
            'category_id' => $category->getKey(),
            'branch_class' => 'BOTH',
        ]);

        $this->product = Product::query()->create([
            'code' => 'FGBMA2001',
            'name' => 'Camellia Body Mist',
        ]);

        // No role: author_id is a person, not a permission, and a schema test
        // that picked a role would read as though the grid decided who may be
        // named on a document. SCR-4.3's gate is walked in the screen test.
        $this->author = User::factory()->create();
    }

    public function testANumberCarriesSeveralVersionsButNeverTwoRowsAtOneVersion(): void
    {
        $first = $this->specification('QC/BP/001/26', 1);
        $second = $this->specification('QC/BP/001/26', 2);

        // FSD-406: "same number, version suffix", and TSD §3.3.3's
        // uq(number, version) is the whole of that rule written as data. A second
        // row at version 1 is not a new version, it is two documents claiming to
        // be the same one.
        $this->assertNotSame($first, $second);

        $this->assertRejected(
            fn () => $this->specification('QC/BP/001/26', 1),
            'two rows at one (number, version) means two documents claiming to be the same revision'
        );
    }

    public function testADeletedSpecificationDoesNotHandItsNumberBackToTheSequence(): void
    {
        DB::table('numbering_sequences')->insert([
            'scope' => 'sbk',
            'period' => '2026',
            'next_value' => 3,
            'pattern' => 'QC/BP/{N:3}/{YY}',
        ]);

        $this->specification('QC/BP/002/26', 1);

        DB::table('specifications')->where('number', 'QC/BP/002/26')->delete();

        // BR-13 and FSD-404 want a number consumed, not held: "never reused even
        // when a specification is withdrawn". The schema is the half that keeps
        // that honest — the counter is a row of its own that nothing references
        // and nothing rewinds, so emptying the document table cannot pull a
        // number back into circulation. The allocator that reads it is TSD-430.
        $this->assertNull(
            DB::table('specifications')->where('number', 'QC/BP/002/26')->value('number'),
            'the row is still there, so this test is not describing a withdrawal at all'
        );

        $this->assertSame(
            3,
            (int) DB::table('numbering_sequences')
                ->where(['scope' => 'sbk', 'period' => '2026'])
                ->value('next_value'),
            'a counter that rewound when its documents were removed would reissue a withdrawn number'
        );
    }

    public function testAReferencedItemOrProductCannotBeRemovedFromUnderASpecification(): void
    {
        $this->specification('QC/BP/003/26', 1);

        // §3.1: nothing in this system is deleted by cascade. Both edges are
        // tried, because the two tables are retired by different screens and a
        // test that only walked the first row of its own loop would pass with one
        // of the two constraints missing.
        $this->assertRejected(
            fn () => DB::table('packaging_items')->where('id', $this->item->getKey())->delete(),
            'an item a specification describes can still be deleted from under it'
        );

        $this->assertRejected(
            fn () => DB::table('products')->where('id', $this->product->getKey())->delete(),
            'a product a specification describes can still be deleted from under it'
        );

        $this->assertNotNull(DB::table('packaging_items')->find($this->item->getKey()));
        $this->assertNotNull(DB::table('products')->find($this->product->getKey()));
    }

    public function testADocumentRowRefusesToFloatFreeOfTheTwoRecordsItDescribes(): void
    {
        // FSD-409 has the specification pick both a product and a packaging
        // item, so neither is optional. §3.3.3 names the columns and leaves the
        // nullability to the reading recorded in the README.
        $this->assertRejected(
            fn () => $this->specification('QC/BP/004/26', 1, ['item_id' => null]),
            'a specification with no packaging item describes nothing'
        );

        $this->assertRejected(
            fn () => $this->specification('QC/BP/005/26', 1, ['product_id' => null]),
            'a specification with no product cannot be the one FSD-409 says the design review chooses'
        );
    }

    public function testAChildRowCannotOutliveTheSpecificationThatOwnsIt(): void
    {
        $specificationId = $this->specification('QC/BP/006/26', 1);

        DB::table('specification_parameters')->insert($this->parameterRow($specificationId));

        // The children are the document. RESTRICT here also means the draft
        // editor has to replace its rows inside one transaction rather than
        // delete-then-insert across two requests, which is what the service does.
        $this->assertRejected(
            fn () => DB::table('specifications')->where('id', $specificationId)->delete(),
            'a parameter row survived the document it belongs to'
        );
    }

    public function testASizeRowIsAnchoredToTheUnitAndMethodRatherThanRetypingThem(): void
    {
        $specificationId = $this->specification('QC/BP/007/26', 1);

        // TSD §3.1 makes a unit a foreign key and never free text because the
        // inspection form compares a measured value against it; §3.3.3 gives the
        // size row both unit_id and method_id.
        $this->assertRejected(
            fn () => $this->sizeRow($specificationId, 'unit_id'),
            'a size row accepts a unit that is not in the units table'
        );

        $this->assertRejected(
            fn () => $this->sizeRow($specificationId, 'method_id'),
            'a size row accepts an analysis method that is not in the methods table'
        );
    }

    public function testADimensionCodeIsNotLockedToTheFiveTheDocumentListsAsExamples(): void
    {
        $specificationId = $this->specification('QC/BP/008/26', 1);

        // §5 writes "Code (TL/C/TH/OD/H)" and §3.3.3 repeats the parenthesis, but
        // neither says "only these five", and the real workbook is the source this
        // table has to accept in Phase 2e. So a sixth code is a data-quality
        // question for the importer, not a rejected row here.
        DB::table('specification_sizes')->insert([
            'specification_id' => $specificationId,
            'code' => 'WT',
            'nominal' => '25.0',
            'unit_id' => DB::table('units')->where('symbol', 'g')->value('id'),
            'sort' => 1,
        ]);

        $this->assertSame(
            1,
            DB::table('specification_sizes')->where('code', 'WT')->count(),
            'a check invented from an illustrative list would refuse the client\'s own workbook'
        );
    }

    public function testAParameterRowNamesItsGroupAndItsCriterionAndNothingElseIsInvented(): void
    {
        $specificationId = $this->specification('QC/BP/009/26', 1);

        DB::table('specification_parameters')->insert($this->parameterRow($specificationId));

        // FSD §5: "the inspection cannot add a parameter that does not exist
        // here" — so the parameter row is the vocabulary, and the group that
        // buckets it is the seeded one the printed form shows in order.
        $this->assertSame(
            'Organoleptis',
            DB::table('parameter_groups')
                ->where('id', DB::table('specification_parameters')->value('group_id'))
                ->value('name')
        );

        $this->assertRejected(
            fn () => DB::table('specification_parameters')->insert([
                'specification_id' => $specificationId,
                'group_id' => 999999,
                'parameter' => 'Parameter tanpa grup',
                'sort' => 1,
            ]),
            'a parameter can claim a group outside the six the form prints'
        );
    }

    public function testTheComponentRowKeepsTheShareThePaperFormPrintsSeparateFromItsNote(): void
    {
        $specificationId = $this->specification('QC/BP/010/26', 1);

        DB::table('specification_components')->insert([
            'specification_id' => $specificationId,
            'component' => 'Body',
            'material' => 'HDPE',
            'percent' => 62.5,
            'sort' => 1,
        ]);

        // §5 gives the block four columns: Komponen, Bahan, %, Catatan. The note
        // is the one a row can do without; the share is what the 77-component
        // import is measured in, and a blank one would read as "none" rather than
        // as "not recorded".
        $this->assertNull(
            DB::table('specification_components')->where('specification_id', $specificationId)->value('note')
        );

        $this->assertRejected(
            fn () => DB::table('specification_components')->insert([
                'specification_id' => $specificationId,
                'component' => 'Tutup',
                'material' => 'PP',
                'sort' => 2,
            ]),
            'a component with no percentage reads as none rather than as not recorded'
        );
    }

    public function testTheStatusColumnHoldsTheFiveWordsTheStatusModelDefines(): void
    {
        // FSD §4.1's first column is doc_status, and its five values are the whole
        // vocabulary. The approval-side words (PENDING_CHECKING, CHECKING,
        // CHECKED, WAITING_APPROVAL, APPROVED, REJECTED) belong to the engine's
        // own derived column and are not states a specification row may claim —
        // FSD-401 says they are never written directly at all.
        $documented = ['DRAFT', 'SUBMITTED', 'PENDING', 'CLOSED', 'CANCELLED'];

        // TSD §3.1 makes an enumeration a checked VARCHAR plus a PHP enum, so the
        // migration and SpecificationStatus are two readings of one list. A case
        // added to one of them without the other is a value the database stores
        // and no code can name, or the reverse: a label nothing can save.
        $this->assertSame($documented, SpecificationStatus::values());

        foreach ($documented as $index => $status) {
            $this->specification('QC/BP/011/26', $index + 1, ['doc_status' => $status]);
        }

        $this->assertSame(
            ['DRAFT', 'SUBMITTED', 'PENDING', 'CLOSED', 'CANCELLED'],
            DB::table('specifications')->where('number', 'QC/BP/011/26')->orderBy('version')->pluck('doc_status')->all()
        );

        $this->assertRejected(
            fn () => $this->specification('QC/BP/011/26', 6, ['doc_status' => 'APPROVED']),
            'an approval_status value was accepted as a doc_status, which FSD-401 forbids'
        );
    }

    public function testARevisionPointsAtTheVersionItCameFromWithoutBeingToldToChainForever(): void
    {
        $first = $this->specification('QC/BP/012/26', 1);
        $second = $this->specification('QC/BP/012/26', 2, ['revision_of_id' => $first]);

        // FSD-406's "prior versions stay retrievable" and T-07's "link both in
        // history" are one column. It is nullable because version 1 has no prior,
        // and it is RESTRICT because a history that can be unpicked is not one.
        $this->assertSame($first, DB::table('specifications')->where('id', $second)->value('revision_of_id'));

        $this->assertRejected(
            fn () => DB::table('specifications')->where('id', $first)->delete(),
            'version 1 was removable while version 2 still said it came from it'
        );
    }

    public function testEverySpecificationRowIsBornWithTheTokenAStaleSaveIsJudgedAgainst(): void
    {
        $id = $this->specification('QC/BP/013/26', 1);

        // FSD §2.5 puts a version token on every editable record and §3.3.3 names
        // row_version for this one, so MSG-020 has something to compare.
        $this->assertSame(1, (int) DB::table('specifications')->where('id', $id)->value('row_version'));
    }

    public function testANumberingSequenceIsOneRowPerScopePerPeriod(): void
    {
        DB::table('numbering_sequences')->insert([
            'scope' => 'sbk',
            'period' => '2026',
            'next_value' => 1,
            'pattern' => 'QC/BP/{N:3}/{YY}',
        ]);

        // TSD §3.3.5 gives the table scope, period, next_value and pattern, and
        // §4.5 locks "the period row" — which is only a single row if the pair is
        // unique. Two rows for one period would let two writers each hold a lock
        // and hand out the same NNN.
        $this->assertRejected(
            fn () => DB::table('numbering_sequences')->insert([
                'scope' => 'sbk',
                'period' => '2026',
                'next_value' => 50,
                'pattern' => 'QC/BP/{N:3}/{YY}',
            ]),
            'two sequence rows for one period means two writers can each hold a lock and issue the same number'
        );

        // A different period is a different row: the year part of the pattern
        // restarts, the counter does not reset by magic.
        DB::table('numbering_sequences')->insert([
            'scope' => 'sbk',
            'period' => '2027',
            'next_value' => 1,
            'pattern' => 'QC/BP/{N:3}/{YY}',
        ]);

        $this->assertSame(2, DB::table('numbering_sequences')->where('scope', 'sbk')->count());
    }

    public function testAnEffectiveDateIsNotNeededToOpenADraft(): void
    {
        // §3.3.3 lists effective_date among the columns and FSD §4.2 stamps it
        // when the document closes, not when the author starts typing. A draft
        // that could not be saved without a date would make the author invent one.
        $id = $this->specification('QC/BP/014/26', 1, ['effective_date' => null]);

        $this->assertNull(DB::table('specifications')->where('id', $id)->value('effective_date'));
    }

    public function testTheSpecificationModelReachesItsFourBlocksThroughTheColumnsTheSchemaHas(): void
    {
        // The same lesson MasterDataSchemaTest records: a relation pointing at a
        // column this schema never had stays invisible until the first screen
        // trusts it, and then it throws in whichever page happens to read the
        // document. §3.3.3 names four children, so four are walked here.
        $specificationId = $this->specification('QC/BP/015/26', 1);

        DB::table('specification_sizes')->insert([
            'specification_id' => $specificationId,
            'code' => 'TL',
            'label' => 'Tinggi badan botol',
            'nominal' => '120.0',
            'unit_id' => DB::table('units')->where('symbol', 'mm')->value('id'),
            'sort' => 1,
        ]);

        DB::table('specification_parameters')->insert($this->parameterRow($specificationId));

        DB::table('specification_components')->insert([
            'specification_id' => $specificationId,
            'component' => 'Body',
            'material' => 'PET',
            'percent' => 78.5,
            'sort' => 1,
        ]);

        $specification = Specification::query()->find($specificationId);

        $this->assertSame(['TL'], $specification->sizes->pluck('code')->all());
        $this->assertSame(['Warna'], $specification->parameters->pluck('parameter')->all());
        $this->assertSame(['Body'], $specification->components->pluck('component')->all());

        // The two records §3.3.3 says the document describes, and the person who
        // wrote it, are read the same way — SCR-4.3 prints all three in its header.
        $this->assertSame('RMPET0001', $specification->item->code);
        $this->assertSame('FGBMA2001', $specification->product->code);
        $this->assertSame($this->author->getKey(), $specification->author->getKey());

        // The block the printed form orders by sort (§7.2), read through the
        // relation rather than through a query the page writes for itself.
        $this->assertSame(
            'Organoleptis',
            $specification->parameters->first()->group->name
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function specification(string $number, int $version, array $overrides = []): int
    {
        $row = array_merge([
            'number' => $number,
            'version' => $version,
            'item_id' => $this->item->getKey(),
            'product_id' => $this->product->getKey(),
            'material_name' => 'Botol PET 200 ml',
            'material_type_id' => DB::table('material_types')->orderBy('id')->value('id'),
            'author_id' => $this->author->getKey(),
            'doc_status' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        DB::table('specifications')->insert($row);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterRow(int $specificationId): array
    {
        return [
            'specification_id' => $specificationId,
            'group_id' => DB::table('parameter_groups')->orderBy('sort')->value('id'),
            'parameter' => 'Warna',
            'spec_text' => 'Merah konsisten',
            'criteria' => 'Visual',
            'is_numeric' => 0,
            'sort' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * One size row with one of its two anchors pointed at a key that does not exist.
     */
    private function sizeRow(int $specificationId, string $brokenColumn): void
    {
        $row = [
            'specification_id' => $specificationId,
            'code' => 'TL',
            'nominal' => '18.0',
            'unit_id' => DB::table('units')->where('symbol', 'mm')->value('id'),
            'method_id' => DB::table('analysis_methods')->orderBy('id')->value('id'),
            'sort' => 1,
        ];

        $row[$brokenColumn] = 999999;

        DB::table('specification_sizes')->insert($row);
    }

    /**
     * MySQL refusals that mean "a constraint said no".
     *
     * 1062 duplicate key, 1048/1364 NOT NULL, 1451/1452 RESTRICT on either side
     * of a foreign key, 3819 CHECK. Anything else — above all 1146, table does
     * not exist — is not the fact under test and must not be swallowed by it.
     */
    private const REFUSAL_CODES = [1062, 1048, 1364, 1451, 1452, 3819];

    /**
     * Assert that a write is refused by the database rather than by a form.
     *
     * expectException() aborts at the first pass, so a loop of rejected writes
     * would prove only its own first row. Each rejection is therefore named, and
     * only named: a QueryException from a missing table is this file's own bug,
     * not evidence that a constraint worked.
     */
    private function assertRejected(callable $write, string $why): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $errno = (int) ($exception->errorInfo[1] ?? 0);

            if (! in_array($errno, self::REFUSAL_CODES, true)) {
                throw $exception;
            }

            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("the schema allowed it: {$why}");
    }
}
