<?php

declare(strict_types=1);

namespace App\Http\Controllers\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\PackagingCategoryService;
use App\Domain\MasterData\PackagingItemDirectory;
use App\Domain\MasterData\PackagingItemService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SetItemActiveRequest;
use App\Http\Requests\Master\StoreItemRequest;
use App\Http\Requests\Master\UpdateItemRequest;
use App\Models\PackagingItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCR-4.2 — the packaging item list (FSD-401, FSD-402, FSD-403).
 *
 * There is no `destroy` on this screen, and the absence is the requirement
 * rather than the omission: FSD-402 retires a pack and keeps every record that
 * named it, so a specification, an assignment or a printout that cited the item
 * still reads and still prints. PackagingItemScreenTest asserts the route table
 * carries no DELETE under `master/items` at all, which is the only way that
 * statement stays true once somebody adds a "cleanup" button.
 *
 * The retirement is its own verb (`active`) rather than a field on the general
 * edit for the same reason it has its own audit action: it is the act that
 * replaces deleting, and an operator should be able to ask the trail for it.
 *
 * The two count columns FSD §5 names for this row — Spesifikasi and Standar —
 * are not computed here. Their tables arrive with the specification (Phase 2d)
 * and the standard (Phase 5), and FR-603's complaint about the prototype was a
 * count reading 0 while the data held rows; a 0 printed because the phase has
 * not come is that same defect with a date on it.
 */
class PackagingItemController extends Controller
{
    public function __construct(
        private readonly PackagingItemService $items,
        private readonly PackagingItemDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Master/Items', [
            'screen' => 'SCR-4.2',
            'items' => $this->directory->paginate($request->query()),
            'filters' => $this->directory->resolvedFilters($request->query()),
            'columns' => $this->meta(),
            'reference' => $this->directory->referenceOptions(),
            'can' => ['write' => $this->mayWrite($request)],
        ]);
    }

    public function store(StoreItemRequest $request): RedirectResponse
    {
        $item = $this->items->create($request->itemAttributes(), $request->user());

        return back()->with('status', "Item \"{$item->code}\" ditambahkan.");
    }

    public function update(UpdateItemRequest $request, PackagingItem $item): RedirectResponse
    {
        $item = $this->items->update(
            $item,
            $request->itemAttributes(),
            $request->user(),
            $request->expectedVersion(),
        );

        return back()->with('status', "Item \"{$item->code}\" disimpan.");
    }

    public function setActive(SetItemActiveRequest $request, PackagingItem $item): RedirectResponse
    {
        $item = $this->items->setActive(
            $item,
            $request->wantsActive(),
            $request->user(),
            $request->expectedVersion(),
        );

        return back()->with(
            'status',
            $item->is_active
                ? "Item \"{$item->code}\" aktif kembali."
                : "Item \"{$item->code}\" dinonaktifkan; setiap catatan yang menyebutnya tetap terbaca."
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'searchable' => PackagingItemDirectory::SEARCHABLE,
            'sortable' => PackagingItemDirectory::SORTABLE,
            'defaultSort' => ['column' => 'name', 'direction' => 'asc'],
            'perPageOptions' => PackagingItemDirectory::PER_PAGE,
            'defaultPerPage' => PackagingItemDirectory::DEFAULT_PER_PAGE,
            'searchFloor' => PackagingItemDirectory::SEARCH_FLOOR,
        ];
    }

    private function mayWrite(Request $request): bool
    {
        /** @var PermissionSet $set */
        $set = $request->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return $set->canAct(PackagingCategoryService::FEATURE);
    }
}
