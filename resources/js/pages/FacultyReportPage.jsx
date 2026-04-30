import React, { useState } from 'react';
import Sidebar from '../components/Sidebar';

export default function FacultyReportPage({
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
    facultyName = '',
    tableRows = [],
}) {
    const [isSetModalOpen, setIsSetModalOpen] = useState(false);
    const [setBreakdownRows, setSetBreakdownRows] = useState([]);
    const [selectedSefBreakdown, setSelectedSefBreakdown] = useState(null);

    const openSetModal = (rows, sefBreakdown) => {
        setSetBreakdownRows(Array.isArray(rows) ? rows : []);
        setSelectedSefBreakdown(sefBreakdown || null);
        setIsSetModalOpen(true);
    };

    const closeSetModal = () => {
        setIsSetModalOpen(false);
        setSetBreakdownRows([]);
        setSelectedSefBreakdown(null);
    };

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
                    <h1 className="mt-2 text-2xl font-semibold tracking-tight">{facultyName} - Evaluation Details</h1>
                    <p className="mt-1 text-sm text-slate-500">Faculty evaluation summary table.</p>
                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <a href={`${reportsUrl}?tab=evaluation`} className="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">
                            Back
                        </a>
                    </div>
                </div>

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
                            {tableRows.length > 0 ? (
                                tableRows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-5 py-3 text-slate-900 font-medium">{row.id}</td>
                                        <td className="px-5 py-3 text-slate-700">{row.employee_id_no}</td>
                                        <td className="px-5 py-3 text-slate-700">{row.employee_name}</td>
                                        <td className="px-5 py-3 text-slate-700">{row.set_score}</td>
                                        <td className="px-5 py-3 text-slate-700">{row.sef_score}</td>
                                        <td className="px-5 py-3">
                                            <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold ${
                                                row.status === 'Evaluated'
                                                    ? 'bg-emerald-100 text-emerald-700'
                                                    : 'bg-amber-100 text-amber-700'
                                            }`}>
                                                {row.status}
                                            </span>
                                        </td>
                                        <td className="px-5 py-3">
                                            <button
                                                type="button"
                                                onClick={() => openSetModal(row.set_breakdown, {
                                                    total_score: row.sef_total_score,
                                                    rating: row.sef_rating,
                                                })}
                                                className="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                                            >
                                                {row.action_label || 'Open'}
                                            </button>
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

                {isSetModalOpen ? (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4">
                        <div className="w-full max-w-5xl rounded-xl border border-slate-200 bg-white shadow-2xl">
                            <div className="flex items-center justify-end border-b border-slate-200 px-5 py-4">
                                <button
                                    type="button"
                                    onClick={closeSetModal}
                                    className="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    Close
                                </button>
                            </div>

                            <div className="max-h-[70vh] overflow-auto p-5">
                                <div className="mb-4">
                                    <h3 className="text-sm font-semibold text-slate-900">SEF Breakdown</h3>
                                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <p className="text-xs text-slate-500">Total Score</p>
                                            <p className="text-sm font-semibold text-slate-900">
                                                {selectedSefBreakdown?.total_score ?? '-'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">Rating</p>
                                            <p className="text-sm font-semibold text-slate-900">
                                                {selectedSefBreakdown?.rating ?? '-'}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <h3 className="mb-3 text-sm font-semibold text-slate-900">SET Breakdown</h3>
                                <table className="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Seq</th>
                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">(1) Course Code</th>
                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">(2) Year/Section</th>
                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">(3) No. of Students</th>
                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">(4) Average SET Rating</th>
                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">(3 x 5) Weighted SET Score</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-200 bg-white">
                                        {setBreakdownRows.length > 0 ? (
                                            setBreakdownRows.map((item) => (
                                                <tr key={`${item.seq}-${item.course_code}`}>
                                                    <td className="px-4 py-2.5 text-slate-900 font-medium">{item.seq}</td>
                                                    <td className="px-4 py-2.5 text-slate-700">{item.course_code}</td>
                                                    <td className="px-4 py-2.5 text-slate-700">{item.year_section}</td>
                                                    <td className="px-4 py-2.5 text-slate-700">{item.no_of_students ?? '-'}</td>
                                                    <td className="px-4 py-2.5 text-slate-700">{item.average_set_rating}</td>
                                                    <td className="px-4 py-2.5 text-slate-700">{item.weighted_set_score}</td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan={6} className="px-4 py-6 text-center text-slate-500">
                                                    No SET breakdown data available.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                ) : null}
            </main>
        </div>
    );
}
