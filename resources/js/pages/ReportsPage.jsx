import React, { useState, useMemo, useCallback, useEffect, useRef } from 'react';
import AppLayout from '../Layouts/AppLayout';
import { isFacultyRole } from '../utils/role';
import { router } from '@inertiajs/react';

export default function ReportsPage({
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
    reportSummary = [],
    facultyList = { data: [], current_page: 1, last_page: 1, per_page: 12, total: 0 },
    recentReports = { data: [] },
    schoolYears = [],
    selectedSchoolYear = '',
}) {
    const isFaculty = useMemo(() => isFacultyRole(user?.role), [user?.role]);
    const [searchQuery, setSearchQuery] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [currentPage, setCurrentPage] = useState(facultyList.current_page || 1);
    const [gotoPage, setGotoPage] = useState('');
    
    // Refs to track mounted state and prevent duplicate requests
    const isMountedRef = useRef(true);
    const searchTimeoutRef = useRef(null);
    const isNavigatingRef = useRef(false);
    const lastRequestIdRef = useRef(0);

    // Update currentPage when prop changes (important for sync)
    useEffect(() => {
        if (facultyList.current_page && facultyList.current_page !== currentPage) {
            setCurrentPage(facultyList.current_page);
        }
    }, [facultyList.current_page, currentPage]);

    // Cleanup on unmount
    useEffect(() => {
        return () => {
            isMountedRef.current = false;
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
            }
        };
    }, []);

    // Centralized navigation function with proper cancellation handling
    const navigateToReports = useCallback((params, options = {}) => {
        // Prevent multiple simultaneous navigations
        if (isNavigatingRef.current) {
            return;
        }

        if (!isMountedRef.current) return;

        isNavigatingRef.current = true;
        setIsLoading(true);

        // Increment request ID to track the latest request
        const requestId = ++lastRequestIdRef.current;
        
        // Build the URL with query parameters
        const urlParams = new URLSearchParams();
        if (params.term && params.term !== '') urlParams.append('term', params.term);
        if (params.faculty_page) urlParams.append('faculty_page', params.faculty_page.toString());
        if (params.faculty_search) urlParams.append('faculty_search', params.faculty_search);
        if (params.faculty_per_page) urlParams.append('faculty_per_page', params.faculty_per_page.toString());
        
        const url = `/reports${urlParams.toString() ? `?${urlParams.toString()}` : ''}`;
        
        // Use a small delay to prevent rapid successive calls
        setTimeout(() => {
            // Only proceed if this is still the latest request
            if (requestId !== lastRequestIdRef.current) {
                isNavigatingRef.current = false;
                setIsLoading(false);
                return;
            }

            router.visit(url, {
                preserveState: options.preserveState ?? false,
                preserveScroll: options.preserveScroll ?? true,
                replace: options.replace ?? false,
                onFinish: () => {
                    if (isMountedRef.current && requestId === lastRequestIdRef.current) {
                        setIsLoading(false);
                        isNavigatingRef.current = false;
                    }
                },
                onError: () => {
                    if (isMountedRef.current && requestId === lastRequestIdRef.current) {
                        setIsLoading(false);
                        isNavigatingRef.current = false;
                    }
                }
            });
        }, 50);
    }, []);

    // Handle school year change
    const handleSchoolYearChange = useCallback((event) => {
        const newSchoolYear = event.target.value;
        
        // Clear any pending search timeout
        if (searchTimeoutRef.current) {
            clearTimeout(searchTimeoutRef.current);
            searchTimeoutRef.current = null;
        }
        
        // Reset states
        setSearchQuery('');
        
        navigateToReports({
            term: newSchoolYear, 
            faculty_page: 1,
            faculty_search: '',
            faculty_per_page: facultyList.per_page,
        }, {
            preserveState: false,
            replace: true
        });
    }, [facultyList.per_page, navigateToReports]);

    // Handle search with proper debounce
    const debouncedSearch = useCallback(() => {
        if (searchTimeoutRef.current) {
            clearTimeout(searchTimeoutRef.current);
        }
        
        searchTimeoutRef.current = setTimeout(() => {
            if (!isMountedRef.current) return;
            
            navigateToReports({
                term: selectedSchoolYear,
                faculty_page: 1,
                faculty_search: searchQuery,
                faculty_per_page: facultyList.per_page,
            }, {
                preserveState: true,
                preserveScroll: true,
            });
        }, 500);
    }, [searchQuery, selectedSchoolYear, facultyList.per_page, navigateToReports]);

    useEffect(() => {
        if (searchQuery !== undefined) {
            debouncedSearch();
        }
        
        return () => {
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
            }
        };
    }, [searchQuery, debouncedSearch]);

    // Handle page change
    const handlePageChange = useCallback((newPage) => {
        if (newPage === currentPage || !isMountedRef.current) return;
        
        // Don't allow page changes outside valid range
        if (newPage < 1 || newPage > facultyList.last_page) return;
        
        navigateToReports({
            term: selectedSchoolYear,
            faculty_page: newPage,
            faculty_search: searchQuery,
            faculty_per_page: facultyList.per_page,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    }, [selectedSchoolYear, searchQuery, currentPage, facultyList.per_page, facultyList.last_page, navigateToReports]);

    // Handle page size change
    const handlePageSizeChange = useCallback((e) => {
        const newSize = parseInt(e.target.value);
        
        navigateToReports({
            term: selectedSchoolYear,
            faculty_page: 1,
            faculty_search: searchQuery,
            faculty_per_page: newSize,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    }, [selectedSchoolYear, searchQuery, navigateToReports]);

    // Handle go to page
    const handleGotoPage = useCallback((e) => {
        e.preventDefault();
        const pageNum = parseInt(gotoPage);
        if (!isNaN(pageNum) && pageNum >= 1 && pageNum <= facultyList.last_page) {
            handlePageChange(pageNum);
            setGotoPage('');
        }
    }, [gotoPage, facultyList.last_page, handlePageChange]);

    // Memoized Pagination component
    const Pagination = useMemo(() => {
        if (facultyList.total <= facultyList.per_page) return null;
        
        const maxVisible = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(facultyList.last_page, startPage + maxVisible - 1);
        
        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }
        
        const pages = [];
        for (let i = startPage; i <= endPage; i++) {
            pages.push(i);
        }

        const pageRanges = [];
        const total = facultyList.last_page;
        const step = Math.max(1, Math.floor(total / 8));
        
        for (let i = 1; i <= total; i += step) {
            const end = Math.min(i + step - 1, total);
            pageRanges.push({
                start: i,
                end: end,
                label: i === end ? `${i}` : `${i}-${end}`
            });
        }
        
        return (
            <div className="flex flex-col gap-4 border-t border-slate-200 bg-white px-4 py-3 sm:px-6 mt-6">
                {/* Mobile view */}
                <div className="flex flex-1 justify-between sm:hidden">
                    <button
                        onClick={() => handlePageChange(currentPage - 1)}
                        disabled={currentPage === 1 || isLoading}
                        className="relative inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Previous
                    </button>
                    <span className="text-sm text-slate-700 py-2">
                        Page {currentPage} of {facultyList.last_page}
                    </span>
                    <button
                        onClick={() => handlePageChange(currentPage + 1)}
                        disabled={currentPage === facultyList.last_page || isLoading}
                        className="relative inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Next
                    </button>
                </div>
                
                {/* Desktop view */}
                <div className="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between flex-wrap gap-4">
                    <div className="flex items-center gap-4">
                        <div>
                            <p className="text-sm text-slate-700">
                                Showing <span className="font-medium">{(currentPage - 1) * facultyList.per_page + 1}</span> to{' '}
                                <span className="font-medium">
                                    {Math.min(currentPage * facultyList.per_page, facultyList.total)}
                                </span>{' '}
                                of <span className="font-medium">{facultyList.total}</span> results
                            </p>
                        </div>
                        
                        <div className="flex items-center gap-2">
                            <label className="text-sm text-slate-600">Show:</label>
                            <select
                                value={facultyList.per_page}
                                onChange={handlePageSizeChange}
                                disabled={isLoading}
                                className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="12">12</option>
                                <option value="24">24</option>
                                <option value="36">36</option>
                                <option value="48">48</option>
                            </select>
                        </div>
                    </div>
                    
                    <div className="flex items-center gap-3">
                        {facultyList.last_page > 15 && (
                            <div className="flex items-center gap-2">
                                <label className="text-sm text-slate-600">Jump to:</label>
                                <select
                                    onChange={(e) => handlePageChange(parseInt(e.target.value))}
                                    value={currentPage}
                                    disabled={isLoading}
                                    className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    {pageRanges.map((range) => (
                                        <option key={range.start} value={range.start}>
                                            {range.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        
                        <form onSubmit={handleGotoPage} className="flex items-center gap-2">
                            <label className="text-sm text-slate-600">Go to:</label>
                            <input
                                type="number"
                                min="1"
                                max={facultyList.last_page}
                                value={gotoPage}
                                onChange={(e) => setGotoPage(e.target.value)}
                                disabled={isLoading}
                                className="w-20 rounded-md border border-slate-300 bg-white px-2 py-1 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Page"
                            />
                            <button
                                type="submit"
                                disabled={isLoading || !gotoPage}
                                className="rounded-md bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700 hover:bg-slate-200 disabled:opacity-50"
                            >
                                Go
                            </button>
                        </form>
                        
                        <nav className="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                            <button
                                onClick={() => handlePageChange(1)}
                                disabled={currentPage === 1 || isLoading}
                                className="relative inline-flex items-center rounded-l-md px-2 py-2 text-slate-400 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="First page"
                            >
                                <span className="sr-only">First</span>
                                <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M15.79 14.77a.75.75 0 01-1.06.02l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 111.04 1.08L11.832 10l3.938 3.71a.75.75 0 01.02 1.06zm-6 0a.75.75 0 01-1.06.02l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 111.04 1.08L5.832 10l3.938 3.71a.75.75 0 01.02 1.06z" clipRule="evenodd" />
                                </svg>
                            </button>
                            
                            <button
                                onClick={() => handlePageChange(currentPage - 1)}
                                disabled={currentPage === 1 || isLoading}
                                className="relative inline-flex items-center px-2 py-2 text-slate-400 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Previous page"
                            >
                                <span className="sr-only">Previous</span>
                                <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clipRule="evenodd" />
                                </svg>
                            </button>
                            
                            {startPage > 1 && (
                                <>
                                    <button
                                        onClick={() => handlePageChange(1)}
                                        disabled={isLoading}
                                        className="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-slate-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20 disabled:opacity-50"
                                    >
                                        1
                                    </button>
                                    {startPage > 2 && (
                                        <span className="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300">
                                            ...
                                        </span>
                                    )}
                                </>
                            )}
                            
                            {pages.map(page => (
                                <button
                                    key={page}
                                    onClick={() => handlePageChange(page)}
                                    disabled={isLoading}
                                    className={`relative inline-flex items-center px-4 py-2 text-sm font-semibold ${
                                        page === currentPage
                                            ? 'z-10 bg-blue-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600'
                                            : 'text-slate-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20'
                                    }`}
                                >
                                    {page}
                                </button>
                            ))}
                            
                            {endPage < facultyList.last_page && (
                                <>
                                    {endPage < facultyList.last_page - 1 && (
                                        <span className="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300">
                                            ...
                                        </span>
                                    )}
                                    <button
                                        onClick={() => handlePageChange(facultyList.last_page)}
                                        disabled={isLoading}
                                        className="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-slate-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20 disabled:opacity-50"
                                    >
                                        {facultyList.last_page}
                                    </button>
                                </>
                            )}
                            
                            <button
                                onClick={() => handlePageChange(currentPage + 1)}
                                disabled={currentPage === facultyList.last_page || isLoading}
                                className="relative inline-flex items-center px-2 py-2 text-slate-400 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Next page"
                            >
                                <span className="sr-only">Next</span>
                                <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clipRule="evenodd" />
                                </svg>
                            </button>
                            
                            <button
                                onClick={() => handlePageChange(facultyList.last_page)}
                                disabled={currentPage === facultyList.last_page || isLoading}
                                className="relative inline-flex items-center rounded-r-md px-2 py-2 text-slate-400 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Last page"
                            >
                                <span className="sr-only">Last</span>
                                <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M4.21 14.77a.75.75 0 001.06.02l4.5-4.25a.75.75 0 000-1.08l-4.5-4.25a.75.75 0 00-1.04 1.08l3.938 3.71-3.938 3.71a.75.75 0 00-.02 1.06zm6 0a.75.75 0 001.06.02l4.5-4.25a.75.75 0 000-1.08l-4.5-4.25a.75.75 0 00-1.04 1.08l3.938 3.71-3.938 3.71a.75.75 0 00-.02 1.06z" clipRule="evenodd" />
                                </svg>
                            </button>
                        </nav>
                    </div>
                </div>
            </div>
        );
    }, [facultyList.total, facultyList.per_page, facultyList.last_page, currentPage, isLoading, handlePageChange, handlePageSizeChange, handleGotoPage, gotoPage]);

    const selectedSchoolYearLabel = useMemo(() => {
        const found = schoolYears.find((option) => String(option.value) === String(selectedSchoolYear));
        return found?.label ?? 'All School Years';
    }, [schoolYears, selectedSchoolYear]);

    const reportSummaryCards = useMemo(() => {
        return reportSummary.map((summary, index) => (
            <div key={index} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <p className="text-xs font-medium text-slate-500">{summary.label}</p>
                <p className="mt-2 text-2xl font-semibold text-slate-900">{summary.value}</p>
                <p className="mt-1 text-xs text-slate-500">{summary.helper}</p>
            </div>
        ));
    }, [reportSummary]);

    const recentReportsItems = useMemo(() => {
        if (!recentReports || recentReports.length === 0) {
            return (
                <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                    No evaluation records found.
                </div>
            );
        }

        return recentReports.map((report, index) => (
            <div key={index} className="group rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-400 hover:shadow-md">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-start gap-4 min-w-0 flex-1">
                        <div className="flex-shrink-0">
                            <div className="inline-flex items-center justify-center rounded-lg bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">
                                {report.course_code || 'N/A'}
                            </div>
                        </div>
                        <div className="min-w-0 flex-1 pt-1">
                            <h3 className="text-base font-semibold text-slate-900 line-clamp-2">
                                {report.course_title}
                            </h3>
                            <p className="text-sm text-slate-600 mt-1">
                                Instructor: <span className="font-medium">{report.instructor}</span>
                            </p>
                            <div className="mt-2 flex flex-wrap items-center gap-3">
                                <span className="text-xs text-slate-500">
                                    Submitted: <span className="font-medium">{report.submitted_at}</span>
                                </span>
                                <span className="text-xs text-slate-500">
                                    Rating: <span className="font-semibold text-blue-600">{report.rating_percentage}%</span>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-col items-end gap-2 flex-shrink-0">
                        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap ${
                            report.final_grade !== null
                                ? 'bg-emerald-100 text-emerald-700'
                                : 'bg-amber-100 text-amber-700'
                        }`}>
                            {report.final_grade !== null ? 'GRADED' : 'PENDING'}
                        </span>
                        {report.final_grade !== null && (
                            <div className="text-right">
                                <p className="text-xs text-slate-600">Final Grade</p>
                                <p className="text-base font-bold text-slate-900">{report.final_grade}</p>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        ));
    }, [recentReports]);

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
            <main className="flex-1 p-6">
                {!isFaculty && (
                    <div className="mb-6">
                        <h1 className="text-2xl font-semibold tracking-tight">Evaluation</h1>
                        <p className="mt-1 text-sm text-slate-500">Faculty evaluation list.</p>

                        <div className="mt-4 flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                            <div className="w-full xl:flex-1">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <label className="block">
                                        <span className="sr-only">School Year</span>
                                        <select
                                            value={selectedSchoolYear ? String(selectedSchoolYear) : ''}
                                            onChange={handleSchoolYearChange}
                                            className="w-full cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                        >
                                            {schoolYears.length > 0 ? (
                                                schoolYears.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))
                                            ) : (
                                                <option value="">No school years available</option>
                                            )}
                                        </select>
                                    </label>

                                    <div className="relative">
                                        <input
                                            type="text"
                                            placeholder="Search by name or ID..."
                                            value={searchQuery}
                                            onChange={(e) => setSearchQuery(e.target.value)}
                                            className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 pl-9 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                        />
                                        <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </div>
                                </div>
                            </div>

                            <div className="flex shrink-0 xl:pt-1">
                                <span className="inline-flex items-center justify-center rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-slate-700">
                                    {selectedSchoolYearLabel}
                                </span>
                            </div>
                        </div>
                    </div>
                )}

                {isFaculty ? (
                    <section>
                        <div className="mb-6">
                            <h2 className="text-lg font-semibold text-slate-900">Recent Evaluations</h2>
                            <p className="mt-1 text-sm text-slate-500">Your submitted evaluation submissions.</p>
                        </div>
                        <div className="space-y-3">
                            {recentReportsItems}
                        </div>
                    </section>
                ) : (
                    <section>
                        <div className="mb-6">
                            <h2 className="text-base font-semibold text-slate-900">All Faculty</h2>
                            <p className="mt-1 text-sm text-slate-500">Ratings for: <span className="font-medium">{selectedSchoolYearLabel}</span></p>
                        </div>
                        
                        <div className="relative">
                            {isLoading && (
                                <div className="absolute inset-0 bg-white/50 backdrop-blur-sm z-10 flex items-center justify-center rounded-lg">
                                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                                </div>
                            )}
                            
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                {facultyList.data && facultyList.data.map((faculty) => (
                                    <a
                                        key={faculty.employee_id_no}
                                        href={faculty.detail_url}
                                        onClick={(e) => {
                                            e.preventDefault();
                                            router.visit(faculty.detail_url, {
                                                preserveState: false,
                                                replace: true,
                                            });
                                        }}
                                        className="group block rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-500 hover:shadow-md hover:bg-slate-50"
                                    >
                                        <div className="flex items-start gap-3 mb-4">
                                            <div className="flex-shrink-0">
                                                <div className="h-10 w-10 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-xs font-bold shadow-sm">
                                                    {faculty.initials}
                                                </div>
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <h3 className="text-sm font-semibold text-slate-900 group-hover:text-blue-600 transition line-clamp-2">
                                                    {faculty.instructor}
                                                </h3>
                                                <p className="text-xs text-slate-500 mt-0.5">
                                                    ID: <span className="font-medium">{faculty.employee_id_no}</span>
                                                </p>
                                            </div>
                                        </div>
                                        <div className="mb-3 h-px bg-gradient-to-r from-slate-200 to-transparent"></div>
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs font-medium text-slate-600">Overall SET</span>
                                                <span className={`text-xs font-semibold ${
                                                    faculty.overall_set_rating !== null
                                                        ? 'text-blue-600'
                                                        : 'text-slate-400'
                                                }`}>
                                                    {faculty.overall_set_rating !== null ? `${faculty.overall_set_rating}%` : '—'}
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs font-medium text-slate-600">Overall SEF</span>
                                                <span className={`text-xs font-semibold ${
                                                    faculty.overall_sef_rating !== null
                                                        ? 'text-emerald-600'
                                                        : 'text-slate-400'
                                                }`}>
                                                    {faculty.overall_sef_rating !== null ? `${faculty.overall_sef_rating}%` : '—'}
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs font-medium text-slate-600">Subjects</span>
                                                <span className="text-xs font-semibold text-slate-700">{faculty.subjects_count}</span>
                                            </div>
                                        </div>
                                    </a>
                                ))}
                            </div>
                            
                            {Pagination}
                            
                            {(!facultyList.data || facultyList.data.length === 0) && !isLoading && (
                                <div className="text-center py-12">
                                    <p className="text-slate-500">No faculty found matching your search.</p>
                                </div>
                            )}
                        </div>
                    </section>
                )}
            </main>
        </AppLayout>
    );
}