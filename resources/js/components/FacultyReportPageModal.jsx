//INDIVIDUAL FACULTY REPORT BREAKDOWN MODAL COMPONENT

export default function FacultyReportPageModal({
    isOpen,
    onClose,
    setBreakdownRows = [],
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
                                            {item.course_code}
                                        </td>

                                        <td className="px-4 py-2.5 text-slate-700">
                                            {item.year_section}
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
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td
                                        colSpan={6}
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