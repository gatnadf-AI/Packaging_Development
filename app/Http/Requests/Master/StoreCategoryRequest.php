<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\PackagingCategoryService;
use App\Http\Requests\Concerns\CatalogueMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCR-4.1 — a new packaging category (FSD-401).
 *
 * The name is unique because it is the vocabulary the reports group by: two rows
 * one character apart would give every picker and every printout a choice that
 * cannot be explained. The uniqueness is checked on the trimmed value, which is
 * also the value stored — "Primer " and "Primer" are one category said twice,
 * and the database's own unique index cannot tell them apart either.
 *
 * There is no `is_active` here. A new row is either offered or it is not, and a
 * form that created a category already retired would be asking for a state
 * nobody can reach: the retire act is on the saved row.
 */
class StoreCategoryRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'min:2',
                'max:120',
                Rule::unique('packaging_categories', 'name'),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter(
            [
                'name' => is_string($this->input('name')) ? trim($this->input('name')) : null,
                'description' => is_string($this->input('description')) ? trim($this->input('description')) : null,
            ],
            static fn (mixed $value): bool => $value !== null
        ));
    }

    public function categoryName(): string
    {
        return (string) $this->validated()['name'];
    }

    public function categoryDescription(): ?string
    {
        $description = $this->validated()['description'] ?? null;

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return [
            'name' => 'Nama kategori',
            'description' => 'Keterangan',
        ];
    }
}
