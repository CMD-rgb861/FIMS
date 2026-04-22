import React, { useEffect, useMemo, useState } from 'react';
import Sidebar from '../components/Sidebar';

function firstError(errors, key) {
    const value = errors?.[key];

    if (Array.isArray(value) && value.length > 0) {
        return value[0];
    }

    return '';
}

export default function AccountSettingsPage({
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    subjectsUrl = '/subjects',
    evaluationUrl = '/evaluation',
    profileUrl = '/my-profile',
    accountSettingsUrl = '/account-settings',
    accountSettingsUpdateUrl = '/account-settings',
    logoutUrl = '/logout',
    csrfToken = '',
    status = '',
    errors = {},
    oldInput = {},
    user = null,
    hasPendingEvaluations = false,
}) {
    const resolvedForm = useMemo(() => ({
        email: oldInput?.email ?? user?.email ?? '',
        current_password: '',
        password: '',
        password_confirmation: '',
    }), [oldInput, user]);

    const [formData, setFormData] = useState(resolvedForm);

    useEffect(() => {
        setFormData((prev) => ({
            ...prev,
            email: resolvedForm.email,
        }));
    }, [resolvedForm]);

    const handleInputChange = (event) => {
        const { name, value } = event.target;
        setFormData((prev) => ({
            ...prev,
            [name]: value,
        }));
    };

    return (
        <div className="min-h-screen flex bg-slate-50 text-slate-900">
            <Sidebar
                user={user}
                appName={appName}
                dashboardUrl={dashboardUrl}
                subjectsUrl={subjectsUrl}
                evaluationUrl={evaluationUrl}
                profileUrl={profileUrl}
                accountSettingsUrl={accountSettingsUrl}
                activePage="account-settings"
                logoutUrl={logoutUrl}
                csrfToken={csrfToken}
                hasPendingEvaluations={hasPendingEvaluations}
            />

            <main className="flex-1 p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">Account Settings</h1>
                    <p className="mt-1 text-sm text-slate-500">Update your email and password.</p>
                </div>

                <div className="max-w-2xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    {status ? (
                        <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                            {status}
                        </div>
                    ) : null}

                    <form method="POST" action={accountSettingsUpdateUrl} className="space-y-4">
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input type="hidden" name="_method" value="PUT" />

                        <label className="block">
                            <span className="mb-1 block text-sm font-medium text-slate-700">Email</span>
                            <input
                                type="email"
                                name="email"
                                value={formData.email}
                                onChange={handleInputChange}
                                onInput={handleInputChange}
                                autoComplete="email"
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                required
                            />
                            {firstError(errors, 'email') ? (
                                <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'email')}</span>
                            ) : null}
                        </label>

                        <div className="my-2 border-t border-slate-200" />

                        <label className="block">
                            <span className="mb-1 block text-sm font-medium text-slate-700">Current Password</span>
                            <input
                                type="password"
                                name="current_password"
                                value={formData.current_password}
                                onChange={handleInputChange}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                placeholder="Required only when changing password"
                            />
                            {firstError(errors, 'current_password') ? (
                                <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'current_password')}</span>
                            ) : null}
                        </label>

                        <label className="block">
                            <span className="mb-1 block text-sm font-medium text-slate-700">New Password</span>
                            <input
                                type="password"
                                name="password"
                                value={formData.password}
                                onChange={handleInputChange}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                placeholder="Leave blank to keep your current password"
                            />
                            {firstError(errors, 'password') ? (
                                <span className="mt-1 block text-xs text-red-600">{firstError(errors, 'password')}</span>
                            ) : null}
                        </label>

                        <label className="block">
                            <span className="mb-1 block text-sm font-medium text-slate-700">Confirm New Password</span>
                            <input
                                type="password"
                                name="password_confirmation"
                                value={formData.password_confirmation}
                                onChange={handleInputChange}
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                            />
                        </label>

                        <div className="pt-2">
                            <button
                                type="submit"
                                className="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
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
