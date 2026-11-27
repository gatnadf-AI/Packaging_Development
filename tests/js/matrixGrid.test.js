// FSD §2.4/§2.5 applied to SCR-2.3 — the arithmetic of a grid that is being
// edited one cell at a time, with no DOM in sight.
//
// What the server owns and what the browser owns is the same split the list
// component made: the grid a person is editing is a draft of decisions, and a
// draft has to say (a) what each cell would become, (b) which cells are actually
// part of the draft at all, and (c) what the save should name as the level it is
// saving against. Those three are arithmetic on a map, so they are tested here;
// the part that is a browser — the click, the focus, the sticky header — is the
// page component's own.
import test from 'node:test';
import assert from 'node:assert/strict';

import {
    cellView,
    cycleCell,
    groupRows,
    nextLevel,
    pendingEdits,
    previewTotals,
} from '../../resources/js/Support/matrixGrid.js';

const token = (feature, role) => `${feature}|${role}`;

// The cycle is the document's, not a preference: PermissionLevel::next() in the
// server runs Full → Read → None → Full, and a grid that cycled differently
// would put a different meaning on the same click.
test('a click cycles the cell in the order the matrix defines', () => {
    assert.equal(nextLevel('FULL'), 'READ');
    assert.equal(nextLevel('READ'), 'NONE');
    assert.equal(nextLevel('NONE'), 'FULL');
});

test('an unknown level is refused rather than cycled into something', () => {
    assert.throws(() => nextLevel('MAYBE'), /MAYBE/);
});

test('clicking a cell puts it in the draft with the level it started from', () => {
    const draft = cycleCell({}, 'report.export', 'mkt', 'READ');

    assert.deepEqual(draft[token('report.export', 'mkt')], {
        feature: 'report.export',
        role: 'mkt',
        from: 'READ',
        to: 'NONE',
    });
});

// `from` is the compare-and-set value the save puts in its WHERE clause, so it is
// captured once from the server's grid and then carried through further clicks.
// Re-reading it from the draft each time would send the draft's own level back as
// the level the screen saw, which wins every race by pretending there was none.
test('a second click on the same cell keeps the level the server showed', () => {
    let draft = cycleCell({}, 'report.export', 'mkt', 'READ');
    draft = cycleCell(draft, 'report.export', 'mkt', 'READ');

    assert.deepEqual(draft[token('report.export', 'mkt')], {
        feature: 'report.export',
        role: 'mkt',
        from: 'READ',
        to: 'FULL',
    });
});

// The preview on the server drops an edit that would not move the cell, and the
// draft has to agree with it before the dialog is even opened: a "2 changes"
// header over a list of one is exactly the sort of off-by-one that makes people
// distrust a diff.
test('cycling a cell back to where it started takes it out of the draft', () => {
    let draft = {};

    for (let click = 0; click < 3; click += 1) {
        draft = cycleCell(draft, 'report.export', 'mkt', 'READ');
    }

    assert.deepEqual(draft, {});
    assert.deepEqual(pendingEdits(draft), []);
});

test('the draft lists its edits in a stable order, feature first then role', () => {
    let draft = cycleCell({}, 'admin.access', 'admin', 'FULL');
    draft = cycleCell(draft, 'report.export', 'mkt', 'READ');
    draft = cycleCell(draft, 'report.export', 'bod', 'NONE');

    assert.deepEqual(
        pendingEdits(draft).map((edit) => `${edit.feature} x ${edit.role}`),
        ['admin.access x admin', 'report.export x bod', 'report.export x mkt'],
    );
});

test('a cell that is in the draft is drawn as pending and says what it would become', () => {
    const draft = cycleCell({}, 'report.export', 'mkt', 'READ');

    assert.deepEqual(cellView('report.export', 'mkt', 'READ', draft), {
        level: 'READ',
        pending: 'NONE',
        changed: true,
    });
});

test('a cell that is not in the draft is drawn as the server has it', () => {
    assert.deepEqual(cellView('report.export', 'mkt', 'READ', {}), {
        level: 'READ',
        pending: null,
        changed: false,
    });
});

// §2.4 draws one screen out of forty-one lists, and the matrix has the same
// problem in the other direction: twenty-nine feature rows across six groups.
// The grouping is the document's structure, so the order the server sent is kept
// and the groups are opened in that order rather than alphabetically.
test('the rows are grouped without losing the order they arrived in', () => {
    const rows = [
        { key: 'fab.create', group: 'FAB' },
        { key: 'project.create', group: 'NPD' },
        { key: 'fab.check', group: 'FAB' },
        { key: 'report.export', group: 'Laporan' },
    ];

    assert.deepEqual(
        groupRows(rows).map((section) => ({ group: section.group, keys: section.rows.map((r) => r.key) })),
        [
            { group: 'FAB', keys: ['fab.create', 'fab.check'] },
            { group: 'NPD', keys: ['project.create'] },
            { group: 'Laporan', keys: ['report.export'] },
        ],
    );
});

// FSD-205 asks the diff to say "which roles gain or lose which features and how
// many users are affected". The dialog needs those three numbers, and a level is
// a gain or a loss by rank, not by name: NONE → READ is a gain, READ → NONE is a
// loss, and FULL → READ takes an act away while leaving the screen visible.
test('the diff counts gains, losses and the accounts reached', () => {
    const totals = previewTotals([
        { from: 'NONE', to: 'READ', users_affected: 3 },
        { from: 'FULL', to: 'READ', users_affected: 2 },
        { from: 'READ', to: 'NONE', users_affected: 2 },
        { from: 'READ', to: 'FULL', users_affected: 5 },
    ]);

    assert.deepEqual(totals, { gains: 2, losses: 2, accounts: 12 });
});

test('an empty diff counts as nothing rather than as a change with no rows', () => {
    assert.deepEqual(previewTotals([]), { gains: 0, losses: 0, accounts: 0 });
});
