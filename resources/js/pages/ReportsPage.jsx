import React, { useState } from 'react';
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
    facultyList = { data: [] },
    recentReports = { data: [] },
    schoolYears = [],
    selectedSchoolYear = '',
}) {
    const isFaculty = isFacultyRole(user?.role);
    const [searchQuery, setSearchQuery] = useState('');

    const filteredFacultyList = facultyList.filter((faculty) =>
        faculty.instructor.toLowerCase().includes(searchQuery.toLowerCase()) ||
        faculty.employee_id_no.toString().includes(searchQuery)
    );

    const handleSchoolYearChange = (event) => {
        const newSchoolYear = event.target.value;
        router.get(route('reports', {
            term: newSchoolYear,
        }), {}, {
            preserveState: false,
            replace: true,
        });
    };

    const selectedSchoolYearLabel = schoolYears.find((option) => String(option.value) === String(selectedSchoolYear))?.label ?? 'All School Years';

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

                        {/* School Year Filter and Search */}
                        <div className="mt-4 flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                            <div className="w-full xl:flex-1">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    {/* School Year Filter */}
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

                                    {/* Search Bar */}
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
                            {recentReports.length > 0 ? (
                                recentReports.map((report, index) => (
                                    <div key={index} className="group rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-400 hover:shadow-md">
                                        <div className="flex items-start justify-between gap-4">
                                            {/* Left: Badge and Info */}
                                            <div className="flex items-start gap-4 min-w-0 flex-1">
                                                {/* Course Code Badge */}
                                                <div className="flex-shrink-0">
                                                    <div className="inline-flex items-center justify-center rounded-lg bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">
                                                        {report.code || 'N/A'}
                                                    </div>
                                                </div>

                                                {/* Content */}
                                                <div className="min-w-0 flex-1 pt-1">
                                                    {/* Course Title - Main Title */}
                                                    <h3 className="text-base font-semibold text-slate-900 line-clamp-2">
                                                        {report.course_title}
                                                    </h3>

                                                    {/* Instructor Name */}
                                                    <p className="text-sm text-slate-600 mt-1">
                                                        Instructor: <span className="font-medium">{report.instructor}</span>
                                                    </p>

                                                    {/* Submitted Date and Status Row */}
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

                                            {/* Right: Status and Grade */}
                                            <div className="flex flex-col items-end gap-2 flex-shrink-0">
                                                {/* Status Badge */}
                                                <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap ${
                                                    report.final_grade !== null
                                                        ? 'bg-emerald-100 text-emerald-700'
                                                        : 'bg-amber-100 text-amber-700'
                                                }`}>
                                                    {report.final_grade !== null ? 'GRADED' : 'PENDING'}
                                                </span>

                                                {/* Final Grade */}
                                                {report.final_grade !== null && (
                                                    <div className="text-right">
                                                        <p className="text-xs text-slate-600">Final Grade</p>
                                                        <p className="text-base font-bold text-slate-900">{report.final_grade}</p>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))
                            ) : (
                                <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                                    No evaluation records found.
                                </div>
                            )}
                        </div>
                    </section>
                ) : (
                    <section>
                        <div className="mb-6">
                            <h2 className="text-base font-semibold text-slate-900">All Faculty</h2>
                            <p className="mt-1 text-sm text-slate-500">Ratings for: <span className="font-medium">{selectedSchoolYearLabel}</span></p>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            {filteredFacultyList.length > 0 ? (
                                filteredFacultyList.map((faculty) => (
                                    <a
                                        key={faculty.instructor}
                                        href={faculty.detail_url}
                                        className="group block rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-500 hover:shadow-md hover:bg-slate-50 cursor-pointer"
                                    >
                                        {/* Header with Avatar and Basic Info */}
                                        <div className="flex items-start gap-3 mb-4">
                                            {/* Initials Badge */}
                                            <div className="flex-shrink-0">
                                                <div className="h-10 w-10 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 text-white flex items-center justify-center text-xs font-bold shadow-sm">
                                                    {faculty.initials}
                                                </div>
                                            </div>

                                            {/* Faculty Info */}
                                            <div className="min-w-0 flex-1">
                                                {/* Faculty Name */}
                                                <h3 className="text-sm font-semibold text-slate-900 group-hover:text-blue-600 transition line-clamp-2">
                                                    {faculty.instructor}
                                                </h3>

                                                {/* Employee ID */}
                                                <p className="text-xs text-slate-500 mt-0.5">
                                                    ID: <span className="font-medium">{faculty.employee_id_no}</span>
                                                </p>
                                            </div>
                                        </div>

                                        {/* Divider */}
                                        <div className="mb-3 h-px bg-gradient-to-r from-slate-200 to-transparent"></div>

                                        {/* Stats */}
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
                                ))
                            ) : (
                                <div className="col-span-full rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                                    {searchQuery ? 'No faculty found matching your search.' : 'No faculty records available.'}
                                </div>
                            )}
                        </div>
                    </section>
                )}
            </main>
        </AppLayout>
    );
}