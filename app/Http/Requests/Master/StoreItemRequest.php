<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\BranchClass;
use App\Domain\MasterData\PackagingCategoryService;
use App\Http\Requests\Concerns\CatalogueMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCR-4.2 — a new packaging item (FSD-401, FSD-403, FR-605).
 *
 * Two of these fields are chosen rather than typed, and the validation is what
 * makes that true rather than merely intended. `category_id` is required and has
 * to exist because FSD-401 gives every pack exactly one category — a null here
 * is a row no report can group. `material_type_id` is optional but, when it is
 * sent, must be a row of the reference list: FR-605 records the material, TSD
 * §3.1 refuses a free-text unit for the same reason, and a typed "PP-LDPE " with
 * a stray space is a second material that the pickers will offer forever.
 *
 * `branch_class` is checked against the three BranchClass cases and nothing else
 * — FSD-403 makes it metadata that seeds the assignment picker, so a fourth
 * value is not a new classification but a branch the workflow does not have.
 *
 * No pattern is asserted on `code`. Its shape is the client's own (RMLPC0794 in
 * the master-spec file), FSD §2.3 lists no row for packaging items, and a regex
 * invented here would refuse a real code the day the client issues one that does
 * not fit the guess.
 */
class StoreItemRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        /** @var PermissionSet $set */
        $set = $this->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        // Not master.item: FSD §6 gives the two packaging lists one cell
        // (PackagingCategoryService::FEATURE), so this screen is gated by it.
        return $set->canAct(PackagingCategoryService::FEATURE);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('packaging_items', 'code'),
            ],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'category_id' => ['required', 'integer', 'exists:packaging_categories,id'],
            'material_type_id' => ['nullable', 'integer', 'exists:material_types,id'],
            'branch_class' => ['required', 'string', 'in:'.implode(',', BranchClass::values())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        if (is_string($this->input('code'))) {
            $trimmed['code'] = trim($this->input('code'));
        }

        if (is_string($this->input('name'))) {
            $trimmed['name'] = trim($this->input('name'));
        }

        $this->merge($trimmed);
    }

    /**
     * @return array<string, mixed>
     */
    public function itemAttributes(): array
    {
        $data = $this->validated();

        return [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'category_id' => (int) $data['category_id'],
            'material_type_id' => isset($data['material_type_id']) ? (int) $data['material_type_id'] : null,
            'branch_class' => (string) $data['branch_class'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return [
            'code' => 'Kode item',
            'name' => 'Nama item',
            'category_id' => 'Kategori',
            'material_type_id' => 'Jenis material',
            'branch_class' => 'Klasifikasi cabang',
        ];
    }
}
