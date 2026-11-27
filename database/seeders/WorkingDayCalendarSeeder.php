<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Package 0.7 (TSD-PDS-003 §3.3.9, §9) — the working-day calendar.
 *
 * Seeds Mon-Fri as working and Sat-Sun as rest for the configured year span,
 * then overrides the statutory holidays. The seeder is idempotent and is meant
 * to be re-run when the next year's decree is published.
 *
 * Rows an administrator has edited by hand (remark set) are left alone, so a
 * re-seed cannot overwrite a local collective agreement.
 */
class WorkingDayCalendarSeeder extends Seeder
{
    private const DEFAULT_FROM_YEAR = 2026;

    private const DEFAULT_TO_YEAR = 2028;

    /** Indonesia's annual decree typically names at least this many days. */
    private const EXPECTED_MINIMUM_HOLIDAYS = 16;

    public function run(): void
    {
        $holidays = require __DIR__.'/data/holidays.php';
        $overrides = $holidays['fixed'] + $holidays['skb'];

        $from = (int) (getenv('CALENDAR_FROM_YEAR') ?: self::DEFAULT_FROM_YEAR);
        $to = (int) (getenv('CALENDAR_TO_YEAR') ?: self::DEFAULT_TO_YEAR);

        $inserted = 0;
        $updated = 0;

        DB::transaction(function () use ($from, $to, $overrides, &$inserted, &$updated) {
            $day = CarbonImmutable::parse("{$from}-01-01", config('app.timezone'));
            $last = CarbonImmutable::parse("{$to}-12-31", config('app.timezone'));

            // Existing hand-edited rows are protected: load them once rather
            // than querying per day.
            $protected = DB::table('working_day_calendar')
                ->whereNotNull('remark')
                ->pluck('is_working_day', 'date')
                ->all();

            while ($day <= $last) {
                $key = $day->toDateString();
                $isWeekend = in_array($day->isoWeekday(), [6, 7], true);
                $holidayLabel = $overrides[$key] ?? null;

                if (array_key_exists($key, $protected)) {
                    $day = $day->addDay();

                    continue;
                }

                $values = [
                    'is_working_day' => $isWeekend || $holidayLabel !== null ? 0 : 1,
                    'label' => $holidayLabel,
                    'updated_at' => now(),
                ];

                $exists = DB::table('working_day_calendar')->where('date', $key)->exists();

                if ($exists) {
                    DB::table('working_day_calendar')->where('date', $key)->update($values);
                    $updated++;
                } else {
                    DB::table('working_day_calendar')->insert($values + [
                        'date' => $key,
                        'created_at' => now(),
                    ]);
                    $inserted++;
                }

                $day = $day->addDay();
            }
        });

        $this->command?->info(sprintf(
            'Working-day calendar %d-%d: %d rows written, %d refreshed.',
            $from,
            $to,
            $inserted,
            $updated
        ));

        $incomplete = $this->incompleteYears($from, $to);

        if ($incomplete !== [] && $this->command !== null) {
            foreach ($incomplete as $year => $count) {
                $this->command->warn(
                    "{$year}: only {$count} dated holidays in the calendar. The SKB 3 Menteri list normally ".
                    "yields 16 or more — the Islamic and Vesak dates are still missing, so SLA maths for that ".
                    "year is optimistic."
                );
            }
        }
    }

    /**
     * A year is only trustworthy once the decree has been loaded into
     * database/seeders/data/holidays.php. Counting dated rows is the honest
     * signal: statutory fixed dates alone are never the full list.
     *
     * @return array<int, int> year => dated holiday count, for thin years only
     */
    private function incompleteYears(int $from, int $to): array
    {
        $counts = DB::table('working_day_calendar')
            ->whereNotNull('label')
            ->selectRaw('year(date) as y, count(*) as c')
            ->groupBy('y')
            ->pluck('c', 'y')
            ->all();

        $thin = [];

        for ($year = $from; $year <= $to; $year++) {
            $count = (int) ($counts[$year] ?? 0);

            if ($count < self::EXPECTED_MINIMUM_HOLIDAYS) {
                $thin[$year] = $count;
            }
        }

        return $thin;
    }
}
