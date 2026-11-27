import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

/**
 * SCR-1.3 Profil saya.
 *
 * Two cards, not Breeze's three: the account cannot be deleted from here,
 * because FSD-202 ends a person's access by deactivating the account and every
 * document they signed has to keep their name. The link to the session list
 * stands in for it, since "who else is signed in as me" is the question people
 * reach this screen for.
 */
export default function Edit({
    account,
    languages,
    passwordRules,
    sessionLifetimeMinutes,
}) {
    return (
        <AppLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Profil saya
                </h2>
            }
        >
            <Head title="Profil saya" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdateProfileInformationForm
                            account={account}
                            languages={languages}
                            className="max-w-xl"
                        />
                    </div>

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdatePasswordForm
                            rules={passwordRules}
                            className="max-w-xl"
                        />
                    </div>

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <h2 className="text-lg font-medium text-gray-900">
                            Sesi aktif
                        </h2>

                        <p className="mt-1 text-sm text-gray-600">
                            Daftar perangkat yang masuk sebagai akun Anda dalam
                            {sessionLifetimeMinutes} menit terakhir, dan
                            penutupannya.
                        </p>

                        <Link
                            href={route('profile.sessions.index')}
                            className="mt-4 inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        >
                            Lihat sesi aktif
                        </Link>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
