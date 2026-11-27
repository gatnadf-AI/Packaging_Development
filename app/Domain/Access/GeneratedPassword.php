<?php

declare(strict_types=1);

namespace App\Domain\Access;

use Random\Randomizer;

/**
 * The password the system gives an account before its owner has one (FSD-201).
 *
 * A generated secret here has one job: to hold a row that the person has not
 * typed into anything, and to survive being read off a screen or a file by hand.
 * `Str::password()` draws each character independently, so a twenty-character
 * draw can legitimately come out without an uppercase letter or a digit — a
 * secret that would fail V-102 if its owner ever reused it as the basis of a
 * real password, which is exactly what people do with the first thing they are
 * given. This class guarantees the three classes the policy asks for instead of
 * hoping the draw produces them.
 *
 * The alphabet also drops the pairs that make a spoken or transcribed secret
 * unreliable: no I/l/1 and no O/0. That costs a little entropy (57 symbols
 * instead of 62, so about 116 bits at twenty characters) and buys the ability to
 * read one out loud to a person who is waiting for access.
 *
 * The value is returned once, hashed by the caller, and never stored: an account
 * created here is created with must_change_password, so this string stops being
 * anything as soon as the single-use link is used (FSD-104).
 */
final class GeneratedPassword
{
    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    /**
     * A password that satisfies PasswordPolicy::rules() by construction.
     */
    public static function forPolicy(int $length = 20): string
    {
        if ($length < PasswordPolicy::MIN_LENGTH) {
            $length = PasswordPolicy::MIN_LENGTH;
        }

        $pool = self::LOWER.self::UPPER.self::DIGITS;
        $randomizer = new Randomizer;

        // One from each class first: the guarantee is positional until the
        // shuffle below, which is why the shuffle is the last step and not a
        // comment.
        $characters = [
            self::pick(self::LOWER, $randomizer),
            self::pick(self::UPPER, $randomizer),
            self::pick(self::DIGITS, $randomizer),
        ];

        while (count($characters) < $length) {
            $characters[] = self::pick($pool, $randomizer);
        }

        $characters = $randomizer->shuffleArray($characters);

        return implode('', $characters);
    }

    private static function pick(string $alphabet, Randomizer $randomizer): string
    {
        return $alphabet[$randomizer->getInt(0, strlen($alphabet) - 1)];
    }
}
