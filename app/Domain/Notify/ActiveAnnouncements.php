<?php

declare(strict_types=1);

namespace App\Domain\Notify;

use App\Models\Announcement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as DbQueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * FSD-208's reading half — what this account should be shown right now.
 *
 * One question with three parts, and none of them left to the browser: the
 * window (is it up at this instant), the audience (was this person addressed),
 * and the dismissal (has this person already said "Mengerti"). A banner the
 * client filtered would be a banner whose rules can be changed by editing the
 * page, and the audience in particular is not cosmetic — it is the sentence that
 * says who is being told something. So the shell receives a list of words to
 * draw and the key to dismiss by, and nothing about why those words reached this
 * account.
 *
 * The window and the dismissal are settled in SQL because both are indexed
 * lookups over a table that grows forever, and the audience is settled here
 * because it lives in a JSON document: MySQL can do the same with its JSON
 * predicates, but it would mean one query per audience shape and a second
 * implementation of "who is meant" that has to agree with the first. The rows
 * left after the window filter are the notices up at this instant, which is a
 * handful, not a history.
 */
final class ActiveAnnouncements
{
    /**
     * @return list<array{id: int|string, title: string, body: string, at: string}>
     */
    public static function forUser(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $rows = Announcement::query()
            ->where('publish_at', '<=', $now)
            ->where(fn (Builder $q) => $q->whereNull('expire_at')->orWhere('expire_at', '>', $now))
            // Dismissed by this account: not deleted, just no longer shown to
            // the one person who already acknowledged it. The sub-query is a
            // plain query builder — Laravel hands that class to *Exists closures.
            ->whereNotExists(fn (DbQueryBuilder $q) => $q
                ->select(DB::raw(1))
                ->from('announcement_reads')
                ->whereColumn('announcement_reads.announcement_id', 'announcements.id')
                ->where('announcement_reads.user_id', $user->getKey()))
            ->orderByDesc('publish_at')
            ->orderByDesc('id')
            ->get(['id', 'title', 'body', 'audience', 'publish_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        $roleSlugs = $user->roles->pluck('name')->all();
        $departmentId = $user->department_id === null ? null : (int) $user->department_id;

        return $rows
            ->filter(fn (Announcement $row): bool => self::addresses($row->audience, $roleSlugs, $departmentId))
            ->map(fn (Announcement $row): array => [
                'id' => $row->getKey(),
                'title' => $row->title,
                'body' => $row->body,
                // The instant the notice said it would go up, which is the line
                // the banner prints beside the title and the only time a reader
                // has to check this against.
                'at' => $row->publish_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $audience
     * @param  list<string>  $roleSlugs
     */
    private static function addresses(?array $audience, array $roleSlugs, ?int $departmentId): bool
    {
        return match ((string) ($audience['type'] ?? '')) {
            'all' => true,
            'roles' => array_intersect($roleSlugs, array_map(strval(...), (array) ($audience['roles'] ?? []))) !== [],
            'departments' => $departmentId !== null
                && in_array($departmentId, array_map(intval(...), (array) ($audience['departments'] ?? [])), true),
            // The default is that nobody sees it, not that everybody does. The
            // migration's check makes an unreadable document a hand-edited row
            // rather than something this application can save, and the one
            // mistake worth refusing to make is an announcement to all staff
            // that was meant for the laboratory.
            default => false,
        };
    }
}
