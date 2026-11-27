<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §3.3.3's component block — the 77 rows FSD-407 describes and Phase 2e imports.
 *
 * material is the client's own wording as free text, not a foreign key into
 * material_types: §5 prints the block with a Bahan column per row, and FSD-408's
 * extensible list is a different vocabulary (its own master-spec file holds 36
 * spellings of it). Reconciling the two is a decision for the document owner,
 * not a constraint to assume here.
 *
 * percent is NOT NULL because a blank share prints as "none" rather than as "not
 * recorded", and the share is what the import is measured in.
 */
class SpecificationComponent extends Model
{
    protected $fillable = [
        'specification_id',
        'component',
        'material',
        'percent',
        'note',
        'sort',
    ];

    protected $casts = [
        'percent' => 'decimal:4',
        'sort' => 'integer',
    ];

    /**
     * @return BelongsTo<Specification, $this>
     */
    public function specification(): BelongsTo
    {
        return $this->belongsTo(Specification::class);
    }
}
