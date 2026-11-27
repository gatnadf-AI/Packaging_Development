<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FSD-208's "remembered dismissed per user per announcement".
 *
 * One row says this person stopped being shown that notice, and the unique pair
 * is the whole model: it is a reading preference rather than evidence, so it is
 * the only announcement-side table whose rows may go with the announcement they
 * belong to.
 */
class AnnouncementRead extends Model
{
    /**
     * The row's only moment is dismissed_at, which is a column of its own rather
     * than a bookkeeping stamp: a pair of framework timestamps would say when the
     * database row was written, which is the same fact stated twice.
     */
    public $timestamps = false;

    protected $fillable = ['announcement_id', 'user_id', 'dismissed_at'];

    protected $casts = [
        'dismissed_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
