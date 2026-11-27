<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FSD-401 — a packaging category, the list reports group by.
 *
 * There is no delete path on the model because there is none on the schema: the
 * items under a category point at it with a RESTRICT key, so "deletion is not,
 * once referenced" is a fact about the database rather than a rule a screen has
 * to remember.
 */
class PackagingCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'row_version' => 'integer',
    ];

    /**
     * @return HasMany<PackagingItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PackagingItem::class, 'category_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
