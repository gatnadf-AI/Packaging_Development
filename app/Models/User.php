<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * TSD-PDS-003 §3.3.1 — the person behind a session.
 *
 * Placed in App\Models rather than App\Domain\Access as §2.5 sketches, because
 * Laravel's auth config, Breeze's controllers and the factory resolver all
 * address the authenticatable class by this namespace. The behaviour that makes
 * this a domain object — the matrix-backed permission set and the segregation
 * rules — lives in App\Domain\Access, which this model only delegates to.
 *
 * is_active = 0 blocks login AND is excluded from queue resolution (FSD-202);
 * must_change_password forces the first-login change (FSD-103). Neither is ever
 * cleared by a controller guessing: they are written by the account service.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'web';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'department_id',
        'phone',
        'is_active',
        'must_change_password',
        'last_login_at',
        'locale',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'department_id' => 'integer',
            'row_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Users eligible to be resolved into a chain step (TSD-402). A deactivated
     * account must not receive a queue item even if its role grant survives.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeResolvable(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return Builder<$this>
     */
    public function scopeWithEmail(Builder $query, string $email): void
    {
        $query->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))]);
    }

    public function isSupplier(): bool
    {
        return $this->hasRole('supplier');
    }
}
