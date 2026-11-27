<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TSD-PDS-003 §3.3.2 — one of the six buckets a specification parameter is
 * described under (FSD-408: Organoleptis, Fisik, Kimia, Mikrobiologi, Fungsi,
 * Tampilan).
 *
 * sort is data, not a query: SCR-4.3 and §7.2 both print the groups "in the
 * observed order" of the paper document, so an order a template could rewrite
 * would reorder the form. A missing group is not a shorter list — FSD-408 makes
 * these six the vocabulary the 77-component set is described with — which is
 * why MasterDataSchemaTest asserts all six arrive.
 */
class ParameterGroup extends Model
{
    protected $fillable = ['name', 'sort'];

    protected $casts = ['sort' => 'integer'];
}
