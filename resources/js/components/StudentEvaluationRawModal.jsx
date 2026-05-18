export default function StudentEvaluationRawModal({
    isOpen,
    onClose,
    faculty = null,
    rows = [],
    columns = [],
    isLoading = false,
    errorMessage = '',
}) {
    if (!isOpen) {
        return null;
    }

    const displayColumns = columns.length > 0
        ? columns
        : rows.reduce((columnList, row) => {
            Object.keys(row || {}).forEach((column) => {
                if (!columnList.includes(column)) {
                    columnList.push(column);
                }
            });

            return columnList;
        }, []);

    const renderCell = (value) => {
        if (value === null || value === undefined || value === '') {
            return '-';
        }

        if (typeof value === 'object') {
            return JSON.stringify(value, null, 2);
        }

        return String(value);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div className="flex max-h-[90vh] w-full max-w-7xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Raw POES data
                        </p>
                        <h3 className="mt-1 text-lg font-semibold text-slate-900">
                            {faculty?.instructor ?? 'Selected instructor'}
                        </h3>
                        <p className="mt-1 text-sm text-slate-500">
                            student_evaluation_submissions from lnu_poes
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        Close
                    </button>
                </div>

                <div className="flex-1 overflow-auto p-5">
                    {isLoading ? (
                        <div className="mb-4 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-700">
                            Loading raw submission rows...
                        </div>
                    ) : null}

                    {errorMessage ? (
                        <div className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                            {errorMessage}
                        </div>
                    ) : null}

                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">
                                Total rows: {rows.length}
                            </p>
                            <p className="text-xs text-slate-500">
                                Showing every column returned by the database query.
                            </p>
                        </div>
                        {faculty?.employee_id_no ? (
                            <p className="text-xs text-slate-500">
                                Employee ID: <span className="font-medium text-slate-700">{faculty.employee_id_no}</span>
                            </p>
                        ) : null}
                    </div>

                    <div className="overflow-x-auto rounded-lg border border-slate-200">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    {displayColumns.length > 0 ? (
                                        displayColumns.map((column) => (
                                            <th
                                                key={column}
                                                className="whitespace-nowrap px-4 py-2.5 text-left font-semibold text-slate-600"
                                            >
                                                {column}
                                            </th>
                                        ))
                                    ) : (
                                        <th className="px-4 py-2.5 text-left font-semibold text-slate-600">
                                            No columns available
                                        </th>
                                    )}
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-200 bg-white">
                                {rows.length > 0 ? (
                                    rows.map((row, rowIndex) => (
                                        <tr key={row.id ?? rowIndex} className="align-top">
                                            {displayColumns.map((column) => (
                                                <td
                                                    key={`${row.id ?? rowIndex}-${column}`}
                                                    className="whitespace-nowrap px-4 py-2.5 text-slate-700"
                                                >
                                                    <span className="block max-w-[24rem] whitespace-pre-wrap break-words">
                                                        {renderCell(row?.[column])}
                                                    </span>
                                                </td>
                                            ))}
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={Math.max(displayColumns.length, 1)}
                                            className="px-4 py-6 text-center text-slate-500"
                                        >
                                            No raw submission records found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    );
}
