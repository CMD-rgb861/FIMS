import React, { useState } from 'react';
import ReportSubmissionsModal from './ReportSubmissionsModal';
import { Transition } from '@headlessui/react';

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

const normalizeCourseCode = (value) => {
    const courseCode = String(value ?? '').trim();

    if (!courseCode || courseCode === '-') {
        return '';
    }

    return courseCode;
};

export default function FacultyReportPageModal({
    isOpen,
    onClose,
    setBreakdownRows = [],
    selectedCourseCode = '',
    selectedSefBreakdown = null,
    isLoading = false,
    errorMessage = '',
    instructorId = null,
    termId = null,
    facultyName = '', // Add facultyName prop
}) {
    const [isSubmissionsModalOpen, setIsSubmissionsModalOpen] = useState(false);
    const [selectedCourseData, setSelectedCourseData] = useState(null);

    const handleViewClick = (item) => {
        console.log('View clicked, item:', item);
        setSelectedCourseData({
            courseCode: normalizeCourseCode(item.course_code) || selectedCourseCode,
            yearSection: normalizeYearSectionLabel(item.year_section),
            instructorId: instructorId,
            termId: termId,
        });
        console.log('Setting isSubmissionsModalOpen to true');
        setIsSubmissionsModalOpen(true);
    };

    return (
        <>
            <Transition show={isOpen} appear>
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    {/* Backdrop */}
                    <Transition.Child
                        enter="ease-out duration-300"
                        enterFrom="opacity-0"
                        enterTo="opacity-100"
                        leave="ease-in duration-200"
                        leaveFrom="opacity-100"
                        leaveTo="opacity-0"
                    >
                        <div className="fixed inset-0 bg-slate-900/45" />
                    </Transition.Child>

                    {/* Modal Panel */}
                    <Transition.Child
                        enter="ease-out duration-300"
                        enterFrom="opacity-0 scale-95"
                        enterTo="opacity-100 scale-100"
                        leave="ease-in duration-200"
                        leaveFrom="opacity-100 scale-100"
                        leaveTo="opacity-0 scale-95"
                    >
                        <div className="relative w-full max-w-6xl rounded-xl border border-slate-200 bg-white shadow-2xl">
                            <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                                <h2 className="text-lg font-semibold text-slate-900">
                                    Student Evaluation of Teachers (SET)
                                </h2>
                                <button
                                    type="button"
                                    onClick={onClose}
                                    className="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    Close
                                </button>
                            </div>

                            <div className="max-h-[70vh] overflow-auto p-5">
                                {isLoading ? (
                                    <div className="mb-4 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-700">
                                        Loading breakdown data...
                                    </div>
                                ) : null}

                                {errorMessage ? (
                                    <div className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                                        {errorMessage}
                                    </div>
                                ) : null}

                                <table className="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                                Seq
                                            </th>

                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                                Course Code
                                            </th>

                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                                Year/Section
                                            </th>

                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                                No. of Students
                                            </th>

                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                                Average SET Rating
                                            </th>

                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                                Weighted SET Score
                                            </th>
                                            
                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                                Total SET
                                            </th>

                                            <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                                Action
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody className="divide-y divide-slate-200 bg-white">
                                        {setBreakdownRows.length > 0 ? (
                                            setBreakdownRows.map((item) => (
                                                <tr key={`${item.seq}-${item.course_code}`}>
                                                    <td className="px-4 py-2.5 text-slate-900 font-medium">
                                                        {item.seq}
                                                    </td>

                                                    <td className="px-4 py-2.5 text-slate-700">
                                                        {normalizeCourseCode(item.course_code) || selectedCourseCode || '-'}
                                                    </td>

                                                    <td className="px-4 py-2.5 text-slate-700">
                                                        {normalizeYearSectionLabel(item.year_section)}
                                                    </td>

                                                    <td className="px-4 py-2.5 text-slate-700">
                                                        {item.no_of_students ?? '-'}
                                                    </td>

                                                    <td className="px-4 py-2.5 text-slate-700">
                                                        {item.average_set_rating}
                                                    </td>

                                                    <td className="px-4 py-2.5 text-slate-700">
                                                        {item.weighted_set_score}
                                                    </td>
                                                    
                                                    <td className="px-4 py-2.5 text-slate-700">
                                                        {item.total_set_value !== null && item.total_set_value !== undefined
                                                            ? Number(item.total_set_value).toFixed(2)
                                                            : (item.weighted_set_score_value !== null && item.weighted_set_score_value !== undefined && item.no_of_students_value && Number(item.no_of_students_value) > 0)
                                                                ? (Number(item.weighted_set_score_value) / Number(item.no_of_students_value)).toFixed(2)
                                                                : (item.weighted_set_score_value !== null && item.weighted_set_score_value !== undefined)
                                                                    ? Number(item.weighted_set_score_value).toFixed(2)
                                                                    : '-'
                                                        }
                                                    </td>

                                                    <td className="px-4 py-2.5">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleViewClick(item)}
                                                            className="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                                        >
                                                            View
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td
                                                    colSpan={8}
                                                    className="px-4 py-6 text-center text-slate-500"
                                                >
                                                    No SET breakdown data available.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </Transition.Child>
                </div>
            </Transition>

            {/* Nested Modal 2 - Student Submissions */}
            <ReportSubmissionsModal
                isOpen={isSubmissionsModalOpen}
                onClose={() => setIsSubmissionsModalOpen(false)}
                courseCode={selectedCourseData?.courseCode}
                courseDesc={selectedCourseData?.courseDescription}
                yearSection={selectedCourseData?.yearSection}
                instructorId={selectedCourseData?.instructorId}
                termId={selectedCourseData?.termId}
                instructorName={facultyName}
            />
        </>
    );
}