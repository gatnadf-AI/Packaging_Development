<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Models\Announcement;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * SCR-2.4 — one page of the announcement sheet (FSD-208).
 *
 * The reading half of the administration screen, kept out of the controller for
 * the same reason AuditHistory is out of its own: §2.4's list shape (data, total,
 * current_page, last_page, per_page) is one contract shared by every table in the
 * application, and the rows have to carry the words the screen draws — the state
 * the window is in and the audience spelled out — not the columns the table
 * happens to store. A browser that derived "Tayang" itself would be a browser
 * holding a second copy of the window rule, and TSD-704 says the client may hide
 * things but not decide them.
 *
 * Unlike the trail, this sheet has no filters. FSD-208 does not ask for any, and
 * an announcements list is the one table in the system where the honest answer to
 * "show me everything" is a handful of rows: notices expire, and a window that has
 * closed stops being interesting to anybody except the person auditing what was
 * said, which is the trail's job (FSD-907) rather than this sheet's.
 */
final class AnnouncementList
{
    public const PER_PAGE = [10, 20, 50];

    public const DEFAULT_PER_PAGE = 20;

    /**
     * @param  array<string, mixed>  $input  the query string, unvalidated
     * @return array{data: list<array<string, mixed>>, total: int, current_page: int, last_page: int, per_page: int, filters: array{page: int, per_page: int}}
     */
    public static function paginate(array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = in_array((int) ($input['per_page'] ?? 0), self::PER_PAGE, true)
            ? (int) $input['per_page']
            : self::DEFAULT_PER_PAGE;

        $total = Announcement::query()->count();

        $now = CarbonImmutable::now();

        // Newest window first: the notice that is up now, then the one that is
        // coming, then what was said before. The id breaks a tie between two rows
        // typed in the same second, which is the only way the page stays the same
        // across the two halves of one redirect.
        $rows = Announcement::query()
            ->with('author:id,name')
            ->orderByDesc('publish_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $labels = self::labels();

        return [
            'data' => $rows
                ->map(static fn (Announcement $announcement): array => self::row($announcement, $now, $labels))
                ->all(),
            'total' => $total,
            'current_page' => $page,
            'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
            'per_page' => $perPage,
            'filters' => ['page' => $page, 'per_page' => $perPage],
        ];
    }

    /**
     * The two lists an audience is chosen from, in the order the screen shows them.
     *
     * Values are the keys the record stores and labels are what §2.2 puts on the
     * screen, so the administrator never sees the string the database answers to.
     *
     * @return array{roles: list<array{value: string, label: string}>, departments: list<array{value: int, label: string}>}
     */
    public static function options(): array
    {
        $roles = Role::query()->orderBy('label')->get(['name', 'label']);

        $departments = Department::query()->orderBy('name')->get(['id', 'name']);

        return [
            'roles' => $roles
                ->map(static fn (Role $role): array => ['value' => $role->name, 'label' => $role->label ?? $role->name])
                ->values()
                ->all(),
            'departments' => $departments
                ->map(static fn (Department $department): array => [
                    'value' => (int) $department->getKey(),
                    'label' => (string) $department->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{roles: array<string, string>, departments: array<int, string>}
     */
    private static function labels(): array
    {
        /** @var array<string, string> $roles */
        $roles = Role::query()->pluck('label', 'name')
            ->map(static fn (?string $label, string $slug): string => $label ?? $slug)
            ->all();

        /** @var array<int, string> $departments */
        $departments = DB::table('departments')->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();

        return ['roles' => $roles, 'departments' => $departments];
    }

    /**
     * @param  array{roles: array<string, string>, departments: array<int, string>}  $labels
     * @return array<string, mixed>
     */
    private static function row(Announcement $announcement, CarbonImmutable $now, array $labels): array
    {
        $state = AnnouncementAudience::state($announcement, $now);

        return [
            'id' => $announcement->getKey(),
            'title' => (string) $announcement->title,
            'body' => (string) $announcement->body,
            'state' => $state,
            'state_label' => AnnouncementAudience::stateLabels()[$state],
            'audience_label' => AnnouncementAudience::describe(
                $announcement->audience,
                $labels['roles'],
                $labels['departments'],
            ),
            'publish_at' => $announcement->publish_at?->toIso8601String(),
            'expire_at' => $announcement->expire_at?->toIso8601String(),
            'author' => $announcement->author?->name,
        ];
    }
}
