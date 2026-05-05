import React from 'react';

export default function Sidebar({
    user,
    appName,
    dashboardUrl,
    subjectsUrl = '/subjects',
    evaluationUrl = '/evaluation',
    gradesUrl = '/grades',
    reportsUrl = '/reports',
    profileUrl = '/my-profile',
    accountSettingsUrl = '#',
    activePage = 'dashboard',
    logoutUrl,
    csrfToken,
    hasPendingEvaluations = false,
    canAccessEvaluation = true,
}) {
    const fullName = `${user?.firstname ?? ''} ${user?.lastname ?? 'Student'}`.trim();
    const initial = (user?.firstname?.[0] ?? 'U').toUpperCase();
    const appLabel = appName || 'FIMS';
    const profilePhotoUrl = user?.profile_photo_url ?? '';

    const navClass = (key) => (
        activePage === key
            ? 'flex items-center gap-3 px-3 py-2 rounded-lg bg-blue-50 text-blue-700'
            : 'flex items-center gap-3 px-3 py-2 rounded-lg text-slate-700 hover:bg-slate-100'
    );

    const iconClass = (key) => (
        activePage === key
            ? 'h-8 w-8 rounded-lg bg-blue-100 flex items-center justify-center text-blue-700'
            : 'h-8 w-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600'
    );

    return (
        <aside className="hidden md:sticky md:top-0 md:flex h-screen w-72 bg-white border-r border-slate-200 flex-col">
            <div className="h-16 px-6 flex items-center gap-3 border-b border-slate-200">
                <img
                    src="/image/LNULogo.png"
                    alt="LNU Logo"
                    className="h-9 w-9 rounded-lg object-contain"
                />
                <div className="leading-tight">
                    <div className="font-semibold">{appLabel}</div>
                    <div className="text-xs text-slate-500">LNU</div>
                </div>
            </div>

            <nav className="flex-1 p-3 space-y-1">
                <a href={dashboardUrl} className={navClass('dashboard')}>
                    <span className={iconClass('dashboard')}>
                        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1v-10.5Z" />
                        </svg>
                    </span>
                    <span className="text-sm font-medium">Dashboard</span>
                </a>

                <a href={subjectsUrl} className={navClass('subjects')}>
                    <span className={iconClass('subjects')}>
                        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M4 5h16" />
                            <path d="M4 12h16" />
                            <path d="M4 19h16" />
                        </svg>
                    </span>
                    <span className="text-sm font-medium">Subjects</span>
                </a>

                {canAccessEvaluation ? (
                    <a href={evaluationUrl} className={navClass('evaluation')}>
                        <span className={iconClass('evaluation')}>
                            <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M4 4h16v14H4z" />
                                <path d="M8 22h8" />
                                <path d="M12 18v4" />
                            </svg>
                        </span>
                        <span className="text-sm font-medium">Evaluation</span>
                        {hasPendingEvaluations ? (
                            <span className="ml-auto inline-flex h-2.5 w-2.5 rounded-full bg-red-500" aria-label="Pending evaluations" title="Pending evaluations" />
                        ) : null}
                    </a>
                ) : null}

                {canAccessEvaluation ? (
                    <a href={gradesUrl} className={navClass('grades')}>
                        <span className={iconClass('grades')}>
                            <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M8 6h12" />
                                <path d="M8 12h12" />
                                <path d="M8 18h12" />
                                <path d="M4 6h.01" />
                                <path d="M4 12h.01" />
                                <path d="M4 18h.01" />
                            </svg>
                        </span>
                        <span className="text-sm font-medium">Grades</span>
                    </a>
                ) : null}

                <a href={reportsUrl} className={navClass('reports')}>
                    <span className={iconClass('reports')}>
                        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M4 19h16" />
                            <path d="M7 16V8" />
                            <path d="M12 16V5" />
                            <path d="M17 16v-4" />
                        </svg>
                    </span>
                    <span className="text-sm font-medium">Reports</span>
                </a>

                <a href={profileUrl} className={navClass('profile')}>
                    <span className={iconClass('profile')}>
                        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Z" />
                            <path d="M20 21a8 8 0 1 0-16 0" />
                        </svg>
                    </span>
                    <span className="text-sm font-medium">My Profile</span>
                </a>

                <div className="my-2 border-t border-slate-200" />

                <a href={accountSettingsUrl} className={navClass('account-settings')}>
                    <span className={iconClass('account-settings')}>
                        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2">
                            <circle cx="12" cy="12" r="3" />
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                        </svg>
                    </span>
                    <span className="text-sm font-medium">Account Settings</span>
                </a>
            </nav>

            <div className="p-4 border-t border-slate-200">
                <div className="flex items-center gap-3">
                    {profilePhotoUrl ? (
                        <img
                            src={profilePhotoUrl}
                            alt="Profile"
                            className="h-10 w-10 rounded-full border border-slate-200 object-cover"
                        />
                    ) : (
                        <div className="h-10 w-10 rounded-full bg-slate-900 text-white flex items-center justify-center text-sm font-semibold">
                            {initial}
                        </div>
                    )}
                    <div className="min-w-0">
                        <div className="text-sm font-semibold truncate">{fullName}</div>
                        <div className="text-xs text-slate-500 truncate">{user?.id_no ?? 'N/A'}</div>
                    </div>
                    <form method="POST" action={logoutUrl} className="ml-auto">
                        <input type="hidden" name="_token" value={csrfToken} />
                        <button type="submit" className="inline-flex items-center rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </aside>
    );
}
