<?php

declare(strict_types=1);

namespace App\Domain\Import;

use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;

/**
 * SCR-4.4 / TSD-901 — the first leg of the import: open the client's sheet, find its
 * header, and hand the rows over a band at a time.
 *
 * Three decisions are worth their tests, and all three are about not inventing data.
 *
 * The header row is *found*, in the first rows of the sheet, as the first row carrying
 * at least two non-empty labels. FSD-407 describes "one sheet, header row, one
 * component per row" and the files the client actually keeps open with a title banner
 * in A1 — a single label — so a reader that took row 1 for the header would map
 * "DAFTAR KOMPOSISI" onto the component column, and every row under it would then look
 * like a validation failure caused by the user's own data.
 *
 * Memory is bounded by a read filter (RowChunkFilter) rather than by trusting a
 * small file: the sheet is opened once per band, so what is held is a band of rows and
 * not the workbook. That is why `readPasses()` exists — the number is the evidence that
 * the bound is real, and it is the sentence the screen can print under the progress bar.
 *
 * Values arrive as the sheet stores them (numbers as numbers) because `readDataOnly`
 * is what TSD-901 asks for, and it is also the reason formats are not read: a cell
 * displayed as "4,5 %" arrives as 4.5 or as 0.045 depending on how the file was made,
 * and the question of which is 2e-3's validation, not this reader's. A reader that
 * prettified cells here would hide the case the preview stage has to refuse.
 */
final class SpecificationImportReader
{
    public const CHUNK_ROWS = 250;

    /**
     * How far down the sheet the header is looked for before the file is refused.
     */
    private const HEADER_SCAN_ROWS = 25;

    private const MIN_HEADER_LABELS = 2;

    public function __construct(private readonly int $chunkRows = self::CHUNK_ROWS)
    {
        if ($chunkRows < 1) {
            throw new ImportFileUnreadable('Sebuah impor harus membaca minimal satu baris per blok.');
        }
    }

    public function read(string $path): ImportTable
    {
        [$sheetName, $lastRow, $lastColumn] = $this->inspect($path);

        $headerRow = 0;
        $headers = [];

        $scan = $this->band($path, 1, min(self::HEADER_SCAN_ROWS, $lastRow), $lastColumn);

        foreach ($scan as $rowNumber => $cells) {
            $labels = $this->labels($cells);

            if ($this->labelled($labels) >= self::MIN_HEADER_LABELS) {
                $headerRow = $rowNumber;
                $headers = $labels;

                break;
            }
        }

        if ($headerRow === 0) {
            throw new ImportHeaderNotFound($path, $sheetName, count($scan));
        }

        return new ImportTable(
            reader: $this,
            path: $path,
            sheetName: $sheetName,
            headerRow: $headerRow,
            headers: $headers,
            signature: self::signature($headers),
            lastRow: $lastRow,
            lastColumn: $lastColumn,
            chunkRows: $this->chunkRows,
        );
    }

    /**
     * Walk the rows below the header, opening the file once per band.
     *
     * @return Generator<int, array<int, string|int|float|null>>
     */
    public function rows(ImportTable $table): Generator
    {
        for ($first = $table->headerRow + 1; $first <= $table->lastRow; $first += $table->chunkRows) {
            $last = min($first + $table->chunkRows - 1, $table->lastRow);

            $table->countedPass();

            foreach ($this->band($table->path, $first, $last, $table->lastColumn) as $cells) {
                if ($this->isBlank($cells)) {
                    $table->countedBlank();

                    continue;
                }

                $table->countedRow();

                yield $cells;
            }
        }
    }

    /**
     * The sheet's own words for its columns, trimmed, with the blanks a file leaves at
     * the right edge removed. A gap in the middle stays: it is a column the mapping
     * screen will be shown and left unassigned.
     *
     * @param  array<int, string|int|float|null>  $cells
     * @return list<string>
     */
    private function labels(array $cells): array
    {
        $labels = array_map(
            static fn ($cell): string => trim((string) ($cell ?? '')),
            array_values($cells)
        );

        while ($labels !== [] && end($labels) === '') {
            array_pop($labels);
        }

        return $labels;
    }

    /**
     * How many of these labels are words rather than blanks.
     *
     * @param  array<int, string>  $labels
     */
    private function labelled(array $labels): int
    {
        return count(array_filter($labels, static fn (string $label): bool => $label !== ''));
    }

    /**
     * TSD-902's key. Spacing and letter case are untidiness in the same file; a column
     * that was renamed or moved is a different sheet shape, and a remembered mapping
     * applied to it would write one field into another.
     *
     * @param  list<string>  $headers
     */
    public static function signature(array $headers): string
    {
        $normalised = array_map(
            static fn (string $label): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($label))),
            $headers
        );

        return hash('sha256', implode("\x1f", $normalised));
    }

    /**
     * @return array{0: string, 1: int, 2: int} sheet name, last row, last column
     */
    private function inspect(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ImportFileUnreadable::missing($path);
        }

        try {
            // The first sheet only, which is what FSD-407's "one sheet" means; a
            // workbook with more tabs is 2e-2's screen's question to ask, not one this
            // reader answers by choosing for the user.
            $info = (new Xlsx)->listWorksheetInfo($path);
        } catch (Throwable $failure) {
            throw ImportFileUnreadable::unreadable($path, $failure);
        }

        if ($info === []) {
            throw ImportFileUnreadable::empty($path);
        }

        return [$info[0]['worksheetName'], max(0, (int) $info[0]['totalRows']), max(0, (int) $info[0]['totalColumns'])];
    }

    /**
     * One band of rows, read with only that band parsed.
     *
     * @return array<int, array<int, string|int|float|null>> keyed by sheet row number
     */
    private function band(string $path, int $firstRow, int $lastRow, int $lastColumn): array
    {
        if ($lastColumn < 1 || $lastRow < $firstRow) {
            return [];
        }

        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new RowChunkFilter($firstRow, $lastRow, $lastColumn));

        try {
            $spreadsheet = $reader->load($path);
        } catch (Throwable $failure) {
            throw ImportFileUnreadable::unreadable($path, $failure);
        }

        try {
            $range = 'A'.$firstRow.':'.Coordinate::stringFromColumnIndex($lastColumn).$lastRow;

            // rangeToArray keys a band from zero, and a header row has to be reported
            // as the row the person sees in Excel — row 3 under a title banner is row 3,
            // not the second thing this reader happened to find.
            $rows = $spreadsheet->getSheet(0)->rangeToArray($range, null, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $band = [];

        foreach ($rows as $offset => $cells) {
            $band[$firstRow + $offset] = $cells;
        }

        return $band;
    }

    /**
     * A row with no value anywhere in it. Blank lines are normal in the client's files
     * — a spacing row between groups, or the residue of deleted rows inside the used
     * range — and one of them becoming a component with an empty name is how an import
     * arrives at 78 rows for a 77-row sheet.
     *
     * @param  array<int, string|int|float|null>  $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
