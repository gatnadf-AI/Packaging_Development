<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FSD-206 — departments are an editable reference list, not configuration.
 *
 * There is no delete path. §3.1 makes every foreign key RESTRICT and names
 * deactivation as the mechanism, and DepartmentService is the only writer, so
 * "a department with users under it is deactivated, not removed" is a property
 * of the routes that exist rather than a check a screen has to remember.
 */
class Department extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        // The version token of FSD §2.5. Written by RowVersion, never by a
        // form, so it is deliberately absent from $fillable.
        'row_version' => 'integer',
    ];

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
