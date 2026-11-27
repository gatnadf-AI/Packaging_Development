<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Access\MatrixResolver;
use App\Domain\Access\MatrixVersion;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PermissionMatrixSeeder;
use Database\Seeders\UnitsGroupsMethodsSeeder;
use Database\Seeders\WorkingDayCalendarSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the reference data the application is useless without.
 *
 * Paired with RefreshDatabase, so each test pays one seed pass (a few hundred
 * rows) and gets the FSD §6 grid exactly as production will hold it. Nothing in
 * a test may hand-write a feature_grants row: if the grid is not seeded, the
 * assertion proves nothing.
 *
 * Fixtures for business records are separate (TSD §13) — a test that needs a
 * project builds one through the scenario builders, not a seeder.
 */
trait SeedsReferenceData
{
    protected function seedReferenceData(): void
    {
        $this->seed(DepartmentSeeder::class);
        $this->seed(PermissionMatrixSeeder::class);
        $this->seed(WorkingDayCalendarSeeder::class);
        // The parameter groups, units and material types a specification is
        // described with (TSD §3.6). Without them an SBK row cannot name a
        // measurement, so they are reference data in the same sense as the grid.
        $this->seed(UnitsGroupsMethodsSeeder::class);

        // The resolver memoises per matrix version; a fresh schema with a
        // recycled version integer would otherwise inherit the previous test's
        // permission set.
        MatrixResolver::reset();
        MatrixVersion::reset();
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function featureKeys(): array
    {
        return DB::table('features')->orderBy('sort')->pluck('key')->all();
    }

    /**
     * @return array<string, string> role slug => label
     */
    protected function roleSlugs(): array
    {
        return DB::table('roles')->orderBy('name')->pluck('label', 'name')->all();
    }

    protected function grant(string $featureKey, string $roleSlug): string
    {
        $level = DB::table('feature_grants')
            ->where('feature_key', $featureKey)
            ->where('role_slug', $roleSlug)
            ->value('level');

        // Absence is NONE (BR-07); the helper states it so a missing row reads
        // as a refusal rather than as a null.
        return $level ?? 'NONE';
    }
}
