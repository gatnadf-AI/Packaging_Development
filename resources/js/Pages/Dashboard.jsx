import AppLayout from '@/Layouts/AppLayout';
import { announcementCard } from '@/Support/dashboardCards';
import { Head, Link, usePage } from '@inertiajs/react';

/**
 * Phase 1 landing (FSD-901).
 *
 * The queue widgets are part of the workflow engine and are deliberately not
 * faked here: they arrive in Phase 3, when there is something in a queue to
 * count. What this screen can already tell the user about their own account is
 * the useful part of a sign-off — the identity they were given, the department
 * they belong to, and when the account was last used.
 *
 * FSD-901 asks for cards rather than one layout, and the announcements card is
 * one of the ones it names. It reads the shared prop the banner reads, so the
 * two can never disagree about how many notices are live or who they address:
 * ActiveAnnouncements has answered both on the server, and announcementCard()
 * only decides whether there is anything to say and how much of it fits above the
 * fold. A card that was filtered again here would be a second place knowing what
 * an audience means, which is what TSD-704 forbids.
 */
export default function Dashboard({ account }) {
    const announcements = announcementCard(usePage().props.announcements);
    const clock = (iso) =>
        iso
            ? new Date(iso).toLocaleString('id-ID', {
                  day: '2-digit',
                  month: 'long',
                  year: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
              })
            : 'belum pernah';

    return (
        <AppLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Dashboard
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="rounded border border-gray-200 bg-white p-4">
                        <h3 className="text-sm font-semibold text-gray-700">
                            Selamat datang, {account.name}
                        </h3>
                        <dl className="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-gray-500">Email</dt>
                                <dd className="font-medium text-gray-800">
                                    {account.email}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Departemen</dt>
                                <dd className="font-medium text-gray-800">
                                    {account.department ?? 'belum ditetapkan'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Identitas</dt>
                                <dd className="font-medium text-gray-800">
                                    {account.roles.length > 0
                                        ? account.roles.join(', ')
                                        : 'tidak ada — hubungi administrator'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Masuk terakhir</dt>
                                <dd className="font-medium text-gray-800">
                                    {clock(account.last_login_at)}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    {announcements && (
                        <div className="rounded border border-gray-200 bg-white p-4">
                            <h3 className="text-sm font-semibold text-gray-700">
                                {announcements.label}
                            </h3>

                            <ul className="mt-3 space-y-3">
                                {announcements.items.map((item) => (
                                    <li
                                        key={item.id}
                                        className="border-s-2 border-amber-200 ps-3"
                                    >
                                        <div className="text-sm font-medium text-gray-800">
                                            {item.title}
                                        </div>
                                        <div className="mt-1 whitespace-pre-line text-sm text-gray-600">
                                            {item.body}
                                        </div>
                                        <div className="mt-1 text-xs text-gray-500">
                                            Tayang sejak {clock(item.at)}
                                        </div>
                                    </li>
                                ))}
                            </ul>

                            {/* The rest are not missing: they are above this card,
                            in the banner, which is where FSD-208 puts the
                            acknowledgement. Saying how many keeps the two
                            renderings of one list countable against each other. */}
                            {announcements.hidden > 0 && (
                                <p className="mt-3 text-xs text-gray-500">
                                    {announcements.hidden} pengumuman lain masih
                                    tertayang di banner atas layar ini.
                                </p>
                            )}
                        </div>
                    )}

                    <div className="rounded border border-gray-200 bg-white p-4 text-sm text-gray-600">
                        Antrian kerja, dokumen dan laporan menyusul pada fase
                        berikutnya sesuai rencana pengantaran TSD §15.
                        {' '}
                        <Link
                            href={route('profile.sessions.index')}
                            className="text-indigo-600 underline hover:text-indigo-900"
                        >
                            Lihat sesi aktif Anda
                        </Link>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
