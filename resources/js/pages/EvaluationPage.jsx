import React, { useState } from 'react';
import Sidebar from '../components/Sidebar';
import SefEvaluationModal from '../components/SefEvaluationModal';
import EvaluationResultModal from '../components/EvaluationResultModal';

export default function EvaluationPage({
    appName = 'FIMS',
    dashboardUrl = '/dashboard',
    subjectsUrl = '/subjects',
    evaluationUrl = '/evaluation',
    profileUrl = '/my-profile',
    accountSettingsUrl = '/account-settings',
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
    isEvaluationClosed = false,
    evaluationStatusLabel = 'Open for Evaluation',
    hasPendingEvaluations = false,
    reportsUrl = '/reports',
    canAccessEvaluation = true,
}) {
    const role = String(user?.role ?? '').toLowerCase();
    const isAdmin = role === 'admin' || user?.isAdmin === true;
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
        if (item.evaluated || isEvaluationClosed) {
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
                subjectsUrl={subjectsUrl}
                evaluationUrl={evaluationUrl}
                reportsUrl={reportsUrl}
                profileUrl={profileUrl}
                accountSettingsUrl={accountSettingsUrl}
                activePage="evaluation"
                logoutUrl={logoutUrl}
                csrfToken={csrfToken}
                hasPendingEvaluations={hasPendingEvaluations}
                canAccessEvaluation={canAccessEvaluation}
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
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <h1 className="text-2xl font-semibold tracking-tight">Supervisor's Evaluation of Faculty (SEF)</h1>
                            {isAdmin ? (
                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                                    Admin view
                                </span>
                            ) : null}
                        </div>
                        <p className="mt-2 max-w-3xl text-sm text-slate-500">
                            {isAdmin
                                ? 'Administrator accounts can view the page layout, but evaluation actions remain governed by Unit Head access.'
                                : 'Use this page to review faculty entries and submit evaluations during the active schedule.'}
                        </p>
                    </div>

                    <div className="mt-6 flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                        <form method="GET" action={evaluationUrl} className="w-full xl:flex-1">
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-3 max-w-3xl">
                                <label className="block">
                                    <span className="sr-only">School Year</span>
                                    <select
                                        name="sy"
                                        defaultValue={selectedSchoolYear}
                                        onChange={(event) => event.currentTarget.form?.submit()}
                                        className="w-full cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
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
                                        className="w-full cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
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
                                        className="w-full cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                    >
                                        <option value="">Select a name to evaluate</option>
                                        {subjects.filter((option) => option.value).map((option) => (
                                            <option key={option.value} value={option.value}>{option.label}</option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                        </form>

                        <div className="flex shrink-0 xl:pt-1">
                            <span className={`inline-flex items-center justify-center rounded-md px-3 py-1.5 text-xs font-semibold whitespace-nowrap ${
                                isEvaluationClosed
                                    ? 'bg-amber-100 text-amber-800'
                                    : 'bg-emerald-100 text-emerald-700'
                            }`}>
                                {evaluationStatusLabel}
                            </span>
                        </div>
                    </div>

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
                                                <div className="mt-1 space-y-1 text-xs text-slate-500">
                                                    <div className="truncate"><span className="font-semibold text-slate-700">Academic Rank:</span> {item.academic_rank || 'N/A'}</div>
                                                    <div className="truncate"><span className="font-semibold text-slate-700">College/Department:</span> {item.college_department || 'N/A'}</div>
                                                    <div className="truncate"><span className="font-semibold text-slate-700">Course Code/Title:</span> {item.code || item.title ? `${item.code ? `${item.code} - ` : ''}${item.title || ''}` : 'N/A'}</div>
                                                    <div className="truncate"><span className="font-semibold text-slate-700">Program Year:</span> {item.program_year || 'N/A'}</div>
                                                    <div className="truncate"><span className="font-semibold text-slate-700">Semester or Term/Academic Year:</span> {item.term || 'N/A'}</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="shrink-0">
                                            {item.evaluated ? (
                                                <span className="inline-flex items-center rounded-md bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-700">
                                                    Evaluated
                                                </span>
                                            ) : isEvaluationClosed ? (
                                                <span className="inline-flex items-center rounded-md bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700">
                                                    Closed Evaluation
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
                                                className="inline-flex cursor-pointer items-center rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100"
                                            >
                                                View Evaluation
                                            </button>
                                        ) : isEvaluationClosed ? (
                                            <button
                                                type="button"
                                                disabled
                                                className="inline-flex items-center rounded-md bg-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 cursor-not-allowed"
                                            >
                                                Evaluation Closed
                                            </button>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => openEvaluationModal(item)}
                                                className="inline-flex cursor-pointer items-center rounded-md bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700"
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