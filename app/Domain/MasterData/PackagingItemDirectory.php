<?php

declare(strict_types=1);

namespace App\Domain\MasterData;

use App\Domain\Specification\SpecificationDirectory;
use App\Models\MaterialType;
use App\Models\PackagingItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * SCR-4.2 — the packaging item list, on §2.4's list contract.
 *
 * The row answers FSD §2.2's rule that a screen never shows a machine value:
 * `category` and `material_type` are the joined labels rather than their ids,
 * and `branch_label` is the words BranchClass owns — `A` on its own is a
 * classification nobody outside R&D can read, and the reviewed prototype's
 * tables printed exactly that.
 *
 * Search is over Kode and Nama item, the two columns the FSD marks (S). It does
 * not reach the category or material names even though they are on the screen,
 * because §2.4's contract is about the marked columns and a search that quietly
 * grew to cover every joined label is a second vocabulary the screen has not
 * promised.
 *
 * Of the two count columns FSD §5's SCR-4.2 line names, one is here and one is
 * not. `specification_count` reads the register through SpecificationDirectory
 * because that is where its rows live. `Standar` is absent, and its absence is
 * the same discipline FR-603 asks for: the standard sheet arrives in Phase 5, and
 * a zero typed in because nothing exists yet is the prototype's defect written
 * deliberately — an omitted column says "not yet", a printed 0 says "none".
 */
final class PackagingItemDirectory
{
    public const SEARCH_FLOOR = 3;

    /**
     * @var list<string>
     */
    public const SEARCHABLE = ['code', 'name'];

    /**
     * @var list<string>
     */
    public const SORTABLE = ['code', 'name'];

    /**
     * @var list<int>
     */
    public const PER_PAGE = [5, 10, 25, 50, 100];

    public const DEFAULT_PER_PAGE = 10;

    /**
     * @param  array<string, mixed>  $input  the query string, unvalidated
     */
    public function paginate(array $input): LengthAwarePaginator
    {
        $filters = $this->resolvedFilters($input);

        $query = PackagingItem::query()
            ->with(['category', 'materialType'])
            // FR-603's column, asked of the register on every read rather than
            // stored beside the item, and read through the register's own helper
            // so the number and the page its link opens stay one question.
            ->addSelect(['specification_count' => SpecificationDirectory::itemUsageCountColumn()]);

        $this->applySearch($query, $filters['q']);
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'], $filters['direction']);

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->withQueryString();

        return $page->through(static fn (PackagingItem $item): array => self::row($item));
    }

    /**
     * The pickers the create and edit panels are filled from.
     *
     * Categories come from PackagingCategoryService::assignableOptions() rather
     * than from a query here, so "a retired category is not offered to a new
     * item" is one rule with one home and the item screen cannot disagree with
     * the category screen about it.
     *
     * @return array{categories: list<array{id: int, name: string}>, material_types: list<array{id: int, name: string}>, branch_classes: list<array{value: string, label: string}>}
     */
    public function referenceOptions(): array
    {
        $materials = MaterialType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (MaterialType $type): array => [
                'id' => (int) $type->getKey(),
                'name' => $type->name,
            ])
            ->all();

        $branches = array_map(
            static fn (BranchClass $class): array => ['value' => $class->value, 'label' => $class->label()],
            BranchClass::cases()
        );

        return [
            'categories' => app(PackagingCategoryService::class)->assignableOptions(),
            'material_types' => $materials,
            'branch_classes' => $branches,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolvedFilters(array $input): array
    {
        $term = mb_strtolower(trim((string) ($input['q'] ?? '')));

        return [
            'q' => mb_strlen($term) >= self::SEARCH_FLOOR ? $term : '',
            'search_too_short' => $term !== '' && mb_strlen($term) < self::SEARCH_FLOOR,
            'category' => ctype_digit((string) ($input['category'] ?? '')) ? (int) $input['category'] : '',
            'status' => in_array((string) ($input['status'] ?? ''), ['active', 'inactive'], true)
                ? (string) $input['status']
                : '',
            'sort' => in_array((string) ($input['sort'] ?? ''), self::SORTABLE, true)
                ? (string) $input['sort']
                : 'name',
            'direction' => strtolower((string) ($input['direction'] ?? '')) === 'desc' ? 'desc' : 'asc',
            'per_page' => $this->perPage((string) ($input['per_page'] ?? '')),
            'page' => max(1, (int) ($input['page'] ?? 1)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(PackagingItem $item): array
    {
        return [
            'id' => (int) $item->getKey(),
            'code' => $item->code,
            'name' => $item->name,
            'category' => $item->category?->name,
            'category_id' => (int) $item->category_id,
            'material_type' => $item->materialType?->name,
            'material_type_id' => $item->material_type_id === null ? null : (int) $item->material_type_id,
            'branch_class' => $item->branch_class,
            'branch_label' => BranchClass::labelFor($item->branch_class),
            'specification_count' => (int) ($item->specification_count ?? 0),
            'is_active' => (bool) $item->is_active,
            'row_version' => (int) $item->row_version,
        ];
    }

    private function applySearch(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $inner) use ($like): void {
            foreach (self::SEARCHABLE as $column) {
                $inner->orWhereRaw("lower({$column}) like ?", [$like]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filters  as resolved by resolvedFilters()
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (is_int($filters['category'])) {
            $query->where('category_id', $filters['category']);
        }

        match ($filters['status']) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            default => null,
        };
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $query->orderBy($sort, $direction);

        if ($sort !== 'name') {
            $query->orderBy('id', 'asc');
        }
    }

    private function perPage(string $value): int
    {
        if ($value === '' || ! ctype_digit($value)) {
            return self::DEFAULT_PER_PAGE;
        }

        $wanted = (int) $value;

        return in_array($wanted, self::PER_PAGE, true) ? $wanted : self::DEFAULT_PER_PAGE;
    }
}
