import React, { useState, useCallback, useMemo } from 'react';
import axios from 'axios';
import AppLayout from '../Layouts/AppLayout';
import { router, Link } from '@inertiajs/react'; // Add Link import
import FacultyReportPageModal from '../modals/FacultyReportPageModal';

export default function FacultySubjectReportPage({
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
    reportSummary = [],
    facultySubjects = [],
    schoolYears = [],
    selectedSchoolYear = '',
    isFacultyView = true,
    hasPendingEvaluations = false,
}) {
    const [isLoading, setIsLoading] = useState(false);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedSubject, setSelectedSubject] = useState(null);
    const [breakdownData, setBreakdownData] = useState(null);
    const [modalLoading, setModalLoading] = useState(false);
    const [modalError, setModalError] = useState('');

    const handleSchoolYearChange = useCallback((event) => {
        const newSchoolYear = event.target.value;
        setIsLoading(true);
        
        router.visit('/reports', {
            data: { term: newSchoolYear },
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setIsLoading(false),
        });
    }, []);

    const handleSubjectClick = async (subject) => {
        setSelectedSubject(subject);
        setIsModalOpen(true);
        setModalLoading(true);
        setModalError('');

        try {
            // Use the breakdown_url from the subject data
            const url = new URL(subject.breakdown_url, window.location.origin);
            if (selectedSchoolYear) {
                url.searchParams.set('term', selectedSchoolYear);
            }
            
            const response = await axios.get(url.toString(), {
                headers: { Accept: 'application/json' },
            });

            const data = response?.data ?? {};
            setBreakdownData(data);
        } catch (error) {
            console.error('Error loading breakdown:', error);
            setModalError('Unable to load breakdown data.');
        } finally {
            setModalLoading(false);
        }
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setSelectedSubject(null);
        setBreakdownData(null);
        setModalError('');
    };

    const selectedSchoolYearLabel = schoolYears.find(
        (option) => String(option.value) === String(selectedSchoolYear)
    )?.label ?? 'All School Years';

    const completeReportSummary = useMemo(() => {
        const hasAverageSef = reportSummary.some(
            (summary) => summary.label === 'Average SEF Rating'
        );

        if (hasAverageSef) {
            return reportSummary;
        }

        return [
            ...reportSummary,
            {
                label: 'Average SEF Rating',
                value: 'N/A',
                helper: 'Average SEF score from supervisor evaluations.',
            },
        ];
    }, [reportSummary]);

    const reportSummaryCards = completeReportSummary.map((summary, index) => (
        <div key={index} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs font-medium text-slate-500">{summary.label}</p>
            <p className="mt-2 text-2xl font-semibold text-slate-900">{summary.value}</p>
            <p className="mt-1 text-xs text-slate-500">{summary.helper}</p>
        </div>
    ));

    const subjectCards = facultySubjects.map((subject, index) => (
        <div
            key={`${subject.course_code}-${index}`}
            onClick={() => handleSubjectClick(subject)}
            className="group block rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-500 hover:shadow-md hover:bg-slate-50 cursor-pointer"
        >
            <div className="flex items-start gap-3 mb-4">
                <div className="flex-shrink-0">
                    <div className="h-10 w-10 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-xs font-bold shadow-sm">
                        {subject.initials}
                    </div>
                </div>
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold text-slate-900 group-hover:text-blue-600 transition line-clamp-2">
                        {subject.course_title}
                    </h3>
                    <p className="text-xs text-slate-500 mt-0.5">
                        {subject.course_code} • {subject.class_section}
                    </p>
                </div>
            </div>
            
            <div className="mb-3 h-px bg-gradient-to-r from-slate-200 to-transparent"></div>
            
            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <span className="text-xs font-medium text-slate-600">Subject Overall SET Rating</span>
                    <span className={`text-xs font-semibold ${
                        subject.set_rating !== null ? 'text-blue-600' : 'text-slate-400'
                    }`}>
                        {subject.set_rating_formatted}
                    </span>
                </div>
                
                {/* <div className="flex items-center justify-between">
                    <span className="text-xs font-medium text-slate-600">SEF Score</span>
                    <span className={`text-xs font-semibold ${
                        subject.sef_score !== null ? 'text-emerald-600' : 'text-slate-400'
                    }`}>
                        {subject.sef_score_formatted}
                    </span>
                </div> */}
                
                {/* <div className="flex items-center justify-between">
                    <span className="text-xs font-medium text-slate-600">Status</span>
                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                        subject.final_grade !== null
                            ? 'bg-emerald-100 text-emerald-700'
                            : subject.sef_score !== null
                            ? 'bg-blue-100 text-blue-700'
                            : 'bg-amber-100 text-amber-700'
                    }`}>
                        {subject.status}
                    </span>
                </div> */}
                
                {subject.final_grade !== null && (
                    <div className="mt-2 pt-2 border-t border-slate-100">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-slate-600">Final Grade</span>
                            <span className="text-sm font-bold text-slate-900">{subject.final_grade}</span>
                        </div>
                    </div>
                )}
            </div>
        </div>
    ));

    return (
        <AppLayout
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
        >
            <main className="flex-1 overflow-y-auto">
                {/* Breadcrumbs - same style as FacultyReportPage */}
                <div className="h-16 bg-white border-b border-slate-200 flex items-center px-6">
                    <div className="text-sm text-slate-500 flex items-center gap-2">
                        <Link href={dashboardUrl} className="hover:text-slate-700">Home</Link>
                        <span className="text-slate-300">›</span>
                        <Link href={reportsUrl} className="hover:text-slate-700">Reports</Link>
                        <span className="text-slate-300">›</span>
                        <span className="text-slate-700">Subjects</span>
                    </div>
                </div>

                {/* Main content area with padding */}
                <div className="p-6">
                    <div className="mb-6">
                        <h1 className="text-2xl font-semibold tracking-tight">My Subjects</h1>
                        <p className="mt-1 text-sm text-slate-500">View your subject evaluations and ratings.</p>
                    </div>

                    {/* Report Summary Cards */}
                    <div className="mb-6">
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            {reportSummaryCards}
                        </div>
                    </div>

                    {/* Filters */}
                    <div className="mb-6">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div className="w-full md:w-64">
                                <label className="block text-sm font-medium text-slate-700 mb-1">
                                    School Year
                                </label>
                                <select
                                    value={selectedSchoolYear ? String(selectedSchoolYear) : ''}
                                    onChange={handleSchoolYearChange}
                                    disabled={isLoading}
                                    className="w-full cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 disabled:opacity-50"
                                >
                                    {schoolYears.length > 0 ? (
                                        schoolYears.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))
                                    ) : (
                                        <option value="">No school years available</option>
                                    )}
                                </select>
                            </div>
                            
                            <div className="flex items-center gap-2">
                                <span className="inline-flex items-center rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700">
                                    {selectedSchoolYearLabel}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Subjects Grid */}
                    <section>
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-lg font-semibold text-slate-900">My Subjects</h2>
                            <span className="text-sm text-slate-500">
                                {facultySubjects.length} subject(s)
                            </span>
                        </div>
                        
                        <div className="relative">
                            {isLoading && (
                                <div className="absolute inset-0 bg-white/50 backdrop-blur-sm z-10 flex items-center justify-center rounded-lg">
                                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                                </div>
                            )}
                            
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                {subjectCards}
                            </div>
                            
                            {(!facultySubjects || facultySubjects.length === 0) && !isLoading && (
                                <div className="text-center py-12">
                                    <p className="text-slate-500">No subjects found for the selected school year.</p>
                                </div>
                            )}
                        </div>
                    </section>

                    {/* Modal for Breakdown */}
                    <FacultyReportPageModal
                        isOpen={isModalOpen}
                        onClose={closeModal}
                        setBreakdownRows={breakdownData?.set_breakdown || []}
                        selectedCourseCode={selectedSubject?.course_code || ''}
                        selectedSefBreakdown={breakdownData?.sef_breakdown || null}
                        isLoading={modalLoading}
                        errorMessage={modalError}
                        instructorId={user?.id_no}
                        termId={selectedSchoolYear}
                        facultyName={user ? `${user.firstname} ${user.lastname}` : ''}
                    />
                </div>
            </main>
        </AppLayout>
    );
}