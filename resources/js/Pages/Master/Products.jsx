import AppLayout from '@/Layouts/AppLayout';
import DataTable from '@/Components/DataTable';
import Dropdown from '@/Components/Dropdown';
import FormShell from '@/Components/FormShell';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * SCR-4.5 — the product master (FSD-409, FSD-812).
 *
 * A product is the thing a specification is written about, not the pack it is
 * written on: the same brand moves between items over a year, which is why the
 * two lists are separate screens and why this one has no category picker.
 *
 * FSD §5 gives this screen a URL and no column table, so the six columns here are
 * the fields TSD §3.3.2 put in the table plus the status FSD-812 makes the
 * mechanism: Kode, Nama produk, Merek, Varian, Ukuran kemasan, Status. The three
 * descriptive ones are nullable in the schema because the client's own records
 * leave them out, and an absent value is shown as "belum dicatat" rather than as
 * a blank box (§2.2 keeps a screen from making a person guess which of the two it
 * was).
 *
 * Search covers Kode and Nama produk only — the two identity columns, and the
 * same promise SCR-4.2 makes. Merek, Varian and Ukuran kemasan are printed but
 * not searched, because widening a search to every label on the row is a result
 * count nobody can predict. The choice is recorded in the README as one of the
 * places §2.4 had to fill a gap §5 left.
 *
 * There is no Hapus in the row menu and no delete route behind it. FSD-812 names
 * deactivating as the act for a product, and while no table in the schema can
 * name a product the reference test G-13 asks for cannot be computed — so the
 * destructive verb is withheld rather than shipped unguarded.
 */
export default function Products({ screen, items, filters, columns, can }) {
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
                        <h2 className="text-xl font-semibold leading-tight text-gray-800">Master produk</h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Nama produk yang dipakai spesifikasi bahan pengemas dan tinjauan desain. Produk tidak
                            pernah dihapus; yang berhenti dipakai dinonaktifkan dan tetap terbaca di catatan lama.
                        </p>
                    </div>
                    {can.write && <PrimaryButton onClick={() => setCreating(true)}>Tambah produk</PrimaryButton>}
                </div>
            }
        >
            <Head title="Master produk" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <DataTable
                        screen={screen}
                        items={items}
                        only={['items', 'filters']}
                        filters={filters}
                        meta={columns}
                        searchPlaceholder="Cari kode atau nama produk…"
                        filterFields={[
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
                                label: 'Nama produk',
                                sortable: columns.sortable.includes('name'),
                                render: (row) => <span className="font-medium text-gray-900">{row.name}</span>,
                            },
                            {
                                key: 'brand',
                                label: 'Merek',
                                render: (row) => row.brand ?? <NotRecorded />,
                            },
                            {
                                key: 'variant',
                                label: 'Varian',
                                render: (row) => row.variant ?? <NotRecorded />,
                            },
                            {
                                key: 'pack_size',
                                label: 'Ukuran kemasan',
                                render: (row) => row.pack_size ?? <NotRecorded />,
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
                    <h3 className="text-lg font-medium text-gray-900">Tambah produk</h3>
                    <p className="mt-1 text-sm text-gray-600">
                        Kode adalah identitas yang tercetak, jadi tidak boleh sama dengan produk lain — bahkan dengan
                        huruf kecil berbeda.
                    </p>

                    <div className="mt-6">
                        <ProductForm
                            row={{
                                code: '',
                                name: '',
                                brand: '',
                                variant: '',
                                pack_size: '',
                                row_version: 1,
                            }}
                            action={route('master.products.store')}
                            method="post"
                            onSaved={() => setCreating(false)}
                        />
                    </div>
                </div>
            </Modal>

            <Modal show={Boolean(editing)} onClose={() => setEditing(null)} maxWidth="2xl">
                {editing && (
                    <div className="p-6">
                        <h3 className="text-lg font-medium text-gray-900">Ubah produk</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            Mengubah merek, varian, atau ukuran kemasan tidak menulis ulang spesifikasi yang sudah ada:
                            setiap catatan tetap menyimpan teks yang dipakai saat ia dibuat.
                        </p>

                        <div className="mt-6">
                            <ProductForm
                                row={editing}
                                action={route('master.products.update', editing.id)}
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

function NotRecorded() {
    return <span className="text-gray-400">belum dicatat</span>;
}

function StatusChip({ row }) {
    return row.is_active ? (
        <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800">Aktif</span>
    ) : (
        <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-700">Nonaktif</span>
    );
}

/**
 * §2.4's single ⋯ per row, and the two acts FSD-812 leaves open: change the
 * record, or stop it being offered. Nothing here re-derives permission — the menu
 * only appears because the server said this account may write (TSD-704).
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
            route('master.products.active', row.id),
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
            <p className="mt-1 text-sm text-gray-600">
                {activating
                    ? 'Produk ini akan muncul lagi sebagai pilihan saat catatan baru dibuat.'
                    : 'Produk ini berhenti muncul di pilihan untuk catatan baru. Barisnya tidak dihapus: spesifikasi dan tinjauan desain yang menyebutnya tetap terbaca dan tetap tercetak.'}
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

function ProductForm({ row, action, method, onSaved }) {
    const rules = {
        code: {
            required: 'Kode produk wajib diisi.',
            format: (value) =>
                String(value).trim().length > 0 && String(value).length <= 32
                    ? null
                    : 'Kode produk maksimal 32 karakter.',
        },
        name: {
            required: 'Nama produk wajib diisi.',
            format: (value) =>
                String(value).trim().length >= 2 && String(value).length <= 160
                    ? null
                    : 'Nama produk harus antara 2 dan 160 karakter.',
        },
        brand: {
            format: (value) => (String(value).trim().length <= 120 ? null : 'Merek maksimal 120 karakter.'),
        },
        variant: {
            format: (value) => (String(value).trim().length <= 120 ? null : 'Varian maksimal 120 karakter.'),
        },
        pack_size: {
            format: (value) =>
                String(value).trim().length <= 60 ? null : 'Ukuran kemasan maksimal 60 karakter.',
        },
    };

    // A row read back from the server holds nulls; a form box holds a string.
    // Leaving the null in the values would render it as the text "null".
    const text = (value) => (value === null || value === undefined ? '' : String(value));

    return (
        <FormShell
            action={action}
            method={method}
            values={{
                code: text(row.code),
                name: text(row.name),
                brand: text(row.brand),
                variant: text(row.variant),
                pack_size: text(row.pack_size),
                row_version: row.row_version,
            }}
            rules={rules}
            order={['code', 'name', 'brand', 'variant', 'pack_size', 'row_version']}
            resetKey={`${row.id ?? 'new'}-${row.row_version}`}
            submitLabel="Simpan produk"
            onCancel={onSaved}
            onSuccess={onSaved}
        >
            {({ errors, fieldProps }) => (
                <div className="space-y-4">
                    {/* MSG-020 comes back under the version token, which is not a box on this form. */}
                    {errors.row_version && (
                        <p
                            className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                            role="alert"
                        >
                            {errors.row_version}
                        </p>
                    )}

                    <p className="text-xs text-gray-500">
                        Tanda * wajib diisi. Merek, varian, dan ukuran kemasan boleh kosong — rekaman klien sendiri
                        memang sering belum memilikinya, dan kosong di sini berarti belum dicatat, bukan salah isi.
                    </p>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Kode produk *" htmlFor="product-code" />
                            <TextInput id="product-code" className="mt-1 block w-full" {...fieldProps('code')} />
                            <InputError message={errors.code} />
                        </div>

                        <div>
                            <InputLabel value="Nama produk *" htmlFor="product-name" />
                            <TextInput id="product-name" className="mt-1 block w-full" {...fieldProps('name')} />
                            <InputError message={errors.name} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Merek" htmlFor="product-brand" />
                            <TextInput id="product-brand" className="mt-1 block w-full" {...fieldProps('brand')} />
                            <InputError message={errors.brand} />
                        </div>

                        <div>
                            <InputLabel value="Varian" htmlFor="product-variant" />
                            <TextInput id="product-variant" className="mt-1 block w-full" {...fieldProps('variant')} />
                            <InputError message={errors.variant} />
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Ukuran kemasan" htmlFor="product-pack-size" />
                        <TextInput
                            id="product-pack-size"
                            className="mt-1 block w-full"
                            placeholder="mis. 200 ml, 60 g, 50 pcs"
                            {...fieldProps('pack_size')}
                        />
                        <InputError message={errors.pack_size} />
                        <p className="mt-1 text-xs text-gray-500">
                            Ditulis apa adanya, seperti yang tercetak pada kemasan; tidak diurai menjadi angka dan
                            satuan.
                        </p>
                    </div>
                </div>
            )}
        </FormShell>
    );
}
