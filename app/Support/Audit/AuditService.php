<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AccessLog;
use App\Models\AuditEntry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The single place an audit or access row is written (TSD §6, TSD-1101).
 *
 * Two rules from the signed design are implemented here rather than repeated
 * across the application:
 *
 * One — the call comes from a domain service, never a controller. A controller
 * that forgets to audit is invisible; a service that always audits is not, so
 * the writers in App\Domain go through this object and the reviewers only have
 * to check that they did.
 *
 * Two — only what changed is recorded. §3.3.5 asks for before and after, and
 * TSD-1101 narrows it to "only the changed columns", which is what keeps the
 * history strip readable after a hundred edits and what stops an unchanged
 * snapshot being mistaken for a decision. For that reason record() drops any
 * pair whose two sides are equal, and drops secret-bearing keys entirely: a
 * password hash has no business in a table an auditor reads.
 *
 * The actor's roles are copied at the moment of the write. A person who is
 * later demoted must not have their past decisions reinterpreted, and FSD-907
 * asks for "role at the time" for exactly that reason.
 */
final class AuditService
{
    /**
     * Keys that must never reach the JSON columns, whatever a caller passes.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = [
        'password',
        'current_password',
        'password_confirmation',
        'remember_token',
        'token',
        'payload',
        'secret',
    ];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        ?Authenticatable $actor,
        AuditAction $action,
        Model $target,
        ?array $before = null,
        ?array $after = null,
    ): void {
        [$before, $after] = $this->changedPair($before, $after);

        AuditEntry::create([
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_role_snapshot' => $this->roleSnapshot($actor),
            'action' => $action->value,
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
        ]);
    }

    /**
     * A refusal of a route, or a read of something commercial (§5.3, FSD-908).
     *
     * @param  array<string, mixed>  $grantSnapshot
     */
    public function logAccess(
        ?Authenticatable $actor,
        AccessLogKind $kind,
        ?Model $target,
        string $route,
        array $grantSnapshot,
    ): void {
        AccessLog::create([
            'actor_id' => $actor?->getAuthIdentifier(),
            'kind' => $kind->value,
            'target_type' => $target instanceof Model ? $target::class : null,
            'target_id' => $target?->getKey(),
            'route' => mb_substr($route, 0, 190),
            'grant_snapshot' => $grantSnapshot,
        ]);
    }

    /**
     * The named columns of a model, for the before side of record().
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model, array $keys): array
    {
        return Arr::only($model->attributesToArray(), $keys);
    }

    /**
     * @return list<string>
     */
    public static function roleNames(?Authenticatable $actor): array
    {
        if (! $actor instanceof Model) {
            return [];
        }

        return $actor->relationLoaded('roles')
            ? $actor->roles->pluck('name')->values()->all()
            : $actor->roles()->pluck('name')->all();
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function changedPair(?array $before, ?array $after): array
    {
        if ($before === null || $after === null) {
            return [$this->stripSecrets($before), $this->stripSecrets($after)];
        }

        $kept = [];

        foreach ($after as $key => $value) {
            // Direct index, not Arr::get(): Arr::get() reads a dot as a path
            // separator, and a key that is a JSON pointer, a filename, or a
            // matrix feature such as "project.create" would then be looked up
            // as a nested path and silently read as null — which the diff would
            // report as a change from nothing to the new value.
            $previous = array_key_exists($key, $before) ? $before[$key] : null;

            if ($this->isSame($previous, $value)) {
                continue;
            }

            $kept[$key] = [$previous, $value];
        }

        if ($kept === []) {
            return [null, null];
        }

        $beforeOut = [];
        $afterOut = [];

        foreach ($kept as $key => [$previous, $value]) {
            $beforeOut[$key] = $previous;
            $afterOut[$key] = $value;
        }

        return [$this->stripSecrets($beforeOut), $this->stripSecrets($afterOut)];
    }

    private function isSame(mixed $a, mixed $b): bool
    {
        // A model read back from the database and the value typed into a form
        // differ in type, not in meaning: 1 and "1", true and 1, null and "".
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        // Lists are compared as sets, because that is what their users mean:
        // assigning ["admin","qa_manager"] over ["qa_manager","admin"] changes
        // nothing, and without this branch the cast below would quietly compare
        // the string "Array" with itself and call every list edit unchanged.
        if (is_array($a) || is_array($b)) {
            if (! is_array($a) || ! is_array($b)) {
                return false;
            }

            $left = array_map(static fn (mixed $v): string => (string) $v, $a);
            $right = array_map(static fn (mixed $v): string => (string) $v, $b);

            sort($left);
            sort($right);

            return $left === $right;
        }

        if ($a === null || $b === null) {
            $normalise = static fn (mixed $v): string => $v === null || $v === '' ? '' : (string) $v;

            return $normalise($a) === $normalise($b);
        }

        return (string) $a === (string) $b;
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function stripSecrets(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $clean = array_diff_key($values, array_flip(self::SECRET_KEYS));

        return $clean === [] ? null : $clean;
    }

    /**
     * @return list<string>|null
     */
    private function roleSnapshot(?Authenticatable $actor): ?array
    {
        if ($actor === null) {
            return null;
        }

        return self::roleNames($actor);
    }

    private function ip(): ?string
    {
        return app()->bound('request') ? app(Request::class)->ip() : null;
    }

    private function userAgent(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        return mb_substr((string) app(Request::class)->userAgent(), 0, 512);
    }
}
