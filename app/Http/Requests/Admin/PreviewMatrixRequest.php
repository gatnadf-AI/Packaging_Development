<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Access\PermissionLevel;
use App\Domain\Access\PermissionSet;
use App\Http\Requests\Concerns\CatalogueMessages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FSD-205 — the page of pending edits an administrator wants to see described
 * before any of it is saved.
 *
 * There is no `from` here, unlike the save: the preview is a question about the
 * grid as it stands, and answering it from the database is the point. Requiring
 * a client-asserted level would let a stale tab preview a change that is already
 * in place and report an effect that will not happen.
 *
 * Reading the grid is a READ-level act (the screen itself is reachable at READ),
 * so this request does not ask for the right to write — a preview that needed a
 * FULL grant would leave the read-only reviewer unable to explain a proposed
 * change to whoever holds it.
 */
class PreviewMatrixRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        $set = $this->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return $set->canView('admin.access');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cells' => ['required', 'array'],
            'cells.*.feature' => ['required', 'string', 'max:96', 'exists:features,key'],
            'cells.*.role' => ['required', 'string', 'max:255', 'exists:roles,name'],
            'cells.*.to' => ['required', 'in:FULL,READ,NONE'],
        ];
    }

    /**
     * @return list<array{feature: string, role: string, to: PermissionLevel}>
     */
    public function cells(): array
    {
        return array_map(
            static fn (array $cell): array => [
                'feature' => (string) $cell['feature'],
                'role' => (string) $cell['role'],
                'to' => PermissionLevel::from((string) $cell['to']),
            ],
            (array) $this->validated('cells')
        );
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return [
            'cells' => 'Sel yang diubah',
            'cells.*.feature' => 'Fitur',
            'cells.*.role' => 'Peran',
            'cells.*.to' => 'Level baru',
        ];
    }
}
