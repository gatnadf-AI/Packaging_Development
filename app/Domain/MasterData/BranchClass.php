<?php

declare(strict_types=1);

namespace App\Domain\MasterData;

/**
 * FSD-403 — how a packaging item is classified against the two branches.
 *
 * The three values are the branches the flowchart has: A is bahan kemas utama,
 * B is sekunder/tersier, BOTH is the pack that appears in either. TSD §3.1 makes
 * an enumeration a checked VARCHAR plus this class rather than a MySQL ENUM, so
 * the check constraint in the migration and the vocabulary a screen prints are
 * two readings of one list.
 *
 * The classification is metadata that seeds a picker. It is never a gate: FSD-403
 * says the explicit branch choice at FSD-305 still decides which lane an
 * assignment runs in, so no query in this application may filter a workflow by
 * these values.
 */
enum BranchClass: string
{
    case Primary = 'A';
    case Secondary = 'B';
    case Both = 'BOTH';

    /**
     * The Indonesian words SCR-4.2 prints. §2.2 keeps a machine value off every
     * screen, and a bare "A" in a column is one, so the label travels with the
     * code and the browser is never asked to know what A means.
     *
     * The wording is the flowchart's own band names (Branch A — bahan kemas
     * utama, Branch B — sekunder/tersier); FSD §2.6 assigns no MSG code to a
     * branch, which is reported rather than answered with an invented number.
     */
    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Utama',
            self::Secondary => 'Sekunder / tersier',
            self::Both => 'Keduanya',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function labelFor(?string $value): ?string
    {
        return self::tryFrom((string) $value)?->label();
    }
}
