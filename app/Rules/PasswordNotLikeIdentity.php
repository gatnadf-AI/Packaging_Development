<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * V-102 (FSD-104) — the password may not be the email local part.
 *
 * The rule is written as the document states it: equality, case-insensitive.
 * It deliberately does not try to be an entropy estimator; length, mixed case
 * and digits are separate rules, and the four of them together are what the
 * account screens quote to the user.
 */
final class PasswordNotLikeIdentity implements ValidationRule
{
    public function __construct(private readonly ?string $email) {}

    /**
     * @param  Closure(string, string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $local = Str::lower(Str::before((string) $this->email, '@'));
        $candidate = Str::lower(trim((string) $value));

        if ($local === '' || $candidate === '') {
            return;
        }

        if ($candidate === $local) {
            $fail('Password tidak boleh sama dengan alamat email Anda.');
        }
    }
}
