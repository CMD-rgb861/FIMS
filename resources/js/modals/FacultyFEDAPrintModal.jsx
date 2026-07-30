// resources/js/modals/FacultyFEDAPrintModal.jsx

import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import axios from 'axios';
import { toast } from 'react-toastify';

export default function FacultyFEDAPrintModal({
    isOpen,
    onClose,
    facultyIdNo,
    facultyName,
    term,
    schoolYearLabel
}) {
    const [isLoading, setIsLoading] = useState(false);
    const [isGenerating, setIsGenerating] = useState(false);
    const [facultyData, setFacultyData] = useState(null);
    const [setBreakdown, setSetBreakdown] = useState([]);
    const [sefData, setSefData] = useState(null);
    const [studentComments, setStudentComments] = useState([]);
    const [supervisorComments, setSupervisorComments] = useState([]);
    const [developmentPlan, setDevelopmentPlan] = useState({
        areas_for_improvement: '',
        proposed_activities: '',
        action_plan: '',
        is_submitted: false,
        submitted_at: null
    });
    const [facultyInfo, setFacultyInfo] = useState({
        college: '',
        program: '',
        academic_rank: ''
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
            const response = await axios.get(`/feda/faculty/${facultyIdNo}/data`, {
                params: { term_id: term },
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = response.data;
            
            if (data.success) {
                // Set faculty info
                setFacultyInfo({
                    college: data.faculty_info?.college || 'N/A',
                    program: data.faculty_info?.program || 'N/A',
                    academic_rank: data.faculty_info?.academic_rank || 'N/A'
                });

                // Set SET data (from the response)
                // Note: The FEDA endpoint might not return full SET breakdown, 
                // but we can display the overall rating
                setSetBreakdown([]); // Will be populated if available
                
                // Set SEF data
                setSefData({
                    overall_rating: data.overall_sef_rating || null,
                    comments: data.comments || '',
                    ratings_breakdown: data.ratings_breakdown || null
                });

                // Set comments
                // For FEDA, we might want to show supervisor comments prominently
                setSupervisorComments(data.comments ? [{ seq: 1, comment: data.comments }] : []);
                setStudentComments([]); // FEDA focuses on supervisor comments

                // Set development plan
                setDevelopmentPlan({
                    areas_for_improvement: data.development_plan?.areas_for_improvement || '',
                    proposed_activities: data.development_plan?.proposed_activities || '',
                    action_plan: data.development_plan?.action_plan || '',
                    is_submitted: data.development_plan?.is_submitted || false,
                    submitted_at: data.development_plan?.submitted_at || null
                });

                setFacultyData({
                    employee_id_no: facultyIdNo,
                    instructor: facultyName,
                    has_complete_data: data.overall_set_rating !== null && data.overall_sef_rating !== null,
                    has_sef_data: data.overall_sef_rating !== null,
                    has_set_data: data.overall_set_rating !== null,
                    feda_submitted: data.development_plan?.is_submitted || false,
                    overall_set_rating: data.overall_set_rating || null,
                    overall_sef_rating: data.overall_sef_rating || null,
                });

                // If there are SET rows available (from a separate endpoint), fetch them
                // This is optional - you can also fetch from /reports/faculty/{id}/breakdown
                if (data.overall_set_rating !== null) {
                    try {
                        const setResponse = await axios.get(`/reports/faculty/${facultyIdNo}/breakdown`, {
                            params: { term_id: term },
                            headers: { 'Accept': 'application/json' }
                        });
                        if (setResponse.data?.set_breakdown) {
                            setSetBreakdown(setResponse.data.set_breakdown);
                        }
                    } catch (e) {
                        // SET breakdown not available, that's fine
                        console.log('SET breakdown not available');
                    }
                }

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
        const loadingToastId = toast.loading('Generating FEDA PDF...');

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const selectedFacultyData = [{
                employee_id_no: facultyIdNo,
                instructor: facultyName,
                department: facultyInfo.college || '',
                course_code: '',
                course_title: '',
                ratings_breakdown: sefData?.ratings_breakdown || [],
                comments: sefData?.comments || '',
                evaluator_name: '',
                evaluator_id: '',
                areas_for_improvement: developmentPlan.areas_for_improvement,
                proposed_activities: developmentPlan.proposed_activities,
                action_plan: developmentPlan.action_plan,
                overall_set_rating: facultyData?.overall_set_rating || null,
                overall_sef_rating: facultyData?.overall_sef_rating || null,
            }];

            const response = await axios.post('/feda/pdf/generate', {
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
                toast.success('FEDA PDF generated successfully!');
                onClose();
            } else {
                toast.dismiss(loadingToastId);
                toast.error('Failed to generate PDF. Please try again.');
            }
        } catch (error) {
            console.error('Error generating FEDA PDF:', error);
            toast.dismiss(loadingToastId);
            toast.error(error.response?.data?.message || 'Failed to generate PDF. Please try again.');
        } finally {
            setIsGenerating(false);
        }
    };

    if (!isOpen) return null;

    const hasCompleteData = facultyData?.has_complete_data || false;
    const isFedaSubmitted = developmentPlan.is_submitted || false;
    const canGenerate = hasCompleteData && isFedaSubmitted;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4">
            <div className="w-full max-w-5xl max-h-[90vh] rounded-2xl border border-slate-300 bg-white shadow-2xl flex flex-col">
                {/* Header */}
                <div className="border-b border-slate-200 px-5 py-4 flex-shrink-0">
                    <div className="flex items-start justify-between">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">FEDA Report Preview</h2>
                            <p className="text-sm text-slate-600 mt-1">
                                Faculty Evaluation and Development Acknowledgment for {facultyName}
                            </p>
                            {schoolYearLabel && (
                                <p className="text-xs text-slate-500 mt-0.5">
                                    School Year: {schoolYearLabel}
                                </p>
                            )}
                            {isFedaSubmitted && (
                                <span className="inline-flex items-center mt-1 px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                    ✓ FEDA Submitted
                                </span>
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
                                <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-amber-600 mx-auto"></div>
                                <p className="mt-4 text-sm text-slate-500">Loading FEDA data...</p>
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
                                        <span className="ml-2 font-medium text-slate-800">{facultyInfo.college}</span>
                                    </div>
                                    <div>
                                        <span className="text-slate-500">Program:</span>
                                        <span className="ml-2 font-medium text-slate-800">{facultyInfo.program}</span>
                                    </div>
                                    <div>
                                        <span className="text-slate-500">Academic Rank:</span>
                                        <span className="ml-2 font-medium text-slate-800">{facultyInfo.academic_rank}</span>
                                    </div>
                                    <div>
                                        <span className="text-slate-500">School Year:</span>
                                        <span className="ml-2 font-medium text-slate-800">{schoolYearLabel}</span>
                                    </div>
                                </div>
                            </div>

                            {/* Evaluation Summary */}
                            <div className="bg-white rounded-lg border border-slate-200">
                                <h3 className="text-sm font-semibold text-slate-700 p-4 bg-slate-50 border-b border-slate-200">
                                    B. Evaluation Summary
                                </h3>
                                <div className="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div className="bg-blue-50 rounded-lg p-3 border border-blue-200">
                                        <h4 className="text-sm font-semibold text-blue-700">Overall SET Rating</h4>
                                        <p className="text-2xl font-bold text-blue-600 mt-1">
                                            {facultyData?.overall_set_rating ? Number(facultyData.overall_set_rating).toFixed(2) + '%' : 'N/A'}
                                        </p>
                                        <p className="text-xs text-blue-500 mt-1">Student Evaluation of Teaching</p>
                                    </div>
                                    <div className="bg-emerald-50 rounded-lg p-3 border border-emerald-200">
                                        <h4 className="text-sm font-semibold text-emerald-700">Overall SEF Rating</h4>
                                        <p className="text-2xl font-bold text-emerald-600 mt-1">
                                            {facultyData?.overall_sef_rating ? Number(facultyData.overall_sef_rating).toFixed(2) + '%' : 'N/A'}
                                        </p>
                                        <p className="text-xs text-emerald-500 mt-1">Supervisor Evaluation of Faculty</p>
                                    </div>
                                </div>
                            </div>

                            {/* Supervisor Comments */}
                            {sefData?.comments && (
                                <div className="bg-white rounded-lg border border-slate-200">
                                    <h3 className="text-sm font-semibold text-slate-700 p-4 bg-slate-50 border-b border-slate-200">
                                        Supervisor Comments
                                    </h3>
                                    <div className="p-4">
                                        <div className="text-sm text-slate-700 bg-slate-50 p-3 rounded-lg whitespace-pre-wrap">
                                            {sefData.comments}
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Development Plan */}
                            <div className="bg-white rounded-lg border border-slate-200">
                                <h3 className="text-sm font-semibold text-slate-700 p-4 bg-slate-50 border-b border-slate-200">
                                    C. Development Plan
                                    {isFedaSubmitted && (
                                        <span className="ml-2 text-xs font-normal text-green-600">
                                            (Submitted)
                                        </span>
                                    )}
                                </h3>
                                <div className="p-4 space-y-4">
                                    <div>
                                        <h4 className="text-sm font-medium text-slate-700 mb-1">Areas for Improvement</h4>
                                        <div className="text-sm text-slate-600 bg-slate-50 p-3 rounded-lg whitespace-pre-wrap min-h-[60px]">
                                            {developmentPlan.areas_for_improvement || 'No areas for improvement specified.'}
                                        </div>
                                    </div>
                                    <div>
                                        <h4 className="text-sm font-medium text-slate-700 mb-1">Proposed Learning and Development Activities</h4>
                                        <div className="text-sm text-slate-600 bg-slate-50 p-3 rounded-lg whitespace-pre-wrap min-h-[60px]">
                                            {developmentPlan.proposed_activities || 'No proposed activities specified.'}
                                        </div>
                                    </div>
                                    <div>
                                        <h4 className="text-sm font-medium text-slate-700 mb-1">Action Plan</h4>
                                        <div className="text-sm text-slate-600 bg-slate-50 p-3 rounded-lg whitespace-pre-wrap min-h-[60px]">
                                            {developmentPlan.action_plan || 'No action plan specified.'}
                                        </div>
                                    </div>
                                    {isFedaSubmitted && developmentPlan.submitted_at && (
                                        <div className="text-xs text-slate-500 border-t border-slate-100 pt-3">
                                            Submitted on: {new Date(developmentPlan.submitted_at).toLocaleString()}
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Status Banner */}
                            {!canGenerate && (
                                <div className="rounded-md bg-yellow-50 p-3 text-sm text-yellow-700 border border-yellow-200">
                                    ⚠️ Cannot generate FEDA report. Missing requirements:
                                    {!hasCompleteData && ' SET and/or SEF data missing.'}
                                    {!isFedaSubmitted && ' FEDA form not submitted.'}
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
                        disabled={isGenerating || isLoading || !canGenerate}
                        className="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {isGenerating ? (
                            <>
                                <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Generating FEDA PDF...
                            </>
                        ) : (
                            'Generate FEDA PDF'
                        )}
                    </button>
                </div>
            </div>
        </div>
    );
}