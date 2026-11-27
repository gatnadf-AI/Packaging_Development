<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §3.3.3's dimension block — the rows SCR-4.3 prints as "Ukuran".
 *
 * code carries the client's own abbreviation (TL, C, TH, OD, H in §5's example)
 * with no check constraint over it: the parenthesis in the document is an
 * illustration, and Phase 2e loads this column from the real workbook, so a
 * check invented here would refuse the client's own data. label is the printed
 * name beside the code, which is why the code is not simply displayed.
 *
 * nominal/min/max are decimals because they are measured against, not
 * sentences; unit_id and method_id are foreign keys rather than retyped text
 * (TSD §3.1) so the inspection compares a value against the same thing the
 * specification named.
 */
class SpecificationSize extends Model
{
    protected $fillable = [
        'specification_id',
        'code',
        'label',
        'nominal',
        'min',
        'max',
        'unit_id',
        'method_id',
        'sort',
    ];

    protected $casts = [
        'nominal' => 'decimal:4',
        'min' => 'decimal:4',
        'max' => 'decimal:4',
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
        return $this->belongsTo(AnalysisMethod::class);
    }
}
