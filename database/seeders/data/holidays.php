<?php

declare(strict_types=1);

/*
 * Indonesian public holidays loaded into working_day_calendar.
 *
 * Only dates fixed by statute are listed here. The Islamic (Hijri) and
 * Chinese-calendar holidays move each year and are set by the joint ministerial
 * decree (Surat Keputusan Bersama 3 Menteri); their dates are deliberately NOT
 * invented. At go-live the client pastes the published SKB list into the
 * matching year block, or enters it through the admin calendar screen, and the
 * WorkingDayCalendarSeeder re-run picks it up.
 *
 * Until then an SLA computed over a period containing an unset religious
 * holiday is one working day optimistic. That gap is visible in the admin
 * health panel (TSD §4.7), not hidden.
 *
 * Fixed by law:
 *   1 January      Tahun Baru Masehi
 *   1 May          Hari Buruh Internasional
 *   1 June         Hari Lahir Pancasila
 *   17 August      Hari Kemerdekaan Republik Indonesia
 *   25 December     Hari Raya Natal
 */

return [
    'fixed' => [
        '2026-01-01' => 'Tahun Baru Masehi',
        '2026-05-01' => 'Hari Buruh Internasional',
        '2026-06-01' => 'Hari Lahir Pancasila',
        '2026-08-17' => 'Hari Kemerdekaan Republik Indonesia',
        '2026-12-25' => 'Hari Raya Natal',

        '2027-01-01' => 'Tahun Baru Masehi',
        '2027-05-01' => 'Hari Buruh Internasional',
        '2027-06-01' => 'Hari Lahir Pancasila',
        '2027-08-17' => 'Hari Kemerdekaan Republik Indonesia',
        '2027-12-25' => 'Hari Raya Natal',

        '2028-01-01' => 'Tahun Baru Masehi',
        '2028-05-01' => 'Hari Buruh Internasional',
        '2028-06-01' => 'Hari Lahir Pancasila',
        '2028-08-17' => 'Hari Kemerdekaan Republik Indonesia',
        '2028-12-25' => 'Hari Raya Natal',
    ],

    // Filled from the published SKB 3 Menteri for each year. Empty is a known,
    // reported gap — see database/seeders/data/holidays.php header.
    'skb' => [
        // '2026-03-19' => 'Hari Suci Nyepi',
    ],
];
