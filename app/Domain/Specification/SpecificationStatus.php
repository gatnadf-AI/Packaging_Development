<?php

declare(strict_types=1);

namespace App\Domain\Specification;

/**
 * FSD §4.1 — the five states a specification document may be in.
 *
 * These are doc_status values, the column the author's own screen writes. They
 * are not the approval engine's words: FSD-401 says approval_status is derived
 * from the ordered step rows and "never written directly", so PENDING_CHECKING,
 * CHECKING, CHECKED, WAITING_APPROVAL, APPROVED and REJECTED belong to a
 * different column on a different table and are absent from this list on
 * purpose. A specification row that could claim APPROVED would be a document
 * able to approve itself.
 *
 * TSD §3.1 makes an enumeration a checked VARCHAR plus this class rather than a
 * MySQL ENUM, so the CHECK constraint in the migration and the vocabulary a
 * screen prints are two readings of one list — which is why the unit test for
 * this enum names the five against FSD §2.2.1 rather than reading them back
 * from itself.
 */
enum SpecificationStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Pending = 'PENDING';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';

    /**
     * The Indonesian labels FSD §2.2.1 assigns to the document-status column.
     *
     * §2.2.1's rule is "never a bare code", and NFR-08 puts these words on a
     * coloured chip in every list, so the label travels with the code and no
     * page re-types the mapping. The row's own words are used unchanged —
     * including "Selesai" for CLOSED, which names the document being finished
     * rather than the approval that made it so, because §4.1's row for CLOSED is
     * "No — immutable (BR-04)" and the signature is the engine's to show.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Terajukan',
            self::Pending => 'Menunggu',
            self::Closed => 'Selesai',
            self::Cancelled => 'Batal',
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
