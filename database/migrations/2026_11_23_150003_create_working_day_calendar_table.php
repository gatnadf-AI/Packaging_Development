<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD-PDS-003 §3.3.9, §9 — the working-day calendar.
 *
 * Every SLA, escalation and cycle-time figure in the system is expressed in
 * WORKING days (FSD-905), so the calendar is data the engine reads rather than
 * a maths function that assumes Monday-to-Friday. Weekends come from the
 * baseline config; national holidays are rows that override it.
 *
 * The table is intentionally additive: a holiday added late retroactively
 * changes an open SLA, which is the correct behaviour for an overdue queue and
 * the reason the calendar is not derived at query time from Carbon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('working_day_calendar', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->boolean('is_working_day');
            // Free text so a shared religious observance can be labelled in the
            // users' own words rather than forced into a code list.
            $table->string('label', 120)->nullable();
            // A row carrying a remark was set by a person, not by the seeder,
            // and re-seeding the calendar must not overwrite it.
            $table->string('remark', 190)->nullable();
            $table->timestamps();

            // §3.3.9 keeps this table to (date, is_working_day, label). Every
            // SLA query asks for a date range, which the unique date index
            // serves directly; a denormalised `year` column was tried and
            // MySQL 8.4 would not accept it as generated through Laravel 12's
            // Blueprint, so it stayed an ordinary NOT NULL column that the
            // seeder then had to fill for no benefit.

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_day_calendar');
    }
};
