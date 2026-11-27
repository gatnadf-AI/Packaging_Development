<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Access\SessionInventory;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FSD-105 — "your device" and everyone else's, on one screen.
 *
 * A user may revoke any of their own sessions except the one they are using.
 * Both halves of that sentence are enforced here rather than assumed: the list
 * is filtered by the account id, and the revoke is refused when the id matches
 * the session carrying the request.
 */
class SessionController
{
    public function __construct(private readonly SessionInventory $sessions) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Account/Sessions', [
            'sessions' => $this->sessions->forUser((int) $user->getKey(), $request->session()->getId()),
            'idleMinutes' => (int) config('session.lifetime'),
            'warnMinutes' => (int) config('session.idle_warning_minutes'),
        ]);
    }

    public function destroy(Request $request, string $session): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = $request->session()->getId();

        if ($session === $current) {
            return back()->withErrors(['session' => 'Anda tidak dapat menutup sesi yang sedang digunakan.']);
        }

        if (! $this->sessions->revoke($user, $session, $current, $user)) {
            // Not theirs, already gone, or never existed. The same answer for
            // all three so the screen does not become a way to probe ids.
            return back()->withErrors(['session' => 'Sesi itu tidak dapat ditemukan atau sudah ditutup.']);
        }

        return back()->with('status', 'Sesi ditutup.');
    }
}
