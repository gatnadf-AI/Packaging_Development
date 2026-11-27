/**
 * FSD-204 with FSD-205 — the draft half of the matrix editor.
 *
 * The server owns the grid; the browser owns the page of edits a person is
 * assembling before any of it is saved. That page is a map from a cell's natural
 * key to the decision, and the three things it has to get right are arithmetic
 * rather than rendering, so they live here where they can be tested:
 *
 * The cycle is the document's, not a preference. PermissionLevel::next() runs
 * Full → Read → None → Full, and the grid's click has to mean the same move.
 *
 * `from` is captured once, from the server's grid, and carried through every
 * further click on that cell. It is the value the save puts in its compare-and-set,
 * so re-deriving it from the draft would tell the server the draft was the truth.
 *
 * A cell cycled back to the level it started at is not part of the draft at all,
 * which is the same rule MatrixGrid::preview() applies to the diff. The header
 * count and the list the person reads have to be computed from one rule.
 */

const CYCLE = {
    FULL: 'READ',
    READ: 'NONE',
    NONE: 'FULL',
};

const RANK = {
    NONE: 0,
    READ: 1,
    FULL: 2,
};

const key = (feature, role) => `${feature}|${role}`;

export function nextLevel(level) {
    const next = CYCLE[level];

    if (next === undefined) {
        throw new Error(`Unknown matrix level: ${level}`);
    }

    return next;
}

/**
 * Click one cell. Returns a new draft — the old one is never mutated, so a React
 * re-render cannot be reading a half-applied edit.
 */
export function cycleCell(draft, feature, role, serverLevel) {
    const token = key(feature, role);
    const existing = draft[token];
    const from = existing ? existing.from : serverLevel;
    const to = nextLevel(existing ? existing.to : serverLevel);

    const next = { ...draft };

    if (to === from) {
        delete next[token];

        return next;
    }

    next[token] = { feature, role, from, to };

    return next;
}

/**
 * The draft as the server will be told about it: one entry per changed cell, in
 * an order that does not depend on insertion, so a preview and a save describe
 * the same page.
 *
 * @param {Record<string, {feature: string, role: string, from: string, to: string}>} draft
 */
export function pendingEdits(draft) {
    return Object.values(draft).sort((a, b) => {
        if (a.feature !== b.feature) {
            return a.feature < b.feature ? -1 : 1;
        }

        if (a.role !== b.role) {
            return a.role < b.role ? -1 : 1;
        }

        return 0;
    });
}

/**
 * How one cell is drawn: the level the server holds, plus the level the draft
 * would move it to. The two are reported apart rather than as one value because
 * the screen has to show both — a cell that already says NONE and is about to
 * say FULL is a decision in progress, and flattening them would hide it.
 */
export function cellView(feature, role, serverLevel, draft) {
    const edit = draft[key(feature, role)];

    return {
        level: serverLevel,
        pending: edit ? edit.to : null,
        changed: Boolean(edit),
    };
}

/**
 * Feature rows in document order, opened by group in the order the groups first
 * appear. Alphabetical section headers would be a second ordering of the same
 * sheet, and FSD §6 is read as one list from top to bottom.
 */
export function groupRows(rows) {
    const sections = [];
    const index = new Map();

    for (const row of rows) {
        if (!index.has(row.group)) {
            const section = { group: row.group, rows: [] };

            index.set(row.group, section);
            sections.push(section);
        }

        index.get(row.group).rows.push(row);
    }

    return sections;
}

/**
 * The three numbers FSD-205 asks a preview to make visible: how many cells gain
 * rights, how many lose them, and how many accounts the page reaches. The last
 * one is a sum over cells rather than a distinct count of people, because a
 * person who holds two of the roles on the page is reached twice — and the row
 * list underneath shows the per-role number whoever is deciding has to read.
 */
export function previewTotals(diff) {
    let gains = 0;
    let losses = 0;
    let accounts = 0;

    for (const row of diff) {
        if (RANK[row.to] > RANK[row.from]) {
            gains += 1;
        } else {
            losses += 1;
        }

        accounts += row.users_affected;
    }

    return { gains, losses, accounts };
}
