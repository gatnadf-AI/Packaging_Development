<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * V-101 — the one place an e-mail address is turned into an identity.
 *
 * The clause is two sentences long and they belong together: "lowercased and
 * trimmed before lookup" and "corporate domain enforced by Admin policy". If the
 * normalisation lives in the login form and the domain check lives in the admin
 * form, the two drift, and the drift is a person who can log in but cannot be
 * created — or the worse one, an account created on a domain that login then
 * refuses.
 *
 * The stored value is the normalised one. A unique index cannot be trusted to
 * catch "RnD.Staf1@PPM.TEST" against "rnd.staf1@ppm.test", so the write side
 * normalises rather than the read side compensating forever (TSD §3.3.1 lists
 * email as uq, which only means something if the column holds one shape).
 */
final class EmailIdentity
{
    /**
     * The address as it is stored, looked up, and compared.
     */
    public static function normalise(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /**
     * The part before the last @, which is what the password rule forbids as a
     * password (FSD-104: "not equal to email local part").
     */
    public static function localPart(?string $email): string
    {
        $email = self::normalise($email);
        $at = mb_strrpos($email, '@');

        return $at === false ? $email : mb_substr($email, 0, $at);
    }

    public static function domain(?string $email): string
    {
        $email = self::normalise($email);
        $at = mb_strrpos($email, '@');

        return $at === false ? '' : mb_substr($email, $at + 1);
    }

    /**
     * Whether the address sits on one of the configured corporate domains.
     *
     * An empty configuration means the deployment has not stated its policy, and
     * refusing every address would lock the system from the outside. That is the
     * lesser failure, so the absence is reported by pds:doctor rather than
     * silently allowed here; within this method an unset list means "not
     * enforced", and callers that must not depend on it say so.
     */
    public static function isCorporate(?string $email): bool
    {
        $domains = self::corporateDomains();

        if ($domains === []) {
            return true;
        }

        return in_array(self::domain($email), $domains, true);
    }

    /**
     * @return list<string>
     */
    public static function corporateDomains(): array
    {
        /** @var list<string> $domains */
        $domains = array_values(array_filter(
            (array) config('access.corporate_email_domains', []),
            static fn (mixed $domain): bool => is_string($domain) && $domain !== ''
        ));

        return $domains;
    }
}
