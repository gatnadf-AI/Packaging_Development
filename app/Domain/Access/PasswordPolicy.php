<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Rules\PasswordNotLikeIdentity;
use Illuminate\Validation\Rules\Password;

/**
 * V-102 (FSD-104, FSD-201) — the one password rule set used by every writer.
 *
 * Three screens touch a password: the forced first-login change, the reset
 * flow that delivers an admin-set account, and the profile form. Each of them
 * used to be free to pick its own strength rule, which is how a system ends up
 * with a weak door next to a strong one. They all call this.
 *
 * The rules are exactly the documented four: at least ten characters, mixed
 * case, at least one digit, and not the email local part. There is no breach
 * database check because this host has no outbound network by design
 * (TSD-1003); that is a stated limitation, not an oversight.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    /**
     * @return list<mixed>
     */
    public static function rules(?string $email = null): array
    {
        return [
            'required',
            'string',
            Password::min(self::MIN_LENGTH)->mixedCase()->numbers(),
            new PasswordNotLikeIdentity($email),
        ];
    }

    /**
     * The rule set as a sentence, for the help text under the field. Keeping it
     * here means the form cannot promise something the validator does not check.
     */
    public static function describe(): string
    {
        return 'Minimal '.self::MIN_LENGTH.' karakter, menggunakan huruf besar dan kecil, '.
            'mengandung angka, dan tidak sama dengan alamat email Anda.';
    }
}
