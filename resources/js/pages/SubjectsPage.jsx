import React, { useMemo, useState } from 'react';
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
    subjects = { data: [] },
    availableTerms = [],
    hasPendingEvaluations = false,
}) {
    const displayName = user?.display_name || [user?.firstname, user?.lastname].filter(Boolean).join(' ') || 'Faculty';
    const subjectItems = Array.isArray(subjects?.data)
        ? subjects.data
        : Array.isArray(subjects)
            ? subjects
            : [];
    const [selectedTerm, setSelectedTerm] = useState('');

    const termOptions = useMemo(() => {
        const fromProps = (Array.isArray(availableTerms) ? availableTerms : [])
            .map((term) => String(term || '').trim())
            .filter(Boolean);

        const fromSubjects = subjectItems
            .map((subject) => {
                const value = subject.term || subject.semester || (subject.school_year_id ? `School Year #${subject.school_year_id}` : '');
                return String(value || '').trim();
            })
            .filter(Boolean);

        return Array.from(new Set([...fromProps, ...fromSubjects])).sort((a, b) => {
            const yearA = parseInt(a.match(/\d{4}/)?.[0] || 0);
            const yearB = parseInt(b.match(/\d{4}/)?.[0] || 0);
            return yearB - yearA;
        });
    }, [availableTerms, subjectItems]);

    const filteredItems = useMemo(() => {
        if (selectedTerm === 'all') {
            return subjectItems;
        }

        return subjectItems.filter((subject) => {
            const value = String(subject.term || subject.semester || (subject.school_year_id ? `School Year #${subject.school_year_id}` : '') || '').trim();
            return value === selectedTerm;
        });
    }, [selectedTerm, subjectItems]);

    const handlePageChange = (page) => {
        router.get(route('subjects.index'), {
            page,
            term: selectedTerm,
        }, {
            preserveState: true,
            replace: true,
        });
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

                <section className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-x-auto">
                    <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <p className="text-sm font-semibold text-slate-900">Enrolled Subjects</p>
                        <div className="flex items-center gap-3">
                            <label className="text-xs text-slate-600">
                                <span className="mr-2">Term/Semester</span>
                                <select
                                    value={selectedTerm}
                                    onChange={(event) => setSelectedTerm(event.target.value)}
                                    className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700"
                                >
                                    {/* <option value="all">All</option> */}
                                    {termOptions.map((term) => (
                                        <option key={term} value={term}>
                                            {term}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <p className="text-xs text-slate-500">
                                {filteredItems.length} of {subjectItems.length} record{subjectItems.length === 1 ? '' : 's'}
                            </p>
                        </div>
                    </div>

                    <table className="min-w-full divide-y divide-slate-200 text-xs">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Course Code</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Course Description</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Course Units</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Section Code</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Schedule Time</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Schedule Days</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Room</th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">Term/Semester</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 bg-white">
                            {filteredItems.length > 0 ? (
                                filteredItems.map((subject, index) => (
                                    <tr key={`${subject.course_code}-${subject.course_description}-${index}`}>
                                        <td className="px-4 py-2.5 font-medium text-slate-900">{subject.course_code || '-'}</td>
                                        <td className="px-4 py-2.5 text-slate-700">{subject.course_description || '-'}</td>
                                        <td className="px-4 py-2.5 text-slate-700">{subject.course_units ?? '-'}</td>
                                        <td className="px-4 py-2.5 text-slate-700">{subject.section_code || '-'}</td>
                                        <td className="px-4 py-2.5 text-slate-700">{subject.schedule_time || '-'}</td>
                                        <td className="px-4 py-2.5 text-slate-700">{subject.schedule_days || '-'}</td>
                                        <td className="px-4 py-2.5 text-slate-700">{subject.room || '-'}</td>
                                        <td className="px-4 py-2.5 text-slate-700">{subject.term || subject.semester || (subject.school_year_id ? `School Year #${subject.school_year_id}` : '-')}</td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={8} className="px-4 py-6 text-center text-slate-500">
                                        No enrolled subjects were found for this faculty member.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>

                    {subjectPagination && (
                        <div className="flex items-center justify-between mt-4">
                            <button
                                disabled={subjectPagination.current_page <= 1}
                                onClick={() => handlePageChange(subjectPagination.current_page - 1)}
                                className="px-3 py-1 text-sm bg-gray-200 rounded disabled:opacity-50"
                            >
                                Previous
                            </button>

                            <span className="text-sm text-slate-600">
                                Page {subjectPagination.current_page} of {subjectPagination.last_page}
                            </span>

                            <button
                                disabled={subjectPagination.current_page >= subjectPagination.last_page}
                                onClick={() => handlePageChange(subjectPagination.current_page + 1)}
                                className="px-3 py-1 text-sm bg-gray-200 rounded disabled:opacity-50"
                            >
                                Next
                            </button>
                        </div>
                    )}
                </section>
            </main>
        </AppLayout>
    );
}
