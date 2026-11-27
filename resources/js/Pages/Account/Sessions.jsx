import AppLayout from '@/Layouts/AppLayout';
import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import { moment } from '@/Support/format';
import { Head, router, usePage } from '@inertiajs/react';

/**
 * FSD-105 — the sessions this account holds, and the power to close any of
 * them except the one being used to look at the list.
 *
 * Two honest limits are shown rather than hidden. "Perkiraan lokasi" reads the
 * address: a private range is labelled as the local network and anything else
 * stays blank, because there is no geolocation service on this host and a made
 * up city in a security screen is worse than an empty cell. The list is the
 * framework's own live-session window, so a session that has already expired
 * but not yet been garbage-collected does not appear.
 */
export default function Sessions({ sessions, idleMinutes, warnMinutes }) {
    const errors = usePage().props.errors ?? {};

    const revoke = (id) => {
        if (!window.confirm('Tutup sesi ini? Perangkat tersebut harus masuk kembali.')) {
            return;
        }

        router.delete(route('profile.sessions.destroy', id), {
            preserveScroll: true,
        });
    };


    return (
        <AppLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Sesi aktif
                </h2>
            }
        >
            <Head title="Sesi aktif" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="rounded border border-gray-200 bg-white p-4 text-sm text-gray-600">
                        Sesi ditutup otomatis setelah {idleMinutes} menit tanpa
                        aktivitas; Anda akan diberi tahu pada menit{' '}
                        {warnMinutes}. Menutup sesi dari daftar ini memaksa
                        perangkat itu masuk kembali.
                    </div>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="px-4 py-3">Perangkat</th>
                                    <th className="px-4 py-3">IP</th>
                                    <th className="px-4 py-3">Perkiraan lokasi</th>
                                    <th className="px-4 py-3">Aktivitas terakhir</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {sessions.map((session) => (
                                    <tr key={session.id}>
                                        <td className="px-4 py-3 font-medium text-gray-800">
                                            {session.device}
                                            {session.is_current && (
                                                <span className="ms-2 rounded bg-green-100 px-2 py-0.5 text-xs text-green-800">
                                                    sesi ini
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">
                                            {session.ip ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">
                                            {session.location ?? 'tidak tersedia'}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">
                                            {moment(session.last_action_at)}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {!session.is_current && (
                                                <DangerButton
                                                    onClick={() => revoke(session.id)}
                                                >
                                                    Tutup
                                                </DangerButton>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {sessions.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-4 py-6 text-center text-gray-500"
                                        >
                                            Tidak ada sesi lain yang sedang aktif.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <InputError message={errors.session} />
                </div>
            </div>
        </AppLayout>
    );
}
