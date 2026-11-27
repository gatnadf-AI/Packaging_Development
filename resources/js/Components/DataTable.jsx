import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import {
    activeChips,
    emptyStateVariant,
    fromQuery,
    nextSort,
    pageWindow,
    perPageKey,
    searchDecision,
    toQuery,
} from '@/Support/listState';
import { router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';

/**
 * FSD §2.4 — the list contract, drawn once so that the 41 lists are one screen.
 *
 * The server owns the decisions: the directory behind each screen applies the
 * search floor, the column whitelist and the page-size clamp, and echoes back
 * what it actually used. This component owns the three things only a browser can
 * own — the URL, the 300 ms debounce with its floor hint, and the rows-per-page
 * memory — and it renders the echoed state rather than its own copy, which is
 * what keeps a chip and the rows underneath it talking about the same query.
 *
 * Which filters exist is the screen's answer, not this one: a page hands over
 * `filterFields` descriptors (key, label, choices, and whether the value is a
 * day) and they round-trip through the URL like any other part of the state.
 * That is how SCR-2.1's three selects and SCR-2.5's four filters plus a date
 * range are the same component. `search={false}` and an empty `sortable` list
 * are the two ways a screen says §2.4 does not apply to it, and both are the
 * server's answer, echoed in `meta`.
 *
 * Every interaction replaces the URL instead of pushing it. §2.4 asks for a
 * shareable view; a back button that walks someone through eleven half-typed
 * searches is not that.
 *
 * The row menu is the §2.4 "single ⋯ per row". It draws what the server's
 * per-row `can_*` flags allow (TSD-704) and nothing else, so the menu and the
 * route middleware can be walked cell by cell and agree. `rowDetails` is the
 * other half of §2.5: a row that carries more than its columns — an audit pair,
 * a line total — expands in place rather than sending the person to a URL.
 */
export default function DataTable({
    screen,
    items,
    columns,
    filters,
    meta,
    only,
    canView = true,
    filterFields = [],
    search = true,
    rowMenu,
    rowDetails = null,
    toolbar = null,
    searchPlaceholder = 'Cari kode, nama, atau judul…',
}) {
    const { auth } = usePage().props;
    const defaults = useMemo(
        () => ({
            sort: meta.defaultSort.column,
            direction: meta.defaultSort.direction,
            per_page: meta.defaultPerPage,
            sizes: meta.perPageOptions,
        }),
        [meta.defaultSort, meta.defaultPerPage, meta.perPageOptions],
    );

    const state = useMemo(
        () => fromQuery(filters, defaults, meta.sortable, filterFields),
        [filters, defaults, meta.sortable, filterFields],
    );

    // The box holds what the person is typing; the URL holds what was searched.
    // Keeping them apart is what lets a two-character term show the hint without
    // firing a request the server would refuse.
    const [term, setTerm] = useState(state.q);
    useEffect(() => setTerm(state.q), [state.q]);

    const [open, setOpen] = useState({});

    const visit = (changes) => {
        router.visit(window.location.pathname, {
            method: 'get',
            data: toQuery({ ...state, ...changes }, defaults, filterFields),
            replace: true,
            preserveScroll: true,
            preserveState: true,
            only,
        });
    };

    const liveHint = search ? searchDecision(term, meta.searchFloor).hint : null;

    // §2.4 asks for a debounce, not a button: the request goes when the typing
    // stops. The timer is cleared on every keystroke and on unmount, so a fast
    // typist causes one request rather than one per character.
    const timer = useRef(null);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const onTermChange = (value) => {
        setTerm(value);
        window.clearTimeout(timer.current);

        const decision = searchDecision(value, meta.searchFloor);

        if (decision.send) {
            timer.current = window.setTimeout(() => visit({ q: decision.term, page: 1 }), 300);
        } else if (decision.term === '' && state.q !== '') {
            // An emptied box is a cleared search, not a term that is too short.
            timer.current = window.setTimeout(() => visit({ q: '', page: 1 }), 300);
        }
    };

    const chips = activeChips(state, filterFields);
    const filtersActive = chips.length > 0;

    const remembered = useRef(false);

    useEffect(() => {
        if (remembered.current) {
            return;
        }

        remembered.current = true;

        const stored = Number.parseInt(window.localStorage.getItem(perPageKey(screen, auth.user.id)), 10);

        // A shared link keeps the size its author chose; the memory only speaks
        // when the URL says nothing.
        if (new URLSearchParams(window.location.search).has('per_page')) {
            return;
        }

        if (stored && stored !== state.per_page) {
            visit({ per_page: stored });
        }
    }, []);

    const changePerPage = (value) => {
        window.localStorage.setItem(perPageKey(screen, auth.user.id), String(value));
        visit({ per_page: Number(value), page: 1 });
    };

    const pages = pageWindow(items.current_page, items.last_page);

    const variant = emptyStateVariant({
        rows: items.data.length,
        total: items.total,
        filtersActive,
        canView,
    });

    // §2.4's "reset filter" is the whole filter set cleared at once, including
    // the term — a screen that cleared the selects and left the search box
    // holding a term would put the rows and the chips back out of step.
    const resetFilters = () => {
        const cleared = { q: '', page: 1 };

        filterFields.forEach((field) => {
            cleared[field.key] = '';
        });

        return cleared;
    };

    // Every column the body draws, and therefore every column the empty row and
    // an expanded detail row have to span.
    const span = columns.length + (rowMenu ? 1 : 0) + (rowDetails ? 1 : 0);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4">
                {search && (
                    <div className="w-full sm:w-72">
                        <label className="block text-sm font-medium text-gray-700" htmlFor={`search-${screen}`}>
                            Pencarian
                        </label>
                        <input
                            id={`search-${screen}`}
                            type="search"
                            value={term}
                            onChange={(event) => onTermChange(event.target.value)}
                            placeholder={searchPlaceholder}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        {(liveHint || filters.search_too_short) && (
                            <p className="mt-1 text-xs text-amber-700">
                                {liveHint ?? `Ketik minimal ${meta.searchFloor} huruf untuk mencari.`}
                            </p>
                        )}
                    </div>
                )}

                {filterFields.map((field) => (
                    <div key={field.key}>
                        <label className="block text-sm font-medium text-gray-700" htmlFor={`filter-${field.key}`}>
                            {field.label}
                        </label>
                        {field.options ? (
                            <SelectInput
                                id={`filter-${field.key}`}
                                className="mt-1 block"
                                value={state[field.key] ?? ''}
                                onChange={(event) => visit({ [field.key]: event.target.value, page: 1 })}
                            >
                                <option value="">{`Semua ${field.label.toLowerCase()}`}</option>
                                {field.options.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </SelectInput>
                        ) : (
                            <TextInput
                                id={`filter-${field.key}`}
                                type={field.type === 'date' ? 'date' : 'text'}
                                className="mt-1 block"
                                value={state[field.key] ?? ''}
                                onChange={(event) => visit({ [field.key]: event.target.value, page: 1 })}
                            />
                        )}
                    </div>
                ))}

                <div className="ms-auto">{toolbar}</div>
            </div>

            {chips.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="text-gray-500">Filter aktif:</span>
                    {chips.map((chip) => (
                        <button
                            key={chip.key}
                            type="button"
                            onClick={() => visit({ [chip.key]: '', page: 1 })}
                            className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-3 py-1 text-indigo-800 hover:bg-indigo-100"
                        >
                            {chip.label}
                            <span aria-hidden="true">×</span>
                            <span className="sr-only">Hapus filter ini</span>
                        </button>
                    ))}
                </div>
            )}

            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <tr>
                            {rowDetails && <th className="w-10 px-4 py-3" aria-hidden="true" />}
                            {columns.map((column) => {
                                const isSorted = state.sort === column.key;

                                return (
                                    <th key={column.key} className="px-4 py-3" aria-sort={ariaSort(isSorted, state.direction)}>
                                        {column.sortable ? (
                                            <button
                                                type="button"
                                                onClick={() => visit({ ...nextSort(column.key, state, defaults), page: 1 })}
                                                className="inline-flex items-center gap-1 hover:text-gray-800"
                                            >
                                                {column.label}
                                                <span aria-hidden="true" className={isSorted ? '' : 'text-gray-300'}>
                                                    {isSorted ? (state.direction === 'asc' ? '▲' : '▼') : '↕'}
                                                </span>
                                            </button>
                                        ) : (
                                            column.label
                                        )}
                                    </th>
                                );
                            })}
                            {rowMenu && <th className="px-4 py-3 text-right">Aksi</th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {items.data.map((row) => (
                            <Fragment key={row.id}>
                                <tr>
                                    {rowDetails && (
                                        <td className="px-4 py-3 align-top">
                                            <button
                                                type="button"
                                                onClick={() => setOpen((current) => ({ ...current, [row.id]: !current[row.id] }))}
                                                aria-expanded={Boolean(open[row.id])}
                                                className="rounded border border-gray-300 px-2 py-0.5 text-gray-600 hover:bg-gray-50"
                                            >
                                                <span aria-hidden="true">{open[row.id] ? '−' : '+'}</span>
                                                <span className="sr-only">Ubah detail baris ini</span>
                                            </button>
                                        </td>
                                    )}
                                    {columns.map((column) => (
                                        <td key={column.key} className="px-4 py-3 align-top text-gray-700">
                                            {column.render ? column.render(row) : display(row[column.key])}
                                        </td>
                                    ))}
                                    {rowMenu && <td className="px-4 py-3 text-right align-top">{rowMenu(row)}</td>}
                                </tr>

                                {rowDetails && open[row.id] && (
                                    <tr>
                                        <td colSpan={span} className="bg-gray-50 px-4 py-3 align-top">
                                            {rowDetails(row)}
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}

                        {items.data.length === 0 && (
                            <tr>
                                <td colSpan={span} className="px-4 py-10">
                                    <EmptyState variant={variant} onReset={() => visit(resetFilters())} />
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600">
                <div className="flex items-center gap-3">
                    <span>{`${items.total} baris`}</span>
                    <label className="flex items-center gap-2">
                        Baris per halaman
                        <SelectInput value={String(state.per_page)} onChange={(event) => changePerPage(event.target.value)}>
                            {meta.perPageOptions.map((size) => (
                                <option key={size} value={size}>
                                    {size}
                                </option>
                            ))}
                        </SelectInput>
                    </label>
                </div>

                <div className="flex items-center gap-3">
                    <span>{`Halaman ${items.current_page} dari ${items.last_page}`}</span>
                    <div className="flex items-center gap-1">
                        <PageButton label="Awal" page={pages.first} current={items.current_page} onVisit={visit} />
                        <PageButton label="Sebelumnya" page={pages.prev} current={items.current_page} onVisit={visit} />
                        <PageButton label="Berikutnya" page={pages.next} current={items.current_page} onVisit={visit} />
                        <PageButton label="Akhir" page={pages.last} current={items.current_page} onVisit={visit} />
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * §2.7's three empty states, each with its own sentence and its own action: the
 * filtered case offers a way back, the empty-database case offers none, and the
 * no-permission case offers nothing at all — a button that the route will refuse
 * is not a recovery.
 */
function EmptyState({ variant, onReset }) {
    if (variant === 'filtered_out') {
        return (
            <div className="text-center">
                <p className="text-gray-600">Data ada, tetapi tidak ada yang cocok dengan filter ini.</p>
                <SecondaryButton className="mt-3" onClick={onReset}>
                    Reset filter
                </SecondaryButton>
            </div>
        );
    }

    if (variant === 'forbidden') {
        return <p className="text-center text-gray-600">Anda tidak memiliki akses ke daftar ini.</p>;
    }

    return <p className="text-center text-gray-600">Belum ada data untuk ditampilkan.</p>;
}

function PageButton({ label, page, current, onVisit }) {
    return (
        <button
            type="button"
            disabled={page === null || page === current}
            onClick={() => onVisit({ page })}
            className="rounded border border-gray-300 bg-white px-3 py-1 text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
        >
            {label}
        </button>
    );
}

function ariaSort(isSorted, direction) {
    if (!isSorted) {
        return undefined;
    }

    return direction === 'asc' ? 'ascending' : 'descending';
}

function display(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value);
}
