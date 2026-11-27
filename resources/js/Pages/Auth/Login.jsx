import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * SCR-1.1 / FSD-101, with MSG-001 and MSG-005.
 *
 * There is no sign-up link, and the failure text never says whether the address
 * or the secret was the wrong half. Both are deliberate: accounts here are the
 * fourteen named identities created by an administrator, and a login screen
 * that explains which half failed is a way to enumerate them.
 *
 * "Ingat saya" is offered to everyone because the role is not known until the
 * password is. The server refuses it for an account that holds admin.access
 * (V-101 field note), so the box is a request here, not a promise.
 */
export default function Login({ status, expired, canResetPassword }) {
    const [reveal, setReveal] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Masuk" />

            {expired && (
                <div className="mb-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    Waktu sesi habis. Silakan masuk kembali.
                </div>
            )}

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="email" value="Email perusahaan" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full"
                        autoComplete="username"
                        isFocused={true}
                        onChange={(e) => setData('email', e.target.value)}
                    />

                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="password" value="Kata sandi" />

                    <div className="relative mt-1">
                        <TextInput
                            id="password"
                            type={reveal ? 'text' : 'password'}
                            name="password"
                            value={data.password}
                            className="block w-full pe-16"
                            autoComplete="current-password"
                            onChange={(e) => setData('password', e.target.value)}
                        />

                        <button
                            type="button"
                            onClick={() => setReveal((previous) => !previous)}
                            className="absolute inset-y-0 end-0 px-3 text-xs font-medium text-gray-500 hover:text-gray-700 focus:outline-none"
                        >
                            {reveal ? 'Sembunyi' : 'Lihat'}
                        </button>
                    </div>

                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-4 block">
                    <label className="flex items-center">
                        <Checkbox
                            name="remember"
                            checked={data.remember}
                            onChange={(e) =>
                                setData('remember', e.target.checked)
                            }
                        />
                        <span className="ms-2 text-sm text-gray-600">
                            Ingat saya
                        </span>
                    </label>
                </div>

                <div className="mt-4 flex items-center justify-end">
                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        >
                            Lupa password?
                        </Link>
                    )}

                    <PrimaryButton className="ms-4" disabled={processing}>
                        Masuk
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
