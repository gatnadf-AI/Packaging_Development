<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Access\PasswordPolicy;
use App\Domain\Access\SessionInventory;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FSD-104 — the change that has to happen before anything else does.
 *
 * Kept separate from the profile password form on purpose: this one is not a
 * preference, it is the condition of entry. It therefore does not ask for the
 * current password (the user may never have chosen it) and it redirects to the
 * screen the user first asked for, which is what makes an invitation link still
 * work after an administrator-set password is replaced.
 *
 * Two things happen alongside the write. Every other session of this account is
 * closed, because an administrator-set password was known to someone else and
 * that knowledge should stop being usable. And the session id is deliberately
 * not rotated: it was minted seconds ago at login, it is the row the user is
 * about to see listed under "Sesi aktif", and replacing it here would orphan
 * that row for no additional protection.
 */
class ForcedPasswordChangeController
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Auth/ForcedPasswordChange', [
            'email' => $user->email,
            'rules' => PasswordPolicy::describe(),
        ]);
    }

    public function store(Request $request, SessionInventory $sessions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'password' => array_merge(PasswordPolicy::rules($user->email), ['confirmed']),
        ]);

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        $sessions->revokeOthers($user, (string) $request->session()->getId(), $user);

        return Redirect::to($this->target($request))
            ->with('status', 'Password Anda sudah diganti.');
    }

    /**
     * Where the user was going when the gate closed. Path-only from the
     * middleware is trusted as-is; the framework's intended URL is absolute and
     * is discarded if it points at another host.
     */
    private function target(Request $request): string
    {
        $fallback = route('dashboard', absolute: false);

        $path = $request->session()->pull(EnsurePasswordIsChanged::INTENDED_KEY);

        if (is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
            return $path;
        }

        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && $intended !== '') {
            $host = parse_url($intended, PHP_URL_HOST);

            if ($host === null || $host === $request->getHost()) {
                return $intended;
            }
        }

        return $fallback;
    }
}
