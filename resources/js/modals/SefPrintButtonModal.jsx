import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import axios from 'axios';
import { toast } from 'react-toastify';

// Memoized row component to prevent re-renders
const FacultyRow = React.memo(({ faculty, isSelected, onToggle, isLoadingDetails }) => (
    <tr className="hover:bg-slate-50">
        <td className="px-4 py-3">
            <input
                type="checkbox"
                checked={isSelected}
                onChange={() => onToggle(faculty.employee_id_no)}
                disabled={isLoadingDetails || !faculty.has_sef_data}
                className="rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50"
            />
        </td>
        <td className="px-4 py-3 text-sm text-slate-700 font-medium">
            {faculty.instructor || '-'}
        </td>
        <td className="px-4 py-3 text-sm text-slate-600">
            {faculty.employee_id_no || '-'}
        </td>
        <td className="px-4 py-3 text-sm text-slate-600 text-center font-semibold">
            {faculty.overall_sef_rating ? (
                <span className="text-emerald-600 font-bold">
                    {Number(faculty.overall_sef_rating).toFixed(2)}%
                </span>
            ) : (
                <span className="text-slate-400">—</span>
            )}
        </td>
    </tr>
));

export default function SefPrintButtonModal({ 
    isOpen, 
    onClose, 
    facultyList = [],
    facultyListAll = [],
    selectedSchoolYear = '',
    schoolYearLabel = ''
}) {
    const [selectedFaculty, setSelectedFaculty] = useState([]);
    const effectiveFacultyList = useMemo(
        () => (facultyListAll.length > 0 ? facultyListAll : facultyList),
        [facultyListAll, facultyList]
    );
    const [isGenerating, setIsGenerating] = useState(false);
    const [facultyWithSef, setFacultyWithSef] = useState([]);
    const [isLoadingDetails, setIsLoadingDetails] = useState(false);
    const [currentPage, setCurrentPage] = useState(1);
    const itemsPerPage = 20;
    
    // Cache ref to avoid refetching same data
    const cacheRef = useRef(new Map());

    // Fetch SEF data using batch API
    const fetchAllSefData = useCallback(async () => {
        if (effectiveFacultyList.length === 0) return;
        
        // Check cache first
        const cacheKey = `${selectedSchoolYear}_${effectiveFacultyList.map(f => f.employee_id_no).join(',')}`;
        if (cacheRef.current.has(cacheKey)) {
            setFacultyWithSef(cacheRef.current.get(cacheKey));
            return;
        }

        setIsLoadingDetails(true);
        
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const facultyIds = effectiveFacultyList.map(f => f.employee_id_no);
            const response = await axios.post('/sef/batch-reports', {
                term_id: selectedSchoolYear,
                faculty_ids: facultyIds
            }, {
                headers: {
                    'X-CSRF-TOKEN': csrfToken || '',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                timeout: 60000
            });
            
            const facultyDataMap = response.data;
            
            const facultyWithData = effectiveFacultyList.map(faculty => {
                const facultyId = String(faculty.employee_id_no);
                const data = facultyDataMap[facultyId] || {};
                return {
                    ...faculty,
                    overall_sef_rating: data.overall_sef_rating || faculty.overall_sef_rating || null,
                    sef_details: data.details || null,
                    ratings_breakdown: data.ratings_breakdown || data.details?.ratings_breakdown,
                    comments: data.comments || '',
                    has_sef_data: data.has_data || false,
                    total_evaluators: data.total_evaluators || 0,
                    error: data.error || null
                };
            });
            
            // Store in cache
            cacheRef.current.set(cacheKey, facultyWithData);
            setFacultyWithSef(facultyWithData);
            setCurrentPage(1);
        } catch (err) {
            console.error('Error fetching SEF data:', err);
            toast.error(err.response?.data?.message || 'Failed to load SEF data. Please try again.', {
                position: "top-right",
                autoClose: 5000,
            });
            setFacultyWithSef(effectiveFacultyList.map(f => ({ 
                ...f, 
                has_sef_data: false, 
                total_evaluators: 0,
                overall_sef_rating: null
            })));
        } finally {
            setIsLoadingDetails(false);
        }
    }, [effectiveFacultyList, selectedSchoolYear]);

    // Fetch when modal opens
    useEffect(() => {
        if (isOpen && effectiveFacultyList.length > 0 && selectedSchoolYear) {
            fetchAllSefData();
        }
    }, [isOpen, effectiveFacultyList, selectedSchoolYear, fetchAllSefData]);

    // Reset when modal closes
    useEffect(() => {
        if (!isOpen) {
            setSelectedFaculty([]);
            setIsGenerating(false);
            setCurrentPage(1);
        }
    }, [isOpen]);

    const handleFacultyToggle = useCallback((facultyId) => {
        setSelectedFaculty(prev => 
            prev.includes(facultyId)
                ? prev.filter(id => id !== facultyId)
                : [...prev, facultyId]
        );
    }, []);

    const handleSelectAll = useCallback(() => {
        const facultyWithData = facultyWithSef.filter(f => f.has_sef_data);
        setSelectedFaculty(prev => 
            prev.length === facultyWithData.length 
                ? [] 
                : facultyWithData.map(f => f.employee_id_no)
        );
    }, [facultyWithSef]);

    const handleGeneratePDF = useCallback(async () => {
        if (selectedFaculty.length === 0) {
            toast.error('Please select at least one faculty member to print.', {
                position: "top-right",
                autoClose: 3000,
            });
            return;
        }

        setIsGenerating(true);

        const loadingToastId = toast.loading(`Generating SEF PDF for ${selectedFaculty.length} faculty member(s)...`, {
            position: "top-right",
        });

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const selectedFacultyData = facultyWithSef
                .filter(f => selectedFaculty.includes(f.employee_id_no))
                .map(faculty => ({
                    employee_id_no: faculty.employee_id_no,
                    instructor: faculty.instructor,
                    department: faculty.department || faculty.college,
                    course_code: faculty.course_code,
                    course_title: faculty.course_title,
                    ratings_breakdown: faculty.ratings_breakdown || faculty.sef_details?.ratings_breakdown,
                    comments: faculty.comments || '',
                    evaluator_name: '',
                    evaluator_id: ''
                }));
            
            const response = await axios.post('/sef/pdf/generate', {
                term_id: String(selectedSchoolYear),
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
                
                toast.success(`PDF generated successfully! ${selectedFaculty.length} faculty report(s) opening...`, {
                    position: "top-right",
                    autoClose: 3000,
                });
                onClose();
            } else {
                toast.dismiss(loadingToastId);
                toast.error('Failed to generate PDF. Please try again.', {
                    position: "top-right",
                    autoClose: 5000,
                });
            }
        } catch (error) {
            console.error('Error generating PDF:', error);
            toast.dismiss(loadingToastId);
            toast.error(error.response?.data?.message || 'Failed to generate PDF. Please try again.', {
                position: "top-right",
                autoClose: 5000,
            });
        } finally {
            setIsGenerating(false);
        }
    }, [selectedFaculty, facultyWithSef, selectedSchoolYear, schoolYearLabel, onClose]);

    // Memoized computed values
    const facultyWithData = useMemo(() => 
        facultyWithSef.filter(f => f.has_sef_data), 
        [facultyWithSef]
    );
    
    const selectedCount = selectedFaculty.length;
    const totalWithData = facultyWithData.length;
    
    // Pagination
    const totalPages = Math.ceil(facultyWithSef.length / itemsPerPage);
    const indexOfLastItem = currentPage * itemsPerPage;
    const indexOfFirstItem = indexOfLastItem - itemsPerPage;
    const currentFaculty = facultyWithSef.slice(indexOfFirstItem, indexOfLastItem);
    
    const handlePageChange = useCallback((pageNumber) => {
        setCurrentPage(pageNumber);
        const tableContainer = document.getElementById('faculty-table-container');
        if (tableContainer) tableContainer.scrollTop = 0;
    }, []);
    
    const goToPreviousPage = useCallback(() => {
        setCurrentPage(prev => Math.max(prev - 1, 1));
    }, []);
    
    const goToNextPage = useCallback(() => {
        setCurrentPage(prev => Math.min(prev + 1, totalPages));
    }, [totalPages]);

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4">
            <div className="w-full max-w-4xl rounded-2xl border border-slate-300 bg-white shadow-2xl">
                {/* Header */}
                <div className="border-b border-slate-200 px-5 py-4">
                    <div className="flex items-start justify-between">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">Print SEF Reports</h2>
                            <p className="text-sm text-slate-600 mt-1">
                                Select faculty members to generate SEF summary reports
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

                {/* Body */}
                <div className="px-5 py-4">
                    {isLoadingDetails && (
                        <div className="mb-4 rounded-md bg-blue-50 p-3 text-sm text-blue-700 border border-blue-200">
                            <div className="flex items-center gap-2">
                                <svg className="animate-spin h-4 w-4 text-blue-700" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Loading SEF data for faculty...
                            </div>
                        </div>
                    )}

                    {/* Select All Button */}
                    {!isLoadingDetails && facultyWithData.length > 0 && (
                        <div className="mb-4 flex items-center justify-between">
                            <button
                                onClick={handleSelectAll}
                                className="text-sm text-blue-600 hover:text-blue-700 font-medium"
                            >
                                {selectedCount === totalWithData ? 'Deselect All' : 'Select All'}
                            </button>
                            <span className="text-xs text-slate-500">
                                {selectedCount} of {totalWithData} selected
                            </span>
                        </div>
                    )}

                    {/* Faculty List Table with Pagination */}
                    <div 
                        id="faculty-table-container"
                        className="border border-slate-200 rounded-lg overflow-hidden"
                    >
                        {facultyWithSef.length === 0 ? (
                            <div className="p-8 text-center text-slate-500">
                                No faculty members available.
                            </div>
                        ) : (
                            <>
                                <div className="max-h-96 overflow-y-auto">
                                    <table className="min-w-full divide-y divide-slate-200">
                                        <thead className="bg-slate-50 sticky top-0 z-10">
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 w-12">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedCount === totalWithData && totalWithData > 0}
                                                        onChange={handleSelectAll}
                                                        disabled={isLoadingDetails}
                                                        className="rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50"
                                                    />
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600">Faculty Name</th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600">Employee ID</th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 text-center">Overall SEF Rating</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-200 bg-white">
                                            {currentFaculty.map((faculty) => (
                                                <FacultyRow 
                                                    key={faculty.employee_id_no}
                                                    faculty={faculty}
                                                    isSelected={selectedFaculty.includes(faculty.employee_id_no)}
                                                    onToggle={handleFacultyToggle}
                                                    isLoadingDetails={isLoadingDetails}
                                                />
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                
                                {/* Pagination Controls */}
                                {totalPages > 1 && (
                                    <div className="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3">
                                        <div className="text-sm text-slate-700">
                                            Showing {indexOfFirstItem + 1} to {Math.min(indexOfLastItem, facultyWithSef.length)} of {facultyWithSef.length} faculty
                                        </div>
                                        <div className="flex gap-2">
                                            <button
                                                onClick={goToPreviousPage}
                                                disabled={currentPage === 1}
                                                className="rounded-md border border-slate-300 bg-white px-3 py-1 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                Previous
                                            </button>
                                            <div className="flex gap-1">
                                                {Array.from({ length: Math.min(5, totalPages) }, (_, i) => {
                                                    let pageNum;
                                                    if (totalPages <= 5) {
                                                        pageNum = i + 1;
                                                    } else if (currentPage <= 3) {
                                                        pageNum = i + 1;
                                                    } else if (currentPage >= totalPages - 2) {
                                                        pageNum = totalPages - 4 + i;
                                                    } else {
                                                        pageNum = currentPage - 2 + i;
                                                    }
                                                    
                                                    return (
                                                        <button
                                                            key={pageNum}
                                                            onClick={() => handlePageChange(pageNum)}
                                                            className={`rounded-md px-3 py-1 text-sm font-medium ${
                                                                currentPage === pageNum
                                                                    ? 'bg-blue-600 text-white'
                                                                    : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                                            }`}
                                                        >
                                                            {pageNum}
                                                        </button>
                                                    );
                                                })}
                                                {totalPages > 5 && currentPage < totalPages - 2 && (
                                                    <>
                                                        <span className="px-2 py-1 text-slate-500">...</span>
                                                        <button
                                                            onClick={() => handlePageChange(totalPages)}
                                                            className="rounded-md border border-slate-300 bg-white px-3 py-1 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                                        >
                                                            {totalPages}
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                            <button
                                                onClick={goToNextPage}
                                                disabled={currentPage === totalPages}
                                                className="rounded-md border border-slate-300 bg-white px-3 py-1 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                Next
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                    
                    {/* Warning if no faculty have data */}
                    {!isLoadingDetails && facultyWithSef.length > 0 && totalWithData === 0 && (
                        <div className="mt-4 rounded-md bg-yellow-50 p-3 text-sm text-yellow-700 border border-yellow-200">
                            No SEF data found for any faculty member in the selected school year.
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
                        disabled={isGenerating || selectedFaculty.length === 0 || isLoadingDetails}
                        className="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed"
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
                                Print Selected SEF ({selectedCount})
                            </>
                        )}
                    </button>
                </div>
            </div>
        </div>
    );
}