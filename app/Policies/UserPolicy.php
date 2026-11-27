<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\MatrixResolver;
use App\Models\User;

/**
 * TSD §5.3 — what the matrix cannot say about a particular account.
 *
 * The route middleware already carries feature:admin.access, so the question
 * "is this person an administrator" is answered before a policy method runs.
 * The policy exists for the second half of §5.3, "aggregate-level rules the
 * matrix cannot express", and for the can-flags the screens hand to React (TSD
 * -704): whether *this row* may be edited by *this actor*, which no grid of
 * feature × role cells can know.
 *
 * The self-lockout — the account that is the only way back into /admin must not
 * be deactivated, nor stripped of its administrator role, from inside /admin — is
 * deliberately not answered here. It is a question about the value being posted,
 * and UserService and AccessService enforce it so the refusal arrives as a
 * sentence under a field instead of as a bare Forbidden page: the policy is a
 * convenience at the boundary, the service is the rule (TSD §2.3, stage 5). What
 * the screens do is hide the switch before anyone reaches for it, from the
 * can_deactivate flag the controller builds out of the actor's own id.
 */
class UserPolicy
{
    /** The one feature key that opens F2. FSD §6 gives it FULL to admin alone. */
    public const FEATURE = 'admin.access';

    public function __construct(private readonly MatrixResolver $matrix) {}

    public function viewAny(User $actor): bool
    {
        return $this->matrix->forUser($actor)->canView(self::FEATURE);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->matrix->forUser($actor)->canAct(self::FEATURE);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->create($actor);
    }

    public function assignRoles(User $actor, User $target): bool
    {
        // Left to the service rather than decided here: whether this particular
        // set of slugs may be combined, and whether it would strip the actor's
        // own administrator role, are questions about the value being posted,
        // and a policy that has to be given the payload to answer them is the
        // start of two places enforcing one rule.
        return $this->create($actor);
    }

    public function setActive(User $actor, User $target): bool
    {
        // Deliberately the matrix cell and nothing more. The self-lockout belongs
        // to UserService, for the reason stated in assignRoles() above: a boundary
        // that refuses first never lets the rule speak. Answering false here made
        // the form request 403, which threw away the one sentence FSD-202's
        // reader needs — "you cannot deactivate your own account from this
        // screen" — and left them with a bare Forbidden page instead of a refusal
        // they can act on. The screens still hide the switch, through the
        // can_deactivate flag, so nobody is offered an act that will be refused.
        return $this->create($actor);
    }

    public function sendResetLink(User $actor, User $target): bool
    {
        return $this->create($actor);
    }
}
