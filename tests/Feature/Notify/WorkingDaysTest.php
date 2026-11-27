<?php

declare(strict_types=1);

namespace Tests\Feature\Notify;

use App\Domain\Notify\WorkingDays;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkingDayCalendarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * TSD-PDS-003 §9, FSD-905 — the working-day arithmetic every SLA in the system
 * is measured on.
 *
 * The calendar is seeded from the weekday baseline plus the statutory fixed
 * dates, which is enough to prove the behaviour: a Friday submission with a
 * three-day SLA lands on Wednesday, not on Monday, and Independence Day is not
 * a working day however the calendar looks around it.
 *
 * The religious dates set by the SKB 3 Menteri are deliberately not invented
 * (see database/seeders/data/holidays.php), so one test below asserts the gap
 * rather than papering over it: if a future change adds them, that test is the
 * one that has to be updated on purpose.
 */
final class WorkingDaysTest extends TestCase
{
    use RefreshDatabase;

    private WorkingDays $days;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkingDayCalendarSeeder::class);
        $this->days = WorkingDays::fromConfig();
    }

    public function testWeekendsAreNotWorkingDaysAndWeekdaysAre(): void
    {
        $this->assertTrue($this->days->isWorkingDay('2026-12-01')); // Tuesday
        $this->assertFalse($this->days->isWorkingDay('2026-11-28')); // Saturday
        $this->assertFalse($this->days->isWorkingDay('2026-11-29')); // Sunday
    }

    public function testEverySeededHolidayIsRestAndEveryOtherWeekdayIsWorking(): void
    {
        $holidays = require __DIR__.'/../../../database/seeders/data/holidays.php';
        $overrides = $holidays['fixed'] + $holidays['skb'];

        foreach ($overrides as $date => $label) {
            $this->assertFalse(
                $this->days->isWorkingDay($date),
                "{$date} ({$label}) must be a rest day"
            );
            $this->assertSame(
                $label,
                DB::table('working_day_calendar')->where('date', $date)->value('label'),
                'the holiday name belongs on the row so a queue can say why today is quiet'
            );
        }

        // A plain weekday, three days after Pancasila Day.
        $this->assertTrue($this->days->isWorkingDay('2026-06-03'));
    }

    public function testTheThreeDayApproverSlaSkipsTheWeekend(): void
    {
        $due = $this->days->add('2026-12-01 09:00', 3); // Tue, submitted during the morning

        $this->assertSame('2026-12-04', $due->toDateString()); // Friday
        $this->assertSame('23:59:59', $due->format('H:i:s')); // the allowance ends with the day, not at 17:00
    }

    public function testASubmissionOnAFridayIsNotDueUntilTheFollowingWednesday(): void
    {
        $due = $this->days->add('2026-11-27', 3); // Friday

        $this->assertSame('2026-12-02', $due->toDateString()); // Mon and Tue counted, then Wed lands
    }

    public function testCountingFromARestDayStartsOnTheFirstWorkingDayAfterIt(): void
    {
        // 2026-12-25 is Christmas and a Friday; the clock starts Monday 28th,
        // and the three counted days are the 29th, 30th and 31st.
        $due = $this->days->add('2026-12-25', 3);

        $this->assertSame('2026-12-31', $due->toDateString());
    }

    public function testZeroWorkingDaysMeansTodayWhenTodayIsWorking(): void
    {
        $this->assertSame('2026-12-01', $this->days->add('2026-12-01', 0)->toDateString());

        // On a rest day "today" cannot be a deadline; the first working day is.
        $this->assertSame('2026-11-30', $this->days->add('2026-11-29', 0)->toDateString());
    }

    public function testBetweenCountsWholeWorkingDaysAndExcludesTheStart(): void
    {
        $this->assertSame(0, $this->days->between('2026-12-01', '2026-12-01'));
        $this->assertSame(1, $this->days->between('2026-12-01', '2026-12-02'));
        $this->assertSame(3, $this->days->between('2026-12-01', '2026-12-04'));
        $this->assertSame(1, $this->days->between('2026-12-04', '2026-12-07')); // Fri -> Mon
    }

    public function testTheCountAcrossAHolidayExcludesIt(): void
    {
        // Mon 17 Aug 2026 is Independence Day, surrounded by an ordinary weekend.
        $this->assertSame(0, $this->days->between('2026-08-14', '2026-08-17')); // Fri -> the holiday itself
        $this->assertSame(1, $this->days->between('2026-08-14', '2026-08-18')); // Fri -> the next Tuesday
    }

    public function testAnEarlierStartGivesAnEarlierDeadlineSoTheOrderingIsUsable(): void
    {
        $first = $this->days->add('2026-11-27', 3);
        $second = $this->days->add('2026-11-30', 3);

        $this->assertTrue($second->greaterThan($first), 'queue ordering depends on monotonic deadlines');
    }

    public function testNegativeOffsetsAreRefusedRatherThanAnsweredWrong(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->days->add('2026-12-01', -1);
    }

    public function testIsOverdueFlipsAtTheEndOfTheAllowanceDay(): void
    {
        // Fri 27 Nov + 3 working days = Wed 2 Dec 23:59:59.
        $submitted = '2026-11-27 09:00';

        $this->assertFalse($this->days->isOverdue($submitted, 3, CarbonImmutable::parse('2026-12-02 12:00', 'Asia/Jakarta')));
        $this->assertFalse($this->days->isOverdue($submitted, 3, CarbonImmutable::parse('2026-12-02 23:59:59', 'Asia/Jakarta')));
        $this->assertTrue($this->days->isOverdue($submitted, 3, CarbonImmutable::parse('2026-12-03 08:00', 'Asia/Jakarta')));
    }

    public function testADateOutsideTheSeededSpanIsDerivedFromTheBaselineAndReported(): void
    {
        $days = WorkingDays::fromConfig();
        $days->coversGaps = true;

        $this->assertFalse($days->isWorkingDay('2031-08-16')); // Saturday, beyond the calendar
        $this->assertFalse($days->coversGaps, 'a derived answer must be flagged, not presented as read data');

        $days = WorkingDays::fromConfig();

        $this->assertTrue($days->isWorkingDay('2031-08-18')); // Monday, still derived
        $this->assertFalse($days->coversGaps);
    }

    public function testCoverageReportsTheSeededSpanForTheHealthPanel(): void
    {
        $coverage = $this->days->coverage();

        $this->assertSame('2026-01-01', $coverage['first']);
        $this->assertSame('2028-12-31', $coverage['last']);
        $this->assertSame(DB::table('working_day_calendar')->count(), $coverage['rows']);
    }

    public function testTheCalendarHoldsExactlyOneRowPerDateWithNoGapsInsideTheSpan(): void
    {
        $rows = DB::table('working_day_calendar')->count();
        $distinct = DB::table('working_day_calendar')->distinct()->count('date');
        $span = (int) CarbonImmutable::parse('2026-01-01')->diffInDays(CarbonImmutable::parse('2028-12-31')) + 1;

        // 2026 and 2027 are common years, 2028 is a leap year: 365 + 365 + 366.
        $this->assertSame(1096, $span);
        $this->assertSame($span, $rows, 'the calendar must be dense: a missing day is a silent working day');
        $this->assertSame($rows, $distinct, 'one date must not appear twice with conflicting flags');
    }

    public function testTheReligiousHolidayGapIsVisibleRatherThanSilent(): void
    {
        $count = DB::table('working_day_calendar')
            ->whereYear('date', 2026)
            ->whereNotNull('label')
            ->count();

        // Five statute-fixed dates only. Once the SKB 3 Menteri list is loaded
        // this rises past a dozen and this assertion is updated deliberately.
        $this->assertSame(5, $count);
        $this->assertLessThan(16, $count, '2026 SLA maths is optimistic until the decree dates are seeded');
    }
}
