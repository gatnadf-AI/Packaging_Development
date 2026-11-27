<?php

declare(strict_types=1);

namespace App\Domain\Access;

use Illuminate\Support\Str;

/**
 * FSD-105 — "device" as the sessions screen can honestly say.
 *
 * There is no device fingerprint in this system and no third-party service is
 * called for it. What is available is the User-Agent string the browser sent,
 * so this reads the browser and the operating system out of it and says nothing
 * more than that. An unrecognised string is reported as unknown instead of being
 * guessed at, because a security screen that invents a device name teaches the
 * user to distrust it.
 */
final class UserAgentSummary
{
    private const BROWSERS = [
        'Edge' => '/Edge\/([\d.]+)/',
        'Opera' => '/OPR\/([\d.]+)/',
        'Chrome' => '/Chrome\/([\d.]+)/',
        'Firefox' => '/Firefox\/([\d.]+)/',
        'Safari' => '/Version\/([\d.]+).*Safari/',
    ];

    private const SYSTEMS = [
        'Windows' => '/Windows NT ([\d.]+)/',
        'macOS' => '/Mac OS X ([\d_]+)/',
        'Android' => '/Android ([\d.]+)/',
        'iOS' => '/iPhone OS ([\d_]+)/',
        'Linux' => '/Linux/',
    ];

    public static function describe(?string $userAgent): string
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return 'Tidak diketahui';
        }

        $browser = null;

        foreach (self::BROWSERS as $name => $pattern) {
            if (preg_match($pattern, $userAgent, $m) === 1) {
                $browser = $name.' '.Str::before($m[1], '.');

                break;
            }
        }

        $system = null;

        foreach (self::SYSTEMS as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                $system = $name;

                break;
            }
        }

        if ($browser === null && $system === null) {
            return 'Tidak diketahui';
        }

        return trim(implode(' · ', array_filter([$browser, $system])));
    }

    /**
     * FSD-105 also asks for an approximate location. A private address is
     * certain, and anything else is unknown rather than inferred: there is no
     * geolocation database on this host and none is called at request time.
     */
    public static function approximateLocation(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        if ($ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'Jaringan lokal';
        }

        return null;
    }
}
