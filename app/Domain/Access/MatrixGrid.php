<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\Feature;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * SCR-2.3 — the read side of the access matrix (FSD-204, FSD-205).
 *
 * The grid the screen draws and the diff the screen previews are the same
 * reading of the same table, deliberately: FSD-205 asks for the preview "before
 * saving", and a preview computed from a second copy of the truth is how an
 * administrator ends up approving a change they were never shown. There is no
 * cache here — the two methods cost one indexed read each, and ADR-08 already
 * settled that the version integer is the thing that makes a matrix change
 * visible rather than a stored snapshot.
 *
 * Two readings worth naming, because both are places a later edit could quietly
 * change the behaviour:
 *
 * Columns come in insertion order, not alphabetical. The seeder writes the roles
 * in the order FSD §6 lists them — twelve business identities, then the two
 * system ones — so the table's own order is the document's order, and re-sorting
 * it by name would put `admin` beside `bod` and make the grid unreadable against
 * the paper sheet it came from.
 *
 * An absent grant row reads as NONE (§3.1, BR-07) even though the seeder writes
 * every cell. Deny staying the default is what keeps a hand-deleted row from
 * becoming an open door.
 */
final class MatrixGrid
{
    /**
     * The grid: one row per feature, one cell per role.
     *
     * @return list<array{key: string, label: string, group: string, cells: array<string, array{level: string}>}>
     */
    public function rows(): array
    {
        $levels = $this->cellLevels();
        $slugs = $this->roleSlugs();

        return Feature::query()
            ->orderBy('sort')
            ->get()
            ->map(static function (Feature $feature) use ($levels, $slugs): array {
                $cells = [];

                foreach ($slugs as $slug) {
                    $cells[$slug] = ['level' => $levels[$feature->key.'|'.$slug] ?? PermissionLevel::None->value];
                }

                return [
                    'key' => $feature->key,
                    'label' => $feature->label,
                    'group' => $feature->group,
                    'cells' => $cells,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * FSD-205 — the diff of a page of pending edits, before any of them is saved.
     *
     * Each entry names the feature and the role in the words an administrator
     * reads, both levels, and how many live accounts the move reaches. An edit
     * that would leave the cell where it is drops out: a preview padded with
     * non-changes is a preview nobody trusts.
     *
     * @param  list<array{feature: string, role: string, to: PermissionLevel}>  $edits
     * @return list<array{feature: string, feature_label: string, role: string, role_label: string, from: string, to: string, users_affected: int}>
     */
    public function preview(array $edits): array
    {
        if ($edits === []) {
            return [];
        }

        $levels = $this->cellLevels();
        $featureLabels = Feature::query()->pluck('label', 'key');
        $roleLabels = Role::query()->pluck('label', 'name');

        $diff = [];

        // One count query per role named in the page of edits, rather than one
        // per cell: a grid page can hold six edits for the same role, and the
        // answer is the same number each time.
        $affected = [];

        foreach (array_unique(array_column($edits, 'role')) as $slug) {
            $affected[$slug] = $this->activeHolders($slug);
        }

        foreach ($edits as $edit) {
            $token = $edit['feature'].'|'.$edit['role'];
            $from = PermissionLevel::from($levels[$token] ?? PermissionLevel::None->value);

            if ($from === $edit['to']) {
                continue;
            }

            $diff[] = [
                'feature' => $edit['feature'],
                'feature_label' => (string) ($featureLabels[$edit['feature']] ?? $edit['feature']),
                'role' => $edit['role'],
                'role_label' => (string) ($roleLabels[$edit['role']] ?? $edit['role']),
                'from' => $from->value,
                'to' => $edit['to']->value,
                'users_affected' => $affected[$edit['role']],
            ];
        }

        return $diff;
    }

    /**
     * The version a save will be checked against, shared by the screen and its
     * writes so that the two cannot disagree about what they are looking at.
     */
    public function version(): int
    {
        return MatrixVersion::current();
    }

    /**
     * The role columns, in the order the document lists them, with the label the
     * grid puts in the header cell. The system identities are marked because the
     * screen has to be able to say why `admin` and `supplier` sit apart from the
     * twelve business roles rather than pretending they are one list.
     *
     * @return list<array{slug: string, label: string, system: bool}>
     */
    public function columns(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->orderBy('id')
            ->get()
            ->map(static fn (Role $role): array => [
                'slug' => $role->name,
                'label' => (string) ($role->label ?? $role->name),
                'system' => (bool) $role->is_system,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function roleSlugs(): array
    {
        return array_column($this->columns(), 'slug');
    }

    /**
     * @return array<string, string> "feature|role" => level
     */
    private function cellLevels(): array
    {
        $levels = [];

        foreach (DB::table('feature_grants')->get(['feature_key', 'role_slug', 'level']) as $row) {
            $levels[$row->feature_key.'|'.$row->role_slug] = (string) $row->level;
        }

        return $levels;
    }

    /**
     * How many accounts the change reaches: the holders of that role who can
     * actually sign in. A deactivated account is counted nowhere because FSD-201
     * takes its rights from it at the resolver, so a matrix edit cannot move it.
     */
    private function activeHolders(string $roleSlug): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', static fn ($query): mixed => $query->where('name', $roleSlug))
            ->count();
    }
}
