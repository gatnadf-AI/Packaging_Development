<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditService;
use App\Support\Persistence\RowVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * FSD-201 and FSD-202 — the writer of the account record.
 *
 * TSD §6 lists the workflow services by name and leaves the reference-data
 * writers to be inferred, so this class extends that table in the same style:
 * one service, its own columns, and the only place they are written. The
 * controller under App\Http\Controllers\Admin does not save a User, and the
 * profile screen does not save these columns; both ask this object, which is how
 * "documents they authored remain" and "deactivation clears the sessions" stay
 * true on every screen instead of only on the ones written first.
 *
 * Three decisions in create() are worth their weight in comments.
 *
 * One — the system generates a password and nobody is told it. FSD-201 says the
 * password "is set by the system and delivered as a single-use reset link", which
 * is one sentence describing two facts: the column cannot be empty, and the
 * person must choose their own secret. A random twenty-character value satisfies
 * the first, the reset link satisfies the second, and must_change_password
 * (FSD-104) makes the generated value useless even to whoever can read the table.
 *
 * Two — the e-mail is stored normalised (EmailIdentity), so the unique index in
 * §3.3.1 means what it says and V-101's lookup cannot be widened by a capital
 * letter typed into the create form.
 *
 * Three — the notification is sent after the transaction commits. A reset link
 * mailed for a row that then rolled back is a token pointing at nothing, and the
 * person who clicks it meets the framework's "user not found" instead of a
 * password form. TSD §2.3, stage 7 says this outright.
 *
 * is_active is not in the editable field list on purpose: it is written by
 * deactivate() and reactivate(), which also close the sessions and use the audit
 * verbs that say which. An edit that could flip the flag as a side effect of a
 * form submit would make "who deactivated this person, and did they mean to"
 * unreadable in the history strip.
 */
final class UserService
{
    /**
     * The columns an administrator owns on this record (FSD-201), except the
     * flag and except the password, which no route in this system lets one
     * person type for another.
     */
    private const EDITABLE = ['name', 'email', 'department_id', 'phone'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly AccessService $access,
        private readonly SessionInventory $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $roleSlugs
     */
    public function create(array $input, array $roleSlugs, ?Authenticatable $actor): AccountCreated
    {
        // The system generates the password, the person replaces it through the
        // single-use link, and nobody types one into this form. The generator is
        // the policy's own (V-102) rather than a random draw, so a secret handed
        // over the phone cannot arrive without a digit or an uppercase letter.
        $generated = GeneratedPassword::forPolicy();

        $user = DB::transaction(function () use ($input, $roleSlugs, $actor, $generated): User {
            $user = User::create([
                'name' => trim((string) $input['name']),
                'email' => EmailIdentity::normalise((string) $input['email']),
                'password' => $generated,
                'department_id' => $input['department_id'] ?? null,
                'phone' => $input['phone'] ?? null,
                'is_active' => (bool) ($input['is_active'] ?? true),
                // FSD-104: the first login is the forced change, whatever role
                // the account is about to hold.
                'must_change_password' => true,
                'locale' => 'id',
                // No row_version here on purpose: the column is the FSD §2.5
                // version token, its initial value is the schema's default, and
                // a system column that a service can mass-assign is one that a
                // form can eventually mass-assign too. RowVersion is the only
                // writer from here on.
            ]);

            $this->access->assignRoles($user, $roleSlugs, $actor, RowVersion::initial());

            $this->audit->record(
                $actor,
                AuditAction::UserCreated,
                $user,
                null,
                AuditService::snapshot($user, self::EDITABLE)
                    // The role set is audited twice on purpose — once here, as
                    // part of "who this account is", and once by AccessService as
                    // the act of assigning it. A reader who only ever looks at the
                    // creation row still learns what the person was made for.
                    + ['roles' => $roleSlugs],
            );

            return $user;
        });

        // Outside the transaction, on purpose (stage 7). The account exists and
        // is audited whether or not the mail transport is having a good day.
        $delivered = $user->is_active && $this->sendInitialLink($user, $actor);

        return new AccountCreated($user, $delivered);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input, ?Authenticatable $actor, int $expectedVersion): User
    {
        $attributes = [];

        foreach (self::EDITABLE as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $attributes[$key] = match (true) {
                $key === 'email' => EmailIdentity::normalise((string) $input[$key]),
                $key === 'name' => trim((string) $input[$key]),
                default => $input[$key] === '' ? null : $input[$key],
            };
        }

        if ($attributes === []) {
            return $user;
        }

        $before = AuditService::snapshot($user, self::EDITABLE);

        $fresh = DB::transaction(fn (): User => RowVersion::save($user, $expectedVersion, $attributes));

        $this->audit->record(
            $actor,
            AuditAction::UserUpdated,
            $user,
            $before,
            AuditService::snapshot($fresh, self::EDITABLE),
        );

        return $fresh;
    }

    /**
     * FSD-202 — end a person's access without ending their authorship.
     *
     * Three things happen and they must happen together: the flag goes down
     * (which blocks login through EnsureUserIsActive and empties the resolvable
     * set through User::scopeResolvable), every session it holds is closed
     * (SessionInventory, which is also the FSD-105 audit of who did it), and the
     * change is written down so that whoever asks "when did this person stop
     * receiving work" gets a date and a name rather than a guess.
     *
     * What is not here yet: the reassignment of open steps to the role. There are
     * no assignments before Phase 3, so impactOfDeactivating() reports zero and
     * says which phase owns the rest, instead of implying the sentence is done.
     *
     * @return array{sessions: int, open_assignments: int} the counts the
     *         confirmation dialog listed before the act, for the closing message
     */
    public function deactivate(User $user, ?Authenticatable $actor, int $expectedVersion): array
    {
        if ($actor !== null && (int) $actor->getAuthIdentifier() === (int) $user->getKey()) {
            throw ChangeRefused::selfDeactivation();
        }

        $impact = $this->impactOfDeactivating($user);

        // The before side is the flag, not the profile. AuditService drops a pair
        // whose two sides are equal (TSD-1101: "only the changed columns"), and it
        // compares the after keys against the before array — so snapshotting
        // EDITABLE here, which has no is_active key, compared false against
        // nothing, called the flip unchanged, and stored a deactivation with
        // neither a before nor an after. An audit row that records the verb but
        // not the act is the failure mode FSD-907 is written against.
        $before = AuditService::snapshot($user, ['is_active']);

        DB::transaction(function () use ($user, $before, $actor, $expectedVersion): void {
            $fresh = RowVersion::save($user, $expectedVersion, ['is_active' => false]);

            // The same clause of FSD-202 applied to the queue that exists at this
            // phase: nothing they hold stays open.
            $this->sessions->terminateAllFor($user, $actor);

            $this->audit->record(
                $actor,
                AuditAction::UserDeactivated,
                $user,
                $before,
                AuditService::snapshot($fresh, ['is_active']),
            );
        });

        return $impact;
    }

    /**
     * FSD-202 in the other direction. A reactivated account whose first-login
     * change never happened gets a fresh link, because the token that was emailed
     * has expired and the person cannot be expected to know that they are still
     * standing at the FSD-104 gate rather than at a locked door.
     */
    public function reactivate(User $user, ?Authenticatable $actor, int $expectedVersion): User
    {
        if ($user->is_active) {
            // Nothing to undo: the flag is already up, and writing an audit row
            // for a no-op would push the real rows down the history strip.
            return $user;
        }

        // Same reason as in deactivate(): the pair has to be the flag, or the
        // reactivation is audited as an event with no content.
        $before = AuditService::snapshot($user, ['is_active']);

        $fresh = DB::transaction(function () use ($user, $actor, $before, $expectedVersion): User {
            $fresh = RowVersion::save($user, $expectedVersion, ['is_active' => true]);

            $this->audit->record(
                $actor,
                AuditAction::UserReactivated,
                $user,
                $before,
                AuditService::snapshot($fresh, ['is_active']),
            );

            return $fresh;
        });

        if ($fresh->must_change_password) {
            $this->sendInitialLink($fresh, $actor);
        }

        return $fresh;
    }

    /**
     * The single-use link of FSD-201, through the same broker SCR-1.2 uses, so
     * there is one token table, one expiry (TSD-1104: 60 minutes), one mail
     * template, and one set of words a person can follow. Two paths to a reset
     * link is how the reviewed prototype ended up with tokens that expired
     * differently.
     *
     * @return bool whether the broker accepted the address at all
     */
    public function sendInitialLink(User $user, ?Authenticatable $actor): bool
    {
        $response = Password::broker()->sendResetLink(['email' => $user->email]);

        $sent = $response === Password::RESET_LINK_SENT;

        $this->audit->record(
            $actor,
            AuditAction::UserResetLinkSent,
            $user,
            null,
            [
                'channel' => 'email',
                'delivered' => $sent,
                // The token is never passed to the audit service: SECRET_KEYS in
                // AuditService drops a key named token whatever a caller sends,
                // and this line exists so the next caller does not try anyway.
                'expires_in_minutes' => (int) config('auth.passwords.users.expire', 60),
            ],
        );

        return $sent;
    }

    /**
     * The numbers FSD-202 asks the confirmation dialog to list before the act, so
     * that the warning names what is about to happen instead of asking for trust.
     *
     * @return array{sessions: int, open_assignments: int, owned_by_phase: string}
     */
    public function impactOfDeactivating(User $user): array
    {
        return [
            'sessions' => DB::table('sessions')->where('user_id', $user->getKey())->count(),
            // Phase 3 writes the assignment tables. Zero is the honest answer for
            // this build; the key is named now so the dialog does not change when
            // the real count arrives.
            'open_assignments' => 0,
            'owned_by_phase' => '3',
        ];
    }
}
