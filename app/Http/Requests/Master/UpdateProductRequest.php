<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Access\PermissionSet;
use App\Domain\MasterData\ProductService;
use App\Http\Requests\Concerns\CatalogueMessages;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCR-4.5 — an edit of an existing product (FSD-409, FSD §2.5).
 *
 * The create screen's rules with two differences. The unique code ignores this
 * row, which is the only way a correction of a mistyped code — the master-spec
 * file has one — stays possible while a duplicate is not. And the version token
 * is required, because §2.5 puts a token on every editable record and products
 * carry one in the table: a save that arrives with somebody else's token is
 * refused with MSG-020 rather than written over their work.
 *
 * `is_active` is not on this request. Retirement is the act FSD-812 puts in
 * place of deletion, so it has its own route and its own audit action; inside a
 * general edit form it would be the version of "delete" that looks like a save.
 */
class UpdateProductRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('products', 'code')->ignore($this->product()->getKey()),
            ],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'variant' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pack_size' => ['sometimes', 'nullable', 'string', 'max:60'],
            'row_version' => ['required', 'integer', 'min:1'],
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

        $attributes = [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
        ];

        // Presence, not value: a key the form did not send is a form that did not
        // speak about that field, and ProductService::update keeps the stored one.
        foreach (['brand', 'variant', 'pack_size'] as $field) {
            if ($this->has($field)) {
                $attributes[$field] = $data[$field] ?? null;
            }
        }

        return $attributes;
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
            'code' => 'Kode produk',
            'name' => 'Nama produk',
            'brand' => 'Merek',
            'variant' => 'Varian',
            'pack_size' => 'Ukuran kemasan',
            'row_version' => 'Versi data',
        ];
    }
}
