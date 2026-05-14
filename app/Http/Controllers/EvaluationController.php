<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\SupervisorEvaluationSubmission;
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
        abort_if(! $canAccessEvaluation, 403); // Backend access gate remains

        $schoolYearRows = DB::connection('lnu_poes')
            ->table('school_years')
            ->select(['id', 'school_year_from', 'school_year_to', 'semester', 'is_active'])
            ->orderByDesc('id')
            ->get();

        $schoolYears = $schoolYearRows
            ->map(function ($row) {
                return [
                    'label' => sprintf(
                        'S.Y. %s-%s - %s',
                        $row->school_year_from,
                        $row->school_year_to,
                        $row->semester
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
        $selectedSchoolYear = $request->query('sy', $schoolYears[0]['value'] ?? '');
        $selectedSchoolYearId = ctype_digit((string) $selectedSchoolYear)
            ? (int) $selectedSchoolYear
            : null;
        $selectedSchoolYearRow = $selectedSchoolYearId !== null
            ? $schoolYearRows->firstWhere('id', $selectedSchoolYearId)
            : null;
        $scheduleWindow = null;

        if ($selectedSchoolYearId !== null) {
            $scheduleWindow = DB::connection('lnu_poes')
                ->table('evaluation_schedules')
                ->where('school_year_id', $selectedSchoolYearId)
                ->orderByDesc('id')
                ->first();
        }

        $today = Carbon::today();
        $isEvaluationOpen = false;

        if ($scheduleWindow) {
            $scheduleStart = Carbon::parse($scheduleWindow->date_from)->startOfDay();
            $scheduleEnd = Carbon::parse($scheduleWindow->date_extension ?: $scheduleWindow->date_to)->endOfDay();
            $isEvaluationOpen = $today->betweenIncluded($scheduleStart, $scheduleEnd);
        } elseif ($selectedSchoolYearRow) {
            $isEvaluationOpen = (bool) $selectedSchoolYearRow->is_active;
        }

        $isEvaluationClosed = !$isEvaluationOpen;
        $evaluationStatusLabel = $isEvaluationClosed ? 'Closed Evaluation' : 'Open for Evaluation';

        $selectedTerm = $request->query('term', 'all');
        $selectedSubject = $request->query('subject', '');

        $subjects = collect($facultyEvaluations)
            ->map(function ($f) {
                return ['label' => $f['instructor'], 'value' => $f['instructor']];
            })
            ->prepend(['label' => 'Select a name to evaluate', 'value' => ''])
            ->values()
            ->all();

        $evaluatedInstructors = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->pluck('instructor')
            ->unique()
            ->values()
            ->all();

        $latestEvaluationsByInstructor = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->latest('submitted_at')
            ->get()
            ->unique('instructor')
            ->keyBy('instructor');

        $evaluations = array_map(function ($faculty) use ($evaluatedInstructors, $latestEvaluationsByInstructor) {
            $primarySubject = $faculty['subjects'][0] ?? ['code' => '', 'title' => '', 'term' => ''];
            $latestEvaluation = $latestEvaluationsByInstructor->get($faculty['instructor']);
            $ratings = $latestEvaluation?->ratings ?? [];
            $scores = collect($ratings)
                ->map(function ($score, $benchmark) {
                    return [
                        'benchmark' => $benchmark,
                        'score' => (int) $score,
                    ];
                })
                ->sortBy(function ($row) {
                    return (int) preg_replace('/[^0-9]/', '', $row['benchmark']);
                })
                ->values()
                ->all();
            $totalScore = collect($scores)->sum('score');
            $ratingPercentage = round(($totalScore / 75) * 100, 2);

            return [
                'initials' => $faculty['initials'],
                'code' => $primarySubject['code'],
                'title' => $primarySubject['title'],
                'instructor' => $faculty['instructor'],
                'term' => $primarySubject['term'],
                'subjects' => $faculty['subjects'],
                'evaluated' => in_array($faculty['instructor'], $evaluatedInstructors, true),
                'evaluation_result' => $latestEvaluation ? [
                    'instructor' => $latestEvaluation->instructor,
                    'course_code' => $latestEvaluation->course_code,
                    'course_title' => $latestEvaluation->course_title,
                    'term' => $latestEvaluation->term,
                    'scores' => $scores,
                    'total_score' => $totalScore,
                    'rating_percentage' => $ratingPercentage,
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
