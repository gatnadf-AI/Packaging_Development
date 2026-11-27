<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\BranchClass;
use App\Domain\MasterData\PackagingCategoryService;
use App\Http\Requests\Concerns\CatalogueMessages;
use App\Models\PackagingItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCR-4.2 — an edit of an existing item, including moving it to another category.
 *
 * The rules are the create screen's and the unique code ignores this row, which
 * is the only difference between the two: FSD §2.3 makes the code the printed
 * identity of the pack, so it can be corrected (the master-spec file itself has
 * a typo in one) but never duplicated.
 *
 * `is_active` is deliberately not on this request. Retirement is the one act on
 * this list with a route of its own, because it is the act FSD-402 says replaces
 * deletion — a flag inside a general edit form would be the version of "delete"
 * that looks like a save.
 */
class UpdateItemRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        /** @var PermissionSet $set */
        $set = $this->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

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
                Rule::unique('packaging_items', 'code')->ignore($this->item()->getKey()),
            ],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'category_id' => ['required', 'integer', 'exists:packaging_categories,id'],
            'material_type_id' => ['sometimes', 'nullable', 'integer', 'exists:material_types,id'],
            'branch_class' => ['required', 'string', 'in:'.implode(',', BranchClass::values())],
            'row_version' => ['required', 'integer', 'min:1'],
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

        $attributes = [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'category_id' => (int) $data['category_id'],
            'branch_class' => (string) $data['branch_class'],
        ];

        // Presence, not value: an absent key is a form that did not speak about
        // the material, and PackagingItemService::update() keeps the stored one.
        if ($this->has('material_type_id')) {
            $attributes['material_type_id'] = isset($data['material_type_id'])
                ? (int) $data['material_type_id']
                : null;
        }

        return $attributes;
    }

    public function expectedVersion(): int
    {
        return (int) $this->validated()['row_version'];
    }

    public function item(): PackagingItem
    {
        /** @var PackagingItem $item */
        $item = $this->route('item');

        return $item;
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
            'row_version' => 'Versi data',
        ];
    }
}
