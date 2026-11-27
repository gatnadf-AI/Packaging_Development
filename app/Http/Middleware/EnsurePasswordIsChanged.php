<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FSD-104 — an account whose password was set by an administrator can do
 * nothing else until it has been changed.
 *
 * The check is a middleware rather than a controller guard because the point of
 * the rule is that no screen can forget it. The four routes that stay reachable
 * are the change form, its submission, the keep-alive ping and logout.
 *
 * The path the user was aiming at is remembered for the change screen to hand
 * back (FSD-104 promises the flow continues where it was interrupted, which is
 * what makes an invitation link survive). Only the path and query are stored,
 * never a full URL: an absolute target would turn this convenience into an
 * open redirect, and the host is not the user's to choose.
 */
class EnsurePasswordIsChanged
{
    public const INTENDED_KEY = 'pds.intended_after_password';

    /** @var list<string> */
    private const ALLOWED = [
        'password.change',
        'password.change.store',
        'session.extend',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password && ! $request->routeIs(self::ALLOWED)) {
            if ($request->method() === 'GET' && $request->path() !== '/') {
                $request->session()->put(self::INTENDED_KEY, $request->getRequestUri());
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
