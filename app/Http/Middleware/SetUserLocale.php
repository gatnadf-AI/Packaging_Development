<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * FSD-106 (OQ-03) — the interface language is a per-user preference, and it is
 * applied before anything in the request that produces a sentence.
 *
 * The framework locale decides validation messages, the lang() calls in
 * controllers, and the dates the Blade root renders. Setting it after the
 * controller has run would give a user who chose English a page of Indonesian
 * errors, which is the failure this middleware exists to prevent. The default
 * stays Indonesian because that is what the system was specified in.
 *
 * Document output does not follow this value: SBK and FAB print with the
 * English-formatted labels of the paper originals, which is decided by document
 * type at the PDF layer.
 */
class SetUserLocale
{
    public const SUPPORTED = ['id', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = $user?->locale;

        if (is_string($locale) && in_array($locale, self::SUPPORTED, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
