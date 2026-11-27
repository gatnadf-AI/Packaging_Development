<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TSD-PDS-003 §3.3.2 — a measurement symbol, as a reference list.
 *
 * §3.1 makes a unit a foreign key and never free text because the inspection
 * form compares a measured value against it: "120" without a symbol is not a
 * measurement, and a unit typed into a cell cannot be recognised as the one the
 * specification meant.
 *
 * kind is the checked VARCHAR (length, mass, volume, count) that says what a
 * symbol measures, so a volume never answers a length column. There is no
 * is_active column: FSD-408 retires a reference list by adding to it, never by
 * retiring it, and a withdrawn symbol would still be named by the parameters
 * that used it.
 */
class Unit extends Model
{
    protected $fillable = ['symbol', 'name', 'kind'];
}
