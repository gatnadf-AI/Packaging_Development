<?php

declare(strict_types=1);

namespace App\Http\Requests\Spec;

use App\Domain\Access\PermissionSet;
use App\Domain\Specification\SpecificationService;
use App\Http\Requests\Concerns\CatalogueMessages;
use App\Http\Requests\Concerns\SpecificationSheet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SCR-4.3 — the first save of a specification sheet (FSD-404, §2.5).
 *
 * No row_version on this request: §2.5 puts a token on every editable record, and
 * a record that does not exist yet has none. The number, the version and the
 * status are equally absent, because the sheet does not get to say what the
 * sequence gave it — SpecificationService::createDraft() allocates all three.
 */
class StoreSpecificationRequest extends FormRequest
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

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return $this->sheetLabels();
    }
}
