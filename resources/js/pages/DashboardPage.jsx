import React, { useState, useMemo, useRef, useEffect } from 'react';
import { Link, usePage } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { isAdminRole, isUnitHeadRole } from '../utils/role';

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
    facultyGrade = 'N/A',
    averageSetRating = 'N/A',
    averageSefRating = 'N/A',
    subjectsHandled = 0,
    evaluationsReceived = 0,
    completionRate = 0,
    mySubjects = [],
    recentGrades = [],
    recentEvaluations = [],
    schoolYear = '',
    hasPendingEvaluations = false,
    evaluationSections = [],
    availableTerms = [],
    selectedTerm = null,
    selectedTermLabel = null,
    // Evaluator specific props
    evaluatedCount = 0,
    pendingCount = 0,
    facultyEvaluationList = [],
    totalFaculty = 0,
    // Dean specific props
    deanFacultyRankings = [],
    deanCompletedFaculty = [],
    deanSetRankings = [],
    deanSefRankings = [],
}) {
    const { get, visit } = usePage();
    const [isDropdownOpen, setIsDropdownOpen] = useState(false);
    const [selectedTermId, setSelectedTermId] = useState(selectedTerm);
    const buttonRef = useRef(null);
    const [dropdownPosition, setDropdownPosition] = useState({ top: 0, right: 0 });
    const [isFilterLoading, setIsFilterLoading] = useState(false);
    const [loadingButtons, setLoadingButtons] = useState({
        subjects: false,
        reports: false,
        evaluate: false,
    });
    const [activeChartTab, setActiveChartTab] = useState('set');

    // Calculate dropdown position when opened
    useEffect(() => {
        if (isDropdownOpen && buttonRef.current) {
            const rect = buttonRef.current.getBoundingClientRect();
            setDropdownPosition({
                top: rect.bottom + 8,
                right: window.innerWidth - rect.right,
            });
        }
    }, [isDropdownOpen]);

    const isAdmin = user?.isAdmin === true || isAdminRole(user?.role);
    const isUnitHead = user?.isUnitHead === true || user?.canEvaluateFaculty === true || isUnitHeadRole(user?.role);
    const isAssociateDean = user?.isAssociateDean === true || user?.role === 'associate_dean';
    const isDean = user?.isDean === true || user?.role === 'dean';
    const canEvaluate = isUnitHead || isAssociateDean || user?.canEvaluateFaculty === true;
    
    // Check if user is an evaluator (Unit Head or Associate Dean, but NOT Dean)
    const isEvaluator = useMemo(() => {
        return (isUnitHead || isAssociateDean) && !isDean;
    }, [isUnitHead, isAssociateDean, isDean]);

    const facultyName = useMemo(() => {
        const first = user?.firstname || '';
        const last = user?.lastname || '';
        return `${first} ${last}`.trim() || 'Faculty';
    }, [user]);

    const formatGrade = (grade) => {
        if (!grade || grade === 'N/A') return 'N/A';
        return typeof grade === 'number' ? grade.toFixed(2) : grade;
    };

    const formatPercentage = (value) => {
        if (!value || value === 'N/A') return 'N/A';
        return typeof value === 'number' ? `${value.toFixed(1)}%` : value;
    };

    const getPerformanceBadge = (grade) => {
        if (!grade || grade === 'N/A') return null;
        const numGrade = parseFloat(grade);
        if (numGrade <= 1.5) return { label: 'Outstanding', color: 'bg-yellow-100 text-yellow-800 border-yellow-300' };
        if (numGrade <= 1.75) return { label: 'Excellent', color: 'bg-yellow-100 text-yellow-800 border-yellow-300' };
        if (numGrade <= 2.0) return { label: 'Very Good', color: 'bg-blue-100 text-blue-800 border-blue-300' };
        if (numGrade <= 2.5) return { label: 'Good', color: 'bg-blue-100 text-blue-800 border-blue-300' };
        if (numGrade <= 3.0) return { label: 'Satisfactory', color: 'bg-amber-100 text-amber-800 border-amber-300' };
        return { label: 'Needs Improvement', color: 'bg-red-100 text-red-800 border-red-300' };
    };

    const performanceBadge = getPerformanceBadge(facultyGrade);

    const latestEvaluationDate = useMemo(() => {
        if (recentEvaluations.length === 0 && recentGrades.length === 0) return null;
        const allDates = [
            ...recentEvaluations.map(e => e.submitted_at),
            ...recentGrades.map(g => g.submitted_at)
        ].filter(Boolean);
        if (allDates.length === 0) return null;
        const latest = new Date(Math.max(...allDates.map(d => new Date(d).getTime())));
        return latest.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    }, [recentEvaluations, recentGrades]);

    const handleTermChange = (termId) => {
        setSelectedTermId(termId);
        setIsDropdownOpen(false);
        setIsFilterLoading(true);
        const url = new URL(window.location.href);
        if (termId && termId !== 'all') {
            url.searchParams.set('term', termId);
        } else {
            url.searchParams.delete('term');
        }
        window.location.href = url.toString();
    };

    const handleNavigation = (url, buttonKey) => {
        setLoadingButtons(prev => ({ ...prev, [buttonKey]: true }));
        window.location.href = url;
    };

    const currentTermLabel = useMemo(() => {
        if (selectedTermLabel) return selectedTermLabel;
        if (selectedTermId) {
            const term = availableTerms.find(t => t.value === selectedTermId);
            return term ? term.label : 'Select Term';
        }
        return 'All Terms';
    }, [selectedTermId, selectedTermLabel, availableTerms]);

    // Compute completion percentage for the progress bar
    const completionPercentage = useMemo(() => {
        if (totalFaculty === 0) return 0;
        return Math.round((evaluatedCount / totalFaculty) * 100);
    }, [evaluatedCount, totalFaculty]);

    // Check if user should see evaluation progress
    const showEvaluationProgress = useMemo(() => {
        return isEvaluator;
    }, [isEvaluator]);

    // Check if user should see evaluation sources
    const showEvaluationSources = useMemo(() => {
        return !isAdmin && !isDean;
    }, [isAdmin, isDean]);

    // Check if user should see dean-specific content
    const showDeanContent = useMemo(() => {
        return isDean;
    }, [isDean]);

    // Get rankings based on active tab - backend already sorted and limited to top 5
    const getRankingsForTab = useMemo(() => {
        if (activeChartTab === 'set') {
            return deanSetRankings || [];
        } else {
            return deanSefRankings || [];
        }
    }, [deanSetRankings, deanSefRankings, activeChartTab]);

    // Use data directly from backend - no frontend filtering needed
    const completedFacultyData = deanCompletedFaculty || [];
    const completedCount = completedFacultyData.filter((faculty) => faculty.set && faculty.sef && faculty.ife && faculty.feda).length;

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
            activePage="dashboard"
            logoutUrl={logoutUrl}
            csrfToken={csrfToken}
            hasPendingEvaluations={hasPendingEvaluations}
        >
            <main className="flex-1 overflow-y-auto p-6 bg-gradient-to-br from-slate-50 via-white to-yellow-50/40">
                
                {/* ========== HERO SECTION ========== */}
                <section className="relative overflow-hidden rounded-3xl border border-slate-200 bg-slate-950 px-6 py-7 text-white shadow-xl shadow-slate-900/10 sm:px-8">
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_rgba(125,211,252,0.24),_transparent_38%),radial-gradient(circle_at_bottom_left,_rgba(148,163,184,0.18),_transparent_35%)]" />
                    
                    <div className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-yellow-400 to-transparent" />
                    
                    <div className="relative">
                        <div className="flex items-center justify-between flex-wrap gap-4">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-200/90">
                                    {isDean ? 'Dean Dashboard' : isAssociateDean ? 'Associate Dean Dashboard' : isUnitHead ? 'Unit Head Dashboard' : isAdmin ? 'Dashboard' : 'Faculty Dashboard'}
                                </p>
                                <h1 className="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                                    Welcome back, <span className="text-yellow-300">{facultyName}</span>
                                </h1>
                                <p className="mt-1 text-sm text-blue-200/80">
                                    {isDean 
                                        ? 'Overview of your college\'s faculty performance and evaluations'
                                        : isAssociateDean 
                                            ? 'Manage unit head evaluations and track your performance'
                                            : isUnitHead 
                                                ? 'Manage faculty evaluations and track your performance'
                                                : isAdmin
                                                    ? 'System administration dashboard'
                                                    : 'Track your grades, evaluations, and progress in one place.'}
                                </p>
                            </div>
                            
                            {/* Academic Year Dropdown */}
                            <div className="relative">
                                <button
                                    ref={buttonRef}
                                    onClick={() => setIsDropdownOpen(!isDropdownOpen)}
                                    className="inline-flex items-center gap-2 rounded-full bg-white/10 backdrop-blur-sm px-4 py-2 text-sm text-blue-100 border border-white/20 hover:bg-white/20 transition-all duration-200"
                                    disabled={isFilterLoading}
                                >
                                    {isFilterLoading ? (
                                        <>
                                            <svg className="animate-spin h-4 w-4 text-yellow-400" fill="none" viewBox="0 0 24 24">
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span>Loading...</span>
                                        </>
                                    ) : (
                                        <>
                                            <svg className="h-4 w-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                            <span>{currentTermLabel}</span>
                                            <svg 
                                                className={`h-4 w-4 transition-transform duration-200 ${isDropdownOpen ? 'rotate-180' : ''}`} 
                                                fill="none" 
                                                stroke="currentColor" 
                                                viewBox="0 0 24 24"
                                            >
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </>
                                    )}
                                </button>

                                {isDropdownOpen && (
                                    <>
                                        <div 
                                            className="fixed inset-0 z-[9999]"
                                            onClick={() => setIsDropdownOpen(false)}
                                        />
                                        <div 
                                            className="fixed w-72 rounded-xl bg-white shadow-2xl shadow-slate-900/20 border border-slate-200/60 z-[10000] overflow-hidden"
                                            style={{
                                                top: dropdownPosition.top,
                                                right: dropdownPosition.right,
                                            }}
                                        >
                                            <div className="max-h-64 overflow-y-auto py-1">                     
                                                {availableTerms && availableTerms.length > 0 ? (
                                                    availableTerms.map((term) => (
                                                        <button
                                                            key={term.value}
                                                            onClick={() => handleTermChange(term.value)}
                                                            className={`w-full px-4 py-2.5 text-left text-sm transition-colors hover:bg-blue-50 flex items-center justify-between ${
                                                                selectedTermId === term.value ? 'bg-blue-50 text-slate-900 font-medium' : 'text-slate-700'
                                                            }`}
                                                            disabled={isFilterLoading}
                                                        >
                                                            <span>{term.label}</span>
                                                            {selectedTermId === term.value && (
                                                                <svg className="h-4 w-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                                </svg>
                                                            )}
                                                        </button>
                                                    ))
                                                ) : (
                                                    <div className="px-4 py-3 text-sm text-slate-500 text-center">
                                                        No terms available
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>

                        {facultyGrade !== 'N/A' && (
                            <div className="mt-4 flex items-center gap-4">
                                <div className="flex items-baseline gap-2">
                                    <span className={`text-3xl font-bold ${facultyGrade && parseFloat(facultyGrade) <= 1.75 ? 'text-yellow-300' : 'text-white'}`}>
                                        {formatGrade(facultyGrade)}
                                    </span>
                                    <span className="text-sm text-blue-300">GPA</span>
                                </div>
                                {performanceBadge && (
                                    <span className={`inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium ${performanceBadge.color} backdrop-blur-sm`}>
                                        {performanceBadge.label}
                                    </span>
                                )}
                            </div>
                        )}
                    </div>
                </section>

                {/* ========== KEY PERFORMANCE METRICS ========== */}
                {!isAdmin && (
                    <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-4">
                        <div className="rounded-2xl bg-gradient-to-br from-blue-50/80 to-white p-5 shadow-lg shadow-blue-500/10 border border-blue-200/40 hover:shadow-xl hover:shadow-blue-500/20 transition-all duration-300">
                            <div className="flex items-center gap-3">
                                <div className="rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 p-2.5 text-white shadow-lg shadow-blue-400/30">
                                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                    </svg>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold text-slate-900">{subjectsHandled}</p>
                                    <p className="text-xs text-slate-500 font-medium">Subjects Handled</p>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-2xl bg-gradient-to-br from-yellow-50/80 to-white p-5 shadow-lg shadow-yellow-500/10 border border-yellow-200/40 hover:shadow-xl hover:shadow-yellow-500/20 transition-all duration-300">
                            <div className="flex items-center gap-3">
                                <div className="rounded-xl bg-gradient-to-br from-yellow-400 to-yellow-500 p-2.5 text-white shadow-lg shadow-yellow-400/30">
                                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                    </svg>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold text-slate-900">{formatPercentage(averageSetRating)}</p>
                                    <p className="text-xs text-slate-500 font-medium">My Avg SET Rating</p>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-2xl bg-gradient-to-br from-blue-50/80 to-white p-5 shadow-lg shadow-blue-500/10 border border-blue-200/40 hover:shadow-xl hover:shadow-blue-500/20 transition-all duration-300">
                            <div className="flex items-center gap-3">
                                <div className="rounded-xl bg-gradient-to-br from-blue-600 to-blue-700 p-2.5 text-white shadow-lg shadow-blue-400/30">
                                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold text-slate-900">{formatPercentage(averageSefRating)}</p>
                                    <p className="text-xs text-slate-500 font-medium">My Avg SEF Rating</p>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-2xl bg-gradient-to-br from-blue-50/80 to-white p-5 shadow-lg shadow-blue-500/10 border border-blue-200/40 hover:shadow-xl hover:shadow-blue-500/20 transition-all duration-300">
                            <div className="flex items-center gap-3">
                                <div className="rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 p-2.5 text-white shadow-lg shadow-blue-400/30">
                                    {isEvaluator ? (
                                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                        </svg>
                                    ) : (
                                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                        </svg>
                                    )}
                                </div>
                                <div>
                                    <p className="text-2xl font-bold text-slate-900">
                                        {isEvaluator ? `${evaluatedCount}/${totalFaculty}` : evaluationsReceived}
                                    </p>
                                    <p className="text-xs text-slate-500 font-medium">
                                        {isEvaluator ? (
                                            isAssociateDean ? 'Unit Heads Evaluated' : 'Faculty Evaluated'
                                        ) : isDean ? 'College Student Evaluations' : 'Student Evaluations Received'}
                                    </p>
                                    {isEvaluator && totalFaculty > 0 && (
                                        <div className="mt-1 w-full">
                                            <div className="h-1.5 w-full bg-blue-100 rounded-full overflow-hidden">
                                                <div 
                                                    className="h-full bg-blue-600 rounded-full transition-all duration-500"
                                                    style={{ width: `${completionPercentage}%` }}
                                                />
                                            </div>
                                            <p className="text-[10px] text-slate-400 mt-0.5">
                                                {completionPercentage}% complete
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* ========== DEAN SPECIFIC CONTENT ========== */}
                {showDeanContent && (
                    <div className="mt-8">
                        {/* ========== COMPLETED FACULTY (Full width card) ========== */}
                        <div className="rounded-xl bg-white shadow-lg shadow-slate-200/50 border border-slate-200/60 overflow-hidden">
                            <div className="px-6 py-4 border-b border-slate-200">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <h2 className="text-lg font-semibold text-slate-900 flex items-center gap-2">
                                            <span className="inline-block w-1 h-6 bg-gradient-to-b from-emerald-500 to-emerald-600 rounded-full" />
                                            Completed Faculty
                                        </h2>
                                        <p className="text-sm text-slate-500">
                                            Faculty who completed all requirements (SET, SEF, IFE, FEDA)
                                        </p>
                                    </div>
                                    <span className="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-700">
                                        {completedCount} / {completedFacultyData.length}
                                    </span>
                                </div>
                            </div>

                            <div className="p-4 max-h-[320px] overflow-y-auto">
                                {completedFacultyData.length > 0 ? (
                                    <div className="space-y-2">
                                        {/* Table Header - Sticky */}
                                        <div className="sticky top-0 z-10 grid grid-cols-6 gap-2 px-4 py-2 bg-slate-50 rounded-lg text-xs font-semibold text-slate-500 uppercase tracking-wider border-b border-slate-200">
                                            <div className="col-span-2">Faculty</div>
                                            <div className="text-center">SET</div>
                                            <div className="text-center">SEF</div>
                                            <div className="text-center">IFE</div>
                                            <div className="text-center">FEDA</div>
                                        </div>

                                        {/* Table Rows */}
                                        {completedFacultyData.map((faculty, index) => {
                                            const allCompleted = faculty.set && faculty.sef && faculty.ife && faculty.feda;
                                            return (
                                                <div 
                                                    key={index}
                                                    className={`grid grid-cols-6 gap-2 px-4 py-3 rounded-lg transition-colors items-center ${
                                                        allCompleted 
                                                            ? 'bg-emerald-50/50 border border-emerald-200' 
                                                            : 'bg-slate-50/50 border border-slate-200'
                                                    }`}
                                                >
                                                    <div className="col-span-2 min-w-0">
                                                        <p className="text-sm font-semibold text-slate-900 truncate">
                                                            {faculty.name}
                                                        </p>
                                                        <p className="text-xs text-slate-500 truncate">
                                                            {faculty.department}
                                                        </p>
                                                    </div>
                                                    
                                                    <div className="text-center">
                                                        {faculty.set ? (
                                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-600">
                                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                                </svg>
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-slate-400">
                                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                                </svg>
                                                            </span>
                                                        )}
                                                    </div>
                                                    
                                                    <div className="text-center">
                                                        {faculty.sef ? (
                                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-600">
                                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                                </svg>
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-slate-400">
                                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                                </svg>
                                                            </span>
                                                        )}
                                                    </div>
                                                    
                                                    <div className="text-center">
                                                        {faculty.ife ? (
                                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-600">
                                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                                </svg>
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-slate-400">
                                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                                </svg>
                                                            </span>
                                                        )}
                                                    </div>
                                                    
                                                    <div className="text-center">
                                                        {faculty.feda ? (
                                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-600">
                                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                                </svg>
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-slate-400">
                                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                                </svg>
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="text-center py-8 text-slate-500">
                                        No faculty found
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Two columns below: Rankings and My Subjects */}
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            {/* Chart Card - Left Column */}
                            <div className="rounded-xl bg-white shadow-lg shadow-slate-200/50 border border-slate-200/60 overflow-hidden">
                                <div className="px-6 py-4 border-b border-slate-200">
                                    <h2 className="text-lg font-semibold text-slate-900 flex items-center gap-2">
                                        <span className="inline-block w-1 h-6 bg-gradient-to-b from-blue-500 to-blue-600 rounded-full" />
                                        Faculty Rankings
                                    </h2>
                                    <p className="text-sm text-slate-500">
                                        Based on evaluation ratings for the selected term
                                    </p>
                                </div>
                                
                                {/* Tabs */}
                                <div className="flex border-b border-slate-200">
                                    <button
                                        onClick={() => {
                                            setActiveChartTab('set');
                                        }}
                                        className={`flex-1 px-4 py-3 text-sm font-medium transition-colors ${
                                            activeChartTab === 'set'
                                                ? 'text-blue-600 border-b-2 border-blue-600 bg-blue-50/30'
                                                : 'text-slate-500 hover:text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        SET Rankings
                                    </button>
                                    <button
                                        onClick={() => {
                                            setActiveChartTab('sef');
                                        }}
                                        className={`flex-1 px-4 py-3 text-sm font-medium transition-colors ${
                                            activeChartTab === 'sef'
                                                ? 'text-blue-600 border-b-2 border-blue-600 bg-blue-50/30'
                                                : 'text-slate-500 hover:text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        SEF Rankings
                                    </button>
                                </div>

                                {/* Chart Content */}
                                <div className="p-6">
                                    {getRankingsForTab.length > 0 ? (
                                        <div className="space-y-4">
                                            {getRankingsForTab.map((item, index) => {
                                                const rating = activeChartTab === 'set' ? item.set_rating : item.sef_rating;
                                                const isSetTab = activeChartTab === 'set';
                                                
                                                return (
                                                    <div key={item.id_no || index} className="flex items-center gap-4">
                                                        <span className="text-sm font-medium text-slate-500 w-8">
                                                            #{index + 1}
                                                        </span>
                                                        <div className="flex-1">
                                                            <div className="flex items-center justify-between mb-1">
                                                                <span className="text-sm font-medium text-slate-700 truncate">
                                                                    {item.name}
                                                                </span>
                                                                <span className={`text-sm font-semibold ${isSetTab ? 'text-blue-600' : 'text-emerald-600'}`}>
                                                                    {rating !== null ? `${rating}%` : 'N/A'}
                                                                </span>
                                                            </div>
                                                            <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                                                                <div 
                                                                    className={`h-full rounded-full transition-all duration-500 ${
                                                                        isSetTab 
                                                                            ? 'bg-gradient-to-r from-blue-500 to-blue-600' 
                                                                            : 'bg-gradient-to-r from-emerald-500 to-emerald-600'
                                                                    }`}
                                                                    style={{ width: `${rating !== null ? Math.min(rating, 100) : 0}%` }}
                                                                />
                                                            </div>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    ) : (
                                        <div className="py-8 text-center text-sm text-slate-500">
                                            No {activeChartTab.toUpperCase()} ranking data available for this term yet.
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* My Subjects - Right Column (swapped from below) */}
                            <div className="rounded-xl bg-white shadow-lg shadow-slate-200/50 border border-slate-200/60 overflow-hidden">
                                <div className="px-6 py-4 border-b border-slate-200">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <h2 className="text-lg font-semibold text-slate-900 flex items-center gap-2">
                                                <span className="inline-block w-1 h-6 bg-gradient-to-b from-yellow-400 to-yellow-500 rounded-full" />
                                                My Subjects
                                            </h2>
                                            <p className="text-sm text-slate-500">
                                                {subjectsHandled} subject{subjectsHandled !== 1 ? 's' : ''} assigned this term
                                            </p>
                                        </div>
                                        <button
                                            onClick={() => handleNavigation(subjectsUrl, 'subjects')}
                                            disabled={loadingButtons.subjects}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-r from-yellow-400 to-yellow-500 px-3 py-1.5 text-sm font-medium text-white shadow-lg shadow-yellow-400/30 hover:shadow-xl hover:shadow-yellow-400/40 transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            {loadingButtons.subjects ? (
                                                <>
                                                    <svg className="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                    Loading...
                                                </>
                                            ) : (
                                                <>
                                                    View All
                                                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                                    </svg>
                                                </>
                                            )}
                                        </button>
                                    </div>
                                </div>

                                <div className="p-4 max-h-[320px] overflow-y-auto">
                                    {mySubjects && mySubjects.length > 0 ? (
                                        <div className="space-y-2">
                                            {mySubjects.map((subject, index) => (
                                                <div key={`${subject.course_code}-${index}`} className="flex items-center justify-between px-4 py-3 bg-slate-50/50 rounded-lg hover:bg-gradient-to-r hover:from-yellow-50/30 hover:to-blue-50/30 transition-colors border border-slate-200">
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-sm font-semibold text-slate-900">
                                                            {subject.course_code || '-'}
                                                        </p>
                                                        <p className="text-xs text-slate-500 truncate">
                                                            {subject.course_title || subject.course_description || '-'}
                                                        </p>
                                                    </div>
                                                    <div className="flex-shrink-0 ml-2">
                                                        <span className="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 border border-blue-200">
                                                            {subject.section_code || 'N/A'}
                                                        </span>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="text-center py-8 text-slate-500">
                                            <div className="mx-auto h-12 w-12 rounded-full bg-gradient-to-br from-yellow-100 to-blue-100 flex items-center justify-center mb-3">
                                                <svg className="h-6 w-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                                </svg>
                                            </div>
                                            <p className="text-sm text-slate-500">No subjects assigned for this term</p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* ========== MY SUBJECTS (for non-Deans) ========== */}
                {!isAdmin && !isDean && (
                    <div className="mt-8">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900 flex items-center gap-2">
                                    <span className="inline-block w-1 h-6 bg-gradient-to-b from-yellow-400 to-yellow-500 rounded-full" />
                                    My Subjects
                                </h2>
                                <p className="text-sm text-slate-500">
                                    {subjectsHandled} subject{subjectsHandled !== 1 ? 's' : ''} assigned this term
                                </p>
                            </div>
                            <button
                                onClick={() => handleNavigation(subjectsUrl, 'subjects')}
                                disabled={loadingButtons.subjects}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-r from-yellow-400 to-yellow-500 px-3 py-1.5 text-sm font-medium text-white shadow-lg shadow-yellow-400/30 hover:shadow-xl hover:shadow-yellow-400/40 transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {loadingButtons.subjects ? (
                                    <>
                                        <svg className="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Loading...
                                    </>
                                ) : (
                                    <>
                                        View All
                                        <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                        </svg>
                                    </>
                                )}
                            </button>
                        </div>

                        {mySubjects && mySubjects.length > 0 ? (
                            <div className="overflow-hidden rounded-xl bg-white shadow-lg shadow-slate-200/50 border border-slate-200/60">
                                <div className="overflow-y-auto max-h-[320px]">
                                    <table className="min-w-full divide-y divide-slate-100">
                                        <thead className="sticky top-0 z-10 bg-white">
                                            <tr className="bg-gradient-to-r from-blue-50/80 via-white to-yellow-50/80">
                                                <th className="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Course Code</th>
                                                <th className="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Course Description</th>
                                                <th className="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Section</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-50">
                                            {mySubjects.map((subject, index) => (
                                                <tr key={`${subject.course_code}-${index}`} className="hover:bg-gradient-to-r hover:from-yellow-50/30 hover:to-blue-50/30 transition-colors">
                                                    <td className="px-3 py-2.5 text-sm font-semibold text-slate-900 whitespace-nowrap">
                                                        {subject.course_code || '-'}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-sm text-slate-600 break-words max-w-[200px]">
                                                        {subject.course_title || subject.course_description || '-'}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-sm text-slate-600 whitespace-nowrap">
                                                        {subject.section_code || '-'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                
                                {mySubjects.length > 0 && (
                                    <div className="border-t border-slate-100 px-4 py-2 text-center text-xs text-slate-400 bg-slate-50/50">
                                        Showing {mySubjects.length} subject{mySubjects.length !== 1 ? 's' : ''}
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="rounded-xl bg-white p-8 text-center shadow-lg shadow-slate-200/50 border border-slate-200/60">
                                <div className="mx-auto h-12 w-12 rounded-full bg-gradient-to-br from-yellow-100 to-blue-100 flex items-center justify-center">
                                    <svg className="h-6 w-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                    </svg>
                                </div>
                                <p className="mt-3 text-sm text-slate-500">No subjects assigned for this term</p>
                            </div>
                        )}
                    </div>
                )}

                {/* ========== EVALUATION SOURCES - Only for Faculty, Unit Head, Associate Dean (NOT Dean) ========== */}
                {showEvaluationSources && (
                    <div className="mt-8">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900 flex items-center gap-2">
                                    <span className="inline-block w-1 h-6 bg-gradient-to-b from-blue-500 to-blue-600 rounded-full" />
                                    Student Evaluation Sources
                                </h2>
                                <p className="text-sm text-slate-500">
                                    {evaluationSections?.length || 0} section{evaluationSections?.length !== 1 ? 's' : ''} that evaluated you
                                </p>
                            </div>
                            <button
                                onClick={() => handleNavigation(reportsUrl, 'reports')}
                                disabled={loadingButtons.reports}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-r from-blue-500 to-blue-600 px-3 py-1.5 text-sm font-medium text-white shadow-lg shadow-blue-400/30 hover:shadow-xl hover:shadow-blue-400/40 transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {loadingButtons.reports ? (
                                    <>
                                        <svg className="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Loading...
                                    </>
                                ) : (
                                    <>
                                        View All
                                        <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                        </svg>
                                    </>
                                )}
                            </button>
                        </div>

                        {evaluationSections && evaluationSections.length > 0 ? (
                            <div className="rounded-xl bg-white shadow-lg shadow-slate-200/50 border border-slate-200/60 overflow-hidden">
                                <div className="divide-y divide-slate-100 max-h-[320px] overflow-y-auto">
                                    {evaluationSections.map((section, index) => (
                                        <div key={index} className="flex items-center justify-between px-4 py-3 hover:bg-gradient-to-r hover:from-blue-50/30 hover:to-yellow-50/30 transition-colors">
                                            <div className="flex items-center gap-3">
                                                <div className="flex-shrink-0">
                                                    <div className="h-10 w-10 rounded-lg bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center">
                                                        <span className="text-sm font-bold text-blue-700">{section.year || 'N/A'}</span>
                                                    </div>
                                                </div>
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-900">
                                                        {section.section_name || 'Unnamed Section'}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {section.course_code || 'No course'}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <span className="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 border border-blue-200">
                                                    {section.evaluation_count || 0} submissions
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                {evaluationSections.length > 0 && (
                                    <div className="border-t border-slate-100 px-4 py-2 text-center text-xs text-slate-400 bg-slate-50/50">
                                        Showing {evaluationSections.length} section{evaluationSections.length !== 1 ? 's' : ''}
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="rounded-xl bg-white p-8 text-center shadow-lg shadow-slate-200/50 border border-slate-200/60">
                                <div className="mx-auto h-12 w-12 rounded-full bg-gradient-to-br from-blue-100 to-yellow-100 flex items-center justify-center">
                                    <svg className="h-6 w-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                </div>
                                <p className="mt-3 text-sm text-slate-500">No evaluation sections available</p>
                            </div>
                        )}
                    </div>
                )}

                {/* ========== EVALUATION PROGRESS (Only for Unit Head & Associate Dean) ========== */}
                {showEvaluationProgress && (
                    <div className="mt-8">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900 flex items-center gap-2">
                                    <span className="inline-block w-1 h-6 bg-gradient-to-b from-blue-500 to-blue-600 rounded-full" />
                                    {isAssociateDean ? 'Unit Head Evaluation Progress' : 'Faculty Evaluation Progress'}
                                </h2>
                                <p className="text-sm text-slate-500">
                                    {pendingCount} pending, {evaluatedCount} completed of {totalFaculty} total
                                </p>
                            </div>
                            <button
                                onClick={() => handleNavigation(`${evaluationUrl}${selectedTermId ? `?term=${selectedTermId}` : ''}`, 'evaluate')}
                                disabled={loadingButtons.evaluate}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-r from-blue-500 to-blue-600 px-3 py-1.5 text-sm font-medium text-white shadow-lg shadow-blue-400/30 hover:shadow-xl hover:shadow-blue-400/40 transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {loadingButtons.evaluate ? (
                                    <>
                                        <svg className="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Loading...
                                    </>
                                ) : (
                                    <>
                                        Evaluate
                                        <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                        </svg>
                                    </>
                                )}
                            </button>
                        </div>

                        {facultyEvaluationList && facultyEvaluationList.length > 0 ? (
                            <div className="rounded-xl bg-white shadow-lg shadow-slate-200/50 border border-slate-200/60 overflow-hidden">
                                <div className="divide-y divide-slate-100 max-h-[320px] overflow-y-auto">
                                    {[...facultyEvaluationList]
                                        .sort((a, b) => {
                                            const aCompleted = a.status === 'Completed' || a.evaluated === true;
                                            const bCompleted = b.status === 'Completed' || b.evaluated === true;
                                            return aCompleted === bCompleted ? 0 : aCompleted ? 1 : -1;
                                        })
                                        .map((faculty, index) => {
                                            const isCompleted = faculty.status === 'Completed' || faculty.evaluated === true;
                                            return (
                                                <div key={index} className="flex items-center justify-between px-4 py-3 hover:bg-gradient-to-r hover:from-blue-50/30 hover:to-yellow-50/30 transition-colors">
                                                    <div className="flex items-center gap-3 min-w-0 flex-1">
                                                        <div className="flex-shrink-0">
                                                            <div className="h-10 w-10 rounded-lg bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center">
                                                                <span className="text-sm font-bold text-blue-700">
                                                                    {faculty.initials || faculty.name?.charAt(0) || '?'}
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <p className="text-sm font-semibold text-slate-900 truncate">
                                                                {faculty.name || faculty.instructor || 'Unknown'}
                                                            </p>
                                                            <p className="text-xs text-slate-500">
                                                                {faculty.role === 'Unit Head' ? `Unit Head · ${faculty.department}` : faculty.department || 'No department'}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-3 flex-shrink-0">
                                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                            isCompleted 
                                                                ? 'bg-emerald-100 text-emerald-700 border border-emerald-200'
                                                                : 'bg-amber-100 text-amber-700 border border-amber-200'
                                                        }`}>
                                                            {isCompleted ? 'Completed' : 'Pending'}
                                                        </span>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                </div>
                                <div className="border-t border-slate-100 px-4 py-2 text-center text-xs text-slate-400 bg-slate-50/50">
                                    Showing {facultyEvaluationList.length} {isAssociateDean ? 'unit head' : 'faculty member'}{facultyEvaluationList.length !== 1 ? 's' : ''}
                                    <span className="ml-2">
                                        ({facultyEvaluationList.filter(f => f.status === 'Pending' || !f.evaluated).length} pending, 
                                        {facultyEvaluationList.filter(f => f.status === 'Completed' || f.evaluated).length} completed)
                                    </span>
                                </div>
                            </div>
                        ) : (
                            <div className="rounded-xl bg-white p-8 text-center shadow-lg shadow-slate-200/50 border border-slate-200/60">
                                <div className="mx-auto h-12 w-12 rounded-full bg-gradient-to-br from-blue-100 to-yellow-100 flex items-center justify-center">
                                    <svg className="h-6 w-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                </div>
                                <p className="mt-3 text-sm text-slate-500">
                                    {isAssociateDean ? 'No unit heads to evaluate' : 'No faculty members to evaluate'}
                                </p>
                            </div>
                        )}
                    </div>
                )}
            </main>
        </AppLayout>
    );
}