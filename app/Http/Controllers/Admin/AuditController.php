<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Access\PermissionSet;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditHistory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCR-2.5 — the audit viewer (FSD-207, reading what FSD-907 made the services
 * write).
 *
 * There is one method here, and that is the requirement: FSD-207 says the trail
 * is read-only and that no audit row is deleted or edited, so the screen has no
 * write to route to. The expansion a row offers is the before/after pair the
 * list already carried — §2.5's strip and §207's expansion are the same two
 * columns — which means the screen needs no per-row URL at all, and "no verb but
 * GET" is a fact about the routing rather than a policy that has to remember to
 * refuse.
 *
 * The filtering, the paging and the page sizes are decided by AuditHistory, not
 * by this controller or by the browser. TSD §12 assumes ~50k audit rows a year
 * and says the viewer always pages server-side; a component that could ask for
 * more rows than the reader permits would be the second half of that sentence
 * broken in the wrong direction. What the props echo back is what the reader
 * actually applied, so the chips above the table cannot disagree with the rows
 * under them (§2.4).
 *
 * Two halves of FSD-207 are deliberately not here yet, and both are stated
 * rather than left to be noticed:
 *
 * The export. The cell that governs it (report.export) is answered below and
 * handed to the screen, but the file itself belongs with the export contract
 * §2.7 and the nine reports of TSD §15 Phase 6 — the two need the same formatted
 * value and the same filter-description header row, and an audit CSV written
 * now would be a third spelling of a rule that is being written once. The button
 * is therefore shown disabled with that reason, rather than absent: an
 * administrator who is promised an export by the requirement should be able to
 * see that it is a phase, not a permission they lack.
 *
 * The read log. Denied attempts and commercial reads land in access_logs (§3.3.5
 * keeps them off audit_entries on purpose, so a flood of reads cannot bury a
 * decision), and FSD-207 does not put either table on this screen. §5.3's
 * "who knocked" list is an addition to the FSD rather than a reading of it, so it
 * is reported to the document owner instead of being slipped in beside the trail.
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Audit', [
            'screen' => 'SCR-2.5',
            'audit' => AuditHistory::paginate($request->query()),
            'columns' => $this->meta(),
            'reference' => AuditHistory::options(),
            'can' => $this->ability($request),
        ]);
    }

    /**
     * §2.4's list meta, said out loud so the browser does not have to guess it.
     *
     * Two of these answers are "none": no column of the trail is sortable,
     * because the order is the one thing an audit list is guaranteed to have
     * (§2.5's newest-first), and no column is searchable, because every term a
     * person could type here is one of FSD-207's four filters and a free-text
     * box beside them would be a second way to ask the same question. The page
     * sizes are the short list and the default is the wide one, which is what
     * rows that expand into a before/after pair are worth.
     *
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'searchable' => [],
            'sortable' => [],
            'defaultSort' => ['column' => 'created_at', 'direction' => 'desc'],
            'perPageOptions' => AuditHistory::PER_PAGE,
            'defaultPerPage' => AuditHistory::DEFAULT_PER_PAGE,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function ability(Request $request): array
    {
        /** @var PermissionSet $set */
        $set = $request->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return ['export' => $set->canAct('report.export')];
    }
}
