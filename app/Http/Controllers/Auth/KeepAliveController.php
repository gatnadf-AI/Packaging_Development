<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FSD-103 — "Perpanjang" on the idle warning.
 *
 * The session lifetime is the framework's own; this only touches the store so
 * the next request sees a fresh last_activity, which is what makes the answer
 * the same one the authenticator will give. A client-side timer that reset
 * itself without a request would be the kind of extension that fails the first
 * time the user actually navigates.
 */
class KeepAliveController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->session()->put('pds.last_kept_alive', now()->toIso8601String());
        $request->session()->save();

        return response()->json([
            'ok' => true,
            'expires_at' => now()
                ->addMinutes((int) config('session.lifetime'))
                ->setTimezone(config('app.timezone'))
                ->toIso8601String(),
            'idle_minutes' => (int) config('session.lifetime'),
            'warn_minutes' => (int) config('session.idle_warning_minutes'),
        ]);
    }
}
