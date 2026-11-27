<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * V-104 — the telephone number used for escalation on the overdue report.
 *
 * The FSD names the rule but does not spell out a pattern, so the rule is the
 * permissive one an office actually needs: characters outside digits, space,
 * dash and a leading + are refused because they are almost always a paste from
 * a document; between 7 and 15 digits are required because 7 is the shortest
 * landline that can be dialled here and 15 is E.164's own ceiling.
 *
 * It is deliberately not a "valid number" check. The system only has to be able
 * to reach the person, and pretending to verify carrier ranges would produce
 * rejections that are wrong more often than it produces acceptances that are
 * right.
 */
final class PhoneNumber implements ValidationRule
{
    public const MIN_DIGITS = 7;

    public const MAX_DIGITS = 15;

    /**
     * @param  Closure(string, string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $candidate = trim((string) $value);

        if ($candidate === '') {
            return;
        }

        if (! preg_match('/^\+?[0-9][0-9 \-]*$/', $candidate)) {
            $fail('Nomor telepon hanya boleh berisi angka, spasi, tanda hubung, dan awalan +.');

            return;
        }

        $digits = strlen(preg_replace('/\D/', '', $candidate));

        if ($digits < self::MIN_DIGITS || $digits > self::MAX_DIGITS) {
            $fail('Nomor telepon harus berisi '.self::MIN_DIGITS.' sampai '.self::MAX_DIGITS.' angka.');
        }
    }
}
