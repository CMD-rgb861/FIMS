// resources/js/modals/FacultyIFEPrintModal.jsx

import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import axios from 'axios';
import { toast } from 'react-toastify';

export default function FacultyIFEPrintModal({
    isOpen,
    onClose,
    facultyIdNo,
    facultyName,
    term,
    schoolYearLabel,
    subjects = []
}) {
    const [isLoading, setIsLoading] = useState(false);
    const [isGenerating, setIsGenerating] = useState(false);
    const [facultyData, setFacultyData] = useState(null);
    const [setBreakdown, setSetBreakdown] = useState([]);
    const [sefData, setSefData] = useState(null);
    const [studentComments, setStudentComments] = useState([]);
    const [supervisorComments, setSupervisorComments] = useState([]);
    const [facultyInfo, setFacultyInfo] = useState({
        college: '',
        academic_rank: '',
        dean_name: '',
        associate_dean_name: ''
    });

    // Fetch faculty data when modal opens
    useEffect(() => {
        if (isOpen && term && facultyIdNo) {
            fetchFacultyData();
        }
    }, [isOpen, term, facultyIdNo]);

    const fetchFacultyData = async () => {
        setIsLoading(true);
        try {
            const response = await axios.get(`/ife/faculty/${facultyIdNo}`, {
                params: { term_id: term },
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = response.data;
            
            if (data.success) {
                // Set all the data from the response
                setSetBreakdown(data.set_data?.rows || []);
                setSefData(data.sef_data || {});
                
                // ✅ FIX: Handle comments properly - they should already be arrays with seq and comment
                setStudentComments(data.comments?.student || []);
                setSupervisorComments(data.comments?.supervisor || []);
                
                setFacultyInfo({
                    college: data.faculty_info?.college || '',
                    academic_rank: data.faculty_info?.academic_rank || '',
                    dean_name: data.faculty_info?.dean_name || '',
                    associate_dean_name: data.faculty_info?.associate_dean_name || ''
                });

                // ✅ FIX: Handle sef_data.comments properly (it's now an array)
                const sefComments = data.sef_data?.comments || [];
                const sefCommentsText = Array.isArray(sefComments) 
                    ? sefComments.join('\n') 
                    : sefComments || '';

                setFacultyData({
                    employee_id_no: facultyIdNo,
                    instructor: facultyName,
                    has_complete_data: data.has_complete_data || false,
                    overall_set_rating: data.set_data?.overall_rating || null,
                    overall_sef_rating: data.sef_data?.overall_rating || null,
                    total_evaluators: data.sef_data?.total_evaluators || 0,
                    ratings_breakdown: data.sef_data?.ratings_breakdown || null,
                    comments: sefCommentsText
                });
            } else {
                toast.error(data.message || 'Failed to load faculty data');
            }
        } catch (error) {
            console.error('Error fetching faculty data:', error);
            toast.error('Failed to load faculty data. Please try again.');
        } finally {
            setIsLoading(false);
        }
    };

    // Generate PDF
    const handleGeneratePDF = async () => {
        setIsGenerating(true);
        const loadingToastId = toast.loading('Generating IFE PDF...');

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const selectedFacultyData = [{
                employee_id_no: facultyIdNo,
                instructor: facultyName,
                department: facultyInfo.college || '',
                faculty_data: {
                    set_rows: setBreakdown,
                    overall_set_rating: facultyData?.overall_set_rating || null,
                    overall_sef_rating: facultyData?.overall_sef_rating || null,
                    student_comments: studentComments,
                    supervisor_comments: supervisorComments,
                    faculty_info: facultyInfo
                }
            }];

            const response = await axios.post('/individual-faculty-evaluation/pdf/generate', {
                term_id: String(term),
                faculty_list: selectedFacultyData,
                school_year_label: schoolYearLabel
            }, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                    'Accept': 'application/json'
                },
                timeout: 300000
            });

            const pdfUrl = response.data.pdf_url;
            if (pdfUrl) {
                toast.dismiss(loadingToastId);
                window.open(pdfUrl, '_blank');
                toast.success('IFE PDF generated successfully!');
                onClose();
            } else {
                toast.dismiss(loadingToastId);
                toast.error('Failed to generate PDF. Please try again.');
            }
        } catch (error) {
            console.error('Error generating IFE PDF:', error);
            toast.dismiss(loadingToastId);
            toast.error(error.response?.data?.message || 'Failed to generate PDF. Please try again.');
        } finally {
            setIsGenerating(false);
        }
    };

    if (!isOpen) return null;

    // Calculate totals
    const totalStudents = setBreakdown.reduce((sum, row) => sum + (parseFloat(row.student_count) || 0), 0);
    const totalWeightedScore = setBreakdown.reduce((sum, row) => sum + (parseFloat(row.weighted_score) || 0), 0);
    const overallSetRating = facultyData?.overall_set_rating || (totalStudents > 0 ? totalWeightedScore / totalStudents : 0);
    const overallSefRating = facultyData?.overall_sef_rating || null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4">
            <div className="w-full max-w-5xl max-h-[90vh] rounded-2xl border border-slate-300 bg-white shadow-2xl flex flex-col">
                {/* Header */}
                <div className="border-b border-slate-200 px-5 py-4 flex-shrink-0">
                    <div className="flex items-start justify-between">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">Individual Faculty Evaluation (IFE) Report</h2>
                            <p className="text-sm text-slate-600 mt-1">
                                Preview and generate IFE report for {facultyName}
                            </p>
                            {schoolYearLabel && (
                                <p className="text-xs text-slate-500 mt-0.5">
                                    School Year: {schoolYearLabel}
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

                {/* Body - Scrollable */}
                <div className="px-5 py-4 overflow-y-auto flex-1">
                    {isLoading ? (
                        <div className="flex items-center justify-center py-12">
                            <div className="text-center">
                                <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-purple-600 mx-auto"></div>
                                <p className="mt-4 text-sm text-slate-500">Loading faculty data...</p>
                            </div>
                        </div>
                    ) : (
                        <div className="space-y-6">
                            {/* Faculty Information */}
                            <div className="bg-slate-50 rounded-lg p-4 border border-slate-200">
                                <h3 className="text-sm font-semibold text-slate-700 mb-3">A. Faculty Information</h3>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span className="text-slate-500">Name:</span>
                                        <span className="ml-2 font-medium text-slate-800">{facultyName}</span>
                                    </div>
                                    <div>
                                        <span className="text-slate-500">Employee ID:</span>
                                        <span className="ml-2 font-medium text-slate-800">{facultyIdNo}</span>
                                    </div>
                                    <div>
                                        <span className="text-slate-500">Department/College:</span>
                                        <span className="ml-2 font-medium text-slate-800">{facultyInfo.college || 'N/A'}</span>
                                    </div>
                                    <div>
                                        <span className="text-slate-500">Academic Rank:</span>
                                        <span className="ml-2 font-medium text-slate-800">{facultyInfo.academic_rank || 'N/A'}</span>
                                    </div>
                                    <div className="md:col-span-2">
                                        <span className="text-slate-500">School Year:</span>
                                        <span className="ml-2 font-medium text-slate-800">{schoolYearLabel}</span>
                                    </div>
                                </div>
                            </div>

                            {/* SET Ratings Summary */}
                            <div className="bg-white rounded-lg border border-slate-200 overflow-hidden">
                                <h3 className="text-sm font-semibold text-slate-700 p-4 bg-slate-50 border-b border-slate-200">
                                    B. Summary of Average SET Rating Computation
                                </h3>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <thead className="bg-slate-50">
                                            <tr>
                                                <th className="px-3 py-2 text-left text-xs font-semibold text-slate-600">Seq</th>
                                                <th className="px-3 py-2 text-left text-xs font-semibold text-slate-600">Course Code</th>
                                                <th className="px-3 py-2 text-left text-xs font-semibold text-slate-600">Year/Section</th>
                                                <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">No. of Students</th>
                                                <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Avg SET Rating</th>
                                                <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Weighted Score</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-200 bg-white">
                                            {setBreakdown.length > 0 ? (
                                                setBreakdown.map((row, index) => (
                                                    <tr key={index} className="hover:bg-slate-50">
                                                        <td className="px-3 py-2 text-sm text-slate-600">{row.seq || index + 1}</td>
                                                        <td className="px-3 py-2 text-sm text-slate-600">{row.course_code || '-'}</td>
                                                        <td className="px-3 py-2 text-sm text-slate-600">{row.year_section || '-'}</td>
                                                        <td className="px-3 py-2 text-sm text-slate-600 text-right">{row.student_count || 0}</td>
                                                        <td className="px-3 py-2 text-sm text-slate-600 text-right">{row.avg_set_rating ? Number(row.avg_set_rating).toFixed(2) : '-'}</td>
                                                        <td className="px-3 py-2 text-sm text-slate-600 text-right">{row.weighted_score ? Number(row.weighted_score).toFixed(2) : '-'}</td>
                                                    </tr>
                                                ))
                                            ) : (
                                                <tr>
                                                    <td colSpan="6" className="px-3 py-4 text-sm text-slate-500 text-center">
                                                        No SET data available
                                                    </td>
                                                </tr>
                                            )}
                                            {/* Total Row */}
                                            {setBreakdown.length > 0 && (
                                                <tr className="bg-slate-50 font-semibold">
                                                    <td colSpan="3" className="px-3 py-2 text-sm text-slate-700 text-right">TOTAL</td>
                                                    <td className="px-3 py-2 text-sm text-slate-700 text-right">{totalStudents}</td>
                                                    <td className="px-3 py-2 text-sm text-slate-700 text-right">
                                                        {setBreakdown.length > 0 ? (setBreakdown.reduce((sum, row) => sum + (parseFloat(row.avg_set_rating) || 0), 0) / setBreakdown.length).toFixed(2) : '-'}
                                                    </td>
                                                    <td className="px-3 py-2 text-sm text-slate-700 text-right">{totalWeightedScore.toFixed(2)}</td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {/* Overall Ratings */}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="bg-blue-50 rounded-lg p-4 border border-blue-200">
                                    <h4 className="text-sm font-semibold text-blue-700">Overall SET Rating</h4>
                                    <p className="text-2xl font-bold text-blue-600 mt-1">
                                        {overallSetRating ? Number(overallSetRating).toFixed(2) + '%' : 'N/A'}
                                    </p>
                                    <p className="text-xs text-blue-500 mt-1">Based on {totalStudents} total student(s)</p>
                                </div>
                                <div className="bg-emerald-50 rounded-lg p-4 border border-emerald-200">
                                    <h4 className="text-sm font-semibold text-emerald-700">Overall SEF Rating</h4>
                                    <p className="text-2xl font-bold text-emerald-600 mt-1">
                                        {overallSefRating ? Number(overallSefRating).toFixed(2) + '%' : 'N/A'}
                                    </p>
                                    <p className="text-xs text-emerald-500 mt-1">
                                        Based on {facultyData?.total_evaluators || 0} supervisor evaluation(s)
                                    </p>
                                </div>
                            </div>

                            {/* Comments Section */}
                            <div className="bg-white rounded-lg border border-slate-200">
                                <h3 className="text-sm font-semibold text-slate-700 p-4 bg-slate-50 border-b border-slate-200">
                                    D. Summary of Qualitative Comments and Suggestions
                                </h3>
                                <div className="p-4">
                                    <h4 className="text-sm font-medium text-slate-600 mb-2">From Students:</h4>
                                    {studentComments.length > 0 && studentComments[0]?.comment !== 'No student comments available' ? (
                                        <div className="space-y-2 max-h-32 overflow-y-auto">
                                            {studentComments.map((comment, index) => (
                                                <div key={index} className="text-sm text-slate-700 bg-slate-50 p-2 rounded">
                                                    <span className="font-medium text-slate-500">#{comment.seq || index + 1}:</span> {comment.comment}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-slate-400 italic">No student comments available</p>
                                    )}
                                </div>
                                <div className="border-t border-slate-200 p-4">
                                    <h4 className="text-sm font-medium text-slate-600 mb-2">From Supervisor:</h4>
                                    {supervisorComments.length > 0 && supervisorComments[0]?.comment !== 'No supervisor comments available' ? (
                                        <div className="space-y-2 max-h-32 overflow-y-auto">
                                            {supervisorComments.map((comment, index) => (
                                                <div key={index} className="text-sm text-slate-700 bg-slate-50 p-2 rounded">
                                                    <span className="font-medium text-slate-500">#{comment.seq || index + 1}:</span> {comment.comment}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-slate-400 italic">No supervisor comments available</p>
                                    )}
                                </div>
                            </div>

                            {/* Status Banner */}
                            {facultyData && !facultyData.has_complete_data && (
                                <div className="rounded-md bg-yellow-50 p-3 text-sm text-yellow-700 border border-yellow-200">
                                    ⚠️ Incomplete data: Both SET and SEF data are required to generate IFE reports.
                                    {!facultyData.overall_set_rating && ' Missing SET data.'}
                                    {!facultyData.overall_sef_rating && ' Missing SEF data.'}
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-4 flex-shrink-0">
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex items-center rounded-md bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-300"
                        disabled={isGenerating}
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        onClick={handleGeneratePDF}
                        disabled={isGenerating || isLoading || !facultyData?.has_complete_data}
                        className="inline-flex items-center rounded-md bg-purple-600 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {isGenerating ? (
                            <>
                                <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Generating IFE PDF...
                            </>
                        ) : (
                            'Generate IFE PDF'
                        )}
                    </button>
                </div>
            </div>
        </div>
    );
}