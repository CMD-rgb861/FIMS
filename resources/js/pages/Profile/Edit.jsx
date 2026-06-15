import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status }) {
    const { url } = usePage();
    const dashboardUrl = route('dashboard'); // or '/dashboard' if you prefer
    
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Account Settings
                </h2>
            }
        >
            <Head title="Account Settings" />

            {/* Breadcrumb Navigation */}
            <div className="h-16 bg-white border-b border-slate-200 flex items-center px-6">
                <div className="text-sm text-slate-500 flex items-center gap-2">
                    <a href={dashboardUrl} className="hover:text-slate-700">Home</a>
                    <span className="text-slate-300">›</span>
                    <span className="text-slate-700 font-medium">Account Settings</span>
                </div>
            </div>

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdateProfileInformationForm
                            status={status}
                            className="max-w-xl"
                        />
                    </div>

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdatePasswordForm className="max-w-xl" />
                    </div>

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <DeleteUserForm className="max-w-xl" />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}