<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * `expired` is MSG-005: the idle timer in AuthenticatedLayout sends an
     * outlived session here and the difference between "you are logged out" and
     * "your session ran out" is the difference between confusion and a reason.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'expired' => $request->boolean('expired'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * There is deliberately no second `session()->regenerate()` here, which is
     * what the Breeze screen ships with. The guard already migrated the session
     * id inside `attempt()` (TSD-1103), and regenerating a second time would
     * move the session to a new row after the login listener has stamped the
     * owner on the old one — which is exactly how a "your other sessions" list
     * ends up showing nothing.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Anda sudah keluar.');
    }
}
