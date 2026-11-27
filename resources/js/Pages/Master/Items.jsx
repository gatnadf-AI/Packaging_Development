import AppLayout from '@/Layouts/AppLayout';
import DataTable from '@/Components/DataTable';
import Dropdown from '@/Components/Dropdown';
import FormShell from '@/Components/FormShell';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * SCR-4.2 — the packaging item list (FSD-401, FSD-402, FSD-403).
 *
 * The row says what a pack is in words. §2.2 keeps machine values off a screen,
 * so Kategori and Jenis material arrive as the joined names and Cabang arrives
 * as BranchClass's own phrase — `A` is a classification only R&D reads, and the
 * reviewed prototype printed exactly that letter.
 *
 * Seven of the eight columns the FSD's SCR-4.2 line names are here. Spesifikasi
 * is a live count of the register rows that name the item, taken from the
 * register's own helper and linked to the list it counts — FR-603's complaint
 * against the reviewed prototype was a count reading 0 while the specification
 * held 77 components, and a number that has drifted from the page behind its
 * link is that defect in a new coat. Standar is the one that stays absent: the
 * standard sheet arrives in Phase 5, and printing a 0 for a table that does not
 * exist yet is the same mistake made on purpose.
 *
 * There is no Hapus in the row menu, and there is no delete route behind it:
 * FSD-402 retires a pack and keeps every record that named it. Nonaktifkan is
 * the act that replaces deletion, which is why it is a verb of its own here
 * rather than a checkbox inside the edit form.
 */
export default function Items({ screen, items, filters, columns, reference, can }) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(null);
    const [confirming, setConfirming] = useState(null);

    return (
        <AppLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">
                            Master Data · {screen}
                        </p>
                        <h2 className="text-xl font-semibold leading-tight text-gray-800">Item kemasan</h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Setiap bahan kemas disimpan dalam satu kategori. Item tidak pernah dihapus; yang tidak
                            dipakai lagi dinonaktifkan dan tetap terbaca di catatan lama.
                        </p>
                    </div>
                    {can.write && <PrimaryButton onClick={() => setCreating(true)}>Tambah item</PrimaryButton>}
                </div>
            }
        >
            <Head title="Item kemasan" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <DataTable
                        screen={screen}
                        items={items}
                        only={['items', 'filters']}
                        filters={filters}
                        meta={columns}
                        searchPlaceholder="Cari kode atau nama item…"
                        filterFields={[
                            {
                                key: 'category',
                                label: 'Kategori',
                                // The same list the create panel offers, which is
                                // the directory's answer rather than a second
                                // query: what FSD-402 stopped offering is stopped
                                // here too, and the two screens cannot disagree.
                                options: reference.categories.map((category) => ({
                                    value: String(category.id),
                                    label: category.name,
                                })),
                            },
                            {
                                key: 'status',
                                label: 'Status',
                                options: [
                                    { value: 'active', label: 'Aktif' },
                                    { value: 'inactive', label: 'Nonaktif' },
                                ],
                            },
                        ]}
                        columns={[
                            {
                                key: 'code',
                                label: 'Kode',
                                sortable: columns.sortable.includes('code'),
                                render: (row) => <span className="font-mono text-xs text-gray-900">{row.code}</span>,
                            },
                            {
                                key: 'name',
                                label: 'Nama item',
                                sortable: columns.sortable.includes('name'),
                                render: (row) => <span className="font-medium text-gray-900">{row.name}</span>,
                            },
                            { key: 'category', label: 'Kategori' },
                            {
                                key: 'material_type',
                                label: 'Jenis material',
                                render: (row) =>
                                    row.material_type ?? <span className="text-gray-400">belum ditentukan</span>,
                            },
                            { key: 'branch_label', label: 'Cabang' },
                            {
                                key: 'specification_count',
                                label: 'Spesifikasi',
                                render: (row) => <SpecCount row={row} />,
                            },
                            { key: 'is_active', label: 'Status', render: (row) => <StatusChip row={row} /> },
                        ]}
                        rowMenu={
                            can.write
                                ? (row) => <RowMenu row={row} onEdit={setEditing} onConfirm={setConfirming} />
                                : undefined
                        }
                    />
                </div>
            </div>

            <Modal show={creating} onClose={() => setCreating(false)} maxWidth="2xl">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Tambah item</h3>
                    <p className="mt-1 text-sm text-gray-600">
                        Kode adalah identitas yang tercetak, jadi tidak boleh sama dengan item lain — bahkan dengan
                        huruf kecil berbeda.
                    </p>

                    <div className="mt-6">
                        <ItemForm
                            row={{
                                code: '',
                                name: '',
                                category_id: '',
                                material_type_id: '',
                                branch_class: '',
                                row_version: 1,
                            }}
                            reference={reference}
                            action={route('master.items.store')}
                            method="post"
                            onSaved={() => setCreating(false)}
                        />
                    </div>
                </div>
            </Modal>

            <Modal show={Boolean(editing)} onClose={() => setEditing(null)} maxWidth="2xl">
                {editing && (
                    <div className="p-6">
                        <h3 className="text-lg font-medium text-gray-900">Ubah item</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            Memindahkan item ke kategori lain tidak mengubah spesifikasi atau tugas yang sudah
                            menyebutnya; perpindahannya tercatat beserta kategori yang ditinggalkan.
                        </p>

                        <div className="mt-6">
                            <ItemForm
                                row={editing}
                                reference={reference}
                                action={route('master.items.update', editing.id)}
                                method="patch"
                                onSaved={() => setEditing(null)}
                            />
                        </div>
                    </div>
                )}
            </Modal>

            <Modal show={Boolean(confirming)} onClose={() => setConfirming(null)} maxWidth="md">
                {confirming && <Confirm row={confirming} onDone={() => setConfirming(null)} />}
            </Modal>
        </AppLayout>
    );
}

function StatusChip({ row }) {
    return row.is_active ? (
        <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800">Aktif</span>
    ) : (
        <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-700">Nonaktif</span>
    );
}

/**
 * FR-603's count, and the link is the half that makes it a count. The number is
 * asked of the register by the same query that fills the page this link opens —
 * the register filtered to exactly this item — so reading "2" and arriving at two
 * rows is one fact rather than two that can drift apart, which is what the
 * reviewed prototype got wrong when it printed 0 beside a specification holding
 * 77 components.
 *
 * The unit is "versi" rather than "dokumen" because that is what the register
 * lists: §9 shows v1 and v2 of the same number as two rows, so a figure named for
 * documents would print 1 beside a link showing 2 — the same defect, counted the
 * other way round. A zero keeps its unit and keeps its link, because §2.2 would
 * rather show a true 0 than a dash, and arriving at an empty register is how a
 * person confirms it really is empty.
 */
function SpecCount({ row }) {
    return (
        <a
            href={route('spec.index', { item: row.id })}
            className={
                row.specification_count === 0
                    ? 'text-gray-500 hover:underline'
                    : 'font-medium text-indigo-700 hover:underline'
            }
        >
            {row.specification_count} versi
        </a>
    );
}

/**
 * §2.4's single ⋯ per row, and the two acts FSD-402 leaves open: change the
 * record, or stop it being offered. Nothing here re-derives permission — the
 * menu only appears because the server said this account may write (TSD-704).
 */
function RowMenu({ row, onEdit, onConfirm }) {
    return (
        <Dropdown>
            <Dropdown.Trigger>
                <button
                    type="button"
                    className="rounded border border-gray-300 px-2 py-1 text-gray-600 hover:bg-gray-50"
                    aria-label={`Aksi untuk ${row.code}`}
                >
                    ⋯
                </button>
            </Dropdown.Trigger>

            <Dropdown.Content>
                <Dropdown.Link as="button" onClick={() => onEdit(row)}>
                    Ubah
                </Dropdown.Link>

                <Dropdown.Link
                    as="button"
                    onClick={() => onConfirm({ ...row, act: row.is_active ? 'deactivate' : 'activate' })}
                >
                    {row.is_active ? 'Nonaktifkan' : 'Aktifkan kembali'}
                </Dropdown.Link>
            </Dropdown.Content>
        </Dropdown>
    );
}

function Confirm({ row, onDone }) {
    const [busy, setBusy] = useState(false);

    const activating = row.act === 'activate';

    const run = () => {
        setBusy(true);

        router.patch(
            route('master.items.active', row.id),
            { is_active: activating ? 1 : 0, row_version: row.row_version },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    onDone();
                },
            },
        );
    };

    return (
        <div className="p-6">
            <h3 className="text-lg font-medium text-gray-900">
                {activating ? `Aktifkan kembali "${row.code}"?` : `Nonaktifkan "${row.code}"?`}
            </h3>
            <p className="mt-2 text-sm text-gray-600">
                {activating
                    ? 'Item ini akan muncul lagi di pilihan specifications dan penugasan.'
                    : 'Item ini berhenti muncul di pilihan untuk catatan baru. Barisnya tidak dihapus: spesifikasi, tugas, dan cetakan yang menyebutnya tetap terbaca dan tetap tercetak.'}
            </p>

            <div className="mt-6 flex flex-wrap gap-3">
                <PrimaryButton onClick={run} disabled={busy}>
                    {activating ? 'Aktifkan' : 'Nonaktifkan'}
                </PrimaryButton>
                <SecondaryButton onClick={onDone} disabled={busy}>
                    Batal
                </SecondaryButton>
            </div>
        </div>
    );
}

function ItemForm({ row, reference, action, method, onSaved }) {
    const rules = {
        code: {
            required: 'Kode item wajib diisi.',
            format: (value) =>
                String(value).trim().length > 0 && String(value).length <= 32
                    ? null
                    : 'Kode item maksimal 32 karakter.',
        },
        name: {
            required: 'Nama item wajib diisi.',
            format: (value) =>
                String(value).trim().length >= 2 && String(value).length <= 160
                    ? null
                    : 'Nama item harus antara 2 dan 160 karakter.',
        },
        category_id: { required: 'Kategori wajib dipilih.' },
        branch_class: { required: 'Klasifikasi cabang wajib dipilih.' },
    };

    // A select holds a string, and an id the server never sent has to arrive as
    // the empty choice rather than as "0" or "null".
    const optionId = (value) => (value === null || value === undefined ? '' : String(value));

    return (
        <FormShell
            action={action}
            method={method}
            values={{
                code: row.code,
                name: row.name,
                category_id: optionId(row.category_id),
                material_type_id: optionId(row.material_type_id),
                branch_class: row.branch_class,
                row_version: row.row_version,
            }}
            rules={rules}
            order={['code', 'name', 'category_id', 'material_type_id', 'branch_class', 'row_version']}
            resetKey={`${row.id ?? 'new'}-${row.row_version}`}
            submitLabel="Simpan item"
            onCancel={onSaved}
            onSuccess={onSaved}
        >
            {({ errors, fieldProps }) => (
                <div className="space-y-4">
                    {/* MSG-020 comes back under the version token, which is not a box on this form. */}
                    {errors.row_version && (
                        <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                           role="alert">
                            {errors.row_version}
                        </p>
                    )}

                    <p className="text-xs text-gray-500">
                        Tanda * wajib diisi. Jenis material dipilih dari daftar referensi, bukan diketik — penulisan
                        bebas menghasilkan dua baris untuk satu bahan yang sama.
                    </p>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Kode item *" htmlFor="item-code" />
                            <TextInput id="item-code" className="mt-1 block w-full" {...fieldProps('code')} />
                            <InputError message={errors.code} />
                        </div>

                        <div>
                            <InputLabel value="Nama item *" htmlFor="item-name" />
                            <TextInput id="item-name" className="mt-1 block w-full" {...fieldProps('name')} />
                            <InputError message={errors.name} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Kategori *" htmlFor="item-category" />
                            <SelectInput id="item-category" className="mt-1 block w-full" {...fieldProps('category_id')}>
                                <option value="">Pilih kategori</option>
                                {reference.categories.map((category) => (
                                    <option key={category.id} value={String(category.id)}>
                                        {category.name}
                                    </option>
                                ))}
                            </SelectInput>
                            <InputError message={errors.category_id} />
                            <p className="mt-1 text-xs text-gray-500">
                                Satu item hanya punya satu kategori; kategori yang sudah dinonaktifkan tidak
                                ditawarkan lagi.
                            </p>
                        </div>

                        <div>
                            <InputLabel value="Jenis material" htmlFor="item-material" />
                            <SelectInput
                                id="item-material"
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
                    </div>

                    <div>
                        <InputLabel value="Klasifikasi cabang *" htmlFor="item-branch" />
                        <SelectInput id="item-branch" className="mt-1 block w-full" {...fieldProps('branch_class')}>
                            <option value="">Pilih klasifikasi</option>
                            {reference.branch_classes.map((branch) => (
                                <option key={branch.value} value={branch.value}>
                                    {branch.label}
                                </option>
                            ))}
                        </SelectInput>
                        <InputError message={errors.branch_class} />
                        <p className="mt-1 text-xs text-gray-500">
                            Klasifikasi ini hanya mengisi pilihan cabang saat penugasan; cabang tetap dipilih saat
                            itu juga (FSD-403).
                        </p>
                    </div>
                </div>
            )}
        </FormShell>
    );
}
