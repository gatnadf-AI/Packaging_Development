<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * The three states of an FSD §6 cell.
 *
 * Stored as VARCHAR with a CHECK constraint rather than a MySQL ENUM (§3.1), so
 * adding a state is a migration plus this enum and not a column rebuild.
 */
enum PermissionLevel: string
{
    case Full = 'FULL';
    case Read = 'READ';
    case None = 'NONE';

    /**
     * F = read and act, R = read only, em dash = no access. The paper sheet is
     * the source, so the parser accepts exactly its characters.
     */
    public static function fromSheet(string $cell): self
    {
        return match (trim($cell)) {
            'F' => self::Full,
            'R' => self::Read,
            '—', '-', '' => self::None,
            default => throw new \InvalidArgumentException("Unrecognised matrix cell '{$cell}'"),
        };
    }

    public function canAct(): bool
    {
        return $this === self::Full;
    }

    public function canView(): bool
    {
        return $this !== self::None;
    }

    public function sheet(): string
    {
        return match ($this) {
            self::Full => 'F',
            self::Read => 'R',
            self::None => '—',
        };
    }

    /**
     * The same level in the words a sentence needs. sheet() is FSD §6's own
     * character and the grid is the sheet, so the grid has room for nothing else;
     * an audit expansion, a preview line and a report header are sentences and
     * say what the cell means.
     */
    public function word(): string
    {
        return match ($this) {
            self::Full => 'Penuh',
            self::Read => 'Baca saja',
            self::None => 'Tidak ada',
        };
    }

    /** Cycle order for the editor grid: click Full -> Read -> None -> Full (FSD-204). */
    public function next(): self
    {
        return match ($this) {
            self::Full => self::Read,
            self::Read => self::None,
            self::None => self::Full,
        };
    }
}
