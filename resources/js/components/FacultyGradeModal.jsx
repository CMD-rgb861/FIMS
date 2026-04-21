import React, { useEffect, useState } from 'react';

export default function FacultyGradeModal({
    isOpen,
    evaluation,
    submitUrl = '/evaluations',
    csrfToken = '',
    onClose,
    onSubmitted,
}) {
    const isEvaluated = evaluation?.final_grade !== null && evaluation?.final_grade !== undefined;
    const [grade, setGrade] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        setGrade('');
        setError('');
        setIsSubmitting(false);
    }, [isOpen, evaluation?.code, evaluation?.instructor]);

    const handleSubmit = async () => {
        if (isSubmitting || !evaluation) {
            return;
        }

        setIsSubmitting(true);
        setError('');

        const numericGrade = Number(grade);

        if (!Number.isFinite(numericGrade) || numericGrade < 1 || numericGrade > 5) {
            setError('Grade must be a number between 1 and 5.');
            setIsSubmitting(false);
            return;
        }

        const normalizedGrade = Number(numericGrade.toFixed(2));

        try {
            const response = await fetch(submitUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    instructor: evaluation.instructor,
                    course_code: evaluation.code,
                    course_title: evaluation.title,
                    term: evaluation.term,
                    grade: normalizedGrade,
                }),
            });

            if (!response.ok) {
                let message = 'Unable to submit faculty grade.';

                try {
                    const payload = await response.json();
                    if (payload?.message) {
                        message = payload.message;
                    }
                } catch {
                    // Fallback to default message when response is not JSON.
                }

                throw new Error(message);
            }

            let payload = null;

            try {
                payload = await response.json();
            } catch {
                payload = null;
            }

            const savedGrade = Number(payload?.grade ?? normalizedGrade);

            onSubmitted?.({
                instructor: evaluation.instructor,
                evaluation_result: {
                    instructor: evaluation.instructor,
                    course_code: evaluation.code,
                    course_title: evaluation.title,
                    term: evaluation.term,
                    final_grade: Number.isFinite(savedGrade) ? savedGrade : normalizedGrade,
                },
            });

            onClose?.();
        } catch (submitError) {
            setError(submitError.message || 'Unable to submit faculty grade.');
        } finally {
            setIsSubmitting(false);
        }
    };

    if (!isOpen || !evaluation) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4">
            <div className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div className="border-b border-slate-200 px-5 py-4">
                    <h2 className="text-lg font-semibold text-slate-900">Faculty Grade</h2>
                    <p className="mt-1 text-sm text-slate-500">Provide an overall faculty grade for this subject.</p>
                </div>

                <div className="space-y-4 px-5 py-4">
                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                        <p><span className="font-semibold text-slate-900">Instructor:</span> {evaluation.instructor}</p>
                        <p><span className="font-semibold text-slate-900">Subject:</span> {evaluation.code} - {evaluation.title}</p>
                        <p><span className="font-semibold text-slate-900">Term:</span> {evaluation.term}</p>
                    </div>

                    <label className="block">
                        <span className="mb-1 block text-sm font-medium text-slate-700">Grade</span>
                        <input
                            type="text"
                            value={grade}
                            onChange={(event) => setGrade(event.target.value)}
                            inputMode="decimal"
                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 disabled:bg-slate-100 disabled:text-slate-500"
                        />
                    </label>

                    {isEvaluated ? (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                            This subject already has a grade. Submitting will update it.
                        </div>
                    ) : null}

                    {error ? (
                        <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {error}
                        </div>
                    ) : null}
                </div>

                <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        onClick={handleSubmit}
                        disabled={isSubmitting}
                        className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-70"
                    >
                        {isSubmitting ? 'Submitting...' : (isEvaluated ? 'Update Grade' : 'Submit Grade')}
                    </button>
                </div>
            </div>
        </div>
    );
}
