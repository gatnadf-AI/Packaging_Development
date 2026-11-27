<?php

declare(strict_types=1);

namespace App\Http\Controllers\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\ProductDirectory;
use App\Domain\MasterData\ProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SetProductActiveRequest;
use App\Http\Requests\Master\StoreProductRequest;
use App\Http\Requests\Master\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCR-4.5 — the product master (FSD-409, FSD-812).
 *
 * Four verbs and no DELETE, which is the requirement rather than an omission:
 * FSD-812 speaks of deactivating a product and G-13's test for a deletion cannot
 * be computed while no table names a product. ProductScreenTest asserts the route
 * table has no delete verb under master/products, so the absence survives the day
 * somebody reaches for a cleanup button.
 *
 * The retirement is its own route rather than a field on the edit for the reason
 * it is on SCR-4.2: it is the act that replaces removing a row, so the trail
 * should be able to answer "who retired this product" without reading a form
 * submission.
 *
 * There are no reference pickers here. A product is the only master list in F4
 * whose fields are all words the person types, so the page needs no option lists
 * from the server — which is also why this screen hands over no `reference` prop.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly ProductDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Master/Products', [
            'screen' => 'SCR-4.5',
            'items' => $this->directory->paginate($request->query()),
            'filters' => $this->directory->resolvedFilters($request->query()),
            'columns' => $this->meta(),
            'can' => ['write' => $this->mayWrite($request)],
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = $this->products->create($request->productAttributes(), $request->user());

        return back()->with('status', "Produk \"{$product->code}\" ditambahkan.");
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product = $this->products->update(
            $product,
            $request->productAttributes(),
            $request->user(),
            $request->expectedVersion(),
        );

        return back()->with('status', "Produk \"{$product->code}\" disimpan.");
    }

    public function setActive(SetProductActiveRequest $request, Product $product): RedirectResponse
    {
        $product = $this->products->setActive(
            $product,
            $request->wantsActive(),
            $request->user(),
            $request->expectedVersion(),
        );

        return back()->with(
            'status',
            $product->is_active
                ? "Produk \"{$product->code}\" aktif kembali."
                : "Produk \"{$product->code}\" dinonaktifkan; setiap spesifikasi yang menyebutnya tetap terbaca."
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'searchable' => ProductDirectory::SEARCHABLE,
            'sortable' => ProductDirectory::SORTABLE,
            'defaultSort' => ['column' => ProductDirectory::DEFAULT_SORT, 'direction' => 'asc'],
            'perPageOptions' => ProductDirectory::PER_PAGE,
            'defaultPerPage' => ProductDirectory::DEFAULT_PER_PAGE,
            'searchFloor' => ProductDirectory::SEARCH_FLOOR,
        ];
    }

    private function mayWrite(Request $request): bool
    {
        /** @var PermissionSet $set */
        $set = $request->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return $set->canAct(ProductService::FEATURE);
    }
}
