<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TSD-PDS-003 §3.3.3 — the SBK, before SCR-4.3 exists to write one.
 *
 * §3.3.3 is a table of "columns of consequence", not a DDL listing, so the
 * readings that turned a named column into a constraint are recorded in the
 * test that asserts them (SpecificationSchemaTest) and here in one line each:
 *
 * — uq(number, version) is the document's own words. FSD-406's "same number,
 *   version suffix" is the reason a revision is a second row rather than an
 *   overwrite, and it is the reason prior versions stay retrievable.
 * — item_id and product_id are NOT NULL because FSD-409 has a specification
 *   describe a product packed in an item; a row with neither is a form with no
 *   subject. Both edges RESTRICT (§3.1: nothing in this system is deleted by
 *   cascade).
 * — the four children are RESTRICT against the parent rather than cascade, which
 *   is what forces the draft editor to replace its rows inside one transaction:
 *   the children are the document, so a delete-then-insert that loses the second
 *   half loses the specification.
 * — approval_document_id from §3.3.3 is deliberately absent. It is a pointer
 *   into the workflow engine's own table (§3.3.5), which arrives with Phase 3;
 *   a nullable FK to a table this schema does not have yet would be a column no
 *   code can fill and every later reader would have to re-derive. Phase 3 adds
 *   it in its own migration, next to the chain that needs it.
 *
 * row_version is here because §3.3.3 names it and FSD §2.5 puts a token on every
 * editable record — MSG-020 refuses a stale save against it.
 *
 * No secondary index is added. §3.4 justifies each index by a named query, names
 * none for these tables, and uq(number, version) already leads on the one column
 * every read of a specification starts from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specifications', function (Blueprint $table) {
            $table->id();
            // The allocated code (QC/BP/NNN/YY) is printed and joined in the
            // register, and the id is the key, so §3.1's rule for human codes
            // applies: a unique column, never the primary key. 24 characters
            // holds every pattern §4.5 lists, the longest of which is
            // INS-{assignment}-{level}.
            $table->string('number', 24);
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('item_id')->constrained('packaging_items')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('material_name', 160);
            // Nullable because the header's material type is the paper form's
            // summary line; §5 gives the per-row material to the component block,
            // which is where the 77-row import puts it. A draft with a component
            // list and no header type is a real document, not a broken row.
            $table->foreignId('material_type_id')->nullable()
                ->constrained('material_types')->restrictOnDelete();
            // FSD §4.2 stamps the effective date when the document closes, not
            // when the author starts typing, so a draft has none.
            $table->date('effective_date')->nullable();
            $table->string('doc_status', 16)->default('DRAFT');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            // §3.3.3 names it, and FSD-406 makes a revision carry the reason the
            // last version was not simply overwritten. Nullable because version 1
            // revises nothing, and because T-07's reason is acknowledged by the
            // author rather than stored as a second copy of it.
            $table->string('change_note', 500)->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->unique(['number', 'version'], 'uq_specifications_number_version');

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        Schema::table('specifications', function (Blueprint $table) {
            // T-07 and FSD-406 both want the chain written down: a cancelled
            // document resumes as "DRAFT (version+1)" with both versions linked
            // in history. Nullable because version 1 has no prior, and RESTRICT
            // because a history a DELETE can unpick is not a history.
            $table->foreignId('revision_of_id')->nullable()
                ->constrained('specifications')->restrictOnDelete();
        });

        DB::statement(
            "alter table `specifications` add constraint `ck_specifications_doc_status` ".
            "check (`doc_status` in ('DRAFT', 'SUBMITTED', 'PENDING', 'CLOSED', 'CANCELLED'))"
        );

        Schema::create('specification_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specification_id')->constrained('specifications')->restrictOnDelete();
            // No check constraint over TL/C/TH/OD/H. §5 writes the five in a
            // parenthesis and §3.3.3 repeats it, but neither calls the list
            // closed, and Phase 2e loads the client's own workbook into this
            // column: a check invented from an example would refuse real rows.
            $table->string('code', 16);
            // The paper form prints a name beside the code ("Tinggi badan botol"),
            // which is why label exists and why code is not simply displayed.
            $table->string('label', 120)->nullable();
            $table->decimal('nominal', 12, 4)->nullable();
            $table->decimal('min', 12, 4)->nullable();
            $table->decimal('max', 12, 4)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->foreignId('method_id')->nullable()
                ->constrained('analysis_methods')->restrictOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        Schema::create('specification_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specification_id')->constrained('specifications')->restrictOnDelete();
            // NOT NULL: FSD §5 and SCR-4.3 bucket the parameters under the six
            // seeded groups, and the printed form prints them in that order, so
            // a parameter outside a group has no place on the page.
            $table->foreignId('group_id')->constrained('parameter_groups')->restrictOnDelete();
            $table->string('parameter', 160);
            $table->string('spec_text', 500)->nullable();
            $table->decimal('min', 12, 4)->nullable();
            $table->decimal('max', 12, 4)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->foreignId('method_id')->nullable()
                ->constrained('analysis_methods')->restrictOnDelete();
            $table->string('criteria', 255)->nullable();
            // TSD §3.3.3 says this row "drives the inspection template exactly".
            // The flag is what makes that true for a measurement and for a
            // judgement: a numeric parameter is compared against min/max, a text
            // one is answered against criteria.
            $table->boolean('is_numeric')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        Schema::create('specification_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specification_id')->constrained('specifications')->restrictOnDelete();
            $table->string('component', 160);
            // NOT NULL rather than a FK to material_types: §5's block prints the
            // material as the paper form writes it (77 rows of the client's own
            // wording), and FSD-408's extensible list is not the same vocabulary.
            // Turning this column into a foreign key is a decision for whoever
            // reconciles the two lists, not a constraint to assume here.
            $table->string('material', 160);
            // NOT NULL because a blank share on the component block reads as
            // "none" rather than as "not recorded", and this is the column the
            // import is measured in.
            $table->decimal('percent', 8, 4);
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });

        Schema::create('numbering_sequences', function (Blueprint $table) {
            $table->id();
            // TSD §4.5 puts one allocator behind every pattern, so the row is
            // keyed by what is being numbered and by which period restarts.
            $table->string('scope', 32);
            $table->string('period', 8);
            $table->unsignedInteger('next_value')->default(1);
            $table->string('pattern', 60);
            $table->timestamps();

            // §4.5 locks "the period row", which is only one row if the pair is
            // unique. Two rows for one period would let two writers each take a
            // FOR UPDATE lock and hand out the same NNN.
            $table->unique(['scope', 'period'], 'uq_numbering_sequences_scope_period');

            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_sequences');
        Schema::dropIfExists('specification_components');
        Schema::dropIfExists('specification_parameters');
        Schema::dropIfExists('specification_sizes');
        Schema::dropIfExists('specifications');
    }
};
