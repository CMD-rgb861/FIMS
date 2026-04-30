import React, { useMemo, useState } from 'react';
import Sidebar from '../components/Sidebar';
import FacultyGradeModal from '../components/FacultyGradeModal';

export default function SubjectsPage({
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    subjectsUrl = '/subjects',
    evaluationUrl = '/evaluation',
    reportsUrl = '/reports',
    profileUrl = '/my-profile',
    accountSettingsUrl = '/account-settings',
    unitHeadGradeStoreUrl = '/unit-head-grades',
    logoutUrl = '/logout',
    csrfToken = '',
    user = null,
    subjects = [],
    hasPendingEvaluations = false,
}) {
    const [subjectItems, setSubjectItems] = useState(subjects);
    const [selectedTerm, setSelectedTerm] = useState('all');
    const [selectedInstructor, setSelectedInstructor] = useState('all');
    const [isEvaluationOpen, setIsEvaluationOpen] = useState(false);
    const [selectedEvaluation, setSelectedEvaluation] = useState(null);

    const termOptions = useMemo(() => {
        return Array.from(new Set(subjectItems.map((subject) => subject.term).filter(Boolean)));
    }, [subjectItems]);

    const instructorOptions = useMemo(() => {
        return Array.from(new Set(subjectItems.map((subject) => subject.instructor).filter(Boolean)));
    }, [subjectItems]);

    const filteredSubjects = useMemo(() => {
        return subjectItems.filter((subject) => {
            const termMatched = selectedTerm === 'all' || subject.term === selectedTerm;
            const instructorMatched = selectedInstructor === 'all' || subject.instructor === selectedInstructor;

            return termMatched && instructorMatched;
        });
    }, [subjectItems, selectedTerm, selectedInstructor]);

    const openEvaluationModal = (subject) => {
        setSelectedEvaluation({
            code: subject.code,
            title: subject.title,
            instructor: subject.instructor,
            term: subject.term,
            status: subject.status,
            final_grade: subject.final_grade,
        });
        setIsEvaluationOpen(true);
    };

    const closeEvaluationModal = () => {
        setIsEvaluationOpen(false);
        setSelectedEvaluation(null);
    };

    const handleEvaluationSubmitted = ({ evaluation_result: evaluationResult }) => {
        if (!evaluationResult) {
            return;
        }

        const finalGrade = evaluationResult.final_grade ?? null;
        const nextStatus = finalGrade !== null && finalGrade !== undefined
            ? 'Passed'
            : 'For Evaluation';

        setSubjectItems((prev) => prev.map((item) => (
            item.instructor === evaluationResult.instructor && item.code === evaluationResult.course_code
                ? {
                    ...item,
                    final_grade: finalGrade,
                    status: nextStatus,
                }
                : item
        )));
    };

    return (
        <div className="h-screen flex overflow-hidden bg-slate-50 text-slate-900">
            <Sidebar
                user={user}
                appName={appName}
                dashboardUrl={dashboardUrl}
                subjectsUrl={subjectsUrl}
                evaluationUrl={evaluationUrl}
                reportsUrl={reportsUrl}
                profileUrl={profileUrl}
                accountSettingsUrl={accountSettingsUrl}
                activePage="subjects"
                logoutUrl={logoutUrl}
                csrfToken={csrfToken}
                hasPendingEvaluations={hasPendingEvaluations}
            />

            <main className="flex-1 overflow-y-auto p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">Subjects</h1>
                    <p className="mt-1 text-sm text-slate-500">All assigned subjects for this term.</p>
                </div>

                <form className="mb-4 grid grid-cols-1 md:grid-cols-2 gap-3 max-w-2xl">
                    <label className="block">
                        <span className="mb-1 block text-sm font-medium text-slate-700">Term</span>
                        <select
                            value={selectedTerm}
                            onChange={(event) => setSelectedTerm(event.target.value)}
                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                        >
                            <option value="all">All Terms</option>
                            {termOptions.map((term) => (
                                <option key={term} value={term}>{term}</option>
                            ))}
                        </select>
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-sm font-medium text-slate-700">Instructor</span>
                        <select
                            value={selectedInstructor}
                            onChange={(event) => setSelectedInstructor(event.target.value)}
                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                        >
                            <option value="all">All Instructors</option>
                            {instructorOptions.map((instructor) => (
                                <option key={instructor} value={instructor}>{instructor}</option>
                            ))}
                        </select>
                    </label>
                </form>

                <section className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-xs">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Code</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Subject Title</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Instructor</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Term</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Final Grade</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 bg-white">
                            {filteredSubjects.length > 0 ? (
                                filteredSubjects.map((subject, index) => (
                                    <tr key={`${subject.code}-${subject.instructor}-${index}`}>
                                        <td className="px-4 py-2.5 text-slate-900 font-medium">{subject.code}</td>
                                        <td className="px-4 py-2.5 text-slate-700">{subject.title}</td>
                                        <td className="px-4 py-2.5 text-slate-700">{subject.instructor}</td>
                                        <td className="px-4 py-2.5 text-slate-700">{subject.term}</td>
                                        <td className="px-4 py-2.5 text-slate-700">
                                            {subject.final_grade !== null && subject.final_grade !== undefined
                                                ? Number(subject.final_grade).toFixed(1)
                                                : '-'}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {subject.status === 'Passed' ? (
                                                <span className="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                                                    Passed
                                                </span>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => openEvaluationModal(subject)}
                                                    className="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700 hover:bg-amber-200"
                                                >
                                                    View
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={6} className="px-4 py-6 text-center text-slate-500">
                                        No subjects available.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </section>

                <FacultyGradeModal
                    isOpen={isEvaluationOpen}
                    evaluation={selectedEvaluation}
                    submitUrl={unitHeadGradeStoreUrl}
                    csrfToken={csrfToken}
                    onSubmitted={handleEvaluationSubmitted}
                    onClose={closeEvaluationModal}
                />
            </main>
        </div>
    );
}
