<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Access\SessionInventory;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Session\Session;

/**
 * TSD-1103 with FSD-105 and FSD-101 — the two things that must be true the
 * moment a session starts: the session row knows whose it is, and the account
 * knows when it was last used.
 *
 * The session store is flushed before the row is stamped because the framework
 * would otherwise not have created the row yet; the login is the only point
 * where the owner is both known and worth writing. A "remember me" recall
 * arrives here too, since Laravel rebuilds the session from the recaller and
 * fires the same event — a subsequent ordinary request resolves from the
 * session data and does not.
 */
class RecordLoginMetadata
{
    public function __construct(
        private readonly Session $store,
        private readonly SessionInventory $sessions,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->store->save();
        $this->sessions->claim((int) $user->getKey(), $this->store->getId());

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
