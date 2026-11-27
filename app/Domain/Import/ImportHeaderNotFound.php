<?php

declare(strict_types=1);

namespace App\Domain\Import;

use RuntimeException;

/**
 * The sheet was opened and no header row was found in it.
 *
 * This is a refusal rather than an empty result on purpose. A reader that returned
 * zero rows would let the preview stage report "77 baris, 0 baru, 0 diubah" against
 * a file whose header sits below a title banner nobody recognised — the count would
 * be arithmetically consistent with nothing.
 */
final class ImportHeaderNotFound extends RuntimeException
{
    public function __construct(string $path, string $sheetName, int $scannedRows)
    {
        parent::__construct(
            "Lembar \"{$sheetName}\" di {$path} tidak memiliki baris judul dalam {$scannedRows} baris pertama.".
            ' Baris judul adalah satu baris yang memuat nama kolomnya.'
        );
    }
}
