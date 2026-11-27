<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\CatalogueMessages;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FSD-206 — a department is data the client owns, not a constant in a migration.
 *
 * The name is unique because §3.3.1 says so, and the uniqueness is checked on the
 * trimmed value: two rows that differ only by a trailing space would give a
 * dropdown two entries that read the same, which is the kind of thing a reference
 * list is supposed to prevent rather than produce.
 */
class StoreDepartmentRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Department::class) ?? false;
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
                Rule::unique('departments', 'name'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
        ]);
    }

    public function departmentName(): string
    {
        return (string) $this->validated()['name'];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return ['name' => 'Nama departemen'];
    }
}
