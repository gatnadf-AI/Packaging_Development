import AppLayout from '@/Layouts/AppLayout';
import Checkbox from '@/Components/Checkbox';
import FormShell from '@/Components/FormShell';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import { moment } from '@/Support/format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * SCR-2.1 detail — one person's account, and the four acts §2.2.1 allows on it.
 *
 * The screen is deliberately the same layout whether or not the reader may edit:
 * §2.5's read-only rule says an account the user may see but not change opens
 * with its inputs disabled and a banner naming the reason, rather than as a
 * different page. That is what lets a READ cell in §6 be the whole answer to a
 * permission question.
 *
 * Roles are a separate form because the consequence is different — AccessService
 * audits them with its own verb and refuses the TSD-523 combinations — and the
 * active switch is a third because FSD-202 requires the confirmation to enumerate
 * what closing an account touches before it happens. Each carries the row's own
 * version token, so a save that races another administrator is refused with
 * MSG-020 rather than silently winning.
 */
export default function UserDetail({ screen, user, impact, history, reference }) {
    const dialog = new URLSearchParams(window.location.search).get('dialog');

    const [deactivating, setDeactivating] = useState(dialog === 'deactivate');

    const activeDepartments = reference.departments.filter((department) => department.is_active);

    return (
        <AppLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">
                            User Management · {screen}
                        </p>
                        <h2 className="text-xl font-semibold leading-tight text-gray-800">{user.name}</h2>
                        <p className="mt-1 text-sm text-gray-600">
                            {user.email} · {user.department ?? 'tanpa departemen'}
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <Link
                            href={route('admin.users.index')}
                            className="text-sm text-indigo-700 hover:underline"
                        >
                            Kembali ke daftar akun
                        </Link>
                        {user.can_send_link && (
                            <SecondaryButton
                                onClick={() => router.post(route('admin.users.reset-link', user.id), {}, { preserveScroll: true })}
                            >
                                Kirim ulang tautan reset
                            </SecondaryButton>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Akun ${user.name}`} />

            <div className="py-6">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {user.must_change_password && (
                        <div className="rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            Akun ini belum menentukan password sendiri. Orang tersebut akan diarahkan ke
                            halaman ganti password pada login berikutnya.
                        </div>
                    )}

                    <section className="rounded-lg border border-gray-200 bg-white p-4">
                        <h3 className="text-base font-semibold text-gray-800">Data akun</h3>

                        <div className="mt-4">
                            <EditAccountForm
                                user={user}
                                departments={activeDepartments}
                                startOpen={dialog === 'edit'}
                            />
                        </div>
                    </section>

                    <section className="rounded-lg border border-gray-200 bg-white p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 className="text-base font-semibold text-gray-800">Peran</h3>
                                <p className="text-sm text-gray-600">
                                    Perubahan berlaku pada permintaan berikutnya; setiap kombinasi peran yang
                                    melanggar pemisahan tugas ditolak server.
                                </p>
                            </div>
                        </div>

                        <div className="mt-4">
                            <RolesForm user={user} roles={reference.roles} />
                        </div>
                    </section>

                    <section className="rounded-lg border border-gray-200 bg-white p-4">
                        <h3 className="text-base font-semibold text-gray-800">Status akun</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            {user.is_active
                                ? 'Akun aktif; orang ini dapat masuk dan diberi izin sesuai perannya.'
                                : 'Akun nonaktif; orang ini tidak dapat masuk dan datanya tetap tersimpan.'}
                        </p>

                        {user.can_deactivate && (
                            <div className="mt-3">
                                {user.is_active ? (
                                    <SecondaryButton onClick={() => setDeactivating(true)}>
                                        Nonaktifkan akun
                                    </SecondaryButton>
                                ) : (
                                    <PrimaryButton
                                        onClick={() =>
                                            router.patch(
                                                route('admin.users.active', user.id),
                                                { is_active: 1, row_version: user.row_version },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Aktifkan kembali
                                    </PrimaryButton>
                                )}
                            </div>
                        )}
                    </section>

                    <AuditStrip history={history} />
                </div>
            </div>

            <DeactivateDialog
                show={deactivating}
                user={user}
                impact={impact}
                onClose={() => setDeactivating(false)}
            />
        </AppLayout>
    );
}

function EditAccountForm({ user, departments, startOpen }) {
    const [open, setOpen] = useState(startOpen);

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
                value === '' || /^[+]?[0-9- ]{7,20}$/.test(String(value).trim())
                    ? null
                    : 'Nomor telepon hanya boleh angka, spasi, atau tanda hubung.',
        },
    };

    if (!open) {
        return (
            <div className="space-y-2 text-sm text-gray-700">
                <Row label="Nama" value={user.name} />
                <Row label="Email" value={user.email} />
                <Row label="Telepon" value={user.phone} />
                {user.can_edit && (
                    <SecondaryButton className="mt-2" onClick={() => setOpen(true)}>
                        Ubah data akun
                    </SecondaryButton>
                )}
            </div>
        );
    }

    return (
        <FormShell
            action={route('admin.users.update', user.id)}
            method="patch"
            values={{
                name: user.name,
                email: user.email,
                department_id: String(user.department_id ?? ''),
                phone: user.phone ?? '',
            }}
            rules={rules}
            order={['name', 'email', 'department_id', 'phone']}
            canEdit={user.can_edit}
            conflict={{ field: 'row_version', props: ['user'], read: (props) => props.user.row_version }}
            resetKey={`${user.id}:${user.row_version}`}
            submitProps={() => ({ row_version: user.row_version })}
            submitLabel="Simpan"
            onCancel={() => setOpen(false)}
            onSuccess={() => setOpen(false)}
        >
            {({ errors, fieldProps }) => (
                <div className="space-y-4">
                    <p className="text-xs text-gray-500">
                        Tanda * wajib diisi. Aturan format berlaku saat Anda meninggalkan kolom; kelengkapan
                        diperiksa saat menyimpan.
                    </p>

                    <div>
                        <InputLabel value="Nama lengkap *" htmlFor="edit-name" />
                        <TextInput id="edit-name" className="mt-1 block w-full" {...fieldProps('name')} />
                        <InputError message={errors.name} />
                    </div>

                    <div>
                        <InputLabel value="Email perusahaan *" htmlFor="edit-email" />
                        <TextInput id="edit-email" type="email" className="mt-1 block w-full" {...fieldProps('email')} />
                        <InputError message={errors.email} />
                        <p className="mt-1 text-xs text-gray-500">
                            Email adalah catatan administrator atas orang ini (FSD-201), bukan milik akun untuk
                            diubah sendiri.
                        </p>
                    </div>

                    <div>
                        <InputLabel value="Departemen *" htmlFor="edit-department" />
                        <SelectInput id="edit-department" className="mt-1 block w-full" {...fieldProps('department_id')}>
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
                        <InputLabel value="No. telepon" htmlFor="edit-phone" />
                        <TextInput id="edit-phone" className="mt-1 block w-full" {...fieldProps('phone')} />
                        <InputError message={errors.phone} />
                    </div>
                </div>
            )}
        </FormShell>
    );
}

function RolesForm({ user, roles }) {
    const current = user.roles.map((role) => role.slug);

    return (
        <FormShell
            action={route('admin.users.roles', user.id)}
            method="put"
            values={{ roles: current, row_version: user.row_version }}
            order={['roles']}
            canEdit={user.can_assign_roles}
            conflict={{ field: 'row_version', props: ['user'], read: (props) => props.user.row_version }}
            resetKey={`${user.id}:roles:${user.row_version}`}
            submitProps={() => ({ row_version: user.row_version })}
            submitLabel="Simpan peran"
        >
            {({ values, set, busy }) => (
                <div className="space-y-3">
                    <div className="grid gap-1 sm:grid-cols-2">
                        {roles.map((role) => (
                            <label key={role.slug} className="flex items-center gap-2 text-sm text-gray-700">
                                <Checkbox
                                    checked={values.roles.includes(role.slug)}
                                    disabled={!user.can_assign_roles || busy}
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
                    <p className="text-xs text-gray-500">
                        Tidak memilih peran apa pun sah: akun itu tetap ada untuk dokumen yang sudah dibuatnya,
                        tetapi tidak dapat membuka layar apa pun.
                    </p>
                </div>
            )}
        </FormShell>
    );
}

/**
 * FSD-202's confirmation. The counts are the ones the screen was handed by the
 * same service that performs the act, so the dialog cannot promise a number the
 * write will not match; the acknowledgement travels as `confirm`, which the form
 * request requires before it will close anything.
 */
function DeactivateDialog({ show, user, impact, onClose }) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="sm">
            <div className="p-6">
                <h3 className="text-lg font-medium text-gray-900">Nonaktifkan akun {user.name}?</h3>
                <ul className="mt-3 space-y-1 text-sm text-gray-600">
                    <li>· {impact.sessions} sesi login akan ditutup.</li>
                    <li>· {impact.open_assignments} penugasan yang masih terbuka akan berhenti.</li>
                    <li>· Data dan jejak audit orang ini tetap tersimpan dan dapat dibaca.</li>
                </ul>
                <div className="mt-6 flex gap-3">
                    <PrimaryButton
                        onClick={() =>
                            router.patch(
                                route('admin.users.active', user.id),
                                { is_active: 0, row_version: user.row_version, confirm: 1 },
                                { preserveScroll: true, onSuccess: onClose },
                            )
                        }
                    >
                        Nonaktifkan
                    </PrimaryButton>
                    <SecondaryButton onClick={onClose}>Batal</SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}

/**
 * §2.5's audit strip: who, when, what changed, for this record only.
 */
function AuditStrip({ history }) {
    return (
        <section className="rounded-lg border border-gray-200 bg-white p-4">
            <h3 className="text-base font-semibold text-gray-800">Riwayat perubahan</h3>

            {history.length === 0 ? (
                <p className="mt-2 text-sm text-gray-500">Belum ada perubahan tercatat untuk akun ini.</p>
            ) : (
                <ul className="mt-3 divide-y divide-gray-100 text-sm">
                    {history.map((entry) => (
                        <li key={entry.id} className="py-2">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <span className="font-medium text-gray-800">{entry.verb}</span>
                                <span className="text-gray-500">{clock(entry.at)}</span>
                            </div>
                            <div className="text-gray-600">
                                {entry.actor ?? 'sistem'}
                                {entry.actor_roles?.length ? ` (${entry.actor_roles.join(', ')})` : ''}
                            </div>
                            {changed(entry).length > 0 && (
                                <ul className="mt-1 space-y-0.5 text-xs text-gray-500">
                                    {changed(entry).map((line) => (
                                        <li key={line.field}>{line.text}</li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function changed(entry) {
    const after = entry.after ?? {};

    return Object.keys(after).map((field) => ({
        field,
        text: `${field}: ${display(entry.before?.[field])} → ${display(after[field])}`,
    }));
}

function display(value) {
    if (value === null || value === undefined || value === '') {
        return 'kosong';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

function Row({ label, value }) {
    return (
        <div className="flex gap-2">
            <span className="w-24 shrink-0 text-gray-500">{label}</span>
            <span className="font-medium">{value || '—'}</span>
        </div>
    );
}

function clock(iso) {
    return moment(iso);
}
