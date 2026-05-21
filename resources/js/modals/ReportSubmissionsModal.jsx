import React, { useState, useEffect } from 'react';
import axios from 'axios';
import StudentAnswersDrawer from './StudentAnswersDrawer';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';

export default function ReportSubmissionsModal({
    isOpen,
    onClose,
    courseCode,
    yearSection,
    instructorId,
    termId,
}) {
    const [students, setStudents] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');
    const [selectedStudent, setSelectedStudent] = useState(null);
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [isInitialLoading, setIsInitialLoading] = useState(false);
    const [isPageLoading, setIsPageLoading] = useState(false);

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

        // only show full loader on first open
        if (students.length === 0) {
            setIsInitialLoading(true);
        } else {
            setIsPageLoading(true);
        }

        try {
            const response = await axios.get('/submissions', {
                params: {
                    course_code: courseCode,
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
                        <h2 className="text-lg font-medium text-gray-900">
                            Student Submissions
                        </h2>

                        <p className="mt-1 text-sm text-gray-500">
                            {courseCode} • {yearSection}
                        </p>
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
                                    <div className="flex-shrink-0 border-b border-gray-200 bg-gray-50">
                                        <table className="w-full table-fixed text-sm">
                                            <thead>
                                                <tr>
                                                    <th className="w-28 px-4 py-3 text-left font-medium text-gray-600">
                                                        #
                                                    </th>

                                                    <th className="px-4 py-3 text-left font-medium text-gray-600">
                                                        Submitted At
                                                    </th>

                                                    <th className="w-28 px-4 py-3 text-left font-medium text-gray-600">
                                                        Rating
                                                    </th>

                                                    <th className="w-28 px-4 py-3 text-left font-medium text-gray-600">
                                                        Action
                                                    </th>
                                                </tr>
                                            </thead>
                                        </table>
                                    </div>

                                    {/* SCROLLABLE TABLE BODY */}
                                    <div className="flex-1 min-h-0 overflow-y-auto relative">

                                        {/* PAGE LOADING OVERLAY (ONLY TABLE AREA) */}
                                        {isPageLoading && (
                                            <div className="absolute inset-0 flex items-center justify-center bg-white/60 text-blue-700 z-10">
                                                Loading page...
                                            </div>
                                        )}

                                        <table className="w-full table-fixed text-sm">
                                            <tbody className="divide-y divide-gray-200 bg-white">
                                                {students.map((student, index) => (
                                                    <tr
                                                        key={student.submission_id || student.id}
                                                        className="hover:bg-gray-50"
                                                    >
                                                        <td className="w-28 px-4 py-3 text-gray-700">
                                                            {pagination.from + index}
                                                        </td>

                                                        <td className="px-4 py-3">
                                                            <div className="truncate text-gray-600">
                                                                {student.submitted_at
                                                                    ? new Date(student.submitted_at).toLocaleString()
                                                                    : '-'}
                                                            </div>
                                                        </td>

                                                        <td className="w-28 px-4 py-3 whitespace-nowrap text-gray-700">
                                                            {student.rating_percentage
                                                                ? `${Number(student.rating_percentage).toFixed(2)}%`
                                                                : '-'}
                                                        </td>

                                                        <td className="w-28 px-4 py-3">
                                                            <button
                                                                onClick={() => handleViewStudent(student)}
                                                                className="w-full rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                                                            >
                                                                View
                                                            </button>
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
                                                    Showing {pagination.from} to{' '}
                                                    {pagination.to} of{' '}
                                                    {pagination.total}
                                                </div>

                                                <div className="flex items-center gap-2">
                                                    {/* PREV */}
                                                    <button
                                                        onClick={() =>
                                                            handlePageChange(
                                                                currentPage - 1
                                                            )
                                                        }
                                                        disabled={
                                                            currentPage === 1
                                                        }
                                                        className="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200 disabled:opacity-50"
                                                    >
                                                        Prev
                                                    </button>

                                                    {/* PAGE NUMBERS */}
                                                    {Array.from(
                                                        {
                                                            length:
                                                                totalPages,
                                                        },
                                                        (_, i) => i + 1
                                                    )
                                                        .slice(
                                                            Math.max(
                                                                currentPage - 3,
                                                                0
                                                            ),
                                                            Math.max(
                                                                currentPage - 3,
                                                                0
                                                            ) + 5
                                                        )
                                                        .map((pageNum) => (
                                                            <button
                                                                key={pageNum}
                                                                onClick={() =>
                                                                    handlePageChange(
                                                                        pageNum
                                                                    )
                                                                }
                                                                className={`px-3 py-1 text-sm rounded ${
                                                                    currentPage ===
                                                                    pageNum
                                                                        ? 'bg-blue-600 text-white'
                                                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                                                }`}
                                                            >
                                                                {pageNum}
                                                            </button>
                                                        ))}

                                                    {/* NEXT */}
                                                    <button
                                                        onClick={() =>
                                                            handlePageChange(
                                                                currentPage + 1
                                                            )
                                                        }
                                                        disabled={
                                                            currentPage ===
                                                            totalPages
                                                        }
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
                termId={termId}
            />
        </>
    );
}