import AppLayout from '@/Layouts/AppLayout';
import DataTable from '@/Components/DataTable';
import SecondaryButton from '@/Components/SecondaryButton';
import { moment } from '@/Support/format';
import { Head } from '@inertiajs/react';

/**
 * SCR-2.5 — the audit trail, read (FSD-207).
 *
 * This page is §2.4's list contract with no list of its own: the search box is
 * switched off because every term a person could type here is already one of
 * FSD-207's four filters, and the columns are not sortable because the trail has
 * exactly one order — newest first, decided by the reader (TSD-1101's rows arrive
 * in the order the acts did). Both of those are the server's answer, echoed in
 * `columns`, not this file's opinion.
 *
 * What the screen adds is the expansion. A row of the trail is one act on one
 * record, and the act is the before/after pair the services wrote, so opening a
 * row shows the fields that moved rather than sending the person to a URL for a
 * record they were never asking about. The words in that pair are the server's:
 * the reader hands over field labels and formatted values, which is what keeps
 * §2.2's "no machine values on a screen" true in the one place the system shows
 * raw attributes.
 *
 * There is nothing to click that changes anything. That is the requirement, not
 * an omission — and the export is shown disabled with its reason instead of
 * hidden, because FSD-207 promises it and the reason is a phase (TSD §15 Phase 6,
 * where the formatted-value and filter-description rules the nine reports share
 * are written), not a permission this account lacks.
 */
export default function Audit({ screen, audit, columns, reference, can }) {
    return (
        <AppLayout
            header={
                <div>
                    <p className="text-xs uppercase tracking-wide text-gray-500">
                        User Management · {screen}
                    </p>
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">Jejak audit</h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Semua perubahan yang tercatat, dari yang terbaru. Daftar ini hanya-baca: tidak ada
                        baris yang bisa diubah atau dihapus dari layar ini.
                    </p>
                </div>
            }
        >
            <Head title="Jejak audit" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <DataTable
                        screen={screen}
                        items={audit}
                        filters={audit.filters}
                        meta={columns}
                        only={['audit']}
                        search={false}
                        filterFields={[
                            {
                                key: 'actor',
                                label: 'Pelaku',
                                options: reference.actors.map((actor) => ({
                                    value: String(actor.id),
                                    label: actor.name,
                                })),
                            },
                            { key: 'action', label: 'Jenis aksi', options: reference.actions },
                            { key: 'target', label: 'Objek', options: reference.targets },
                            { key: 'from', label: 'Dari tanggal', type: 'date' },
                            { key: 'to', label: 'Sampai tanggal', type: 'date' },
                        ]}
                        columns={[
                            {
                                key: 'at',
                                label: 'Waktu',
                                render: (row) => <span className="whitespace-nowrap">{moment(row.at)}</span>,
                            },
                            { key: 'actor', label: 'Pelaku', render: (row) => <Actor row={row} /> },
                            { key: 'verb', label: 'Aksi' },
                            { key: 'target', label: 'Objek', render: (row) => <Target row={row} /> },
                        ]}
                        rowDetails={(row) => <Changes row={row} />}
                        toolbar={<Export allowed={can.export} />}
                    />
                </div>
            </div>
        </AppLayout>
    );
}

/**
 * The person as they were at the moment of the act: the name is looked up from
 * the account and the roles are the snapshot stored on the row, so a change of
 * identity later does not rewrite who did it.
 */
function Actor({ row }) {
    if (row.actor === null) {
        return <span className="text-gray-500">Sistem</span>;
    }

    return (
        <div>
            <span className="font-medium text-gray-800">{row.actor}</span>
            {row.actor_roles.length > 0 && (
                <span className="mt-1 flex flex-wrap gap-1">
                    {row.actor_roles.map((role) => (
                        <span key={role} className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                            {role}
                        </span>
                    ))}
                </span>
            )}
        </div>
    );
}

function Target({ row }) {
    if (row.target === null) {
        return <span className="text-gray-400">—</span>;
    }

    return (
        <span>
            {row.target}
            {row.target_id !== null && <span className="text-gray-500">{` #${row.target_id}`}</span>}
        </span>
    );
}

/**
 * The before/after pair, field by field. The reader has already chosen which
 * fields to show (only the ones that moved), so a row that changed one attribute
 * is one line here and a row that created a record is the whole record with an
 * empty opening side.
 */
function Changes({ row }) {
    return (
        <div className="space-y-1">
            <p className="text-xs text-gray-500">{`Entri #${row.id}${row.ip === null ? '' : ` · dari ${row.ip}`}`}</p>
            {row.changes.length === 0 ? (
                <p className="text-gray-600">Tidak ada nilai yang berubah.</p>
            ) : (
                <ul className="space-y-1">
                    {row.changes.map((change) => (
                        <li key={change.field} className="flex flex-wrap gap-2 text-gray-700">
                            <span className="font-medium">{change.field}</span>
                            <span className="text-gray-500">{`${show(change.from)} → ${show(change.to)}`}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function show(value) {
    if (value === null || value === '') {
        return '(kosong)';
    }

    return String(value);
}

/**
 * FSD-207's last clause, deferred with its reason on the button rather than left
 * off the screen: the disabled state says which phase the file belongs to, and
 * who would be allowed to use it once it exists.
 */
function Export({ allowed }) {
    const reason = allowed
        ? 'Ekspor menyertai aturan laporan pada fase 6 (TSD §15).'
        : 'Hak ekspor laporan belum ada pada akun ini.';

    return (
        <SecondaryButton disabled title={reason}>
            Ekspor
        </SecondaryButton>
    );
}
