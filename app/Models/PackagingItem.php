<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FSD-402, FSD-403 — a packaging item.
 *
 * The code is the client's own (the master-spec file writes RMLPC0794 and
 * FGBMA2001), kept as a unique printed column while every join uses the id,
 * per §3.1. An item is retired by is_active, never by removal: FSD-402 asks
 * that an inactive item stops appearing in new pickers while every historical
 * record still reads it and prints, which only works if the row survives.
 *
 * branch_class seeds the branch picker at sourcing and is not a gate — FSD-403
 * says the explicit choice at FSD-305 still decides, so no query in this
 * application should ever filter a workflow by it.
 */
class PackagingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category_id',
        'material_type_id',
        'branch_class',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'row_version' => 'integer',
    ];

    /**
     * @return BelongsTo<PackagingCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PackagingCategory::class);
    }

    /**
     * @return BelongsTo<MaterialType, $this>
     */
    public function materialType(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
