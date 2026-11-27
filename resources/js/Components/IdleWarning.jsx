import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * FSD-103 with MSG-004 and MSG-005.
 *
 * The countdown is a browser timer, so it cannot be more trustworthy than the
 * browser: it is the reminder, not the rule. The rule is the server session, and
 * if this timer is wrong the user simply meets the normal unauthenticated
 * redirect a moment earlier than the text promised. "Perpanjang" therefore posts
 * a real request — the session only lives again if the server agrees.
 */
export default function IdleWarning({ warnMinutes = 25, idleMinutes = 30 }) {
    const [warning, setWarning] = useState(false);
    const warnAt = useRef(null);
    const expireAt = useRef(null);
    const ticker = useRef(null);

    const reset = () => {
        const now = Date.now();
        warnAt.current = now + warnMinutes * 60_000;
        expireAt.current = now + idleMinutes * 60_000;
        setWarning(false);
    };

    const expire = () => {
        // MSG-005 belongs on the login screen, and the login screen only knows
        // the visit was an expiry because the query says so. The intended route
        // itself is restored by the server: whichever request the user makes
        // next while the session is gone is remembered and replayed after the
        // next successful login.
        router.get(route('login', { expired: 1 }));
    };

    useEffect(() => {
        reset();

        const check = () => {
            const now = Date.now();

            if (now >= expireAt.current) {
                expire();

                return;
            }

            if (now >= warnAt.current) {
                setWarning(true);
            }
        };

        ticker.current = window.setInterval(check, 15_000);

        const offNavigate = router.on('navigate', reset);

        return () => {
            window.clearInterval(ticker.current);
            if (typeof offNavigate === 'function') {
                offNavigate();
            }
        };
    }, [warnMinutes, idleMinutes]);

    const extend = async () => {
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(route('session.extend'), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                expire();

                return;
            }

            reset();
        } catch {
            // A dead keep-alive endpoint must not pretend the session is alive.
            expire();
        }
    };

    const gap = Math.max(1, idleMinutes - warnMinutes);

    return (
        <Modal show={warning} maxWidth="sm" onClose={() => setWarning(false)}>
            <h2 className="text-lg font-medium text-gray-900">
                Sesi Anda akan berakhir dalam {gap} menit. Perpanjang?
            </h2>

            <div className="mt-4 flex justify-end gap-2">
                <SecondaryButton
                    onClick={() => {
                        setWarning(false);
                        router.post(route('logout'));
                    }}
                >
                    Keluar sekarang
                </SecondaryButton>

                <SecondaryButton
                    onClick={extend}
                    className="!border-indigo-600 !bg-indigo-600 !text-white hover:!bg-indigo-700"
                >
                    Perpanjang
                </SecondaryButton>
            </div>
        </Modal>
    );
}
