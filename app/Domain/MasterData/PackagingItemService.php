<?php

declare(strict_types=1);

namespace App\Domain\MasterData;

use App\Models\PackagingItem;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditService;
use App\Support\Persistence\RowVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * FSD-402 and FSD-403 — the packaging item list.
 *
 * There is no delete method here, and that absence is the requirement: FSD-402
 * says an item is deactivated rather than removed because every specification,
 * assignment and printout that named it still has to read correctly. The schema
 * agrees — there is no soft-delete column, because a flag a picker must remember
 * is the honest shape of "keep the row, stop offering it" — and
 * PackagingItemScreenTest asserts the route table has no verb that would reach a
 * delete either, so the requirement is stated in three places that cannot drift.
 *
 * What an edit can do is move an item between categories. That is audited
 * against the category it left rather than the one it arrives at, because the
 * question a reader of the trail asks about a pack is "which list was it filed
 * under when this was decided".
 */
final class PackagingItemService
{
    private const EDITABLE = ['code', 'name', 'category_id', 'material_type_id', 'branch_class', 'is_active'];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{code: string, name: string, category_id: int|string, branch_class: string, material_type_id?: int|string|null}  $input
     */
    public function create(array $input, ?Authenticatable $actor): PackagingItem
    {
        return DB::transaction(function () use ($input, $actor): PackagingItem {
            $item = PackagingItem::query()->create([
                'code' => trim($input['code']),
                'name' => trim($input['name']),
                'category_id' => (int) $input['category_id'],
                'material_type_id' => ($input['material_type_id'] ?? null) === null
                    ? null
                    : (int) $input['material_type_id'],
                'branch_class' => $input['branch_class'],
                'is_active' => true,
            ]);

            $this->audit->record(
                $actor,
                AuditAction::ItemCreated,
                $item,
                null,
                AuditService::snapshot($item, self::EDITABLE),
            );

            return $item;
        });
    }

    /**
     * The fields of an item, as one audited act.
     *
     * `material_type_id` is only rewritten when the caller spoke about it: a pack
     * whose material is not yet known is a pack with a null, and a form that
     * posts a subset of its columns must not quietly empty the one it left out.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        PackagingItem $item,
        array $attributes,
        ?Authenticatable $actor,
        int $expectedVersion,
    ): PackagingItem {
        $next = $this->normalise($attributes, $item);

        if ($this->unchanged($item, $next)) {
            return $item;
        }

        $before = AuditService::snapshot($item, self::EDITABLE);

        $fresh = DB::transaction(
            fn (): PackagingItem => RowVersion::save($item, $expectedVersion, $next)
        );

        $this->audit->record(
            $actor,
            AuditAction::ItemUpdated,
            $item,
            $before,
            AuditService::snapshot($fresh, self::EDITABLE),
        );

        return $fresh;
    }

    public function setActive(
        PackagingItem $item,
        bool $isActive,
        ?Authenticatable $actor,
        int $expectedVersion,
    ): PackagingItem {
        if ($item->is_active === $isActive) {
            return $item;
        }

        $before = AuditService::snapshot($item, self::EDITABLE);

        $fresh = DB::transaction(
            fn (): PackagingItem => RowVersion::save($item, $expectedVersion, ['is_active' => $isActive])
        );

        $this->audit->record(
            $actor,
            $isActive ? AuditAction::ItemUpdated : AuditAction::ItemDeactivated,
            $item,
            $before,
            AuditService::snapshot($fresh, self::EDITABLE),
        );

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{code: string, name: string, category_id: int, material_type_id: int|null, branch_class: string}
     */
    private function normalise(array $attributes, PackagingItem $item): array
    {
        return [
            'code' => trim((string) $attributes['code']),
            'name' => trim((string) $attributes['name']),
            'category_id' => (int) $attributes['category_id'],
            'material_type_id' => array_key_exists('material_type_id', $attributes)
                ? ($attributes['material_type_id'] === null ? null : (int) $attributes['material_type_id'])
                : ($item->material_type_id === null ? null : (int) $item->material_type_id),
            'branch_class' => (string) $attributes['branch_class'],
        ];
    }

    /**
     * @param  array<string, mixed>  $next
     */
    private function unchanged(PackagingItem $item, array $next): bool
    {
        foreach ($next as $column => $value) {
            $stored = $item->{$column};

            if ($stored === $value || (string) $stored === (string) $value) {
                continue;
            }

            // Both sides of an absent material are the same fact said as null,
            // and "" from a form is that same fact a third way.
            if (($stored === null || $stored === '') && ($value === null || $value === '')) {
                continue;
            }

            return false;
        }

        return true;
    }
}
