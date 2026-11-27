<?php

declare(strict_types=1);

namespace App\Domain\MasterData;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * SCR-4.5 — the product list, on §2.4's list contract.
 *
 * FSD §5 names this screen's URL and nothing else: there is no column table for
 * SCR-4.5, so no (S) markers and no default sort were written down anywhere. The
 * generic contract in §2.4 still governs, and where it needs a value the screen
 * was not given one, this file picks the smallest answer and says so:
 *
 * Search is over Kode and Nama produk. Those are the two identity columns, the
 * pair every other master list in this application searches, and the four
 * descriptive columns (brand, variant, pack size, status) are printed rather
 * than searched. Widening search to a label because it happens to be on the row
 * is the thing that makes a result count unpredictable, and FSD-409 does not
 * promise it.
 *
 * Default sort is Nama produk ascending, because a product list is read by
 * people looking for a name they already know; Kode is the printed identity and
 * stays sortable but is not the default order.
 *
 * Both choices are recorded in the README's gap list for the same reason: if a
 * reviewer signs the column list for SCR-4.5 and it says otherwise, this file is
 * the only place that has to change.
 */
final class ProductDirectory
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

    public const DEFAULT_SORT = 'name';

    /**
     * @param  array<string, mixed>  $input  the query string, unvalidated
     */
    public function paginate(array $input): LengthAwarePaginator
    {
        $filters = $this->resolvedFilters($input);

        $query = Product::query();

        $this->applySearch($query, $filters['q']);
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'], $filters['direction']);

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->withQueryString();

        return $page->through(static fn (Product $product): array => self::row($product));
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
                : self::DEFAULT_SORT,
            'direction' => strtolower((string) ($input['direction'] ?? '')) === 'desc' ? 'desc' : 'asc',
            'per_page' => $this->perPage((string) ($input['per_page'] ?? '')),
            'page' => max(1, (int) ($input['page'] ?? 1)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Product $product): array
    {
        return [
            'id' => (int) $product->getKey(),
            'code' => $product->code,
            'name' => $product->name,
            'brand' => $product->brand,
            'variant' => $product->variant,
            'pack_size' => $product->pack_size,
            'is_active' => (bool) $product->is_active,
            'row_version' => (int) $product->row_version,
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

        if ($sort !== self::DEFAULT_SORT) {
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
