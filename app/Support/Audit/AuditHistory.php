<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\Department;
use App\Models\FeatureGrant;
use App\Models\PackagingCategory;
use App\Models\PackagingItem;
use App\Models\Product;
use App\Models\Specification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * FSD §2.5 (audit strip) and FSD-207 (audit viewer) — the rows the screens read
 * out of audit_entries.
 *
 * "Every detail view ends with a compact history strip: who, when, what changed,
 * for that record only" and "the audit viewer filters by actor, action type,
 * target type, date range" are the same query with and without a WHERE on the
 * target, so they live in one class and share the row shape: a strip at the
 * bottom of an account and the viewer's page of that same account cannot afford
 * to describe one event in two ways.
 *
 * Both readings put the person as they were at the moment of the act in the row,
 * which is why the role snapshot is stored rather than joined from the live
 * account (FSD-907), and why the verb is turned into the Indonesian phrase from
 * AuditVerb rather than shown as the code the table holds (§2.2: no machine
 * values on a screen).
 *
 * Reading is done straight from audit_entries with the query builder rather than
 * through a model: the table is append-only, has no update path, and §3.3.6
 * indexes it by (target_type, target_id, created_at) precisely so that the strip
 * is an index seek instead of a scan. The viewer's newest-first, server-paged
 * reading rides the (action, created_at) and (actor_id, created_at) indexes
 * rather than asking for a fourth (TSD §12).
 *
 * The filter rule worth stating, because a later editor would otherwise
 * "improve" it: a value this reader cannot interpret narrows the result to
 * nothing rather than dropping its condition. §2.4 combines filters with AND,
 * and the alternative — ignore what you do not recognise — is a screen that shows
 * an administrator the entire trail when they asked for one kind of act and made
 * a typo. For an audit viewer that is the worse of the two failures: too few
 * rows are visibly wrong, too many are not.
 */
final class AuditHistory
{
    /**
     * §2.4's page sizes for a list whose rows expand into a before/after pair.
     * TSD §12 assumes ~50k audit rows a year and says the viewer always pages
     * server-side, so no reading of this table is ever "all of it".
     */
    public const PER_PAGE = [5, 10, 25, 50];

    public const DEFAULT_PER_PAGE = 25;

    /**
     * FSD-207's target-type filter, spelled the way a URL should spell it.
     *
     * The table stores a class name, and a query parameter holding a class name
     * is a parameter that can be pointed at a model this screen was never meant
     * to open. So the filter takes a short key and the key is resolved here,
     * against a closed list, into the class the rows actually carry.
     *
     * @var array<string, array{class: class-string, label: string}>
     */
    public const TARGETS = [
        'user' => ['class' => User::class, 'label' => 'Akun'],
        'department' => ['class' => Department::class, 'label' => 'Departemen'],
        'packaging_category' => ['class' => PackagingCategory::class, 'label' => 'Kategori kemasan'],
        'packaging_item' => ['class' => PackagingItem::class, 'label' => 'Item kemasan'],
        'product' => ['class' => Product::class, 'label' => 'Produk'],
        'specification' => ['class' => Specification::class, 'label' => 'Spesifikasi bahan pengemas'],
        'matrix_cell' => ['class' => FeatureGrant::class, 'label' => 'Sel matriks akses'],
    ];

    /**
     * The columns every reading of the trail needs, in one list so the strip and
     * the viewer cannot drift apart.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'actor_id', 'actor_role_snapshot', 'action', 'target_type', 'target_id',
        'before', 'after', 'ip', 'created_at',
    ];

    /**
     * The value a filter is aimed at when the reader cannot interpret it. No
     * target_type can hold it — they are class names, which always contain a
     * backslash — so matching it is how "narrow to nothing" is written here
     * without a whereRaw and without dropping the condition.
     */
    private const UNMATCHABLE = 'unknown';

    /**
     * The history strip: the last few things done to one record.
     *
     * @param  int  $limit  rows for one record; the strip is compact by definition
     * @return list<array<string, mixed>>
     */
    public static function forRecord(Model $target, int $limit = 20): array
    {
        $rows = DB::table('audit_entries')
            ->where('target_type', $target::class)
            ->where('target_id', $target->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get(self::COLUMNS);

        return self::rows($rows);
    }

    /**
     * SCR-2.5 — one page of the whole trail, filtered as FSD-207 names it.
     *
     * The keys are §2.4's list shape — data, total, current_page, last_page,
     * per_page — not an audit spelling of the same five facts, because the trail
     * is a list page and the shared table component pages one list. What an
     * auditor might call rows is called data here for the same reason the account
     * directory calls them data.
     *
     * @param  array<string, mixed>  $input  the query string, unvalidated
     * @return array{data: list<array<string, mixed>>, total: int, current_page: int, last_page: int, per_page: int, filters: array<string, mixed>}
     */
    public static function paginate(array $input): array
    {
        $filters = self::resolvedFilters($input);

        $query = DB::table('audit_entries');
        self::applyFilters($query, $filters);

        $total = (int) (clone $query)->count();

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($filters['page'], $filters['per_page'])
            ->get(self::COLUMNS);

        return [
            'data' => self::rows($rows),
            'total' => $total,
            'current_page' => $filters['page'],
            'last_page' => $total === 0 ? 1 : (int) ceil($total / $filters['per_page']),
            'per_page' => $filters['per_page'],
            'filters' => $filters,
        ];
    }

    /**
     * The choices the filter row offers.
     *
     * Actors are the people who appear in the trail, not the account directory:
     * "who has done anything worth recording" is the question, and sixty names of
     * which two have acted is the same list with fifty-eight dead ends. Actions
     * and targets are the whole closed vocabulary instead, because asking whether
     * a kind of act ever happened is a legitimate question that deserves an empty
     * answer rather than a missing option.
     *
     * @return array{actors: list<array{id: int, name: string}>, actions: list<array{value: string, label: string}>, targets: list<array{value: string, label: string}>}
     */
    public static function options(): array
    {
        $ids = DB::table('audit_entries')->whereNotNull('actor_id')->distinct()->pluck('actor_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $actors = [];

        if ($ids !== []) {
            foreach (User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']) as $user) {
                $actors[] = ['id' => (int) $user->getKey(), 'name' => (string) $user->name];
            }
        }

        $actions = [];

        foreach (AuditAction::cases() as $case) {
            $actions[] = ['value' => $case->value, 'label' => AuditVerb::phrase($case->value)];
        }

        $targets = [];

        foreach (self::TARGETS as $key => $definition) {
            $targets[] = ['value' => $key, 'label' => $definition['label']];
        }

        return ['actors' => $actors, 'actions' => $actions, 'targets' => $targets];
    }

    /**
     * The filters as they were actually applied, echoed back for the screen
     * (§2.4: the state belongs in the address bar, so a view can be shared).
     *
     * @param  array<string, mixed>  $input
     * @return array{actor: ?string, action: ?string, target: ?string, from: ?string, to: ?string, page: int, per_page: int}
     */
    private static function resolvedFilters(array $input): array
    {
        $per_page = (int) ($input['per_page'] ?? self::DEFAULT_PER_PAGE);

        return [
            'actor' => self::text($input['actor'] ?? null),
            'action' => self::text($input['action'] ?? null),
            'target' => self::text($input['target'] ?? null),
            'from' => self::text($input['from'] ?? null),
            'to' => self::text($input['to'] ?? null),
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array($per_page, self::PER_PAGE, true) ? $per_page : self::DEFAULT_PER_PAGE,
        ];
    }

    /**
     * @param  array{actor: ?string, action: ?string, target: ?string, from: ?string, to: ?string, page: int, per_page: int}  $filters
     */
    private static function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['actor'] !== null) {
            $query->where(
                'actor_id',
                preg_match('/^\d+$/', $filters['actor']) === 1 ? $filters['actor'] : self::UNMATCHABLE
            );
        }

        if ($filters['action'] !== null) {
            // Any string at all: an action code the trail has never written is
            // answered by the equality itself, which matches nothing.
            $query->where('action', $filters['action']);
        }

        if ($filters['target'] !== null) {
            $query->where('target_type', self::TARGETS[$filters['target']]['class'] ?? self::UNMATCHABLE);
        }

        // Both ends included. §2.4's date ranges are day ranges on the wall clock
        // the whole system keeps (§14.2), so the closing day belongs to the range
        // up to its last second — a range that quietly meant "up to midnight
        // UTC" would hide the end of a working day, which is when the changes
        // worth reading about tend to happen.
        if ($filters['from'] !== null) {
            $query->where('created_at', '>=', self::day($filters['from'], '00:00:00') ?? self::UNMATCHABLE);
        }

        if ($filters['to'] !== null) {
            $query->where('created_at', '<=', self::day($filters['to'], '23:59:59') ?? self::UNMATCHABLE);
        }
    }

    private static function text(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }

    /**
     * One edge of a day range, or null when the parameter is not a day the
     * calendar has. The caller turns that into a filter matching nothing rather
     * than into a range nobody asked for.
     */
    private static function day(string $date, string $time): ?string
    {
        try {
            return CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $date.' '.$time,
                (string) config('app.timezone')
            )->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The word the screen shows for a record of this kind, read out of TARGETS so
     * that the filter list and the row label are one fact rather than two lists
     * that can be maintained into disagreeing.
     *
     * A target type the vocabulary does not name answers null, and the screen
     * shows the row without a type. That is deliberate: the alternative is
     * printing the class name, which §2.2 forbids, or inventing an Indonesian
     * word for a record nobody specified — and a phase that adds audited types
     * will add the word here, where the test can ask for it.
     */
    private static function targetLabel(string $class): ?string
    {
        foreach (self::TARGETS as $definition) {
            if ($definition['class'] === $class) {
                return $definition['label'];
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(Collection $rows): array
    {
        $names = self::actorNames(
            $rows->pluck('actor_id')->filter()->unique()->values()->map(
                static fn (mixed $id): int => (int) $id
            )->all()
        );

        // One query for the whole page, the same way the actor names are read:
        // the snapshot decides which roles a row mentions, and the role list only
        // says what each of them is called.
        $roles = self::roleLabels(
            $rows->flatMap(
                static fn (object $row): array => self::decode($row->actor_role_snapshot)
            )->unique()->values()->all()
        );

        return $rows
            ->map(static function (object $row) use ($names, $roles): array {
                $before = self::decodeMap($row->before);
                $after = self::decodeMap($row->after);

                return [
                    'id' => (int) $row->id,
                    'actor' => $row->actor_id === null ? null : ($names[(int) $row->actor_id] ?? null),
                    'actor_roles' => self::spellRoles($row->actor_role_snapshot, $roles),
                    'action' => (string) $row->action,
                    'verb' => AuditVerb::phrase((string) $row->action),
                    'target' => self::targetLabel((string) $row->target_type),
                    'target_id' => $row->target_id === null ? null : (int) $row->target_id,
                    'at' => self::iso((string) $row->created_at),
                    'before' => $before,
                    'after' => $after,
                    'changes' => self::changes($before, $after),
                    'ip' => $row->ip === null ? null : (string) $row->ip,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The expansion FSD-207 asks for: the fields that moved, in the order the
     * writer listed them, with the fields only one side has included on the
     * strength of that side alone.
     *
     * The raw before/after maps travel with it because they are what an export
     * compares against the table, and a screen that had to rebuild them from the
     * sentences could not be checked against the row it came from.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return list<array{field: string, from: ?string, to: ?string}>
     */
    private static function changes(?array $before, ?array $after): array
    {
        $keys = array_values(array_unique(array_merge(
            array_keys($before ?? []),
            array_keys($after ?? [])
        )));

        return array_map(
            static fn (string $key): array => [
                'field' => AuditField::label($key),
                'from' => AuditField::text($before[$key] ?? null),
                'to' => AuditField::text($after[$key] ?? null),
            ],
            $keys
        );
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private static function actorNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();
    }

    /**
     * The words the role list uses for the roles named by this page's snapshots.
     *
     * Which roles an actor held is evidence and lives in the snapshot; what a
     * role is called is presentation and lives in the role list. §2.2 keeps the
     * role key off a screen, so the reader spells each member of the stored set
     * the way the list spells it — and leaves a slug it cannot spell as it is,
     * because a chip that still reads like a database is a naming gap somebody
     * can see, where an invented label would be a lie about a decision.
     *
     * @param  list<string>  $slugs
     * @return array<string, string>
     */
    private static function roleLabels(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return Role::query()
            ->whereIn('name', $slugs)
            ->pluck('label', 'name')
            ->map(static fn (mixed $label): string => (string) $label)
            ->all();
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<string>
     */
    private static function spellRoles(?string $snapshot, array $labels): array
    {
        return array_values(array_map(
            static fn (string $slug): string => $labels[$slug] ?? $slug,
            self::decode($snapshot)
        ));
    }

    /**
     * @return list<string>
     */
    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_map(static fn (mixed $v): string => (string) $v, $decoded)) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeMap(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function iso(string $when): string
    {
        // Parsed in the application timezone because that is the clock Eloquent
        // stamped the row with (§14.2 keeps the whole system on Asia/Jakarta),
        // and returned as ISO-8601 so the shared formatter on the client renders
        // it the way every other date in the system is rendered (TSD §2.3,
        // stage 8: raw values go out, one formatter displays them).
        return CarbonImmutable::parse($when, (string) config('app.timezone'))->toIso8601String();
    }
}
