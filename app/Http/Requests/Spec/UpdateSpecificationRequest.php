<?php

declare(strict_types=1);

namespace App\Http\Requests\Spec;

use App\Domain\Access\PermissionSet;
use App\Domain\Specification\SpecificationService;
use App\Http\Requests\Concerns\CatalogueMessages;
use App\Http\Requests\Concerns\SpecificationSheet;
use App\Models\Specification;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SCR-4.3 — saving a sheet that is still a draft (G-12, FSD §2.5).
 *
 * The create's rules plus the token. §2.5 asks for it on every editable record
 * and a specification carries one in the table, so a save that arrives against a
 * version somebody else already replaced is refused with MSG-020 instead of being
 * written over their work.
 *
 * A locked document passes this request and is refused further down, by
 * SpecificationService::assertEditable(): whether the sheet may be rewritten is a
 * fact about the row, not about the payload, and §2.3 keeps that kind of rule in
 * the domain. The two refusals read the same on the screen — an error under the
 * field that raised them — which is the point of routing both through the same
 * message catalogue.
 */
class UpdateSpecificationRequest extends FormRequest
{
    use CatalogueMessages;
    use SpecificationSheet;

    public function authorize(): bool
    {
        /** @var PermissionSet $set */
        $set = $this->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return $set->canAct(SpecificationService::FEATURE);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->sheetRules() + [
            'row_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function expectedVersion(): int
    {
        return (int) $this->validated('row_version');
    }

    public function specification(): Specification
    {
        /** @var Specification $specification */
        $specification = $this->route('specification');

        return $specification;
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return $this->sheetLabels() + ['row_version' => 'Versi data'];
    }
}
