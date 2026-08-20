import React, { useEffect, useState, useCallback, useRef } from 'react';
import AppLayout from '../Layouts/AppLayout';
import SefEvaluationModal from '../modals/SefEvaluationModal';
import EvaluationResultModal from '../modals/EvaluationResultModal';
import FEDAFormModal from '../modals/FEDAFormModal';
import {router, Link} from '@inertiajs/react';
import FEDAResultModal from '../modals/FEDAResultModal';
import ConfirmDeleteModal from '../modals/ConfirmDeleteModal';
import { isAdminRole } from '../utils/role';
import { toast } from 'react-toastify';

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
    statusOptions = [],
    units = [],
    subjects = [],
    evaluations = [],
    evaluatedInstructors = [],
    selectedSchoolYear = '',
    selectedTerm = 'all',
    selectedUnit = '',
    selectedSubject = '',
    searchQuery = '',
    currentPage = 1,
    totalEvaluations = 0,
    lastPage = 1,
    perPage = 10,
    showUnitFilter = false,
    isEvaluationClosed = false,
    evaluationStatusLabel = 'Open for Evaluation',
    hasPendingEvaluations = false,
    reportsUrl = '/reports',
    infoMessage = null,
}) {
    const isAdmin = user?.isAdmin === true || isAdminRole(user?.role);
    const [isEvaluationOpen, setIsEvaluationOpen] = useState(false);
    const [selectedEvaluation, setSelectedEvaluation] = useState(null);
    const [isResultOpen, setIsResultOpen] = useState(false);
    const [selectedResult, setSelectedResult] = useState(null);
    const [isFedaModalOpen, setIsFedaModalOpen] = useState(false);
    const [selectedFedaFaculty, setSelectedFedaFaculty] = useState(null);
    
    // Local state for all filter values - initialized with props
    const [localSchoolYear, setLocalSchoolYear] = useState(selectedSchoolYear || '');
    const [localTerm, setLocalTerm] = useState(selectedTerm || 'all');
    const [localUnit, setLocalUnit] = useState(selectedUnit || '');
    const [localSearchQuery, setLocalSearchQuery] = useState(searchQuery || '');
    const [localCurrentPage, setLocalCurrentPage] = useState(currentPage || 1);
    const [isLoading, setIsLoading] = useState(false);
    const [openingAction, setOpeningAction] = useState(null);

    const searchTimeoutRef = useRef(null);
    const filterTimeoutRef = useRef(null);
    const isFirstRender = useRef(true);

    // Update local state when props change (from server/URL)
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            setIsLoading(false);
            return;
        }
        
        setLocalSchoolYear(selectedSchoolYear || '');
        setLocalTerm(selectedTerm || 'all');
        setLocalUnit(selectedUnit || '');
        setLocalSearchQuery(searchQuery || '');
        setLocalCurrentPage(currentPage || 1);
        setIsLoading(false);
    }, [selectedSchoolYear, selectedTerm, selectedUnit, searchQuery, currentPage]);

    // Cleanup timeouts on unmount
    useEffect(() => {
        return () => {
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
            }
            if (filterTimeoutRef.current) {
                clearTimeout(filterTimeoutRef.current);
            }
        };
    }, []);

    const startLoading = useCallback(() => {
        setIsLoading(true);
    }, []);

    const stopLoading = useCallback(() => {
        setIsLoading(false);
    }, []);

    // Debounced search
    const debouncedSearch = useCallback((value) => {
        if (searchTimeoutRef.current) {
            clearTimeout(searchTimeoutRef.current);
        }
        
        startLoading();
        
        searchTimeoutRef.current = setTimeout(() => {
            const params = new URLSearchParams();
            
            // Preserve all filter values
            if (localSchoolYear) params.set('term', localSchoolYear);
            if (localTerm && localTerm !== 'all') params.set('status', localTerm);
            if (localUnit) params.set('unit', localUnit);
            if (value) params.set('search', value);
            params.set('page', '1');
            
            router.get(window.location.pathname, Object.fromEntries(params), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => stopLoading(),
                onError: () => stopLoading(),
            });
        }, 500);
    }, [localSchoolYear, localTerm, localUnit, startLoading, stopLoading]);

    // Handle search input change
    const handleSearchChange = (e) => {
        const value = e.target.value;
        setLocalSearchQuery(value);
        debouncedSearch(value);
    };

    // Handle filter changes with debouncing
    const handleFilterChange = (e) => {
        const { name, value } = e.target;
        
        // Update local state immediately for responsive UI
        if (name === 'term') {
            setLocalSchoolYear(value);
        } else if (name === 'status') {
            setLocalTerm(value);
        } else if (name === 'unit') {
            setLocalUnit(value);
        }
        
        // Clear any pending filter timeout
        if (filterTimeoutRef.current) {
            clearTimeout(filterTimeoutRef.current);
        }
        
        startLoading();
        
        filterTimeoutRef.current = setTimeout(() => {
            const params = new URLSearchParams();
            
            // Get current values (use the new value for the changed filter)
            const currentSchoolYear = name === 'term' ? value : localSchoolYear;
            const currentTerm = name === 'status' ? value : localTerm;
            const currentUnit = name === 'unit' ? value : localUnit;
            const currentSearch = localSearchQuery;
            
            if (currentSchoolYear) params.set('term', currentSchoolYear);
            if (currentTerm && currentTerm !== 'all') params.set('status', currentTerm);
            if (currentUnit) params.set('unit', currentUnit);
            if (currentSearch) params.set('search', currentSearch);
            params.set('page', '1');
            
            router.get(window.location.pathname, Object.fromEntries(params), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => stopLoading(),
                onError: () => stopLoading(),
            });
        }, 300);
    };

    // Handle page change
    const handlePageChange = (page) => {
        if (page < 1 || page > lastPage) return;
        
        setLocalCurrentPage(page);
        startLoading();
        
        const params = new URLSearchParams();
        
        if (localSchoolYear) params.set('term', localSchoolYear);
        if (localTerm && localTerm !== 'all') params.set('status', localTerm);
        if (localUnit) params.set('unit', localUnit);
        if (localSearchQuery) params.set('search', localSearchQuery);
        params.set('page', page.toString());
        
        router.get(window.location.pathname, Object.fromEntries(params), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => stopLoading(),
            onError: () => stopLoading(),
        });
    };

    // Handle clear search
    const handleClearSearch = useCallback(() => {
        setLocalSearchQuery('');
        startLoading();
        
        const params = new URLSearchParams();
        
        if (localSchoolYear) params.set('term', localSchoolYear);
        if (localTerm && localTerm !== 'all') params.set('status', localTerm);
        if (localUnit) params.set('unit', localUnit);
        params.set('page', '1');
        
        router.get(window.location.pathname, Object.fromEntries(params), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => stopLoading(),
            onError: () => stopLoading(),
        });
    }, [localSchoolYear, localTerm, localUnit, startLoading, stopLoading]);

    const openEvaluationModal = (item) => {
        if (item.evaluated || isEvaluationClosed) return;
        
        // 1. Show the loading spinner on this specific button
        setOpeningAction(`sef-${item.id_no}`);
        
        // 2. Add a tiny delay so the UI updates before the heavy modal blocks the thread
        setTimeout(() => {
            setSelectedEvaluation({
                ...item,
                term_id: item.school_year_id || parseInt(localSchoolYear, 10),
                school_year_id: item.school_year_id || parseInt(localSchoolYear, 10),
            });
            setIsEvaluationOpen(true);
            setOpeningAction(null); // Remove loading state
        }, 50);
    };

    const closeEvaluationModal = () => {
        setIsEvaluationOpen(false);
        setSelectedEvaluation(null);
    };

    const openResultModal = (item) => {
        if (!item?.evaluation_result) return;
        setSelectedResult(item.evaluation_result);
        setIsResultOpen(true);
    };

    const closeResultModal = () => {
        setSelectedResult(null);
        setIsResultOpen(false);
    };

    const openEditModal = (item) => {
        setSelectedEvaluation(item);
        setIsEvaluationOpen(true);
    };

    const openFedaModal = (item, isView = true) => {
        setOpeningAction(`feda-${item.id_no}`);
        
        setTimeout(() => {
            setSelectedFedaFaculty({
                ...item,
                name: item.instructor,
                id_no: item.id_no || item.instructor_id_no,
                is_view_mode: isView,
            });
            setIsFedaModalOpen(true);
            setOpeningAction(null);
        }, 50);
    };

    const [deleteConfig, setDeleteConfig] = useState({
        isOpen: false,
        type: '', // 'sef' or 'feda'
        item: null,
        title: '',
        message: ''
    });
    const [isDeleting, setIsDeleting] = useState(false);     

    const openDeleteSefModal = (item) => {
        setDeleteConfig({
            isOpen: true,
            type: 'sef',
            item: item,
            title: 'Remove SEF Evaluation',
            message: `Are you sure you want to remove the SEF evaluation for ${item.instructor}? This will permanently delete the scores and comments.`
        });
    };

    const openDeleteFedaModal = (item) => {
        setDeleteConfig({
            isOpen: true,
            type: 'feda',
            item: item,
            title: 'Remove FEDA Form',
            message: `Are you sure you want to remove the FEDA form for ${item.instructor}? This will permanently delete their development plan.`
        });
    };

    const closeDeleteModal = () => {
        if (isDeleting) return;
        setDeleteConfig({ ...deleteConfig, isOpen: false });
    };

    const executeDelete = async () => {
        setIsDeleting(true);
        const { type, item } = deleteConfig;

        try {
            if (type === 'sef') {
                router.delete(`/evaluations/${item.evaluation_result.id}`, {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        toast.success('SEF Evaluation removed successfully');
                        closeDeleteModal();
                    },
                    onFinish: () => setIsDeleting(false)
                });
            } else if (type === 'feda') {
                const idNo = item.id_no || item.instructor_id_no;
                const termId = item.school_year_id || localSchoolYear;
                
                router.delete(`/feda/${idNo}/${termId}`, {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        toast.success('FEDA form removed successfully');
                        closeDeleteModal();
                    },
                    onFinish: () => setIsDeleting(false)
                });
            }
        } catch (error) {
            toast.error('An error occurred during deletion.');
            setIsDeleting(false);
        }
    };

    const closeFedaModal = () => {
        setSelectedFedaFaculty(null);
        setIsFedaModalOpen(false);
    };

    const handleEvaluationSubmitted = () => {
        router.reload({ preserveScroll: true, preserveState: true });
    };

    const hasEvaluationItems = evaluations && evaluations.length > 0;

    // Pagination component
    const Pagination = () => {
        if (lastPage <= 1) return null;

        const maxVisible = 5;
        let startPage = Math.max(1, localCurrentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(lastPage, startPage + maxVisible - 1);
        
        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        return (
            <div className="flex items-center justify-between border-t border-slate-200 bg-white px-4 py-3 sm:px-6 mt-6">
                <div className="flex flex-1 justify-between sm:hidden">
                    <button
                        onClick={() => handlePageChange(localCurrentPage - 1)}
                        disabled={localCurrentPage === 1 || isLoading}
                        className="relative inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Previous
                    </button>
                    <span className="text-sm text-slate-700 py-2">
                        Page {localCurrentPage} of {lastPage}
                    </span>
                    <button
                        onClick={() => handlePageChange(localCurrentPage + 1)}
                        disabled={localCurrentPage === lastPage || isLoading}
                        className="relative inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Next
                    </button>
                </div>
                <div className="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                    <div>
                        <p className="text-sm text-slate-700">
                            Showing <span className="font-medium">{(localCurrentPage - 1) * perPage + 1}</span> to{' '}
                            <span className="font-medium">{Math.min(localCurrentPage * perPage, totalEvaluations)}</span> of{' '}
                            <span className="font-medium">{totalEvaluations}</span> results
                        </p>
                    </div>
                    <nav className="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                        <button
                            onClick={() => handlePageChange(1)}
                            disabled={localCurrentPage === 1 || isLoading}
                            className="relative inline-flex items-center rounded-l-md px-2 py-2 text-slate-400 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                            title="First page"
                        >
                            <span className="sr-only">First</span>
                            <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fillRule="evenodd" d="M15.79 14.77a.75.75 0 01-1.06.02l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 111.04 1.08L11.832 10l3.938 3.71a.75.75 0 01.02 1.06zm-6 0a.75.75 0 01-1.06.02l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 111.04 1.08L5.832 10l3.938 3.71a.75.75 0 01.02 1.06z" clipRule="evenodd" />
                            </svg>
                        </button>
                        <button
                            onClick={() => handlePageChange(localCurrentPage - 1)}
                            disabled={localCurrentPage === 1 || isLoading}
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
                        
                        {Array.from({ length: endPage - startPage + 1 }, (_, i) => startPage + i).map(page => (
                            <button
                                key={page}
                                onClick={() => handlePageChange(page)}
                                disabled={isLoading}
                                className={`relative inline-flex items-center px-4 py-2 text-sm font-semibold ${
                                    page === localCurrentPage
                                        ? 'z-10 bg-blue-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600'
                                        : 'text-slate-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20'
                                }`}
                            >
                                {page}
                            </button>
                        ))}
                        
                        {endPage < lastPage && (
                            <>
                                {endPage < lastPage - 1 && (
                                    <span className="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300">
                                        ...
                                    </span>
                                )}
                                <button
                                    onClick={() => handlePageChange(lastPage)}
                                    disabled={isLoading}
                                    className="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-slate-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20 disabled:opacity-50"
                                >
                                    {lastPage}
                                </button>
                            </>
                        )}
                        
                        <button
                            onClick={() => handlePageChange(localCurrentPage + 1)}
                            disabled={localCurrentPage === lastPage || isLoading}
                            className="relative inline-flex items-center px-2 py-2 text-slate-400 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
                            title="Next page"
                        >
                            <span className="sr-only">Next</span>
                            <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fillRule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clipRule="evenodd" />
                            </svg>
                        </button>
                        <button
                            onClick={() => handlePageChange(lastPage)}
                            disabled={localCurrentPage === lastPage || isLoading}
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
        );
    };

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
            activePage="evaluation"
            logoutUrl={logoutUrl}
            csrfToken={csrfToken}
            hasPendingEvaluations={hasPendingEvaluations}
            layoutClassName="min-h-screen flex"
        >
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
                            {isAdmin && (
                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                                    Admin view
                                </span>
                            )}
                        </div>
                        <p className="mt-2 max-w-3xl text-sm text-slate-500">
                            {isAdmin
                                ? 'Administrator accounts can view the page layout, but evaluation actions remain governed by Unit Head access.'
                                : 'Use this page to review faculty entries and submit evaluations during the active schedule.'}
                        </p>
                    </div>

                    {infoMessage ? (
                        <div className="mt-10 flex justify-center items-center">
                            <div className="bg-amber-50 border border-amber-200 rounded-lg p-8 max-w-2xl text-center shadow-sm">
                                <h2 className="text-xl font-semibold text-amber-800 mb-2">Under Development</h2>
                                <p className="text-amber-700">{infoMessage}</p>
                                <div className="mt-4 text-xs text-amber-600">
                                    This page will be available for Deans in a future release.
                                </div>
                            </div>
                        </div>
                    ) : (
                        <>
                            <div className="mt-6 flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                                <div className="w-full xl:flex-1">
                                    <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
                                        {/* School Year Filter */}
                                        <label className="block">
                                            <span className="sr-only">School Year</span>
                                            <select
                                                name="term"
                                                value={localSchoolYear}
                                                onChange={handleFilterChange}
                                                className="w-full cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 disabled:opacity-50"
                                            >
                                                {schoolYears.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))}
                                            </select>
                                        </label>

                                        {/* Status Filter */}
                                        <label className="block">
                                            <span className="sr-only">Status</span>
                                            <select
                                                name="status"
                                                value={localTerm}
                                                onChange={handleFilterChange}
                                                className="w-full cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 disabled:opacity-50"
                                            >
                                                {statusOptions.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))}
                                            </select>
                                        </label>

                                        {/* Unit Filter - Only shown for Admin, Associate Dean, and Dean */}
                                        {showUnitFilter && (
                                            <label className="block">
                                                <span className="sr-only">Unit</span>
                                                <select
                                                    name="unit"
                                                    value={localUnit}
                                                    onChange={handleFilterChange}
                                                    className="w-full cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 disabled:opacity-50"
                                                >
                                                    {units.map((option) => (
                                                        <option key={option.value} value={option.value}>{option.label}</option>
                                                    ))}
                                                </select>
                                            </label>
                                        )}

                                        {/* Search Bar */}
                                        <div className={`relative ${showUnitFilter ? '' : 'md:col-span-2'}`}>
                                            <input
                                                type="text"
                                                placeholder="Search by name or ID..."
                                                value={localSearchQuery}
                                                onChange={handleSearchChange}
                                                className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 pl-9 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 disabled:opacity-50 disabled:bg-slate-50"
                                            />
                                            {isLoading ? (
                                                <div className="absolute right-3 top-1/2 -translate-y-1/2">
                                                    <svg className="animate-spin h-4 w-4 text-blue-500" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                </div>
                                            ) : (
                                                <>
                                                    <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                                    </svg>
                                                    {localSearchQuery && (
                                                        <button
                                                            onClick={handleClearSearch}
                                                            className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                                                        >
                                                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                            </svg>
                                                        </button>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex shrink-0 xl:pt-1">
                                    <span className={`inline-flex items-center justify-center rounded-md px-3 py-1.5 text-xs font-semibold whitespace-nowrap ${
                                        isEvaluationClosed ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-700'
                                    }`}>
                                        {evaluationStatusLabel}
                                    </span>
                                </div>
                            </div>

                            {/* Loading Overlay */}
                            {isLoading && (
                                <div className="flex justify-center items-center min-h-[200px] sm:min-h-[250px] md:min-h-[300px] lg:min-h-[350px] py-8 sm:py-12 md:py-16">
                                    <div className="flex flex-col items-center gap-3">
                                        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                                        <span className="text-sm font-medium text-blue-600">Loading...</span>
                                    </div>
                                </div>
                            )}

                            {!isLoading && hasEvaluationItems ? (
                                <>
                                    <div className="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4">
                                        {evaluations.map((item, idx) => (
                                            <div key={idx} className="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
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
                                                                <div className="truncate">
                                                                    <span className="font-semibold text-slate-700">Academic Rank:</span> {item.academic_rank || 'N/A'}
                                                                </div>
                                                                <div className="truncate">
                                                                    <span className="font-semibold text-slate-700">College:</span> {item.college || 'N/A'}
                                                                </div>
                                                                <div className="truncate">
                                                                    <span className="font-semibold text-slate-700">Program:</span> {item.program || 'N/A'}
                                                                </div>
                                                                <div className="truncate">
                                                                    <span className="font-semibold text-slate-700">Semester:</span> {item.term || 'N/A'}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div className="shrink-0">
                                                        {item.evaluated ? (
                                                            <span className="inline-flex items-center rounded-md bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-700">
                                                                SEF Evaluated
                                                            </span>
                                                        ) : isEvaluationClosed ? (
                                                            <span className="inline-flex items-center rounded-md bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700">
                                                                Closed SEF Evaluation
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center rounded-md bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700">
                                                                For SEF Evaluation
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>

                                                <div className="mt-4 flex flex-wrap items-center gap-4 bg-slate-50 p-2.5 rounded-lg border border-slate-100">
    
                                                    {/* --- SEF ACTIONS --- */}
                                                    <div className="flex items-center gap-2 border-r border-slate-200 pr-4">
                                                        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">SEF:</span>
                                                        {item.evaluated ? (
                                                            <div className="flex items-center gap-1.5">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => openResultModal(item)}
                                                                    title="View SEF"
                                                                    className="p-1.5 text-emerald-600 hover:bg-emerald-100 hover:text-emerald-700 rounded-md transition-colors"
                                                                >
                                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                                    </svg>
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => openEditModal(item)}
                                                                    title="Edit SEF"
                                                                    className="p-1.5 text-blue-600 hover:bg-blue-100 hover:text-blue-700 rounded-md transition-colors"
                                                                >
                                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                                                    </svg>
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => openDeleteSefModal(item)}
                                                                    title="Remove SEF"
                                                                    className="p-1.5 text-red-500 hover:bg-red-100 hover:text-red-700 rounded-md transition-colors"
                                                                >
                                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                                    </svg>
                                                                </button>
                                                            </div>
                                                        ) : isEvaluationClosed ? (
                                                            <span className="text-xs font-medium text-slate-400 italic">Closed</span>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                onClick={() => openEvaluationModal(item)}
                                                                title="Evaluate SEF"
                                                                disabled={openingAction === `sef-${item.id_no}`}
                                                                className="flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold text-blue-600 bg-blue-100/50 hover:bg-blue-100 rounded-md transition-colors disabled:opacity-50 disabled:cursor-wait"
                                                            >
                                                                {openingAction === `sef-${item.id_no}` ? (
                                                                    <svg className="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                                    </svg>
                                                                ) : (
                                                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                                    </svg>
                                                                )}
                                                                {openingAction === `sef-${item.id_no}` ? 'Loading...' : 'Evaluate'}
                                                            </button>
                                                        )}
                                                    </div>
                                                    
                                                    {/* --- FEDA ACTIONS --- */}
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">FEDA:</span>
                                                        {(() => {
                                                            const isFedaSubmitted = item.feda_submitted || false;
                                                            
                                                            return isFedaSubmitted ? (
                                                                <div className="flex items-center gap-1.5">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => openFedaModal(item, true)} // View Mode
                                                                        title="View FEDA"
                                                                        className="p-1.5 text-emerald-600 hover:bg-emerald-100 hover:text-emerald-700 rounded-md transition-colors"
                                                                    >
                                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                                        </svg>
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => openFedaModal(item, false)} // Edit Mode
                                                                        title="Edit FEDA"
                                                                        className="p-1.5 text-blue-600 hover:bg-blue-100 hover:text-blue-700 rounded-md transition-colors"
                                                                    >
                                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                                                        </svg>
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => openDeleteFedaModal(item)}
                                                                        title="Remove FEDA"
                                                                        className="p-1.5 text-red-500 hover:bg-red-100 hover:text-red-700 rounded-md transition-colors"
                                                                    >
                                                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                                        </svg>
                                                                    </button>
                                                                </div>
                                                            ) : (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => openFedaModal(item, false)} // Create Mode
                                                                    title="Create FEDA"
                                                                    className="flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold text-blue-600 bg-blue-100/50 hover:bg-blue-100 rounded-md transition-colors"
                                                                >
                                                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                                    </svg>
                                                                    Create
                                                                </button>
                                                            );
                                                        })()}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    
                                    <Pagination />
                                </>
                            ) : !isLoading && !hasEvaluationItems ? (
                                <div className="mt-10 flex justify-center items-center">
                                    <div className="bg-slate-50 border border-slate-200 rounded-lg p-8 max-w-2xl text-center shadow-sm">
                                        <p className="text-sm text-slate-500">
                                            No faculty members available for evaluation in the selected term.
                                        </p>
                                    </div>
                                </div>
                            ) : null}
                        </>
                    )}
                </div>

                <EvaluationResultModal
                    isOpen={isResultOpen}
                    onClose={closeResultModal}
                    result={selectedResult}
                />

                <SefEvaluationModal
                    isOpen={isEvaluationOpen}
                    evaluation={selectedEvaluation}
                    submitUrl={evaluationStoreUrl}
                    csrfToken={csrfToken}
                    onSubmitted={handleEvaluationSubmitted}
                    onClose={closeEvaluationModal}
                />

                {selectedFedaFaculty?.is_view_mode ? (
                    <FEDAResultModal
                        isOpen={isFedaModalOpen}
                        onClose={closeFedaModal}
                        faculty={selectedFedaFaculty}
                        termId={localSchoolYear}
                        termLabel={selectedFedaFaculty?.term || schoolYears.find((option) => option.value === localSchoolYear)?.label || localSchoolYear}
                    />
                ) : (
                    <FEDAFormModal
                        isOpen={isFedaModalOpen}
                        onClose={closeFedaModal}
                        onSubmitted={() => router.reload({ preserveScroll: true, preserveState: true })}
                        faculty={selectedFedaFaculty}
                        termId={localSchoolYear}
                        termLabel={selectedFedaFaculty?.term || schoolYears.find((option) => option.value === localSchoolYear)?.label || localSchoolYear}
                        isViewMode={selectedFedaFaculty?.is_view_mode || false}
                    />
                )}

                <ConfirmDeleteModal
                    isOpen={deleteConfig.isOpen}
                    onClose={closeDeleteModal}
                    onConfirm={executeDelete}
                    title={deleteConfig.title}
                    message={deleteConfig.message}
                    isDeleting={isDeleting}
                />
            </main>
        </AppLayout>
    );
}