// FSD §2.5 — the form-page contract, the decisions that do not need a DOM.
//
// The split §2.5 draws is between a format error (knowable while the person is
// still typing) and a completeness error (only knowable when they try to leave),
// and between Simpan draft (format only) and Ajukan (everything). Those are
// arithmetic on a rule set, so they are tested here; the browser's part — focus,
// scroll, the unload guard — is exercised by the Inertia screen itself.
import test from 'node:test';
import assert from 'node:assert/strict';

import {
    conflictOffers,
    draftErrors,
    firstInvalid,
    isDirty,
    readOnlyBanner,
    submitErrors,
    validateOnBlur,
} from '../../resources/js/Support/formState.js';

const RULES = {
    name: {
        required: 'Nama wajib diisi.',
        format: (value) => (String(value).length <= 60 ? null : 'Nama maksimal 60 karakter.'),
    },
    email: {
        required: 'Email wajib diisi.',
        format: (value) => (/@/.test(String(value)) ? null : 'Format email belum benar.'),
    },
    phone: {
        format: (value) => (value === '' || /^[0-9+-]*$/.test(String(value)) ? null : 'Nomor telepon hanya boleh angka.'),
    },
};

const ORDER = ['name', 'email', 'phone'];

test('blur reports a format error but never a missing value', () => {
    assert.equal(validateOnBlur('email', '', RULES), null);
    assert.equal(validateOnBlur('email', 'bukan-email', RULES), 'Format email belum benar.');
});

test('blur ignores a field nobody marked as formatted', () => {
    assert.equal(validateOnBlur('phone', '', RULES), null);
});

test('saving a draft refuses a bad format but accepts an empty mandatory field', () => {
    assert.deepEqual(draftErrors({ name: '', email: 'x@y.z', phone: 'abc' }, RULES),
        { phone: 'Nomor telepon hanya boleh angka.' });
});

test('submitting refuses the missing mandatory fields as well', () => {
    assert.deepEqual(submitErrors({ name: '', email: '', phone: '' }, RULES),
        { name: 'Nama wajib diisi.', email: 'Email wajib diisi.' });
});

test('a field that is filled is still checked for format on submit', () => {
    assert.deepEqual(submitErrors({ name: 'A'.repeat(61), email: 'x@y.z', phone: '' }, RULES),
        { name: 'Nama maksimal 60 karakter.' });
});

test('a cross-field rule runs on submit and not on a draft save', () => {
    const values = { name: 'Sisa', email: 'blocked@x.z', phone: '' };
    const cross = (v) => (v.email === 'blocked@x.z' ? { email: 'Email ini tidak boleh dipakai.' } : {});

    assert.deepEqual(submitErrors(values, RULES, cross), { email: 'Email ini tidak boleh dipakai.' });
    assert.deepEqual(draftErrors(values, RULES, cross), {});
});

test('the first invalid field is the one in screen order, not object order', () => {
    assert.equal(firstInvalid({ phone: 'x', name: 'y' }, ORDER), 'name');
    assert.equal(firstInvalid({}, ORDER), null);
});

test('an untouched form is not dirty', () => {
    assert.equal(isDirty({ name: 'A', email: 'a@b.c' }, { name: 'A', email: 'a@b.c' }), false);
});

test('clearing a value back to the original is not dirty either', () => {
    assert.equal(isDirty({ name: 'B' }, { name: 'B' }), false);
    assert.equal(isDirty({ name: 'B' }, { name: 'A' }), true);
});

test('a field added to the form counts as a change', () => {
    assert.equal(isDirty({ name: 'A', phone: '12' }, { name: 'A' }), true);
});

// The role panel of SCR-2.1 holds a checkbox set. The array it hands the guard is
// rebuilt on every render, so an identity compare calls an untouched form dirty
// and the person is asked to save a screen they never edited.
test('an unchanged checkbox set is not dirty', () => {
    assert.equal(isDirty({ roles: ['admin', 'rd'] }, { roles: ['admin', 'rd'] }), false);
});

test('adding a role to the set is a change', () => {
    assert.equal(isDirty({ roles: ['admin', 'rd'] }, { roles: ['admin'] }), true);
});

test('the set is compared as a set, not as a sequence', () => {
    assert.equal(isDirty({ roles: ['rd', 'admin'] }, { roles: ['admin', 'rd'] }), false);
});

// SCR-4.3's sheet replaces its three blocks whole, and the printed document reads
// them top to bottom: size row 1 is the first row on paper and the first row in
// the database's sort column. So a block is a sequence, and comparing it the way
// a checkbox set is compared calls a moved row unchanged — the person would
// leave the sheet with the guard saying nothing was edited while the order of the
// document had in fact moved. Rows are rebuilt on every render like the role
// array above, so two identical rows are still not the same object.
test('an unchanged specification block is not dirty', () => {
    const rows = [{ code: 'TL', nominal: '12.5' }, { code: 'OD', nominal: '30.0' }];

    assert.equal(isDirty({ sizes: [...rows.map((row) => ({ ...row }))] }, { sizes: rows }), false);
});

test('editing a cell inside a block row is a change', () => {
    const original = [{ code: 'TL', nominal: '12.5' }];
    const edited = [{ code: 'TL', nominal: '12.8' }];

    assert.equal(isDirty({ sizes: edited }, { sizes: original }), true);
});

test('a block row moved to another position is a change', () => {
    const original = [{ code: 'TL' }, { code: 'OD' }];
    const moved = [{ code: 'OD' }, { code: 'TL' }];

    assert.equal(isDirty({ sizes: moved }, { sizes: original }), true);
});

test('a block that lost a row is a change', () => {
    assert.equal(isDirty({ components: [{ component: 'Botol' }] }, { components: [] }), true);
});

test('the version conflict offers the two recoveries and nothing else', () => {
    assert.deepEqual(conflictOffers(), [
        { key: 'reload', label: 'Muat ulang data' },
        { key: 'resend', label: 'Kirim ulang saya' },
    ]);
});

test('an editable record shows no banner', () => {
    assert.equal(readOnlyBanner({ canEdit: true, locked: false }), null);
});

test('a locked document names the lock as the reason', () => {
    assert.equal(readOnlyBanner({ canEdit: true, locked: true }), 'Dokumen terkunci setelah persetujuan.');
});

test('a read-only grant names the grant as the reason', () => {
    assert.equal(readOnlyBanner({ canEdit: false, locked: false }), 'Anda hanya memiliki akses baca.');
});

test('a record that is both locked and ungrantable says it is locked', () => {
    assert.equal(readOnlyBanner({ canEdit: false, locked: true }), 'Dokumen terkunci setelah persetujuan.');
});
