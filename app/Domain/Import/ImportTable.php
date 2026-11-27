<?php

declare(strict_types=1);

namespace App\Domain\Import;

use Generator;

/**
 * What was found at the top of the client's sheet, before any of it is believed.
 *
 * The table is deliberately not a list of components. FSD-407's order is mapping
 * screen first, then preview, then commit, and until 2e-2 points a column at a
 * field there is no honest answer to "what is this row" — a reader that returned
 * `component`/`material`/`percent` would have guessed the mapping inside the class
 * that is supposed to be asked about it. So this holds the sheet's own words, in the
 * sheet's own order, and nothing more.
 *
 * The counters are read after iteration rather than before it, because a file read in
 * bands only knows how much it read once the bands have been walked: that is also what
 * makes "77 baris dibaca dalam 11 blok" a sentence the screen can print from this
 * object instead of from a loop written around it.
 */
final class ImportTable
{
    private int $readRows = 0;

    private int $readPasses = 0;

    private int $blankRowsSkipped = 0;

    /**
     * @param  list<string>  $headers  the header row's labels, column 1 first, trailing blanks dropped
     */
    public function __construct(
        private readonly SpecificationImportReader $reader,
        public readonly string $path,
        public readonly string $sheetName,
        public readonly int $headerRow,
        public readonly array $headers,
        public readonly string $signature,
        public readonly int $lastRow,
        public readonly int $lastColumn,
        public readonly int $chunkRows,
    ) {}

    /**
     * The data rows below the header, blank rows left out.
     *
     * @return Generator<int, array<int, string|int|float|null>>
     */
    public function rows(): Generator
    {
        return $this->reader->rows($this);
    }

    public function readRows(): int
    {
        return $this->readRows;
    }

    /**
     * How many times the file was opened to walk it — one band per pass.
     */
    public function readPasses(): int
    {
        return $this->readPasses;
    }

    public function blankRowsSkipped(): int
    {
        return $this->blankRowsSkipped;
    }

    /**
     * @internal called by the reader as it walks the bands
     */
    public function countedRow(): void
    {
        $this->readRows++;
    }

    /**
     * @internal
     */
    public function countedPass(): void
    {
        $this->readPasses++;
    }

    /**
     * @internal
     */
    public function countedBlank(): void
    {
        $this->blankRowsSkipped++;
    }
}
