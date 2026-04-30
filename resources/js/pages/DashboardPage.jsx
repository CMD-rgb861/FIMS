import React from 'react';
import Sidebar from '../components/Sidebar';

export default function DashboardPage({
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
    summaryCards = [],
    recentEvaluations = [],
    hasPendingEvaluations = false,
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
                activePage="dashboard"
                logoutUrl={logoutUrl}
                csrfToken={csrfToken}
                hasPendingEvaluations={hasPendingEvaluations}
            />

            <main className="flex-1 p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">Dashboard</h1>
                    <p className="mt-1 text-sm text-slate-500">Overview of your faculty evaluation progress.</p>
                </div>

                <section className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    {summaryCards.map((card) => (
                        <div key={card.label} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <p className="text-xs uppercase tracking-wide text-slate-500">{card.label}</p>
                            <p className="mt-2 text-3xl font-semibold text-slate-900">{card.value}</p>
                            <p className="mt-2 text-xs text-slate-500">{card.helper}</p>
                        </div>
                    ))}
                </section>

                <section className="mt-6 rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h2 className="text-base font-semibold text-slate-900">Recent Evaluations</h2>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">Instructor</th>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">Course</th>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">Rating</th>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">Submitted</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 bg-white">
                                {recentEvaluations.length > 0 ? (
                                    recentEvaluations.map((row) => (
                                        <tr key={`${row.instructor}-${row.course_code}-${row.submitted_at}`}>
                                            <td className="px-5 py-3 text-slate-900 font-medium">{row.instructor}</td>
                                            <td className="px-5 py-3 text-slate-700">{row.course_code} - {row.course_title}</td>
                                            <td className="px-5 py-3 text-slate-700">{row.rating_percentage}%</td>
                                            <td className="px-5 py-3 text-slate-700">{row.submitted_at}</td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan={4} className="px-5 py-8 text-center text-slate-500">
                                            No evaluations submitted yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
    );
}