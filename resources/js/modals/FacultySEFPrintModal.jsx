import { useEffect, useState } from 'react';
import axios from 'axios';
import { toast } from 'react-toastify';

export default function FacultySEFPrintModal({
    isOpen,
    onClose,
    facultyIdNo,
    facultyName,
    term,
    schoolYearLabel,
}) {
    const [isFetching, setIsFetching] = useState(false);
    const [isPrinting, setIsPrinting] = useState(false);
    const [sefSummary, setSefSummary] = useState(null);

    useEffect(() => {
        if (!isOpen) {
            setSefSummary(null);
            setIsFetching(false);
            setIsPrinting(false);
            return;
        }

        async function loadSummary() {
            if (!facultyIdNo || !term) {
                setSefSummary(null);
                return;
            }

            setIsFetching(true);

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                
                const response = await axios.get(`/sef/faculty/${encodeURIComponent(facultyIdNo)}/reports`, {
                    params: { term_id: term },
                    headers: { 
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken || ''
                    },
                });

                const data = response.data;
                
                if (data && data.has_data) {
                    setSefSummary({
                        average_rating: data.overall_sef_rating ? `${data.overall_sef_rating}%` : 'N/A',
                        overall_sef_rating: data.overall_sef_rating,
                        total_evaluators: data.total_evaluators,
                        ratings_breakdown: data.ratings_breakdown,
                        has_data: true
                    });
                } else {
                    setSefSummary({
                        has_data: false,
                        average_rating: 'N/A'
                    });
                }
            } catch (error) {
                console.error('Error loading SEF summary:', error);
                toast.error('Unable to load SEF summary. Please try again.', {
                    position: "top-right",
                    autoClose: 5000,
                });
                setSefSummary({ has_data: false, average_rating: 'N/A' });
            } finally {
                setIsFetching(false);
            }
        }

        loadSummary();
    }, [isOpen, facultyIdNo, term]);

    const handlePrint = async () => {
        if (!facultyIdNo || !facultyName || !term) {
            toast.error('Missing print information.', {
                position: "top-right",
                autoClose: 3000,
            });
            return;
        }

        setIsPrinting(true);

        const loadingToastId = toast.loading('Generating SEF PDF...', {
            position: "top-right",
        });

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const response = await axios.post(
                '/sef/pdf/generate',
                {
                    term_id: String(term),
                    faculty_list: [{ 
                        employee_id_no: facultyIdNo, 
                        instructor: facultyName,
                        ratings_breakdown: sefSummary?.ratings_breakdown || null,
                        comments: sefSummary?.comments || ''
                    }],
                    school_year_label: schoolYearLabel,
                },
                {
                    headers: { 
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken || ''
                    },
                    timeout: 300000
                }
            );

            if (response.data?.pdf_url) {
                toast.dismiss(loadingToastId);
                
                window.open(response.data.pdf_url, '_blank');
                
                toast.success('PDF generated successfully! Opening in new tab...', {
                    position: "top-right",
                    autoClose: 3000,
                });
                onClose();
                return;
            }

            toast.dismiss(loadingToastId);
            toast.error('The PDF was generated, but no file link was returned.', {
                position: "top-right",
                autoClose: 5000,
            });
        } catch (error) {
            console.error('Error generating PDF:', error);
            toast.dismiss(loadingToastId);
            toast.error(error.response?.data?.message || 'Failed to generate SEF PDF. Please try again.', {
                position: "top-right",
                autoClose: 5000,
            });
        } finally {
            setIsPrinting(false);
        }
    };

    if (!isOpen) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4">
            <div className="w-full max-w-2xl rounded-2xl border border-slate-300 bg-white shadow-2xl">
                {/* Header */}
                <div className="border-b border-slate-200 px-5 py-4">
                    <div className="flex items-start justify-between">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">Print SEF Report</h2>
                            <p className="text-sm text-slate-600 mt-1">
                                Print the SEF PDF for {facultyName}
                            </p>
                            {schoolYearLabel && (
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Term: {schoolYearLabel}
                                </p>
                            )}
                        </div>
                        <button
                            onClick={onClose}
                            className="rounded-md text-slate-400 hover:text-slate-600 focus:outline-none"
                        >
                            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                {/* Body */}
                <div className="px-5 py-4">
                    {isFetching && (
                        <div className="mb-4 rounded-md bg-blue-50 p-3 text-sm text-blue-700 border border-blue-200">
                            <div className="flex items-center gap-2">
                                <svg className="animate-spin h-4 w-4 text-blue-700" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Loading SEF summary...
                            </div>
                        </div>
                    )}

                    {/* Faculty Info Card */}
                    <div className="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <p className="text-xs font-medium text-slate-500">Faculty Name</p>
                                <p className="mt-1 text-sm font-semibold text-slate-900">{facultyName || 'Unknown'}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-slate-500">Employee ID</p>
                                <p className="mt-1 text-sm font-semibold text-slate-900">{facultyIdNo || 'N/A'}</p>
                            </div>
                        </div>
                    </div>

                    {/* SEF Rating Card */}
                    {!isFetching && sefSummary?.has_data ? (
                        <div className="rounded-lg border border-slate-200 bg-white p-6">
                            <div className="text-center">
                                <p className="text-sm font-medium text-slate-500">Average SEF Rating</p>
                                <p className="mt-2 text-5xl font-bold text-blue-600">
                                    {sefSummary.average_rating}
                                </p>
                            </div>
                        </div>
                    ) : !isFetching && !sefSummary?.has_data ? (
                        <div className="rounded-lg border border-slate-200 bg-white p-8 text-center">
                            <svg className="mx-auto h-12 w-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <p className="mt-2 text-sm text-slate-500">
                                No SEF summary is available for this faculty and term.
                            </p>
                        </div>
                    ) : null}
                </div>

                {/* Footer */}
                <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex items-center rounded-md bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-300"
                        disabled={isPrinting}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={handlePrint}
                        disabled={isPrinting || isFetching || !sefSummary?.has_data}
                        className="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {isPrinting ? (
                            <>
                                <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Generating PDF...
                            </>
                        ) : (
                            <>
                                <svg className="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                </svg>
                                Print SEF
                            </>
                        )}
                    </button>
                </div>
            </div>
        </div>
    );
}