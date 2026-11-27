<?php

declare(strict_types=1);

namespace Tests\Concerns;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes real .xlsx files for the import tests, because the thing under test is
 * the read of a spreadsheet: a fixture shaped like a PHP array would let the test
 * pass without PhpSpreadsheet ever being asked a question.
 *
 * The 77-row corpus lives in `tests/Fixtures/Import` as a CSV rather than as a
 * committed workbook so a reviewer can read the data and diff a change to it. The
 * words in it are invented (TSD §15 Phase 2's decision "impor nyata, fixture
 * disamaratakan"): the client's own master sheet never enters the repository, and
 * the file it is walked against arrives in Phase 2e's last package.
 */
trait BuildsImportWorkbooks
{
    /**
     * @var list<string>
     */
    private array $importWorkbooks = [];

    /**
     * @param  list<list<string|int|float|null>>  $rows  one entry per sheet row, written from A1 down
     */
    private function workbook(array $rows, string $sheetName = 'Komposisi'): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);

        foreach ($rows as $index => $row) {
            if ($row === []) {
                // A blank line in the fixture has to stay a blank line in the
                // sheet: writing four empty cells would make the reader's
                // blank-row skip untested by construction.
                continue;
            }

            $sheet->fromArray($row, null, 'A'.($index + 1));
        }

        $path = $this->workbookPath();

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * A workbook with thousands of rows, built once per test and worth its seconds:
     * TSD-901's promise is about a file nobody would type by hand.
     *
     * @param  list<string>  $headers
     */
    private function wideWorkbook(int $dataRows, array $headers = ['Komponen', 'Bahan', '%', 'Catatan']): string
    {
        $rows = [$headers];

        for ($n = 1; $n <= $dataRows; $n++) {
            $rows[] = ["Komponen {$n}", 'PP homopolimer', $n % 100, ''];
        }

        return $this->workbook($rows);
    }

    private function workbookPath(): string
    {
        $path = sys_get_temp_dir().'\pds-import-'.bin2hex(random_bytes(6)).'.xlsx';

        $this->importWorkbooks[] = $path;

        return $path;
    }

    /**
     * A file that opens as a zip of the wrong shape rather than as a workbook.
     */
    private function notAWorkbook(): string
    {
        $path = sys_get_temp_dir().'\pds-import-'.bin2hex(random_bytes(6)).'.xlsx';

        file_put_contents($path, "Ini bukan workbook, hanya teks.\n");

        $this->importWorkbooks[] = $path;

        return $path;
    }

    /**
     * @return list<list<string>>
     */
    private function fixtureRows(string $name = 'komponen-bahan-kemas-77'): array
    {
        $handle = fopen(base_path('tests/Fixtures/Import/'.$name.'.csv'), 'rb');

        $this->assertNotFalse($handle, "the import fixture {$name} is not on disk");

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $rows[] = array_map(static fn ($cell): string => (string) ($cell ?? ''), $row);
        }

        fclose($handle);

        return $rows;
    }

    private function disposeImportWorkbooks(): void
    {
        foreach ($this->importWorkbooks as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->importWorkbooks = [];
    }
}
