import AppLayout from '@/Layouts/AppLayout';
import Checkbox from '@/Components/Checkbox';
import DataTable from '@/Components/DataTable';
import Dropdown from '@/Components/Dropdown';
import FormShell from '@/Components/FormShell';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import DepartmentPanel from '@/Pages/Admin/Partials/DepartmentPanel';
import { moment } from '@/Support/format';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

/**
 * SCR-2.1 — the account directory.
 *
 * The screen is the FSD §2.4 contract wearing the data of §2.2.1: everything
 * that behaves like a list (search floor, chips, one-column sorting, the page
 * sizes and their memory) is DataTable's, and what is left here is the three
 * things only this screen owns — which columns the account record shows, the row
 * menu FSD-201 describes, and the department panel FSD-206 puts on this screen
 * rather than on one of its own.
 *
 * The per-row `can_*` flags come from the server (TSD-704). Nothing here
 * re-derives them from a role name, which is how the reviewed prototype ended up
 * offering a button its route would refuse.
 */
export default function Users({ screen, users, filters, columns, reference, can }) {
    const [creating, setCreating] = useState(false);

    const activeDepartments = reference.departments.filter((department) => department.is_active);

    return (
        <AppLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">
                            User Management · {screen}
                        </p>
                        <h2 className="text-xl font-semibold leading-tight text-gray-800">
                            Manajemen akun
                        </h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Daftar akun, departemen, dan peran yang dipegang setiap orang.
                        </p>
                    </div>
                    {can.create && (
                        <PrimaryButton onClick={() => setCreating(true)}>Tambah akun</PrimaryButton>
                    )}
                </div>
            }
        >
            <Head title="Manajemen akun" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <DataTable
                        screen={screen}
                        items={users}
                        only={['users', 'filters']}
                        filters={filters}
                        meta={columns}
                        filterFields={[
                            {
                                key: 'department',
                                label: 'Departemen',
                                options: reference.departments.map((department) => ({
                                    value: String(department.id),
                                    label: department.name,
                                })),
                            },
                            {
                                key: 'role',
                                label: 'Peran',
                                options: reference.roles.map((role) => ({ value: role.slug, label: role.label })),
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
                                key: 'name',
                                label: 'Nama',
                                sortable: columns.sortable.includes('name'),
                                render: (row) => (
                                    <a href={route('admin.users.show', row.id)} className="font-medium text-indigo-700 hover:underline">
                                        {row.name}
                                    </a>
                                ),
                            },
                            { key: 'email', label: 'Email', sortable: columns.sortable.includes('email') },
                            { key: 'department', label: 'Departemen' },
                            {
                                key: 'roles',
                                label: 'Peran',
                                render: (row) =>
                                    row.roles.length === 0 ? (
                                        <span className="text-gray-400">tanpa peran</span>
                                    ) : (
                                        <span className="flex flex-wrap gap-1">
                                            {row.roles.map((role) => (
                                                <span
                                                    key={role.slug}
                                                    className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700"
                                                >
                                                    {role.label}
                                                </span>
                                            ))}
                                        </span>
                                    ),
                            },
                            {
                                key: 'is_active',
                                label: 'Status',
                                render: (row) => <StatusChip row={row} />,
                            },
                            {
                                key: 'last_active_at',
                                label: 'Terakhir aktif',
                                render: (row) => <span className="whitespace-nowrap">{clock(row.last_active_at)}</span>,
                            },
                        ]}
                        rowMenu={(row) => <RowMenu row={row} />}
                    />

                    {can.manageDepartments && <DepartmentPanel departments={reference.departments} />}
                </div>
            </div>

            <Modal show={creating} onClose={() => setCreating(false)} maxWidth="2xl">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Tambah akun</h3>
                    <p className="mt-1 text-sm text-gray-600">
                        Password tidak dibuat di sini. FSD-201 mengirim tautan sekali pakai ke email orang
                        tersebut, dan akun tidak bisa masuk sebelum tautan itu dipakai.
                    </p>

                    <div className="mt-6">
                        <CreateAccountForm
                            departments={activeDepartments}
                            roles={reference.roles}
                            onCreated={() => setCreating(false)}
                        />
                    </div>
                </div>
            </Modal>
        </AppLayout>
    );
}

function StatusChip({ row }) {
    if (!row.is_active) {
        return <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-700">Nonaktif</span>;
    }

    if (row.must_change_password) {
        return (
            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">
                Menunggu ganti password
            </span>
        );
    }

    return <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800">Aktif</span>;
}

/**
 * §2.4's single ⋯ per row. Lihat is always offered; the rest follow the server's
 * flags, and deactivation is a link to the detail screen rather than an act here
 * because FSD-202 wants the confirmation to enumerate what closing an account
 * touches — a count only that screen is handed.
 */
function RowMenu({ row }) {
    const detail = route('admin.users.show', row.id);

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
                <Dropdown.Link href={detail}>Lihat</Dropdown.Link>

                {row.can_edit && <Dropdown.Link href={`${detail}?dialog=edit`}>Ubah</Dropdown.Link>}

                {row.can_deactivate && (
                    <Dropdown.Link href={`${detail}?dialog=deactivate`}>Nonaktifkan</Dropdown.Link>
                )}

                {row.can_send_link && (
                    <Dropdown.Link
                        href={route('admin.users.reset-link', row.id)}
                        method="post"
                        as="button"
                        preserveScroll
                    >
                        Kirim ulang tautan reset
                    </Dropdown.Link>
                )}
            </Dropdown.Content>
        </Dropdown>
    );
}

function CreateAccountForm({ departments, roles, onCreated }) {
    const rules = {
        name: {
            required: 'Nama wajib diisi.',
            format: (value) =>
                String(value).trim().length >= 2 && String(value).length <= 120
                    ? null
                    : 'Nama harus antara 2 dan 120 karakter.',
        },
        email: {
            required: 'Email wajib diisi.',
            format: (value) => (/@[^@\s]+\.[^@\s]+$/.test(String(value)) ? null : 'Format email belum benar.'),
        },
        department_id: { required: 'Departemen wajib dipilih.' },
        phone: {
            format: (value) =>
                /^[+]?[0-9- ]{7,20}$/.test(String(value).trim()) ? null : 'Nomor telepon hanya boleh angka, spasi, atau tanda hubung.',
        },
    };

    return (
        <FormShell
            action={route('admin.users.store')}
            method="post"
            values={{ name: '', email: '', department_id: '', phone: '', roles: [], is_active: true }}
            rules={rules}
            order={['name', 'email', 'department_id', 'phone', 'roles']}
            crossField={(values) => (values.roles?.length ? {} : { roles: 'Pilih minimal satu peran.' })}
            submitProps={(values) => ({ is_active: values.is_active ? 1 : 0 })}
            submitLabel="Simpan akun"
            onCancel={onCreated}
            onSuccess={onCreated}
        >
            {({ values, errors, set, fieldProps }) => (
                <div className="space-y-4">
                    <p className="text-xs text-gray-500">
                        Tanda * wajib diisi. Aturan format berlaku saat Anda meninggalkan kolom; kelengkapan
                        diperiksa saat menyimpan.
                    </p>

                    <div>
                        <InputLabel value="Nama lengkap *" htmlFor="new-name" />
                        <TextInput id="new-name" className="mt-1 block w-full" {...fieldProps('name')} />
                        <InputError message={errors.name} />
                    </div>

                    <div>
                        <InputLabel value="Email perusahaan *" htmlFor="new-email" />
                        <TextInput id="new-email" type="email" className="mt-1 block w-full" {...fieldProps('email')} />
                        <InputError message={errors.email} />
                        <p className="mt-1 text-xs text-gray-500">
                            Hanya domain perusahaan; email pribadi ditolak oleh server.
                        </p>
                    </div>

                    <div>
                        <InputLabel value="Departemen *" htmlFor="new-department" />
                        <SelectInput id="new-department" className="mt-1 block w-full" {...fieldProps('department_id')}>
                            <option value="">Pilih departemen</option>
                            {departments.map((department) => (
                                <option key={department.id} value={String(department.id)}>
                                    {department.name}
                                </option>
                            ))}
                        </SelectInput>
                        <InputError message={errors.department_id} />
                    </div>

                    <div>
                        <InputLabel value="No. telepon" htmlFor="new-phone" />
                        <TextInput id="new-phone" className="mt-1 block w-full" {...fieldProps('phone')} />
                        <InputError message={errors.phone} />
                    </div>

                    <div>
                        <InputLabel value="Peran *" />
                        <div className="mt-1 grid gap-1 sm:grid-cols-2">
                            {roles.map((role) => (
                                <label key={role.slug} className="flex items-center gap-2 text-sm text-gray-700">
                                    <Checkbox
                                        checked={values.roles.includes(role.slug)}
                                        onChange={(event) =>
                                            set(
                                                'roles',
                                                event.target.checked
                                                    ? [...values.roles, role.slug]
                                                    : values.roles.filter((slug) => slug !== role.slug),
                                            )
                                        }
                                    />
                                    {role.label}
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.roles} />
                    </div>

                    <div>
                        <label className="flex items-center gap-2 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                checked={values.is_active}
                                onChange={(event) => set('is_active', event.target.checked)}
                                className="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                            />
                            Akun langsung aktif
                        </label>
                    </div>
                </div>
            )}
        </FormShell>
    );
}

function clock(iso) {
    return iso ? moment(iso) : 'belum pernah masuk';
}
