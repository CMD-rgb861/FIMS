import React, { useMemo, useState, useCallback, useEffect } from 'react';
import { router, Link } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';

export default function SubjectsPage({
    subjectPagination = null,
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
    availableTerms = [],
    selectedTerm = '',
    hasPendingEvaluations = false,
}) {
    const displayName = useMemo(() => {
        return user?.display_name || [user?.firstname, user?.lastname].filter(Boolean).join(' ') || 'Faculty';
    }, [user]);
    
    // Handle both array and object with data property
    const subjectItems = useMemo(() => {
        if (Array.isArray(subjects)) return subjects;
        if (subjects?.data && Array.isArray(subjects.data)) return subjects.data;
        return [];
    }, [subjects]);
    
    const [isLoading, setIsLoading] = useState(false);
    const [localSelectedTerm, setLocalSelectedTerm] = useState(selectedTerm || '');

    // Sync local state with prop from backend
    useEffect(() => {
        setLocalSelectedTerm(selectedTerm || '');
    }, [selectedTerm]);

    // Handle page change with loading state and term preservation
    const handlePageChange = useCallback((page) => {
        if (isLoading) return;
        
        setIsLoading(true);
        
        const params = { page };
        if (localSelectedTerm && localSelectedTerm !== 'all') {
            params.term = localSelectedTerm;
        }
        
        router.get(route('subjects'), params, {
            preserveState: true,
            replace: true,
            preserveScroll: true,
            onFinish: () => setIsLoading(false),
            onError: () => setIsLoading(false),
        });
    }, [localSelectedTerm, isLoading]);

    // Handle term change - reset to page 1 and filter on backend
    const handleTermChange = useCallback((event) => {
        const newTerm = event.target.value;
        setLocalSelectedTerm(newTerm);
        setIsLoading(true);
        
        const params = { page: 1 };
        if (newTerm && newTerm !== 'all' && newTerm !== '') {
            params.term = newTerm;
        }
        
        router.get(route('subjects'), params, {
            preserveState: true,
            replace: true,
            preserveScroll: true,
            onFinish: () => setIsLoading(false),
            onError: () => setIsLoading(false),
        });
    }, []);

    // Calculate pagination range for display
    const paginationRange = useMemo(() => {
        if (!subjectPagination) return null;
        const { current_page, last_page, per_page, total } = subjectPagination;
        const start = (current_page - 1) * per_page + 1;
        const end = Math.min(current_page * per_page, total);
        return { start, end, total, current_page, last_page };
    }, [subjectPagination]);

    // Pagination component
    const Pagination = useMemo(() => {
        if (!subjectPagination || subjectPagination.last_page <= 1) return null;
        
        const { current_page, last_page } = subjectPagination;
        const maxVisible = 5;
        let startPage = Math.max(1, current_page - Math.floor(maxVisible / 2));
        let endPage = Math.min(last_page, startPage + maxVisible - 1);
        
        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }
        
        const pages = [];
        for (let i = startPage; i <= endPage; i++) {
            pages.push(i);
        }
        
        return (
            <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 mt-4">
                <div className="flex items-center gap-2">
                    <button
                        onClick={() => handlePageChange(current_page - 1)}
                        disabled={current_page <= 1 || isLoading}
                        className="relative inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Previous
                    </button>
                    
                    <div className="hidden sm:flex items-center gap-1">
                        {startPage > 1 && (
                            <>
                                <button
                                    onClick={() => handlePageChange(1)}
                                    disabled={isLoading}
                                    className="relative inline-flex items-center px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 rounded-md"
                                >
                                    1
                                </button>
                                {startPage > 2 && <span className="px-2 text-slate-400">...</span>}
                            </>
                        )}
                        
                        {pages.map(page => (
                            <button
                                key={page}
                                onClick={() => handlePageChange(page)}
                                disabled={isLoading}
                                className={`relative inline-flex items-center px-3 py-2 text-sm font-medium rounded-md ${
                                    page === current_page
                                        ? 'z-10 bg-blue-600 text-white'
                                        : 'text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                {page}
                            </button>
                        ))}
                        
                        {endPage < last_page && (
                            <>
                                {endPage < last_page - 1 && <span className="px-2 text-slate-400">...</span>}
                                <button
                                    onClick={() => handlePageChange(last_page)}
                                    disabled={isLoading}
                                    className="relative inline-flex items-center px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 rounded-md"
                                >
                                    {last_page}
                                </button>
                            </>
                        )}
                    </div>
                    
                    <button
                        onClick={() => handlePageChange(current_page + 1)}
                        disabled={current_page >= last_page || isLoading}
                        className="relative inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Next
                    </button>
                </div>
                
                {paginationRange && (
                    <div className="text-sm text-slate-500">
                        Showing <span className="font-medium">{paginationRange.start}</span> to{' '}
                        <span className="font-medium">{paginationRange.end}</span> of{' '}
                        <span className="font-medium">{paginationRange.total}</span> results
                    </div>
                )}
            </div>
        );
    }, [subjectPagination, isLoading, handlePageChange, paginationRange]);

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
            activePage="subjects"
            logoutUrl={logoutUrl}
            csrfToken={csrfToken}
            hasPendingEvaluations={hasPendingEvaluations}
            layoutClassName="min-h-screen flex"
        >
            <main className="flex-1">
                {/* Breadcrumbs - same style as EvaluationPage */}
                <div className="h-16 bg-white border-b border-slate-200 flex items-center px-6">
                    <div className="text-sm text-slate-500 flex items-center gap-2">
                        <Link href={dashboardUrl} className="hover:text-slate-700">Home</Link>
                        <span className="text-slate-300">›</span>
                        <span className="text-slate-700 font-medium">Subjects</span>
                    </div>
                </div>

                {/* Main content with padding */}
                <div className="p-6">
                    <div>
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Handled Subjects
                            </h1>

                            <div className="flex flex-col items-center">
                                <span className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    Faculty Name
                                </span>
                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                                    {displayName}
                                </span>
                            </div>
                        </div>
                        <p className="mt-2 max-w-3xl text-sm text-slate-500">
                            View and manage your assigned subjects for the current semester.
                        </p>
                    </div>

                    <div className="mt-6 flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                        <form method="GET" action={subjectsUrl} className="w-full xl:flex-1">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3 max-w-3xl">
                                {availableTerms.length > 0 && (
                                    <label className="block">
                                        <span className="sr-only">Filter by Term</span>
                                        <select
                                            name="term"
                                            value={localSelectedTerm}
                                            onChange={handleTermChange}
                                            disabled={isLoading}
                                            className="w-full cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 disabled:opacity-50"
                                        >
                                            {availableTerms.map((term) => (
                                                <option key={term.value} value={term.value}>
                                                    {term.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                            </div>
                        </form>

                        <div className="flex shrink-0 xl:pt-1">
                            <span className="inline-flex items-center justify-center rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-slate-700">
                                {subjectPagination?.total || subjectItems.length} total records
                            </span>
                        </div>
                    </div>

                    <section className="mt-6 rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-semibold text-slate-600">Course Code</th>
                                        <th className="px-4 py-3 text-left font-semibold text-slate-600">Course Description</th>
                                        <th className="px-4 py-3 text-left font-semibold text-slate-600">Units</th>
                                        <th className="px-4 py-3 text-left font-semibold text-slate-600">Section</th>
                                        <th className="px-4 py-3 text-left font-semibold text-slate-600">Schedule</th>
                                        <th className="px-4 py-3 text-left font-semibold text-slate-600">Days</th>
                                        <th className="px-4 py-3 text-left font-semibold text-slate-600">Room</th>
                                        <th className="px-4 py-3 text-left font-semibold text-slate-600">Term</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 bg-white relative">
                                    {isLoading ? (
                                        <tr>
                                            <td colSpan={8} className="px-4 py-12 text-center">
                                                <div className="flex flex-col items-center gap-3">
                                                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                                                    <span className="text-sm font-medium text-blue-600">Loading...</span>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : subjectItems.length > 0 ? (
                                        subjectItems.map((subject, index) => (
                                            <tr key={`${subject.course_code}-${subject.course_description}-${index}`} className="hover:bg-slate-50 transition">
                                                <td className="px-4 py-3 font-medium text-slate-900">{subject.course_code || '-'}</td>
                                                <td className="px-4 py-3 text-slate-700">{subject.course_description || '-'}</td>
                                                <td className="px-4 py-3 text-slate-700">{subject.course_units ?? '-'}</td>
                                                <td className="px-4 py-3 text-slate-700">{subject.section_code || '-'}</td>
                                                <td className="px-4 py-3 text-slate-700">{subject.schedule_time || '-'}</td>
                                                <td className="px-4 py-3 text-slate-700">{subject.schedule_days || '-'}</td>
                                                <td className="px-4 py-3 text-slate-700">{subject.room || '-'}</td>
                                                <td className="px-4 py-3 text-slate-700">
                                                    {subject.term || subject.semester || (subject.school_year_id ? `SY #${subject.school_year_id}` : '-')}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan={8} className="px-4 py-8 text-center text-slate-500">
                                                <div className="bg-slate-50 border border-slate-200 rounded-lg p-8 max-w-2xl text-center shadow-sm mx-auto">
                                                    <p className="text-sm text-slate-500">
                                                        No subjects found for the selected term.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {!isLoading && Pagination}
                    </section>
                </div>
            </main>
        </AppLayout>
    );
}