<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FSD-408 — the material a pack is made of, as a reference list.
 *
 * FR-605 records it against the item ("PP-LDPE, PET, Kertas Karton, PP White"),
 * and FSD-408 keeps it in data rather than in code because the real vocabulary
 * is longer than any example in the requirements: the client's own master-spec
 * file holds 36 spellings.
 */
class MaterialType extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * @return HasMany<PackagingItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PackagingItem::class);
    }
}
