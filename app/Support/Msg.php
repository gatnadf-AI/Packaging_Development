<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

/**
 * The one way this application says something in the user's language.
 *
 * FSD §2.6 numbers every sentence, and TSD §2.3 asks validation to return those
 * numbers rather than prose so that a wording change is one file, not forty. A
 * bare trans() call cannot enforce that: nothing stops the next screen from
 * writing its own Indonesian string that reads almost like MSG-031 and is not
 * the signed one. Msg::get() only answers for a catalogue id, so a sentence that
 * is not in §2.6 has to be added there first, where a reviewer will see it.
 *
 * The braces are the document's own placeholder form ({field}, {n}) rather than
 * Laravel's colon form, for the same reason: §2.6 is what was approved.
 */
final class Msg
{
    /**
     * @param  array<string, string|int>  $replace
     *
     * @throws InvalidArgumentException when the id is not in the catalogue
     */
    public static function get(string $code, array $replace = [], ?string $locale = null): string
    {
        $line = Lang::get("messages.{$code}", [], $locale);

        // Lang::get() returns the key itself when nothing matches. Reaching this
        // means somebody quoted an MSG id that §2.6 does not contain, which is a
        // defect in the caller and not something a user should ever be shown.
        if ($line === "messages.{$code}") {
            throw new InvalidArgumentException("Message [{$code}] is not in the FSD §2.6 catalogue.");
        }

        $pairs = [];

        foreach ($replace as $key => $value) {
            $pairs['{'.$key.'}'] = (string) $value;
        }

        return strtr($line, $pairs);
    }
}
