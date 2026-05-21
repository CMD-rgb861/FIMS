import React, { useState, useEffect } from 'react';
import axios from 'axios';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';

export default function StudentAnswersDrawer({
    isOpen,
    onClose,
    student,
    courseCode,
    termId,
}) {
    const [answers, setAnswers] = useState([]);
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
            setAnswers(response.data.answers || []);
        } catch (err) {
            console.error('Failed to fetch answers:', err);
        } finally {
            setIsLoading(false);
        }
    };

    const handleEdit = () => {
        const initialEdits = {};
        answers.forEach(answer => {
            const value = answer.answer_text || answer.selected_option || answer.rating_value || '';
            initialEdits[answer.question_id] = value;
        });
        setEditedAnswers(initialEdits);
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
            
            await fetchAnswers();
            setIsEditMode(false);
        } catch (err) {
            console.error('Failed to save answers:', err);
            alert('Failed to save changes. Please try again.');
        } finally {
            setIsSaving(false);
        }
    };

    const handleCancel = () => {
        setIsEditMode(false);
        setEditedAnswers({});
    };

    if (!isOpen) return null;

    return (
        <>
            {/* Backdrop */}
            <div 
                className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity z-40"
                onClick={onClose}
            />
            
            {/* Drawer */}
            <div className="fixed right-0 top-0 h-full w-full max-w-2xl bg-white shadow-2xl z-50 flex flex-col transform transition-transform duration-300 ease-in-out">
                {/* Header */}
                <div className="border-b border-gray-200 px-6 py-4 bg-gray-50">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-lg font-medium text-gray-900">
                                {isEditMode ? 'Edit Answers' : 'Student Answers'}
                            </h3>
                            <p className="mt-1 text-sm text-gray-500">
                                {student?.student_name} • {student?.student_id_number} • {courseCode}
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            {!isEditMode && (
                                <PrimaryButton onClick={handleEdit}>
                                    Edit
                                </PrimaryButton>
                            )}
                            <SecondaryButton onClick={onClose}>
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

                    {!isLoading && answers.length === 0 && (
                        <div className="text-center py-8 text-gray-500">
                            No answers found for this submission.
                        </div>
                    )}

                    {!isLoading && answers.length > 0 && (
                        <div className="space-y-6">
                            {answers.map((answer, index) => (
                                <div key={answer.question_id} className="border-b border-gray-100 pb-4 last:border-0">
                                    <div className="flex items-start gap-3">
                                        <span className="font-medium text-gray-500 text-sm min-w-[30px]">
                                            {index + 1}.
                                        </span>
                                        <div className="flex-1">
                                            <InputLabel 
                                                htmlFor={`question-${answer.question_id}`}
                                                value={answer.question_text}
                                                className="text-sm font-medium text-gray-900 mb-2"
                                            />
                                            
                                            {isEditMode ? (
                                                <div>
                                                    {answer.question_type === 'text' ? (
                                                        <textarea
                                                            id={`question-${answer.question_id}`}
                                                            value={editedAnswers[answer.question_id] || ''}
                                                            onChange={(e) => handleAnswerChange(answer.question_id, e.target.value)}
                                                            className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                                            rows={3}
                                                        />
                                                    ) : answer.question_type === 'rating' ? (
                                                        <select
                                                            id={`question-${answer.question_id}`}
                                                            value={editedAnswers[answer.question_id] || ''}
                                                            onChange={(e) => handleAnswerChange(answer.question_id, e.target.value)}
                                                            className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                                        >
                                                            <option value="">Select rating</option>
                                                            {[1,2,3,4,5].map(rating => (
                                                                <option key={rating} value={rating}>
                                                                    {rating} - {rating === 1 ? 'Poor' : rating === 2 ? 'Fair' : rating === 3 ? 'Good' : rating === 4 ? 'Very Good' : 'Excellent'}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    ) : (
                                                        <TextInput
                                                            id={`question-${answer.question_id}`}
                                                            value={editedAnswers[answer.question_id] || ''}
                                                            onChange={(e) => handleAnswerChange(answer.question_id, e.target.value)}
                                                            className="w-full"
                                                        />
                                                    )}
                                                </div>
                                            ) : (
                                                <div className="bg-gray-50 rounded-md px-3 py-2 text-sm text-gray-700">
                                                    {answer.answer_text || answer.selected_option || answer.rating_value || '(No answer)'}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Footer - only show in edit mode */}
                {isEditMode && (
                    <div className="border-t border-gray-200 px-6 py-4 bg-gray-50 flex justify-end gap-3">
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