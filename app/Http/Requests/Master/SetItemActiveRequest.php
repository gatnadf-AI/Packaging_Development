<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\PackagingCategoryService;
use App\Http\Requests\Concerns\CatalogueMessages;
use App\Models\PackagingItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SCR-4.2 — retiring an item, or bringing it back.
 *
 * Its own request because it is the verb FSD-402 puts in place of a delete: the
 * row survives, the picker stops offering it, and every record that named it
 * keeps reading. One field and the version token is the whole of what the act
 * changes, so the request validates nothing else and the confirmation the screen
 * asks for is drawn over this route rather than carried as a field in it — the
 * refusal that matters is the one the row's own history makes, not a checkbox.
 */
class SetItemActiveRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
            'row_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function wantsActive(): bool
    {
        return (bool) $this->validated()['is_active'];
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
            'is_active' => 'Status aktif',
            'row_version' => 'Versi data',
        ];
    }
}
