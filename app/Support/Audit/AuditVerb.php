<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * The Indonesian phrase for an audit verb, in one place.
 *
 * FSD-207 promises an audit viewer whose rows say what happened, and §2.5
 * promises the same words in the history strip at the bottom of a detail view.
 * Two spellings of one event across those two screens is exactly the kind of
 * inconsistency the message catalogue exists to prevent, so the map lives here
 * and both screens read it.
 *
 * The FSD's §2.6 catalogue assigns no MSG code to an audit verb — the codes cover
 * failures a user causes, not acts a system records — so these phrases are
 * derived from the AuditAction case names, which are themselves the values stored
 * in the table. That is reported to the FSD owner rather than resolved silently:
 * if the audit viewer is meant to show particular wording, §2.6 has to grow the
 * rows, and then this map becomes a lookup into the language file instead of a
 * constant.
 *
 * The raw code is always sent to the client alongside the phrase. An auditor
 * filtering on "user.roles_assigned" and a developer reading a log line both need
 * the stored value, and a screen that shows only a translation cannot be compared
 * against the table it came from.
 */
final class AuditVerb
{
    private const PHRASES = [
        AuditAction::UserCreated->value => 'Akun dibuat',
        AuditAction::UserUpdated->value => 'Data akun diubah',
        AuditAction::UserRolesAssigned->value => 'Peran akun diubah',
        AuditAction::UserDeactivated->value => 'Akun dinonaktifkan',
        AuditAction::UserReactivated->value => 'Akun diaktifkan kembali',
        AuditAction::UserResetLinkSent->value => 'Tautan reset dikirim',

        AuditAction::DepartmentCreated->value => 'Departemen dibuat',
        AuditAction::DepartmentUpdated->value => 'Departemen diubah',
        AuditAction::DepartmentDeactivated->value => 'Departemen dinonaktifkan',

        AuditAction::CategoryCreated->value => 'Kategori kemasan dibuat',
        AuditAction::CategoryUpdated->value => 'Kategori kemasan diubah',
        AuditAction::CategoryDeactivated->value => 'Kategori kemasan dinonaktifkan',
        AuditAction::CategoryDeleted->value => 'Kategori kemasan dihapus',

        AuditAction::ItemCreated->value => 'Item kemasan dibuat',
        AuditAction::ItemUpdated->value => 'Item kemasan diubah',
        AuditAction::ItemDeactivated->value => 'Item kemasan dinonaktifkan',

        AuditAction::ProductCreated->value => 'Produk dibuat',
        AuditAction::ProductUpdated->value => 'Produk diubah',
        AuditAction::ProductDeactivated->value => 'Produk dinonaktifkan',

        AuditAction::SpecCreated->value => 'Spesifikasi bahan pengemas dibuat',
        AuditAction::SpecUpdated->value => 'Spesifikasi bahan pengemas diubah',
        AuditAction::SpecRevised->value => 'Spesifikasi bahan pengemas direvisi',

        AuditAction::MatrixCellChanged->value => 'Matriks akses diubah',

        AuditAction::SessionRevoked->value => 'Sesi ditutup',
        AuditAction::SessionTerminated->value => 'Seluruh sesi akun ditutup',
    ];

    public static function phrase(string $action): string
    {
        return self::PHRASES[$action] ?? $action;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::PHRASES;
    }
}
