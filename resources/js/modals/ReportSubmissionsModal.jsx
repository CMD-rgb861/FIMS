import React, { useState, useEffect } from 'react';
import axios from 'axios';
import StudentAnswersDrawer from './StudentAnswersDrawer';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';

export default function ReportSubmissionsModal({
    isOpen,
    onClose,
    courseCode,
    courseDesc,
    yearSection,
    instructorId,
    termId,
    instructorName = '',
}) {
    const [students, setStudents] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');
    const [selectedStudent, setSelectedStudent] = useState(null);
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [isInitialLoading, setIsInitialLoading] = useState(false);
    const [isPageLoading, setIsPageLoading] = useState(false);
    const [apiCourseDescription, setApiCourseDescription] = useState(null);
    const [isPrinting, setIsPrinting] = useState(false);
    const [printingStudentId, setPrintingStudentId] = useState(null); // Track which student is printing

    // PAGINATION
    const [currentPage, setCurrentPage] = useState(1);

    const [pagination, setPagination] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
        from: 0,
        to: 0,
    });

    const itemsPerPage = pagination.per_page || 10;
    const totalPages = pagination.last_page || 1;

    // FETCH DATA
    useEffect(() => {
        if (isOpen && courseCode) {
            fetchSubmissions();
        }
    }, [
        isOpen,
        courseCode,
        yearSection,
        instructorId,
        termId,
        currentPage,
    ]);

    const fetchSubmissions = async () => {
        setError('');

        if (students.length === 0) {
            setIsInitialLoading(true);
        } else {
            setIsPageLoading(true);
        }

        try {
            const response = await axios.get('/submissions', {
                params: {
                    course_code: courseCode,
                    course_description: courseDesc || courseCode,
                    year_section: yearSection,
                    instructor_id: instructorId,
                    term_id: termId,
                    page: currentPage,
                    per_page: itemsPerPage,
                },
            });

            if (response.data?.students !== undefined) {
                setStudents(response.data.students || []);
                setPagination(response.data.pagination || pagination);
                if (response.data.course_description) {
                    setApiCourseDescription(response.data.course_description);
                }
            } else {
                setError('Unexpected response format from server');
            }
        } catch (err) {
            setError(
                err.response?.data?.message ||
                err.message ||
                'Failed to load student submissions'
            );
        } finally {
            setIsInitialLoading(false);
            setIsPageLoading(false);
        }
    };

    // VIEW STUDENT
    const handleViewStudent = (student) => {
        setSelectedStudent(student);
        setIsDrawerOpen(true);
    };

    // Helper function to convert answers to ratings array
    const convertAnswersToRatings = (answers) => {
        const ratings = Array(15).fill(4);
        
        const questionMap = {
            's0_i0': 0, 's0_i1': 1, 's0_i2': 2, 's0_i3': 3, 's0_i4': 4, 's0_i5': 5,
            's1_i0': 6, 's1_i1': 7, 's1_i2': 8, 's1_i3': 9, 's1_i4': 10,
            's2_i0': 11, 's2_i1': 12, 's2_i2': 13, 's2_i3': 14
        };
        
        answers.forEach(answer => {
            const questionKey = answer.question_id || answer.question_key;
            const rating = answer.rating_value || answer.selected_option || answer.score;
            
            if (questionMap[questionKey] !== undefined && rating >= 1 && rating <= 5) {
                ratings[questionMap[questionKey]] = parseInt(rating);
            }
        });
        
        return ratings;
    };

    // PRINT INDIVIDUAL SUBMISSION
    const handlePrintSubmission = async (student) => {
        setPrintingStudentId(student.submission_id);
        setIsPrinting(true);
        
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            // Fetch the answers for this submission
            const answersResponse = await axios.get(`/answers/${student.submission_id}`, {
                params: { 
                    term_id: termId ? Number(termId) : undefined
                },
                headers: {
                    'X-CSRF-TOKEN': csrfToken || '',
                    'Accept': 'application/json'
                }
            });
            
            const answersData = answersResponse.data;
            const answers = answersData.answers || [];
            
            // Convert answers to ratings array (15 items)
            const ratings = convertAnswersToRatings(answers);
            const comment = answersData.submission?.comment || '';
            
            // Prepare data for PDF generation
            const pdfData = {
                faculty_id: instructorId,
                faculty_name: instructorName,
                term: termId,
                course_code: courseCode,
                course_title: apiCourseDescription || courseDesc,
                ratings: ratings,
                comments: comment,  // Make sure this matches the controller's expected key
                evaluator_name: student.student_name,
                evaluator_id: student.student_id_number,
                date: student.submitted_at ? new Date(student.submitted_at).toLocaleDateString() : new Date().toLocaleDateString(),
                college: 'College of Arts and Sciences', // Add default college
                program_level: 'Undergraduate' // Add default program level
            };
            
            console.log('Sending PDF data:', pdfData); // Debug log
            
            const response = await axios.post('/student-evaluation/pdf/generate', pdfData, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                    'Accept': 'application/json'
                },
                timeout: 60000 // 60 second timeout
            });
            
            const pdfUrl = response.data.pdf_url;
            
            if (pdfUrl) {
                window.open(pdfUrl, '_blank');
            } else {
                setError('Failed to generate PDF. Please try again.');
                setTimeout(() => setError(''), 3000);
            }
        } catch (err) {
            console.error('Error generating PDF:', err);
            const errorMessage = err.response?.data?.message || err.message || 'Failed to generate PDF. Please try again.';
            setError(errorMessage);
            setTimeout(() => setError(''), 3000);
        } finally {
            setIsPrinting(false);
            setPrintingStudentId(null);
        }
    };

    // CLOSE DRAWER
    const handleCloseDrawer = () => {
        setIsDrawerOpen(false);
        setSelectedStudent(null);
        fetchSubmissions();
    };

    // PAGE CHANGE
    const handlePageChange = (pageNumber) => {
        if (pageNumber >= 1 && pageNumber <= totalPages) {
            setCurrentPage(pageNumber);
        }
    };

    return (
        <>
            <Modal
                show={isOpen}
                onClose={onClose}
                closeable={false}
                maxWidth="2xl"
            >
                <div
                    className="flex flex-col bg-white rounded-lg overflow-hidden"
                    style={{
                        width: '800px',
                        maxWidth: '90vw',
                        height: '700px',
                        maxHeight: '90vh',
                    }}
                >
                    {/* HEADER */}
                    <div className="flex-shrink-0 border-b border-gray-200 px-6 py-4 bg-white">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="text-lg font-medium text-gray-900">
                                    Student Submissions
                                </h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    {courseCode} • {apiCourseDescription || courseDesc || 'N/A'} • {yearSection}
                                </p>
                            </div>
                            {isPrinting && (
                                <div className="text-sm text-green-600">
                                    Generating PDF...
                                </div>
                            )}
                        </div>
                    </div>

                    {/* MAIN CONTENT */}
                    <div className="flex-1 min-h-0 flex flex-col overflow-hidden">

                        {/* LOADING */}
                        {isInitialLoading && (
                            <div className="flex-1 flex items-center justify-center text-blue-700">
                                Loading students...
                            </div>
                        )}

                        {/* ERROR */}
                        {error && (
                            <div className="flex-1 flex items-center justify-center text-red-700 px-6 text-center">
                                Error: {error}
                            </div>
                        )}

                        {/* EMPTY */}
                        {!isInitialLoading && !error && students.length === 0 && (
                            <div className="flex-1 flex items-center justify-center text-yellow-700">
                                No submissions found.
                            </div>
                        )}

                        {/* TABLE */}
                        {!isInitialLoading && !error && students.length > 0 && (
                            <>
                                {/* FIXED TABLE HEADER */}
                                <div className="flex-shrink-0 border-b border-gray-200 bg-gray-50 overflow-hidden">
                                    <table className="w-full text-sm border-collapse">
                                        <thead>
                                            <tr>
                                                <th className="px-4 py-3 text-center font-medium text-gray-600 w-1/6">
                                                    Seq.
                                                </th>
                                                <th className="px-4 py-3 text-center font-medium text-gray-600 flex-1">
                                                    Submitted At
                                                </th>
                                                <th className="px-4 py-3 text-center font-medium text-gray-600 w-1/6">
                                                    Rating
                                                </th>
                                                <th className="px-4 py-3 text-center font-medium text-gray-600 w-1/4">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                    </table>
                                </div>

                                {/* SCROLLABLE TABLE BODY */}
                                <div className="flex-1 min-h-0 overflow-y-auto relative">
                                    {isPageLoading && (
                                        <div className="absolute inset-0 flex items-center justify-center bg-white/60 text-blue-700 z-10">
                                            Loading page...
                                        </div>
                                    )}

                                    <table className="w-full text-sm border-collapse">
                                        <tbody className="divide-y divide-gray-200 bg-white">
                                            {students.map((student, index) => (
                                                <tr
                                                    key={student.submission_id || student.id}
                                                    className="hover:bg-gray-50"
                                                >
                                                    <td className="px-4 py-3 text-gray-700 w-1/6 text-center">
                                                        {pagination.from + index}
                                                    </td>
                                                    <td className="px-4 py-3 flex-1 text-center">
                                                        <div className="truncate text-gray-600">
                                                            {student.submitted_at
                                                                ? new Date(student.submitted_at).toLocaleString()
                                                                : '-'}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 text-gray-700 w-1/6 text-center">
                                                        {student.rating_percentage
                                                            ? `${Number(student.rating_percentage).toFixed(2)}%`
                                                            : '-'}
                                                    </td>
                                                    <td className="px-4 py-3 w-1/4">
                                                        <div className="flex justify-center gap-2">
                                                            <button
                                                                onClick={() => handleViewStudent(student)}
                                                                className="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                                                            >
                                                                View
                                                            </button>
                                                            <button
                                                                onClick={() => handlePrintSubmission(student)}
                                                                disabled={isPrinting && printingStudentId === student.submission_id}
                                                                className="rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                                            >
                                                                {isPrinting && printingStudentId === student.submission_id ? (
                                                                    <span className="flex items-center gap-1">
                                                                        <svg className="animate-spin h-3 w-3 text-white" fill="none" viewBox="0 0 24 24">
                                                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                                        </svg>
                                                                        ...
                                                                    </span>
                                                                ) : (
                                                                    'Print'
                                                                )}
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                {/* FIXED PAGINATION */}
                                {totalPages > 1 && (
                                    <div className="flex-shrink-0 border-t border-gray-200 px-6 py-4 bg-white">
                                        <div className="flex items-center justify-between">
                                            <div className="text-sm text-gray-600">
                                                Showing {pagination.from} to {pagination.to} of {pagination.total}
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <button
                                                    onClick={() => handlePageChange(currentPage - 1)}
                                                    disabled={currentPage === 1}
                                                    className="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200 disabled:opacity-50"
                                                >
                                                    Prev
                                                </button>
                                                {Array.from({ length: totalPages }, (_, i) => i + 1)
                                                    .slice(Math.max(currentPage - 3, 0), Math.max(currentPage - 3, 0) + 5)
                                                    .map((pageNum) => (
                                                        <button
                                                            key={pageNum}
                                                            onClick={() => handlePageChange(pageNum)}
                                                            className={`px-3 py-1 text-sm rounded ${
                                                                currentPage === pageNum
                                                                    ? 'bg-blue-600 text-white'
                                                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                                            }`}
                                                        >
                                                            {pageNum}
                                                        </button>
                                                    ))}
                                                <button
                                                    onClick={() => handlePageChange(currentPage + 1)}
                                                    disabled={currentPage === totalPages}
                                                    className="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200 disabled:opacity-50"
                                                >
                                                    Next
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </div>

                    {/* FOOTER */}
                    <div className="flex-shrink-0 border-t border-gray-200 px-6 py-4 flex justify-end bg-white">
                        <SecondaryButton onClick={onClose}>
                            Close
                        </SecondaryButton>
                    </div>
                </div>
            </Modal>

            {/* DRAWER */}
            <StudentAnswersDrawer
                isOpen={isDrawerOpen}
                onClose={handleCloseDrawer}
                student={selectedStudent}
                courseCode={courseCode}
                courseDescription={apiCourseDescription || courseDesc}
                termId={termId}
            />
        </>
    );
}