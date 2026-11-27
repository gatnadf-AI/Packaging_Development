import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import {
    conflictOffers,
    firstInvalid,
    isDirty,
    readOnlyBanner,
    submitErrors,
    validateOnBlur,
} from '@/Support/formState';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * FSD §2.5 — the form contract, written once.
 *
 * The requirement is a table of behaviours rather than a layout, and the
 * reviewed prototype is why: it validated some fields on blur and others only on
 * submit, focused nothing when a save failed, and let two administrators
 * overwrite each other. Each of those is a decision about *when* a rule speaks
 * and *what happens* when it does, so they live here and a screen supplies its
 * own rules, fields, and layout.
 *
 * What this component does not do is decide anything the server decides for
 * itself. The rules it is handed are the browser's copy of a form request's, and
 * a save that passes here goes through the same validation there: §2.5 promises
 * the sentence beside the field instead of after a round trip, not that the
 * client is the authority.
 */
export default function FormShell({
    action,
    method = 'post',
    values: initial,
    rules = {},
    order,
    crossField = null,
    extraFields = [],
    canEdit = true,
    locked = false,
    conflict = null,
    resetKey = null,
    submitLabel = 'Simpan',
    submitProps = null,
    onCancel = null,
    onSuccess = null,
    children,
}) {
    const serverErrors = usePage().props.errors ?? {};

    const [values, setValues] = useState(initial);
    const [localErrors, setLocalErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [attempted, setAttempted] = useState(false);
    const [showConflict, setShowConflict] = useState(false);
    const [pending, setPending] = useState(null);

    const fields = useMemo(() => order ?? [...Object.keys(rules), ...extraFields], [order, rules, extraFields]);
    const refs = useRef({});
    const formRef = useRef(null);
    const allowNext = useRef(false);
    const dirtyRef = useRef(false);

    const dirty = isDirty(values, initial);
    dirtyRef.current = dirty;

    // A reload after MSG-020 has to hand the person the row they are now looking
    // at, not the one they typed into: the token, and with it the form, resets
    // when the record it was opened on changes.
    useEffect(() => {
        setValues(initial);
        setLocalErrors({});
        setAttempted(false);
    }, [resetKey]);

    // Only this form's own fields: two panels can sit on one screen, and the
    // error bag the framework hands back is shared by both.
    const visibleErrors = useMemo(() => {
        const picked = {};

        fields.forEach((field) => {
            const message = localErrors[field] ?? serverErrors[field];

            if (message) {
                picked[field] = message;
            }
        });

        return picked;
    }, [fields, localErrors, serverErrors]);

    // §2.5: the first invalid field receives focus and the form scrolls to it.
    // Only after a save was attempted — a blur that showed a format error must
    // not drag the caret back to an earlier field the person already passed.
    useEffect(() => {
        if (!attempted) {
            return;
        }

        const field = firstInvalid(visibleErrors, fields);

        if (!field) {
            return;
        }

        const node = refs.current[field];

        node?.focus?.();

        // The scroll target is looked up in the DOM because Breeze's inputs
        // expose only `focus` through their ref handle. The name comes from the
        // screen's own rule set rather than from anything typed, so the
        // selector cannot be steered.
        const target = formRef.current?.querySelector(`[name="${field}"]`) ?? node;

        target?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
    }, [attempted, visibleErrors, fields]);

    // Browser close is guarded by the browser's own prompt; in-app navigation is
    // the three-way Simpan / Buang / Batal dialog below.
    useEffect(() => {
        const warn = (event) => {
            if (!dirtyRef.current) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, []);

    useEffect(() => {
        const before = (event) => {
            if (!dirtyRef.current || allowNext.current) {
                return;
            }

            event.preventDefault();
            setPending(event.detail?.visit ?? { url: window.location.href });
        };

        // router.on returns the deregistration; there is no router.off to call.
        const stop = router.on('before', before);

        return () => stop();
    }, []);

    const conflictMessage = serverErrors[conflict?.field ?? 'row_version'];

    useEffect(() => {
        setShowConflict(Boolean(conflictMessage));
    }, [conflictMessage]);

    const set = (field, value) => setValues((current) => ({ ...current, [field]: value }));

    const blur = (field) =>
        setLocalErrors((current) => ({ ...current, [field]: validateOnBlur(field, values[field], rules) }));

    const fieldProps = (field) => ({
        name: field,
        value: values[field] ?? '',
        disabled: !canEdit || locked || busy,
        onChange: (event) => set(field, event.target.value),
        onBlur: () => blur(field),
        ref: (node) => {
            refs.current[field] = node;
        },
    });

    async function submit(overrides = {}, afterSuccess = null) {
        // submitProps supplies what the screen derives from outside its own
        // fields (the version token, mostly); overrides are the caller's last
        // word, which is what lets MSG-020's "Kirim ulang saya" post the fresh
        // token it just re-read rather than the stale one still in props.
        const payload = { ...values, ...(submitProps ? submitProps(values) : {}), ...(overrides ?? {}) };
        const found = submitErrors(payload, rules, crossField);

        setLocalErrors(found);
        setAttempted(true);

        if (Object.keys(found).length > 0) {
            return;
        }

        setBusy(true);
        allowNext.current = true;

        await router.visit(action, {
            method,
            data: payload,
            preserveScroll: true,
            onSuccess: (response) => {
                setBusy(false);
                setLocalErrors({});
                setShowConflict(false);
                onSuccess?.(response);
                afterSuccess?.(response);
            },
            onError: () => setBusy(false),
            onFinish: () => {
                allowNext.current = false;
            },
        });
    }

    // MSG-020's two recoveries. Reload re-reads the row and its fresh token;
    // resend keeps what was typed and posts it against that fresh token, which is
    // why it reloads first rather than firing the same stale version again.
    const reload = () => {
        allowNext.current = true;
        setShowConflict(false);
        router.reload({ only: conflict?.props ?? [], preserveScroll: true });
    };

    const resend = () => {
        allowNext.current = true;

        router.reload({
            only: conflict?.props ?? [],
            preserveScroll: true,
            preserveState: true,
            onSuccess: (response) => {
                const token = conflict?.read?.(response.props);

                submit(token === undefined ? {} : { [conflict.field]: token });
            },
        });
    };

    function resume(visit) {
        if (!visit) {
            return;
        }

        allowNext.current = true;

        const href = visit.url?.href ?? String(visit.url ?? window.location.href);

        router.visit(href, { method: visit.method ?? 'get', data: visit.data, replace: visit.replace });
    }

    const banner = readOnlyBanner({ canEdit, locked });

    return (
        <>
            {banner && (
                <div className="mb-4 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {banner}
                </div>
            )}

            <form
                ref={formRef}
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className="space-y-6"
            >
                {children({ values, errors: visibleErrors, set, blur, fieldProps, dirty, busy, submit })}

                {canEdit && !locked && (
                    <div className="flex items-center gap-3 border-t border-gray-100 pt-4">
                        <PrimaryButton type="submit" disabled={busy}>
                            {submitLabel}
                        </PrimaryButton>
                        {onCancel && (
                            <SecondaryButton type="button" onClick={onCancel} disabled={busy}>
                                Batal
                            </SecondaryButton>
                        )}
                        {dirty && <span className="text-sm text-gray-500">Perubahan belum disimpan.</span>}
                    </div>
                )}
            </form>

            <Modal show={showConflict && Boolean(conflict)} onClose={() => setShowConflict(false)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Data ini diubah orang lain</h3>
                    <p className="mt-2 text-sm text-gray-600">{conflictMessage}</p>
                    <div className="mt-6 flex flex-wrap gap-3">
                        {conflictOffers().map((offer) => (
                            <SecondaryButton key={offer.key} onClick={offer.key === 'reload' ? reload : resend} disabled={busy}>
                                {offer.label}
                            </SecondaryButton>
                        ))}
                    </div>
                </div>
            </Modal>

            <Modal show={Boolean(pending)} onClose={() => setPending(null)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-gray-900">Ada perubahan yang belum disimpan</h3>
                    <p className="mt-2 text-sm text-gray-600">
                        Simpan untuk menyimpan perubahan ini, Buang untuk pergi tanpa menyimpan, atau kembali ke form.
                    </p>
                    <div className="mt-6 flex flex-wrap gap-3">
                        <PrimaryButton
                            onClick={() => {
                                const visit = pending;

                                setPending(null);
                                submit({}, () => resume(visit));
                            }}
                        >
                            Simpan
                        </PrimaryButton>
                        <SecondaryButton
                            onClick={() => {
                                const visit = pending;

                                setPending(null);
                                resume(visit);
                            }}
                        >
                            Buang
                        </SecondaryButton>
                        <SecondaryButton onClick={() => setPending(null)}>Batal</SecondaryButton>
                    </div>
                </div>
            </Modal>
        </>
    );
}
