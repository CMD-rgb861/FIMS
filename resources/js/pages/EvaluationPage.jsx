import React, { useState } from 'react';
import Sidebar from '../components/Sidebar';
import SefEvaluationModal from '../components/SefEvaluationModal';
import EvaluationResultModal from '../components/EvaluationResultModal';

export default function EvaluationPage({
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    evaluationUrl = '/dashboard',
    profileUrl = '/my-profile',
    evaluationStoreUrl = '/evaluations',
    logoutUrl = '/logout',
    csrfToken = '',
    user = null,
    instructor = 'Unknown Instructor',
    initials = 'NA',
    subjects = [],
    isEvaluated = false,
    evaluationResult = null,
}) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedSubject, setSelectedSubject] = useState(null);
    const [hasEvaluated, setHasEvaluated] = useState(isEvaluated);
    const [isResultOpen, setIsResultOpen] = useState(false);
    const [resultData, setResultData] = useState(evaluationResult);

    const openModal = (subject) => {
        if (hasEvaluated) {
            return;
        }

        setSelectedSubject(subject);
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setSelectedSubject(null);
    };

    const handleEvaluationSubmitted = ({ evaluation_result: evaluationResult }) => {
        setHasEvaluated(true);
        if (evaluationResult) {
            setResultData(evaluationResult);
        }
    };

    const openResultModal = () => {
        if (!resultData) {
            return;
        }

        setIsResultOpen(true);
    };

    const closeResultModal = () => {
        setIsResultOpen(false);
    };

    return (
        <div className="min-h-screen flex bg-slate-50 text-slate-900">
            <Sidebar
                user={user}
                appName={appName}
                dashboardUrl={dashboardUrl}
                evaluationUrl={evaluationUrl}
                profileUrl={profileUrl}
                activePage="evaluation"
                logoutUrl={logoutUrl}
                csrfToken={csrfToken}
            />

            <main className="flex-1 p-6">
                <div className="mb-6">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Supervisor's Evaluation of Faculty (SEF)</h1>
                        <p className="mt-1 text-sm text-slate-500">Subjects handled by instructor</p>
                        <a href={dashboardUrl} className="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-blue-700 hover:text-blue-800">
                            <span aria-hidden="true">←</span>
                            <span>Back</span>
                        </a>
                    </div>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex items-center gap-3 border-b border-slate-200 pb-4">
                        <div className="h-12 w-12 rounded-full bg-blue-600/10 text-blue-700 flex items-center justify-center text-sm font-semibold">
                            {initials}
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-slate-500">Instructor</p>
                            <p className="text-base font-semibold text-slate-900">{instructor}</p>
                        </div>
                    </div>

                    <div className="mt-4 overflow-hidden rounded-lg border border-slate-200">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-2 text-left font-semibold text-slate-600">Code</th>
                                    <th className="px-4 py-2 text-left font-semibold text-slate-600">Subject</th>
                                    <th className="px-4 py-2 text-left font-semibold text-slate-600">Term</th>
                                    <th className="px-4 py-2 text-left font-semibold text-slate-600">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 bg-white">
                                {subjects.length > 0 ? (
                                    subjects.map((subject, index) => (
                                        <tr key={`${subject.code}-${index}`}>
                                            <td className="px-4 py-3 text-slate-700">{subject.code}</td>
                                            <td className="px-4 py-3 text-slate-900 font-medium">{subject.title}</td>
                                            <td className="px-4 py-3 text-slate-700">{subject.term}</td>
                                            <td className="px-4 py-3">
                                                {hasEvaluated ? (
                                                    <button
                                                        type="button"
                                                        onClick={openResultModal}
                                                        className="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100"
                                                    >
                                                        View Evualation
                                                    </button>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        onClick={() => openModal(subject)}
                                                        className="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                                                    >
                                                        Evaluate Subject
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan={4} className="px-4 py-6 text-center text-slate-500">
                                            No subjects found for this instructor.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                <SefEvaluationModal
                    isOpen={isModalOpen}
                    evaluation={selectedSubject ? {
                        instructor,
                        code: selectedSubject.code,
                        title: selectedSubject.title,
                        term: selectedSubject.term,
                    } : null}
                    submitUrl={evaluationStoreUrl}
                    csrfToken={csrfToken}
                    onSubmitted={handleEvaluationSubmitted}
                    onClose={closeModal}
                />

                <EvaluationResultModal
                    isOpen={isResultOpen}
                    result={resultData}
                    onClose={closeResultModal}
                />
            </main>
        </div>
    );
}
