import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { toast } from 'react-toastify';

export default function FEDAResultModal({
    isOpen,
    onClose,
    faculty = null,
    termId = null,
    termLabel = null,
    isViewMode = true,
}) {
    const [loading, setLoading] = useState(false);
    const [formData, setFormData] = useState(null);
    const [facultyInfo, setFacultyInfo] = useState(null);
    const [developmentPlan, setDevelopmentPlan] = useState({
        areas_for_improvement: '',
        proposed_activities: '',
        action_plan: ''
    });
    const [isGenerating, setIsGenerating] = useState(false);
    const [isSubmitted, setIsSubmitted] = useState(false);
    const requestIdRef = useRef(0);

    const getInitialFormState = () => ({
        overall_set_rating: null,
        overall_sef_rating: null,
        comments: '',
    });

    const resetModalState = () => {
        setLoading(false);
        setFormData(getInitialFormState());
        setFacultyInfo(null);
        setDevelopmentPlan({
            areas_for_improvement: '',
            proposed_activities: '',
            action_plan: ''
        });
        setIsSubmitted(false);
    };

    const facultyId = faculty?.id_no ?? faculty?.instructor_id_no ?? faculty?.id ?? null;

    useEffect(() => {
        if (!isOpen) {
            resetModalState();
            return;
        }

        if (!facultyId || !termId) {
            resetModalState();
            return;
        }

        requestIdRef.current += 1;
        fetchFacultyData(requestIdRef.current);
    }, [isOpen, facultyId, termId]);

    const fetchFacultyData = async (currentRequestId) => {
        setLoading(true);
        try {
            const response = await fetch(`/feda/faculty/${facultyId}/data?term_id=${termId}`);
            const data = await response.json();

            if (currentRequestId !== requestIdRef.current) {
                return;
            }
            
            if (data.success && data.has_data) {
                if (data.faculty_info) {
                    setFacultyInfo(data.faculty_info);
                }
                
                setFormData({
                    overall_set_rating: data.overall_set_rating,
                    overall_sef_rating: data.overall_sef_rating,
                    comments: data.comments || '',
                });

                if (data.development_plan) {
                    setDevelopmentPlan({
                        areas_for_improvement: data.development_plan.areas_for_improvement || '',
                        proposed_activities: data.development_plan.proposed_activities || '',
                        action_plan: data.development_plan.action_plan || ''
                    });
                    setIsSubmitted(data.development_plan.is_submitted || false);
                }
            } else {
                setFormData(getInitialFormState());
                setFacultyInfo({
                    name: faculty?.name || 'N/A',
                    id_no: facultyId || 'N/A',
                    college: faculty?.college || 'N/A',
                    program: faculty?.program || 'N/A',
                    academic_rank: faculty?.academic_rank || 'N/A',
                });
            }
        } catch (error) {
            if (currentRequestId !== requestIdRef.current) {
                return;
            }
            console.error('Error fetching faculty data:', error);
            setFormData(getInitialFormState());
            setFacultyInfo({
                name: faculty?.name || 'N/A',
                id_no: facultyId || 'N/A',
                college: faculty?.college || 'N/A',
                program: faculty?.program || 'N/A',
                academic_rank: faculty?.academic_rank || 'N/A',
            });
        } finally {
            if (currentRequestId === requestIdRef.current) {
                setLoading(false);
            }
        }
    };

    const handleGeneratePDF = async () => {
        if (!faculty) return;
        
        setIsGenerating(true);
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            // Pass development plan data as query parameters
            const params = new URLSearchParams({
                term_id: termId,
                areas_for_improvement: developmentPlan.areas_for_improvement || '',
                proposed_activities: developmentPlan.proposed_activities || '',
                action_plan: developmentPlan.action_plan || ''
            });
            
            const response = await fetch(`/feda/pdf-url/${faculty.id_no}?${params.toString()}`, {
                headers: {
                    'X-CSRF-TOKEN': csrfToken || '',
                    'Accept': 'application/json'
                }
            });
            
            const data = await response.json();
            
            if (data.pdf_url) {
                window.open(data.pdf_url, '_blank');
            } else {
                toast.error('Failed to generate PDF URL. Please try again.', {
                    position: "top-right",
                    autoClose: 5000,
                });
            }
        } catch (error) {
            console.error('Error generating FEDA PDF:', error);
            toast.error('Failed to generate FEDA form. Please try again.', {
                position: "top-right",
                autoClose: 5000,
            });
        } finally {
            setIsGenerating(false);
        }
    };

    if (!isOpen) return null;

    const info = facultyInfo || faculty || {};

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex min-h-screen items-center justify-center p-4">
                <div 
                    className="fixed inset-0 bg-black/50 transition-opacity" 
                    onClick={onClose}
                />

                <div className="relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-slate-200 px-6 py-3">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">
                                View FEDA Form
                            </h2>
                            <div className="flex items-center gap-3 mt-0.5">
                                <p className="text-sm text-slate-500">
                                    {info.name} - {info.id_no}
                                </p>
                                {termLabel && (
                                    <span className="text-xs text-slate-400">• {termLabel}</span>
                                )}
                                {isSubmitted && (
                                    <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">
                                        Submitted
                                    </span>
                                )}
                            </div>
                        </div>
                        <button
                            onClick={onClose}
                            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
                        >
                            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {/* Body */}
                    <div className="flex-1 min-h-0 overflow-y-auto px-6 py-4">
                        {loading ? (
                            <div className="flex items-center justify-center py-8">
                                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                                <span className="ml-3 text-sm text-slate-500">Loading faculty data...</span>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {/* Faculty Information - Compact Grid */}
                                <div>
                                    <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Faculty Information</h3>
                                    <div className="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
                                        <div>
                                            <p className="text-xs text-slate-500">Name</p>
                                            <p className="font-medium text-slate-900">{info.name || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">Employee ID</p>
                                            <p className="font-medium text-slate-900">{info.id_no || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">College</p>
                                            <p className="font-medium text-slate-900">{info.college || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">Program</p>
                                            <p className="font-medium text-slate-900">{info.program || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">Academic Rank</p>
                                            <p className="font-medium text-slate-900">{info.academic_rank || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">Term</p>
                                            <p className="font-medium text-slate-900">{termLabel || 'N/A'}</p>
                                        </div>
                                    </div>
                                </div>

                                {/* Evaluation Summary - Compact Cards */}
                                <div>
                                    <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Evaluation Summary</h3>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="rounded-lg border border-slate-200 p-3">
                                            <p className="text-xs text-slate-500">Overall SET Rating</p>
                                            <p className="text-xl font-bold text-blue-600">
                                                {formData?.overall_set_rating !== null && formData?.overall_set_rating !== undefined 
                                                    ? `${formData.overall_set_rating}%` 
                                                    : 'N/A'}
                                            </p>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 p-3">
                                            <p className="text-xs text-slate-500">Overall SEF Rating</p>
                                            <p className="text-xl font-bold text-emerald-600">
                                                {formData?.overall_sef_rating !== null && formData?.overall_sef_rating !== undefined 
                                                    ? `${formData.overall_sef_rating}%` 
                                                    : 'N/A'}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                {/* Comments - Compact */}
                                {formData?.comments && (
                                    <div>
                                        <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Comments</h3>
                                        <div className="rounded-lg border border-slate-200 p-3">
                                            <p className="text-sm text-slate-700 whitespace-pre-wrap">{formData.comments}</p>
                                        </div>
                                    </div>
                                )}

                                {/* Development Plan - Compact */}
                                <div>
                                    <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Development Plan</h3>
                                    <div className="space-y-3">
                                        <div className="rounded-lg border border-slate-200 p-3">
                                            <p className="text-xs font-medium text-slate-700 mb-1">Areas for Improvement</p>
                                            <p className="text-sm text-slate-600 whitespace-pre-wrap">
                                                {developmentPlan.areas_for_improvement || 'No data provided'}
                                            </p>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 p-3">
                                            <p className="text-xs font-medium text-slate-700 mb-1">Proposed Learning and Development Activities</p>
                                            <p className="text-sm text-slate-600 whitespace-pre-wrap">
                                                {developmentPlan.proposed_activities || 'No data provided'}
                                            </p>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 p-3">
                                            <p className="text-xs font-medium text-slate-700 mb-1">Action Plan</p>
                                            <p className="text-sm text-slate-600 whitespace-pre-wrap">
                                                {developmentPlan.action_plan || 'No data provided'}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                {/* Acknowledgment - Compact */}
                                <div>
                                    <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Acknowledgment</h3>
                                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                                        <p className="text-sm text-amber-800">
                                            I acknowledge that I have received and reviewed the faculty evaluation conducted 
                                            for the period mentioned above. I understand that my signature below does not 
                                            necessarily indicate agreement with the evaluation but confirms that I have been 
                                            given the opportunity to discuss it with my supervisor.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Footer */}
                    <div className="flex flex-shrink-0 items-center justify-end gap-3 border-t border-slate-200 px-6 py-3 bg-slate-50">
                        <button
                            onClick={handleGeneratePDF}
                            disabled={isGenerating}
                            className="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {isGenerating ? (
                                <>
                                    <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Generating...
                                </>
                            ) : (
                                <>
                                    <svg className="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    Generate FEDA PDF
                                </>
                            )}
                        </button>
                        <button
                            onClick={onClose}
                            className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}