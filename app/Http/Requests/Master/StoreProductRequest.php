<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\ProductService;
use App\Http\Requests\Concerns\CatalogueMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCR-4.5 — a new product (FSD-409).
 *
 * Only two fields are mandatory, and they are the two the documents actually
 * name: FSD-812 lists brand, product name, variant and pack size as what the
 * master maintains, and TSD §3.3.2 marks brand, variant and pack_size nullable in
 * the table. The client's own records arrive with those three missing, so a form
 * that demanded them would be a form nobody could fill on a Monday.
 *
 * No pattern is asserted on `code`, for the reason PackagingItemRecord stated in
 * Phase 2b: its shape is the client's own, FSD §2.3 lists no row for product
 * codes, and a regex invented here would refuse a real code the day the client
 * issues one that does not fit the guess. Uniqueness is asserted, because
 * §2.3 makes the code the printed identity of the record.
 */
class StoreProductRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32', Rule::unique('products', 'code')],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'brand' => ['nullable', 'string', 'max:120'],
            'variant' => ['nullable', 'string', 'max:120'],
            'pack_size' => ['nullable', 'string', 'max:60'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['code', 'name', 'brand', 'variant', 'pack_size'] as $field) {
            if (is_string($this->input($field))) {
                $trimmed[$field] = trim($this->input($field));
            }
        }

        $this->merge($trimmed);
    }

    /**
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        $data = $this->validated();

        return [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'brand' => $data['brand'] ?? null,
            'variant' => $data['variant'] ?? null,
            'pack_size' => $data['pack_size'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldLabels(): array
    {
        return [
            'code' => 'Kode produk',
            'name' => 'Nama produk',
            'brand' => 'Merek',
            'variant' => 'Varian',
            'pack_size' => 'Ukuran kemasan',
        ];
    }
}
