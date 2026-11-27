<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One audited change (TSD §3.3.5, FSD-907, BR-10).
 *
 * There is no updated_at and no path that mutates a row: the record is written
 * once by a domain service and read ever after. Two things hold that line — the
 * model refuses the write (AppendOnly) and the database account is not granted
 * the verb for it (TSD-1102) — because the first catches the accident in the
 * code and only the second catches the accident in the tooling.
 *
 * before and after hold only the columns that actually changed, which is what
 * lets the per-record history strip in §2.5 show a decision instead of a
 * snapshot of the whole row every time it was touched.
 */
class AuditEntry extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_role_snapshot',
        'action',
        'target_type',
        'target_id',
        'before',
        'after',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'actor_role_snapshot' => 'array',
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
