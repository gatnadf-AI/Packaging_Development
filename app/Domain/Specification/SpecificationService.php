<?php

declare(strict_types=1);

namespace App\Domain\Specification;

use App\Domain\Access\ChangeRefused;
use App\Domain\Numbering\Numbering;
use App\Models\Specification;
use App\Models\SpecificationComponent;
use App\Models\SpecificationParameter;
use App\Models\SpecificationSize;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditService;
use App\Support\Persistence\RowVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * SCR-4.3's authoring half — FSD-404, FSD-406 and G-12, written against the
 * tables TSD §3.3.3 lays out.
 *
 * A specification is not one row. §3.3.3 splits the printed form into a header
 * and three repeating blocks, and the migration makes each block a child table
 * that RESTRICTs against its parent rather than cascading, because the children
 * are the document: half a saved sheet is a document that lies. That shape
 * decides everything below — the four blocks are written, replaced and copied as
 * one unit with the header, inside the same transaction as the row they belong
 * to.
 *
 * The number is taken when the draft is created. FSD-404 describes QC/BP/NNN/YY
 * as the specification's own code rather than as something a submission produces,
 * §2.3 marks it computed, and the sequence is per year — so the year a document
 * was started is the year printed on it, and an SBK opened in December and
 * submitted in January is not silently renumbered into the second one. The cost
 * of that reading is that an abandoned draft has consumed a number, which is what
 * FSD-404 asks for in as many words ("never reused even when a specification is
 * withdrawn").
 *
 * A revision is a second row under the SAME number (FSD-406), so the allocator is
 * not consulted again and the code on paper never moves. T-07 arrives at the same
 * shape from the cancelled side ("DRAFT (version+1)", both versions linked), and
 * revision_of_id is where those two sentences are written down.
 *
 * TSD-432 has no path here at all: no method of this class accepts a number. A
 * wrong code is corrected by cancelling and re-issuing, and re-issuing is
 * allocate()'s business rather than an edit.
 */
final class SpecificationService
{
    public const FEATURE = 'specification.manage';

    /**
     * The header of §5's SCR-4.3 layout: what the document is about.
     *
     * number, version, doc_status, author_id and revision_of_id are absent on
     * purpose. Four of them are the identity of the version, and the fifth is
     * who wrote it — none is something a payload gets to say.
     *
     * @var list<string>
     */
    private const HEADER = ['item_id', 'product_id', 'material_name', 'material_type_id', 'effective_date', 'change_note'];

    /**
     * The three repeating blocks, keyed by the name the form posts them under.
     *
     * @var array<string, class-string>
     */
    private const BLOCKS = [
        'sizes' => SpecificationSize::class,
        'parameters' => SpecificationParameter::class,
        'components' => SpecificationComponent::class,
    ];

    /**
     * What each block may carry, written out rather than read off $fillable, so
     * a payload cannot smuggle a specification_id or a sort of its own choosing
     * into a row the document orders for itself.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'sizes' => ['code', 'label', 'nominal', 'min', 'max', 'unit_id', 'method_id'],
        'parameters' => ['group_id', 'parameter', 'spec_text', 'min', 'max', 'unit_id', 'method_id', 'criteria', 'is_numeric'],
        'components' => ['component', 'material', 'percent', 'note'],
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Open a draft: the number is allocated here, the version is 1, and the four
     * blocks arrive with it or not at all.
     *
     * @param  array<string, mixed>  $input
     */
    public function createDraft(array $input, Authenticatable $author): Specification
    {
        return DB::transaction(function () use ($input, $author): Specification {
            $specification = Specification::query()->create($this->header($input) + [
                'number' => Numbering::allocate('sbk'),
                'version' => 1,
                'doc_status' => SpecificationStatus::Draft,
                'author_id' => $author->getAuthIdentifier(),
                'revision_of_id' => null,
            ]);

            $this->writeBlocks($specification, $input);

            $this->audit->record(
                $author,
                AuditAction::SpecCreated,
                $specification,
                null,
                AuditService::snapshot($specification, ['number', 'version', ...self::HEADER]),
            );

            // The database filled version, doc_status and row_version from their
            // column defaults, and the screen that opens next needs the token it
            // is going to save against — a create that returned an in-memory model
            // with a null row_version hands the first edit a null compare-and-set.
            return $specification->refresh();
        });
    }

    /**
     * Save a draft that is still open for editing.
     *
     * G-12 decides who may reach this: "Doc status is DRAFT or the user holds an
     * explicit correction right", and the correction right is a matrix cell no
     * identity in FSD §6 has, so in practice a draft is the only thing editable
     * until Phase 3 says otherwise. A locked document answers with MSG-042, whose
     * own action is "Buat versi baru" — revise(), below.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveDraft(
        Specification $specification,
        array $input,
        Authenticatable $actor,
        int $expectedVersion,
    ): Specification {
        $this->assertEditable($specification);

        $header = $this->header($input);
        $before = AuditService::snapshot($specification, self::HEADER);

        $saved = DB::transaction(function () use ($specification, $header, $input, $expectedVersion): Specification {
            // The parent's compare-and-set goes first: it is the one statement
            // that knows whether somebody else landed in between, and the block
            // rows are replaced only if it says the sheet may still be rewritten.
            $fresh = RowVersion::save($specification, $expectedVersion, $header);

            $this->writeBlocks($fresh, $input);

            return $fresh;
        });

        $this->audit->record(
            $actor,
            AuditAction::SpecUpdated,
            $saved,
            $before,
            AuditService::snapshot($saved, self::HEADER),
        );

        return $saved;
    }

    /**
     * Start the next version of a document that can no longer be edited.
     *
     * FSD-406 and T-07 are the same act seen from two statuses, and both leave
     * the earlier row exactly where it was: "prior versions stay retrievable" is
     * the sentence that makes a revision an insert rather than an overwrite, and
     * the version an inspection quoted has to keep reading as what it said.
     *
     * What the payload does not name is copied from the version being revised.
     * MSG-042's button opens a sheet that reads as the document it replaces — an
     * empty form would be a different answer to the same requirement, and one the
     * author would have to retype.
     *
     * @param  array<string, mixed>  $input
     */
    public function revise(Specification $superseded, array $input, Authenticatable $actor): Specification
    {
        if ($superseded->doc_status === SpecificationStatus::Draft) {
            throw ChangeRefused::draftIsStillEditable();
        }

        $header = $this->header($input) + $this->carriedForward($superseded);

        $revision = DB::transaction(function () use ($superseded, $header, $input, $actor): Specification {
            $revision = Specification::query()->create($header + [
                'number' => $superseded->number,
                'version' => $this->nextVersion($superseded->number),
                'doc_status' => SpecificationStatus::Draft,
                'author_id' => $actor->getAuthIdentifier(),
                'revision_of_id' => $superseded->getKey(),
            ]);

            $this->writeBlocks($revision, $input, $superseded);

            return $revision;
        });

        $this->audit->record(
            $actor,
            AuditAction::SpecRevised,
            $superseded,
            [
                'version' => $superseded->version,
                'doc_status' => $superseded->doc_status->value,
            ],
            [
                'version' => $revision->version,
                'doc_status' => $revision->doc_status->value,
                'revision_id' => $revision->getKey(),
            ],
        );

        return $revision;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function header(array $input): array
    {
        $header = [];

        foreach (self::HEADER as $column) {
            if (! array_key_exists($column, $input)) {
                continue;
            }

            $header[$column] = match ($column) {
                'material_name' => trim((string) $input[$column]),
                'item_id', 'product_id', 'material_type_id' => self::idOrNull($input[$column]),
                default => self::blankToNull($input[$column]),
            };
        }

        return $header;
    }

    /**
     * The header a revision inherits from the version it starts from.
     *
     * @return array<string, mixed>
     */
    private function carriedForward(Specification $superseded): array
    {
        return [
            'item_id' => $superseded->item_id,
            'product_id' => $superseded->product_id,
            'material_name' => $superseded->material_name,
            'material_type_id' => $superseded->material_type_id,
            'effective_date' => $superseded->effective_date,
            'change_note' => null,
        ];
    }

    /**
     * Replace, or copy, the three repeating blocks.
     *
     * A block the payload names is rewritten whole: the children RESTRICT against
     * their parent rather than cascade, so the delete and the inserts are one
     * statement of intent and cannot leave a sheet with a mixture of two saves. A
     * block it does not name is left alone on a draft and taken from the parent on
     * a revision ($from), which is the only case that needs a copy.
     *
     * The sort column is the position in the payload, one-based, because §7.2
     * prints the blocks in the order the paper form lists them and a form that
     * moves a row moves the printed document with it. A caller that numbered its
     * own rows is not trusted with that: sort is where the document lives, not
     * where the request came from.
     *
     * @param  array<string, mixed>  $input
     */
    private function writeBlocks(Specification $specification, array $input, ?Specification $from = null): void
    {
        foreach (self::BLOCKS as $block => $class) {
            if (array_key_exists($block, $input)) {
                $rows = array_map(
                    fn (array $row): array => $this->blockRow($block, $row),
                    array_values((array) $input[$block]),
                );
            } elseif ($from !== null) {
                // The block is copied, not renumbered by whoever asked: the
                // revision prints the same sheet the approved version printed,
                // and §7.2's order is the paper form's.
                $rows = $from->{$block}()
                    ->get()
                    ->map(static fn ($row): array => Arr::only($row->attributesToArray(), self::COLUMNS[$block]))
                    ->values()
                    ->all();
            } else {
                continue;
            }

            $class::query()->where('specification_id', $specification->getKey())->delete();

            foreach ($rows as $index => $row) {
                $class::query()->create($row + [
                    'specification_id' => $specification->getKey(),
                    'sort' => $index + 1,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function blockRow(string $block, array $row): array
    {
        $clean = [];

        foreach (self::COLUMNS[$block] as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $clean[$column] = match (true) {
                $column === 'is_numeric' => (bool) $row[$column],
                in_array($column, ['unit_id', 'method_id', 'group_id'], true) => self::idOrNull($row[$column]),
                default => self::blankToNull($row[$column]),
            };
        }

        return $clean;
    }

    private function nextVersion(string $number): int
    {
        // Highest version for the number, not a count of rows: FSD-406's history
        // is the one thing a revision must not disturb, and a withdrawn document
        // whose row is gone would otherwise pull the next number down onto a
        // version somebody already printed.
        return (int) Specification::query()->where('number', $number)->max('version') + 1;
    }

    private function assertEditable(Specification $specification): void
    {
        if ($specification->doc_status === SpecificationStatus::Draft) {
            return;
        }

        // MSG-042 is §2.6's sentence for G-12, and its action is the button the
        // screen offers next: nothing here edits a locked document "because the
        // user holds an explicit correction right" either — no cell in FSD §6's
        // grid grants one, and inventing one would be a permission the approved
        // matrix does not contain.
        throw ChangeRefused::documentIsLocked();
    }

    private static function idOrNull(mixed $value): ?int
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : (int) $text;
    }

    private static function blankToNull(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
