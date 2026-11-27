<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Models\Announcement;
use Carbon\CarbonImmutable;

/**
 * How an audience is said out loud on a screen (FSD §2.2, FSD-208).
 *
 * The audience document is the record's own words about who is meant, and this
 * class only dresses them up: it is deliberately not the place that decides
 * whether a given account is addressed, which is ActiveAnnouncements' job. Two
 * spellings of "who is meant" would eventually disagree, so the rule lives once
 * in the domain and the sentence lives once here.
 *
 * The label maps come in from the caller because the list page needs them for
 * every row it draws: asking per row would be a query per announcement, and the
 * role and department lists are two queries whatever the page holds.
 */
final class AnnouncementAudience
{
    /**
     * @param  array<string, mixed>|null  $audience
     * @param  array<string, string>  $roleLabels  keyed by role slug
     * @param  array<int|string, string>  $departmentNames  keyed by department id
     */
    public static function describe(?array $audience, array $roleLabels, array $departmentNames): string
    {
        return match ((string) ($audience['type'] ?? '')) {
            'all' => 'Semua staff',
            'roles' => 'Peran: '.implode(
                ', ',
                array_map(
                    static fn (string $slug): string => $roleLabels[$slug] ?? $slug,
                    array_map(strval(...), (array) ($audience['roles'] ?? [])),
                ),
            ),
            'departments' => 'Departemen: '.implode(
                ', ',
                array_map(
                    static fn (int $id): string => $departmentNames[$id] ?? (string) $id,
                    array_map(intval(...), (array) ($audience['departments'] ?? [])),
                ),
            ),
            // An audience the writer no longer recognises is spelled as it was
            // stored rather than turned into "unknown": the administrator looking
            // at the row needs the fact that the document is odd, not a sentence
            // that hides which one it is.
            default => 'Sasaran tidak dikenal: '.json_encode($audience, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * The window as one word, which is the column the screen is sorted by in
     * somebody's head: is it up, is it coming, or is it over.
     *
     * There is no status column to read (see the migration), so this is derived
     * from the two instants each time — and given the instant to derive it from,
     * because a screen that asked for "now" per row would render a page whose
     * rows disagree about what time it is.
     */
    public static function state(Announcement $announcement, CarbonImmutable $now): string
    {
        if ($announcement->publish_at !== null && $announcement->publish_at->greaterThan($now)) {
            return 'scheduled';
        }

        return $announcement->isLiveAt($now) ? 'live' : 'ended';
    }

    /**
     * @return array<string, string>
     */
    public static function stateLabels(): array
    {
        return [
            'scheduled' => 'Terjadwal',
            'live' => 'Tayang',
            'ended' => 'Selesai',
        ];
    }
}
