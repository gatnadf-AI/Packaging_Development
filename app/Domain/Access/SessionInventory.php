<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * FSD-105 — the list a user sees of their own open sessions, and the only way
 * to close one of them.
 *
 * Scope is the whole of it: a row is offered only when its user_id matches the
 * asking account, and the row backing the current request is listed but never
 * revocable, because "log out this device" from the device you are typing on
 * would look like a bug rather than a feature.
 *
 * The window is the configured session lifetime rather than a constant, so the
 * screen and the authenticator cannot disagree about what "still open" means.
 *
 * Every mutator here audits. FSD-105 asks for "who revoked, when", and a
 * deletion that leaves no trace is the one answer an auditor cannot accept
 * later; because the write lives in this class rather than in a controller, a
 * screen that forgets to log the act is no longer a way to lose the record.
 */
final class SessionInventory
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return list<array{
     *     id: string, device: string, ip: ?string, location: ?string,
     *     last_action_at: string, is_current: bool
     * }>
     */
    public function forUser(int $userId, ?string $currentSessionId): array
    {
        $window = max(60, (int) config('session.lifetime') * 60);
        $since = CarbonImmutable::now()->subSeconds($window)->getTimestamp();

        return DB::table('sessions')
            ->where('user_id', $userId)
            ->where('last_activity', '>=', $since)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(static function (object $row) use ($currentSessionId): array {
                return [
                    'id' => (string) $row->id,
                    'device' => UserAgentSummary::describe($row->user_agent),
                    'ip' => $row->ip_address !== null && $row->ip_address !== '' ? (string) $row->ip_address : null,
                    'location' => UserAgentSummary::approximateLocation($row->ip_address),
                    'last_action_at' => CarbonImmutable::createFromTimestamp(
                        (int) $row->last_activity,
                        (string) config('app.timezone')
                    )->toIso8601String(),
                    'is_current' => $currentSessionId !== null && hash_equals((string) $currentSessionId, (string) $row->id),
                ];
            })
            ->all();
    }

    /**
     * Record which account a freshly written session row belongs to.
     *
     * The row has to be saved before it can be updated: Laravel writes sessions
     * at the end of the request, and the login that creates the row is the one
     * moment where the owner is known. The flush here is deliberate, and the
     * framework's own save later in the request rewrites payload, ip and
     * last_activity but never this column.
     */
    public function claim(int $userId, string $sessionId): void
    {
        DB::table('sessions')->where('id', $sessionId)->update(['user_id' => $userId]);
    }

    /**
     * Close one of the user's own sessions.
     *
     * FSD-105 signs off the revoke as an audited act, so the row is written here
     * rather than by the screen that asked for it: the answer to "who closed
     * this session, and from which account" has to survive whoever edits the
     * controller next.
     *
     * @return bool false when the row was not theirs, was not there, or was the
     *              session making the request. The caller turns that into a
     *              message; nothing here throws, and nothing here is audited,
     *              because a refusal that changed nothing is not a decision.
     */
    public function revoke(User $owner, string $sessionId, ?string $currentSessionId, ?Authenticatable $actor): bool
    {
        if ($currentSessionId !== null && hash_equals($currentSessionId, $sessionId)) {
            return false;
        }

        $removed = DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $owner->getKey())
            ->delete();

        if ($removed !== 1) {
            return false;
        }

        // Recorded as an event rather than a column diff: the thing an auditor
        // needs is which device row stopped existing, and there is no "before"
        // side to compare. Keeping $before null is what stops the changed-column
        // rule in AuditService from dropping the id as "unchanged".
        $this->audit->record(
            $actor,
            AuditAction::SessionRevoked,
            $owner,
            null,
            ['session_id' => $sessionId],
        );

        return true;
    }

    /**
     * FSD-202 — deactivating an account ends every session it holds. Returns
     * the number of rows removed so the confirmation screen can state it.
     */
    public function terminateAllFor(User $owner, ?Authenticatable $actor): int
    {
        $removed = DB::table('sessions')->where('user_id', $owner->getKey())->delete();

        if ($removed > 0) {
            $this->audit->record($actor, AuditAction::SessionTerminated, $owner, null, [
                'count' => $removed,
                'reason' => 'account_terminated',
            ]);
        }

        return $removed;
    }

    /**
     * FSD-104 — closing everything except the session that just changed its
     * password.
     *
     * An administrator-set password is known to whoever set it, so the forced
     * change is the moment to make sure that knowledge stops being enough. The
     * acting session survives because the user is still inside the flow, and the
     * count is audited because FSD-105 asks who ended a session and when.
     */
    public function revokeOthers(User $owner, string $currentSessionId, ?Authenticatable $actor): int
    {
        $removed = DB::table('sessions')
            ->where('user_id', $owner->getKey())
            ->where('id', '!=', $currentSessionId)
            ->delete();

        if ($removed > 0) {
            $this->audit->record($actor, AuditAction::SessionTerminated, $owner, null, [
                'count' => $removed,
                'reason' => 'password_changed',
            ]);
        }

        return $removed;
    }
}
