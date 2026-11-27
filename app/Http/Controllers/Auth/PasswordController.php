<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Access\PasswordPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * FSD-104 — the voluntary change, from the profile screen.
 *
 * Same strength rule as the forced change, because two doors on one account
 * must not have two different locks. The current password is required here and
 * not there: on this screen the user already knows the secret.
 */
class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => array_merge(PasswordPolicy::rules($user->email), ['confirmed']),
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('status', 'Password Anda sudah diganti.');
    }
}
