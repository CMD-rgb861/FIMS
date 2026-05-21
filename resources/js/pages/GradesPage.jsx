import React, { useMemo, useState } from 'react';
import AppLayout from '../Layouts/AppLayout';
import FacultyGradeModal from '../modals/FacultyGradeModal';

export default function GradesPage({
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    subjectsUrl = '/subjects',
    evaluationUrl = '/evaluation',
    gradesUrl = '/grades',
    reportsUrl = '/reports',
    profileUrl = '/my-profile',
    accountSettingsUrl = '/account-settings',
    unitHeadGradeStoreUrl = '/unit-head-grades',
    logoutUrl = '/logout',
    csrfToken = '',
    user = null,
    evaluations = [],
    hasPendingEvaluations = false,
}) {
    const [items, setItems] = useState(Array.isArray(evaluations) ? evaluations : []);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedItem, setSelectedItem] = useState(null);

    const pendingCount = useMemo(() => (
        items.filter((item) => item.final_grade === null || item.final_grade === undefined).length
    ), [items]);

    const openModal = (item) => {
        setSelectedItem(item);
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setSelectedItem(null);
    };

    const handleGradeSubmitted = ({ evaluation_result: evaluationResult }) => {
        setItems((prev) => prev.map((item) => {
            if (
                item.instructor === evaluationResult.instructor
                && item.code === evaluationResult.course_code
            ) {
                return {
                    ...item,
                    final_grade: evaluationResult.final_grade,
                };
            }

            return item;
        }));
    };

    return (
        <AppLayout
            user={user}
            appName={appName}
            dashboardUrl={dashboardUrl}
            subjectsUrl={subjectsUrl}
            evaluationUrl={evaluationUrl}
            gradesUrl={gradesUrl}
            reportsUrl={reportsUrl}
            profileUrl={profileUrl}
            accountSettingsUrl={accountSettingsUrl}
            activePage="grades"
            logoutUrl={logoutUrl}
            csrfToken={csrfToken}
            hasPendingEvaluations={hasPendingEvaluations}
        >
            <main className="flex-1 overflow-y-auto p-6">
                <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Faculty Grades</h1>
                        <p className="mt-1 text-sm text-slate-500">Submit or update grades for faculty members.</p>
                    </div>
                    <div className="inline-flex items-center rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600">
                        Pending: {pendingCount} of {items.length}
                    </div>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Faculty</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Course Code/Title</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Term</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Grade</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Action</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 bg-white">
                            {items.length > 0 ? (
                                items.map((item, index) => {
                                    const hasGrade = item.final_grade !== null && item.final_grade !== undefined;

                                    return (
                                        <tr key={`${item.instructor}-${item.code}-${index}`}>
                                            <td className="px-4 py-3 text-slate-800 font-medium">{item.instructor}</td>
                                            <td className="px-4 py-3 text-slate-700">{item.code || 'N/A'} - {item.title || 'N/A'}</td>
                                            <td className="px-4 py-3 text-slate-700">{item.term || 'N/A'}</td>
                                            <td className="px-4 py-3 text-slate-900 font-semibold">{hasGrade ? Number(item.final_grade).toFixed(2) : '-'}</td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold ${
                                                    hasGrade
                                                        ? 'bg-emerald-100 text-emerald-700'
                                                        : 'bg-amber-100 text-amber-700'
                                                }`}>
                                                    {hasGrade ? 'Graded' : 'For Grading'}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <button
                                                    type="button"
                                                    onClick={() => openModal(item)}
                                                    className="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                                                >
                                                    {hasGrade ? 'Update Grade' : 'Set Grade'}
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                                        No faculty records available for grading.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </section>
            </main>

            <FacultyGradeModal
                isOpen={isModalOpen}
                evaluation={selectedItem}
                submitUrl={unitHeadGradeStoreUrl}
                csrfToken={csrfToken}
                onClose={closeModal}
                onSubmitted={handleGradeSubmitted}
            />
        </AppLayout>
    );
}
