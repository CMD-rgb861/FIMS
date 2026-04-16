import React, { useState } from 'react';
import Sidebar from '../components/Sidebar';
import SefEvaluationModal from '../components/SefEvaluationModal';
import EvaluationResultModal from '../components/EvaluationResultModal';

export default function EvaluationPage({
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    evaluationUrl = '/evaluation',
    profileUrl = '/my-profile',
    evaluationStoreUrl = '/evaluations',
    logoutUrl = '/logout',
    csrfToken = '',
    user = null,
    schoolYears = [],
    terms = [],
    subjects = [],
    evaluations = [],
    evaluatedInstructors = [],
    selectedSchoolYear = '',
    selectedTerm = 'all',
    selectedSubject = '',
}) {
    const [isEvaluationOpen, setIsEvaluationOpen] = useState(false);
    const [selectedEvaluation, setSelectedEvaluation] = useState(null);
    const [isResultOpen, setIsResultOpen] = useState(false);
    const [selectedResult, setSelectedResult] = useState(null);
    const [evaluationItems, setEvaluationItems] = useState(() => (
        evaluations.map((item) => ({
            ...item,
            evaluated: item.evaluated || evaluatedInstructors.includes(item.instructor),
        }))
    ));

    const openEvaluationModal = (item) => {
        if (item.evaluated) {
            return;
        }

        setSelectedEvaluation(item);
        setIsEvaluationOpen(true);
    };

    const closeEvaluationModal = () => {
        setIsEvaluationOpen(false);
        setSelectedEvaluation(null);
    };

    const openResultModal = (item) => {
        if (!item?.evaluation_result) {
            return;
        }

        setSelectedResult(item.evaluation_result);
        setIsResultOpen(true);
    };

    const closeResultModal = () => {
        setSelectedResult(null);
        setIsResultOpen(false);
    };

    const handleEvaluationSubmitted = ({ instructor, evaluation_result: evaluationResult }) => {
        setEvaluationItems((prev) => prev.map((item) => (
            item.instructor === instructor
                ? {
                    ...item,
                    evaluated: true,
                    evaluation_result: evaluationResult || item.evaluation_result,
                }
                : item
        )));
    };

    return (
        <div className="min-h-screen flex">
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

            <main className="flex-1">
                <div className="h-16 bg-white border-b border-slate-200 flex items-center px-6">
                    <div className="text-sm text-slate-500 flex items-center gap-2">
                        <a href={dashboardUrl} className="hover:text-slate-700">Home</a>
                        <span className="text-slate-300">›</span>
                        <span className="text-slate-700 font-medium">Evaluation</span>
                    </div>
                </div>

                <div className="p-6">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Supervisor's Evaluation of Faculty (SEF)</h1>
                    </div>

                    <form method="GET" action={evaluationUrl} className="mt-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 max-w-3xl">
                            <label className="block">
                                <span className="sr-only">School Year</span>
                                <select
                                    name="sy"
                                    defaultValue={selectedSchoolYear}
                                    onChange={(event) => event.currentTarget.form?.submit()}
                                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                >
                                    {schoolYears.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </label>

                            <label className="block">
                                <span className="sr-only">Status</span>
                                <select
                                    name="term"
                                    defaultValue={selectedTerm}
                                    onChange={(event) => event.currentTarget.form?.submit()}
                                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                >
                                    {terms.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </label>

                            <label className="block">
                                <span className="sr-only">Name</span>
                                <select
                                    name="subject"
                                    defaultValue={selectedSubject}
                                    onChange={(event) => event.currentTarget.form?.submit()}
                                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                >
                                    {subjects.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </label>
                        </div>
                    </form>

                    <div className="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {evaluationItems.map((item, idx) => {
                            return (
                                <div key={`${item.code}-${idx}`} className="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="flex items-start gap-3 min-w-0">
                                            <div className="h-10 w-10 rounded-full bg-blue-600/10 text-blue-700 flex items-center justify-center text-sm font-semibold shrink-0">
                                                {item.initials}
                                            </div>
                                            <div className="min-w-0">
                                                <div className="text-sm font-semibold text-slate-900 truncate">
                                                    {item.instructor}
                                                </div>
                                                <div className="mt-1 text-xs text-slate-500 truncate">{item.code} - {item.title}</div>
                                                <div className="mt-1 text-xs text-slate-500 truncate">Term: {item.term}</div>
                                            </div>
                                        </div>
                                        <div className="shrink-0">
                                            {item.evaluated ? (
                                                <span className="inline-flex items-center rounded-md bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-700">
                                                    Evaluated
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center rounded-md bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700">
                                                    For Evaluation
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    <div className="mt-4">
                                        {item.evaluated ? (
                                            <button
                                                type="button"
                                                onClick={() => openResultModal(item)}
                                                className="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100"
                                            >
                                                View Evaluation
                                            </button>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => openEvaluationModal(item)}
                                                className="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700"
                                            >
                                                Start Evaluation
                                            </button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <SefEvaluationModal
                    isOpen={isEvaluationOpen}
                    evaluation={selectedEvaluation}
                    submitUrl={evaluationStoreUrl}
                    csrfToken={csrfToken}
                    onSubmitted={handleEvaluationSubmitted}
                    onClose={closeEvaluationModal}
                />

                <EvaluationResultModal
                    isOpen={isResultOpen}
                    result={selectedResult}
                    onClose={closeResultModal}
                />
            </main>
        </div>
    );
}