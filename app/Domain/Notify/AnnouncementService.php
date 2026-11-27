<?php

declare(strict_types=1);

namespace App\Domain\Notify;

use App\Domain\Access\ChangeRefused;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

/**
 * FSD-208 (SCR-2.4) — the only writer of an announcement.
 *
 * An announcement is the shortest record in the system and the one with the
 * widest blast radius: an administrator types a few sentences and every account
 * in an audience reads them at the top of their screen. That asymmetry is what
 * the rules here defend. An audience naming a role that does not exist does not
 * fail loudly, it reaches nobody; a window that ends before it opens does not
 * fail either, it simply never appears. Both look like a published announcement
 * from the screen that published them, which is why the checks belong to the
 * record rather than to the form.
 *
 * There is deliberately no AuditService dependency in the constructor. FSD-907
 * lists the events the trail records — permission changes, decisions, exports,
 * account lifecycle — and an announcement is none of them, the same way a
 * profile edit is not. A later editor who thinks a notice should be audited has
 * to add the dependency and the enum case, at which point the requirement text
 * is the thing that has to say so first.
 *
 * Stop rather than delete is the other decision worth naming, and it is G-13
 * applied to a banner: once a notice has gone up, somebody has read it and their
 * dismissal is a fact about a message that existed. Deleting the announcement
 * would either orphan those rows or, if the row were re-created, show the words
 * again to the person who already acknowledged them. So a record that has begun
 * its window comes down by having its window closed, and only a record nobody
 * could yet have seen is untypeable.
 */
final class AnnouncementService
{
    /**
     * @param  array<string, mixed>  $input  title, body, audience, publish_at, expire_at
     */
    public function create(array $input, User $actor, ?CarbonImmutable $now = null): Announcement
    {
        $now ??= CarbonImmutable::now();

        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));

        if ($title === '') {
            throw ChangeRefused::fieldBlank('title', 'Judul pengumuman');
        }

        if ($body === '') {
            throw ChangeRefused::fieldBlank('body', 'Isi pengumuman');
        }

        $audience = $this->audience($input['audience'] ?? null);

        $publishAt = self::moment($input['publish_at'] ?? null) ?? $now;
        $expireAt = self::moment($input['expire_at'] ?? null);

        if ($expireAt !== null) {
            if ($expireAt->lessThanOrEqualTo($publishAt)) {
                throw ChangeRefused::windowInverted();
            }

            if ($expireAt->lessThanOrEqualTo($now)) {
                throw ChangeRefused::windowAlreadyPassed();
            }
        }

        return Announcement::query()->create([
            'title' => $title,
            'body' => $body,
            'audience' => $audience,
            'publish_at' => $publishAt,
            'expire_at' => $expireAt,
            'created_by' => $actor->getKey(),
        ]);
    }

    /**
     * Takes a live announcement down now rather than at the time it said.
     *
     * The FSD's own wording for a notice that must stop is that it "expires", and
     * the window is the only place that state lives — there is no status column
     * to flip, because a flag would need somebody to sweep it at midnight to stay
     * true while this instant is the answer to the same question.
     */
    public function stop(Announcement $announcement, ?CarbonImmutable $now = null): Announcement
    {
        $now ??= CarbonImmutable::now();

        if ($announcement->expire_at !== null && $announcement->expire_at->lessThanOrEqualTo($now)) {
            throw ChangeRefused::windowAlreadyClosed();
        }

        $announcement->forceFill(['expire_at' => $now])->save();

        return $announcement;
    }

    /**
     * Removes an announcement nobody could yet have seen.
     *
     * @see the class comment for why a record that has gone up is stopped instead.
     */
    public function delete(Announcement $announcement, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        if ($announcement->hasGoneUpBy($now)) {
            throw ChangeRefused::publishedRecordsAreNotDeleted();
        }

        $announcement->delete();
    }

    /**
     * FSD-208's "dismissible banner, remembered dismissed per user per
     * announcement": one row per pair, and the unique index is what makes the
     * second click of the same button the same fact rather than a second one.
     */
    public function dismiss(Announcement $announcement, User $user, ?CarbonImmutable $now = null): AnnouncementRead
    {
        $now ??= CarbonImmutable::now();

        /** @var AnnouncementRead $read */
        $read = AnnouncementRead::query()->firstOrCreate(
            ['announcement_id' => $announcement->getKey(), 'user_id' => $user->getKey()],
            ['dismissed_at' => $now],
        );

        return $read;
    }

    /**
     * The audience as FSD-208 draws it, checked against the lists it names.
     *
     * Stored as written and not normalised into ids, because it is a statement
     * about who is meant rather than a join: the people on a department change
     * while the notice is up, and ActiveAnnouncements resolves the same document
     * against the live membership each time it is asked.
     *
     * @return array<string, mixed>
     */
    private function audience(mixed $audience): array
    {
        if (! is_array($audience)) {
            throw ChangeRefused::audienceTypeUnknown('');
        }

        $type = (string) ($audience['type'] ?? '');

        return match ($type) {
            'all' => ['type' => 'all'],
            'roles' => ['type' => 'roles', 'roles' => $this->roleAudience($audience['roles'] ?? [])],
            'departments' => ['type' => 'departments', 'departments' => $this->departmentAudience($audience['departments'] ?? [])],
            default => throw ChangeRefused::audienceTypeUnknown($type),
        };
    }

    /**
     * @return list<string>
     */
    private function roleAudience(mixed $roles): array
    {
        $slugs = array_values(array_filter(array_map(strval(...), (array) $roles), fn (string $slug): bool => $slug !== ''));

        if ($slugs === []) {
            throw ChangeRefused::audienceListEmpty('peran');
        }

        $known = Role::query()->whereIn('name', $slugs)->pluck('name')->all();
        $unknown = array_values(array_diff($slugs, $known));

        if ($unknown !== []) {
            throw ChangeRefused::audienceRolesUnknown($unknown);
        }

        return $slugs;
    }

    /**
     * @return list<int>
     */
    private function departmentAudience(mixed $departments): array
    {
        $ids = array_values(array_unique(array_filter(array_map(intval(...), (array) $departments))));

        if ($ids === []) {
            throw ChangeRefused::audienceListEmpty('departemen');
        }

        $known = Department::query()->whereIn('id', $ids)->pluck('id')->map(intval(...))->all();
        $unknown = array_values(array_diff($ids, $known));

        if ($unknown !== []) {
            throw ChangeRefused::audienceDepartmentsUnknown($unknown);
        }

        return $ids;
    }

    /**
     * An instant as the record holds it: the browser sends a string, the tests
     * and the scheduler send a Carbon, and the columns are immutable so that a
     * window computed once cannot move under the reader's feet later in the
     * request.
     */
    private static function moment(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonImmutable
            ? $value
            : CarbonImmutable::parse($value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value);
    }
}
