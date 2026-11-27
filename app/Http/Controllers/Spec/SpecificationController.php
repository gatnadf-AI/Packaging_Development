<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spec;

use App\Domain\Access\PermissionSet;
use App\Domain\Specification\SpecificationDirectory;
use App\Domain\Specification\SpecificationService;
use App\Domain\Specification\SpecificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Spec\ReviseSpecificationRequest;
use App\Http\Requests\Spec\StoreSpecificationRequest;
use App\Http\Requests\Spec\UpdateSpecificationRequest;
use App\Models\Specification;
use App\Models\SpecificationComponent;
use App\Models\SpecificationParameter;
use App\Models\SpecificationSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SCR-4.3 — the specification register and the sheet itself (FSD-404, FSD-406, G-12).
 *
 * Two addresses and five verbs, and the absence of a sixth is the requirement:
 * FSD-404's number is never reused, and the act that retires a specification is
 * the approval engine's withdrawal rather than an author's delete, so there is no
 * DELETE verb under /spec at all. SpecificationScreenTest reads that off the
 * route table, which is the only way it stays true once somebody reaches for a
 * cleanup button.
 *
 * What the screen may not do is decide the four things the document is. number,
 * version, doc_status and author_id never appear in a form request's rule list,
 * so a payload that sends them is not read; the sequence and the state machine
 * own those columns (§2.3, TSD-432, FSD §4.1). Everything else on the sheet —
 * header and the three blocks — arrives through SpecificationService, which is
 * where "the blocks are the document" is enforced.
 *
 * The reviewer and approver chips read as null because nothing can name them
 * yet: FSD §4.5 derives who is holding a document from ordered step rows, and the
 * table those rows live in is Phase 3. Printing the author's name in the
 * "Disetujui oleh" box would be a guess dressed as a signature block, which is
 * why the props carry the absence rather than a value.
 */
class SpecificationController extends Controller
{
    public function __construct(
        private readonly SpecificationService $specifications,
        private readonly SpecificationDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Spec/List', [
            'screen' => 'SCR-4.3',
            'items' => $this->directory->paginate($request->query()),
            'filters' => $this->directory->resolvedFilters($request->query()),
            'columns' => $this->meta(),
            'reference' => $this->directory->referenceOptions(),
            'can' => $this->authority($request, null),
        ]);
    }

    /**
     * The blank sheet. No number is taken here: FSD-404's code is allocated when
     * the draft is created, which is the first save, so an abandoned click on
     * "SBK baru" cannot burn a code out of the year's sequence.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Spec/Document', [
            'screen' => 'SCR-4.3',
            'specification' => null,
            'reference' => $this->directory->referenceOptions(),
            'can' => $this->authority($request, null),
        ]);
    }

    public function show(Request $request, Specification $specification): Response
    {
        return Inertia::render('Spec/Document', [
            'screen' => 'SCR-4.3',
            'specification' => $this->document($specification),
            'reference' => $this->directory->referenceOptions(),
            'can' => $this->authority($request, $specification),
        ]);
    }

    public function store(StoreSpecificationRequest $request): RedirectResponse
    {
        $specification = $this->specifications->createDraft(
            $request->sheetAttributes(),
            $request->user(),
        );

        return redirect()
            ->route('spec.show', $specification->getKey())
            ->with('status', "SBK \"{$specification->number}\" v{$specification->version} dibuat sebagai draf.");
    }

    public function update(UpdateSpecificationRequest $request, Specification $specification): RedirectResponse
    {
        $saved = $this->specifications->saveDraft(
            $specification,
            $request->sheetAttributes(),
            $request->user(),
            $request->expectedVersion(),
        );

        return back()->with('status', "SBK \"{$saved->number}\" v{$saved->version} disimpan.");
    }

    public function revise(ReviseSpecificationRequest $request, Specification $specification): RedirectResponse
    {
        $revision = $this->specifications->revise(
            $specification,
            $request->sheetAttributes(),
            $request->user(),
        );

        return redirect()
            ->route('spec.show', $revision->getKey())
            ->with('status', "Versi baru SBK \"{$revision->number}\" v{$revision->version} dibuat.");
    }

    /**
     * The sheet as the screen reads it: §5's header block, then the three
     * repeating tables in the order the paper form prints them.
     *
     * The joined words travel beside the ids — a unit symbol, a method name, a
     * group name — because §2.2 forbids a machine value on a screen and a
     * read-only reader has no picker to look an id up with.
     *
     * @return array<string, mixed>
     */
    private function document(Specification $specification): array
    {
        $specification->loadMissing([
            'item', 'product', 'materialType', 'author.roles',
            'sizes.unit', 'sizes.method',
            'parameters.group', 'parameters.unit', 'parameters.method',
            'components',
        ]);

        return [
            'id' => (int) $specification->getKey(),
            'number' => $specification->number,
            'version' => (int) $specification->version,
            'doc_status' => $specification->doc_status->value,
            'doc_status_label' => $specification->doc_status->label(),
            'item_id' => $specification->item_id === null ? null : (int) $specification->item_id,
            'item_code' => $specification->item?->code,
            'item_name' => $specification->item?->name,
            'product_id' => $specification->product_id === null ? null : (int) $specification->product_id,
            'product_name' => $specification->product?->name,
            'material_name' => $specification->material_name,
            'material_type_id' => $specification->material_type_id === null ? null : (int) $specification->material_type_id,
            'material_type_name' => $specification->materialType?->name,
            'effective_date' => $specification->effective_date?->format('Y-m-d'),
            'change_note' => $specification->change_note,
            // §5's signature row. Only the first chip a draft can fill exists
            // today: Direview oleh and Disetujui oleh are the approval engine's
            // recorded decisions (FSD §4.5), and until its table exists the honest
            // answer beside those two boxes is that nothing has been recorded.
            'author' => $specification->author === null ? null : [
                'name' => $specification->author->name,
                'role' => $specification->author->roles->pluck('label')->first(),
                'date' => $specification->created_at?->format('Y-m-d'),
            ],
            'reviewer' => null,
            'approver' => null,
            'revision_of_id' => $specification->revision_of_id === null ? null : (int) $specification->revision_of_id,
            'row_version' => (int) $specification->row_version,
            'sizes' => $specification->sizes
                ->map(static fn (SpecificationSize $row): array => [
                    'code' => $row->code,
                    'label' => $row->label,
                    'nominal' => $row->nominal,
                    'min' => $row->min,
                    'max' => $row->max,
                    'unit_id' => $row->unit_id === null ? null : (int) $row->unit_id,
                    'unit_symbol' => $row->unit?->symbol,
                    'method_id' => $row->method_id === null ? null : (int) $row->method_id,
                    'method_name' => $row->method?->name,
                ])
                ->values()->all(),
            'parameters' => $specification->parameters
                ->map(static fn (SpecificationParameter $row): array => [
                    'group_id' => $row->group_id === null ? null : (int) $row->group_id,
                    'group_name' => $row->group?->name,
                    'parameter' => $row->parameter,
                    'spec_text' => $row->spec_text,
                    'min' => $row->min,
                    'max' => $row->max,
                    'unit_id' => $row->unit_id === null ? null : (int) $row->unit_id,
                    'unit_symbol' => $row->unit?->symbol,
                    'method_id' => $row->method_id === null ? null : (int) $row->method_id,
                    'method_name' => $row->method?->name,
                    'criteria' => $row->criteria,
                    'is_numeric' => (bool) $row->is_numeric,
                ])
                ->values()->all(),
            'components' => $specification->components
                ->map(static fn (SpecificationComponent $row): array => [
                    'component' => $row->component,
                    'material' => $row->material,
                    'percent' => $row->percent,
                    'note' => $row->note,
                ])
                ->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'searchable' => SpecificationDirectory::SEARCHABLE,
            'sortable' => SpecificationDirectory::SORTABLE,
            'defaultSort' => ['column' => SpecificationDirectory::DEFAULT_SORT, 'direction' => 'asc'],
            'perPageOptions' => SpecificationDirectory::PER_PAGE,
            'defaultPerPage' => SpecificationDirectory::DEFAULT_PER_PAGE,
            'searchFloor' => SpecificationDirectory::SEARCH_FLOOR,
        ];
    }

    /**
     * The four answers a screen of this shape needs, in one place, because the
     * grid's cell and the document's own status are two different questions and a
     * page that mixes them up shows a button that cannot work.
     *
     * write is the cell at FULL; editable adds G-12's half, which no cell can
     * override while the "explicit correction right" it mentions is a matrix
     * column nothing has; revise is the same gate read sideways — MSG-042's own
     * action, so a locked document always offers it and a draft never does;
     * create asks only the cell, since a blank sheet has no status to be blocked
     * by.
     *
     * @return array{write: bool, create: bool, editable: bool, revise: bool}
     */
    private function authority(Request $request, ?Specification $specification): array
    {
        /** @var PermissionSet $set */
        $set = $request->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        $write = $set->canAct(SpecificationService::FEATURE);

        $isDraft = $specification === null || $specification->doc_status === SpecificationStatus::Draft;

        return [
            'write' => $write,
            'create' => $write,
            'editable' => $write && $isDraft,
            'revise' => $write && ! $isDraft,
        ];
    }
}
