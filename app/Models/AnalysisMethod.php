<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TSD-PDS-003 §3.3.2 — how a parameter is measured or judged, as a reference list.
 *
 * default_unit_id is nullable because the method is named before its usual unit
 * is known, and §3.1 only forbids the unit itself being free text.
 *
 * The table exists with no rows. TSD §3.6 asks the seeder for the methods "from
 * the observed SBK document" and nothing supplied ever named one, so the gap is
 * reported in the README rather than filled with invented vocabulary — which is
 * why every method_id on the specification blocks is nullable: a draft can be
 * written now, and the method becomes a foreign key the day the list has names.
 */
class AnalysisMethod extends Model
{
    protected $fillable = ['name', 'default_unit_id'];

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_unit_id');
    }
}
