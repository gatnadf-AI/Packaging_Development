<?php

declare(strict_types=1);

namespace App\Domain\MasterData;

use App\Domain\Access\ChangeRefused;
use App\Models\PackagingCategory;
use App\Models\PackagingItem;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditService;
use App\Support\Persistence\RowVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FSD-401 — the packaging category list, and the one place its delete is decided.
 *
 * Two verbs on this screen look alike and are not alike, which is the whole
 * reason a reference list of a dozen rows has a domain service in front of it.
 * A rename is an edit of a row people have already filed items under, so the
 * trail has to keep the old spelling (the reports were written against it). A
 * delete is an act that is only available while nothing points here, and the
 * schema says so independently of this class: `category_id` is a RESTRICT key,
 * so a screen that forgot the pre-check still cannot remove the row. What the
 * pre-check adds is the sentence — MSG-070 with the number in it — rather than a
 * database error arriving as a 500.
 *
 * G-13 is the general rule this is one instance of ("otherwise deactivate"), and
 * the flag is the mechanism: FSD-401's list keeps retired rows because an item
 * filed under an old category has to be able to say which one, while the picker
 * that files a new item must not offer it. Those are two different queries and
 * both live here rather than in whichever screen asks.
 */
final class PackagingCategoryService
{
    /**
     * FSD §6 has no key for packaging items: SCR-4.1 and SCR-4.2 are one area
     * with one cell, so the item screen is gated by this key too. Inventing a
     * `master.item` row would put a cell on the Phase 1.3 grid that the signed
     * document does not contain, and the grid is asserted against the FSD by test.
     */
    public const FEATURE = 'master.category';

    private const EDITABLE = ['name', 'description', 'is_active'];

    public function __construct(private readonly AuditService $audit) {}

    public function create(string $name, ?string $description, ?Authenticatable $actor): PackagingCategory
    {
        return DB::transaction(function () use ($name, $description, $actor): PackagingCategory {
            $category = PackagingCategory::query()->create([
                'name' => trim($name),
                'description' => $this->text($description),
                'is_active' => true,
                // row_version is not in the list: its initial value is the
                // schema's default and RowVersion is the only writer after that.
            ]);

            $this->audit->record(
                $actor,
                AuditAction::CategoryCreated,
                $category,
                null,
                AuditService::snapshot($category, self::EDITABLE),
            );

            return $category;
        });
    }

    /**
     * The text fields of a category, as one audited act.
     *
     * An absent `description` key means the caller did not speak about it; an
     * empty one means the description was cleared. Only the first is allowed to
     * leave the stored sentence standing, because a form that posts a subset of
     * columns is not the same statement as a form that posts an empty text box.
     *
     * @param  array{name: string, description?: ?string}  $attributes
     */
    public function edit(
        PackagingCategory $category,
        array $attributes,
        ?Authenticatable $actor,
        int $expectedVersion,
    ): PackagingCategory {
        $name = trim($attributes['name']);
        $description = array_key_exists('description', $attributes)
            ? $this->text($attributes['description'])
            : $category->description;

        if ($name === $category->name && $description === $this->text($category->description)) {
            return $category;
        }

        $before = AuditService::snapshot($category, self::EDITABLE);

        $fresh = DB::transaction(
            fn (): PackagingCategory => RowVersion::save($category, $expectedVersion, [
                'name' => $name,
                'description' => $description,
            ])
        );

        $this->audit->record(
            $actor,
            AuditAction::CategoryUpdated,
            $category,
            $before,
            AuditService::snapshot($fresh, self::EDITABLE),
        );

        return $fresh;
    }

    public function setActive(
        PackagingCategory $category,
        bool $isActive,
        ?Authenticatable $actor,
        int $expectedVersion,
    ): PackagingCategory {
        if ($category->is_active === $isActive) {
            return $category;
        }

        $before = AuditService::snapshot($category, self::EDITABLE);

        $fresh = DB::transaction(
            fn (): PackagingCategory => RowVersion::save($category, $expectedVersion, ['is_active' => $isActive])
        );

        // Retiring a category is its own action in the trail rather than another
        // "category.updated": a reader looking for what stopped being offered has
        // to be able to ask, and an edit and a withdrawal that share a word
        // cannot be told apart at the scale of a hundred saves.
        $this->audit->record(
            $actor,
            $isActive ? AuditAction::CategoryUpdated : AuditAction::CategoryDeactivated,
            $category,
            $before,
            AuditService::snapshot($fresh, self::EDITABLE),
        );

        return $fresh;
    }

    /**
     * Delete a category, or refuse with the number that stops it.
     */
    public function delete(PackagingCategory $category, ?Authenticatable $actor): void
    {
        $count = $this->itemCount($category);

        if ($count > 0) {
            throw ChangeRefused::categoryStillReferenced($count);
        }

        $before = AuditService::snapshot($category, self::EDITABLE);

        DB::transaction(function () use ($category, $before, $actor): void {
            $category->delete();

            // The row is gone and the trail still names it: FSD-907's history is
            // read after the fact, and an empty after-side is what says this one
            // was removed rather than changed.
            $this->audit->record($actor, AuditAction::CategoryDeleted, $category, $before, null);
        });
    }

    /**
     * The count as SCR-4.1's column wants it: one subselect per row, correlated
     * to the category the row is about.
     *
     * This is the single expression for the number. TSD §3.5 asks that "the
     * count is the same subquery the list column uses, so the number shown and
     * the number blocking are identical", and the honest reading of that is one
     * definition with two callers — the page asks it about every row it draws,
     * itemCount() below asks it about the row somebody pressed.
     *
     * @return Builder<PackagingItem>
     */
    public static function itemCountColumn(): Builder
    {
        return PackagingItem::query()
            ->selectRaw('count(*)')
            ->whereColumn('packaging_items.category_id', 'packaging_categories.id');
    }

    /**
     * The same question for one category, bound rather than correlated (§3.5).
     */
    public function itemCount(PackagingCategory $category): int
    {
        return PackagingItem::query()->where('category_id', $category->getKey())->count();
    }

    /**
     * The categories a new item may be filed under: live rows only, because
     * FSD-402's "inactive items stop appearing in new assignment pickers" is
     * said of items and means the same thing of the list they are picked from.
     * Historical rows keep their category either way — that is a join, not an
     * option list.
     *
     * @return list<array{id: int, name: string}>
     */
    public function assignableOptions(): array
    {
        return PackagingCategory::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (PackagingCategory $c): array => [
                'id' => (int) $c->getKey(),
                'name' => $c->name,
            ])
            ->all();
    }

    private function text(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
