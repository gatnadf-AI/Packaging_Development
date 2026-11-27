<?php

declare(strict_types=1);

namespace App\Support\Persistence;

use App\Domain\Access\RecordHasBeenChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * FSD §2.5 with TSD-PDS-003 §3.3.1 — the write half of the version token.
 *
 * Every editable record in this system carries row_version, and the contract on
 * the screen is that a stale save is refused rather than quietly applied. A
 * compare-and-set belongs in the statement that changes the row: read the token
 * the screen saw, ask for it in the WHERE, and treat "zero rows matched" as the
 * conflict it is. Anything else — comparing in PHP, or re-reading after the
 * write — leaves a window exactly as wide as the request that lost the race.
 *
 * Infrastructure rather than domain (§2.4) because the rule is the same for a
 * user, a department, and, from Phase 3, a project: the domain decides which
 * columns may be written, this decides that nobody else got there first.
 */
final class RowVersion
{
    /**
     * Apply $attributes to $model only if the row still carries $expected.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws RecordHasBeenChanged when another save landed in between
     */
    public static function save(Model $model, int $expected, array $attributes): Model
    {
        unset($attributes['row_version']);

        $affected = $model->newQuery()
            ->whereKey($model->getKey())
            ->where('row_version', $expected)
            ->update($attributes + [
                'row_version' => DB::raw('row_version + 1'),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new RecordHasBeenChanged(
                $model::class.'#'.$model->getKey(),
                self::peek($model)
            );
        }

        // The database has the only true copy of the row now: the version moved,
        // and updated_at moved with it. Refreshing is what stops the rest of the
        // request from reasoning from a token that no longer exists.
        return $model->refresh();
    }

    /**
     * Move the token when the change is not a column of this table at all —
     * assigning roles writes a pivot, and the screen that did it still has to
     * lose its stale form. Same race, same compare-and-set.
     */
    public static function bump(Model $model, int $expected): Model
    {
        return self::save($model, $expected, []);
    }

    /**
     * The version a create writes, so a caller never has to guess the column.
     */
    public static function initial(): int
    {
        return 1;
    }

    private static function peek(Model $model): ?int
    {
        $fresh = $model->newQuery()->whereKey($model->getKey())->value('row_version');

        return $fresh === null ? null : (int) $fresh;
    }
}
