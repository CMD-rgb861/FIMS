import React, { useEffect, useMemo, useState } from 'react';
import Sidebar from '../components/Sidebar';

function firstError(errors, key) {
    const value = errors?.[key];

    if (Array.isArray(value) && value.length > 0) {
        return value[0];
    }

    return '';
}

export default function ProfilePage({
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    subjectsUrl = '/subjects',
    evaluationUrl = '/evaluation',
    reportsUrl = '/reports',
    profileUrl = '/my-profile',
    accountSettingsUrl = '/account-settings',
    profileUpdateUrl = '/my-profile',
    logoutUrl = '/logout',
    csrfToken = '',
    status = '',
    errors = {},
    oldInput = {},
    user = null,
    hasPendingEvaluations = false,
}) {
    const resolvedUser = useMemo(() => ({
        id_no: oldInput?.id_no ?? user?.id_no ?? '',
        firstname: oldInput?.firstname ?? user?.firstname ?? '',
        lastname: oldInput?.lastname ?? user?.lastname ?? '',
        middlename: oldInput?.middlename ?? user?.middlename ?? '',
        extname: oldInput?.extname ?? user?.extname ?? '',
        dob: oldInput?.dob ?? user?.dob ?? '',
        sex: oldInput?.sex ?? user?.sex ?? '',
        civil_status: oldInput?.civil_status ?? user?.civil_status ?? '',
        email: oldInput?.email ?? user?.email ?? '',
        contact_no: oldInput?.contact_no ?? user?.contact_no ?? '',
        profile_photo_url: user?.profile_photo_url ?? '',
    }), [oldInput, user]);

    const [formData, setFormData] = useState(resolvedUser);
    const [selectedPhotoPreview, setSelectedPhotoPreview] = useState(resolvedUser.profile_photo_url);

    useEffect(() => {
        return () => {
            if (selectedPhotoPreview?.startsWith('blob:')) {
                URL.revokeObjectURL(selectedPhotoPreview);
            }
        };
    }, [selectedPhotoPreview]);

    const handleInputChange = (event) => {
        const { name, value } = event.target;
        setFormData((prev) => ({
            ...prev,
            [name]: value,
        }));
    };

    const handleProfilePhotoChange = (event) => {
        const file = event.target.files?.[0];

        if (!file) {
            return;
        }

        if (selectedPhotoPreview?.startsWith('blob:')) {
            URL.revokeObjectURL(selectedPhotoPreview);
        }

        setSelectedPhotoPreview(URL.createObjectURL(file));
    };

    const photoFallbackInitial = (formData.firstname?.[0] ?? user?.firstname?.[0] ?? 'U').toUpperCase();

    return (
        <div className="min-h-screen flex bg-slate-50 text-slate-900">
            <Sidebar
                user={user}
                appName={appName}
                dashboardUrl={dashboardUrl}
                subjectsUrl={subjectsUrl}
                evaluationUrl={evaluationUrl}
                reportsUrl={reportsUrl}
                profileUrl={profileUrl}
                accountSettingsUrl={accountSettingsUrl}
                activePage="profile"
                logoutUrl={logoutUrl}
                csrfToken={csrfToken}
                hasPendingEvaluations={hasPendingEvaluations}
            />

            <main className="flex-1 p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">My Profile</h1>
                    <p className="mt-1 text-sm text-slate-500">Update your account information.</p>
                </div>

                <div className="max-w-3xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    {status ? (
                        <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                            {status}
                        </div>
                    ) : null}

                    <form method="POST" action={profileUpdateUrl} className="space-y-4" encType="multipart/form-data">
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input type="hidden" name="_method" value="PUT" />

                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <div className="flex items-center gap-4">
                                {selectedPhotoPreview ? (
                                    <img
                                        src={selectedPhotoPreview}
                                        alt="Profile preview"
                                        className="h-16 w-16 rounded-full border border-slate-200 object-cover"
                                    />
                                ) : (
                                    <div className="h-16 w-16 rounded-full bg-slate-900 text-white flex items-center justify-center text-lg font-semibold">
                                        {photoFallbackInitial}
                                    </div>
                                )}

                                <label className="block flex-1">
                                    <span className="mb-1 block text-sm font-medium text-slate-700">Profile Photo</span>
                                    <input
                                        type="file"
                                        name="profile_photo"
                                        accept="image/png,image/jpeg,image/webp"
                                        onChange={handleProfilePhotoChange}
                                        className="block w-full text-sm text-slate-700 file:mr-4 file:rounded-md file:border-0 file:bg-blue-600 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700"
                                    />
                                    <span className="mt-1 block text-xs text-slate-500">Accepted: JPG, PNG, WEBP. Max size: 2MB.</span>
                                    {firstError(errors, 'profile_photo') ? (
                                        <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'profile_photo')}</span>
                                    ) : null}
                                </label>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label className="block">
                                <span className="mb-1 block text-sm font-medium text-slate-700">ID Number</span>
                                <input
                                    type="text"
                                    name="id_no"
                                    value={formData.id_no}
                                    onChange={handleInputChange}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                    required
                                />
                                {firstError(errors, 'id_no') ? (
                                    <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'id_no')}</span>
                                ) : null}
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-sm font-medium text-slate-700">First Name</span>
                                <input
                                    type="text"
                                    name="firstname"
                                    value={formData.firstname}
                                    onChange={handleInputChange}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                    required
                                />
                                {firstError(errors, 'firstname') ? (
                                    <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'firstname')}</span>
                                ) : null}
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-sm font-medium text-slate-700">Last Name</span>
                                <input
                                    type="text"
                                    name="lastname"
                                    value={formData.lastname}
                                    onChange={handleInputChange}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                    required
                                />
                                {firstError(errors, 'lastname') ? (
                                    <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'lastname')}</span>
                                ) : null}
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-sm font-medium text-slate-700">Middle Name</span>
                                <input
                                    type="text"
                                    name="middlename"
                                    value={formData.middlename}
                                    onChange={handleInputChange}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                />
                                {firstError(errors, 'middlename') ? (
                                    <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'middlename')}</span>
                                ) : null}
                            </label>

                            <label className="block md:col-span-2">
                                <span className="mb-1 block text-sm font-medium text-slate-700">Extension Name</span>
                                <input
                                    type="text"
                                    name="extname"
                                    value={formData.extname}
                                    onChange={handleInputChange}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                    placeholder="e.g., Jr., Sr., III"
                                />
                                {firstError(errors, 'extname') ? (
                                    <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'extname')}</span>
                                ) : null}
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-sm font-medium text-slate-700">Date of Birth</span>
                                <input
                                    type="date"
                                    name="dob"
                                    value={formData.dob}
                                    onChange={handleInputChange}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                />
                                {firstError(errors, 'dob') ? (
                                    <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'dob')}</span>
                                ) : null}
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-sm font-medium text-slate-700">Sex</span>
                                <select
                                    name="sex"
                                    value={formData.sex}
                                    onChange={handleInputChange}
                                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                >
                                    <option value="">Select sex</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>
                                {firstError(errors, 'sex') ? (
                                    <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'sex')}</span>
                                ) : null}
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-sm font-medium text-slate-700">Civil Status</span>
                                <input
                                    type="text"
                                    name="civil_status"
                                    value={formData.civil_status}
                                    onChange={handleInputChange}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                    placeholder="Single, Married, etc."
                                />
                                {firstError(errors, 'civil_status') ? (
                                    <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'civil_status')}</span>
                                ) : null}
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-sm font-medium text-slate-700">Contact Number</span>
                                <input
                                    type="text"
                                    name="contact_no"
                                    value={formData.contact_no}
                                    onChange={handleInputChange}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                    placeholder="09xxxxxxxxx"
                                />
                                {firstError(errors, 'contact_no') ? (
                                    <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'contact_no')}</span>
                                ) : null}
                            </label>

                            <label className="block md:col-span-2">
                                <span className="mb-1 block text-sm font-medium text-slate-700">Email</span>
                                <input
                                    type="hidden"
                                    name="email"
                                    value={formData.email}
                                />
                                <input
                                    type="email"
                                    value={formData.email}
                                    autoComplete="email"
                                    readOnly
                                    aria-readonly="true"
                                    className="w-full cursor-not-allowed rounded-lg border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-500"
                                />
                                <span className="mt-1 block text-xs text-slate-500">Email can only be changed in Account Settings.</span>
                                {firstError(errors, 'email') ? (
                                    <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'email')}</span>
                                ) : null}
                            </label>
                        </div>

                        <div className="pt-2">
                            <button
                                type="submit"
                                className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                            >
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    );
}