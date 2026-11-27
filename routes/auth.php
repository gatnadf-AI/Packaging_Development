<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\ForcedPasswordChangeController;
use App\Http\Controllers\Auth\KeepAliveController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

/*
 * FSD-201 (FR-101) — there is no register route. An account is created by an
 * administrator, is given one of the fourteen identities, and is expected to
 * replace the password that was set for it. A public sign-up form would let
 * anyone into a system whose whole permission model is a grid of named roles,
 * so the screen and its controller are removed rather than hidden.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware(['auth', 'localize'])->group(function () {
    // FSD-104: reachable while the account is still locked to its first password.
    Route::get('password/change', [ForcedPasswordChangeController::class, 'show'])
        ->name('password.change');

    Route::post('password/change', [ForcedPasswordChangeController::class, 'store'])
        ->name('password.change.store');

    // FSD-103: the keep-alive ping behind MSG-004's "Perpanjang".
    Route::post('session/extend', KeepAliveController::class)
        ->name('session.extend');

    // FSD-105, at the route the FSD names (SCR-1.4 /profile/sessions). A session
    // id is a 40-character token, not a uuid, so the parameter is taken as it
    // comes and scoped by owner in the controller.
    Route::get('profile/sessions', [SessionController::class, 'index'])
        ->name('profile.sessions.index');

    Route::delete('profile/sessions/{session}', [SessionController::class, 'destroy'])
        ->name('profile.sessions.destroy');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
