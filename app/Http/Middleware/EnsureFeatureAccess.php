<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\PermissionLevel;
use App\Domain\Access\PermissionSet;
use App\Support\Audit\AccessLogKind;
use App\Support\Audit\AuditService;
use App\Support\Msg;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * feature:<key>,<level> — the route half of the matrix (TSD §5.3).
 *
 * This is the enforcement point FSD-101 describes: a menu entry and a URL are
 * answered from the same cell, so arriving at a route that the grid refuses is
 * not a matter of whether the screen was hidden. It is also the only place a
 * page is allowed to be refused: TSD-503 denies by default, and a route with no
 * grant row is denied rather than tolerated.
 *
 * A refusal is not silent. §5.3 requires the attempt in access_logs with the
 * route and the actor's grants at that moment, and §2.6 gives the answer to the
 * person one sentence, MSG-010, sent back to their dashboard rather than left
 * on a page they cannot use.
 */
class EnsureFeatureAccess
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(Request $request, Closure $next, string $feature, string $level = 'FULL'): Response
    {
        $required = PermissionLevel::from($level);

        /** @var PermissionSet $set */
        $set = $request->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        $held = $set->level($feature);

        if ($this->satisfies($held, $required)) {
            return $next($request);
        }

        $this->audit->logAccess(
            $request->user(),
            AccessLogKind::ReadDenied,
            null,
            $request->method().' '.$request->path(),
            [
                'roles' => $set->roleSlugs(),
                'feature' => $feature,
                'held' => $held->value,
                'required' => $required->value,
                'matrix_version' => $set->matrixVersion(),
            ],
        );

        return redirect()
            ->route('dashboard')
            ->with('error', Msg::get('MSG-010'));
    }

    private function satisfies(PermissionLevel $held, PermissionLevel $required): bool
    {
        return match ($required) {
            // TSD-502: a FULL grant implies READ, so asking for READ must not
            // refuse the person who is allowed to act.
            PermissionLevel::Read => $held->canView(),
            PermissionLevel::Full => $held->canAct(),
            // Nobody may put NONE on a route: it would mean "reachable only by
            // those with no rights", which is not a thing the grid says.
            PermissionLevel::None => throw new LogicException(
                "Route middleware feature:{$feature},NONE is a configuration error."
            ),
        };
    }
}
