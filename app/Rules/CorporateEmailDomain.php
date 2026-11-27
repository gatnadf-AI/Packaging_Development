<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Access\EmailIdentity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * V-101 (FSD-101, FSD-201) — the address has to sit on a corporate domain.
 *
 * The sentence is the one the FSD gives for the failure, and it is written as a
 * statement about the account rather than about the domain list: a person who
 * mistyped their own employer's domain learns that from the words, while someone
 * probing for a valid address learns nothing they can use, because the answer is
 * the same for every address outside the list.
 *
 * The same rule is used by the login screen and by the administrator who creates
 * the account, which is the point of putting it here: an address that can log in
 * must be an address that can be issued, and neither screen may decide that on
 * its own.
 */
final class CorporateEmailDomain implements ValidationRule
{
    /**
     * @param  Closure(string, string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((string) $value === '') {
            return; // presence and format belong to the other rules on the field
        }

        if (! EmailIdentity::isCorporate((string) $value)) {
            $fail('Akun Anda tidak terdaftar di domain ini.');
        }
    }
}
