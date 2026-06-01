<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\SupervisorEvaluationAnswer;
use App\Models\Poes\PoesSubjects;
use App\Models\UnitHeadGrade;
use Carbon\Carbon;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EvaluationController extends Controller
{
    use FacultyData;

    public function index(Request $request)
    {
        $facultyEvaluations = $this->getFacultyEvaluations();
        $currentUser = $request->user();
        $canAccessEvaluation = $this->canAccessEvaluationForUser($currentUser);
        abort_if(! $canAccessEvaluation, 403);

        $activeSchoolYear = DB::connection('lnu_poes')
            ->table('school_years')
            ->where('is_active', 1)
            ->first();

        $activeSchoolYearId = $activeSchoolYear?->id;

        $schoolYearRows = DB::connection('lnu_poes')
            ->table('school_years')
            ->select(['id', 'school_year_from', 'school_year_to', 'semester', 'is_active'])
            ->orderByDesc('school_year_to')
            ->orderByDesc('school_year_from')
            ->orderByDesc('semester')
            ->get();

        $schoolYears = $schoolYearRows
            ->map(function ($row) {
                return [
                    'label' => sprintf(
                        'S.Y. %s-%s - %s',
                        $row->school_year_from,
                        $row->school_year_to,
                        match ((int) $row->semester) {
                            1 => '1st Semester',
                            2 => '2nd Semester',
                            3 => 'Summer',
                            default => 'Semester ' . $row->semester,
                        }
                    ),
                    'value' => (string) $row->id,
                ];
            })
            ->values()
            ->all();

        if (empty($schoolYears)) {
            $schoolYears = [
                ['label' => 'S.Y. 2025-2026 - 2nd Semester', 'value' => '2025-2026-2nd Semester'],
                ['label' => 'S.Y. 2025-2026 - 1st Semester', 'value' => '2025-2026-1st Semester'],
            ];
        }

        $terms = [
            ['label' => 'All', 'value' => 'all'],
            ['label' => 'For Evaluation', 'value' => 'for-evaluation'],
            ['label' => 'Evaluated', 'value' => 'evaluated'],
        ];
        
        $selectedSchoolYearParam = $request->query('term', $request->query('sy', null));
        $selectedSchoolYear = (!$selectedSchoolYearParam || $selectedSchoolYearParam === 'current' || $selectedSchoolYearParam === 'all')
            ? (string) ($activeSchoolYearId ?? ($schoolYears[0]['value'] ?? ''))
            : (is_numeric($selectedSchoolYearParam) ? (string) (int) $selectedSchoolYearParam : (string) ($activeSchoolYearId ?? ($schoolYears[0]['value'] ?? '')));

        $selectedSchoolYearId = ctype_digit((string) $selectedSchoolYear)
            ? (int) $selectedSchoolYear
            : null;
        $selectedSchoolYearRow = $selectedSchoolYearId !== null
            ? $schoolYearRows->firstWhere('id', $selectedSchoolYearId)
            : null;
        // Schedule-based opening logic can be restored later if needed.
        // $scheduleWindow = null;
        // if ($selectedSchoolYearId !== null) {
        //     $scheduleWindow = DB::connection('lnu_poes')
        //         ->table('evaluation_schedules')
        //         ->where('school_year_id', $selectedSchoolYearId)
        //         ->orderByDesc('id')
        //         ->first();
        // }
        //
        // $today = Carbon::today();
        // $isEvaluationOpen = false;
        //
        // if ($scheduleWindow) {
        //     $scheduleStart = Carbon::parse($scheduleWindow->date_from)->startOfDay();
        //     $scheduleEnd = Carbon::parse($scheduleWindow->date_extension ?: $scheduleWindow->date_to)->endOfDay();
        //     $isEvaluationOpen = $today->betweenIncluded($scheduleStart, $scheduleEnd);
        // } elseif ($selectedSchoolYearRow) {
        //     $isEvaluationOpen = (bool) $selectedSchoolYearRow->is_active;
        // }

        $isEvaluationOpen = true;

        $isEvaluationClosed = !$isEvaluationOpen;
        $evaluationStatusLabel = $isEvaluationClosed ? 'Closed Evaluation' : 'Open for Evaluation';

        $selectedTerm = $request->query(
            'status',
            in_array($request->query('term'), ['all', 'for-evaluation', 'evaluated'], true)
                ? $request->query('term')
                : 'all'
        );
        $selectedSubject = $request->query('subject', '');

        $subjects = collect($facultyEvaluations)
            ->map(function ($f) {
                return ['label' => $f['instructor'], 'value' => $f['instructor']];
            })
            ->prepend(['label' => 'Select a name to evaluate', 'value' => ''])
            ->values()
            ->all();

        // Get evaluated instructors with their submissions (with answers)
        $evaluatedSubmissions = SupervisorEvaluationSubmission::query()
            ->with('answers')
            ->where('user_id', $currentUser->id)
            ->get();

        $evaluatedInstructors = $evaluatedSubmissions
            ->pluck('instructor')
            ->unique()
            ->values()
            ->all();

        $latestEvaluationsByInstructor = $evaluatedSubmissions
            ->sortByDesc('submitted_at')
            ->unique('instructor')
            ->keyBy('instructor');

        $evaluations = array_map(function ($faculty) use ($evaluatedInstructors, $latestEvaluationsByInstructor, $selectedSchoolYearId) {
            $primarySubject = $faculty['subjects'][0] ?? ['code' => '', 'title' => '', 'term' => ''];
            $latestEvaluation = $latestEvaluationsByInstructor->get($faculty['instructor']);
            
            // Build scores from answers instead of ratings JSON
            $scores = [];
            $totalScore = 0;
            
            if ($latestEvaluation && $latestEvaluation->answers) {
                $answers = $latestEvaluation->answers->sortBy(function ($answer) {
                    // Extract number from question_key (e.g., "q1" -> 1)
                    return (int) preg_replace('/[^0-9]/', '', $answer->question_key);
                });
                
                foreach ($answers as $answer) {
                    $scores[] = [
                        'benchmark' => $answer->question_key,
                        'score' => $answer->score,
                    ];
                    $totalScore += $answer->score;
                }
            }
            
            $maxScore = $latestEvaluation?->max_score ?? 75;
            $ratingPercentage = $latestEvaluation?->rating_percentage 
                ?? ($maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0);

            return [
                'initials' => $faculty['initials'],
                'code' => $primarySubject['code'],
                'title' => $primarySubject['title'],
                'instructor' => $faculty['instructor'],
                'term' => $primarySubject['term'],
                'school_year_id' => $selectedSchoolYearId,
                'subjects' => $faculty['subjects'],
                'evaluated' => in_array($faculty['instructor'], $evaluatedInstructors, true),
                'evaluation_result' => $latestEvaluation ? [
                    'id' => $latestEvaluation->id,
                    'instructor' => $latestEvaluation->instructor,
                    'course_code' => $latestEvaluation->course_code,
                    'course_title' => $latestEvaluation->course_title,
                    'term' => $latestEvaluation->term,
                    'scores' => $scores,
                    'total_score' => $latestEvaluation->total_score,
                    'max_score' => $latestEvaluation->max_score,
                    'rating_percentage' => $latestEvaluation->rating_percentage,
                    'submitted_at' => $latestEvaluation->submitted_at,
                    'status' => $latestEvaluation->status,
                ] : null,
            ];
        }, $facultyEvaluations);

        if ($selectedTerm === 'for-evaluation') {
            $evaluations = array_values(array_filter($evaluations, function ($item) {
                return !$item['evaluated'];
            }));
        }

        if ($selectedTerm === 'evaluated') {
            $evaluations = array_values(array_filter($evaluations, function ($item) {
                return $item['evaluated'];
            }));
        }

        if (!empty($selectedSubject)) {
            $evaluations = array_values(array_filter($evaluations, function ($item) use ($selectedSubject) {
                return $item['instructor'] === $selectedSubject;
            }));
        }

        $evaluationProps = $this->commonInertiaProps($currentUser, [
            'schoolYears' => $schoolYears,
            'terms' => $terms,
            'subjects' => $subjects,
            'evaluations' => $evaluations,
            'evaluatedInstructors' => $evaluatedInstructors,
            'selectedSchoolYear' => $selectedSchoolYear,
            'selectedTerm' => $selectedTerm,
            'selectedSubject' => $selectedSubject,
            'isEvaluationClosed' => $isEvaluationClosed,
            'evaluationStatusLabel' => $evaluationStatusLabel,
            'hasPendingEvaluations' => count($evaluatedInstructors) < count($facultyEvaluations),
        ]);

        return Inertia::render('EvaluationPage', $evaluationProps);
    }
}