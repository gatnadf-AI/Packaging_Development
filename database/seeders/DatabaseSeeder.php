<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Phase 0 seed set (TSD-PDS-003 §14.1 step 7, package 0.7).
 *
 * Deliberately small: reference lists, the permission matrix as approved data,
 * the working-day calendar, and one account that can log in. No demo business
 * records are seeded into a system that will be handed over — sample projects
 * belong in factories and scenario builders, where tests can state them (TSD
 * §13 "Fixtures"), not in the production schema.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,
            PermissionMatrixSeeder::class,
            WorkingDayCalendarSeeder::class,
            // TSD §3.6: the vocabulary a specification is written with. It is
            // reference data, so it belongs in the bring-up seed rather than
            // with the business fixtures the docblock above refuses.
            UnitsGroupsMethodsSeeder::class,
            BootstrapAdminSeeder::class,
        ]);
    }
}
