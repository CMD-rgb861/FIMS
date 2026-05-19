//INDIVIDUAL FACULTY REPORT BREAKDOWN MODAL COMPONENT

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
}) {
    if (!isOpen) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4">
            <div className="w-full max-w-5xl rounded-xl border border-slate-200 bg-white shadow-2xl">
                <div className="flex items-center justify-end border-b border-slate-200 px-5 py-4">
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

                    <div className="mb-4">
                        <h3 className="text-sm font-semibold text-slate-900">
                            SEF Breakdown
                        </h3>

                        <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <p className="text-xs text-slate-500">Total Score</p>
                                <p className="text-sm font-semibold text-slate-900">
                                    {selectedSefBreakdown?.total_score ?? '-'}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs text-slate-500">Rating</p>
                                <p className="text-sm font-semibold text-slate-900">
                                    {selectedSefBreakdown?.rating ?? '-'}
                                </p>
                            </div>
                        </div>
                    </div>

                    <h3 className="mb-3 text-sm font-semibold text-slate-900">
                        SET Breakdown
                    </h3>

                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                    Seq
                                </th>

                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                    (1) Course Code
                                </th>

                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                    (2) Year/Section
                                </th>

                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                    (3) No. of Students
                                </th>

                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                    (4) Average SET Rating
                                </th>

                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                    (3 x 5) Weighted SET Score
                                </th>
                                <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                    Total SET
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
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td
                                        colSpan={7}
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
        </div>
    );
}