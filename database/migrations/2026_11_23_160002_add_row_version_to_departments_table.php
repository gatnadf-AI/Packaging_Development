<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FSD §2.5 concurrency: "Each editable record carries a version token."
 *
 * §3.3.1 lists row_version on users, projects and specifications but not on the
 * two reference lists, which is an omission rather than a decision: FSD-206
 * makes departments editable by an administrator, and a silent overwrite of
 * somebody else's reference data is exactly what the clause exists to stop. The
 * column is added with the same shape as the documented ones, and every later
 * reference list (packaging categories, standards) follows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // MySQL fills the default for rows that already exist, so the
            // seeded list starts on the same token a new row would get.
            $table->unsignedInteger('row_version')->default(1)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('row_version');
        });
    }
};
