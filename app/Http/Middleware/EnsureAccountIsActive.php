<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * FSD-202 — deactivation stops the person, not merely the next login.
 *
 * is_active is checked at login by the credential screen, but a session that is
 * already open would otherwise survive an administrator turning the account off
 * until its thirty minutes ran out. This closes that window on the request that
 * follows the switch, and says so in the flash rather than dropping the user
 * back at the login form with no explanation.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('auth.account_inactive'));
        }

        return $next($request);
    }
}
