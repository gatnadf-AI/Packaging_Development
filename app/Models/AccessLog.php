<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the read log (TSD §3.3.5, FSD-908).
 *
 * Kept off audit_entries on purpose: §3.3.5 says a flood of reads must not bury
 * the decisions, and a denied-URL probe is a read. The row therefore says who
 * asked, for what, through which route, and what their grants were at that
 * moment — enough to answer "were they testing the door, or lost?".
 *
 * Append-only for the same reason the trail is: FSD §5.3 asks what was refused,
 * and a log that can be edited answers a question about whoever edited it.
 */
class AccessLog extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'kind',
        'target_type',
        'target_id',
        'route',
        'grant_snapshot',
    ];

    protected $casts = [
        'grant_snapshot' => 'array',
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
