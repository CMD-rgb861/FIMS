import React, { useMemo, useRef, useState } from 'react';

export default function SefEvaluationModal({ isOpen, evaluation, submitUrl = '/evaluations', csrfToken = '', onClose, onSubmitted }) {
    const [ratings, setRatings] = useState({});
    const [comments, setComments] = useState('');
    const [currentStep, setCurrentStep] = useState(1);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState('');
    const [submitSuccess, setSubmitSuccess] = useState('');
    const benchmarkSectionRef = useRef(null);

    const ratingScale = useMemo(() => ([
        {
            scale: '5',
            qualitative: 'Always manifested',
            operational: 'Evident in nearly all relevant situations (91-100% of instances).',
        },
        {
            scale: '4',
            qualitative: 'Often manifested',
            operational: 'Evident most of the time, with occasional lapses (61-90%).',
        },
        {
            scale: '3',
            qualitative: 'Sometimes manifested',
            operational: 'Evident about half the time (31-60%).',
        },
        {
            scale: '2',
            qualitative: 'Seldom manifested',
            operational: 'Infrequently demonstrated; rarely evident in relevant situations (11-30%).',
        },
        {
            scale: '1',
            qualitative: 'Never/Rarely manifested',
            operational: 'Seldom demonstrated; almost never evident, with only isolated cases (0-10%).',
        },
    ]), []);

    const benchmarkSections = useMemo(() => ([
        {
            title: 'A. Management of Teaching and Learning',
            items: [
                {
                    id: 'a1',
                    statement: 'Comes to class on time.',
                    verification: ['Daily time record', 'Faculty schedule and timetable', 'Informal interview with students'],
                },
                {
                    id: 'a2',
                    statement: 'Submits updated syllabus, grade sheets, and other required reports on time.',
                    verification: ['Submission receipts', 'Acknowledgment emails', 'Class schedules and timetables'],
                },
                {
                    id: 'a3',
                    statement: 'Maximizes the allotted time/learning hours effectively.',
                    verification: ['LMS logs', 'Informal interview with students'],
                },
                {
                    id: 'a4',
                    statement: 'Provides appropriate learning activities that facilitate critical thinking and creativity of students.',
                    verification: ['Course syllabus', 'Learning plan', 'Classroom observation'],
                },
                {
                    id: 'a5',
                    statement: 'Guides students to learn on their own, reflect on new ideas and experiences, and make decisions in accomplishing given tasks.',
                    verification: ['Student work samples', 'Classroom observation', 'Faculty consultation log'],
                },
                {
                    id: 'a6',
                    statement: 'Communicates constructive feedback to students for their academic growth.',
                    verification: ['Graded student work with feedback', 'Informal interview with students', 'Emails or official correspondence'],
                },
            ],
        },
        {
            title: 'B. Content Knowledge, Pedagogy and Technology',
            items: [
                {
                    id: 'b7',
                    statement: 'Demonstrates extensive and broad knowledge of the subject/course.',
                    verification: ['Course syllabus', 'Learning plan', 'Informal interview with students', 'Mentorship or Thesis/ Dissertation Advisory records'],
                },
                {
                    id: 'b8',
                    statement: 'Simplifies complex ideas in the lesson for ease of understanding.',
                    verification: ['Learning Plan', 'Course Syllabus', 'Classroom Observation', 'Informal interview with students', 'Lecture notes and presentations', 'LMS Logs'],
                },
                {
                    id: 'b9',
                    statement: 'Integrates contemporary issues and developments in the discipline and/or daily life activities in the syllabus.',
                    verification: ['Course Syllabus', 'Learning Plan', 'Classroom Observation', 'Informal interview with students', 'LMS Logs', 'IMs developed by the faculty', 'Participation in Conferences, Webinars, and Training'],
                },
                {
                    id: 'b10',
                    statement: 'Promotes active learning and student engagement by using appropriate teaching and learning resources including ICT tools and platforms.',
                    verification: ['Course Syllabus', 'Learning Plan', 'Classroom Observation', 'Informal interview with students', 'LMS Logs', 'Multimedia Lecture Materials', 'Student Work Samples'],
                },
                {
                    id: 'b11',
                    statement: 'Uses appropriate assessments (projects, exams, quizzes, assignments, etc.) aligned with the learning outcomes.',
                    verification: ['Course Syllabus', 'Learning Plan', 'Informal interview with students', 'Assessment tools and rubrics', 'Exam and Quiz Samples', 'Graded Student Work Samples', 'LMS records'],
                },
            ],
        },
        {
            title: 'C. Commitment and Transparency',
            items: [
                {
                    id: 'c12',
                    statement: 'Recognizes and values the unique diversity and individual differences among students.',
                    verification: ['Course Syllabus', 'Learning Plan', 'IMs developed by the faculty', 'Classroom Observation', 'Informal interview with students'],
                },
                {
                    id: 'c13',
                    statement: 'Assists students with their learning challenges during consultation hours.',
                    verification: ['Course Syllabus', 'Faculty Consultation Log', 'Advisory Records', 'LMS Logs', 'Emails or Official Correspondence'],
                },
                {
                    id: 'c14',
                    statement: 'Provides immediate feedback on student outputs and performance.',
                    verification: ['Graded Student Work Samples', 'Assessment tools and rubrics', 'Informal interview with students', 'LMS Logs', 'Emails or Official Correspondence'],
                },
                {
                    id: 'c15',
                    statement: 'Provides transparent and clear criteria in rating student\'s performance.',
                    verification: ['Course Syllabus', 'Assessment Tools and Rubrics', 'Informal interview with students', 'LMS Records', 'Grade Sheets and Records'],
                },
            ],
        },
    ]), []);

    const currentSection = currentStep <= 3 ? benchmarkSections[currentStep - 1] : null;
    const currentSectionItems = currentSection?.items ?? [];
    const totalItems = currentSectionItems.length;
    const answeredCount = currentSectionItems.filter((item) => ratings[item.id]).length;
    const canProceedNext = totalItems > 0 && answeredCount === totalItems;
    const allBenchmarkIds = benchmarkSections.flatMap((section) => section.items.map((item) => item.id));
    const canSubmitAllRatings = allBenchmarkIds.every((id) => Boolean(ratings[id]));

    const handleRatingChange = (benchmarkId, value) => {
        setRatings((prev) => ({ ...prev, [benchmarkId]: value }));
        setSubmitError('');
        setSubmitSuccess('');
    };

    const handleNext = () => {
        setCurrentStep((prev) => Math.min(prev + 1, 4));
        requestAnimationFrame(() => {
            benchmarkSectionRef.current?.scrollIntoView({ block: 'start' });
        });
    };

    const handlePrevious = () => {
        setCurrentStep((prev) => Math.max(prev - 1, 1));
        requestAnimationFrame(() => {
            benchmarkSectionRef.current?.scrollIntoView({ block: 'start' });
        });
    };

    const handleSubmit = async () => {
        if (!canSubmitAllRatings || isSubmitting) {
            setSubmitError('Please complete all ratings before submitting.');
            return;
        }

        setIsSubmitting(true);
        setSubmitError('');
        setSubmitSuccess('');

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
                    ratings,
                    comments: comments.trim() || null,
                }),
            });

            if (!response.ok) {
                let message = 'Unable to submit evaluation. Please try again.';

                try {
                    const payload = await response.json();
                    if (payload?.message) {
                        message = payload.message;
                    }
                } catch {
                    // Keep the default fallback message when response is not JSON.
                }

                throw new Error(message);
            }

            const submittedScores = Object.entries(ratings)
                .map(([benchmark, score]) => ({ benchmark, score: Number(score) }))
                .sort((a, b) => {
                    const aNum = Number(String(a.benchmark).replace(/[^0-9]/g, ''));
                    const bNum = Number(String(b.benchmark).replace(/[^0-9]/g, ''));
                    return aNum - bNum;
                });
            const submittedTotal = submittedScores.reduce((sum, item) => sum + item.score, 0);
            const submittedRating = Number(((submittedTotal / 75) * 100).toFixed(2));

            setSubmitSuccess('Evaluation submitted successfully.');
            onSubmitted?.({
                instructor: evaluation.instructor,
                evaluation_result: {
                    instructor: evaluation.instructor,
                    course_code: evaluation.code,
                    course_title: evaluation.title,
                    term: evaluation.term,
                    scores: submittedScores,
                    total_score: submittedTotal,
                    rating_percentage: submittedRating,
                },
            });
            setTimeout(() => {
                handleClose();
            }, 500);
        } catch (error) {
            setSubmitError(error.message || 'Unable to submit evaluation. Please try again.');
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleClose = () => {
        setRatings({});
        setComments('');
        setCurrentStep(1);
        setSubmitError('');
        setSubmitSuccess('');
        onClose?.();
    };

    if (!isOpen || !evaluation) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-2 sm:p-3">
            <div className="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-2xl">
                <div className="border-b border-slate-200 px-4 py-2 sm:px-5">
                    <div className="flex items-center gap-6 text-sm font-semibold text-slate-900">
                        <span>Evaluation progress</span>
                        <span className="font-medium text-slate-500">Step {currentStep} of 4</span>
                        <span className="font-medium text-slate-500">Answered {answeredCount}/{totalItems}</span>
                    </div>

                    <div className="mt-2 grid grid-cols-4 gap-1 text-center text-xs font-semibold">
                        <span className={`rounded-md px-2 py-1 ${currentStep === 1 ? 'bg-sky-200 text-sky-900' : 'bg-sky-50 text-slate-500'}`}>Management</span>
                        <span className={`rounded-md px-2 py-1 ${currentStep === 2 ? 'bg-sky-200 text-sky-900' : 'bg-sky-50 text-slate-500'}`}>Content &amp; Pedagogy</span>
                        <span className={`rounded-md px-2 py-1 ${currentStep === 3 ? 'bg-sky-200 text-sky-900' : 'bg-sky-50 text-slate-500'}`}>Commitment &amp; Transparency</span>
                        <span className={`rounded-md px-2 py-1 ${currentStep === 4 ? 'bg-sky-200 text-sky-900' : 'bg-sky-50 text-slate-500'}`}>Review &amp; Submit</span>
                    </div>
                </div>

                <div className="max-h-[80vh] space-y-3 overflow-y-auto px-4 py-3 sm:px-5">

                    <section className="rounded-xl border border-slate-300 p-2 sm:p-2.5">
                        <h3 className="text-xs font-semibold text-slate-900 sm:text-sm">A. Faculty Information</h3>
                        <div className="mt-1.5 grid grid-cols-1 gap-1.5 text-[10px] sm:grid-cols-2 sm:gap-2 sm:text-[11px]">
                            <div>
                                <p className="text-left text-[10px] leading-tight text-slate-500 sm:text-[11px]">Name of Faculty being Evaluated</p>
                                <p className="mt-0.5 pb-1 text-xs font-bold text-slate-900 sm:text-sm">{evaluation.instructor.toUpperCase()}</p>
                            </div>
                            <div>
                                <p className="text-[10px] text-slate-500 sm:text-[11px]">College/Department</p>
                                <p className="mt-0.5 pb-1 text-xs font-bold leading-tight text-slate-900 sm:text-sm">Masteral - Master in Information Technology</p>
                            </div>
                            <div>
                                <p className="text-[10px] text-slate-500 sm:text-[11px]">Course Code/Title</p>
                                <p className="mt-0.5 pb-1 text-xs font-bold text-slate-900 sm:text-sm">{evaluation.code} - {evaluation.title}</p>
                            </div>
                            <div>
                                <p className="text-[10px] text-slate-500 sm:text-[11px]">Program Level</p>
                                <p className="mt-0.5 pb-1 text-xs font-bold text-slate-900 sm:text-sm">Year 3</p>
                            </div>
                            <div className="sm:col-span-2">
                                <p className="text-[10px] text-slate-500 sm:text-[11px]">Semester or Term/Academic Year</p>
                                <p className="mt-0.5 pb-1 text-xs font-bold text-slate-900 sm:text-sm">{evaluation.term}</p>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-xl border border-slate-300 p-2 sm:p-2.5">
                        <h3 className="text-xs font-semibold text-slate-900 sm:text-sm">B. Rating Scale</h3>
                        <div className="mt-2 overflow-hidden rounded-lg border border-slate-300">
                            <table className="min-w-full border border-slate-300 text-[10px] sm:text-xs">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="border border-slate-300 px-1.5 py-1 text-left font-semibold text-slate-700">Scale</th>
                                        <th className="border border-slate-300 px-1.5 py-1 text-left font-semibold text-slate-700">Qualitative Description</th>
                                        <th className="border border-slate-300 px-1.5 py-1 text-left font-semibold text-slate-700">Operational Definition</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white">
                                    {ratingScale.map((row) => (
                                        <tr key={row.scale}>
                                            <td className="border border-slate-300 px-1.5 py-1 text-center font-medium text-slate-900">{row.scale}</td>
                                            <td className="border border-slate-300 px-1.5 py-1 text-slate-800">{row.qualitative}</td>
                                            <td className="border border-slate-300 px-1.5 py-1 text-slate-700">{row.operational}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section className="rounded-xl border border-slate-300 p-2 sm:p-2.5">
                        <h3 className="text-xs font-semibold text-slate-900 sm:text-sm">C. Instruction:</h3>
                        <p className="mt-1.5 text-[10px] leading-snug text-slate-700 sm:text-xs">
                            Carefully read each benchmark statement and rate the faculty by encircling the appropriate rating based on the scale above. The "Suggested Means of Verification" column may guide the supervisor in conducting an objective assessment.
                        </p>
                    </section>

                    {currentStep === 4 && (
                        <section className="rounded-xl border border-slate-300 p-4">
                            <label htmlFor="other-comments" className="block text-lg font-semibold text-slate-900">
                                Other comments and suggestions
                            </label>
                            <textarea
                                id="other-comments"
                                rows="4"
                                className="mt-3 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                                placeholder="Write any additional comments or suggestions here..."
                                value={comments}
                                onChange={(event) => setComments(event.target.value)}
                            />
                        </section>
                    )}

                    {currentStep < 4 && (
                        <section ref={benchmarkSectionRef} className="rounded-xl border border-slate-300 p-4">
                            <div className="max-h-[46vh] overflow-auto">
                                <table className="min-w-full table-fixed border border-slate-300 text-xs">
                                    <thead>
                                        <tr className="bg-slate-50">
                                            <th className="w-[38%] border border-slate-300 px-2 py-2 text-left font-semibold text-slate-700">Benchmark Statements</th>
                                            <th className="w-[40%] border border-slate-300 px-2 py-2 text-left font-semibold text-slate-700">Suggested Means for Verification</th>
                                            <th colSpan={5} className="border border-slate-300 px-2 py-2 text-center font-semibold text-slate-700">Rating</th>
                                        </tr>
                                        <tr className="bg-slate-50">
                                            <th className="w-[38%] border border-slate-300 px-2 py-1" />
                                            <th className="w-[40%] border border-slate-300 px-2 py-1" />
                                            {[5, 4, 3, 2, 1].map((value) => (
                                                <th key={value} className="border border-slate-300 px-2 py-1 text-center font-semibold text-slate-700">{value}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {currentSection ? (
                                            <React.Fragment key={currentSection.title}>
                                                <tr className="bg-slate-100">
                                                    <td colSpan={7} className="border border-slate-300 px-2 py-2 font-semibold text-slate-800">
                                                        {currentSection.title}
                                                    </td>
                                                </tr>

                                                {currentSection.items.map((item, idx) => (
                                                    <tr key={item.id} className="align-top">
                                                        <td className="w-[38%] border border-slate-300 px-2 py-2 text-slate-800">
                                                            <span className="font-semibold">{idx + 1}.</span> {item.statement}
                                                        </td>
                                                        <td className="w-[40%] border border-slate-300 px-2 py-2 text-slate-700">
                                                            <ul className="list-disc pl-4">
                                                                {item.verification.map((entry) => (
                                                                    <li key={entry}>{entry}</li>
                                                                ))}
                                                            </ul>
                                                        </td>
                                                        {[5, 4, 3, 2, 1].map((value) => (
                                                            <td key={value} className="border border-slate-300 px-1 py-1 text-center">
                                                                <div className="flex items-center justify-center">
                                                                    <input
                                                                        id={`${item.id}-${value}`}
                                                                        type="radio"
                                                                        name={item.id}
                                                                        value={value}
                                                                        checked={String(ratings[item.id] || '') === String(value)}
                                                                        onChange={() => handleRatingChange(item.id, value)}
                                                                        className="peer sr-only"
                                                                    />
                                                                    <label
                                                                        htmlFor={`${item.id}-${value}`}
                                                                        className="inline-flex h-6 w-6 cursor-pointer items-center justify-center rounded-md border border-slate-400 bg-white text-[11px] font-semibold text-slate-600 transition hover:border-slate-500 hover:bg-slate-100 peer-checked:border-slate-700 peer-checked:bg-slate-700 peer-checked:text-white"
                                                                    >
                                                                        {value}
                                                                    </label>
                                                                </div>
                                                            </td>
                                                        ))}
                                                    </tr>
                                                ))}
                                            </React.Fragment>
                                        ) : (
                                            <tr>
                                                <td colSpan={7} className="border border-slate-300 px-4 py-8 text-center text-sm text-slate-500">
                                                    No benchmark items in this step yet.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}

                    {!canProceedNext && currentStep <= 3 && (
                        <div className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            Please answer all items in this section before proceeding.
                        </div>
                    )}

                    {submitError && (
                        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            {submitError}
                        </div>
                    )}

                    {submitSuccess && (
                        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                            {submitSuccess}
                        </div>
                    )}
                </div>

                <div className="flex items-center justify-between gap-2 border-t border-slate-200 px-4 py-3 sm:px-5">
                    <button
                        type="button"
                        onClick={handleClose}
                        className="inline-flex items-center rounded-md bg-slate-200 px-5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-300"
                    >
                        Cancel
                    </button>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={handlePrevious}
                            disabled={currentStep === 1 || isSubmitting}
                            className="inline-flex items-center rounded-md border border-slate-300 bg-white px-5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            onClick={currentStep < 4 ? handleNext : handleSubmit}
                            disabled={
                                currentStep < 4
                                    ? (!canProceedNext || isSubmitting)
                                    : (!canSubmitAllRatings || isSubmitting)
                            }
                            className="inline-flex items-center rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500"
                        >
                            {isSubmitting ? 'Saving...' : (currentStep < 4 ? 'Next' : 'Save Rating')}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
