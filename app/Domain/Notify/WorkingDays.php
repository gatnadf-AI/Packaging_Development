<?php

declare(strict_types=1);

namespace App\Domain\Notify;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * TSD-PDS-003 §9 — working-day arithmetic over the seeded calendar.
 *
 * Every SLA, overdue flag and cycle-time figure in the system is expressed in
 * WORKING days (FSD-905), so this is the only place that is allowed to decide
 * what a day means. It reads working_day_calendar rather than counting
 * Carbon::isWeekend, because a national holiday is not a weekend and the
 * difference is exactly what an approver notices when a queue says "due today".
 *
 * Rows the calendar does not cover are treated as ordinary weekdays derived
 * from config('workflow.working_days') and reported, not silently assumed —
 * see $coversGaps.
 */
final class WorkingDays
{
    /** True when every date examined fell inside a calendar row. */
    public bool $coversGaps = true;

    /** @var array<string, bool>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $timezone,
        private readonly array $baselineWorkingIsoDays = [1, 2, 3, 4, 5],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('app.timezone'),
            array_map('intval', (array) config('workflow.working_days')),
        );
    }

    public function isWorkingDay(CarbonImmutable|string $date): bool
    {
        $day = $this->normalize($date);

        return $this->lookup($day->toDateString());
    }

    /**
     * The deadline `n` working days after `$from`, at end of that day.
     *
     * A zero-day SLA means "today, if today is a working day"; otherwise the
     * count starts on the next working day. That is the reading the paper form
     * uses and the one the escalation job depends on.
     */
    public function add(CarbonImmutable|string $from, int $n): CarbonImmutable
    {
        if ($n < 0) {
            throw new InvalidArgumentException('Working-day offsets cannot be negative.');
        }

        $day = $this->normalize($from)->startOfDay();
        $remaining = $n;

        // An offset measured from a non-working day lands on the first working
        // day, then counts forward the requested number of further days.
        if (! $this->lookup($day->toDateString())) {
            $day = $this->nextWorkingDay($day);
        }

        while ($remaining > 0) {
            $day = $this->nextWorkingDay($day->addDay());
            $remaining--;
        }

        return $day->endOfDay();
    }

    /**
     * Whole working days between two dates, not counting `$from` itself.
     */
    public function between(CarbonImmutable|string $from, CarbonImmutable|string $to): int
    {
        $a = $this->normalize($from)->startOfDay();
        $b = $this->normalize($to)->startOfDay();

        if ($b < $a) {
            return -1 * $this->between($b, $a);
        }

        $count = 0;
        $cursor = $a->addDay();

        while ($cursor <= $b) {
            if ($this->lookup($cursor->toDateString())) {
                $count++;
            }
            $cursor = $cursor->addDay();
        }

        return $count;
    }

    /**
     * Due date + elapsed check for the overdue queue: a document submitted on
     * `$submittedAt` with an `$sla` working-day allowance is late when the
     * clock has passed the end of that allowance day.
     */
    public function isOverdue(CarbonImmutable|string $submittedAt, int $sla, ?CarbonImmutable $now = null): bool
    {
        $due = $this->add($submittedAt, $sla);
        $now ??= CarbonImmutable::now($this->timezone)->startOfMinute();

        return $now->greaterThan($due);
    }

    public function nextWorkingDay(CarbonImmutable $day): CarbonImmutable
    {
        $guard = 0;

        while (! $this->lookup($day->toDateString())) {
            $day = $day->addDay();

            if (++$guard > 400) {
                // A calendar that marks more than a year of consecutive rest is
                // broken data; refusing to loop forever is the safe answer.
                throw new \RuntimeException('working_day_calendar appears to contain no working days.');
            }
        }

        return $day;
    }

    private function normalize(CarbonImmutable|string $date): CarbonImmutable
    {
        return $date instanceof CarbonImmutable
            ? $date->setTimezone($this->timezone)->startOfDay()
            : CarbonImmutable::parse($date, $this->timezone)->startOfDay();
    }

    private function lookup(string $dateString): bool
    {
        $this->load();

        if (array_key_exists($dateString, $this->cache)) {
            return $this->cache[$dateString];
        }

        // Outside the seeded span: fall back to the weekday baseline and record
        // that the answer was derived rather than read.
        $this->coversGaps = false;

        return in_array(
            (int) CarbonImmutable::parse($dateString, $this->timezone)->isoWeekday(),
            $this->baselineWorkingIsoDays,
            true
        );
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $this->cache = DB::table('working_day_calendar')
            ->pluck('is_working_day', 'date')
            ->map(static fn ($v): bool => (bool) $v)
            ->all();
    }

    /**
     * Diagnostic for the admin health panel (TSD §4.7): the widest and narrowest
     * dates actually present in the calendar.
     *
     * @return array{first: ?string, last: ?string, rows: int}
     */
    public function coverage(): array
    {
        $row = DB::table('working_day_calendar')
            ->selectRaw('min(date) as first_date, max(date) as last_date, count(*) as row_count')
            ->first();

        return [
            'first' => $row !== null ? (string) $row->first_date : null,
            'last' => $row !== null ? (string) $row->last_date : null,
            'rows' => $row !== null ? (int) $row->row_count : 0,
        ];
    }
}
