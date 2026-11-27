<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TSD-PDS-003 §3.3.2 — FSD area F4's master data, before any screen writes it.
 *
 * Three of F4's rules are properties of this schema rather than of a form, and
 * they are the three a later screen could otherwise forget:
 *
 * — FSD-401 says an item belongs to exactly one category and that a referenced
 *   category cannot be deleted. The first is a NOT NULL foreign key, the second
 *   is RESTRICT (§3.1: "nothing in this system is deleted by cascade;
 *   deactivation is the mechanism"). The screen's job is to say MSG-070 with the
 *   live count first; the database's job is to make the deletion impossible
 *   when it does not.
 * — FSD-402 says an item is deactivated, never deleted, and still prints. So
 *   there is no delete path here and no soft-delete column either: §3.1 puts
 *   master data outside the soft-delete list, and an is_active flag that a
 *   picker must remember to filter is the honest shape of "keep the row, stop
 *   offering it".
 * — FSD-403 makes branch_class metadata that seeds a picker and is never a
 *   gate. A VARCHAR with a check over the three named branches keeps it from
 *   becoming a fourth branch somebody typed into a cell.
 *
 * row_version is on the three editable lists because FSD §2.5 puts a version
 * token on every editable record; §3.3.2 simply did not repeat it, the same
 * omission 2026_11_23_160002 recorded for departments.
 *
 * The four reference lists (material types, units, parameter groups, analysis
 * methods) carry no is_active because §3.3.2 names no such column for them:
 * FSD-408 makes them extensible by adding rows, not by retiring them, and a
 * retired unit symbol would still be referenced by the parameters that used it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique('uq_material_types_name');
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 16)->unique('uq_units_symbol');
            $table->string('name', 80);
            // §3.1: an enumeration is a checked VARCHAR plus a PHP enum, never a
            // MySQL ENUM, so adding a kind is an insert rather than a rebuild.
            $table->string('kind', 16);
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        DB::statement(
            "alter table `units` add constraint `ck_units_kind` ".
            "check (`kind` in ('length', 'mass', 'volume', 'count'))"
        );

        Schema::create('parameter_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            // The printed form shows the groups in the order the paper document
            // shows them (SCR-4.3, §7.2), so the order is data and not a sort
            // rule a template could rewrite.
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        Schema::create('analysis_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160)->unique('uq_analysis_methods_name');
            // Nullable because the method is named before its usual unit is
            // known; a parameter row that carries no unit is a text judgement,
            // not a measurement, and §3.1 only forbids the unit being free text.
            $table->foreignId('default_unit_id')->nullable()
                ->constrained('units')->restrictOnDelete();
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        Schema::create('packaging_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique('uq_packaging_categories_name');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        Schema::create('packaging_items', function (Blueprint $table) {
            $table->id();
            // §3.1: the human code is printed and the id is joined, so the code
            // is a unique column and never the key. Its shape is the client's
            // own (RMLPC0794 in the master-spec file), not a pattern invented
            // here — FSD §2.3 has no row for packaging items.
            $table->string('code', 32)->unique('uq_packaging_items_code');
            $table->string('name', 160);
            $table->foreignId('category_id')->constrained('packaging_categories')->restrictOnDelete();
            $table->foreignId('material_type_id')->nullable()
                ->constrained('material_types')->restrictOnDelete();
            $table->string('branch_class', 8);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->index('category_id', 'idx_packaging_items_category');

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        DB::statement(
            "alter table `packaging_items` add constraint `ck_packaging_items_branch_class` ".
            "check (`branch_class` in ('A', 'B', 'BOTH'))"
        );

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique('uq_products_code');
            $table->string('name', 160);
            $table->string('brand', 120)->nullable();
            $table->string('variant', 120)->nullable();
            $table->string('pack_size', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('packaging_items');
        Schema::dropIfExists('packaging_categories');
        Schema::dropIfExists('analysis_methods');
        Schema::dropIfExists('parameter_groups');
        Schema::dropIfExists('units');
        Schema::dropIfExists('material_types');
    }
};
