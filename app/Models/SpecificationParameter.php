<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §3.3.3's parameter block — the row that "drives the inspection template
 * exactly", so that nothing may be added at inspection time (FSD §5).
 *
 * Two readings of one block, kept apart on purpose. spec_text/min/max say what
 * is required; criteria says how it is judged. is_numeric picks which half is
 * live, because a numeric parameter is compared against a range in a unit and a
 * text one is answered against a sentence — the inspection template has to know
 * which control to draw, and guessing from "min is null" would make an omitted
 * bound decide the shape of the form.
 *
 * group_id is NOT NULL (§3.3.3 and SCR-4.3 both bucket every parameter under the
 * six seeded groups); unit_id and method_id are foreign keys, never typed text.
 */
class SpecificationParameter extends Model
{
    protected $fillable = [
        'specification_id',
        'group_id',
        'parameter',
        'spec_text',
        'min',
        'max',
        'unit_id',
        'method_id',
        'criteria',
        'is_numeric',
        'sort',
    ];

    protected $casts = [
        'min' => 'decimal:4',
        'max' => 'decimal:4',
        'is_numeric' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @return BelongsTo<Specification, $this>
     */
    public function specification(): BelongsTo
    {
        return $this->belongsTo(Specification::class);
    }

    /**
     * @return BelongsTo<ParameterGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ParameterGroup::class, 'group_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<AnalysisMethod, $this>
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(AnalysisMethod::class, 'method_id');
    }
}
