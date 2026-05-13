import InputError from '@/components/InputError';
import InputLabel from '@/components/InputLabel';
import PrimaryButton from '@/components/PrimaryButton';
import TextInput from '@/components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        id_no: '',
        firstname: '',
        lastname: '',
        middlename: '',
        extname: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Register" />

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="id_no" value="User ID" />

                    <TextInput
                        id="id_no"
                        name="id_no"
                        value={data.id_no}
                        className="mt-1 block w-full"
                        autoComplete="username"
                        isFocused={true}
                        onChange={(e) => setData('id_no', e.target.value)}
                        required
                    />

                    <InputError message={errors.id_no} className="mt-2" />
                </div>

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="firstname" value="First Name" />

                        <TextInput
                            id="firstname"
                            name="firstname"
                            value={data.firstname}
                            className="mt-1 block w-full"
                            autoComplete="given-name"
                            onChange={(e) => setData('firstname', e.target.value)}
                            required
                        />

                        <InputError message={errors.firstname} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="lastname" value="Last Name" />

                        <TextInput
                            id="lastname"
                            name="lastname"
                            value={data.lastname}
                            className="mt-1 block w-full"
                            autoComplete="family-name"
                            onChange={(e) => setData('lastname', e.target.value)}
                            required
                        />

                        <InputError message={errors.lastname} className="mt-2" />
                    </div>
                </div>

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="middlename" value="Middle Name" />

                        <TextInput
                            id="middlename"
                            name="middlename"
                            value={data.middlename}
                            className="mt-1 block w-full"
                            onChange={(e) => setData('middlename', e.target.value)}
                        />

                        <InputError message={errors.middlename} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="extname" value="Extension Name" />

                        <TextInput
                            id="extname"
                            name="extname"
                            value={data.extname}
                            className="mt-1 block w-full"
                            onChange={(e) => setData('extname', e.target.value)}
                            placeholder="e.g. Jr., Sr., III"
                        />

                        <InputError message={errors.extname} className="mt-2" />
                    </div>
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="password" value="Password" />

                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full"
                        autoComplete="new-password"
                        onChange={(e) => setData('password', e.target.value)}
                        required
                    />

                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel
                        htmlFor="password_confirmation"
                        value="Confirm Password"
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
                        required
                    />

                    <InputError
                        message={errors.password_confirmation}
                        className="mt-2"
                    />
                </div>

                <div className="mt-4 flex items-center justify-end">
                    <Link
                        href={route('login')}
                        className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Already registered?
                    </Link>

                    <PrimaryButton className="ms-4" disabled={processing}>
                        Register
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
