import React, { useMemo } from 'react';

const SECTION_QUESTIONS = [
    {
        title: 'A. Management of Teaching and Learning',
        items: [
            { benchmark: 'a1', question: 'Comes to class on time.' },
            { benchmark: 'a2', question: 'Submits updated syllabus, grade sheets, and other required reports on time.' },
            { benchmark: 'a3', question: 'Maximizes the allotted time/learning hours effectively.' },
            { benchmark: 'a4', question: 'Provides appropriate learning activities that facilitate critical thinking and creativity of students.' },
            { benchmark: 'a5', question: 'Guides students to learn on their own, reflect on new ideas and experiences, and make decisions in accomplishing given tasks.' },
            { benchmark: 'a6', question: 'Communicates constructive feedback to students for their academic growth.' },
        ],
    },
    {
        title: 'B. Content Knowledge, Pedagogy and Technology',
        items: [
            { benchmark: 'b7', question: 'Demonstrates extensive and broad knowledge of the subject/course.' },
            { benchmark: 'b8', question: 'Simplifies complex ideas in the lesson for ease of understanding.' },
            { benchmark: 'b9', question: 'Integrates contemporary issues and developments in the discipline and/or daily life activities in the syllabus.' },
            { benchmark: 'b10', question: 'Promotes active learning and student engagement by using appropriate teaching and learning resources including ICT tools and platforms.' },
            { benchmark: 'b11', question: 'Uses appropriate assessments (projects, exams, quizzes, assignments, etc.) aligned with the learning outcomes.' },
        ],
    },
    {
        title: 'C. Commitment and Transparency',
        items: [
            { benchmark: 'c12', question: 'Recognizes and values the unique diversity and individual differences among students.' },
            { benchmark: 'c13', question: 'Assists students with their learning challenges during consultation hours.' },
            { benchmark: 'c14', question: 'Provides immediate feedback on student outputs and performance.' },
            { benchmark: 'c15', question: 'Provides transparent and clear criteria in rating student performance.' },
        ],
    },
];

export default function EvaluationResultModal({ isOpen, result, onClose }) {
    const sectionResults = useMemo(() => {
        if (!result?.scores || !Array.isArray(result.scores)) {
            return SECTION_QUESTIONS.map((section) => ({
                ...section,
                items: section.items.map((item) => ({ ...item, score: null })),
            }));
        }

        const scoreByBenchmark = result.scores.reduce((acc, row) => {
            acc[String(row.benchmark).toLowerCase()] = row.score;
            return acc;
        }, {});

        return SECTION_QUESTIONS.map((section) => ({
            ...section,
            items: section.items.map((item) => ({
                ...item,
                score: scoreByBenchmark[item.benchmark] ?? null,
            })),
        }));
    }, [result]);

    const handlePrintPDF = async () => {
        try {
            // Get CSRF token from meta tag
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            // Make API call to your Laravel controller
            const response = await fetch(`/supervisor-evaluation/pdf/${result.id}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/pdf',
                    'X-CSRF-TOKEN': csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest'
                },
            });

            if (response.ok) {
                // Get the PDF blob
                const blob = await response.blob();
                
                // Create a URL for the blob
                const url = window.URL.createObjectURL(blob);
                
                // Open PDF in new tab
                window.open(url, '_blank');
                
                // Clean up the URL object after a delay
                setTimeout(() => window.URL.revokeObjectURL(url), 100);
            } else {
                const errorText = await response.text();
                console.error('Failed to generate PDF:', errorText);
                alert('Failed to generate PDF. Please try again.');
            }
        } catch (error) {
            console.error('Error generating PDF:', error);
            alert('An error occurred while generating the PDF.');
        }
    };

    if (!isOpen || !result) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-2 sm:p-4">
            <div className="w-full max-w-2xl rounded-2xl border border-slate-300 bg-white shadow-2xl">
                <div className="border-b border-slate-200 px-4 py-3 sm:px-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold text-slate-900">Evaluation Result</h2>
                            <p className="text-sm text-slate-600">{result.instructor}</p>
                            <p className="text-xs text-slate-500">{result.course_code} - {result.course_title}</p>
                            <p className="text-xs text-slate-500">{result.term}</p>
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-right">
                            <p className="text-xs text-slate-600">Total Score: <span className="font-semibold text-slate-900">{result.total_score}</span></p>
                            <p className="text-sm font-semibold text-slate-900">Total Rating: {result.rating_percentage}%</p>
                        </div>
                    </div>
                </div>

                <div className="max-h-[60vh] overflow-y-auto px-4 py-3 sm:px-5">
                    {sectionResults.map((section) => (
                        <div key={section.title} className="mb-4 rounded-lg border border-slate-200 last:mb-0">
                            <div className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800">
                                {section.title}
                            </div>
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-white">
                                    <tr>
                                        <th className="px-3 py-2 text-left font-semibold text-slate-700">Question</th>
                                        <th className="px-3 py-2 text-right font-semibold text-slate-700">Score</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 bg-white">
                                    {section.items.map((item, idx) => (
                                        <tr key={item.benchmark}>
                                            <td className="px-3 py-2 text-slate-700">
                                                <span className="font-semibold text-slate-800">{idx + 1}.</span> {item.question}
                                            </td>
                                            <td className="px-3 py-2 text-right font-semibold text-slate-900">
                                                {item.score ?? '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
                </div>

                <div className="flex justify-end gap-2 border-t border-slate-200 px-4 py-3 sm:px-5">
                    {/* <button
                        type="button"
                        onClick={handlePrintPDF}
                        className="inline-flex items-center rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                    >
                        <svg className="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Print PDF
                    </button> */}
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex items-center rounded-md bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
}