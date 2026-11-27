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
 * SCR-4.3 — MSG-042's "Buat versi baru" (FSD-406, G-12).
 *
 * The same sheet as the other two and no version token, which is the difference
 * that matters. A revision does not overwrite the row it starts from — FSD-406
 * requires the earlier version to stay retrievable, and every inspection that
 * quoted it has to keep reading what it said — so there is nothing to
 * compare-and-set against. Two people starting a revision of one document is the
 * race §2.5's token cannot cover, and README records it as the gap it is.
 *
 * What the payload leaves out is carried forward by the domain rather than
 * defaulted here: SpecificationService::revise() reads the header and the unnamed
 * blocks off the superseded version, so the sheet that opens looks like the
 * document it replaces instead of like a blank one.
 */
class ReviseSpecificationRequest extends FormRequest
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
        return $this->sheetRules();
    }

    public function superseded(): Specification
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
        return $this->sheetLabels();
    }
}
