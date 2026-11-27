<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Notify\AnnouncementService;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * FSD-208's dismissible banner — the reader's half of the announcement.
 *
 * One route outside the administration area, and the reason it is outside is the
 * requirement itself: the banner is shown to the staff an announcement addresses,
 * which is everybody, so a dismissal cannot live behind admin.access. It carries
 * no feature cell for that reason, and the navigation catalogue's invariant test
 * (every menu entry is guarded by the cell it claims) does not reach it — this is
 * not a menu entry and not a screen. FSD-614 forbids a screen from adding its own
 * role test; it does not ask a person to be permitted to say "I have read this".
 *
 * Two things the route does not take are the point. The account comes from the
 * session, so a body parameter cannot make one person dismiss another's banner;
 * and the announcement id is not checked for reach, because a preference row
 * about a notice this account was never addressed to simply never shows: the
 * reader's list is decided by ActiveAnnouncements, not by the reads table.
 */
class AnnouncementDismissController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    public function store(Request $request, Announcement $announcement): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->announcements->dismiss($announcement, $user);

        // No flash: the banner leaving the screen is the whole answer, and a
        // sentence about it would be the notice telling you it is gone after it
        // is already gone.
        return back();
    }
}
