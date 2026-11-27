<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Access\AccessService;
use App\Domain\Access\MatrixGrid;
use App\Domain\Access\PermissionSet;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewMatrixRequest;
use App\Http\Requests\Admin\UpdateMatrixCellRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCR-2.3 — the access matrix, drawn from the table FSD §6 filled in and edited
 * one cell at a time (FSD-204, FSD-205).
 *
 * There is no Gate call in this controller, and that is a decision rather than an
 * omission. A policy answers a question about a record — is this person the
 * author, is this row theirs, is this document locked — and the matrix has no
 * such question: the cell a save writes *is* the permission, so a FeaturePolicy
 * would be asked the same thing the route middleware already answered. TSD-704's
 * objection to a second answer applies here exactly: one question, asked once,
 * against the PermissionSet the request resolved. What the middleware and the two
 * form requests both consult is that one set, which is why a READ holder reaches
 * the screen and is refused the write without either layer holding its own copy of
 * the rule.
 *
 * The props are read at request time rather than cached: the version integer the
 * grid carries is the same one every save is checked against, so a screen that
 * kept its own snapshot would be the one place in the application where a
 * permission change could arrive late.
 */
class MatrixController extends Controller
{
    public function __construct(
        private readonly MatrixGrid $grid,
        private readonly AccessService $access,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Matrix', [
            'screen' => 'SCR-2.3',
            'matrix' => fn (): array => [
                'rows' => $this->grid->rows(),
                'roles' => $this->grid->columns(),
                'version' => $this->grid->version(),
            ],
            'can' => $this->ability($request),
        ]);
    }

    /**
     * FSD-204's per-cell save. The response is a plain redirect back: the grid
     * reloads its own props, and a sentence announcing a cell that visibly moved
     * would be noise under the rows a reviewer is comparing.
     */
    public function update(UpdateMatrixCellRequest $request): RedirectResponse
    {
        $this->access->grant(
            $request->featureKey(),
            $request->roleSlug(),
            $request->fromLevel(),
            $request->toLevel(),
            $request->user(),
        );

        return back();
    }

    /**
     * FSD-205's preview, answered as data for the dialog rather than as a page:
     * it is read in the middle of an edit the person may still abandon.
     */
    public function preview(PreviewMatrixRequest $request): JsonResponse
    {
        return response()->json(['diff' => $this->grid->preview($request->cells())]);
    }

    /**
     * @return array<string, bool>
     */
    private function ability(Request $request): array
    {
        $set = $request->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return ['edit' => $set->canAct('admin.access')];
    }
}
