<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class ReportEvaluationController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        $query = DB::connection('lnu_poes')
        ->table('student_evaluation_submissions')
        ->where('instructor_id', $currentUser->id_no);

        // Optional raw scores
        $totalScores = (clone $query)->pluck('total_score');

        // Overall unique evaluators (all subjects combined)
        $submittedStudentsCount = (clone $query)
            ->distinct('student_id_number')
            ->count('student_id_number');

        // SUBJECT-BASED COMPUTATION
        $subjectEvaluations = (clone $query)
            ->select('subject_id')
            ->selectRaw('COUNT(DISTINCT student_id_number) as total_evaluators')
            ->selectRaw('AVG(total_score) as average_score')
            ->groupBy('subject_id')
            ->get()
            ->map(function ($item) {
                $item->weighted_score =
                    $item->total_evaluators * $item->average_score;

                return $item;
            });

        return Inertia::render('ReportsPage', [
            'totalScores' => $totalScores,
            'submittedStudentsCount' => $submittedStudentsCount,
            'subjectEvaluations' => $subjectEvaluations,
        ]);
    }

    public function breakdown(Request $request, string $instructor)
    {
        $instructorId = $request->query('instructor_id');
        $subjectId = $request->query('subject_id');
        $courseCode = $request->query('course_code');
        $yearSection = $request->query('year_section');

        $query = DB::connection('lnu_poes')
            ->table('student_evaluation_submissions');

        // Always filter by instructor_id if provided
        if (! empty($instructorId)) {
            $query->where('instructor_id', $instructorId);
        }

        // If subject_id is provided, use it directly (most accurate)
        if (! empty($subjectId)) {
            $query->where('subject_id', $subjectId);
        } elseif (! empty($courseCode)) {
            // Look up subject_id from enrollment_courses based on course_code
            $enrollmentQuery = DB::connection('lnu_poes')
                ->table('enrollment_courses')
                ->where('course_code', $courseCode);

            // If yearSection is provided, try to filter by section_code
            if (! empty($yearSection)) {
                // Extract section code from year_section (e.g., "Year 4-SF43:1" -> "SF43")
                // Try to match patterns like "SF43", "SF45", etc.
                $sectionPatterns = [];
                
                // Pattern 1: Extract uppercase letters followed by digits (SF43, etc)
                if (preg_match('/([A-Z]+\d+)/', $yearSection, $match)) {
                    $sectionPatterns[] = $match[1];
                }
                
                // Pattern 2: Extract the part after the dash
                if (preg_match('/\-([^\:]+)/', $yearSection, $match)) {
                    $sectionPatterns[] = trim($match[1]);
                }

                if (!empty($sectionPatterns)) {
                    $enrollmentQuery->where(function ($q) use ($sectionPatterns) {
                        foreach ($sectionPatterns as $pattern) {
                            $q->orWhere('section_code', 'LIKE', '%' . $pattern . '%');
                        }
                    });
                }
            }

            $enrollmentRecords = $enrollmentQuery->pluck('id');

            if ($enrollmentRecords->isEmpty()) {
                // If no match with section filter, try without section filter
                $enrollmentRecords = DB::connection('lnu_poes')
                    ->table('enrollment_courses')
                    ->where('course_code', $courseCode)
                    ->pluck('id');
            }

            if ($enrollmentRecords->isNotEmpty()) {
                $query->whereIn('subject_id', $enrollmentRecords);
            } else {
                // Return empty breakdown if no matching enrollment found
                $setBreakdown = [
                    [
                        'seq' => 1,
                        'course_code' => $courseCode ?: '-',
                        'year_section' => $yearSection ?: '-',
                        'no_of_students' => null,
                        'average_set_rating' => '-',
                        'weighted_set_score' => '-',
                        'no_of_students_value' => 0,
                        'average_set_rating_value' => null,
                        'weighted_set_score_value' => null,
                    ],
                ];

                return response()->json([
                    'instructor' => $instructor,
                    'set_breakdown' => $setBreakdown,
                    'sef_breakdown' => null,
                ]);
            }
        }

        $totalEvaluators = (clone $query)
            ->distinct('student_id_number')
            ->count('student_id_number');

        $averageScore = (clone $query)->avg('total_score');
        $averageScore = $averageScore !== null ? (float) $averageScore : null;

        $weightedScore = ($averageScore !== null)
            ? $averageScore * $totalEvaluators
            : null;

        $setBreakdown = [
            [
                'seq' => 1,
                'course_code' => $courseCode ?: '-',
                'year_section' => $yearSection ?: '-',
                'no_of_students' => $totalEvaluators > 0 ? $totalEvaluators : null,
                'average_set_rating' => $averageScore !== null ? number_format($averageScore, 2) : '-',
                'weighted_set_score' => $weightedScore !== null ? number_format($weightedScore, 2) : '-',
                'no_of_students_value' => $totalEvaluators,
                'average_set_rating_value' => $averageScore,
                'weighted_set_score_value' => $weightedScore,
            ],
        ];

        return response()->json([
            'instructor' => $instructor,
            'set_breakdown' => $setBreakdown,
            'sef_breakdown' => null,
        ]);
    }
}