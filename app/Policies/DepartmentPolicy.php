<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\MatrixResolver;
use App\Models\Department;
use App\Models\User;

/**
 * FSD-206 — the reference list shares its feature key with the directory.
 *
 * The matrix in FSD §6 has no "department" feature: departments are edited from
 * the account screen, which is opened by admin.access, so the same grant is the
 * only honest answer. Inventing a feature key would put a cell on the Phase 1.3
 * grid that the signed document does not contain, and the grid is asserted
 * against the FSD by test.
 */
class DepartmentPolicy
{
    public function __construct(private readonly MatrixResolver $matrix) {}

    public function viewAny(User $actor): bool
    {
        return $this->matrix->forUser($actor)->canView(UserPolicy::FEATURE);
    }

    /**
     * Reading one department's live count is the act of deciding whether it can
     * be closed (TSD-520), so it is answered at the level that closes it rather
     * than at the level that lists the panel. The route already carries the same
     * cell, and a policy that quietly disagreed with it would be the second
     * opinion FSD-614 is written against.
     */
    public function view(User $actor, Department $department): bool
    {
        return $this->update($actor, $department);
    }

    public function create(User $actor): bool
    {
        return $this->matrix->forUser($actor)->canAct(UserPolicy::FEATURE);
    }

    public function update(User $actor, Department $department): bool
    {
        return $this->create($actor);
    }
}
