<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\Department;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditService;
use App\Support\Persistence\RowVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * FSD-206 — the department reference list, which is the only reference list this
 * phase has a screen for.
 *
 * The list is small and the rules on it are short, but two of them are worth
 * stating where a reviewer will find them.
 *
 * There is no delete here. §3.1 makes every foreign key RESTRICT and says
 * outright that "deactivation is the mechanism", because a department is what
 * reports group by and what users point at: removing the row would either orphan
 * people or quietly rewrite history. §3.5 asks for a live count when something
 * tries anyway, and that guard belongs to the packaging categories of Phase 2,
 * whose delete-blocked message (MSG-070) is written in their words — "Kategori
 * masih dipakai {n} kemasan" — and would be a lie about a department. When a
 * route for categories arrives, the shared pre-check arrives with it.
 *
 * Deactivating a department that still has people in it is allowed and says how
 * many. The alternative — refusing until the list is empty — would make the
 * reference list the thing that blocks an organisational change rather than the
 * thing that records it.
 */
final class DepartmentService
{
    private const EDITABLE = ['name', 'is_active'];

    public function __construct(private readonly AuditService $audit) {}

    public function create(string $name, ?Authenticatable $actor): Department
    {
        return DB::transaction(function () use ($name, $actor): Department {
            $department = Department::create([
                'name' => trim($name),
                'is_active' => true,
                // The version token is not mass-assignable, in this table as in
                // users: its initial value is the schema's default and RowVersion
                // is the only writer after that.
            ]);

            $this->audit->record(
                $actor,
                AuditAction::DepartmentCreated,
                $department,
                null,
                AuditService::snapshot($department, self::EDITABLE),
            );

            return $department;
        });
    }

    /**
     * A rename is audited as a change of name, so the history strip shows the
     * old spelling the reports were made under instead of only the new one.
     */
    public function rename(Department $department, string $name, ?Authenticatable $actor, int $expectedVersion): Department
    {
        $name = trim($name);

        if ($name === $department->name) {
            return $department;
        }

        $before = AuditService::snapshot($department, self::EDITABLE);

        $fresh = DB::transaction(
            fn (): Department => RowVersion::save($department, $expectedVersion, ['name' => $name])
        );

        $this->audit->record(
            $actor,
            AuditAction::DepartmentUpdated,
            $department,
            $before,
            AuditService::snapshot($fresh, self::EDITABLE),
        );

        return $fresh;
    }

    /**
     * @return array{users: int} how many accounts still sit in this department
     */
    public function setActive(
        Department $department,
        bool $isActive,
        ?Authenticatable $actor,
        int $expectedVersion,
    ): array {
        if ($department->is_active === $isActive) {
            return ['users' => $this->userCount($department)];
        }

        $before = AuditService::snapshot($department, self::EDITABLE);

        $fresh = DB::transaction(function () use ($department, $isActive, $actor, $before, $expectedVersion): Department {
            $fresh = RowVersion::save($department, $expectedVersion, ['is_active' => $isActive]);

            $this->audit->record(
                $actor,
                $isActive ? AuditAction::DepartmentUpdated : AuditAction::DepartmentDeactivated,
                $department,
                $before,
                AuditService::snapshot($fresh, self::EDITABLE),
            );

            return $fresh;
        });

        return ['users' => $this->userCount($fresh)];
    }

    /**
     * What the confirmation dialog and the list column both read, so the number
     * a person is warned with is the number that is actually there (§3.5).
     */
    public function userCount(Department $department): int
    {
        return DB::table('users')->where('department_id', $department->getKey())->count();
    }
}
