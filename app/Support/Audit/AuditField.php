<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Domain\Access\PermissionLevel;
use App\Domain\MasterData\BranchClass;

/**
 * The words an audit row uses for the attributes it changed.
 *
 * FSD-207's expansion is the one place the system shows raw attributes, and §2.2
 * keeps machine values off a screen, so the field names and the values have to be
 * translated somewhere. That place is the reader rather than the browser for the
 * same reason AuditVerb is a server class: the trail's history strip (§2.5) and
 * the export (Phase 6) say the same sentences, and two vocabularies on either
 * side of one request is how a strip and a viewer come to describe one act
 * differently.
 *
 * The labels are the words the forms that write these attributes already use.
 * FSD §2.6's catalogue covers failures a person causes, not attributes a record
 * has, so no code names this list — which is reported to the document owner
 * rather than answered with a numbering scheme invented here.
 *
 * A key that is not listed keeps the name the table holds. An unmapped attribute
 * is a vocabulary the phase that introduced it did not add, and printing the
 * stored name is the visible version of that; inventing an Indonesian word for a
 * field nobody specified would be the invisible version.
 */
final class AuditField
{
    private const LABELS = [
        'name' => 'Nama',
        'email' => 'Email',
        'department_id' => 'Departemen',
        'phone' => 'No. telepon',
        'is_active' => 'Aktif',
        'code' => 'Kode',
        'description' => 'Keterangan',
        'category_id' => 'Kategori',
        'material_type_id' => 'Jenis material',
        'branch_class' => 'Klasifikasi cabang',
        'brand' => 'Merek',
        'variant' => 'Varian',
        'pack_size' => 'Ukuran kemasan',
        'roles' => 'Peran',
        'level' => 'Level hak akses',
        'session_id' => 'Sesi',
        'count' => 'Jumlah sesi',
        'reason' => 'Alasan',
    ];

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    /**
     * One side of a change, said the way a person would say it.
     *
     * A flag is Ya or Tidak because the checkbox on the form is answered in those
     * words and 1 is not. A set is joined because "admin, qc_lead" is a list of
     * two identities and an array in JSON is a data structure. A level is spelled
     * by PermissionLevel and a branch by BranchClass, each of which owns its three
     * words the way it owns the sheet's F and R. Absence stays null so the screen
     * can say "there was nothing here" in its own words rather than have this
     * class guess at a value.
     */
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        if (is_array($value)) {
            return implode(', ', array_map(
                static fn (mixed $item): string => (string) $item,
                array_values($value)
            ));
        }

        $text = (string) $value;

        if ($text === '') {
            return null;
        }

        return BranchClass::tryFrom($text)?->label()
            ?? PermissionLevel::tryFrom($text)?->word()
            ?? $text;
    }
}
