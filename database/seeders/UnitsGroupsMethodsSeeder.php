<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TSD-PDS-003 §3.6 — the reference lists a specification is written with.
 *
 * Everything here comes from a document, not from a guess, and the three
 * sources are named where each row is:
 *
 * — The six parameter groups are quoted twice in the FSD (SCR-4.3 "Groups in
 *   the observed order: Organoleptis, Fisik, Kimia, Mikrobiologi, Fungsi,
 *   Tampilan" and §7.2's print region), and the order is the printed form's
 *   order, so it is stored as data rather than as a constant a template could
 *   re-sort.
 * — The units are the ones the client's own master-spec columns are labelled
 *   with: Panjang/Lebar/Tinggi/Tebal/Diameter in mm, Berat in gram, Volume in
 *   ml. `pcs` is the count a component list needs when a pack is counted rather
 *   than weighed. §3.1 makes a unit a foreign key and never free text because
 *   the inspection form compares a measured value against it, which is the one
 *   reason this list cannot be left empty.
 * — The material types are FR-605's examples (PP-LDPE, PET, Kertas Karton, PP
 *   White) plus the four the same file uses for the packs themselves. The real
 *   vocabulary is 36 spellings wide and full of the client's own variants
 *   ("PP - LDEP", "Aluminium - LPDE", "Slinder"); correcting a supplier's
 *   spelling is the reference list's job at SCR-4.1/4.2, not a decision this
 *   seeder makes alone.
 *
 * analysis_methods is deliberately left empty. TSD §3.6 says the methods come
 * "from the observed SBK document" — the paper form ref. QC/BP/013/04 — and
 * that document is not in the repository or in any file supplied so far, so
 * there is no evidence to seed from. Inventing "Uji visual, Uji tarik" would
 * put words into a form QC signs, and FSD-408 exists so the list can be
 * extended by data when the document arrives. Until then the importer's mapping
 * screen (TSD-902) is where the client's own method names enter, and the SBK
 * form leaves the method blank rather than guessing it.
 *
 * Every row is inserted only when absent, so the seeder can run again on a
 * database that already has the client's edits without duplicating or
 * overwriting them.
 */
final class UnitsGroupsMethodsSeeder extends Seeder
{
    /**
     * @var list<array{0: string, 1: int}>
     */
    private const GROUPS = [
        ['Organoleptis', 1],
        ['Fisik', 2],
        ['Kimia', 3],
        ['Mikrobiologi', 4],
        ['Fungsi', 5],
        ['Tampilan', 6],
    ];

    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const UNITS = [
        ['mm', 'milimeter', 'length'],
        ['g', 'gram', 'mass'],
        ['ml', 'mililiter', 'volume'],
        ['pcs', 'pieces', 'count'],
    ];

    /**
     * @var list<string>
     */
    private const MATERIAL_TYPES = [
        'Kertas Karton',
        'PET',
        'PP - LDPE',
        'PP White',
        'HDPE',
        'PP',
        'Aluminium',
        'Botol Kaca',
    ];

    public function run(): void
    {
        foreach (self::GROUPS as [$name, $sort]) {
            if (! DB::table('parameter_groups')->where('name', $name)->exists()) {
                DB::table('parameter_groups')->insert([
                    'name' => $name,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (self::UNITS as [$symbol, $name, $kind]) {
            if (! DB::table('units')->where('symbol', $symbol)->exists()) {
                DB::table('units')->insert([
                    'symbol' => $symbol,
                    'name' => $name,
                    'kind' => $kind,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (self::MATERIAL_TYPES as $name) {
            if (! DB::table('material_types')->where('name', $name)->exists()) {
                DB::table('material_types')->insert([
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
