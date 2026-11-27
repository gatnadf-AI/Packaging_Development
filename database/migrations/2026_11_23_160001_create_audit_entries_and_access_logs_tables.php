<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD-PDS-003 §3.3.5 (F9) — the two append-only record tables, and §3.3.6's
 * index for the per-record history strip.
 *
 * These arrive with Phase 1.2 rather than 1.4 on purpose. The delivery plan
 * puts the audit *viewer* and the database-level immutability grants in 1.4,
 * but 1.2 is where the first writes that §FSD-907 demands an account for happen:
 * an account created, a role changed, a person switched off. A table that does
 * not exist yet means either a writer that silently skips the audit or a feature
 * left unfinished, and the first is the kind of gap this project was asked to
 * eliminate. The audit_entries rows written from here on are the same rows the
 * 1.4 viewer will read.
 *
 * Nothing here has an updated_at column or a delete path, and the application's
 * MySQL user will hold INSERT and SELECT only (TSD-1102) once the provisioning
 * script is re-run. That grant, not a convention in the code, is what makes the
 * table append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();

            // Nullable because a write can be attributable to nobody: a seeded
            // reference list, a console command, a token holder who has since
            // gone. An audit row with a blank actor still says what changed.
            $table->foreignId('actor_id')->nullable()
                ->constrained('users')->restrictOnDelete();

            // TSD-1101: the role, not only the person, because the person's
            // roles change afterwards and the record must not follow them.
            $table->json('actor_role_snapshot')->nullable();

            $table->string('action', 64);
            $table->string('target_type', 120);
            $table->unsignedBigInteger('target_id')->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(
                ['target_type', 'target_id', 'created_at'],
                'idx_audit_entries_target'
            );
            $table->index(['action', 'created_at'], 'idx_audit_entries_action');

            $table->index(['actor_id', 'created_at'], 'idx_audit_entries_actor');

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        // §3.3.5: "separate from audit so a read flood cannot bury decisions".
        // Every denial of a route is written here (§5.3, FSD-907's failed
        // permission attempts), with the attempted route and the grant snapshot
        // that produced the answer.
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_id')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->string('kind', 32);
            $table->string('target_type', 120)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('route', 190)->nullable();
            $table->json('grant_snapshot')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['actor_id', 'created_at'], 'idx_access_logs_actor');
            $table->index(['kind', 'created_at'], 'idx_access_logs_kind');

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
        Schema::dropIfExists('audit_entries');
    }
};
