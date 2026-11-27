// FSD §2.4 — the list-page contract, the parts a browser owns.
//
// TSD-704 keeps permission decisions on the server; §2.4 keeps URL sync, the
// search floor, the sort cycle, the rows-per-page memory and the three empty
// states in one place so that 41 lists cannot each invent their own. Those are
// pure decisions, so they are tested here rather than through a browser: Dusk
// stays for the three flows package 0.5 names.
//
// Which filters a screen has is that screen's business, so it arrives as a
// descriptor list rather than being named here: SCR-2.1 filters an account by
// department, role and status while SCR-2.5 filters a trail by actor, action,
// target and date range, and a support module that hard-codes either set is one
// that the other list has to work around. What is asserted below is the shape of
// the deal — a declared filter round-trips through the URL, an undeclared value
// is dropped, and every active filter is removable.
import test from 'node:test';
import assert from 'node:assert/strict';

import {
    activeChips,
    emptyStateVariant,
    fromQuery,
    nextSort,
    pageWindow,
    perPageKey,
    searchDecision,
    toQuery,
} from '../../resources/js/Support/listState.js';

const DEFAULTS = { sort: 'name', direction: 'asc', per_page: 10, sizes: [5, 10, 25, 50, 100] };

// SCR-2.1's three selects, as the page describes them.
const ACCOUNTS = [
    {
        key: 'department',
        label: 'Departemen',
        options: [{ value: '7', label: 'Quality Control' }],
    },
    {
        key: 'role',
        label: 'Peran',
        options: [{ value: 'qc_manager', label: 'QC Manager' }],
    },
    {
        key: 'status',
        label: 'Status',
        options: [
            { value: 'active', label: 'Aktif' },
            { value: 'inactive', label: 'Nonaktif' },
        ],
    },
];

// SCR-2.5's two selects and two day ranges.
const TRAIL = [
    {
        key: 'actor',
        label: 'Pelaku',
        options: [{ value: '3', label: 'Penjaga Sistem' }],
    },
    {
        key: 'action',
        label: 'Aksi',
        options: [{ value: 'user.created', label: 'Akun dibuat' }],
    },
    { key: 'target', label: 'Objek', options: [{ value: 'user', label: 'Akun' }] },
    { key: 'from', label: 'Dari tanggal', type: 'date' },
    { key: 'to', label: 'Sampai tanggal', type: 'date' },
];

const BLANK = {
    q: '',
    department: '',
    role: '',
    status: '',
    actor: '',
    action: '',
    target: '',
    from: '',
    to: '',
    sort: 'name',
    direction: 'asc',
    per_page: 10,
    page: 1,
};

test('a term below the floor is not sent and says why', () => {
    const decision = searchDecision('ab', 3);

    assert.equal(decision.send, false);
    assert.equal(decision.term, '');
    assert.match(decision.hint, /3 huruf/);
});

test('the floor counts the term, not the spaces around it', () => {
    assert.equal(searchDecision('  ab  ', 3).send, false);
    assert.equal(searchDecision('   ', 3).hint, null);
});

test('a term at the floor is sent lowercased and trimmed', () => {
    const decision = searchDecision('  Sisa  ', 3);

    assert.equal(decision.send, true);
    assert.equal(decision.term, 'sisa');
    assert.equal(decision.hint, null);
});

test('clicking a column that is not sorting starts it ascending', () => {
    assert.deepEqual(nextSort('email', DEFAULTS, DEFAULTS), { sort: 'email', direction: 'asc' });
});

test('clicking the sorted column again reverses it', () => {
    assert.deepEqual(nextSort('email', { sort: 'email', direction: 'asc' }, DEFAULTS),
        { sort: 'email', direction: 'desc' });
});

test('the third click returns the screen to its default sort', () => {
    assert.deepEqual(nextSort('email', { sort: 'email', direction: 'desc' }, DEFAULTS), {
        sort: 'name',
        direction: 'asc',
    });
});

test('the query string carries only what differs from the defaults', () => {
    assert.deepEqual(toQuery(BLANK, DEFAULTS, ACCOUNTS), {});
});

test('a screen with no declared filters keeps only the search, sort and page in the URL', () => {
    const query = toQuery({ ...BLANK, q: 'sisa', department: '7', per_page: 25 }, DEFAULTS, []);

    assert.deepEqual(query, { q: 'sisa', per_page: '25' });
});

test('an odd page is sent but an odd page size is not', () => {
    const query = toQuery({ ...BLANK, q: 'sisa', per_page: 4000, page: 3 }, DEFAULTS, ACCOUNTS);

    assert.equal(query.q, 'sisa');
    assert.equal(query.page, '3');
    assert.equal('per_page' in query, false);
});

test('the page size the server called default never reaches the URL', () => {
    const query = toQuery(
        { ...BLANK, per_page: 25 },
        { ...DEFAULTS, per_page: 25 },
        TRAIL,
    );

    assert.equal('per_page' in query, false);
});

test('a shared URL is read back as the same state', () => {
    const state = fromQuery(
        {
            q: 'QA', department: '7', role: 'qc_manager', status: 'inactive',
            sort: 'email', direction: 'desc', per_page: '25', page: '2',
        },
        DEFAULTS,
        ['name', 'email'],
        ACCOUNTS,
    );

    assert.deepEqual(state, {
        q: 'qa', department: '7', role: 'qc_manager', status: 'inactive',
        sort: 'email', direction: 'desc', per_page: 25, page: 2,
    });
});

test('a URL with values the contract does not allow falls back to the defaults', () => {
    const state = fromQuery(
        { sort: 'password', direction: 'sideways', per_page: '999', page: '0' },
        DEFAULTS,
        ['name', 'email'],
        ACCOUNTS,
    );

    assert.equal(state.sort, 'name');
    assert.equal(state.direction, 'asc');
    assert.equal(state.per_page, DEFAULTS.per_page);
    assert.equal(state.page, 1);
});

test('a column the screen does not mark sortable is not honoured', () => {
    const state = fromQuery({ sort: 'phone' }, DEFAULTS, ['name', 'email'], ACCOUNTS);

    assert.equal(state.sort, 'name');
});

test('a filter value that is not one of the choices the screen offered is dropped', () => {
    const state = fromQuery({ role: 'super_admin', status: 'aktifsekali' }, DEFAULTS, [], ACCOUNTS);

    assert.equal(state.role, '');
    assert.equal(state.status, '');
});

test('a date filter keeps a well-formed day and refuses any other spelling', () => {
    const state = fromQuery({ from: '2026-11-01', to: '2-11-2026' }, DEFAULTS, [], TRAIL);

    assert.equal(state.from, '2026-11-01');
    assert.equal(state.to, '');
});

test('the filters a screen declares round-trip through the URL', () => {
    const state = fromQuery(
        { actor: '3', action: 'user.created', target: 'user', from: '2026-11-01', to: '2026-11-02' },
        DEFAULTS,
        [],
        TRAIL,
    );

    assert.deepEqual(toQuery(state, DEFAULTS, TRAIL), {
        actor: '3', action: 'user.created', target: 'user', from: '2026-11-01', to: '2026-11-02',
    });
});

test('rows per page offers the sizes the server echoed back', () => {
    const state = fromQuery({ per_page: '100' }, DEFAULTS, [], TRAIL);

    assert.equal(state.per_page, 100);

    const clamped = fromQuery({ per_page: '100' }, { ...DEFAULTS, sizes: [5, 25] }, [], TRAIL);

    assert.equal(clamped.per_page, DEFAULTS.per_page);
});

test('the remembered page size belongs to one person on one screen', () => {
    assert.notEqual(perPageKey('SCR-2.1', 'u1'), perPageKey('SCR-2.1', 'u2'));
    assert.notEqual(perPageKey('SCR-2.1', 'u1'), perPageKey('SCR-3.1', 'u1'));
    assert.equal(perPageKey('SCR-2.1', 'u1'), perPageKey('SCR-2.1', 'u1'));
});

test('every active filter becomes one removable chip', () => {
    const chips = activeChips({ q: 'sisa', department: '7', role: 'qc_manager', status: '' }, ACCOUNTS);

    assert.deepEqual(chips, [
        { key: 'q', label: 'Pencarian: "sisa"' },
        { key: 'department', label: 'Departemen: Quality Control' },
        { key: 'role', label: 'Peran: QC Manager' },
    ]);
});

test('a chip for a value that is no longer in the choices still clears', () => {
    const chips = activeChips({ q: '', department: '99', status: 'inactive' }, ACCOUNTS);

    assert.deepEqual(chips, [
        { key: 'department', label: 'Departemen: 99' },
        { key: 'status', label: 'Status: Nonaktif' },
    ]);
});

test('a chip for a day range says which day it cuts the list at', () => {
    const chips = activeChips({ actor: '3', from: '2026-11-01', to: '' }, TRAIL);

    assert.deepEqual(chips, [
        { key: 'actor', label: 'Pelaku: Penjaga Sistem' },
        { key: 'from', label: 'Dari tanggal: 2026-11-01' },
    ]);
});

test('the empty table names which of the three cases it is', () => {
    assert.equal(emptyStateVariant({ rows: 0, total: 0, filtersActive: false, canView: true }), 'no_records');
    assert.equal(emptyStateVariant({ rows: 0, total: 12, filtersActive: true, canView: true }), 'filtered_out');
    assert.equal(emptyStateVariant({ rows: 0, total: 0, filtersActive: false, canView: false }), 'forbidden');
});

test('a page that has records but none on this page is not an empty state', () => {
    assert.equal(emptyStateVariant({ rows: 0, total: 12, filtersActive: false, canView: true }), 'filtered_out');
});

test('the footer stops at both ends of the range', () => {
    assert.deepEqual(pageWindow(1, 5), { first: 1, prev: null, next: 2, last: 5 });
    assert.deepEqual(pageWindow(3, 5), { first: 1, prev: 2, next: 4, last: 5 });
    assert.deepEqual(pageWindow(5, 5), { first: 1, prev: 4, next: null, last: 5 });
    assert.deepEqual(pageWindow(1, 1), { first: 1, prev: null, next: null, last: 1 });
});
