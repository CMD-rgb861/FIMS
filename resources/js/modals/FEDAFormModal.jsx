import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { toast } from 'react-toastify';

export default function FEDAFormModal({
    isOpen,
    onClose,
    onSubmitted,
    faculty = null,
    termId = null,
    termLabel = null,
    isViewMode = false,
}) {
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [formData, setFormData] = useState(null);
    const [facultyInfo, setFacultyInfo] = useState(null);
    const [developmentPlan, setDevelopmentPlan] = useState({
        areas_for_improvement: '',
        proposed_activities: '',
        action_plan: ''
    });
    const [isSubmitted, setIsSubmitted] = useState(false);
    const requestIdRef = useRef(0);

    const getInitialFormState = () => ({
        overall_set_rating: null,
        overall_sef_rating: null,
        ratings_breakdown: null,
    });

    const resetModalState = () => {
        setLoading(false);
        setSaving(false);
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
        resetModalState();
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
                    ratings_breakdown: data.ratings_breakdown || null
                });

                if (data.development_plan) {
                    setDevelopmentPlan({
                        areas_for_improvement: data.development_plan.areas_for_improvement || '',
                        proposed_activities: data.development_plan.proposed_activities || '',
                        action_plan: data.development_plan.action_plan || ''
                    });
                    setIsSubmitted(isViewMode ? (data.development_plan.is_submitted || false) : false);
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

    const handleSave = async () => {
        if (!faculty || !termId) return;
        
        // Frontend validation
        if (!developmentPlan.areas_for_improvement.trim()) {
            toast.warning('Please enter areas for improvement.', {
                position: "top-right",
                autoClose: 3000,
            });
            return;
        }
        
        if (!developmentPlan.proposed_activities.trim()) {
            toast.warning('Please enter proposed learning and development activities.', {
                position: "top-right",
                autoClose: 3000,
            });
            return;
        }
        
        if (!developmentPlan.action_plan.trim()) {
            toast.warning('Please enter an action plan.', {
                position: "top-right",
                autoClose: 3000,
            });
            return;
        }
        
        setSaving(true);
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const response = await axios.post('/feda/save', {
                id_no: faculty.id_no,
                term_id: termId,
                areas_for_improvement: developmentPlan.areas_for_improvement,
                proposed_activities: developmentPlan.proposed_activities,
                action_plan: developmentPlan.action_plan,
                submit: true,
            }, {
                headers: {
                    'X-CSRF-TOKEN': csrfToken || '',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });

            if (response.data.success) {
                setIsSubmitted(true);
                toast.success('FEDA form submitted successfully!', {
                    position: "top-right",
                    autoClose: 3000,
                });
                
                // Call the onSubmitted callback to refresh the parent
                if (onSubmitted) {
                    onSubmitted();
                }
                
                onClose();
            }
        } catch (error) {
            console.error('Error saving development plan:', error);
            toast.error(error.response?.data?.message || 'Failed to save. Please try again.', {
                position: "top-right",
                autoClose: 5000,
            });
        } finally {
            setSaving(false);
        }
    };

    const handleInputChange = (field, value) => {
        setDevelopmentPlan(prev => ({
            ...prev,
            [field]: value
        }));
    };

    if (!isOpen) return null;

    const isReadOnly = isViewMode || isSubmitted;
    const info = facultyInfo || faculty || {};

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex min-h-screen items-center justify-center p-4">
                <div 
                    className="fixed inset-0 bg-black/50 transition-opacity" 
                    onClick={onClose}
                />

                <div className="relative w-full max-w-4xl rounded-2xl bg-white shadow-xl">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <div>
                            <h2 className="text-xl font-semibold text-slate-900">
                                {isReadOnly ? 'View FEDA Form' : 'Faculty Evaluation and Development Acknowledgment'}
                            </h2>
                            {faculty && (
                                <p className="mt-1 text-sm text-slate-500">
                                    {info.name} - {info.id_no}
                                </p>
                            )}
                            {termLabel && (
                                <p className="text-xs text-slate-400">
                                    {termLabel}
                                </p>
                            )}
                            {isSubmitted && (
                                <span className="mt-1 inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">
                                    Submitted
                                </span>
                            )}
                        </div>
                        <button
                            onClick={onClose}
                            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
                        >
                            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {/* Body */}
                    <div className="max-h-[calc(100vh-200px)] overflow-y-auto px-6 py-4">
                        {loading ? (
                            <div className="flex items-center justify-center py-12">
                                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                                <span className="ml-3 text-sm text-slate-500">Loading faculty data...</span>
                            </div>
                        ) : (
                            <>
                                {/* Faculty Information */}
                                <div className="mb-6">
                                    <h3 className="text-sm font-semibold text-slate-900 mb-3">A. Faculty Information</h3>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 rounded-lg bg-slate-50 p-4">
                                        <div>
                                            <p className="text-xs text-slate-500">Name</p>
                                            <p className="text-sm font-medium text-slate-900">{info.name || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">Employee ID</p>
                                            <p className="text-sm font-medium text-slate-900">{info.id_no || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">College</p>
                                            <p className="text-sm font-medium text-slate-900">{info.college || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">Program</p>
                                            <p className="text-sm font-medium text-slate-900">{info.program || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">Academic Rank</p>
                                            <p className="text-sm font-medium text-slate-900">{info.academic_rank || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-slate-500">Term</p>
                                            <p className="text-sm font-medium text-slate-900">{termLabel || 'N/A'}</p>
                                        </div>
                                    </div>
                                </div>

                                {/* Evaluation Summary - SET and SEF */}
                                <div className="mb-6">
                                    <h3 className="text-sm font-semibold text-slate-900 mb-3">B. Faculty Evaluation Summary</h3>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div className="rounded-lg border border-slate-200 p-4">
                                            <p className="text-xs text-slate-500">Overall SET Rating</p>
                                            <p className="text-2xl font-bold text-blue-600">
                                                {formData?.overall_set_rating !== null && formData?.overall_set_rating !== undefined 
                                                    ? `${formData.overall_set_rating}%` 
                                                    : 'N/A'}
                                            </p>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 p-4">
                                            <p className="text-xs text-slate-500">Overall SEF Rating</p>
                                            <p className="text-2xl font-bold text-emerald-600">
                                                {formData?.overall_sef_rating !== null && formData?.overall_sef_rating !== undefined 
                                                    ? `${formData.overall_sef_rating}%` 
                                                    : 'N/A'}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                {/* Development Plan */}
                                <div className="mb-6">
                                    <h3 className="text-sm font-semibold text-slate-900 mb-3">C. Development Plan</h3>
                                    <div className="space-y-4">
                                        <div>
                                            <label className="block text-xs font-medium text-slate-700 mb-1">
                                                Areas for Improvement
                                            </label>
                                            <textarea
                                                rows={3}
                                                value={developmentPlan.areas_for_improvement}
                                                onChange={(e) => handleInputChange('areas_for_improvement', e.target.value)}
                                                readOnly={isReadOnly}
                                                className={`w-full rounded-lg border px-3 py-2 text-sm ${
                                                    isReadOnly 
                                                        ? 'border-slate-200 bg-slate-50 text-slate-700' 
                                                        : 'border-slate-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500'
                                                }`}
                                                placeholder={isReadOnly ? '' : 'Enter areas for improvement...'}
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-medium text-slate-700 mb-1">
                                                Proposed Learning and Development Activities
                                            </label>
                                            <textarea
                                                rows={3}
                                                value={developmentPlan.proposed_activities}
                                                onChange={(e) => handleInputChange('proposed_activities', e.target.value)}
                                                readOnly={isReadOnly}
                                                className={`w-full rounded-lg border px-3 py-2 text-sm ${
                                                    isReadOnly 
                                                        ? 'border-slate-200 bg-slate-50 text-slate-700' 
                                                        : 'border-slate-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500'
                                                }`}
                                                placeholder={isReadOnly ? '' : 'Enter proposed activities...'}
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-medium text-slate-700 mb-1">
                                                Action Plan
                                            </label>
                                            <textarea
                                                rows={3}
                                                value={developmentPlan.action_plan}
                                                onChange={(e) => handleInputChange('action_plan', e.target.value)}
                                                readOnly={isReadOnly}
                                                className={`w-full rounded-lg border px-3 py-2 text-sm ${
                                                    isReadOnly 
                                                        ? 'border-slate-200 bg-slate-50 text-slate-700' 
                                                        : 'border-slate-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500'
                                                }`}
                                                placeholder={isReadOnly ? '' : 'Enter action plan...'}
                                            />
                                        </div>
                                    </div>
                                </div>

                                {/* Acknowledgment */}
                                <div className="mb-6">
                                    <h3 className="text-sm font-semibold text-slate-900 mb-3">D. Acknowledgment</h3>
                                    <div className="rounded-lg border border-slate-200 bg-amber-50 p-4">
                                        <p className="text-sm text-amber-800">
                                            I acknowledge that I have received and reviewed the faculty evaluation conducted 
                                            for the period mentioned above. I understand that my signature below does not 
                                            necessarily indicate agreement with the evaluation but confirms that I have been 
                                            given the opportunity to discuss it with my supervisor.
                                        </p>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>

                    {/* Footer */}
                    <div className="flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
                        <button
                            onClick={onClose}
                            className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
                            disabled={saving}
                        >
                            Close
                        </button>
                        {!isReadOnly && (
                            <button
                                onClick={handleSave}
                                disabled={saving || loading}
                                className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {saving ? (
                                    <>
                                        <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Saving...
                                    </>
                                ) : (
                                    'Save & Submit'
                                )}
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}