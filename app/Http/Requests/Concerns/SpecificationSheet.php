<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * SCR-4.3 — the specification sheet as a request reads it: one header and three
 * repeating blocks (TSD §3.3.3).
 *
 * Three requests need these rules — a create, an edit of a draft, and the edit of
 * a locked document that becomes a new version — and they differ only in whether
 * a version token travels with them. Repeating the field list three times would
 * put the shape of the printed document in three places, and the day §3.3.3 gains
 * a column is the day one of them is forgotten.
 *
 * Every limit here is the migration's own column width, and every required field
 * is a NOT NULL column: FSD §2.5 wants the screen to refuse a submit with gaps,
 * and the only gaps that matter are the ones the row cannot be written with.
 *
 * The blocks must each carry at least one row, and a row they carry has to be
 * complete. Both halves come from the same reading of §3.3.3: the children
 * RESTRICT against their parent rather than cascade, which is the schema's way of
 * saying a half-written block is a document that lies — so an empty table is
 * refused the way a blank cell is, and the sheet's own "Tambah baris" is what
 * answers it. The requirement that a save not be partly applied is what makes
 * SpecificationScreenTest count rows rather than look for the missing one.
 */
trait SpecificationSheet
{
    /**
     * @return array<string, mixed>
     */
    public function sheetRules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:packaging_items,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'material_name' => ['required', 'string', 'max:160'],
            'material_type_id' => ['nullable', 'integer', 'exists:material_types,id'],
            // §4.2 stamps the effective date when a document closes, so an author
            // may leave it blank; it is not a column a draft has to carry.
            'effective_date' => ['nullable', 'date'],
            'change_note' => ['nullable', 'string', 'max:500'],

            'sizes' => ['required', 'array'],
            'sizes.*.code' => ['required', 'string', 'max:16'],
            'sizes.*.label' => ['nullable', 'string', 'max:120'],
            'sizes.*.nominal' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'sizes.*.min' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'sizes.*.max' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'sizes.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'sizes.*.method_id' => ['nullable', 'integer', 'exists:analysis_methods,id'],

            'parameters' => ['required', 'array'],
            'parameters.*.group_id' => ['required', 'integer', 'exists:parameter_groups,id'],
            'parameters.*.parameter' => ['required', 'string', 'max:160'],
            'parameters.*.spec_text' => ['nullable', 'string', 'max:500'],
            'parameters.*.min' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'parameters.*.max' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'parameters.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'parameters.*.method_id' => ['nullable', 'integer', 'exists:analysis_methods,id'],
            'parameters.*.criteria' => ['nullable', 'string', 'max:255'],
            'parameters.*.is_numeric' => ['nullable', 'boolean'],

            'components' => ['required', 'array'],
            'components.*.component' => ['required', 'string', 'max:160'],
            'components.*.material' => ['required', 'string', 'max:160'],
            'components.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'components.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The sheet, with nothing a payload has no business asserting.
     *
     * number, version and doc_status are absent because no rule above names them:
     * TSD-432 makes an allocated code immutable and §4.1 makes doc_status the
     * engine's column, so a form that sends one is answered by the sheet being
     * saved without it rather than by the value being read.
     *
     * @return array<string, mixed>
     */
    public function sheetAttributes(): array
    {
        $data = $this->validated();

        $attributes = [];

        foreach (['item_id', 'product_id', 'material_name', 'material_type_id', 'effective_date', 'change_note'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }

        foreach (['sizes', 'parameters', 'components'] as $block) {
            if (array_key_exists($block, $data)) {
                $attributes[$block] = array_values((array) $data[$block]);
            }
        }

        return $attributes;
    }

    /**
     * The words §5's SCR-4.3 layout prints beside each box, in Indonesian, for
     * MSG-031's {field} placeholder.
     *
     * @return array<string, string>
     */
    public function sheetLabels(): array
    {
        return [
            'item_id' => 'Nama bahan',
            'product_id' => 'Nama produk',
            'material_name' => 'Uraian bahan',
            'material_type_id' => 'Jenis bahan',
            'effective_date' => 'Tanggal efektif',
            'change_note' => 'Catatan perubahan',
            'sizes' => 'Tabel ukuran',
            'sizes.*.code' => 'Kode ukuran',
            'sizes.*.label' => 'Nama ukuran',
            'sizes.*.nominal' => 'Nilai nominal',
            'sizes.*.min' => 'Batas minimum',
            'sizes.*.max' => 'Batas maksimum',
            'sizes.*.unit_id' => 'Satuan',
            'sizes.*.method_id' => 'Metode analisa',
            'parameters' => 'Tabel parameter',
            'parameters.*.group_id' => 'Grup parameter',
            'parameters.*.parameter' => 'Parameter',
            'parameters.*.spec_text' => 'Spesifikasi',
            'parameters.*.min' => 'Batas minimum',
            'parameters.*.max' => 'Batas maksimum',
            'parameters.*.unit_id' => 'Satuan',
            'parameters.*.method_id' => 'Metode analisa',
            'parameters.*.criteria' => 'Kriteria lulus',
            'parameters.*.is_numeric' => 'Parameter numerik',
            'components' => 'Tabel komponen',
            'components.*.component' => 'Komponen',
            'components.*.material' => 'Bahan',
            'components.*.percent' => 'Persentase',
            'components.*.note' => 'Catatan',
        ];
    }
}
