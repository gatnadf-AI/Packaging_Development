<?php

use App\Domain\Access\ChangeRefused;
use App\Domain\Access\RecordHasBeenChanged;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureFeatureAccess;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetUserLocale;
use App\Support\Msg;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // FSD-614: the permission set is resolved once, before anything that
        // reads it — and "before anything" is a position in the stack, not a
        // promise about the moment. It reads $request->user(), which only has an
        // answer once the cookies are decrypted, the session is started and the
        // guard has named the account, so it goes at the head of the appended
        // group (after all three) rather than at the front of the web group.
        // Prepended, every live request was a guest: the grid said FULL, the
        // route said MSG-010, and only a browser could see it. It still has to
        // precede HandleInertiaRequests, which shares the set with the client.
        $middleware->web(append: [
            \App\Http\Middleware\ResolvePermissionSet::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);

        // The two account-state guards (FSD-202, FSD-104) are aliases rather
        // than global middleware: the login screen has to stay reachable for a
        // locked or pending-change account, so the choice is made per route
        // group and is visible in the route list.
        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            // FSD-106: applied after the guard, because the preference lives on
            // the account and a guest gets the system default (Indonesian).
            'localize' => SetUserLocale::class,
            // TSD §5.3: the route half of the matrix. Every screen route under
            // an area carries the cell that governs it, so a URL cannot be
            // reached by a person whose grid says otherwise (FSD-101).
            'feature' => EnsureFeatureAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Two domain refusals have to arrive on the screen as form errors rather
        // than as a 500 page, and they have to arrive the same way from every
        // screen, which is why the mapping lives here and not in a controller.
        //
        // ChangeRefused (TSD-523, FSD-202, FSD-203, FSD-204) carries the sentence
        // and the field it belongs under. §2.6 has no id for a role-segregation
        // refusal, so the wording is the domain's own; it is reported to the
        // reviewer for that reason, not hidden here.
        $exceptions->render(function (ChangeRefused $refused, Request $request): ?RedirectResponse {
            if ($request->expectsJson()) {
                return null;
            }

            return back()->withErrors([$refused->attribute() => $refused->getMessage()])->withInput();
        });

        // RecordHasBeenChanged is FSD §2.5's MSG-020 case: the row moved under
        // the form. The sentence comes from the catalogue, the field is the
        // version token every FormShell already carries, and nothing is saved —
        // a silent overwrite is the behaviour the requirement forbids in as many
        // words. The client's two options ("Muat ulang data" / "Kirim ulang
        // saya") need no extra payload: reloading the screen re-reads the row and
        // its fresh token, and resubmitting is the user's choice to make with
        // that token, not ours.
        $exceptions->render(function (RecordHasBeenChanged $changed, Request $request): ?RedirectResponse {
            if ($request->expectsJson()) {
                return null;
            }

            return back()
                ->withErrors(['row_version' => Msg::get('MSG-020')])
                ->withInput();
        });
    })->create();
