<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * FSD-409 — the product master SCR-4.3 selects "Nama produk" from.
 *
 * A product is not a packaging item: the same brand can be packed in a bottle
 * that is under development today and a different one next year, which is why
 * the specification points at both an item and a product and why a product may
 * reference many specifications over time.
 *
 * §3.3.2 blocks deactivating a product that still has open assignments. Those
 * rows arrive with Phase 3, so until then nothing here pretends to check them:
 * a product with a specification is retired by deactivating it while the
 * specification keeps reading the name it was written under.
 */
class Product extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'brand', 'variant', 'pack_size', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'row_version' => 'integer',
    ];

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
