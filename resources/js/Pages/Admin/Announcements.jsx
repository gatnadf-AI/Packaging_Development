import AppLayout from '@/Layouts/AppLayout';
import Checkbox from '@/Components/Checkbox';
import DangerButton from '@/Components/DangerButton';
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
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * SCR-2.4 — the announcements sheet (FSD-208).
 *
 * The screen owns three things and no more: which columns a notice shows, the
 * audience editor that names the people it is addressed to, and the two acts that
 * end a window. The rules — whether a role exists, whether a window has room in
 * it, whether whitespace is a title — are the domain's, and what arrives from a
 * refused save is an error under the field the domain named, which is why the
 * form below validates only the shape it can see (a date is a date) and lets the
 * server answer for everything else.
 *
 * There is no edit. A notice that is up has been read, and its dismissal is a
 * fact about the words that were on screen; changing those words afterwards would
 * make the record disagree with what people acknowledged. So a mistake that is
 * still scheduled is taken back and written again, and a mistake that is already
 * up is stopped. The requirement does not say either of these things, and both are
 * in the FSD-208 review note rather than hidden in a button that is missing.
 */
export default function Announcements({ screen, announcements, columns, reference, can }) {
    const [creating, setCreating] = useState(false);
    const [confirming, setConfirming] = useState(null);

    return (
        <AppLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">
                            User Management · {screen}
                        </p>
                        <h2 className="text-xl font-semibold leading-tight text-gray-800">Pengumuman</h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Pemberitahuan untuk staff, dengan sasaran dan jendela tayang. Yang masih tayang muncul
                            di semua halaman sebagai banner yang bisa ditutup.
                        </p>
                    </div>
                    {can.write && <PrimaryButton onClick={() => setCreating(true)}>Buat pengumuman</PrimaryButton>}
                </div>
            }
        >
            <Head title="Pengumuman" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <DataTable
                        screen={screen}
                        items={announcements}
                        only={['announcements']}
                        filters={announcements.filters}
                        meta={columns}
                        search={false}
                        columns={[
                            { key: 'title', label: 'Judul' },
                            { key: 'audience_label', label: 'Sasaran' },
                            {
                                key: 'state',
                                label: 'Status',
                                render: (row) => <StateChip state={row.state} label={row.state_label} />,
                            },
                            {
                                key: 'publish_at',
                                label: 'Tayang mulai',
                                render: (row) => <span className="whitespace-nowrap">{stamp(row.publish_at)}</span>,
                            },
                            {
                                key: 'expire_at',
                                label: 'Selesai',
                                render: (row) => (
                                    <span className="whitespace-nowrap">
                                        {row.expire_at ? stamp(row.expire_at) : 'sampai dihentikan'}
                                    </span>
                                ),
                            },
                            { key: 'author', label: 'Oleh' },
                        ]}
                        rowDetails={(row) => <Body row={row} />}
                        rowMenu={can.write ? (row) => <RowMenu row={row} onConfirm={setConfirming} /> : undefined}
                    />
                </div>
            </div>

            <Modal show={creating} onClose={() => setCreating(false)} maxWidth="2xl">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Buat pengumuman</h3>
                    <p className="mt-1 text-sm text-gray-600">
                        Waktu tayang di masa lalu berarti pengumuman langsung tampil. Tanpa waktu selesai, pengumuman
                        tetap tayang sampai Anda hentikan.
                    </p>

                    <div className="mt-6">
                        <AnnouncementForm
                            roles={reference.roles}
                            departments={reference.departments}
                            onSaved={() => setCreating(false)}
                        />
                    </div>
                </div>
            </Modal>

            <Modal show={Boolean(confirming)} onClose={() => setConfirming(null)} maxWidth="md">
                {confirming && <Confirm row={confirming} onDone={() => setConfirming(null)} />}
            </Modal>
        </AppLayout>
    );
}

function StateChip({ state, label }) {
    const tones = {
        live: 'bg-green-100 text-green-800',
        scheduled: 'bg-amber-100 text-amber-800',
        ended: 'bg-gray-200 text-gray-700',
    };

    return <span className={`rounded-full px-2 py-0.5 text-xs ${tones[state] ?? tones.ended}`}>{label}</span>;
}

/**
 * The body is the part of a notice that does not fit a column, and §2.5 says an
 * expansion is a browser act rather than a URL — the row already carries it.
 */
function Body({ row }) {
    return (
        <div className="whitespace-pre-line rounded bg-gray-50 px-3 py-2 text-sm text-gray-700">
            {row.body}
        </div>
    );
}

function RowMenu({ row, onConfirm }) {
    const live = row.state === 'live';
    const scheduled = row.state === 'scheduled';

    return (
        <Dropdown>
            <Dropdown.Trigger>
                <button
                    type="button"
                    className="rounded border border-gray-300 px-2 py-1 text-gray-600 hover:bg-gray-50"
                    aria-label={`Aksi untuk ${row.title}`}
                >
                    ⋯
                </button>
            </Dropdown.Trigger>

            <Dropdown.Content>
                {/* Only the acts this row's window still allows. A scheduled notice can be taken back because
                    nobody could have read it; a live one can only be stopped, and an ended one offers nothing,
                    which is the same difference the domain draws. */}
                {live && (
                    <Dropdown.Link as="button" onClick={() => onConfirm({ ...row, act: 'stop' })}>
                        Hentikan tayang
                    </Dropdown.Link>
                )}

                {scheduled && (
                    <Dropdown.Link as="button" onClick={() => onConfirm({ ...row, act: 'delete' })}>
                        Hapus
                    </Dropdown.Link>
                )}
            </Dropdown.Content>
        </Dropdown>
    );
}

function Confirm({ row, onDone }) {
    const { flash } = usePage().props;
    const [busy, setBusy] = useState(false);

    const acting = row.act === 'stop';

    const run = () => {
        setBusy(true);

        router[acting ? 'patch' : 'delete'](
            acting ? route('admin.announcements.stop', row.id) : route('admin.announcements.destroy', row.id),
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
                {acting ? 'Hentikan pengumuman ini?' : 'Hapus pengumuman yang belum tayang?'}
            </h3>
            <p className="mt-2 text-sm text-gray-600">
                {acting
                    ? `"${row.title}" berhenti tayang sekarang dan tidak muncul lagi di banner. Pengumumannya tetap
                       tersimpan di daftar ini karena orang mungkin sudah membacanya.`
                    : `"${row.title}" belum pernah tampil, sehingga menghapusnya tidak menghilangkan sesuatu yang
                       sudah dibaca siapa pun.`}
            </p>

            {flash?.error && <p className="mt-3 text-sm text-red-700">{flash.error}</p>}

            <div className="mt-6 flex flex-wrap gap-3">
                <DangerButton onClick={run} disabled={busy}>
                    {acting ? 'Hentikan tayang' : 'Hapus'}
                </DangerButton>
                <SecondaryButton onClick={onDone} disabled={busy}>
                    Batal
                </SecondaryButton>
            </div>
        </div>
    );
}

function AnnouncementForm({ roles, departments, onSaved }) {
    const rules = {
        title: {
            required: 'Judul pengumuman wajib diisi.',
            format: (value) => (String(value).length <= 200 ? null : 'Judul maksimal 200 karakter.'),
        },
        body: { required: 'Isi pengumuman wajib diisi.' },
        publish_at: { required: 'Waktu tayang wajib diisi.' },
    };

    const initial = {
        title: '',
        body: '',
        type: 'all',
        roleSlugs: [],
        departmentIds: [],
        publish_at: localInputValue(),
        expire_at: '',
    };

    return (
        <FormShell
            action={route('admin.announcements.store')}
            method="post"
            values={initial}
            rules={rules}
            order={['title', 'body', 'audience', 'type', 'roleSlugs', 'departmentIds', 'publish_at', 'expire_at']}
            crossField={(values) =>
                values.type === 'roles' && values.roleSlugs.length === 0
                    ? { audience: 'Pilih minimal satu peran.' }
                    : values.type === 'departments' && values.departmentIds.length === 0
                      ? { audience: 'Pilih minimal satu departemen.' }
                      : values.expire_at !== '' && values.expire_at <= values.publish_at
                        ? { expire_at: 'Waktu selesai harus setelah waktu tayang.' }
                        : {}
            }
            submitProps={(values) => ({
                audience:
                    values.type === 'roles'
                        ? { type: 'roles', roles: values.roleSlugs }
                        : values.type === 'departments'
                          ? { type: 'departments', departments: values.departmentIds }
                          : { type: 'all' },
                expire_at: values.expire_at === '' ? null : values.expire_at,
            })}
            submitLabel="Simpan pengumuman"
            onCancel={onSaved}
            onSuccess={onSaved}
        >
            {({ values, errors, set, fieldProps }) => (
                <div className="space-y-4">
                    <p className="text-xs text-gray-500">
                        Tanda * wajib diisi. Daftar sasaran diisi dari peran dan departemen yang ada; pilihan yang
                        tidak dikenal ditolak saat menyimpan.
                    </p>

                    <div>
                        <InputLabel value="Judul *" htmlFor="announcement-title" />
                        <TextInput id="announcement-title" className="mt-1 block w-full" {...fieldProps('title')} />
                        <InputError message={errors.title} />
                    </div>

                    <div>
                        <InputLabel value="Isi pengumuman *" htmlFor="announcement-body" />
                        <textarea
                            id="announcement-body"
                            rows={5}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            {...fieldProps('body')}
                        />
                        <InputError message={errors.body} />
                    </div>

                    <div>
                        <InputLabel value="Sasaran" htmlFor="announcement-type" />
                        <SelectInput id="announcement-type" className="mt-1 block w-full" {...fieldProps('type')}>
                            <option value="all">Semua staff</option>
                            <option value="roles">Peran tertentu</option>
                            <option value="departments">Departemen tertentu</option>
                        </SelectInput>
                    </div>

                    {values.type === 'roles' && (
                        <div>
                            <InputLabel value="Peran yang diberi tahu" />
                            <div className="mt-1 grid gap-1 sm:grid-cols-2">
                                {roles.map((role) => (
                                    <label key={role.value} className="flex items-center gap-2 text-sm text-gray-700">
                                        <Checkbox
                                            checked={values.roleSlugs.includes(role.value)}
                                            onChange={(event) =>
                                                set(
                                                    'roleSlugs',
                                                    event.target.checked
                                                        ? [...values.roleSlugs, role.value]
                                                        : values.roleSlugs.filter((slug) => slug !== role.value),
                                                )
                                            }
                                        />
                                        {role.label}
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}

                    {values.type === 'departments' && (
                        <div>
                            <InputLabel value="Departemen yang diberi tahu" />
                            <div className="mt-1 grid gap-1 sm:grid-cols-2">
                                {departments.map((department) => (
                                    <label key={department.value} className="flex items-center gap-2 text-sm text-gray-700">
                                        <Checkbox
                                            checked={values.departmentIds.includes(department.value)}
                                            onChange={(event) =>
                                                set(
                                                    'departmentIds',
                                                    event.target.checked
                                                        ? [...values.departmentIds, department.value]
                                                        : values.departmentIds.filter((id) => id !== department.value),
                                                )
                                            }
                                        />
                                        {department.label}
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}

                    <InputError message={errors.audience} />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Waktu tayang *" htmlFor="announcement-publish" />
                            <TextInput id="announcement-publish" type="datetime-local" className="mt-1 block w-full" {...fieldProps('publish_at')} />
                            <InputError message={errors.publish_at} />
                        </div>

                        <div>
                            <InputLabel value="Waktu selesai" htmlFor="announcement-expire" />
                            <TextInput id="announcement-expire" type="datetime-local" className="mt-1 block w-full" {...fieldProps('expire_at')} />
                            <InputError message={errors.expire_at} />
                            <p className="mt-1 text-xs text-gray-500">Kosong berarti tayang sampai dihentikan.</p>
                        </div>
                    </div>
                </div>
            )}
        </FormShell>
    );
}

function stamp(iso) {
    return iso ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * A datetime-local input wants the wall-clock reading of this machine, not the
 * ISO string the server would compare in WIB. The requirement is about the instant
 * the administrator meant, and a field that prefilled midnight in the wrong zone
 * would publish something nobody typed.
 */
function localInputValue() {
    const now = new Date();
    const offset = new Date(now.getTime() - now.getTimezoneOffset() * 60000);

    return offset.toISOString().slice(0, 16);
}
