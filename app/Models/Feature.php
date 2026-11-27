<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Access\PermissionLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TSD-PDS-003 §3.3.1 — one row of the FSD §6 feature list.
 *
 * The `key` is the contract between the matrix, the routes and the menus.
 * Labels stay in the source language of the sheet because that is what the
 * users read on the approval document; keys stay ascii and stable.
 */
class Feature extends Model
{
    protected $table = 'features';

    protected $fillable = ['key', 'group', 'label', 'sort', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @return HasMany<FeatureGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(FeatureGrant::class, 'feature_key', 'key');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('group')->orderBy('sort');
    }

    public function levelFor(string $roleSlug): PermissionLevel
    {
        $grant = $this->grants()->where('role_slug', $roleSlug)->first();

        // Absence is NONE, never an inferred grant (BR-07).
        return $grant?->level ?? PermissionLevel::None;
    }
}
