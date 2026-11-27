import AppLayout from '@/Layouts/AppLayout';
import DataTable from '@/Components/DataTable';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, router } from '@inertiajs/react';

/**
 * SCR-4.3 — the specification register (FSD-404, FSD-405, FSD §9).
 *
 * The columns are §9's register row, and one of its six is missing on purpose.
 * "Approvers" is the approval engine's recorded decision, and until that engine
 * has a table nothing here can name the person who approved a sheet — so the
 * column is absent rather than filled with the author, who is a different person
 * and the only one of the two the schema holds. SpecificationScreenTest reads the
 * absence off the row's keys, which is what keeps a well-meaning "Dibuat oleh"
 * column from being labelled as a signature.
 *
 * The row is a door rather than a panel: there is no ⋯ menu here because every
 * act on a specification happens on the sheet itself, and the one act a list of
 * these would otherwise offer — removing a row — has no route behind it. FSD-404
 * never reuses a number and retirement is a withdrawal, so the header's single
 * button is the only write this screen offers.
 *
 * Sorting and searching are the server's answer, echoed in `columns`: the header
 * only asks for the keys the directory whitelisted, and the status column's key
 * is `doc_status` rather than the row's `status` because the whitelist speaks the
 * column's name. A second vocabulary typed here is how a clickable header ends up
 * re-sorting by the default.
 */
export default function List({ screen, items, filters, columns, reference, can }) {
    return (
        <AppLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">
                            Master Data · {screen}
                        </p>
                        <h2 className="text-xl font-semibold leading-tight text-gray-800">
                            Spesifikasi bahan pengemas
                        </h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Setiap SBK adalah dokumen dengan nomor sendiri. Versi baru memakai nomor yang sama; tidak
                            ada spesifikasi yang dihapus, yang ada berhenti berlaku lewat penarikan.
                        </p>
                    </div>
                    {can.create && (
                        <PrimaryButton onClick={() => router.get(route('spec.create'))}>SBK baru</PrimaryButton>
                    )}
                </div>
            }
        >
            <Head title="Spesifikasi bahan pengemas" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <DataTable
                        screen={screen}
                        items={items}
                        only={['items', 'filters']}
                        filters={filters}
                        meta={columns}
                        searchPlaceholder="Cari nomor SBK atau nama item…"
                        filterFields={[
                            {
                                key: 'item',
                                label: 'Item',
                                // The item master's list, retired rows included:
                                // SCR-4.2's count links here with this filter, and
                                // a sheet written about a pack the master has
                                // since stopped offering is exactly the case a
                                // picker of live items would hide.
                                options: reference.items.map((item) => ({
                                    value: String(item.id),
                                    label: `${item.code} — ${item.name}`,
                                })),
                            },
                            {
                                key: 'category',
                                label: 'Kategori',
                                options: reference.categories.map((category) => ({
                                    value: String(category.id),
                                    label: category.name,
                                })),
                            },
                            {
                                key: 'status',
                                label: 'Status',
                                // The five words the column holds, from the enum
                                // rather than from a list typed here: APPROVED is
                                // not among them, and a select that offered it
                                // would answer "no rows" for a state the document
                                // cannot be in.
                                options: reference.statuses.map((status) => ({
                                    value: status.value,
                                    label: status.label,
                                })),
                            },
                            { key: 'date_from', label: 'Berlaku dari', type: 'date' },
                            { key: 'date_to', label: 'Sampai dengan', type: 'date' },
                        ]}
                        columns={[
                            {
                                key: 'number',
                                label: 'Nomor SBK',
                                sortable: columns.sortable.includes('number'),
                                render: (row) => (
                                    <a
                                        href={route('spec.show', row.id)}
                                        className="font-mono text-xs text-indigo-700 hover:underline"
                                    >
                                        {row.number}
                                    </a>
                                ),
                            },
                            {
                                key: 'item_name',
                                label: 'Item',
                                sortable: columns.sortable.includes('item_name'),
                                render: (row) => (
                                    <div>
                                        <span className="font-medium text-gray-900">{row.item_name}</span>
                                        <p className="font-mono text-xs text-gray-500">{row.item_code}</p>
                                    </div>
                                ),
                            },
                            {
                                key: 'version',
                                label: 'Versi',
                                sortable: columns.sortable.includes('version'),
                                render: (row) => `v${row.version}`,
                            },
                            {
                                key: 'doc_status',
                                label: 'Status',
                                sortable: columns.sortable.includes('doc_status'),
                                render: (row) => <StatusChip row={row} />,
                            },
                            {
                                key: 'effective_date',
                                label: 'Tanggal berlaku',
                                sortable: columns.sortable.includes('effective_date'),
                                render: (row) =>
                                    row.effective_date ?? <span className="text-gray-400">belum ditetapkan</span>,
                            },
                        ]}
                    />
                </div>
            </div>
        </AppLayout>
    );
}

/**
 * NFR-08 puts the document status on a coloured chip, and §2.2.1 makes it the
 * Indonesian word: both halves arrive from the server as `status_label`, so the
 * page colours the word it was handed instead of translating the code again.
 */
function StatusChip({ row }) {
    const tone = {
        DRAFT: 'bg-gray-100 text-gray-700',
        SUBMITTED: 'bg-blue-100 text-blue-800',
        PENDING: 'bg-amber-100 text-amber-800',
        CLOSED: 'bg-green-100 text-green-800',
        CANCELLED: 'bg-red-100 text-red-700',
    }[row.status];

    return (
        <span className={`rounded-full px-2 py-0.5 text-xs ${tone ?? 'bg-gray-100 text-gray-700'}`}>
            {row.status_label}
        </span>
    );
}
