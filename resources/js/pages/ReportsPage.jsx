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
    canAccessEvaluation = true,
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
                canAccessEvaluation={canAccessEvaluation}
            />

            <main className="flex-1 p-6">
                {user?.role !== 'faculty' && (
                    <div className="mb-6">
                        <h1 className="text-2xl font-semibold tracking-tight">Evaluation</h1>
                        <p className="mt-1 text-sm text-slate-500">Faculty evaluation list.</p>
                    </div>
                )}

                {user?.role === 'faculty' ? (
                    <section className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">ID</th>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">Employee ID No</th>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">Employee Name</th>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">SET</th>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">SEF</th>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">Status</th>
                                    <th className="px-5 py-3 text-left font-semibold text-slate-600">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 bg-white">
                                {recentReports.length > 0 ? (
                                    recentReports.map((report, index) => (
                                        <tr key={index}>
                                            <td className="px-5 py-3 text-slate-900 font-medium">{index + 1}</td>
                                            <td className="px-5 py-3 text-slate-700">-</td>
                                            <td className="px-5 py-3 text-slate-700">{report.instructor}</td>
                                            <td className="px-5 py-3 text-slate-700">{report.final_grade || '-'}</td>
                                            <td className="px-5 py-3 text-slate-700">{report.rating_percentage}%</td>
                                            <td className="px-5 py-3">
                                                <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold ${
                                                    report.final_grade !== null
                                                        ? 'bg-emerald-100 text-emerald-700'
                                                        : 'bg-amber-100 text-amber-700'
                                                }`}>
                                                    {report.final_grade !== null ? 'Evaluated' : 'For Evaluation'}
                                                </span>
                                            </td>
                                            <td className="px-5 py-3">
                                                <a href={evaluationUrl} className="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan={7} className="px-5 py-8 text-center text-slate-500">
                                            No evaluation records found for this faculty.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </section>
                ) : (
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
                )}
            </main>
        </div>
    );
}