/**
 * FSD §2.4 — the browser's half of the list-page contract.
 *
 * The server's half is App\Domain\Access\UserDirectory: it decides what a term
 * means, clamps the page size and refuses a sort column the screen never marked.
 * This module is the mirror of those rules for the address bar, because §2.4
 * keeps the state in the URL: a chip removed, a column clicked and a page turned
 * all have to produce the query the server will read back unchanged.
 *
 * Which filters exist is not decided here. A screen hands over a descriptor list
 * — key, label, the choices it offers, and whether the value is a day — and the
 * three functions below read and write exactly those keys. That is what lets the
 * account directory filter by department, role and status while the audit viewer
 * filters by actor, action, target and date range without either one owning a
 * copy of the URL rules.
 *
 * Nothing here decides permission. TSD-704 keeps the can-flags on the server and
 * this module does not read them, so a list cannot become filterable by guessing
 * a query string.
 */

const DAY = /^\d{4}-\d{2}-\d{2}$/;

export function searchDecision(term, floor) {
    const value = String(term ?? '').trim().toLowerCase();

    if (value === '') {
        return { send: false, term: '', hint: null };
    }

    if (value.length < floor) {
        return { send: false, term: '', hint: `Ketik minimal ${floor} huruf untuk mencari.` };
    }

    return { send: true, term: value, hint: null };
}

export function nextSort(column, current, defaults) {
    if (current.sort !== column) {
        return { sort: column, direction: 'asc' };
    }

    if (current.direction !== 'desc') {
        return { sort: column, direction: 'desc' };
    }

    return { sort: defaults.sort, direction: defaults.direction };
}

export function toQuery(state, defaults, fields = []) {
    const query = {};

    if (state.q) {
        query.q = state.q;
    }

    fields.forEach((field) => {
        if (state[field.key]) {
            query[field.key] = String(state[field.key]);
        }
    });

    if (state.sort !== defaults.sort || state.direction !== defaults.direction) {
        query.sort = state.sort;
        query.direction = state.direction;
    }

    if (state.per_page !== defaults.per_page && defaults.sizes.includes(state.per_page)) {
        query.per_page = String(state.per_page);
    }

    if (Number(state.page) > 1) {
        query.page = String(state.page);
    }

    return query;
}

export function fromQuery(params, defaults, sortable, fields = []) {
    const column = String(params.sort ?? '');
    const wanted = Number.parseInt(params.per_page, 10);
    const page = Number.parseInt(params.page, 10);

    const state = {
        q: String(params.q ?? '').trim().toLowerCase(),
        sort: sortable.includes(column) ? column : defaults.sort,
        direction: String(params.direction ?? '').toLowerCase() === 'desc' ? 'desc' : 'asc',
        per_page: defaults.sizes.includes(wanted) ? wanted : defaults.per_page,
        page: Number.isInteger(page) && page > 1 ? page : 1,
    };

    fields.forEach((field) => {
        state[field.key] = readFilter(field, params[field.key]);
    });

    return state;
}

export function activeChips(state, fields = []) {
    const chips = [];

    if (state.q) {
        chips.push({ key: 'q', label: `Pencarian: "${state.q}"` });
    }

    fields.forEach((field) => {
        const value = state[field.key];

        if (value === '' || value === null || value === undefined) {
            return;
        }

        chips.push({ key: field.key, label: `${field.label}: ${describe(field, value)}` });
    });

    return chips;
}

/**
 * A filter value as this screen can actually mean it. A select keeps only a
 * choice it was offered — the alternative is a URL that filters on a value the
 * control cannot display, so the rows and the box disagree — and a day keeps
 * only the one spelling the server reads back.
 */
function readFilter(field, raw) {
    const value = String(raw ?? '').trim();

    if (value === '') {
        return '';
    }

    if ((field.options ?? []).length > 0) {
        return field.options.some((option) => String(option.value) === value) ? value : '';
    }

    if (field.type === 'date') {
        return DAY.test(value) ? value : '';
    }

    return value;
}

/**
 * The chip shows the word the person chose, not the value the query carries.
 * A value the choices no longer name is printed as it arrived: it still has to
 * be removable, and a deleted department is worth seeing in the address bar
 * rather than hidden behind a chip that says nothing.
 */
function describe(field, value) {
    const found = (field.options ?? []).find((option) => String(option.value) === String(value));

    return found ? found.label : String(value);
}

export function emptyStateVariant({ rows, total, filtersActive, canView }) {
    if (!canView) {
        return 'forbidden';
    }

    if (rows > 0) {
        return 'none';
    }

    return filtersActive || total > 0 ? 'filtered_out' : 'no_records';
}

export function perPageKey(screen, userKey) {
    return `pds.list.${userKey}.${screen}.perPage`;
}

export function pageWindow(current, last) {
    return {
        first: 1,
        prev: current > 1 ? current - 1 : null,
        next: current < last ? current + 1 : null,
        last,
    };
}
