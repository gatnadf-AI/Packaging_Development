<?php

declare(strict_types=1);

namespace App\Http\Controllers\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\PackagingCategoryDirectory;
use App\Domain\MasterData\PackagingCategoryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreCategoryRequest;
use App\Http\Requests\Master\UpdateCategoryRequest;
use App\Models\PackagingCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCR-4.1 — the packaging category list (FSD-401).
 *
 * Four verbs, and the fourth is the reason this screen has a controller of its
 * own rather than a resource class: `destroy` exists, but it only succeeds while
 * nothing points at the row. The service answers that with MSG-070 and the live
 * count, the schema answers it with a RESTRICT key, and this method is the thin
 * span between them — no SQL, no count of its own, no opinion about whether the
 * delete should go through (TSD §2.3, stage 4).
 *
 * There is no `show`. A category's whole record is the three columns the list
 * already carries plus the count of items filed under it; FSD §5 gives SCR-4.1
 * one route, and a detail page for a name and a sentence would be a second
 * place to keep the same vocabulary.
 */
class PackagingCategoryController extends Controller
{
    public function __construct(
        private readonly PackagingCategoryService $categories,
        private readonly PackagingCategoryDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Master/Categories', [
            'screen' => 'SCR-4.1',
            'categories' => $this->directory->paginate($request->query()),
            'filters' => $this->directory->resolvedFilters($request->query()),
            'columns' => $this->meta(),
            'can' => ['write' => $this->mayWrite($request)],
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $category = $this->categories->create(
            $request->categoryName(),
            $request->categoryDescription(),
            $request->user(),
        );

        return back()->with('status', "Kategori \"{$category->name}\" ditambahkan.");
    }

    public function update(UpdateCategoryRequest $request, PackagingCategory $category): RedirectResponse
    {
        $version = $request->expectedVersion();

        // Two audited acts out of one save, in the order that keeps the second
        // one's token honest: the text edit bumps the row, so the flag is asked
        // for against the version the edit just returned rather than the one the
        // form loaded. Sending the loaded token twice is how a row that moved
        // under a form gets overwritten by the save that follows it.
        $category = $this->categories->edit($category, $request->categoryAttributes(), $request->user(), $version);

        $wants = $request->wantsActive();

        if ($wants !== null && $wants !== (bool) $category->is_active) {
            $category = $this->categories->setActive($category, $wants, $request->user(), (int) $category->row_version);
        }

        return back()->with('status', "Kategori \"{$category->name}\" disimpan.");
    }

    /**
     * The delete, which the domain may refuse.
     *
     * No request class: there is nothing on this act to validate. What decides
     * it is how many items are filed here at the moment the button arrives, and
     * PackagingCategoryService::delete() asks that question of the same subquery
     * the list column above drew (TSD §3.5) — so the number in MSG-070 is the
     * number the operator was looking at.
     */
    public function destroy(Request $request, PackagingCategory $category): RedirectResponse
    {
        $name = $category->name;

        $this->categories->delete($category, $request->user());

        return back()->with('status', "Kategori \"{$name}\" dihapus.");
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'searchable' => PackagingCategoryDirectory::SEARCHABLE,
            'sortable' => PackagingCategoryDirectory::SORTABLE,
            'defaultSort' => ['column' => 'name', 'direction' => 'asc'],
            'perPageOptions' => PackagingCategoryDirectory::PER_PAGE,
            'defaultPerPage' => PackagingCategoryDirectory::DEFAULT_PER_PAGE,
            'searchFloor' => PackagingCategoryDirectory::SEARCH_FLOOR,
        ];
    }

    private function mayWrite(Request $request): bool
    {
        /** @var PermissionSet $set */
        $set = $request->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return $set->canAct(PackagingCategoryService::FEATURE);
    }
}
