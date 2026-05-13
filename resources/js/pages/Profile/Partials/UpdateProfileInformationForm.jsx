import InputError from '@/components/InputError';
import InputLabel from '@/components/InputLabel';
import PrimaryButton from '@/components/PrimaryButton';
import TextInput from '@/components/TextInput';
import { Transition } from '@headlessui/react';
import { useForm, usePage } from '@inertiajs/react';

export default function UpdateProfileInformation({
    status,
    className = '',
}) {
    const user = usePage().props.auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            id_no: user.id_no,
            firstname: user.firstname,
            lastname: user.lastname,
            middlename: user.middlename ?? '',
            extname: user.extname ?? '',
        });

    const submit = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Profile Information
                </h2>

                <p className="mt-1 text-sm text-gray-600">
                    Update your account information.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="id_no" value="User ID" />

                    <TextInput
                        id="id_no"
                        className="mt-1 block w-full"
                        value={data.id_no}
                        onChange={(e) => setData('id_no', e.target.value)}
                        required
                        isFocused
                        autoComplete="username"
                    />

                    <InputError className="mt-2" message={errors.id_no} />
                </div>

                <div className="grid gap-6 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="firstname" value="First Name" />

                        <TextInput
                            id="firstname"
                            className="mt-1 block w-full"
                            value={data.firstname}
                            onChange={(e) => setData('firstname', e.target.value)}
                            required
                            autoComplete="given-name"
                        />

                        <InputError className="mt-2" message={errors.firstname} />
                    </div>

                    <div>
                        <InputLabel htmlFor="lastname" value="Last Name" />

                        <TextInput
                            id="lastname"
                            className="mt-1 block w-full"
                            value={data.lastname}
                            onChange={(e) => setData('lastname', e.target.value)}
                            required
                            autoComplete="family-name"
                        />

                        <InputError className="mt-2" message={errors.lastname} />
                    </div>
                </div>

                <div className="grid gap-6 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="middlename" value="Middle Name" />

                        <TextInput
                            id="middlename"
                            className="mt-1 block w-full"
                            value={data.middlename}
                            onChange={(e) => setData('middlename', e.target.value)}
                        />

                        <InputError className="mt-2" message={errors.middlename} />
                    </div>

                    <div>
                        <InputLabel htmlFor="extname" value="Extension Name" />

                        <TextInput
                            id="extname"
                            className="mt-1 block w-full"
                            value={data.extname}
                            onChange={(e) => setData('extname', e.target.value)}
                            placeholder="e.g. Jr., Sr., III"
                        />

                        <InputError className="mt-2" message={errors.extname} />
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Save</PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-gray-600">
                            Saved.
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
