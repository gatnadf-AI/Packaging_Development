<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD-PDS-003 §3.3.1 — access and identity, part 1.
 *
 * The users table ships with the framework skeleton; this widens it with the
 * columns the FSD demands (department, active flag, forced first login, locale)
 * and adds the departments reference list. Nothing here is deleted by cascade:
 * a department with users underneath it is deactivated, not removed (G-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->after('password')
                ->constrained('departments')
                ->restrictOnDelete();
            $table->string('phone', 32)->nullable()->after('department_id');
            $table->boolean('is_active')->default(true)->after('phone');
            $table->boolean('must_change_password')->default(true)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
            // OQ-03: the interface is Indonesian, so that is what a new account
            // starts with. FSD-106 keeps it a per-user preference, and document
            // output language follows the document type rather than this column.
            $table->string('locale', 8)->default('id')->after('last_login_at');
            // Optimistic concurrency (§3.1): a stale edit must fail, not silently win.
            $table->unsignedInteger('row_version')->default(1)->after('locale');

            $table->index(['is_active', 'department_id'], 'idx_users_active_department');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_active_department');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'phone',
                'is_active',
                'must_change_password',
                'last_login_at',
                'locale',
                'row_version',
            ]);
        });

        Schema::dropIfExists('departments');
    }
};
