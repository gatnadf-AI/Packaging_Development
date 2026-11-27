<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\ProductService;
use App\Http\Requests\Concerns\CatalogueMessages;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SCR-4.5 — retiring a product, or bringing it back (FSD-812).
 *
 * One field and the version token. FSD-812 is the only rule on this screen about
 * a state change — "a product cannot be deactivated while referenced by an open
 * assignment" — and it is not checked here because nothing in this schema can yet
 * reference a product: the assignments that would answer it arrive after the
 * specification register. ProductScreenTest pins that waiting with a schema
 * tripwire rather than letting it become a forgotten sentence.
 *
 * The refusal, when it is written, belongs in ProductService next to the act, for
 * the same reason the category count lives next to the delete: a controller that
 * asks the question is a controller that can forget to.
 */
class SetProductActiveRequest extends FormRequest
{
    use CatalogueMessages;

    public function authorize(): bool
    {
        /** @var PermissionSet $set */
        $set = $this->attributes->get(PermissionSet::class) ?? PermissionSet::empty();

        return $set->canAct(ProductService::FEATURE);
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

    public function product(): Product
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product;
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
