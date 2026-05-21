import React, { useState, useEffect } from 'react';
import axios from 'axios';
import AppLayout from '../Layouts/AppLayout';
import { router } from '@inertiajs/react';
import FacultyReportPageModal from '../modals/FacultyReportPageModal';

const extractYearLevelFromSectionCode = (sectionCode) => {
    const digits = String(sectionCode ?? '').match(/\d/g) ?? [];

    if (digits.length >= 2) {
        return digits[digits.length - 2];
    }

    if (digits.length === 1) {
        return digits[0];
    }

    return '';
};

const normalizeYearSectionLabel = (value) => {
    const label = String(value ?? '').trim();

    if (!label) {
        return '-';
    }

    const yearSectionMatch = label.match(/^Year\s*([0-9]+)\s*-\s*(.+)$/i);
    if (yearSectionMatch) {
        const sectionCode = yearSectionMatch[2].trim();
        const yearLevel = extractYearLevelFromSectionCode(sectionCode) || yearSectionMatch[1];

        return sectionCode ? `${yearLevel}-${sectionCode}` : yearLevel;
    }

    const compactMatch = label.match(/^([0-9]+)\s*-\s*(.+)$/);
    if (compactMatch) {
        const sectionCode = compactMatch[2].trim();
        const yearLevel = extractYearLevelFromSectionCode(sectionCode) || compactMatch[1];

        return sectionCode ? `${yearLevel}-${sectionCode}` : yearLevel;
    }

    if (/^Year\s*[0-9]+$/i.test(label)) {
        return label.replace(/^Year\s*/i, '').trim();
    }

    const inferredYearLevel = extractYearLevelFromSectionCode(label);
    if (inferredYearLevel) {
        return `${inferredYearLevel}-${label}`;
    }

    return label;
};

export default function FacultyReportPage({
    tablePagination = null,
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
    facultyName = '',
    facultyIdNo = '',
    tableRows = [],
    schoolYears = [],
    selectedSchoolYear = '',
}) {
    const [isSetModalOpen, setIsSetModalOpen] = useState(false);
    const [setBreakdownRows, setSetBreakdownRows] = useState([]);
    const [selectedCourseCode, setSelectedCourseCode] = useState('');
    const [selectedSefBreakdown, setSelectedSefBreakdown] = useState(null);
    const [isModalLoading, setIsModalLoading] = useState(false);
    const [modalError, setModalError] = useState('');
    const [selectedInstructorId, setSelectedInstructorId] = useState(null);

    /**
     * CRITICAL DEBUG POINT: Track current school year filter state
     * Initialized from props (selectedSchoolYear from backend)
     * Used to filter tableRows by matching school_year_id
     */
    

    // Ensure the school year filter is initialized to the backend-provided
    // `selectedSchoolYear` when available; otherwise default to the first
    // available school year so the list is filtered immediately.
    const openSetModal = async (row) => {
        setModalError('');
        setSelectedCourseCode(row?.course_code ?? '');
        setSetBreakdownRows(Array.isArray(row?.set_breakdown) ? row.set_breakdown : []);
        setSelectedSefBreakdown({
            total_score: row?.sef_total_score ?? null,
            rating: row?.sef_rating ?? null,
        });

        // Store instructor ID for modal 2
        setSelectedInstructorId(row?.instructor_id);

        setIsSetModalOpen(true);

        if (!row?.breakdown_url) return;

        try {
            setIsModalLoading(true);

            // Add the current term to the URL
            const url = new URL(row.breakdown_url, window.location.origin);
            if (selectedSchoolYear) {
                url.searchParams.set('term', selectedSchoolYear);
            }
            
            const response = await axios.get(url.toString(), {
                headers: { Accept: 'application/json' },
            });

            const data = response?.data ?? {};

            setSetBreakdownRows(Array.isArray(data.set_breakdown) ? data.set_breakdown : []);
            setSelectedSefBreakdown(data.sef_breakdown || null);
        } catch (error) {
            setModalError('Unable to load breakdown data right now.');
        } finally {
            setIsModalLoading(false);
        }
    };

    const closeSetModal = () => {
        setIsSetModalOpen(false);
        setSetBreakdownRows([]);
        setSelectedCourseCode('');
        setSelectedSefBreakdown(null);
        setIsModalLoading(false);
        setModalError('');
        setSelectedInstructorId(null);
    };

    /**
     * CRITICAL DEBUG POINT: Handle school year dropdown change
     * Updates currentSchoolYear state which triggers re-filter of tableRows
     * Called when user changes school year dropdown
     */
    const handleSchoolYearChange = (event) => {
        const newSchoolYear = event.target.value;

        router.get(route('reports.faculty', {
            instructor: facultyIdNo,
            term: newSchoolYear,
            page: 1,
        }), {}, {
            preserveState: false,
            replace: true,
        });
    };


    const filteredRows = tableRows;
    const selectedSchoolYearLabel = schoolYears.find((option) => String(option.value) === String(selectedSchoolYear))?.label ?? '';
    

    const handlePageChange = (newPage) => {
        router.get(route('reports.faculty', {
            instructor: facultyIdNo,
            term: selectedSchoolYear,
            page: newPage,
        }));
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
            activePage="reports"
            logoutUrl={logoutUrl}
            csrfToken={csrfToken}
            hasPendingEvaluations={hasPendingEvaluations}
        >
            <main className="flex-1 p-6">
                <div className="mb-6">
                    <h1 className="mt-2 text-2xl font-semibold tracking-tight">
                        {facultyName} - Evaluation Details
                    </h1>

                    <p className="mt-1 text-sm text-slate-500">
                        Faculty evaluation summary table.
                    </p>

                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <a
                            href={`${reportsUrl}?tab=evaluation`}
                            onClick={(e) => {
                                e.preventDefault();
                                router.visit(`${reportsUrl}?tab=evaluation`);
                            }}
                            className="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700"
                        >
                            Back
                        </a>
                    </div>
                </div>

                <div className="mt-6 flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                    <div className="w-full xl:flex-1">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 max-w-3xl">
                            <label className="block">
                                <span className="sr-only">School Year</span>

                                {/**
                                  * CRITICAL DEBUG POINT: School year filter dropdown
                                  * Triggers handleSchoolYearChange when value changes
                                  * currentSchoolYear is used in filteredRows logic
                                  */}
                                <select
                                    value={selectedSchoolYear ? String(selectedSchoolYear) : ''}
                                    onChange={handleSchoolYearChange}
                                    className="w-full cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500"
                                >
                                    {/* <option value="">All School Years</option> */}
                                    {schoolYears.length > 0 ? (
                                        schoolYears.map((option) => (
                                            <option key={option.value} value={option.value}>{option.label}</option>
                                        ))
                                    ) : (
                                        <option value="">No school years available</option>
                                    )}
                                </select>
                            </label>
                        </div>
                    </div>

                    <div className="flex shrink-0 xl:pt-1">
                        <span className="inline-flex items-center justify-center rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-slate-700">
                            {/**
                              * DEBUG POINT: Display selected school year label
                              * Shows which school year is currently filtered
                              */}
                            {selectedSchoolYear
                                ? schoolYears.find((option) => String(option.value) === String(selectedSchoolYear))?.label || 'All school years'
                                : 'All school years'}
                        </span>
                    </div>
                </div>

                <section className="mt-4 rounded-xl border border-slate-200 bg-white shadow-sm overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    Course Code
                                </th>
                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    Subject
                                </th>
                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    Year/Section
                                </th>
                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    Employee Name
                                </th>
                                <th className="px-5 py-3 text-left font-semibold text-slate-600">
                                    Action
                                </th>
                            </tr>
                        </thead>

                        <tbody className="divide-y divide-slate-200 bg-white">
                            {filteredRows.length > 0 ? (
                                filteredRows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-5 py-3 text-slate-700">
                                            {row.course_code || '-'}
                                        </td>

                                        <td className="px-5 py-3 text-slate-900 font-medium">
                                            {row.course_description || '-'}
                                        </td>

                                        <td className="px-5 py-3 text-slate-700">
                                            {normalizeYearSectionLabel(row.year_section)}
                                        </td>

                                        <td className="px-5 py-3 text-slate-700">
                                            {row.employee_name}
                                        </td>

                                        <td className="px-5 py-3">
                                            <button
                                                type="button"
                                                onClick={() => openSetModal(row)}
                                                className="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                                            >
                                                {row.action_label || 'Open'}
                                            </button>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-5 py-8 text-center text-slate-500"
                                    >
                                        No evaluation records found for this faculty.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </section>

                <div className="flex items-center justify-between mt-4">
                    <button
                        disabled={tablePagination?.current_page <= 1}
                        onClick={() => handlePageChange((tablePagination?.current_page ?? 1) - 1)}
                        className="px-3 py-1 text-sm bg-gray-200 rounded disabled:opacity-50"
                    >
                        Previous
                    </button>

                    <span className="text-sm text-slate-600">
                        Page {tablePagination?.current_page} of {tablePagination?.last_page}
                    </span>

                    <button
                        disabled={tablePagination?.current_page >= tablePagination?.last_page}
                        onClick={() => handlePageChange((tablePagination?.current_page ?? 1) + 1)}
                        className="px-3 py-1 text-sm bg-gray-200 rounded disabled:opacity-50"
                    >
                        Next
                    </button>
                </div>

                <FacultyReportPageModal
                    isOpen={isSetModalOpen}
                    onClose={closeSetModal}
                    setBreakdownRows={setBreakdownRows}
                    selectedCourseCode={selectedCourseCode}
                    selectedSefBreakdown={selectedSefBreakdown}
                    isLoading={isModalLoading}
                    errorMessage={modalError}
                    instructorId={facultyIdNo}
                    termId={selectedSchoolYear}
                />
            </main>
        </AppLayout>
    );
}