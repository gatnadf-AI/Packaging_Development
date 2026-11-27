<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\PackagingCategoryService;
use App\Http\Requests\Concerns\CatalogueMessages;
use App\Models\PackagingCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCR-4.1 — renaming a category, editing its description, or retiring it.
 *
 * One endpoint for the three because FSD §2.5 puts a version token on a record
 * rather than on a column: splitting the text edit from the flag would give the
 * same row two tokens a form has to keep in step, and the reviewed prototype's
 * lost changes came from exactly that. The controller still asks for two
 * audited acts, so the trail keeps "the name changed" apart from "the list was
 * closed" even when one save did both.
 *
 * `is_active` and `description` are `sometimes` rather than `required`: a panel
 * that posts only what it shows is not a panel that cleared what it did not, and
 * the distinction between "not mentioned" and "emptied" is made in the service
 * that receives it.
 *
 * There is no delete on this request, and the row's own fate is a different
 * question — FSD-401 blocks it while items are filed here, with MSG-070 saying
 * how many.
 */
class UpdateCategoryRequest extends FormRequest
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
                Rule::unique('packaging_categories', 'name')->ignore($this->category()->getKey()),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'row_version' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        if (is_string($this->input('name'))) {
            $trimmed['name'] = trim($this->input('name'));
        }

        if (is_string($this->input('description'))) {
            $trimmed['description'] = trim($this->input('description'));
        }

        $this->merge($trimmed);
    }

    /**
     * The text fields, with `description` included only when the caller spoke
     * about it. PackagingCategoryService::edit() reads that absence as "leave
     * the stored sentence standing" and an empty string as "clear it".
     *
     * @return array{name: string, description?: ?string}
     */
    public function categoryAttributes(): array
    {
        $attributes = ['name' => (string) $this->validated()['name']];

        if ($this->has('description')) {
            $description = $this->input('description');

            $attributes['description'] = is_string($description) && $description !== '' ? $description : null;
        }

        return $attributes;
    }

    public function wantsActive(): ?bool
    {
        if (! $this->has('is_active')) {
            return null;
        }

        return (bool) $this->input('is_active');
    }

    public function expectedVersion(): int
    {
        return (int) $this->validated()['row_version'];
    }

    public function category(): PackagingCategory
    {
        /** @var PackagingCategory $category */
        $category = $this->route('category');

        return $category;
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return [
            'name' => 'Nama kategori',
            'description' => 'Keterangan',
            'is_active' => 'Status aktif',
            'row_version' => 'Versi data',
        ];
    }
}
