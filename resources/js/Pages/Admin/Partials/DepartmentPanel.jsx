import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

/**
 * FSD-206 — the department reference list, edited on the account screen.
 *
 * The panel lives here because the FSD gives the reference lists a requirement
 * and no screen id of their own, and DepartmentController says the same thing
 * about the routes: an administrator changes a department name while filing a
 * person in it. The panel is drawn only when the server's
 * `can.manageDepartments` says the actor may add one (TSD-704), so a READ cell
 * in §6 shows the directory without the panel rather than a form that 403s.
 *
 * Two rules shape the editing. A department is never deleted — §3.1 makes the
 * foreign key RESTRICT and names deactivation as the retirement mechanism, so
 * there is no delete control to draw. And switching one off asks first: TSD-520
 * wants the live account count named before the act, which is why the row calls
 * admin.departments.usage and shows the number instead of posting a checkbox.
 */
export default function DepartmentPanel({ departments }) {
    const [adding, setAdding] = useState(false);

    const create = useForm({ name: '' });

    return (
        <section className="rounded-lg border border-gray-200 bg-white p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 className="text-base font-semibold text-gray-800">Daftar departemen</h3>
                    <p className="text-sm text-gray-600">
                        Nama departemen dapat diubah; penonaktifan menjaga akun yang sudah ada tetap terbaca.
                    </p>
                </div>
                <SecondaryButton onClick={() => setAdding(true)}>Tambah departemen</SecondaryButton>
            </div>

            <ul className="mt-4 divide-y divide-gray-100">
                {departments.map((department) => (
                    <DepartmentRow key={department.id} department={department} />
                ))}
            </ul>

            <Modal show={adding} onClose={() => setAdding(false)} maxWidth="md">
                <form
                    className="p-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        create.post(route('admin.departments.store'), {
                            preserveScroll: true,
                            onSuccess: () => {
                                create.reset();
                                setAdding(false);
                            },
                        });
                    }}
                >
                    <h3 className="text-lg font-medium text-gray-900">Departemen baru</h3>
                    <InputLabel className="mt-4" value="Nama departemen" htmlFor="department-name" />
                    <TextInput
                        id="department-name"
                        className="mt-1 block w-full"
                        value={create.data.name}
                        onChange={(event) => create.setData('name', event.target.value)}
                        autoFocus
                    />
                    <InputError message={create.errors.name} />
                    <div className="mt-6 flex gap-3">
                        <PrimaryButton type="submit" disabled={create.processing}>
                            Simpan
                        </PrimaryButton>
                        <SecondaryButton type="button" onClick={() => setAdding(false)}>
                            Batal
                        </SecondaryButton>
                    </div>
                </form>
            </Modal>
        </section>
    );
}

function DepartmentRow({ department }) {
    const form = useForm({
        name: department.name,
        is_active: department.is_active,
        row_version: department.row_version,
    });

    const [warning, setWarning] = useState(null);
    const [refused, setRefused] = useState(null);

    const post = (payload) => {
        form.patch(route('admin.departments.update', department.id), {
            preserveScroll: true,
            data: payload,
            onError: (errors) => {
                if (errors.row_version) {
                    setRefused(errors.row_version);
                }
            },
        });
    };

    const save = () => {
        if (!form.data.is_active && department.is_active) {
            // The flip to inactive is the only direction that closes something,
            // so only it carries FSD-202's acknowledgement. The count comes from
            // admin.departments.usage, the same query DepartmentService asks
            // again when it writes (TSD-520); a stale answer here cannot let a
            // deactivation through, it can only misstate the dialog.
            axios
                .get(route('admin.departments.usage', department.id))
                .then(({ data }) => setWarning(data))
                .catch(() => post({ ...form.data }));

            return;
        }

        post({ ...form.data });
    };

    return (
        <li className="py-3">
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-48 flex-1">
                    <InputLabel value="Nama" htmlFor={`department-${department.id}`} />
                    <TextInput
                        id={`department-${department.id}`}
                        name="department-name"
                        className="mt-1 block w-full"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </div>

                <label className="flex items-center gap-2 pb-2 text-sm text-gray-700">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                        className="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                    />
                    Aktif
                </label>

                <span className="pb-2 text-sm text-gray-500">{department.user_count} akun</span>

                <PrimaryButton onClick={save} disabled={form.processing}>
                    Simpan
                </PrimaryButton>
            </div>

            <InputError message={form.errors.name} />

            {refused && (
                <div className="mt-2 flex flex-wrap items-center gap-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <span>{refused}</span>
                    <SecondaryButton
                        onClick={() => {
                            setRefused(null);
                            router.reload({ preserveScroll: true });
                        }}
                    >
                        Muat ulang data
                    </SecondaryButton>
                </div>
            )}

            <Modal show={Boolean(warning)} onClose={() => setWarning(null)} maxWidth="sm">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Nonaktifkan departemen ini?</h3>
                    <p className="mt-2 text-sm text-gray-600">
                        {warning.user_count} akun masih terdaftar di {warning.name}. Departemen tidak dihapus,
                        sehingga akun itu tetap terbaca; nama tersebut hanya berhenti menjadi pilihan untuk akun
                        baru.
                    </p>
                    <div className="mt-6 flex gap-3">
                        <PrimaryButton
                            onClick={() => {
                                // The usage answer carries the version the server just read, which is the
                                // token this row must now post with.
                                const payload = { ...form.data, row_version: warning.row_version, confirm: 1 };

                                setWarning(null);
                                post(payload);
                            }}
                        >
                            Nonaktifkan
                        </PrimaryButton>
                        <SecondaryButton onClick={() => setWarning(null)}>Batal</SecondaryButton>
                    </div>
                </div>
            </Modal>
        </li>
    );
}
