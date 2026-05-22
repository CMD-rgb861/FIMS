import React, { useState, useEffect } from 'react';
import axios from 'axios';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';

// Define sections with their actual question keys from the database
const SECTIONS = [
    {
        title: 'A. Management of Teaching and Learning',
        items: [
            { id: 's0_i0', text: 'Comes to class on time.' },
            { id: 's0_i1', text: 'Explains learning outcomes, expectations, grading system, and various requirements of the subject/course.' },
            { id: 's0_i2', text: 'Maximizes the allocated time/learning hours effectively.' },
            { id: 's0_i3', text: 'Facilitates students to think critically and creatively by providing appropriate learning activities.' },
            { id: 's0_i4', text: 'Guides students to learn on their own, reflect on new ideas and experiences, and make decisions in accomplishing given tasks.' },
            { id: 's0_i5', text: 'Communicates constructive feedback to students for their academic growth.' }
        ]
    },
    {
        title: 'B. Content Knowledge, Pedagogy and Technology',
        items: [
            { id: 's1_i0', text: 'Demonstrates extensive and broad knowledge of the subject/course.' },
            { id: 's1_i1', text: 'Simplifies complex ideas in the lesson for ease of understanding.' },
            { id: 's1_i2', text: 'Relates the subject matter to contemporary issues and developments in the discipline and/or daily life activities.' },
            { id: 's1_i3', text: 'Promotes active learning and student engagement by using appropriate teaching and learning resources including ICT tools and platforms.' },
            { id: 's1_i4', text: 'Uses appropriate assessments (projects, exams, quizzes, assignments, etc.) aligned with the learning outcomes.' }
        ]
    },
    {
        title: 'C. Commitment and Transparency',
        items: [
            { id: 's2_i0', text: 'Recognizes and values the unique diversity and individual differences among students.' },
            { id: 's2_i1', text: 'Assists students with their learning challenges during consultation hours.' },
            { id: 's2_i2', text: 'Provides immediate feedback on student outputs and performance.' },
            { id: 's2_i3', text: 'Provides transparent and clear criteria in rating student\'s performance.' }
        ]
    }
];

// Flatten all questions with their section info using actual IDs from database
const ALL_QUESTIONS = [];
SECTIONS.forEach((section, sectionIndex) => {
    section.items.forEach((item, itemIndex) => {
        ALL_QUESTIONS.push({
            id: item.id, // Use the actual question key from database (s0_i0, s0_i1, etc.)
            text: item.text,
            section: section.title,
            sectionIndex: sectionIndex,
            order: ALL_QUESTIONS.length + 1
        });
    });
});

export default function StudentAnswersDrawer({
    isOpen,
    onClose,
    student,
    courseCode,
    courseDescription, // ADD THIS - receive course description from parent
    termId,
}) {
    const [answers, setAnswers] = useState({});
    const [isLoading, setIsLoading] = useState(false);
    const [isEditMode, setIsEditMode] = useState(false);
    const [editedAnswers, setEditedAnswers] = useState({});
    const [isSaving, setIsSaving] = useState(false);

    useEffect(() => {
        if (isOpen && student) {
            fetchAnswers();
        }
    }, [isOpen, student]);

    const fetchAnswers = async () => {
        setIsLoading(true);
        try {
            const response = await axios.get(`/answers/${student.submission_id}`, {
                params: { term_id: termId }
            });
            
            // Convert answers array to object keyed by question_id
            const answersMap = {};
            if (response.data.answers && Array.isArray(response.data.answers)) {
                response.data.answers.forEach(answer => {
                    answersMap[answer.question_id] = answer.rating_value || '';
                });
            }
            setAnswers(answersMap);
        } catch (err) {
            console.error('Failed to fetch answers:', err);
        } finally {
            setIsLoading(false);
        }
    };

    const handleEdit = () => {
        setEditedAnswers({ ...answers });
        setIsEditMode(true);
    };

    const handleAnswerChange = (questionId, value) => {
        setEditedAnswers(prev => ({
            ...prev,
            [questionId]: value
        }));
    };

    const handleSave = async () => {
        setIsSaving(true);
        try {
            await axios.put(`/answers/${student.submission_id}`, {
                answers: editedAnswers,
                term_id: termId
            });
            
            setAnswers({ ...editedAnswers });
            setIsEditMode(false);
        } catch (err) {
            console.error('Failed to save answers:', err);
            alert('Failed to save changes. Please try again.');
        } finally {
            setIsSaving(false);
        }
    };

    const handleCancel = () => {
        setEditedAnswers({});
        setIsEditMode(false);
    };

    const handleBackdropClick = (e) => {
        e.stopPropagation();
        onClose();
    };

    const handleDrawerClick = (e) => {
        e.stopPropagation();
    };

    const handleCloseButtonClick = (e) => {
        e.stopPropagation();
        onClose();
    };

    const getRatingLabel = (rating) => {
        const labels = {
            1: 'Poor',
            2: 'Fair',
            3: 'Good',
            4: 'Very Good',
            5: 'Excellent'
        };
        return labels[rating] || '';
    };

    if (!isOpen) return null;

    return (
        <>
            {/* Backdrop */}
            <div 
                className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity z-[60]"
                onClick={handleBackdropClick}
            />
            
            {/* Drawer */}
            <div 
                className="fixed right-0 top-0 h-full w-full max-w-3xl bg-white shadow-2xl z-[70] flex flex-col transform transition-transform duration-300 ease-in-out"
                onClick={handleDrawerClick}
            >
                {/* Header */}
                <div className="border-b border-gray-200 px-6 py-4 bg-gray-50 flex-shrink-0">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-lg font-medium text-gray-900">
                                {isEditMode ? 'Edit Answers' : 'Student Answers'}
                            </h3>
                            <p className="mt-1 text-sm text-gray-500">
                                {courseCode} {courseDescription ? `• ${courseDescription}` : ''}
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            {!isEditMode && (
                                <PrimaryButton onClick={handleEdit}>
                                    Edit
                                </PrimaryButton>
                            )}
                            <SecondaryButton onClick={handleCloseButtonClick}>
                                Close
                            </SecondaryButton>
                        </div>
                    </div>
                </div>

                {/* Body */}
                <div className="flex-1 overflow-auto p-6">
                    {isLoading && (
                        <div className="text-center py-8 text-gray-500">
                            Loading answers...
                        </div>
                    )}

                    {!isLoading && ALL_QUESTIONS.length === 0 && (
                        <div className="text-center py-8 text-gray-500">
                            No questions found.
                        </div>
                    )}

                    {!isLoading && ALL_QUESTIONS.length > 0 && (
                        <div className="space-y-8">
                            {/* Group questions by section */}
                            {SECTIONS.map((section, sectionIdx) => {
                                const sectionQuestions = ALL_QUESTIONS.filter(
                                    q => q.section === section.title
                                );
                                
                                if (sectionQuestions.length === 0) return null;
                                
                                return (
                                    <div key={sectionIdx} className="space-y-4">
                                        <h4 className="text-md font-semibold text-gray-900 bg-gray-100 px-4 py-2 rounded-lg">
                                            {section.title}
                                        </h4>
                                        
                                        {sectionQuestions.map((question, idx) => {
                                            const currentAnswer = isEditMode 
                                                ? editedAnswers[question.id] 
                                                : answers[question.id];
                                            
                                            return (
                                                <div key={question.id} className="border-b border-gray-100 pb-4 last:border-0">
                                                    <div className="flex items-start gap-3">
                                                        <span className="font-medium text-gray-500 text-sm min-w-[30px] pt-1">
                                                            {idx + 1}.
                                                        </span>
                                                        <div className="flex-1">
                                                            <InputLabel 
                                                                htmlFor={`question-${question.id}`}
                                                                value={question.text}
                                                                className="text-sm font-medium text-gray-900 mb-2"
                                                            />
                                                            
                                                            {isEditMode ? (
                                                                <select
                                                                    id={`question-${question.id}`}
                                                                    value={currentAnswer || ''}
                                                                    onChange={(e) => handleAnswerChange(question.id, e.target.value)}
                                                                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                                                                >
                                                                    <option value="">Select rating</option>
                                                                    {[1, 2, 3, 4, 5].map(rating => (
                                                                        <option key={rating} value={rating}>
                                                                            {rating} - {getRatingLabel(rating)}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            ) : (
                                                                <div className="bg-gray-50 rounded-md px-3 py-2 text-sm text-gray-700">
                                                                    {currentAnswer 
                                                                        ? `${currentAnswer} - ${getRatingLabel(parseInt(currentAnswer))}`
                                                                        : '(No answer)'
                                                                    }
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* Footer */}
                {isEditMode && (
                    <div className="border-t border-gray-200 px-6 py-4 bg-gray-50 flex justify-end gap-3 flex-shrink-0">
                        <SecondaryButton onClick={handleCancel} disabled={isSaving}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton onClick={handleSave} disabled={isSaving}>
                            {isSaving ? 'Saving...' : 'Save Changes'}
                        </PrimaryButton>
                    </div>
                )}
            </div>
        </>
    );
}