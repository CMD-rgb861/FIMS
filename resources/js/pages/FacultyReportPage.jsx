import React, { useState } from 'react';
import axios from 'axios';
import Sidebar from '../components/Sidebar';
import FacultyReportPageModal from '../components/FacultyReportPageModal';

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
    canAccessEvaluation = true,
}) {
    const [isSetModalOpen, setIsSetModalOpen] = useState(false);
    const [setBreakdownRows, setSetBreakdownRows] = useState([]);
    const [selectedSefBreakdown, setSelectedSefBreakdown] = useState(null);
    const [isModalLoading, setIsModalLoading] = useState(false);
    const [modalError, setModalError] = useState('');

    const openSetModal = async (row) => {
        setModalError('');
        setSetBreakdownRows(Array.isArray(row?.set_breakdown) ? row.set_breakdown : []);
        setSelectedSefBreakdown({
            total_score: row?.sef_total_score ?? null,
            rating: row?.sef_rating ?? null,
        });
        setIsSetModalOpen(true);

        if (!row?.breakdown_url) {
            return;
        }

        try {
            setIsModalLoading(true);
            const response = await axios.get(row.breakdown_url, {
                headers: { Accept: 'application/json' },
            });

            const data = response?.data ?? {};
            setSetBreakdownRows(Array.isArray(data.set_breakdown) ? data.set_breakdown : []);
            setSelectedSefBreakdown(data.sef_breakdown || null);
        } catch (error) {
            setModalError('Unable to load breakdown data right now.');
        } finally {
            setIsModalLoading(false);
        }
    };

    const closeSetModal = () => {
        setIsSetModalOpen(false);
        setSetBreakdownRows([]);
        setSelectedSefBreakdown(null);
        setIsModalLoading(false);
        setModalError('');
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
                canAccessEvaluation={canAccessEvaluation}
            />

            <main className="flex-1 p-6">
                <div className="mb-6">
                    <h1 className="mt-2 text-2xl font-semibold tracking-tight">
                        {facultyName} - Evaluation Details
                    </h1>

                    <p className="mt-1 text-sm text-slate-500">
                        Faculty evaluation summary table.
                    </p>

                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <a
                            href={`${reportsUrl}?tab=evaluation`}
                            className="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700"
                        >
                            Back
                        </a>
                    </div>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    Subject
                                </th>

                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    Employee ID No
                                </th>

                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    Employee Name
                                </th>

                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    SET
                                </th>

                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    SEF
                                </th>

                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    Status
                                </th>

                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    Action
                                </th>
                            </tr>
                        </thead>

                        <tbody className="divide-y divide-slate-200 bg-white">
                            {tableRows.length > 0 ? (
                                tableRows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-5 py-3 text-slate-900 font-medium">
                                            {row.course_description || '-'}
                                        </td>

                                        <td className="px-5 py-3 text-slate-700">
                                            {row.employee_id_no}
                                        </td>

                                        <td className="px-5 py-3 text-slate-700">
                                            {row.employee_name}
                                        </td>

                                        <td className="px-5 py-3 text-slate-700">
                                            {row.set_score}
                                        </td>

                                        <td className="px-5 py-3 text-slate-700">
                                            {row.sef_score}
                                        </td>

                                        <td className="px-5 py-3">
                                            <span
                                                className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold ${
                                                    row.status === 'Evaluated'
                                                        ? 'bg-emerald-100 text-emerald-700'
                                                        : 'bg-amber-100 text-amber-700'
                                                }`}
                                            >
                                                {row.status}
                                            </span>
                                        </td>

                                        <td className="px-5 py-3">
                                            <button
                                                type="button"
                                                onClick={() => openSetModal(row)}
                                                className="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                                            >
                                                {row.action_label || 'Open'}
                                            </button>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-5 py-8 text-center text-slate-500"
                                    >
                                        No evaluation records found for this
                                        faculty.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </section>

                <FacultyReportPageModal
                    isOpen={isSetModalOpen}
                    onClose={closeSetModal}
                    setBreakdownRows={setBreakdownRows}
                    selectedSefBreakdown={selectedSefBreakdown}
                    isLoading={isModalLoading}
                    errorMessage={modalError}
                />
            </main>
        </div>
    );
}