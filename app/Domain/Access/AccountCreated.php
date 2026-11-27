<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\User;

/**
 * What creating an account actually produced (FSD-201).
 *
 * The requirement delivers the account as two things — the row and a single-use
 * link to the screen where the person sets their own password — and the second
 * can fail on its own. Returning only the User would force the caller to guess
 * ("created, so the mail went out") and the guess would show up as a success
 * message about a link nobody received, which is precisely the state FSD-107 has
 * to rescue. The service therefore says which of the two it managed to do.
 */
final readonly class AccountCreated
{
    public function __construct(
        public User $user,
        public bool $linkDelivered,
    ) {}
}
