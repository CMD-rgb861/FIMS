import React, { useState, useEffect } from 'react';
import axios from 'axios';

export default function SetPrintButtonModal({ 
    isOpen, 
    onClose, 
    subjects = [], 
    facultyName = '',
    facultyIdNo = '',
    term = ''
}) {
    const [selectedSubjects, setSelectedSubjects] = useState([]);
    const [isGenerating, setIsGenerating] = useState(false);
    const [error, setError] = useState('');
    const [subjectsWithSubmissions, setSubjectsWithSubmissions] = useState([]);
    const [isLoadingDetails, setIsLoadingDetails] = useState(false);

    // Fetch submissions for each subject when modal opens
    useEffect(() => {
        if (isOpen && subjects.length > 0 && facultyName) {
            fetchAllSubmissionsData();
        }
    }, [isOpen, subjects, term, facultyName, facultyIdNo]);

    const fetchAllSubmissionsData = async () => {
        setIsLoadingDetails(true);
        setError('');
        
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            // Fetch submissions for each subject
            const subjectsWithData = await Promise.all(
                subjects.map(async (subject) => {
                    try {
                        let allStudents = [];
                        let currentPage = 1;
                        let lastPage = 1;
                        let allSubmissionIds = [];
                        
                        // First, fetch all submissions (without answers)
                        do {
                            const response = await axios.get('/submissions', {
                                params: {
                                    course_code: subject.course_code,
                                    course_description: subject.course_description,
                                    year_section: subject.year_section,
                                    instructor_id: facultyIdNo,
                                    term_id: term,
                                    page: currentPage,
                                    per_page: 100
                                },
                                headers: {
                                    'X-CSRF-TOKEN': csrfToken || '',
                                    'Accept': 'application/json'
                                }
                            });
                            
                            const submissionData = response.data;
                            const students = submissionData.students || [];
                            allStudents = [...allStudents, ...students];
                            
                            // Collect submission IDs for batch answer fetching
                            students.forEach(student => {
                                if (student.submission_id) {
                                    allSubmissionIds.push(student.submission_id);
                                }
                            });
                            
                            lastPage = submissionData.pagination?.last_page || 1;
                            currentPage++;
                            
                        } while (currentPage <= lastPage);
                        
                        // Fetch ALL answers in ONE batch request
                        if (allSubmissionIds.length > 0) {
                            const answersResponse = await axios.post('/answers/batch', {
                                submission_ids: allSubmissionIds,
                                term_id: term
                            }, {
                                headers: {
                                    'X-CSRF-TOKEN': csrfToken || '',
                                    'Accept': 'application/json'
                                }
                            });
                            
                            const answersBySubmission = answersResponse.data.answers_by_submission || {};
                            
                            // Merge answers with students
                            allStudents = allStudents.map(student => {
                                const answersData = answersBySubmission[student.submission_id] || {};
                                return {
                                    ...student,
                                    ratings: answersData.ratings || Array(15).fill(4),
                                    comments: answersData.comment || '',
                                    rating_percentage: answersData.rating_percentage || student.rating_percentage,
                                    total_score: answersData.total_score || student.total_score,
                                    submitted_at: answersData.submitted_at || student.submitted_at
                                };
                            });
                        }
                        
                        return {
                            ...subject,
                            students: allStudents,
                            total_students: allStudents.length,
                            course_description: allStudents.length > 0 ? (allStudents[0]?.course_description || subject.course_description) : subject.course_description
                        };
                    } catch (err) {
                        console.error(`Error fetching submissions for ${subject.course_code}:`, err);
                        return {
                            ...subject,
                            students: [],
                            total_students: 0,
                            error: err.response?.data?.message || 'Failed to load submissions'
                        };
                    }
                })
            );
            
            setSubjectsWithSubmissions(subjectsWithData);
        } catch (err) {
            console.error('Error fetching subject data:', err);
            setError('Failed to load submission data. Please try again.');
            const subjectsWithDefault = subjects.map(subject => ({
                ...subject,
                students: [],
                total_students: 0
            }));
            setSubjectsWithSubmissions(subjectsWithDefault);
        } finally {
            setIsLoadingDetails(false);
        }
    };

    // Reset when modal closes
    useEffect(() => {
        if (!isOpen) {
            setSelectedSubjects([]);
            setSubjectsWithSubmissions([]);
            setError('');
            setIsGenerating(false);
        }
    }, [isOpen]);

    const handleSubjectToggle = (subjectId) => {
        setSelectedSubjects(prev => 
            prev.includes(subjectId)
                ? prev.filter(id => id !== subjectId)
                : [...prev, subjectId]
        );
    };

    const handleSelectAll = () => {
        if (selectedSubjects.length === subjectsWithSubmissions.length) {
            setSelectedSubjects([]);
        } else {
            setSelectedSubjects(subjectsWithSubmissions.map(s => s.id));
        }
    };

    const handleGeneratePDF = async () => {
        if (selectedSubjects.length === 0) {
            setError('Please select at least one subject to print.');
            return;
        }

        setError('');
        setIsGenerating(true);

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const selectedSubjectsData = subjectsWithSubmissions.filter(s => selectedSubjects.includes(s.id));
            
            const response = await axios.post('/student-evaluation/pdf/batch-generate', {
                faculty_id: String(facultyIdNo),
                faculty_name: String(facultyName),
                term: term ? String(term) : null,
                subjects: selectedSubjectsData
            }, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                    'Accept': 'application/json'
                },
                timeout: 300000 // 5 minute timeout for large PDFs
            });

            const pdfUrl = response.data.pdf_url;
            
            if (pdfUrl) {
                window.open(pdfUrl, '_blank');
                onClose();
            } else {
                setError('Failed to generate PDF. Please try again.');
            }
        } catch (error) {
            console.error('Error generating PDF:', error);
            setError(error.response?.data?.message || 'Failed to generate PDF. Please try again.');
        } finally {
            setIsGenerating(false);
        }
    };

    if (!isOpen) return null;

    const displaySubjects = subjectsWithSubmissions.length > 0 ? subjectsWithSubmissions : subjects;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4">
            <div className="w-full max-w-4xl rounded-2xl border border-slate-300 bg-white shadow-2xl">
                {/* Header */}
                <div className="border-b border-slate-200 px-5 py-4">
                    <div className="flex items-start justify-between">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">Print Evaluation Results</h2>
                            <p className="text-sm text-slate-600 mt-1">
                                Select subjects to generate PDF for {facultyName}
                            </p>
                            {term && (
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Term: {term}
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
                    {isLoadingDetails && (
                        <div className="mb-4 rounded-md bg-blue-50 p-3 text-sm text-blue-700 border border-blue-200">
                            <div className="flex items-center gap-2">
                                <svg className="animate-spin h-4 w-4 text-blue-700" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Loading student submissions...
                            </div>
                        </div>
                    )}

                    {error && (
                        <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700 border border-red-200">
                            {error}
                        </div>
                    )}

                    {/* Select All Button */}
                    {displaySubjects.length > 0 && !isLoadingDetails && (
                        <div className="mb-4 flex items-center justify-between">
                            <button
                                onClick={handleSelectAll}
                                className="text-sm text-blue-600 hover:text-blue-700 font-medium"
                            >
                                {selectedSubjects.length === displaySubjects.length ? 'Deselect All' : 'Select All'}
                            </button>
                            <span className="text-xs text-slate-500">
                                {selectedSubjects.length} of {displaySubjects.length} selected
                            </span>
                        </div>
                    )}

                    {/* Subjects List with Student Count */}
                    <div className="max-h-96 overflow-y-auto border border-slate-200 rounded-lg">
                        {displaySubjects.length === 0 ? (
                            <div className="p-8 text-center text-slate-500">
                                No subjects available to print.
                            </div>
                        ) : (
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50 sticky top-0">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 w-12">
                                            <input
                                                type="checkbox"
                                                checked={selectedSubjects.length === displaySubjects.length && displaySubjects.length > 0}
                                                onChange={handleSelectAll}
                                                disabled={isLoadingDetails}
                                                className="rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50"
                                            />
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600">Course Code</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600">Subject Description</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600">Year/Section</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 text-center">Students Evaluated</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 bg-white">
                                    {displaySubjects.map((subject) => (
                                        <tr key={subject.id} className="hover:bg-slate-50">
                                            <td className="px-4 py-3">
                                                <input
                                                    type="checkbox"
                                                    checked={selectedSubjects.includes(subject.id)}
                                                    onChange={() => handleSubjectToggle(subject.id)}
                                                    disabled={isLoadingDetails || subject.total_students === 0}
                                                    className="rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50"
                                                />
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-700 font-medium">
                                                {subject.course_code || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-600">
                                                {subject.course_description || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-600">
                                                {subject.year_section || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-600 text-center font-semibold">
                                                {isLoadingDetails ? (
                                                    <div className="flex justify-center">
                                                        <div className="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-blue-600"></div>
                                                    </div>
                                                ) : (
                                                    <span className={subject.total_students > 0 ? 'text-green-600' : 'text-slate-400'}>
                                                        {subject.total_students || '0'}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                    
                    {/* Show warning if no students */}
                    {!isLoadingDetails && subjectsWithSubmissions.length > 0 && 
                     subjectsWithSubmissions.every(s => s.total_students === 0) && (
                        <div className="mt-4 rounded-md bg-yellow-50 p-3 text-sm text-yellow-700 border border-yellow-200">
                            No student submissions found for these subjects.
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex items-center rounded-md bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-300"
                        disabled={isGenerating}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={handleGeneratePDF}
                        disabled={isGenerating || selectedSubjects.length === 0 || isLoadingDetails}
                        className="inline-flex items-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {isGenerating ? (
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
                                Generate PDF
                            </>
                        )}
                    </button>
                </div>
            </div>
        </div>
    );
}