<?php

declare(strict_types=1);

namespace App\Domain\Numbering;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * TSD-PDS-003 §4.5 (TSD-430..432) — one allocator for every document number.
 *
 * The pattern is read from config/numbering.php, the sequence is a row per
 * (scope, period) locked while it is being taken, and the formatting is the
 * document's own token vocabulary rather than a per-screen sprintf. One
 * allocator is the point: BR-13 says a code is unique, never reused and never
 * encodes meaning that could later become false, and the way that fails in a
 * real system is nine screens each inventing a numbering rule.
 *
 * Two of §3.5's claims are worth naming because they constrain the shape:
 *
 * The increment is written inside the caller's transaction, which is where
 * TSD-431 puts the FOR UPDATE lock ("two simultaneous submits cannot receive
 * the same number; the loser waits, then gets the next value"). The consequence
 * is that a transaction which rolls back also rewinds its own increment — the
 * sentence "a rolled-back document still consumes its number" holds for a
 * document that was saved and then withdrawn (its row stays, so the number stays
 * out of circulation, which is what FSD-404 and BR-13 actually refuse: one
 * number on two documents). It is not held by writing the counter on a second
 * connection, whose committed writes would escape the transaction that keeps
 * pds_test clean and turn every numbering test into a schema polluter. The
 * deviation is recorded in the README next to the other ones.
 *
 * A scope whose pattern needs a value the allocator has not been given is
 * refused, not half-rendered. INS-{A}-{L} and {P}/{B}{N:2} take an assignment
 * and a level that no caller can name before Phase 3, and a code printed with
 * the braces still in it looks allocated, is unique by accident, and reaches
 * paper.
 *
 * The row is created on first use rather than seeded: which years this system
 * will be used in is not knowledge a deploy date has.
 */
final class Numbering
{
    /**
     * Take the next number for $scope in the period $at falls in.
     *
     * Call this inside the transaction that writes the document it numbers, so
     * that the lock it holds lasts exactly as long as the row it protects.
     */
    public static function allocate(string $scope, ?DateTimeInterface $at = null): string
    {
        $pattern = config('numbering.patterns.'.$scope);

        if (! is_string($pattern) || $pattern === '') {
            throw new InvalidArgumentException(
                "Nothing is configured to number the scope '{$scope}', and a pattern invented here would be meaning BR-13 forbids a code to carry."
            );
        }

        $at ??= now();
        $year = (int) Carbon::instance($at)->setTimezone(config('app.timezone'))->format('Y');

        self::assertTokensAreKnown($scope, $pattern);

        $period = self::period($scope, $year);

        // Insert-then-lock rather than lock-then-insert: the row does not exist
        // the first time a year is used, and ON DUPLICATE KEY leaves the counter
        // alone when it does, so both paths arrive at the same locked row and the
        // unique pair stays the only thing preventing two rows for one period.
        DB::statement(
            'insert into `numbering_sequences` (`scope`, `period`, `next_value`, `pattern`, `created_at`, `updated_at`)
                values (?, ?, 1, ?, ?, ?) on duplicate key update `next_value` = `next_value`',
            [$scope, $period, $pattern, $at->format('Y-m-d H:i:s'), $at->format('Y-m-d H:i:s')]
        );

        $row = DB::table('numbering_sequences')
            ->where('scope', $scope)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        $value = (int) $row->next_value;

        DB::table('numbering_sequences')
            ->where('id', $row->id)
            ->update([
                // The row holds the NEXT number, not the one just handed out: a
                // crash between the two means a number is skipped, which BR-13
                // allows in as many words ("never reused, not gapless"), while
                // the reverse order would hand one number to two documents.
                'next_value' => $value + 1,
                'updated_at' => $at->format('Y-m-d H:i:s'),
            ]);

        return self::format($pattern, $value, $year);
    }

    /**
     * Which period row a scope uses.
     *
     * config/numbering.php names the period for the scopes that count per year;
     * a scope marked 'none' numbers across all years, and one the file does not
     * mention is treated as yearly because that is what every pattern with {Y}
     * or {YY} in it needs.
     */
    private static function period(string $scope, int $year): string
    {
        return match (config('numbering.periods.'.$scope)) {
            'none' => '*',
            default => (string) $year,
        };
    }

    /**
     * Render one value into a pattern.
     *
     * @throws InvalidArgumentException when a token is not a counter, a year, or
     *                                  a padding width this allocator can fill.
     */
    private static function format(string $pattern, int $value, int $year): string
    {
        $rendered = preg_replace_callback(
            '/\{N(?::(\d+))?\}/',
            static fn (array $token): string => str_pad((string) $value, (int) ($token[1] ?? 1), '0', STR_PAD_LEFT),
            $pattern
        );

        $rendered = str_replace('{YY}', str_pad((string) ($year % 100), 2, '0', STR_PAD_LEFT), $rendered);
        $rendered = str_replace('{Y}', (string) $year, $rendered);

        return $rendered;
    }

    /**
     * Refuse a pattern this allocator cannot fill before it touches a counter.
     */
    private static function assertTokensAreKnown(string $scope, string $pattern): void
    {
        preg_match_all('/\{([A-Za-z]+)(?::\d+)?\}/', $pattern, $found);

        $unsupported = array_values(array_unique(array_filter(
            $found[1],
            static fn (string $token): bool => ! in_array($token, ['N', 'Y', 'YY'], true)
        )));

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                "The pattern for '{$scope}' is {$pattern}, which needs ".implode(', ', $unsupported)
                    .' — a value this allocator has not been given. Numbering it with the braces left in would produce a code that only looks allocated.'
            );
        }
    }
}
