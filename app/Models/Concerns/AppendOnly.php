<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The application's half of TSD-1102.
 *
 * The database holds the other half: the application account is not granted
 * UPDATE or DELETE on the trail tables (database/provision/app-grants.sql), so a
 * statement that never touches Eloquent still fails at the server. What this
 * trait adds is the refusal that reaches a developer instead of a deployment —
 * a $row->save() on an audit model says in the exception which rule it broke,
 * and it says the same thing on a machine where the grants cannot be installed,
 * which is every rebuilt test schema and every fresh clone.
 *
 * Both events are answered, not one: changing a row and removing a row are two
 * different accidents, and a guard that only refuses the first is a guard
 * against the bug somebody happened to think of.
 */
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        $table = (new static)->getTable();

        static::updating(
            static fn (Model $row): int => throw new LogicException(
                "{$table} is append-only (TSD-1102): a trail row is written once and never changed"
            )
        );

        static::deleting(
            static fn (Model $row): int => throw new LogicException(
                "{$table} is append-only (TSD-1102): a trail row is written once and never deleted"
            )
        );
    }
}
