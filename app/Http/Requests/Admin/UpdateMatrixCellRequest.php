<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Access\PermissionLevel;
use App\Domain\Access\PermissionSet;
use App\Http\Requests\Concerns\CatalogueMessages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FSD-204 — one cell of the matrix, addressed the way §3.1 addresses it.
 *
 * The cell travels as its natural key rather than as a row id: the grid is keyed
 * on (feature, role), an id would tie the URL to a row number that a re-seed can
 * move, and the pair is what an audit reader has to see anyway.
 *
 * `from` is not decoration. It is the level the screen was showing, and the
 * service puts it in the WHERE clause of the update, which is what turns a save
 * into a compare-and-set rather than an overwrite. A client that leaves it out
 * gets a validation error rather than a write that skipped the race check.
 */
class UpdateMatrixCellRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        $set = $this->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return $set->canAct('admin.access');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'feature' => ['required', 'string', 'max:96', 'exists:features,key'],
            'role' => ['required', 'string', 'max:255', 'exists:roles,name'],
            'from' => ['required', 'in:FULL,READ,NONE'],
            'to' => ['required', 'in:FULL,READ,NONE'],
        ];
    }

    public function featureKey(): string
    {
        return (string) $this->validated('feature');
    }

    public function roleSlug(): string
    {
        return (string) $this->validated('role');
    }

    public function fromLevel(): PermissionLevel
    {
        return PermissionLevel::from((string) $this->validated('from'));
    }

    public function toLevel(): PermissionLevel
    {
        return PermissionLevel::from((string) $this->validated('to'));
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return [
            'feature' => 'Fitur',
            'role' => 'Peran',
            'from' => 'Level saat ini',
            'to' => 'Level baru',
        ];
    }
}
