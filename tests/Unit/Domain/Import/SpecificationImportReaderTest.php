<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import;

use App\Domain\Import\ImportFileUnreadable;
use App\Domain\Import\ImportHeaderNotFound;
use App\Domain\Import\ImportTable;
use App\Domain\Import\SpecificationImportReader;
use Tests\Concerns\BuildsImportWorkbooks;
use Tests\TestCase;

/**
 * SCR-4.4 / TSD-901 — reading the client's sheet without reading it all at once.
 *
 * Nothing here touches the database and no screen exists yet: this is the first of
 * the pipeline's five lines, and the three things a spreadsheet read can get wrong
 * are pinned before any of them can be blamed on a mapping screen. A file that is
 * not a workbook, a header row that is not where the reader assumed it would be,
 * and rows lost at a chunk boundary are the three ways an import quietly invents
 * or drops a component — so they are refused, reported and counted here rather than
 * discovered in the register afterwards.
 *
 * The signature is asserted as behaviour because TSD-902's "mapping remembered per
 * sheet signature" depends on what it is insensitive to: spacing and letter case of
 * the header words (the same file saved twice by the same person), and nothing else.
 * A renamed or reordered column changes it, which is the point — a remembered
 * mapping applied to a file whose columns moved would write the material into the
 * component column, and the row would pass every rule that follows.
 */
final class SpecificationImportReaderTest extends TestCase
{
    use BuildsImportWorkbooks;

    protected function tearDown(): void
    {
        $this->disposeImportWorkbooks();

        parent::tearDown();
    }

    public function test_the_seventy_seven_row_baseline_arrives_whole_with_the_header_the_form_prints(): void
    {
        // AC-C9's number, and FSD §5's four words for the repeating block:
        // Komponen · Bahan · % · Catatan.
        $table = (new SpecificationImportReader)->read($this->workbook($this->fixtureRows()));

        $this->assertSame('Komposisi', $table->sheetName);
        $this->assertSame(1, $table->headerRow);
        $this->assertSame(['Komponen', 'Bahan', '%', 'Catatan'], $table->headers);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $table->signature);

        $rows = iterator_to_array($table->rows(), false);

        $this->assertCount(77, $rows, 'the baseline lost a row between the sheet and the reader');
        $this->assertSame('Botol PET 250 ml — Badan', $rows[0][0]);
        $this->assertSame('Palet primer kayu — Bahan pembantu', $rows[76][0]);
        // The 512M budget is met by asking for a band of rows at a time, and the
        // count of passes is what proves the band was actually used: one pass for
        // a sheet that fits inside it.
        $this->assertSame(1, $table->readPasses());
        $this->assertSame(77, $table->readRows());
    }

    public function test_a_header_below_a_title_banner_is_found_rather_than_the_banner_read_as_data(): void
    {
        // The reviewed prototype's own file opens with a title line, and a reader
        // that takes row 1 as the header would map "DAFTAR KOMPOSISI" onto Komponen
        // and report a sheet full of unrecognisable columns.
        $table = (new SpecificationImportReader)->read($this->workbook([
            ['DAFTAR KOMPOSISI BAHAN KEMAS (REVISI 2023)'],
            [],
            ['Komponen', 'Bahan', '%', 'Catatan'],
            ['Botol PET 250 ml', 'PET jernih', 62.5, ''],
            ['Tutup 28 mm', 'PP homopolimer', 18.0, ''],
            [],
            ['Karton luar', 'Karton gelombang E-flute', 5.5, ''],
        ]));

        $this->assertSame(3, $table->headerRow);
        $this->assertSame(['Komponen', 'Bahan', '%', 'Catatan'], $table->headers);

        $rows = iterator_to_array($table->rows(), false);

        $this->assertCount(3, $rows, 'a blank line inside the sheet became a component');
        $this->assertSame('Karton luar', $rows[2][0]);
        $this->assertSame(1, $table->blankRowsSkipped());
    }

    public function test_a_sheet_that_never_reaches_a_header_row_is_refused_rather_than_read_as_empty(): void
    {
        $this->expectException(ImportHeaderNotFound::class);

        (new SpecificationImportReader)->read($this->workbook([
            ['LAMPIRAN A'],
            ['Disusun oleh QC'],
        ]));
    }

    public function test_a_file_that_is_not_a_workbook_is_refused_as_that_rather_than_as_an_empty_sheet(): void
    {
        // 2e-2 will show this refusal under the upload box. An unreadable file and
        // an empty one are different problems: one is a transfer the user can retry,
        // the other is a sheet nobody has filled in.
        $this->expectException(ImportFileUnreadable::class);

        (new SpecificationImportReader)->read($this->notAWorkbook());
    }

    public function test_the_signature_ignores_the_spacing_and_case_of_the_header_words_and_nothing_else(): void
    {
        $reader = new SpecificationImportReader;

        $clean = $reader->read($this->workbook([['Komponen', 'Bahan', '%', 'Catatan'], ['a', 'b', 1, '']]));
        $sloppy = $reader->read($this->workbook([
            ['  komponen ', 'BAHAN', ' % ', 'catatan'],
            ['a', 'b', 1, ''],
        ], 'Lampiran 1'));

        $this->assertSame($clean->signature, $sloppy->signature, 'the same columns typed untidily should be one sheet shape');

        $renamed = $reader->read($this->workbook([['Komponen', 'Material', '%', 'Catatan'], ['a', 'b', 1, '']]));

        $this->assertNotSame($clean->signature, $renamed->signature);

        $reordered = $reader->read($this->workbook([['Bahan', 'Komponen', '%', 'Catatan'], ['b', 'a', 1, '']]));

        $this->assertNotSame($clean->signature, $reordered->signature, 'a mapping applied to moved columns writes one field into another');
    }

    public function test_rows_survive_the_chunk_boundaries_exactly_once_each(): void
    {
        // 77 rows through a window of 7 is eleven passes, and the row that sits on a
        // boundary is the one a filter with an off-by-one loses or repeats. Reading
        // the same sheet both ways and comparing the whole list is the only way to
        // ask that question without trusting the filter's arithmetic.
        $path = $this->workbook($this->fixtureRows());

        $wide = $this->collect((new SpecificationImportReader)->read($path));
        $narrow = $this->collect((new SpecificationImportReader(chunkRows: 7))->read($path));

        $this->assertSame(11, $narrow['table']->readPasses());
        $this->assertCount(77, $narrow['rows']);
        $this->assertSame($wide['rows'], $narrow['rows'], 'the rows differ by how many were read per pass');
        $this->assertSame($wide['table']->signature, $narrow['table']->signature);
    }

    public function test_a_few_thousand_rows_read_without_holding_the_whole_sheet(): void
    {
        // TSD-901's tolerance for "a few thousand rows at the 512M limit", read as a
        // ceiling a run can trip rather than as a claim. The bound is generous on
        // purpose: what the test discriminates is a reader that loads all 5,000 rows
        // into one Spreadsheet, not the machine it runs on.
        $path = $this->wideWorkbook(5000);

        memory_reset_peak_usage();

        $table = (new SpecificationImportReader)->read($path);
        $rows = iterator_to_array($table->rows(), false);

        $this->assertCount(5000, $rows);
        $this->assertSame(20, $table->readPasses());
        $this->assertSame('Komponen 5000', $rows[4999][0]);
        $this->assertLessThan(256 * 1024 * 1024, memory_get_peak_usage(true), 'the sheet was held in memory rather than read in bands');
    }

    public function test_values_arrive_as_the_sheet_stores_them_and_a_blank_stays_blank(): void
    {
        $table = (new SpecificationImportReader)->read($this->workbook($this->fixtureRows()));

        $rows = iterator_to_array($table->rows(), false);

        // The percent column is a number in the client's file, and 2e-3's validation
        // has to know whether it is arguing with a float or with the text "46".
        $this->assertIsNumeric($rows[0][2]);
        $this->assertEqualsWithDelta(46.0, (float) $rows[0][2], 0.0001);

        // An empty Catatan is a cell that holds nothing, not the word "null" and not a
        // space: the printed form leaves it blank and the note column must be able to
        // tell "no note" from a note someone typed a space into.
        $this->assertNull($rows[0][3], 'a blank cell arrived as something other than nothing');
        $this->assertSame('Termasuk cincin pengaman', $rows[1][3]);
    }

    /**
     * @return array{table: ImportTable, rows: list<array<int, mixed>>}
     */
    private function collect(ImportTable $table): array
    {
        $rows = [];

        foreach ($table->rows() as $row) {
            $rows[] = $row;
        }

        return ['table' => $table, 'rows' => $rows];
    }
}
