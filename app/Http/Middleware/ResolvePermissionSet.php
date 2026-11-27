<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\MatrixResolver;
use App\Domain\Access\PermissionSet;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One permission set per request, resolved from the matrix (TSD §2.3, FSD-614).
 *
 * FSD §6 is the single source of menu, button and API authorisation, and TSD
 * §5.3 puts the enforcement in a route middleware and a policy. Both of them,
 * plus the navigation tree and every `can` flag a page is handed, have to be
 * looking at the same object — otherwise a menu can be drawn for a route that
 * then answers MSG-010, which is precisely the defect FSD-101 forbids.
 *
 * Resolution is cheap because MatrixResolver memoises per request and keys its
 * cache on the matrix version (TSD-510): an administrator editing a cell is
 * seen by everyone on their next request, and nobody has to be logged out for
 * that to happen.
 */
class ResolvePermissionSet
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $set = app(MatrixResolver::class)->forUser($user);

            $this->expose($request, $set);
        } else {
            // A guest has no rights at all, and the empty set says so in the
            // same shape as any other answer. Without this, code that asks for
            // the set has to guess whether null or None is the honest answer.
            $this->expose($request, PermissionSet::empty());
        }

        return $next($request);
    }

    private function expose(Request $request, PermissionSet $set): void
    {
        $request->attributes->set(PermissionSet::class, $set);

        app()->instance(PermissionSet::class, $set);
    }
}
