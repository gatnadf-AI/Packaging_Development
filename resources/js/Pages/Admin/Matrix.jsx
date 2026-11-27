import AppLayout from '@/Layouts/AppLayout';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { conflictOffers, readOnlyBanner } from '@/Support/formState';
import { cellView, cycleCell, groupRows, pendingEdits, previewTotals } from '@/Support/matrixGrid';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

/**
 * SCR-2.3 — the access matrix, one cell per (feature, role) (FSD-204, FSD-205).
 *
 * The grid is FSD §6 turned into a screen, so it is drawn the way the sheet is
 * read: feature rows grouped in document order, role columns in document order,
 * and each cell holding the level the table holds rather than anything inferred.
 * A click cycles the cell Full → Baca saja → Tidak ada, which is the same move
 * PermissionLevel::next() makes on the server; two cycle orders on either side of
 * one click is how a grant comes to mean something on screen that it does not
 * mean in the policy.
 *
 * Clicking does not save. TSD-511 wants many small writes — one transaction per
 * cell, its own audit entry, its own compare-and-set — and FSD-205 wants the diff
 * readable before anything is written, which a click that saved instantly could
 * never offer. So a click joins a page of edits, the page is previewable, and
 * Simpan sends one PATCH per cell. Each PATCH names the level the server showed
 * when the cell was first clicked, never the level the draft has since moved to:
 * that is what makes the save optimistic in the real sense, and what makes a
 * second administrator's competing click surface as MSG-020 instead of losing
 * quietly.
 *
 * "Kirim ulang saya" resends the intended levels against the live ones, which it
 * asks the preview endpoint for rather than reading its own props: after a partial
 * batch the props it was rendered with are exactly the stale thing the conflict
 * is about, and the diff is the one call that returns what the table now says.
 */
const LEVEL_TEXT = { FULL: 'F', READ: 'R', NONE: '—' };

const LEVEL_STYLE = {
    FULL: 'bg-indigo-600 text-white',
    READ: 'bg-indigo-100 text-indigo-900',
    NONE: 'bg-white text-gray-300',
};

export default function Matrix({ screen, matrix, can }) {
    const { errors } = usePage().props;
    const [draft, setDraft] = useState({});
    const [preview, setPreview] = useState(null);
    const [busy, setBusy] = useState(false);
    const [showConflict, setShowConflict] = useState(false);

    const edits = pendingEdits(draft);
    const banner = readOnlyBanner({ canEdit: can.edit, locked: false });
    const conflictMessage = errors.row_version;

    useEffect(() => {
        setShowConflict(Boolean(conflictMessage));
    }, [conflictMessage]);

    const send = (list) => {
        if (list.length === 0) {
            return;
        }

        setBusy(true);
        let remaining = list.length;
        let failures = 0;

        const settle = () => {
            remaining -= 1;

            if (remaining === 0) {
                setBusy(false);

                // A batch that was refused somewhere keeps its draft: the person
                // still has to be able to read what they meant and resend it.
                if (failures === 0) {
                    setDraft({});
                }
            }
        };

        list.forEach((edit) => {
            router.patch(
                route('admin.matrix.cell'),
                { feature: edit.feature, role: edit.role, from: edit.from, to: edit.to },
                {
                    only: ['matrix'],
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: settle,
                    onError: () => {
                        failures += 1;
                        settle();
                    },
                },
            );
        });
    };

    const click = (feature, role, level) => {
        if (!can.edit) {
            return;
        }

        setDraft((current) => cycleCell(current, feature, role, level));
    };

    const openPreview = () => {
        axios
            .post(
                route('admin.matrix.preview'),
                { cells: edits.map((edit) => ({ feature: edit.feature, role: edit.role, to: edit.to })) },
            )
            .then((response) => setPreview(response.data.diff));
    };

    const reload = () => {
        setShowConflict(false);
        setDraft({});
        setPreview(null);
        router.reload({ only: ['matrix'] });
    };

    const resend = () => {
        setShowConflict(false);

        axios
            .post(
                route('admin.matrix.preview'),
                { cells: pendingEdits(draft).map((edit) => ({ feature: edit.feature, role: edit.role, to: edit.to })) },
            )
            .then((response) => send(response.data.diff));
    };

    return (
        <AppLayout
            header={
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">
                            User Management · {screen}
                        </p>
                        <h2 className="text-xl font-semibold leading-tight text-gray-800">Matriks akses</h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Setiap sel adalah satu keputusan: apa yang boleh dilakukan satu peran pada satu bagian
                            aplikasi.
                        </p>
                    </div>
                    <p className="text-sm text-gray-500">{`Versi matriks ${matrix.version}`}</p>
                </div>
            }
        >
            <Head title="Matriks akses" />

            <div className="py-6">
                <div className="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                    {can.edit ? (
                        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white p-4">
                            <PrimaryButton disabled={edits.length === 0 || busy} onClick={() => send(edits)}>
                                {edits.length === 0 ? 'Simpan' : `Simpan ${edits.length} sel`}
                            </PrimaryButton>
                            <SecondaryButton disabled={edits.length === 0 || busy} onClick={openPreview}>
                                Pratinjau perubahan
                            </SecondaryButton>
                            {edits.length > 0 && (
                                <SecondaryButton onClick={() => setDraft({})}>Buang draf</SecondaryButton>
                            )}
                            <p className="ms-auto text-sm text-gray-600">
                                {edits.length === 0
                                    ? 'Klik sel untuk memindahkan Full → Baca saja → Tidak ada.'
                                    : `${edits.length} sel menunggu disimpan.`}
                            </p>
                        </div>
                    ) : (
                        <p className="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600">{banner}</p>
                    )}

                    <div className="overflow-auto rounded-lg border border-gray-200 bg-white shadow-sm" style={{ maxHeight: '70vh' }}>
                        <table className="min-w-full border-separate border-spacing-0 text-sm">
                            <thead>
                                <tr>
                                    <th
                                        scope="col"
                                        className="sticky left-0 top-0 z-30 border-b border-gray-200 bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                                    >
                                        Fitur
                                    </th>
                                    {matrix.roles.map((role) => (
                                        <th
                                            key={role.slug}
                                            scope="col"
                                            title={role.system ? 'Identitas sistem, bukan peran bisnis' : undefined}
                                            className="sticky top-0 z-20 border-b border-l border-gray-200 bg-gray-50 px-2 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500"
                                        >
                                            {role.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {groupRows(matrix.rows).map((section) => (
                                    <SectionRows
                                        key={section.group}
                                        section={section}
                                        roles={matrix.roles}
                                        draft={draft}
                                        editable={can.edit}
                                        onClickCell={click}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <p className="text-xs text-gray-500">
                        F = penuh, R = baca saja, — = tidak ada akses. Sel yang kosong berarti tidak ada akses seberapa
                        pun peran itu disebut di tempat lain: penolakan adalah default matriks ini (BR-07).
                    </p>
                </div>
            </div>

            <Modal show={preview !== null} onClose={() => setPreview(null)} maxWidth="2xl">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Perubahan yang akan disimpan</h3>
                    {preview !== null && <PreviewSummary diff={preview} />}
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setPreview(null)}>Tutup</SecondaryButton>
                        <PrimaryButton
                            onClick={() => {
                                setPreview(null);
                                send(edits);
                            }}
                        >
                            Simpan sekarang
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>

            <Modal show={showConflict} onClose={() => setShowConflict(false)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Sel ini diubah orang lain</h3>
                    <p className="mt-2 text-sm text-gray-600">{conflictMessage}</p>
                    <div className="mt-6 flex flex-wrap gap-3">
                        {conflictOffers().map((offer) => (
                            <SecondaryButton
                                key={offer.key}
                                disabled={busy}
                                onClick={offer.key === 'reload' ? reload : resend}
                            >
                                {offer.label}
                            </SecondaryButton>
                        ))}
                    </div>
                </div>
            </Modal>
        </AppLayout>
    );
}

function SectionRows({ section, roles, draft, editable, onClickCell }) {
    return (
        <>
            <tr>
                <th
                    colSpan={roles.length + 1}
                    scope="colgroup"
                    className="sticky left-0 z-10 border-y border-gray-200 bg-gray-100 px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600"
                >
                    {section.group}
                </th>
            </tr>
            {section.rows.map((row) => (
                <tr key={row.key}>
                    <th
                        scope="row"
                        className="sticky left-0 z-10 border-b border-gray-100 bg-white px-4 py-2 text-left font-medium text-gray-700"
                    >
                        {row.label}
                        <span className="block text-xs font-normal text-gray-400">{row.key}</span>
                    </th>
                    {roles.map((role) => {
                        const cell = cellView(row.key, role.slug, row.cells[role.slug].level, draft);

                        return (
                            <td key={role.slug} className="border-b border-l border-gray-100 p-0 text-center">
                                <LevelCell
                                    cell={cell}
                                    editable={editable}
                                    onClick={() => onClickCell(row.key, role.slug, cell.level)}
                                />
                            </td>
                        );
                    })}
                </tr>
            ))}
        </>
    );
}

function LevelCell({ cell, editable, onClick }) {
    const level = cell.pending ?? cell.level;
    const styles = `${LEVEL_STYLE[level]} ${cell.changed ? 'ring-2 ring-amber-400' : ''}`;
    const text = `${LEVEL_TEXT[level]}${cell.changed ? ` (${cell.level})` : ''}`;

    if (!editable) {
        return (
            <span className={`inline-block min-w-[2.75rem] px-2 py-1 text-xs font-semibold ${styles}`}>{text}</span>
        );
    }

    return (
        <button
            type="button"
            onClick={onClick}
            title={cell.changed ? `${cell.level} → ${cell.pending}, klik untuk melanjutkan` : `${cell.level}, klik untuk memindahkan`}
            className={`inline-block min-w-[2.75rem] px-2 py-1 text-xs font-semibold hover:ring-2 hover:ring-indigo-400 ${styles}`}
        >
            {text}
        </button>
    );
}

/**
 * FSD-205's own order: which roles gain or lose which features, and how many
 * accounts each decision reaches. The account figure in the heading is the sum of
 * the per-cell counts, so the line says "baris dampak" rather than implying it
 * counted distinct people — a person holding two of the roles on the page is
 * reached by two of the rows below.
 */
function PreviewSummary({ diff }) {
    const totals = previewTotals(diff);

    if (diff.length === 0) {
        return <p className="mt-2 text-sm text-gray-600">Tidak ada sel yang benar-benar berubah.</p>;
    }

    return (
        <>
            <p className="mt-2 text-sm text-gray-600">
                {`${totals.gains} sel menambah hak, ${totals.losses} sel mengurangi, ${totals.accounts} baris dampak akun.`}
            </p>
            <ul className="mt-4 max-h-80 space-y-2 overflow-y-auto text-sm">
                {diff.map((row) => (
                    <li key={`${row.feature}|${row.role}`} className="flex flex-wrap items-baseline gap-2">
                        <span className="font-medium text-gray-900">{row.role_label}</span>
                        <span className="text-gray-700">{row.feature_label}</span>
                        <span className="text-gray-500">{`${row.from} → ${row.to}`}</span>
                        <span className="ms-auto text-gray-500">{`${row.users_affected} akun`}</span>
                    </li>
                ))}
            </ul>
        </>
    );
}
