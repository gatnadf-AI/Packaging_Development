<?php

declare(strict_types=1);

namespace App\Domain\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * TSD-901's chunk read filter, as a row band.
 *
 * PhpSpreadsheet does not ship a chunk filter — its documentation hands one as an
 * example — so this is that band: cells between two rows, up to the last column the
 * sheet's own dimension reports. Everything outside it is never parsed, which is the
 * only reason a sheet of a few thousand rows costs a band of memory rather than the
 * whole sheet.
 */
final class RowChunkFilter implements IReadFilter
{
    public function __construct(
        private readonly int $firstRow,
        private readonly int $lastRow,
        private readonly int $lastColumn,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($row < $this->firstRow || $row > $this->lastRow) {
            return false;
        }

        return Coordinate::columnIndexFromString($columnAddress) <= $this->lastColumn;
    }
}
