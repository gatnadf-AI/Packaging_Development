<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\FeatureGrant;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditService;
use App\Support\Persistence\RowVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TSD §6 — the only writer of who holds which role, and of the matrix cells that
 * say what holding a role is allowed to do.
 *
 * The service is named for the row in the design document's service table
 * ("AccessService | grant, revoke, assignRoles, resolveStepActor"), and it is
 * deliberately the only way a role row changes hands. A controller that called
 * syncRoles() itself would skip three things at once: the segregation rule, the
 * audit entry, and the permission cache that the next request reads.
 *
 * assignRoles() does the whole act in one transaction (TSD §2.3, stage 5): the
 * pivot rows, the user's version token, and the audit entry. The three are one
 * decision, and a half-written one is worse than none — an account that holds a
 * role nobody authorised is exactly the failure the matrix test in Phase 1.6 is
 * there to catch.
 *
 * What is NOT here yet, and why:
 *
 * grant() writes a matrix cell and is the editor half of FSD-204 that arrived
 * with Phase 1.3; the TSD's "revoke" is the same write to NONE, which the grid's
 * click-to-cycle reaches through PermissionLevel::next(). resolveStepActor needs
 * chain steps, which are Phase 3.
 *
 * TSD-523 is enforced in its conservative form. The document refuses "admin plus
 * any role referenced by an APPROVE chain step"; until chain steps exist, nothing
 * can be named by that set, so the rule is applied as "admin plus any other role"
 * and config/access.php holds the list so that narrowing it is a data change.
 * Phase 3 replaces the list with the roles the chain table actually references.
 */
final class AccessService
{
    /**
     * False until the supplier master and supplier_user_links exist (Phase 4).
     * FSD-201 makes a supplier link a mandatory part of a supplier-scoped
     * account, so the role cannot be handed out before there is something to
     * link to; see ChangeRefused::supplierLinkUnavailable().
     */
    private const SUPPLIER_LINKS_AVAILABLE = false;

    /**
     * The cell FSD-204 puts the self-revoke rule on. The key is the one the
     * seeded matrix and the route middleware carry; the FSD's prose calls the
     * same feature admin.manage, a wording gap reported to the document owner
     * rather than a second key added here.
     */
    private const ADMIN_FEATURE = 'admin.access';

    public function __construct(
        private readonly AuditService $audit,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * Make the user's role set exactly the set given, and record the change.
     *
     * @param  list<string>  $roleSlugs
     */
    public function assignRoles(
        User $user,
        array $roleSlugs,
        ?Authenticatable $actor,
        int $expectedVersion,
    ): User {
        $slugs = $this->distinctSlugs($roleSlugs);

        return DB::transaction(function () use ($user, $slugs, $actor, $expectedVersion): User {
            $before = $this->roleSnapshot($user);

            $this->guardTsd523($slugs);
            $this->guardOwnAdmin($user, $slugs, $actor);

            if ($before === $slugs) {
                // Nothing moved, so nothing is written and no token is burned.
                // A history strip full of "roles: admin → admin" would hide the
                // rows that record a real decision.
                return $user;
            }

            $user->syncRoles($slugs);
            $this->registrar->forgetCachedPermissions();

            // TSD-510's invalidation is about the matrix cells, and a role
            // assignment is not one: the version stays put. What has to be
            // dropped is Spatie's cached permission set (TSD-501), because the
            // gate asks it what a role holds, and the resolver memo keyed on the
            // user's slugs cannot serve a set that just changed shape.

            $fresh = RowVersion::bump($user, $expectedVersion);

            $this->audit->record(
                $actor,
                AuditAction::UserRolesAssigned,
                $user,
                ['roles' => $before],
                ['roles' => $slugs],
            );

            return $fresh;
        });
    }

    /**
     * FSD-204 with TSD-511 — save one cell of the access matrix.
     *
     * The cell is addressed by its natural key and written with the level the
     * screen was showing in the WHERE clause, because "saving is per cell,
     * optimistic" is a statement about a race: two administrators on the same
     * grid both see FULL, the one who arrives second is refused rather than
     * quietly overwriting the first. Nothing else in the row is touched, and no
     * other cell moves with it — that is why a page of edits is many small saves
     * (TSD-511) and why the grid cycles one cell on a click instead of posting a
     * form.
     *
     * TSD-511's fourth step, "queue N-21 to affected users", is not here: the
     * in-app notification store arrives with the notification module in Phase 6,
     * and a write path that silently drops a notification is worse than one that
     * openly has not grown it yet.
     */
    public function grant(
        string $featureKey,
        string $roleSlug,
        PermissionLevel $from,
        PermissionLevel $to,
        ?Authenticatable $actor,
    ): FeatureGrant {
        return DB::transaction(function () use ($featureKey, $roleSlug, $from, $to, $actor): FeatureGrant {
            if ($from === $to) {
                // Nothing moved, so nothing is written: no version burned, no
                // audit row claiming a decision that was never made. Same rule
                // assignRoles() applies to an unchanged role set.
                return $this->cell($featureKey, $roleSlug);
            }

            $this->guardAdminCell($featureKey, $roleSlug, $from, $to, $actor);

            $affected = DB::table('feature_grants')
                ->where('feature_key', $featureKey)
                ->where('role_slug', $roleSlug)
                ->where('level', $from->value)
                ->update([
                    'level' => $to->value,
                    'changed_at' => now(),
                    'changed_by' => $actor?->getAuthIdentifier(),
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new RecordHasBeenChanged("matrix cell {$featureKey} x {$roleSlug}");
            }

            // ADR-08 / TSD-510: the one integer that invalidates every cached
            // permission set. Bumping it is what makes the cell the resolver
            // answers with change inside this same request, which is the promise
            // FSD-204 makes and the reason there is no TTL anywhere here.
            MatrixVersion::bump($actor?->getAuthIdentifier());

            $grant = $this->cell($featureKey, $roleSlug);

            // TSD-511's third step, inside the same transaction as the write: a
            // cell that moved without an entry is a permission change nobody can
            // account for, and an entry for a cell that did not move is noise.
            // The pair is the decision; the cell itself is named by the target.
            $this->audit->record(
                $actor,
                AuditAction::MatrixCellChanged,
                $grant,
                ['level' => $from->value],
                ['level' => $to->value],
            );

            return $grant;
        });
    }

    /**
     * The cell addressed by its natural key, the way §3.1 keys the grid.
     */
    private function cell(string $featureKey, string $roleSlug): FeatureGrant
    {
        return FeatureGrant::query()
            ->where('feature_key', $featureKey)
            ->where('role_slug', $roleSlug)
            ->sole();
    }

    /**
     * @param  list<string>  $slugs
     */
    private function guardTsd523(array $slugs): void
    {
        if (self::SUPPLIER_LINKS_AVAILABLE === false) {
            $supplierScoped = (array) config('access.supplier_scoped_roles', []);

            if (array_intersect($slugs, $supplierScoped) !== []) {
                throw ChangeRefused::supplierLinkUnavailable();
            }
        }

        $exclusive = (array) config('access.exclusive_roles', []);
        $heldExclusive = array_intersect($slugs, $exclusive);

        if ($heldExclusive !== [] && count($slugs) > count($heldExclusive)) {
            throw ChangeRefused::heldWithExclusiveRole($slugs);
        }
    }

    /**
     * FSD-204 states the rule for the matrix ("Admin cannot revoke their own
     * admin.manage"). The same hole exists one level down: an administrator who
     * can strip the admin role from their own account can lock every future
     * administrator out of /admin with nobody left to undo it. Refusing it costs
     * nothing and keeps the one account the system cannot recreate for itself.
     */
    private function guardOwnAdmin(User $user, array $slugs, ?Authenticatable $actor): void
    {
        if ($actor === null || (int) $actor->getAuthIdentifier() !== (int) $user->getKey()) {
            return;
        }

        $exclusive = (array) config('access.exclusive_roles', []);

        if (array_intersect($this->roleSnapshot($user), $exclusive) !== []
            && array_intersect($slugs, $exclusive) === []) {
            throw ChangeRefused::ownAdminRevoked();
        }
    }

    /**
     * FSD-204's own guard: "Admin cannot revoke their own admin.manage".
     *
     * The cell that governs the administration area sits behind itself, so
     * lowering it for a role the actor holds closes the very screen that would
     * have to reopen it — the same trap guardOwnAdmin() refuses one level down in
     * the role table.
     *
     * Enforced in the conservative form, the way TSD-523 is: the question asked
     * is "does the actor hold this role, and is this a reduction", not "would the
     * union of their roles still reach the screen". The two readings can only
     * differ for an account that holds admin alongside a business role, and
     * TSD-523 already refuses that assignment.
     */
    private function guardAdminCell(
        string $featureKey,
        string $roleSlug,
        PermissionLevel $from,
        PermissionLevel $to,
        ?Authenticatable $actor,
    ): void {
        if ($actor === null || $featureKey !== self::ADMIN_FEATURE || $to->value === $from->value) {
            return;
        }

        $isReduction = match (true) {
            $from === PermissionLevel::Full => true,
            $from === PermissionLevel::Read => $to === PermissionLevel::None,
            default => false,
        };

        if ($isReduction && $actor->getRoleNames()->contains($roleSlug)) {
            throw ChangeRefused::adminCellLoweredByItsHolder($roleSlug);
        }
    }

    /**
     * @return list<string>
     */
    private function roleSnapshot(User $user): array
    {
        return $user->roles
            ->pluck('name')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $roleSlugs
     * @return list<string>
     */
    private function distinctSlugs(array $roleSlugs): array
    {
        $slugs = array_values(array_unique(array_map(
            static fn (mixed $slug): string => trim((string) $slug),
            $roleSlugs
        )));

        sort($slugs);

        $known = Role::query()->where('guard_name', 'web')->pluck('name')->all();
        $unknown = array_diff($slugs, $known);

        if ($unknown !== []) {
            // Not a user error: a slug the matrix does not know about means the
            // seeder and the request have drifted, which is a deployment fault.
            throw new InvalidArgumentException('Unknown role slug: '.implode(', ', $unknown));
        }

        return $slugs;
    }
}
