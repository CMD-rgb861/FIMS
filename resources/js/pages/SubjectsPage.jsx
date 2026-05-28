import React, { useMemo, useState, useCallback, useEffect } from 'react';
import { router } from '@inertiajs/react';
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
    selectedTerm = '', // Receive selected term from backend
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
            layoutClassName="h-screen flex overflow-hidden bg-slate-50 text-slate-900"
        >
            <main className="flex-1 overflow-y-auto p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight">Enrolled Subjects</h1>
                    <p className="mt-1 text-sm text-slate-500">Faculty: {displayName}</p>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 px-4 py-3 gap-3">
                        <p className="text-sm font-semibold text-slate-900">Enrolled Subjects</p>
                        <div className="flex items-center gap-3">
                            {availableTerms.length > 0 && (
                                <label className="text-xs text-slate-600">
                                    <span className="mr-2">Filter by Term:</span>
                                    <select
                                        value={localSelectedTerm}
                                        onChange={handleTermChange}
                                        disabled={isLoading}
                                        className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                        {availableTerms.map((term) => (
                                            <option key={term.id} value={term.id}>
                                                {term.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <p className="text-xs text-slate-500">
                                {subjectPagination?.total || subjectItems.length} total records
                            </p>
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-xs">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Course Code</th>
                                    <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Course Description</th>
                                    <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Units</th>
                                    <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Section</th>
                                    <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Schedule</th>
                                    <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Days</th>
                                    <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Room</th>
                                    <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Term</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 bg-white">
                                {subjectItems.length > 0 ? (
                                    subjectItems.map((subject, index) => (
                                        <tr key={`${subject.course_code}-${subject.course_description}-${index}`} className="hover:bg-slate-50 transition">
                                            <td className="px-4 py-2.5 font-medium text-slate-900">{subject.course_code || '-'}</td>
                                            <td className="px-4 py-2.5 text-slate-700">{subject.course_description || '-'}</td>
                                            <td className="px-4 py-2.5 text-slate-700">{subject.course_units ?? '-'}</td>
                                            <td className="px-4 py-2.5 text-slate-700">{subject.section_code || '-'}</td>
                                            <td className="px-4 py-2.5 text-slate-700">{subject.schedule_time || '-'}</td>
                                            <td className="px-4 py-2.5 text-slate-700">{subject.schedule_days || '-'}</td>
                                            <td className="px-4 py-2.5 text-slate-700">{subject.room || '-'}</td>
                                            <td className="px-4 py-2.5 text-slate-700">
                                                {subject.term || subject.semester || (subject.school_year_id ? `SY #${subject.school_year_id}` : '-')}
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-8 text-center text-slate-500">
                                            {isLoading ? (
                                                <div className="flex items-center justify-center gap-2">
                                                    <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>
                                                    <span>Loading...</span>
                                                </div>
                                            ) : (
                                                'No enrolled subjects found.'
                                            )}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {Pagination}
                </section>
            </main>
        </AppLayout>
    );
}