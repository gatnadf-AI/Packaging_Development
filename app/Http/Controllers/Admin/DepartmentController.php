<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DepartmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDepartmentRequest;
use App\Http\Requests\Admin\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * FSD-206 — the department reference list, edited from the panel on SCR-2.1.
 *
 * There is no index method and no /admin/departments route, and that is a
 * decision worth stating rather than leaving to the reader: the FSD gives the
 * editable reference lists a requirement (FSD-206) but no screen id of their
 * own, and inventing an SCR-2.6 to hold a four-row table would add a screen the
 * matrix has no cell for and the Phase 1.6 gate no way to check. The panel is
 * reached from the account directory, which is where the list is actually used —
 * an administrator picks a department while filing a person in it.
 *
 * The same reason explains what is absent: no destroy method. §3.1 makes the
 * foreign key RESTRICT and names deactivation as the mechanism for retiring a
 * department, and DepartmentService has no delete path either, so a route here
 * would be the only way to reach a statement the requirement forbids.
 */
class DepartmentController extends Controller
{
    public function __construct(
        private readonly DepartmentService $departments,
    ) {}

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        Gate::authorize('create', Department::class);

        $department = $this->departments->create(
            $request->departmentName(),
            $request->user(),
        );

        return back()->with('status', 'Departemen '.$department->name.' ditambahkan.');
    }

    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $version = $request->expectedVersion();
        $name = $request->departmentName();
        $active = $request->wantsActive();

        // Renaming and switching off are two audited acts even though the panel
        // submits them as one save: a reader of the log has to be able to tell
        // "Marketing became Marketing & Communications" apart from "Marketing was
        // closed", and a form that changed both at once would otherwise blur them
        // into a single before/after pair.
        if ($name !== $department->name) {
            $department = $this->departments->rename($department, $name, $request->user(), $version);
            $version = $department->row_version;
        }

        if ($active !== (bool) $department->is_active) {
            // The count is not passed in: setActive() asks the database again when
            // it writes (TSD-520), and the number the panel warned with is only
            // ever shown in its own confirmation dialog, so a value carried here
            // would be a second opinion the write path is designed to ignore.
            $this->departments->setActive($department, $active, $request->user(), $version);
        }

        return back()->with('status', 'Daftar departemen disimpan.');
    }

    /**
     * The live-count pre-check of TSD-520, as the panel needs it before anyone
     * presses the switch.
     *
     * This is a GET because the answer decides a confirmation dialog rather than
     * the state of a row; §3.5 puts the same number inside the write path, and
     * the two read the same query. A stale count from this endpoint cannot let a
     * deactivation through, because DepartmentService asks again when it writes.
     */
    public function usage(Department $department): JsonResponse
    {
        Gate::authorize('view', $department);

        return response()->json([
            'department_id' => (int) $department->getKey(),
            'name' => $department->name,
            'user_count' => $this->departments->userCount($department),
            'row_version' => (int) $department->row_version,
        ]);
    }
}
