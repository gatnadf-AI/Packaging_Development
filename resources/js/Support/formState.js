/**
 * FSD §2.5 — the form contract's decisions.
 *
 * A rule set here is a pair of questions a field may answer: is it required, and
 * is what was typed well-formed. §2.5 splits them in time — format while the
 * person leaves the field, completeness only when they try to submit — and splits
 * the two save actions the same way: Simpan draft refuses a bad format and
 * accepts an unfinished document, Ajukan refuses both.
 *
 * The server repeats every one of these checks in its form request. This is not
 * a substitute for that; it is what lets the screen show the sentence beside the
 * field instead of after a round trip.
 */

function isEmpty(value) {
    return value === undefined || value === null || value === '';
}

function fieldErrors(values, rules, includeRequired) {
    const errors = {};

    Object.entries(rules).forEach(([field, rule]) => {
        const value = values[field];

        if (isEmpty(value)) {
            if (includeRequired && rule.required) {
                errors[field] = rule.required;
            }

            return;
        }

        if (typeof rule.format === 'function') {
            const message = rule.format(value);

            if (message) {
                errors[field] = message;
            }
        }
    });

    return errors;
}

export function validateOnBlur(field, value, rules) {
    const rule = rules[field];

    if (!rule || isEmpty(value) || typeof rule.format !== 'function') {
        return null;
    }

    return rule.format(value) ?? null;
}

export function draftErrors(values, rules) {
    return fieldErrors(values, rules, false);
}

export function submitErrors(values, rules, crossField) {
    const errors = fieldErrors(values, rules, true);

    return { ...errors, ...(crossField ? crossField(values) : {}) };
}

export function firstInvalid(errors, order) {
    return order.find((field) => Object.prototype.hasOwnProperty.call(errors, field)) ?? null;
}

function isRecord(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function sameValue(a, b) {
    if (Array.isArray(a) && Array.isArray(b)) {
        if (a.length !== b.length) {
            return false;
        }

        // A repeating block is a list the person ordered: the specification
        // sheet prints its sizes top to bottom, so a row moved from third to
        // first is a change even though every row is still here, and two rows
        // whose numbers swapped are not the two rows they were. Objects are
        // compared key by key for the same reason.
        if (a.some(isRecord) || b.some(isRecord)) {
            return a.every((item, index) => sameValue(item, b[index]));
        }

        // A checkbox set, by contrast, is a set: the panel's own order is the
        // order of the reference list, not a fact the person chose.
        const key = (list) => [...list].map((item) => String(item)).sort().join('\u0000');

        return key(a) === key(b);
    }

    if (isRecord(a) && isRecord(b)) {
        const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

        return [...keys].every((key) => sameValue(a[key], b[key]));
    }

    return (a ?? null) === (b ?? null);
}

export function isDirty(values, original) {
    const keys = new Set([...Object.keys(values ?? {}), ...Object.keys(original ?? {})]);

    return [...keys].some((key) => !sameValue(values?.[key], original?.[key]));
}

/**
 * MSG-020. The two recoveries the catalogue offers, in the order the dialog
 * shows them: reload first, because the other person's save is usually the
 * version the reader wants; resend second, because it keeps their typing.
 */
export function conflictOffers() {
    return [
        { key: 'reload', label: 'Muat ulang data' },
        { key: 'resend', label: 'Kirim ulang saya' },
    ];
}

export function readOnlyBanner({ canEdit, locked }) {
    if (locked) {
        return 'Dokumen terkunci setelah persetujuan.';
    }

    return canEdit ? null : 'Anda hanya memiliki akses baca.';
}
