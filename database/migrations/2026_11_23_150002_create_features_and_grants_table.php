<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TSD-PDS-003 §3.3.1 — access and identity, part 2.
 *
 * Spatie's tables hold the role/permission plumbing; the business matrix of
 * FSD §6 lives in features + feature_grants because its cells are (feature,
 * role, level) triples with three states, not Laravel permission names.
 *
 * Deny is the default: the absence of a row means NONE (BR-07), so a forgotten
 * grant can never open a door.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Role slug = Spatie's `name` column; the additions make the FSD labels
        // and the system/business distinction queryable without a code table.
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label', 120)->nullable()->after('name');
            $table->boolean('is_system')->default(false)->after('label');
            $table->boolean('is_business')->default(true)->after('is_system');
        });

        // This application runs one auth guard (web), so a role name is unique
        // on its own. The explicit index is what lets feature_grants hold a
        // real foreign key: MySQL 8.4 refuses an FK onto Spatie's composite
        // (name, guard_name) index with error 6125.
        Schema::table('roles', function (Blueprint $table) {
            $table->unique('name', 'uq_roles_name');
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key', 96)->unique();
            $table->string('group', 64);
            $table->string('label', 160);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['group', 'sort'], 'idx_features_group_sort');

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        Schema::create('feature_grants', function (Blueprint $table) {
            $table->id();
            $table->string('feature_key', 96);
            // Must match Spatie's roles.name width exactly or InnoDB refuses the FK.
            $table->string('role_slug', 255);
            // VARCHAR + check instead of MySQL ENUM (§3.1): adding a state must
            // not become a column rebuild.
            $table->string('level', 8);
            $table->timestamp('changed_at')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->unique(['feature_key', 'role_slug'], 'uq_feature_grants_feature_role');
            $table->index('role_slug', 'idx_feature_grants_role');

            $table->foreign('feature_key')
                ->references('key')
                ->on('features')
                ->restrictOnDelete();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        // TSD-PDS-003 §3.1 — VARCHAR plus a CHECK constraint rather than a MySQL
        // ENUM. Laravel's Blueprint has no check() helper in 12.x, so the
        // constraint is issued as raw DDL; MySQL 8.4 enforces it.
        DB::statement(
            "alter table `feature_grants` add constraint `ck_feature_grants_level` ".
            "check (`level` in ('FULL', 'READ', 'NONE'))"
        );

        Schema::table('feature_grants', function (Blueprint $table) {
            $table->foreign('role_slug')
                ->references('name')
                ->on('roles')
                ->restrictOnDelete();
        });

        // One integer bumped on every matrix save (ADR-08). The permission
        // resolver caches per request but keys the cache on this value, so a
        // change is visible within one request cycle (FSD-204) without a TTL.
        Schema::create('matrix_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('changed_at')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matrix_versions');

        Schema::table('feature_grants', function (Blueprint $table) {
            $table->dropForeign(['role_slug']);
            $table->dropForeign(['feature_key']);
        });

        Schema::dropIfExists('feature_grants');
        Schema::dropIfExists('features');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('uq_roles_name');
            $table->dropColumn(['label', 'is_system', 'is_business']);
        });
    }
};
