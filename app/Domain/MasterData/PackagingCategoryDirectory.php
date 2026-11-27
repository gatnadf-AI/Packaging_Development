<?php

declare(strict_types=1);

namespace App\Domain\MasterData;

use App\Models\PackagingCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * SCR-4.1 — the one read path for the category list, on §2.4's list contract.
 *
 * The screen is small, so the interesting thing about it is its count column.
 * FR-603's complaint against the reviewed prototype was a table that read 0
 * while the data held rows, and PackagingCategoryService::itemCountColumn() is
 * the single expression that answers it: the list asks it per row and the delete
 * refusal asks it per button, so the number an operator reads and the number
 * that stops them cannot come from different places (TSD §3.5).
 *
 * Retired categories stay in this list by default. FSD-401's rows are the
 * vocabulary the items are filed under, and an administrator looking for the
 * old spelling of a category on a printed history has to be able to find it;
 * `status` is the filter that narrows the list when somebody wants only what is
 * currently offered.
 */
final class PackagingCategoryDirectory
{
    public const SEARCH_FLOOR = 3;

    /**
     * @var list<string>
     */
    public const SEARCHABLE = ['name'];

    /**
     * @var list<string>
     */
    public const SORTABLE = ['name'];

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

        $query = PackagingCategory::query()
            ->addSelect(['item_count' => PackagingCategoryService::itemCountColumn()]);

        $this->applySearch($query, $filters['q']);
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'], $filters['direction']);

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->withQueryString();

        return $page->through(static fn (PackagingCategory $category): array => self::row($category));
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
    public static function row(PackagingCategory $category): array
    {
        return [
            'id' => (int) $category->getKey(),
            'name' => $category->name,
            'description' => $category->description,
            'item_count' => (int) ($category->item_count ?? 0),
            'is_active' => (bool) $category->is_active,
            'row_version' => (int) $category->row_version,
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
