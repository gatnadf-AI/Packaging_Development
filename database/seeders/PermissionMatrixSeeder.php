<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Package 0.7 / Phase 1 (TSD-PDS-003 §5) — the FSD §6 permission matrix as data.
 *
 * The grid itself is machine-transcribed from the approved FSD into
 * database/seeders/data/permission-matrix.php; this seeder only loads it. That
 * split is what prevents risk R-06 (the seeded matrix drifting from the
 * document): nobody retypes a cell.
 *
 * Idempotent and safe to re-run: it upserts by natural key and never deletes
 * grants a hand-edit may have added since the last run.
 */
class PermissionMatrixSeeder extends Seeder
{
    public function run(): void
    {
        $matrix = require __DIR__.'/data/permission-matrix.php';

        // Spatie caches roles/permissions per guard; forget it before writing so
        // a re-run cannot resurrect a deleted role from the resolver cache.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () use ($matrix) {
            foreach ($matrix['roles'] as $role) {
                // Written through the query builder: Spatie's Role model is
                // mass-assignment-guarded to (name, guard_name) and would
                // silently drop the label and system columns.
                $exists = DB::table('roles')
                    ->where('name', $role['slug'])
                    ->where('guard_name', 'web')
                    ->exists();

                if ($exists) {
                    DB::table('roles')
                        ->where('name', $role['slug'])
                        ->where('guard_name', 'web')
                        ->update([
                            'label' => $role['name'],
                            'is_system' => $role['system'],
                            'is_business' => ! $role['system'],
                            'updated_at' => now(),
                        ]);

                    continue;
                }

                DB::table('roles')->insert([
                    'name' => $role['slug'],
                    'guard_name' => 'web',
                    'label' => $role['name'],
                    'is_system' => $role['system'],
                    'is_business' => ! $role['system'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($matrix['features'] as $feature) {
                DB::table('features')->updateOrInsert(
                    ['key' => $feature['key']],
                    [
                        'group' => $feature['group'],
                        'label' => $feature['label'],
                        'sort' => $feature['sort'],
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            foreach ($matrix['grants'] as $roleSlug => $grants) {
                foreach ($grants as $featureKey => $level) {
                    // Absence means NONE (§3.1 BR-07), so a NONE row is written
                    // anyway: the matrix editor must show the decision, not an
                    // inference, and the matrix x route test asserts against rows.
                    DB::table('feature_grants')->updateOrInsert(
                        ['feature_key' => $featureKey, 'role_slug' => $roleSlug],
                        [
                            'level' => $level,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            }

            $this->bumpMatrixVersion();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Permission matrix: %d roles x %d features (%d grant rows).',
            count($matrix['roles']),
            count($matrix['features']),
            count($matrix['roles']) * count($matrix['features'])
        ));
    }

    /**
     * ADR-08: one integer, read per request, invalidating every cached
     * permission set the moment the matrix changes (FSD-204).
     */
    private function bumpMatrixVersion(): void
    {
        $row = DB::table('matrix_versions')->orderByDesc('id')->first();

        if ($row === null) {
            DB::table('matrix_versions')->insert([
                'version' => 1,
                'changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('matrix_versions')->where('id', $row->id)->update([
            'version' => $row->version + 1,
            'changed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
