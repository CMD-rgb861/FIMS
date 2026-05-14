import React from 'react';
import { usePage } from '@inertiajs/react';
import Sidebar from '../components/Sidebar';

export default function AppLayout({
    children,
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    subjectsUrl = '/subjects',
    evaluationUrl = '/evaluation',
    gradesUrl = '/grades',
    reportsUrl = '/reports',
    profileUrl = '/my-profile',
    accountSettingsUrl = '/account-settings',
    activePage = 'dashboard',
    logoutUrl = '/logout',
    csrfToken = '',
    user = null,
    hasPendingEvaluations = false,
    layoutClassName = 'min-h-screen flex bg-slate-50 text-slate-900',
}) {
    const sharedUser = usePage().props?.auth?.user ?? {};
    const resolvedUser = {
        ...user,
        ...sharedUser,
    };

    return (
        <div className={layoutClassName}>
            <Sidebar
                user={resolvedUser}
                appName={appName}
                dashboardUrl={dashboardUrl}
                subjectsUrl={subjectsUrl}
                evaluationUrl={evaluationUrl}
                gradesUrl={gradesUrl}
                reportsUrl={reportsUrl}
                profileUrl={profileUrl}
                accountSettingsUrl={accountSettingsUrl}
                activePage={activePage}
                logoutUrl={logoutUrl}
                csrfToken={csrfToken}
                hasPendingEvaluations={hasPendingEvaluations}
            />

            {children}
        </div>
    );
}