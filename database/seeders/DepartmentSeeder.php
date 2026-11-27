<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FSD-206 — departments are an editable reference list.
 *
 * The set below mirrors the role families the flowchart already names; it is a
 * starting point for the client, not a rule. Adding a department is an
 * administrator's action on the reference panel of SCR-2.1 — FSD §5 gives the
 * reference lists no screen of their own — and never a migration.
 *
 * It inserts names it has not seen and leaves every other row alone, including
 * rows it seeded on an earlier run. A reference list that a second `db:seed`
 * renames back, re-activates, or restamps with today's date is not a list the
 * client owns any more, and the whole point of FSD-206 is that the client owns
 * it.
 */
class DepartmentSeeder extends Seeder
{
    private const NAMES = [
        'Marketing',
        'Research & Development',
        'Packaging Development',
        'Quality Control',
        'Quality Assurance',
        'Supply Chain Management',
        'Board of Directors',
    ];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            DB::table('departments')->updateOrInsert(
                ['name' => $name],
                ['is_active' => true, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
