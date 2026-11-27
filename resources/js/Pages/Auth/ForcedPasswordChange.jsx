import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, router, useForm } from '@inertiajs/react';

/**
 * FSD-104 (V-102) — the gate an administrator-set password has to pass through
 * before the account can do anything else.
 *
 * The current password is not asked for: the user never chose it, and demanding
 * it would leak nothing but a support call. The address is shown because the
 * rule that refuses a password equal to the email local part only makes sense
 * when you can see the address you are being measured against.
 */
export default function ForcedPasswordChange({ email, rules }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('password.change.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Ganti password" />

            <div className="mb-4 text-sm text-gray-600">
                Password akun Anda dibuat oleh administrator. Ganti terlebih
                dahulu sebelum menggunakan sistem ini.
            </div>

            <div className="mb-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Masuk sebagai <span className="font-medium">{email}</span>
            </div>

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="password" value="Password baru" />

                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full"
                        autoComplete="new-password"
                        isFocused={true}
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <InputError message={errors.password} className="mt-2" />

                    <p className="mt-1 text-xs text-gray-500">{rules}</p>
                </div>

                <div className="mt-4">
                    <InputLabel
                        htmlFor="password_confirmation"
                        value="Konfirmasi password"
                    />

                    <TextInput
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        className="mt-1 block w-full"
                        autoComplete="new-password"
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                    />

                    <InputError
                        message={errors.password_confirmation}
                        className="mt-2"
                    />
                </div>

                <div className="mt-4 flex items-center justify-end">
                    <SecondaryButton
                        className="me-2"
                        disabled={processing}
                        onClick={(e) => {
                            e.preventDefault();
                            router.post(route('logout'));
                        }}
                    >
                        Keluar
                    </SecondaryButton>

                    <PrimaryButton disabled={processing}>
                        Ganti password
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
