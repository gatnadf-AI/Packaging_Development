<?php

declare(strict_types=1);

namespace App\Domain\Import;

use RuntimeException;
use Throwable;

/**
 * The file at this path is not a workbook this reader can open.
 *
 * Kept apart from ImportHeaderNotFound because the two answers send a person to
 * different places: an unreadable upload is retried or re-exported, while a sheet
 * with no header row is a file nobody has filled in yet. 2e-2 puts one of these
 * sentences under the upload box and must not have to guess which.
 */
final class ImportFileUnreadable extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("Berkas {$path} tidak ada untuk dibaca.");
    }

    public static function unreadable(string $path, Throwable $previous): self
    {
        return new self(
            "Berkas {$path} bukan lembar kerja yang bisa dibuka ({$previous->getMessage()}).",
            previous: $previous,
        );
    }

    public static function empty(string $path): self
    {
        return new self("Berkas {$path} tidak memiliki satu lembar pun.");
    }
}
