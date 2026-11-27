import AppLayout from '@/Layouts/AppLayout';
import Checkbox from '@/Components/Checkbox';
import FormShell from '@/Components/FormShell';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import { Head, router, usePage } from '@inertiajs/react';

/**
 * SCR-4.3 — the specification sheet itself (FSD-404, FSD-406, FSD §5, G-12).
 *
 * One component answers three questions the register asks, and which it answers
 * is the server's decision rather than anything typed here: a blank sheet posts
 * to `store`, a draft posts to `update` with its version token, and an approved
 * document posts to `revise`. The third is the whole of G-12's correction — the
 * locked sheet is not editable and is not read-only either; it is the starting
 * point of the next version, which is why the button under it says "Buat versi
 * baru" (MSG-042's own action) instead of "Simpan". `can.revise` and
 * `can.editable` are computed from the document's status and the caller's cell
 * together, so nothing on this page has to know what a qc_supervisor is.
 *
 * What the sheet cannot be given is a number, a version, or a status of its own.
 * None of the three appears in the payload this page builds: FSD-404's code is
 * allocated by the sequence when the draft is first saved — which is why a blank
 * sheet prints "nomor diberikan saat draf disimpan" rather than a guess — and
 * §4.1 makes doc_status the approval engine's column. TSD-432's immutability and
 * the state machine own those columns; a box that let a person type them would be
 * an offer the server refuses.
 *
 * The three blocks are the document, so they are replaced whole on every save and
 * their order on screen is the order the sheet prints them in. The server answers
 * a hole in a named row under that row's own key (`sizes.1.code`), and §2.5 wants
 * the sentence beside the field rather than after a round trip — for a repeating
 * block the sentence arrives beside the cell after the round trip, because a form
 * request's per-row rules cannot run against a row count the page invents. The
 * header's own fields keep §2.5's timing: format on blur, completeness on submit.
 */
export default function Document({ screen, specification, reference, can }) {
    const isNew = specification === null;
    const locked = !isNew && can.revise;
    const editing = !isNew && can.editable;
    const readOnly = !editing && !locked && !isNew;

    const serverErrors = usePage().props.errors ?? {};

    const initial = {
        item_id: optionId(specification?.item_id),
        product_id: optionId(specification?.product_id),
        material_name: specification?.material_name ?? '',
        material_type_id: optionId(specification?.material_type_id),
        effective_date: specification?.effective_date ?? '',
        change_note: specification?.change_note ?? '',
        // A sheet that has never been opened starts with one printed row per
        // block, because the paper form does and because a block with no row at
        // all is refused: the three tables are what a specification is.
        sizes: rows(specification?.sizes, blankSize, isNew),
        parameters: rows(specification?.parameters, blankParameter, isNew),
        components: rows(specification?.components, blankComponent, isNew),
        row_version: specification?.row_version ?? 1,
    };

    const action = isNew
        ? route('spec.store')
        : locked
            ? route('spec.revise', specification.id)
            : route('spec.update', specification.id);

    return (
        <AppLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">
                            Spesifikasi Bahan Pengemas · {screen}
                        </p>
                        <h2 className="text-xl font-semibold leading-tight text-gray-800">
                            {specification?.number ?? 'SBK baru'}
                        </h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Nomor, versi, dan status dokumen tidak diketik di sini. Nomor diberikan oleh urutannya saat
                            draf pertama disimpan; versi berikutnya memakai nomor yang sama.
                        </p>
                    </div>
                    <SecondaryButton onClick={() => router.get(route('spec.index'))}>Kembali ke daftar</SecondaryButton>
                </div>
            }
        >
            <Head title={specification?.number ?? 'SBK baru'} />

            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <FormShell
                        action={action}
                        method={isNew || locked ? 'post' : 'patch'}
                        values={initial}
                        rules={headerRules}
                        order={[...Object.keys(headerRules), 'row_version']}
                        canEdit={editing || locked || isNew}
                        resetKey={`${specification?.id ?? 'new'}-${specification?.row_version ?? 1}`}
                        submitLabel={locked ? 'Buat versi baru' : isNew ? 'Simpan sebagai draf' : 'Simpan draf'}
                        conflict={
                            editing
                                ? {
                                    field: 'row_version',
                                    props: ['specification'],
                                    read: (props) => props.specification?.row_version,
                                }
                                : null
                        }
                        onCancel={() => router.get(route('spec.index'))}
                    >
                        {({ values, errors, set, fieldProps, busy }) => {
                            const cell = (block, index, key) =>
                                serverErrors[`${block}.${index}.${key}`] ?? undefined;

                            const patch = (block, index, key, value) =>
                                set(
                                    block,
                                    (values[block] ?? []).map((row, i) => (i === index ? { ...row, [key]: value } : row)),
                                );

                            const append = (block, blank) => set(block, [...(values[block] ?? []), blank()]);

                            const drop = (block, index) =>
                                set(block, (values[block] ?? []).filter((_, i) => i !== index));

                            const input = (block, index, key) => ({
                                name: `${block}.${index}.${key}`,
                                value: (values[block]?.[index]?.[key] ?? '').toString(),
                                disabled: readOnly || busy,
                                onChange: (event) => patch(block, index, key, event.target.value),
                            });

                            const choice = (block, index, key) => ({
                                ...input(block, index, key),
                                value: optionId(values[block]?.[index]?.[key]),
                            });

                            return (
                                <div className="space-y-6">
                                    {/* A refusal that is about the document rather than a box on it —
                                        MSG-042's locked sheet, answered by the button above. Read from
                                        the shared bag because FormShell only hands back its own fields. */}
                                    {serverErrors.id && (
                                        <p
                                            className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                                            role="alert"
                                        >
                                            {serverErrors.id}
                                        </p>
                                    )}

                                    <SheetIdentity specification={specification} />

                                    <section className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                                        <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-700">
                                            Identitas bahan
                                        </h3>

                                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <InputLabel value="Item bahan kemas *" htmlFor="spec-item" />
                                                <SelectInput id="spec-item" className="mt-1 block w-full" {...fieldProps('item_id')}>
                                                    <option value="">Pilih item</option>
                                                    {reference.items.map((item) => (
                                                        <option key={item.id} value={String(item.id)}>
                                                            {item.code} — {item.name}
                                                        </option>
                                                    ))}
                                                </SelectInput>
                                                <InputError message={errors.item_id} />
                                            </div>

                                            <div>
                                                <InputLabel value="Produk *" htmlFor="spec-product" />
                                                <SelectInput
                                                    id="spec-product"
                                                    className="mt-1 block w-full"
                                                    {...fieldProps('product_id')}
                                                >
                                                    <option value="">Pilih produk</option>
                                                    {reference.products.map((product) => (
                                                        <option key={product.id} value={String(product.id)}>
                                                            {product.code} — {product.name}
                                                        </option>
                                                    ))}
                                                </SelectInput>
                                                <InputError message={errors.product_id} />
                                                <p className="mt-1 text-xs text-gray-500">
                                                    Daftar ini juga berisi item dan produk yang sudah dinonaktifkan,
                                                    supaya versi baru dari spesifikasi lama tetap bisa menyebut bahan
                                                    yang sama.
                                                </p>
                                            </div>

                                            <div>
                                                <InputLabel value="Uraian bahan *" htmlFor="spec-name" />
                                                <TextInput id="spec-name" className="mt-1 block w-full" {...fieldProps('material_name')} />
                                                <InputError message={errors.material_name} />
                                            </div>

                                            <div>
                                                <InputLabel value="Jenis bahan" htmlFor="spec-material" />
                                                <SelectInput
                                                    id="spec-material"
                                                    className="mt-1 block w-full"
                                                    {...fieldProps('material_type_id')}
                                                >
                                                    <option value="">Belum ditentukan</option>
                                                    {reference.material_types.map((material) => (
                                                        <option key={material.id} value={String(material.id)}>
                                                            {material.name}
                                                        </option>
                                                    ))}
                                                </SelectInput>
                                                <InputError message={errors.material_type_id} />
                                            </div>

                                            <div>
                                                <InputLabel value="Tanggal efektif" htmlFor="spec-effective" />
                                                <TextInput
                                                    id="spec-effective"
                                                    type="date"
                                                    className="mt-1 block w-full"
                                                    {...fieldProps('effective_date')}
                                                />
                                                <InputError message={errors.effective_date} />
                                                <p className="mt-1 text-xs text-gray-500">
                                                    Dikosongkan berarti tanggalnya ditetapkan saat dokumen ditutup.
                                                </p>
                                            </div>

                                            <div>
                                                <InputLabel value="Catatan perubahan" htmlFor="spec-change" />
                                                <TextInput id="spec-change" className="mt-1 block w-full" {...fieldProps('change_note')} />
                                                <InputError message={errors.change_note} />
                                            </div>
                                        </div>
                                    </section>

                                    <Block
                                        title="Ukuran"
                                        hint="Satuan dan metode dipilih dari daftar referensi; nominal, minimum, dan maksimum memakai satuan yang sama."
                                        empty={serverErrors.sizes}
                                        rows={values.sizes ?? []}
                                        readOnly={readOnly || busy}
                                        onAdd={() => append('sizes', blankSize)}
                                        onDrop={(index) => drop('sizes', index)}
                                    >
                                        {(row, index) => (
                                            <>
                                                <Cell label="Kode *" error={cell('sizes', index, 'code')}>
                                                    <TextInput className="w-28 font-mono text-xs" {...input('sizes', index, 'code')} />
                                                </Cell>
                                                <Cell label="Nama ukuran" error={cell('sizes', index, 'label')} wide>
                                                    <TextInput className="w-full" {...input('sizes', index, 'label')} />
                                                </Cell>
                                                <Cell label="Nominal" error={cell('sizes', index, 'nominal')}>
                                                    <TextInput className="w-24" {...input('sizes', index, 'nominal')} />
                                                </Cell>
                                                <Cell label="Min" error={cell('sizes', index, 'min')}>
                                                    <TextInput className="w-24" {...input('sizes', index, 'min')} />
                                                </Cell>
                                                <Cell label="Max" error={cell('sizes', index, 'max')}>
                                                    <TextInput className="w-24" {...input('sizes', index, 'max')} />
                                                </Cell>
                                                <Cell label="Satuan" error={cell('sizes', index, 'unit_id')}>
                                                    <SelectInput className="w-32" {...choice('sizes', index, 'unit_id')}>
                                                        <option value="">—</option>
                                                        {reference.units.map((unit) => (
                                                            <option key={unit.id} value={String(unit.id)}>
                                                                {unit.symbol} ({unit.name})
                                                            </option>
                                                        ))}
                                                    </SelectInput>
                                                </Cell>
                                                <Cell label="Metode" error={cell('sizes', index, 'method_id')} wide>
                                                    <SelectInput className="w-48" {...choice('sizes', index, 'method_id')}>
                                                        <option value="">Belum ditentukan</option>
                                                        {reference.methods.map((method) => (
                                                            <option key={method.id} value={String(method.id)}>
                                                                {method.name}
                                                            </option>
                                                        ))}
                                                    </SelectInput>
                                                </Cell>
                                            </>
                                        )}
                                    </Block>

                                    <Block
                                        title="Parameter uji"
                                        hint="Grup parameter mengikuti urutan daftar referensi; nilai numerik diisi batasnya, nilai deskriptif diisi kolom spesifikasi."
                                        empty={serverErrors.parameters}
                                        rows={values.parameters ?? []}
                                        readOnly={readOnly || busy}
                                        onAdd={() => append('parameters', blankParameter)}
                                        onDrop={(index) => drop('parameters', index)}
                                    >
                                        {(row, index) => (
                                            <>
                                                <Cell label="Grup *" error={cell('parameters', index, 'group_id')}>
                                                    <SelectInput className="w-40" {...choice('parameters', index, 'group_id')}>
                                                        <option value="">Pilih grup</option>
                                                        {reference.parameter_groups.map((group) => (
                                                            <option key={group.id} value={String(group.id)}>
                                                                {group.name}
                                                            </option>
                                                        ))}
                                                    </SelectInput>
                                                </Cell>
                                                <Cell label="Parameter *" error={cell('parameters', index, 'parameter')} wide>
                                                    <TextInput className="w-full" {...input('parameters', index, 'parameter')} />
                                                </Cell>
                                                <Cell label="Spesifikasi" error={cell('parameters', index, 'spec_text')} wide>
                                                    <TextInput className="w-full" {...input('parameters', index, 'spec_text')} />
                                                </Cell>
                                                <Cell label="Min" error={cell('parameters', index, 'min')}>
                                                    <TextInput className="w-24" {...input('parameters', index, 'min')} />
                                                </Cell>
                                                <Cell label="Max" error={cell('parameters', index, 'max')}>
                                                    <TextInput className="w-24" {...input('parameters', index, 'max')} />
                                                </Cell>
                                                <Cell label="Satuan" error={cell('parameters', index, 'unit_id')}>
                                                    <SelectInput className="w-32" {...choice('parameters', index, 'unit_id')}>
                                                        <option value="">—</option>
                                                        {reference.units.map((unit) => (
                                                            <option key={unit.id} value={String(unit.id)}>
                                                                {unit.symbol} ({unit.name})
                                                            </option>
                                                        ))}
                                                    </SelectInput>
                                                </Cell>
                                                <Cell label="Metode" error={cell('parameters', index, 'method_id')} wide>
                                                    <SelectInput className="w-48" {...choice('parameters', index, 'method_id')}>
                                                        <option value="">Belum ditentukan</option>
                                                        {reference.methods.map((method) => (
                                                            <option key={method.id} value={String(method.id)}>
                                                                {method.name}
                                                            </option>
                                                        ))}
                                                    </SelectInput>
                                                </Cell>
                                                <Cell label="Kriteria lulus" error={cell('parameters', index, 'criteria')} wide>
                                                    <TextInput className="w-full" {...input('parameters', index, 'criteria')} />
                                                </Cell>
                                                <Cell label="Numerik" error={cell('parameters', index, 'is_numeric')}>
                                                    <Checkbox
                                                        name={`parameters.${index}.is_numeric`}
                                                        checked={Boolean(values.parameters?.[index]?.is_numeric)}
                                                        disabled={readOnly || busy}
                                                        onChange={(event) =>
                                                            patch('parameters', index, 'is_numeric', event.target.checked)
                                                        }
                                                    />
                                                </Cell>
                                            </>
                                        )}
                                    </Block>

                                    <Block
                                        title="Komposisi komponen"
                                        hint="Persentase ditulis dalam angka, bukan huruf, dan ikut jumlah bloknya — bukan angka yang diketik ulang di tempat lain."
                                        empty={serverErrors.components}
                                        rows={values.components ?? []}
                                        readOnly={readOnly || busy}
                                        onAdd={() => append('components', blankComponent)}
                                        onDrop={(index) => drop('components', index)}
                                    >
                                        {(row, index) => (
                                            <>
                                                <Cell label="Komponen *" error={cell('components', index, 'component')} wide>
                                                    <TextInput className="w-full" {...input('components', index, 'component')} />
                                                </Cell>
                                                <Cell label="Bahan *" error={cell('components', index, 'material')} wide>
                                                    <TextInput className="w-full" {...input('components', index, 'material')} />
                                                </Cell>
                                                <Cell label="% *" error={cell('components', index, 'percent')}>
                                                    <TextInput className="w-24" {...input('components', index, 'percent')} />
                                                </Cell>
                                                <Cell label="Catatan" error={cell('components', index, 'note')} wide>
                                                    <TextInput className="w-full" {...input('components', index, 'note')} />
                                                </Cell>
                                            </>
                                        )}
                                    </Block>

                                    <SignatureRow specification={specification} />
                                </div>
                            );
                        }}
                    </FormShell>
                </div>
            </div>
        </AppLayout>
    );
}

/**
 * §5's sheet prints the number, the version, and the document's own state before
 * any of its tables. All three are read-only here for the same reason none of
 * them is in the payload: they are the sequence's and the state machine's answer.
 */
function SheetIdentity({ specification }) {
    return (
        <section className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <dl className="grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt className="text-xs uppercase tracking-wide text-gray-500">Nomor SBK</dt>
                    <dd className="mt-1 font-mono text-base text-gray-900">
                        {specification?.number ?? 'nomor diberikan saat draf disimpan'}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs uppercase tracking-wide text-gray-500">Versi</dt>
                    <dd className="mt-1 text-base text-gray-900">{specification ? `v${specification.version}` : 'v1'}</dd>
                </div>
                <div>
                    <dt className="text-xs uppercase tracking-wide text-gray-500">Status dokumen</dt>
                    <dd className="mt-1">
                        {specification ? (
                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                                {specification.doc_status_label}
                            </span>
                        ) : (
                            <span className="text-sm text-gray-500">belum disimpan</span>
                        )}
                    </dd>
                </div>
            </dl>
        </section>
    );
}

/**
 * FSD §5's signature row. Only "Dibuat oleh" can be filled by a draft: the other
 * two are the approval engine's recorded decisions (FSD §4.5), and the server
 * hands their absence rather than a name it cannot justify — printing the author
 * under "Disetujui oleh" would be a guess dressed as a signature block.
 */
function SignatureRow({ specification }) {
    const chips = [
        { label: 'Dibuat oleh', holder: specification?.author, absent: 'nama pembuat datang dari akun yang menyimpannya' },
        { label: 'Direview oleh', holder: specification?.reviewer, absent: 'belum ada catatan persetujuan' },
        { label: 'Disetujui oleh', holder: specification?.approver, absent: 'belum ada catatan persetujuan' },
    ];

    return (
        <section className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-700">Tanda tangan</h3>

            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                {chips.map((chip) => (
                    <div key={chip.label} className="rounded-md border border-gray-200 p-3">
                        <p className="text-xs uppercase tracking-wide text-gray-500">{chip.label}</p>
                        {chip.holder ? (
                            <>
                                <p className="mt-1 text-sm font-medium text-gray-900">{chip.holder.name}</p>
                                <p className="text-xs text-gray-600">{chip.holder.role ?? '—'}</p>
                                <p className="mt-1 font-mono text-xs text-gray-500">{chip.holder.date}</p>
                            </>
                        ) : (
                            <p className="mt-1 text-sm text-gray-500">{chip.absent}</p>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

/**
 * One printed block of the sheet: its rows, a row count the person controls, and
 * the refusal of an empty block beside its heading rather than under the last
 * cell — the requirement that a named block carry a row is about the block.
 */
function Block({ title, hint, empty, rows, readOnly, onAdd, onDrop, children }) {
    return (
        <section className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-700">{title}</h3>
                    <p className="mt-1 text-xs text-gray-500">{hint}</p>
                </div>
                {!readOnly && (
                    <SecondaryButton type="button" onClick={onAdd}>
                        Tambah baris
                    </SecondaryButton>
                )}
            </div>

            {empty && (
                <p className="mt-3 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
                    {empty}
                </p>
            )}

            <div className="mt-4 space-y-3">
                {rows.length === 0 && (
                    <p className="rounded-md border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500">
                        Belum ada baris. {readOnly ? '' : 'Gunakan "Tambah baris" untuk mengisi blok ini.'}
                    </p>
                )}

                {rows.map((row, index) => (
                    <div key={`${title}-${index}`} className="rounded-md border border-gray-100 bg-gray-50 p-3">
                        <div className="flex flex-wrap items-end gap-3">
                            <span className="w-6 text-xs text-gray-400">{index + 1}.</span>
                            {children(row, index)}
                            {!readOnly && (
                                <button
                                    type="button"
                                    onClick={() => onDrop(index)}
                                    className="text-xs text-gray-500 underline hover:text-red-700"
                                >
                                    Hapus baris
                                </button>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function Cell({ label, error, wide = false, children }) {
    return (
        <label className={`block ${wide ? 'min-w-48 flex-1' : ''}`}>
            <span className="block text-xs text-gray-600">{label}</span>
            <span className="mt-1 block">{children}</span>
            {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
        </label>
    );
}

const headerRules = {
    item_id: { required: 'Item bahan kemas wajib dipilih.' },
    product_id: { required: 'Produk wajib dipilih.' },
    material_name: {
        required: 'Uraian bahan wajib diisi.',
        format: (value) => (String(value).length <= 160 ? null : 'Uraian bahan maksimal 160 karakter.'),
    },
    effective_date: {
        format: (value) => (/^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? null : 'Tanggal efektif ditulis YYYY-MM-DD.'),
    },
    change_note: {
        format: (value) => (String(value).length <= 500 ? null : 'Catatan perubahan maksimal 500 karakter.'),
    },
};

// A select holds a string, and an id the server never sent has to arrive as the
// empty choice rather than as "0" or "null".
function optionId(value) {
    return value === null || value === undefined ? '' : String(value);
}

function blankSize() {
    return { code: '', label: '', nominal: '', min: '', max: '', unit_id: '', method_id: '' };
}

function blankParameter() {
    return {
        group_id: '',
        parameter: '',
        spec_text: '',
        min: '',
        max: '',
        unit_id: '',
        method_id: '',
        criteria: '',
        is_numeric: false,
    };
}

function blankComponent() {
    return { component: '', material: '', percent: '', note: '' };
}

// The numeric cells of a specification sheet are DECIMAL(12,4) columns, and MySQL
// hands them back as "125.0000". A box under the label "Nominal" that reads
// 125.0000 asks the person whether the sheet means four places, and a percent
// composed to 35 prints as 35.0000 on paper it never says. The column keeps its
// four places — the save sends the same number either way — and only the echo is
// trimmed. Ids are excluded: a unit_id is a key, not a measured quantity.
const NUMERIC_CELLS = new Set(['nominal', 'min', 'max', 'percent']);

function echoNumber(value) {
    const text = String(value);

    if (!text.includes('.')) {
        return text;
    }

    return text.replace(/0+$/, '').replace(/\.$/, '') || '0';
}

/**
 * Every column of a stored row has to be present as a key or the input holding it
 * turns uncontrolled, and null is what an unset numeric column arrives as — so a
 * stored row is shaped through its block's own blank rather than used as it came.
 */
function rows(stored, blank, isNew) {
    if (isNew) {
        return [blank()];
    }

    return (stored ?? []).map((row) => {
        const shaped = blank();

        Object.keys(shaped).forEach((key) => {
            if (row[key] === null || row[key] === undefined) {
                return;
            }

            shaped[key] = NUMERIC_CELLS.has(key) ? echoNumber(row[key]) : row[key];
        });

        return shaped;
    });
}
