import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * TSD-1104 — the answer is the same sentence for an address that exists and an
 * address that does not, so this screen shows a status and never an error that
 * could name which half of that is true.
 */
export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title="Lupa password" />

            <div className="mb-4 text-sm text-gray-600">
                Lupa password? Tidak masalah. Masukkan alamat email Anda dan kami
                akan mengirim tautan untuk membuat password baru.
            </div>

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <form onSubmit={submit}>
                <TextInput
                    id="email"
                    type="email"
                    name="email"
                    value={data.email}
                    className="mt-1 block w-full"
                    isFocused={true}
                    onChange={(e) => setData('email', e.target.value)}
                />

                <InputError message={errors.email} className="mt-2" />

                <div className="mt-4 flex items-center justify-end">
                    <Link
                        href={route('login')}
                        className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Kembali ke halaman masuk
                    </Link>

                    <PrimaryButton className="ms-4" disabled={processing}>
                        Kirim tautan
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
