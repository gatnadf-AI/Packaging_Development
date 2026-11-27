<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Access\PermissionLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TSD-PDS-003 §3.3.1 — one cell of the FSD §6 matrix.
 *
 * Natural key is (feature_key, role_slug); both are strings rather than ids so
 * a matrix row survives a re-import of roles and reads the same way as the
 * paper sheet does.
 */
class FeatureGrant extends Model
{
    protected $table = 'feature_grants';

    protected $fillable = ['feature_key', 'role_slug', 'level', 'changed_at', 'changed_by'];

    protected $casts = [
        'level' => PermissionLevel::class,
        'changed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Feature, $this>
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'feature_key', 'key');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(\Spatie\Permission\Models\Role::class, 'role_slug', 'name');
    }

    public function isFull(): bool
    {
        return $this->level === PermissionLevel::Full;
    }

    public function isRead(): bool
    {
        return $this->level === PermissionLevel::Read;
    }
}
