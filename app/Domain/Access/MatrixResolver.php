<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TSD-PDS-003 §5.1 — role slugs in, PermissionSet out.
 *
 * Q-F6: a person may hold two roles and their effective rights are the union,
 * taken cell by cell at the higher level. Segregation of duties is NOT relaxed
 * by the union: G-01 still blocks self-approval and the chain resolver still
 * refuses to give one person two steps of the same chain (FSD-615). Those rules
 * belong to SegregationResolver, not here.
 *
 * Cached for the request and keyed on the matrix version, so the cell an
 * approver acts under is the cell that is on screen right now (FSD-204).
 */
final class MatrixResolver
{
    /** @var array<string, PermissionSet> */
    private static array $memo = [];

    private const RANK = [
        PermissionLevel::None->value => 0,
        PermissionLevel::Read->value => 1,
        PermissionLevel::Full->value => 2,
    ];

    public function forUser(User $user): PermissionSet
    {
        if (! $user->is_active) {
            // FSD-201: a deactivated account has no rights at all, whatever
            // role rows still exist underneath it.
            return PermissionSet::empty();
        }

        $slugs = $this->roleSlugs($user);

        return $this->forRoleSlugs($slugs);
    }

    /**
     * @param  list<string>  $slugs
     */
    public function forRoleSlugs(array $slugs): PermissionSet
    {
        sort($slugs);
        $key = MatrixVersion::current().'|'.implode(',', $slugs);

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $levels = [];

        if ($slugs !== []) {
            $rows = DB::table('feature_grants')
                ->whereIn('role_slug', $slugs)
                ->get(['feature_key', 'level']);

            foreach ($rows as $row) {
                $candidate = PermissionLevel::from($row->level);
                $existing = $levels[$row->feature_key] ?? PermissionLevel::None;

                if (self::RANK[$candidate->value] > self::RANK[$existing->value]) {
                    $levels[$row->feature_key] = $candidate;
                }
            }
        }

        // Features with no row at all resolve to None through PermissionSet, so
        // the grid only ever carries what was actually granted.
        return self::$memo[$key] = PermissionSet::fromArray($levels, $slugs, MatrixVersion::current());
    }

    /**
     * @return list<string>
     */
    private function roleSlugs(User $user): array
    {
        return $user->roles
            ->pluck('name')
            ->map(static fn (string $slug): string => $slug)
            ->values()
            ->all();
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$memo = [];
    }
}
