<?php

namespace App\Http\Middleware;

use App\Domain\Access\PermissionSet;
use App\Domain\Notify\ActiveAnnouncements;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var PermissionSet $set */
        $set = $request->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        $account = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $account,
                // FSD-614's sharing, as the list of features this person may
                // open. The names only, never the levels: a menu that can read
                // FULL versus READ starts deciding buttons from it, and TSD-704
                // keeps that answer in the screen's own `can` props, computed by
                // the policy from the same set. The navigation of package 1.5 is
                // built on this key rather than on a role name.
                'features' => fn (): array => $set->viewableFeatures(),
            ],
            // FSD-101's menu, built here so that the answer the shell draws and
            // the answer the route gate gives come from one comparison rather
            // than two. The front end receives groups and links, never levels.
            'nav' => fn (): array => Navigation::for($set),
            // FSD-208's banner, for the same reason the menu is here: it is drawn
            // by the shell (FSD §2.3) and so belongs to every page rather than to
            // whichever one was loaded last. Asking once also means the words a
            // person sees are decided by the same instant for the whole request —
            // a window that is evaluated per screen could straddle the minute it
            // goes up. Lazy, because a guest on the login screen has no banner,
            // and a page that needs none should not pay for the query.
            'announcements' => fn (): array => $account instanceof User
                ? ActiveAnnouncements::forUser($account)
                : [],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // FSD-103: the browser timer is only trustworthy if it is reading
            // the same numbers the server will apply.
            'session' => [
                'idle_minutes' => (int) config('session.lifetime'),
                'warn_minutes' => (int) config('session.idle_warning_minutes'),
            ],
        ];
    }
}
