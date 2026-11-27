import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import { Head, useForm } from '@inertiajs/react';
import { Transition } from '@headlessui/react';

/**
 * SCR-1.3 — the account's own facts.
 *
 * The email and the department are printed but not editable, and saying so on
 * the screen is part of the requirement: an account here is a record kept by an
 * administrator, not a profile someone can hand to a colleague.
 */
export default function UpdateProfileInformationForm({
    account,
    languages,
    className = '',
}) {
    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: account.name ?? '',
            phone: account.phone ?? '',
            locale: account.locale ?? 'id',
        });

    const submit = (e) => {
        e.preventDefault();

        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Informasi profil
                </h2>

                <p className="mt-1 text-sm text-gray-600">
                    Nama dan nomor telepon digunakan pada dokumen dan laporan
                    terlambat. Bahasa menentukan tampilan antarmuka, bukan bahasa
                    dokumen tercetak.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="name" value="Nama lengkap" />

                    <TextInput
                        id="name"
                        className="mt-1 block w-full"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        isFocused
                        autoComplete="name"
                    />

                    <InputError className="mt-2" message={errors.name} />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Email perusahaan" />

                    <TextInput
                        id="email"
                        className="mt-1 block w-full bg-gray-50 text-gray-500"
                        value={account.email}
                        readOnly
                        disabled
                    />

                    <p className="mt-1 text-sm text-gray-500">
                        Alamat dikelola administrator (FSD-201). Hubungi
                        administrator bila data ini perlu diperbaiki.
                    </p>
                </div>

                <div>
                    <InputLabel htmlFor="department" value="Departemen" />

                    <TextInput
                        id="department"
                        className="mt-1 block w-full bg-gray-50 text-gray-500"
                        value={account.department ?? 'Belum ditetapkan'}
                        readOnly
                        disabled
                    />

                    {account.roles?.length > 0 && (
                        <p className="mt-1 text-sm text-gray-500">
                            Peran: {account.roles.join(', ')}
                        </p>
                    )}
                </div>

                <div>
                    <InputLabel htmlFor="phone" value="No. telepon" />

                    <TextInput
                        id="phone"
                        className="mt-1 block w-full"
                        value={data.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                        autoComplete="tel"
                        placeholder="021 555 1234"
                    />

                    <p className="mt-1 text-sm text-gray-500">
                        Dipakai untuk eskalasi pada laporan terlambat; tidak
                        pernah ditampilkan ke pemasok.
                    </p>

                    <InputError className="mt-2" message={errors.phone} />
                </div>

                <div>
                    <InputLabel htmlFor="locale" value="Bahasa antarmuka" />

                    <SelectInput
                        id="locale"
                        className="mt-1 block w-full"
                        value={data.locale}
                        onChange={(e) => setData('locale', e.target.value)}
                    >
                        {languages.map((language) => (
                            <option
                                key={language.value}
                                value={language.value}
                            >
                                {language.label}
                            </option>
                        ))}
                    </SelectInput>

                    <InputError className="mt-2" message={errors.locale} />
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>
                        Simpan
                    </PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-gray-600">Tersimpan.</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
