<?php

declare(strict_types=1);

namespace App\Domain\Access;

use Illuminate\Support\Facades\DB;

/**
 * ADR-08 — one integer that invalidates every cached permission set.
 *
 * Read once per request. FSD-204 requires a matrix change to take effect for
 * the affected role inside one request cycle, which rules out a TTL; reloading
 * the whole grid per request would be the other alternative, and it is the one
 * the rejected prototype took. A single indexed row read is the cheap seam.
 */
final class MatrixVersion
{
    private static ?int $resolved = null;

    public static function current(): int
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $version = DB::table('matrix_versions')->orderByDesc('id')->value('version');

        return self::$resolved = (int) ($version ?? 0);
    }

    /** Called by the matrix editor after a successful save. */
    public static function bump(?int $actorId = null): int
    {
        $row = DB::table('matrix_versions')->orderByDesc('id')->first();

        if ($row === null) {
            DB::table('matrix_versions')->insert([
                'version' => 1,
                'changed_at' => now(),
                'changed_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return self::$resolved = 1;
        }

        $next = $row->version + 1;

        DB::table('matrix_versions')->where('id', $row->id)->update([
            'version' => $next,
            'changed_at' => now(),
            'changed_by' => $actorId,
            'updated_at' => now(),
        ]);

        return self::$resolved = $next;
    }

    /** Test seam: forget the per-request memo. */
    public static function reset(): void
    {
        self::$resolved = null;
    }
}
