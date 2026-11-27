<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TSD-PDS-003 §3.3 — announcements and the record of who dismissed them.
 *
 * FSD-208 gives an announcement a title, a body, an audience, and a window, and
 * the audience is the reason this table holds a document rather than a set of
 * foreign keys. "All, by role, by department" is three questions about three
 * different populations, and the second and third name a list whose membership
 * changes: an audience of {quality assurance} means the people on that
 * department when the banner is read, not the people who were on it when it was
 * typed, because an announcement that reaches the new joiner late is still the
 * same announcement. That is why no ids are copied into the row — the row states
 * who is meant and the reader resolves it against the live lists.
 *
 * The window is two columns rather than a status flag because "live" is a
 * function of the clock, not a fact about the row: publish-at in the past means
 * immediate (FSD-208's own note), an empty expire-at means until it is stopped,
 * and a flag would need somebody to sweep it at midnight to stay true.
 *
 * announcement_reads is (user, announcement) and nothing else, which is the
 * narrowest thing that satisfies "remembered dismissed per user per
 * announcement". It is a preference, not evidence, so it is the one table here
 * whose rows may be deleted with the announcement they belong to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('body');
            $table->json('audience');
            $table->timestamp('publish_at');
            $table->timestamp('expire_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // The banner's reading is "what is up right now", newest first, and
            // the administration list is the same window with the closed rows
            // left in, so both start here (TSD §12 indexes what the screens ask).
            $table->index(['publish_at', 'expire_at'], 'idx_announcements_window');

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        // MySQL accepts any JSON in a json column, and a document that is not one
        // of the three shapes FSD-208 names would be read by nobody and seen by
        // nobody, which is the silent failure the check is for. It goes on after
        // the table exists: a Blueprint closure only collects commands, so a
        // statement issued inside it runs before the CREATE TABLE it refers to.
        DB::statement(<<<'SQL'
            ALTER TABLE `announcements`
                ADD CONSTRAINT `chk_announcements_audience_shape`
                CHECK (
                    json_unquote(json_extract(`audience`, '$."type"')) in ('all', 'roles', 'departments')
                )
        SQL);

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('dismissed_at');

            $table->unique(['announcement_id', 'user_id'], 'uq_announcement_read');
            $table->index('user_id', 'idx_announcement_reads_user');

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
    }
};
