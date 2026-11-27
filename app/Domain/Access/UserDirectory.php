<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SCR-2.1 — the one read path for the account list, written once so that FSD
 * §2.4's list contract cannot be applied inconsistently between screens.
 *
 * The reviewed prototype is the reason this class exists: its tables sorted on
 * some screens and not others, searched below three characters on one screen and
 * not on the next, and filtered with a case-sensitive LIKE that missed the rows a
 * person could see on screen. FSD §2.4 defines that behaviour for all 41 lists,
 * and a rule defined once belongs in one object that every list calls, with the
 * shared <DataTable> component owning only what a browser can own (URL sync, the
 * floor hint, the rows-per-page memory).
 *
 * Three readings of the requirement are worth naming, because each is a place a
 * later editor might "improve" the behaviour by accident:
 *
 * Search is a LIKE over the columns the screen marks S (Nama, Email), with the
 * term lowercased and trimmed to match the normalised way V-101 stores the
 * address. Below three characters no query is sent at all, which is what §2.4
 * says, and the server enforces it too rather than trusting the component.
 *
 * "Terakhir aktif" is users.last_login_at rather than the sessions table. The
 * sessions table says whether a device is open now; the column the FSD puts in a
 * directory column is when the person last signed in, and a directory that
 * reported "never" for someone sitting in the chair would be wrong in a way
 * nobody notices.
 *
 * Sorting is one column at a time (§2.4) and the direction is taken from the URL
 * rather than guessed, with the screen's default (name, ascending) restored when
 * the parameter is absent or unknown.
 */
final class UserDirectory
{
    public const SEARCH_FLOOR = 3;

    /**
     * The columns SCR-2.1 marks S — and the only columns the search runs over.
     *
     * Public because §2.4's "covers the S-marked columns" has to be one fact: the
     * list hands the component the same set the query uses, so a column can never
     * be advertised as searchable while the server ignores it.
     */
    public const SEARCHABLE = ['name', 'email'];

    public const SORTABLE = ['name', 'email'];

    public const PER_PAGE = [5, 10, 25, 50, 100];

    public const DEFAULT_PER_PAGE = 10;

    /**
     * @param  array<string, mixed>  $input  the query string, unvalidated
     */
    public function paginate(array $input): LengthAwarePaginator
    {
        // The list runs on the same resolved set the screen is handed, so the
        // chips, the page footer and the rows can never disagree about what was
        // asked for. Every whitelist lives in resolvedFilters(), once.
        $filters = $this->resolvedFilters($input);

        $query = User::query()->with(['department', 'roles']);

        $this->applySearch($query, $filters['q']);
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'], $filters['direction']);

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->withQueryString();

        return $page->through(static fn (User $user): array => self::row($user));
    }

    /**
     * The select options the filter row and the create panel need, in one round
     * trip: §2.4's "filters combine with AND" and §2.5's "read-only banner" both
     * assume the screen knows what the reference lists hold without asking per
     * field.
     *
     * @return array{departments: list<array{id:int,name:string,is_active:bool,user_count:int,row_version:int}>, roles: list<array{slug:string,label:string}>}
     */
    public function referenceOptions(): array
    {
        $counts = DB::table('users')
            ->selectRaw('department_id, count(*) as c')
            ->groupBy('department_id')
            ->pluck('c', 'department_id');

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'row_version'])
            ->map(static fn (Department $d): array => [
                'id' => (int) $d->getKey(),
                'name' => $d->name,
                'is_active' => (bool) $d->is_active,
                'user_count' => (int) ($counts[$d->getKey()] ?? 0),
                'row_version' => (int) $d->row_version,
            ])
            ->all();

        $labels = (array) config('matrix.identities', []);
        $existing = DB::table('roles')->where('guard_name', 'web')->pluck('name')->all();

        $roles = [];

        foreach ($labels as $slug => $label) {
            // A slug the matrix names but the deployment has not seeded is not
            // offered: an administrator who picks it would create an account
            // whose grant set is empty, and would be told nothing about why.
            if (! in_array((string) $slug, $existing, true)) {
                continue;
            }

            $roles[] = ['slug' => (string) $slug, 'label' => (string) $label];
        }

        return ['departments' => $departments, 'roles' => $roles];
    }

    /**
     * The filters as they were actually applied, echoed back for the screen.
     *
     * §2.4 wants the filter state in the URL so a view is shareable, which only
     * works if what the browser re-renders is what the server used. Returning the
     * raw query string would show a search term the server refused to run (below
     * the three-character floor) and a page size that was clamped, and the chips
     * would then disagree with the rows underneath them.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolvedFilters(array $input): array
    {
        $term = mb_strtolower(trim((string) ($input['q'] ?? '')));
        $sort = in_array((string) ($input['sort'] ?? ''), self::SORTABLE, true)
            ? (string) $input['sort']
            : 'name';

        return [
            'q' => mb_strlen($term) >= self::SEARCH_FLOOR ? $term : '',
            'search_too_short' => $term !== '' && mb_strlen($term) < self::SEARCH_FLOOR,
            'department' => ctype_digit((string) ($input['department'] ?? '')) ? (int) $input['department'] : '',
            'role' => in_array((string) ($input['role'] ?? ''), array_keys((array) config('matrix.identities', [])), true)
                ? (string) $input['role']
                : '',
            'status' => in_array((string) ($input['status'] ?? ''), ['active', 'inactive'], true)
                ? (string) $input['status']
                : '',
            'sort' => $sort,
            'direction' => strtolower((string) ($input['direction'] ?? '')) === 'desc' ? 'desc' : 'asc',
            'per_page' => $this->perPage((string) ($input['per_page'] ?? '')),
            'page' => max(1, (int) ($input['page'] ?? 1)),
        ];
    }

    private function applySearch(Builder $query, string $term): void
    {
        $term = mb_strtolower(trim($term));

        if (mb_strlen($term) < self::SEARCH_FLOOR) {
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
        if (is_int($filters['department'])) {
            $query->where('department_id', $filters['department']);
        }

        if (is_string($filters['role']) && $filters['role'] !== '') {
            $slug = $filters['role'];

            $query->whereHas('roles', fn (Builder $r): Builder => $r->where('roles.name', $slug));
        }

        // "Semua status" is the unfiltered case; anything else is one of the two
        // states §2.2.1 defines for an account.
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
            // Names collide in an office of any size, and a page boundary that
            // moves between two loads is a bug a user reads as lost rows.
            $query->orderBy('id', 'asc');
        }
    }

    private function perPage(string $value): int
    {
        if ($value === '' || ! ctype_digit($value)) {
            return self::DEFAULT_PER_PAGE;
        }

        $wanted = (int) $value;

        // Only the sizes §2.4 lists are honoured; a made-up per_page of 5000
        // would turn a shareable URL into a way to dump the directory.
        return in_array($wanted, self::PER_PAGE, true) ? $wanted : self::DEFAULT_PER_PAGE;
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(User $user): array
    {
        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'department' => $user->department?->name,
            'department_id' => $user->department_id === null ? null : (int) $user->department_id,
            'roles' => $user->roles
                ->map(static fn ($role): array => ['slug' => $role->name, 'label' => (string) ($role->label ?? $role->name)])
                ->values()
                ->all(),
            'is_active' => (bool) $user->is_active,
            'must_change_password' => (bool) $user->must_change_password,
            'last_active_at' => $user->last_login_at?->toIso8601String(),
            'row_version' => (int) $user->row_version,
        ];
    }
}
