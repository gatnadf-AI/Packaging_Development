<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FSD-208 — one notice, its audience, and the window it is up for.
 *
 * There is no setter here worth defending: AnnouncementService is the only
 * writer, the way every other table in this application has one, because the
 * audience has to be checked against the role and department lists before it is
 * stored and a window that ends before it starts has to be refused rather than
 * saved and read wrong forever.
 */
class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'audience', 'publish_at', 'expire_at', 'created_by'];

    protected $casts = [
        'audience' => 'array',
        'publish_at' => 'immutable_datetime',
        'expire_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<AnnouncementRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    /**
     * Is this the moment the banner shows it?
     *
     * Half-open: from publish_at inclusive to expire_at exclusive. FSD-208 gives
     * two instants and no duration of grace, and the instant an administrator
     * types as the end is the instant they mean the notice to be gone.
     */
    public function isLiveAt(CarbonImmutable $at): bool
    {
        $published = $this->publish_at?->lessThanOrEqualTo($at) ?? false;

        return $published && ($this->expire_at === null || $this->expire_at->greaterThan($at));
    }

    /**
     * Has it been up, which is the question the delete rule asks.
     *
     * Somebody may already have read it, and their dismissal is a fact about a
     * banner that existed. Stopping it answers the same need without erasing
     * that, so a row that has begun its window is taken down rather than
     * removed; a row that has not is a mistake the writer can still untype.
     */
    public function hasGoneUpBy(CarbonImmutable $at): bool
    {
        return $this->publish_at?->lessThanOrEqualTo($at) ?? false;
    }
}
