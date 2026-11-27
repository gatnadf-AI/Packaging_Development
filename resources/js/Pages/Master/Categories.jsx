import AppLayout from '@/Layouts/AppLayout';
import DangerButton from '@/Components/DangerButton';
import DataTable from '@/Components/DataTable';
import Dropdown from '@/Components/Dropdown';
import FormShell from '@/Components/FormShell';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * SCR-4.1 — the packaging category list (FSD-401).
 *
 * Three columns and a count. The count is the reason the screen is worth
 * writing down: FR-603's complaint against the reviewed prototype was a table
 * that read 0 while the data held rows, and this one is drawn from
 * PackagingCategoryService::itemCountColumn() — the same subquery the delete
 * refusal quotes, so the number beside a category and the number that stops you
 * deleting it are one answer (TSD §3.5).
 *
 * The row menu hides Hapus for a category that has items under it. §4's guard
 * note says the rule is server-side and the interface additionally does not
 * invite the failure, and the live count is already in the row — so offering a
 * button that would be refused would be a second, worse version of the same
 * number. Retiring is what the menu offers instead, because G-13 names it as
 * the alternative rather than as a consolation.
 */
export default function Categories({ screen, categories, filters, columns, can }) {
    const { errors } = usePage().props;
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
                        <h2 className="text-xl font-semibold leading-tight text-gray-800">Kategori kemasan</h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Kelompok bahan kemas yang dipakai laporan dan spesifikasi. Nama boleh diubah; selama masih
                            ada item di dalamnya, kategorinya tidak bisa dihapus — nonaktifkan saja.
                        </p>
                    </div>
                    {can.write && <PrimaryButton onClick={() => setCreating(true)}>Tambah kategori</PrimaryButton>}
                </div>
            }
        >
            <Head title="Kategori kemasan" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {(errors.id || errors.row_version) && (
                        <p className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                           role="alert">
                            {errors.id ?? errors.row_version}
                        </p>
                    )}

                    <DataTable
                        screen={screen}
                        items={categories}
                        only={['categories', 'filters']}
                        filters={filters}
                        meta={columns}
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
                                key: 'name',
                                label: 'Kategori',
                                sortable: columns.sortable.includes('name'),
                                render: (row) => <span className="font-medium text-gray-900">{row.name}</span>,
                            },
                            {
                                key: 'description',
                                label: 'Keterangan',
                                render: (row) =>
                                    row.description ? (
                                        <span className="text-gray-700">{row.description}</span>
                                    ) : (
                                        <span className="text-gray-400">—</span>
                                    ),
                            },
                            {
                                key: 'item_count',
                                label: 'Item',
                                render: (row) => <ItemCount count={row.item_count} />,
                            },
                            { key: 'is_active', label: 'Status', render: (row) => <StatusChip row={row} /> },
                        ]}
                        rowMenu={
                            can.write
                                ? (row) => (
                                    <RowMenu
                                        row={row}
                                        onEdit={setEditing}
                                        onConfirm={setConfirming}
                                    />
                                )
                                : undefined
                        }
                    />
                </div>
            </div>

            <Modal show={creating} onClose={() => setCreating(false)} maxWidth="lg">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Tambah kategori</h3>
                    <p className="mt-1 text-sm text-gray-600">
                        Nama kategori dipakai laporan dan spesifikasi, jadi tidak boleh sama dengan kategori lain
                        meskipun hanya berbeda spasi.
                    </p>

                    <div className="mt-6">
                        <CategoryForm
                            row={{ name: '', description: '', is_active: true, row_version: 1 }}
                            action={route('master.categories.store')}
                            method="post"
                            onSaved={() => setCreating(false)}
                        />
                    </div>
                </div>
            </Modal>

            <Modal show={Boolean(editing)} onClose={() => setEditing(null)} maxWidth="lg">
                {editing && (
                    <div className="p-6">
                        <h3 className="text-lg font-medium text-gray-900">Ubah kategori</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            Mengubah nama tidak memindahkan item mana pun — {editing.item_count} item tetap berada di
                            kategori ini.
                        </p>

                        <div className="mt-6">
                            <CategoryForm
                                row={editing}
                                action={route('master.categories.update', editing.id)}
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

/**
 * FR-603: a zero that means something is shown as the number with its unit, not
 * as a dash. The dash is what the prototype used to print for a count it had not
 * looked up, and on this screen a 0 is a fact worth reading — it is the category
 * you are allowed to delete.
 */
function ItemCount({ count }) {
    return (
        <span className={count === 0 ? 'text-gray-500' : 'font-medium text-gray-900'}>
            {count} item
        </span>
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
 * §2.4's single ⋯ per row, drawn from the row's own live count: an empty
 * category can be deleted, a filled one can only be retired. Both halves stay
 * server-side decisions — this is the interface not inviting a failure it cannot
 * prevent, and the refusal sentence is MSG-070 with a number asked again at the
 * moment of the delete (TSD-520).
 */
function RowMenu({ row, onEdit, onConfirm }) {
    return (
        <Dropdown>
            <Dropdown.Trigger>
                <button
                    type="button"
                    className="rounded border border-gray-300 px-2 py-1 text-gray-600 hover:bg-gray-50"
                    aria-label={`Aksi untuk ${row.name}`}
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

                {row.item_count === 0 && (
                    <Dropdown.Link as="button" onClick={() => onConfirm({ ...row, act: 'delete' })}>
                        Hapus
                    </Dropdown.Link>
                )}
            </Dropdown.Content>
        </Dropdown>
    );
}

function Confirm({ row, onDone }) {
    const [busy, setBusy] = useState(false);

    const run = () => {
        setBusy(true);

        if (row.act === 'delete') {
            router.delete(route('master.categories.destroy', row.id), {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    onDone();
                },
            });

            return;
        }

        router.patch(
            route('master.categories.update', row.id),
            { name: row.name, description: row.description ?? '', is_active: row.act === 'activate' ? 1 : 0, row_version: row.row_version },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    onDone();
                },
            },
        );
    };

    const texts = {
        deactivate: [
            `Nonaktifkan "${row.name}"?`,
            `Kategori ini tidak lagi muncul di pilihan saat item baru dibuat, tetapi ${row.item_count} item yang sudah ada di dalamnya tetap menunjuk ke sini dan tetap tercetak seperti sekarang.`,
        ],
        activate: [`Aktifkan kembali "${row.name}"?`, 'Kategori ini akan muncul lagi di pilihan untuk item baru.'],
        delete: [
            `Hapus "${row.name}"?`,
            'Hanya kategori kosong yang bisa dihapus. Riwayat penghapusannya tercatat, jadi tindakan ini tidak bisa dibalik dari layar ini.',
        ],
    };

    const [title, body] = texts[row.act];

    return (
        <div className="p-6">
            <h3 className="text-lg font-medium text-gray-900">{title}</h3>
            <p className="mt-2 text-sm text-gray-600">{body}</p>

            <div className="mt-6 flex flex-wrap gap-3">
                {row.act === 'delete' ? (
                    <DangerButton onClick={run} disabled={busy}>
                        Hapus
                    </DangerButton>
                ) : (
                    <PrimaryButton onClick={run} disabled={busy}>
                        Simpan
                    </PrimaryButton>
                )}
                <SecondaryButton onClick={onDone} disabled={busy}>
                    Batal
                </SecondaryButton>
            </div>
        </div>
    );
}

function CategoryForm({ row, action, method, onSaved }) {
    const rules = {
        name: {
            required: 'Nama kategori wajib diisi.',
            format: (value) =>
                String(value).trim().length >= 2 && String(value).length <= 120
                    ? null
                    : 'Nama kategori harus antara 2 dan 120 karakter.',
        },
        description: {
            format: (value) => (String(value ?? '').length <= 500 ? null : 'Keterangan maksimal 500 karakter.'),
        },
    };

    return (
        <FormShell
            action={action}
            method={method}
            values={{
                name: row.name,
                description: row.description ?? '',
                is_active: Boolean(row.is_active),
                row_version: row.row_version,
            }}
            rules={rules}
            order={['name', 'description', 'row_version']}
            submitProps={(values) => ({ is_active: values.is_active ? 1 : 0 })}
            submitLabel="Simpan kategori"
            onCancel={onSaved}
            onSuccess={onSaved}
            resetKey={`${row.id ?? 'new'}-${row.row_version}`}
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
                        Tanda * wajib diisi. Nama dibandingkan tanpa spasi di awal atau akhir.
                    </p>

                    <div>
                        <InputLabel value="Nama kategori *" htmlFor="category-name" />
                        <TextInput id="category-name" className="mt-1 block w-full" {...fieldProps('name')} />
                        <InputError message={errors.name} />
                    </div>

                    <div>
                        <InputLabel value="Keterangan" htmlFor="category-description" />
                        <textarea
                            id="category-description"
                            rows={3}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            {...fieldProps('description')}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <p className="text-xs text-gray-500">
                        Status aktif diubah dari menu baris, karena itulah tindakan yang menggantikan penghapusan.
                    </p>
                </div>
            )}
        </FormShell>
    );
}
