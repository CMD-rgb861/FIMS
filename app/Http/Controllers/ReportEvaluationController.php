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

        if (! empty($instructorId)) {
            $query->where('instructor_id', $instructorId);
        }

        if (! empty($subjectId)) {
            $query->where('subject_id', $subjectId);
        } elseif (! empty($courseCode)) {
            // if subject_id is not available, filter by course_code
            $query->where('course_code', $courseCode);
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