import React from 'react';
import Sidebar from '../components/Sidebar';

export default function ReportsPage({
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    subjectsUrl = '/subjects',
    evaluationUrl = '/evaluation',
    reportsUrl = '/reports',
    profileUrl = '/my-profile',
    accountSettingsUrl = '/account-settings',
    logoutUrl = '/logout',
    csrfToken = '',
    user = null,
    hasPendingEvaluations = false,
    reportSummary = [],
    recentReports = [],
    facultyList = [],
}) {
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
                activePage="reports"
                logoutUrl={logoutUrl}
                csrfToken={csrfToken}
                hasPendingEvaluations={hasPendingEvaluations}
            />

            <main className="flex-1 p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">Evaluation</h1>
                    <p className="mt-1 text-sm text-slate-500">Faculty evaluation list.</p>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h2 className="text-base font-semibold text-slate-900">All Faculty</h2>
                    </div>

                    <div className="p-5 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                        {facultyList.length > 0 ? (
                            facultyList.map((faculty) => (
                                <a
                                    key={faculty.instructor}
                                    href={faculty.detail_url}
                                    className="rounded-xl border border-slate-200 bg-slate-50 p-4 transition hover:border-blue-300 hover:bg-blue-50/30"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <div className="h-10 w-10 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-sm font-semibold">
                                                {faculty.initials}
                                            </div>
                                            <div className="min-w-0">
                                                <div className="text-sm font-semibold text-slate-900 truncate">{faculty.instructor}</div>
                                                <div className="text-xs text-slate-500">Employee ID No: {faculty.employee_id_no}</div>
                                                <div className="text-xs text-slate-500">Subjects: {faculty.subjects_count}</div>
                                                <div className="text-xs text-slate-500">
                                                    {faculty.evaluated ? 'With Evaluation' : 'Without Evaluation'}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            ))
                        ) : (
                            <div className="col-span-full rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                                No faculty records available.
                            </div>
                        )}
                    </div>
                </section>
            </main>
        </div>
    );
}