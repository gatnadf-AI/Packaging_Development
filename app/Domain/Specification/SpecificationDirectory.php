<?php

declare(strict_types=1);

namespace App\Domain\Specification;

use App\Models\AnalysisMethod;
use App\Models\MaterialType;
use App\Models\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\ParameterGroup;
use App\Models\Product;
use App\Models\Specification;
use App\Models\Unit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * SCR-4.3 — the specification list and the pickers its document screen is filled
 * from, on §2.4's list contract.
 *
 * The column set is the one FSD §9's "Specification register" row names — Number,
 * item, version, status, effective date, approvers — because that is the only
 * place the signed documents describe a table of specifications at all, and §5's
 * SCR-4.3 line names the URL and the document layout rather than a column list.
 * The last of those six is absent on purpose: nothing in this schema can say who
 * approved a sheet until the approval engine arrives in Phase 3
 * (SpecificationDraftTest reads that off information_schema), and printing the
 * author under the heading "approvers" would be the machine-value substitution
 * §2.2 refuses, in sentence form. §9's own rule that "version 1 and later both
 * listed, current flagged" waits with the flag, since a current marker is a
 * statement about the approval chain.
 *
 * Search is over the number and the item name: the same identity pair every other
 * list here searches, and the two columns §9 marks as the register's own. Jenis
 * bahan is printed on the sheet but is not searched — widening a search to a label
 * because it happens to be on the row is how a result count stops being
 * predictable.
 *
 * Default sort is the printed number ascending, because a register is read by
 * people looking for a code they already have on paper. Both this and the search
 * pair are recorded in the README's gap list: if the reviewer signs an SCR-4.3
 * column table that says otherwise, this file is the only place that changes.
 */
final class SpecificationDirectory
{
    public const SEARCH_FLOOR = 3;

    /**
     * @var list<string>
     */
    public const SEARCHABLE = ['number', 'item_name'];

    /**
     * @var list<string>
     */
    public const SORTABLE = ['number', 'item_name', 'version', 'doc_status', 'effective_date'];

    /**
     * @var list<int>
     */
    public const PER_PAGE = [5, 10, 25, 50, 100];

    public const DEFAULT_PER_PAGE = 10;

    public const DEFAULT_SORT = 'number';

    /**
     * Row key => the column that answers for it.
     *
     * The two lists a screen is given are row keys, and the query speaks SQL: a
     * specification's item name lives on a joined table, so the pair is written
     * out once here rather than guessed at by whoever builds the where clause.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'number' => 'specifications.number',
        'item_name' => 'item.name',
        'version' => 'specifications.version',
        'doc_status' => 'specifications.doc_status',
        'effective_date' => 'specifications.effective_date',
    ];

    /**
     * @param  array<string, mixed>  $input  the query string, unvalidated
     */
    public function paginate(array $input): LengthAwarePaginator
    {
        $filters = $this->resolvedFilters($input);

        $query = $this->baseQuery();

        $this->applySearch($query, $filters['q']);
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'], $filters['direction']);

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->withQueryString();

        return $page->through(static fn (Specification $specification): array => self::row($specification));
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
            // SCR-4.2's "Spesifikasi (link count)" column links here with this
            // set, so the two are one question: the number beside the item is
            // produced by itemUsageCountColumn() and the rows behind the link by
            // the where clause below, and both read this filter list. It is the
            // item's id rather than its name because two items can carry the
            // same words and a count that matched both would be FR-603 again.
            'item' => ctype_digit((string) ($input['item'] ?? '')) ? (int) $input['item'] : '',
            // doc_status is the author's column and speaks SpecificationStatus's
            // five words. The approval engine's vocabulary is not offered: a filter
            // on it would answer "no rows" for a word the column cannot hold, and
            // §2.4's list contract reads that as an empty register.
            'status' => in_array((string) ($input['status'] ?? ''), SpecificationStatus::values(), true)
                ? (string) $input['status']
                : '',
            'date_from' => self::date($input['date_from'] ?? null),
            'date_to' => self::date($input['date_to'] ?? null),
            'sort' => in_array((string) ($input['sort'] ?? ''), self::SORTABLE, true)
                ? (string) $input['sort']
                : self::DEFAULT_SORT,
            'direction' => strtolower((string) ($input['direction'] ?? '')) === 'desc' ? 'desc' : 'asc',
            'per_page' => $this->perPage((string) ($input['per_page'] ?? '')),
            'page' => max(1, (int) ($input['page'] ?? 1)),
        ];
    }

    /**
     * The pickers the document form is filled from.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function referenceOptions(): array
    {
        return [
            'items' => PackagingItem::query()->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(static fn (PackagingItem $item): array => [
                    'id' => (int) $item->getKey(),
                    'code' => $item->code,
                    'name' => $item->name,
                ])->all(),
            // A specification names a product rather than a pack, and both lists
            // carry retired rows: FSD-402 and FSD-812 keep every historical record
            // readable, and a revision of a sheet written about a retired pack has
            // to be able to say so.
            'products' => Product::query()->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(static fn (Product $product): array => [
                    'id' => (int) $product->getKey(),
                    'code' => $product->code,
                    'name' => $product->name,
                ])->all(),
            'material_types' => MaterialType::query()->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (MaterialType $type): array => [
                    'id' => (int) $type->getKey(),
                    'name' => $type->name,
                ])->all(),
            'units' => Unit::query()->orderBy('symbol')
                ->get(['id', 'symbol', 'name'])
                ->map(static fn (Unit $unit): array => [
                    'id' => (int) $unit->getKey(),
                    'symbol' => $unit->symbol,
                    'name' => $unit->name,
                ])->all(),
            'methods' => AnalysisMethod::query()->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (AnalysisMethod $method): array => [
                    'id' => (int) $method->getKey(),
                    'name' => $method->name,
                ])->all(),
            // §5's parameter block prints its groups "in the observed order",
            // which is the sort column the reference list carries.
            'parameter_groups' => ParameterGroup::query()->orderBy('sort')->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (ParameterGroup $group): array => [
                    'id' => (int) $group->getKey(),
                    'name' => $group->name,
                ])->all(),
            'categories' => PackagingCategory::query()->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (PackagingCategory $category): array => [
                    'id' => (int) $category->getKey(),
                    'name' => $category->name,
                ])->all(),
            'statuses' => array_map(
                static fn (SpecificationStatus $case): array => ['value' => $case->value, 'label' => $case->label()],
                SpecificationStatus::cases()
            ),
        ];
    }

    /**
     * The correlated subquery behind SCR-4.2's "Spesifikasi" column: how many
     * register rows name the item on that row.
     *
     * It lives here rather than in the item master because it is the register's
     * own row count — the same rows the `item` filter above selects — and a
     * count that has drifted from the page its link opens is the defect FR-603
     * was written against. Kept in one file, the two cannot disagree for long.
     *
     * Every version counts, because §9's register lists version 1 and later and
     * a smaller figure would print a number the link cannot show. No status is
     * excluded either: a withdrawn sheet still names the material, which is the
     * question the column answers.
     */
    public static function itemUsageCountColumn(): Builder
    {
        return Specification::query()
            ->selectRaw('count(*)')
            ->whereColumn('specifications.item_id', 'packaging_items.id');
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Specification $specification): array
    {
        return [
            'id' => (int) $specification->getKey(),
            'number' => $specification->number,
            'version' => (int) $specification->version,
            'status' => $specification->doc_status->value,
            'status_label' => $specification->doc_status->label(),
            'item_code' => $specification->item?->code,
            'item_name' => $specification->item?->name,
            'effective_date' => $specification->effective_date?->format('Y-m-d'),
            'row_version' => (int) $specification->row_version,
        ];
    }

    /**
     * The join the list reads its two non-local columns through, shared with the
     * document screen's props so neither asks the item for its name twice.
     */
    private function baseQuery(): Builder
    {
        return Specification::query()
            ->select('specifications.*')
            ->with('item')
            ->leftJoin('packaging_items as item', 'item.id', '=', 'specifications.item_id');
    }

    private function applySearch(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $inner) use ($like): void {
            foreach (self::SEARCHABLE as $key) {
                $inner->orWhereRaw('lower('.self::sql($key).') like ?', [$like]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filters  as resolved by resolvedFilters()
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (is_int($filters['category'])) {
            $query->where('item.category_id', $filters['category']);
        }

        if (is_int($filters['item'])) {
            $query->where('specifications.item_id', $filters['item']);
        }

        if ($filters['status'] !== '') {
            $query->where('specifications.doc_status', $filters['status']);
        }

        // §9's register filter is a single "date", and the only date a
        // specification carries is its effective one. The screen offers a window
        // because §2.4's contract gives every list a from/to pair.
        if ($filters['date_from'] !== null) {
            $query->where('specifications.effective_date', '>=', $filters['date_from']->format('Y-m-d'));
        }

        if ($filters['date_to'] !== null) {
            $query->where('specifications.effective_date', '<=', $filters['date_to']->format('Y-m-d'));
        }
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $query->orderBy(self::sql($sort), $direction);

        if ($sort !== self::DEFAULT_SORT) {
            $query->orderBy('specifications.id', 'asc');
        }
    }

    private static function sql(string $key): string
    {
        return self::COLUMNS[$key];
    }

    private static function date(mixed $value): ?Carbon
    {
        $text = trim((string) ($value ?? ''));

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $parts)) {
            return null;
        }

        // A query string is not validated by a form request: this is a filter, and
        // "2026-02-31" answers as an empty window rather than as a server error
        // over somebody's typo in a date box.
        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return Carbon::parse($text);
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
