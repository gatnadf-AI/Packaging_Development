<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\CatalogueMessages;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FSD-206 — renaming a department, or switching it off.
 *
 * One endpoint for both because the panel edits one row and §2.5 asks for a
 * version token per record, not per column: splitting the rename from the flag
 * would give the same row two tokens to keep in step, and a form that has to
 * submit twice to save one edit is how the reviewed prototype lost changes.
 *
 * There is no delete here, and that is the requirement rather than the omission:
 * §3.1 makes the foreign key RESTRICT and names deactivation as the mechanism,
 * because reports group by department and people are filed in one. The count of
 * accounts still inside is sent back with the refusal-free answer so the panel
 * can say what turning this off leaves standing.
 */
class UpdateDepartmentRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->departmentModel()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = $this->departmentModel();
        $goingInactive = $this->input('is_active') !== null && ! (bool) $this->input('is_active');

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:120',
                Rule::unique('departments', 'name')->ignore($department->getKey()),
            ],
            'is_active' => ['required', 'boolean'],
            'row_version' => ['required', 'integer', 'min:1'],
            'confirm' => $goingInactive ? ['required', 'accepted'] : ['nullable', 'boolean'],
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

    public function wantsActive(): bool
    {
        return (bool) $this->validated()['is_active'];
    }

    public function expectedVersion(): int
    {
        return (int) $this->validated()['row_version'];
    }

    public function departmentModel(): Department
    {
        /** @var Department $department */
        $department = $this->route('department');

        return $department;
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return [
            'name' => 'Nama departemen',
            'is_active' => 'Status aktif',
            'row_version' => 'Versi data',
            'confirm' => 'Konfirmasi',
        ];
    }
}
