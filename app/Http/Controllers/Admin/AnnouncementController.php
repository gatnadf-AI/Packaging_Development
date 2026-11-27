<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Access\PermissionSet;
use App\Domain\Notify\AnnouncementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Models\Announcement;
use App\Support\Notify\AnnouncementList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCR-2.4 — the announcements sheet (FSD-208).
 *
 * Four verbs and no free parameters, which is what the requirement leaves room
 * for: an administrator writes a notice, ends one that is coming down, and takes
 * back one nobody could have read yet. There is no edit, and that is the reading
 * worth stating: FSD-208 says a notice has a title, a body, an audience and a
 * window, and says nothing about changing any of them once it is up. Rewording a
 * banner somebody has already read would make their dismissal a fact about
 * different words than the ones now on screen, so the screen stops the notice and
 * the administrator writes a new one. If the FSD owner means otherwise, that is a
 * sentence the requirement has to gain first.
 *
 * The split of gates is the matrix screen's split, and for the same reason: the
 * sheet is read at admin.access READ — a reviewer asked to look over what is
 * going up has to see it — and every write on it is FULL. The per-row "can" flags
 * therefore come from one question about the reader rather than from the rows,
 * because nothing in FSD-208 makes one notice somebody else's business and
 * another notice yours.
 */
class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Announcements', [
            'screen' => 'SCR-2.4',
            'announcements' => AnnouncementList::paginate($request->query()),
            'columns' => $this->meta(),
            'reference' => AnnouncementList::options(),
            'can' => $this->ability($request),
        ]);
    }

    public function store(StoreAnnouncementRequest $request): RedirectResponse
    {
        $announcement = $this->announcements->create($request->announcementInput(), $request->user());

        return back()->with('status', "Pengumuman \"{$announcement->title}\" disimpan.");
    }

    /**
     * Takes a live notice down. Its own verb rather than a field on an edit
     * request, because the act is not "the window changed" but "stop".
     */
    public function stop(Announcement $announcement): RedirectResponse
    {
        $this->announcements->stop($announcement);

        return back()->with('status', "Pengumuman \"{$announcement->title}\" berhenti tayang.");
    }

    /**
     * Takes back a notice that has not gone up.
     *
     * The refusal for one that has is the domain's, and it arrives under the row
     * rather than as a page-wide error: what the operator pressed was this row's
     * button.
     */
    public function destroy(Announcement $announcement): RedirectResponse
    {
        $this->announcements->delete($announcement);

        return back()->with('status', 'Pengumuman yang belum tayang dihapus.');
    }

    /**
     * §2.4's list meta. Nothing here is searchable or sortable, and both are said
     * rather than left blank: the sheet's order is the window (what is up first),
     * and a sort control that could put an ended notice above a live one would be
     * a second answer to "what is happening now" that the banner does not give.
     *
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'searchable' => [],
            'sortable' => [],
            'defaultSort' => ['column' => 'publish_at', 'direction' => 'desc'],
            'perPageOptions' => AnnouncementList::PER_PAGE,
            'defaultPerPage' => AnnouncementList::DEFAULT_PER_PAGE,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function ability(Request $request): array
    {
        /** @var PermissionSet $set */
        $set = $request->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return ['write' => $set->canAct('admin.access')];
    }
}
