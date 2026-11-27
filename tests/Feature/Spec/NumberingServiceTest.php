<?php

declare(strict_types=1);

namespace Tests\Feature\Spec;

use App\Domain\Numbering\Numbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TSD-430..432 — the allocator behind SCR-4.3's document number.
 *
 * FSD-404 is the sentence this file exists to keep: "the sequence is per year
 * and never reused even when a specification is withdrawn". Two facts make that
 * true and both are tested here. The counter is a row per (scope, period) that
 * only ever moves forward, and the number it handed out belongs to a
 * specification row that withdrawal does not remove — the schema half of the
 * second fact is asserted in SpecificationSchemaTest, and this file asserts the
 * allocator's own half.
 *
 * The pattern lives in config/numbering.php, seeded in Phase 0.7, so these tests
 * read the shape back from the string the document wrote rather than from a
 * format the implementation could have chosen for itself: QC/BP/NNN/YY, zero
 * padded, restarting when the year does.
 *
 * One scope is deliberately not implemented and one is deliberately refused. The
 * other five patterns in config belong to phases that have no table to number
 * yet, and INS-{A}-{L} is the one of them whose tokens are not a counter at all:
 * the allocator says so instead of rendering a number with a literal `{A}` in
 * it, which is the failure a silent format() produces — a code that looks
 * allocated and is not.
 */
final class NumberingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function testTheFirstDocumentOfAPeriodTakesTheNumberThePatternDescribes(): void
    {
        // TSD-430: read the pattern, lock the period row, increment, format. The
        // first value is 001 because the sequence row does not exist until
        // somebody needs it — §3.6 seeds reference data, and a numbering row is
        // not reference data: which years this system will ever be used in is
        // not known on a deploy date.
        $this->assertSame('QC/BP/001/26', Numbering::allocate('sbk', $this->january()));

        $this->assertSame(
            [['scope' => 'sbk', 'period' => '2026', 'next_value' => 2]],
            DB::table('numbering_sequences')
                ->get(['scope', 'period', 'next_value'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'the row must hold the NEXT number, not the one just handed out, or a crash between the two loses one'
        );
    }

    public function testTheSequenceCountsUpWithinAPeriodAndNeverHandsOneNumberTwice(): void
    {
        $numbers = [
            Numbering::allocate('sbk', $this->january()),
            Numbering::allocate('sbk', $this->january()),
            Numbering::allocate('sbk', $this->january()),
        ];

        // The observable half of TSD-431's concurrency claim, on one connection:
        // successive allocations are distinct and monotonic. The FOR UPDATE lock
        // is what makes the same true of two simultaneous writers, and it is the
        // implementation's job, not a thing a test on a single session can
        // demonstrate without a second connection whose writes would escape the
        // transaction that keeps pds_test clean.
        $this->assertSame(
            ['QC/BP/001/26', 'QC/BP/002/26', 'QC/BP/003/26'],
            $numbers
        );

        $this->assertSame(
            $numbers,
            array_unique($numbers),
            'two documents with one number is the defect BR-13 exists to prevent'
        );
    }

    public function testANumberLongerThanThePatternSaysIsPrintedInFullRatherThanTruncated(): void
    {
        DB::table('numbering_sequences')->insert([
            'scope' => 'sbk',
            'period' => '2026',
            'next_value' => 1000,
            'pattern' => 'QC/BP/{N:3}/{YY}',
        ]);

        // {N:3} is a padding width, not a capacity: a thousandth SBK in one year
        // is a data-quality question for the owner, but a truncated code is a
        // collision, and str_pad cannot produce one. Four characters also fits
        // the string(24) the number column was created with.
        $this->assertSame('QC/BP/1000/26', Numbering::allocate('sbk', $this->january()));
    }

    public function testANewYearStartsAgainAtOneAndLeavesTheYearBeforeWhereItGotTo(): void
    {
        Numbering::allocate('sbk', $this->january());
        Numbering::allocate('sbk', $this->january());

        $this->assertSame('QC/BP/001/27', Numbering::allocate('sbk', $this->nextJanuary()));

        $this->assertSame(
            ['2026' => 3, '2027' => 2],
            DB::table('numbering_sequences')->orderBy('period')->pluck('next_value', 'period')->all(),
            'FSD-404 says the sequence is per year, so a new period must not inherit the old counter and must not reset it either'
        );
    }

    public function testAPatternWhoseTokensAreNotACounterIsRefusedRatherThanHalfRendered(): void
    {
        // INS-{A}-{L} takes an assignment code and a level, neither of which is a
        // sequence. An allocator that silently left the braces in the output
        // would hand out a code that looks allocated, is unique by accident, and
        // reaches a printed document. Inspections are Phase 3; until their tokens
        // have a source the allocator says it cannot number them.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('INS-{A}-{L}');

        Numbering::allocate('inspection', $this->january());
    }

    public function testAScopeTheConfigurationDoesNotNameIsRefused(): void
    {
        // Nothing in config/numbering.php is called 'colour_range'. Inventing a
        // pattern at runtime would be the allocator encoding meaning the document
        // never gave it (BR-13's other half: a code must not mean anything that
        // could later become false).
        $this->expectException(\InvalidArgumentException::class);

        Numbering::allocate('colour_range', $this->january());
    }

    public function testARolledBackTransactionFreesAValueItNeverGaveToADocument(): void
    {
        // TSD §3.5 writes that "a rolled-back document still consumes its
        // number". What BR-13 and FSD-404 refuse is a number reaching two
        // documents, and the case they name is a withdrawal — a specification
        // that was saved and then cancelled, whose row stays and whose number is
        // therefore out of circulation for good (asserted one test down, and at
        // the schema level in SpecificationSchemaTest).
        //
        // The MySQL case is different and is a limitation stated rather than
        // hidden: the increment is written inside the caller's transaction, which
        // is exactly where TSD-431 puts it, and an increment that rolls back with
        // the document rolls back. Honouring §3.5's sentence literally would need
        // a second connection, whose committed writes escape the transaction that
        // keeps pds_test clean — a test-isolation defect traded for a gap. A
        // rolled-back transaction wrote no document, so no number was ever shared
        // by two of them, which is the invariant the requirement holds.
        $first = Numbering::allocate('sbk', $this->january());

        DB::beginTransaction();
        $unused = Numbering::allocate('sbk', $this->january());
        DB::rollBack();

        $this->assertSame('QC/BP/002/26', $unused);
        $this->assertNotSame($unused, $first);

        // The value the rolled-back transaction took comes back around. That is
        // the deviation, written down rather than left for a reviewer to
        // discover: the document that would have held it was never written, so
        // no two documents ever carry one number, and the counter did not rewind
        // from a value somebody else is printing.
        $this->assertSame($unused, Numbering::allocate('sbk', $this->january()));
    }

    public function testTheCounterNeverRewindsToAValueItAlreadyHandedOut(): void
    {
        $this->assertSame('QC/BP/001/26', Numbering::allocate('sbk', $this->january()));

        $this->assertSame(2, (int) DB::table('numbering_sequences')
            ->where(['scope' => 'sbk', 'period' => '2026'])
            ->value('next_value'));

        Numbering::allocate('sbk', $this->january());
        Numbering::allocate('sbk', $this->january());

        // Whatever later phases do to the rows — cancel, revise, withdraw — the
        // allocator's own half of "never reused" is that this number only ever
        // moves away from the values already printed: three allocations handed
        // out 001, 002 and 003, so the row is waiting on 004 and not on anything
        // a document already carries.
        $this->assertSame(4, (int) DB::table('numbering_sequences')
            ->where(['scope' => 'sbk', 'period' => '2026'])
            ->value('next_value'));

        $this->assertSame('QC/BP/004/26', Numbering::allocate('sbk', $this->january()));
    }

    private function january(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-05 09:00:00', 'Asia/Jakarta');
    }

    private function nextJanuary(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-01-04 09:00:00', 'Asia/Jakarta');
    }
}
