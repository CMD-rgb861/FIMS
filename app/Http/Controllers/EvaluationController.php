<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\User;
use App\Models\Dean;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EvaluationController extends Controller
{
    use FacultyData;

    private const CACHE_TTL = 3600; // 1 hour

    public function index(Request $request)
    {
        $currentUser = $request->user();
        $canAccessEvaluation = $this->canAccessEvaluationForUser($currentUser);
        abort_if(!$canAccessEvaluation, 403);

        if ($currentUser->isDean()) {
        $evaluationProps = $this->commonInertiaProps($currentUser, [
            'schoolYears' => [],
            'terms' => [],
            'subjects' => [],
            'evaluations' => [],
            'evaluatedInstructors' => [],
            'selectedSchoolYear' => null,
            'selectedTerm' => 'all',
            'selectedSubject' => '',
            'isEvaluationClosed' => false,
            'evaluationStatusLabel' => 'Dean Access',
            'infoMessage' => 'Coming Soon: Evaluation module for Deans is currently under development.',
        ]);
        return Inertia::render('EvaluationPage', $evaluationProps);
    }

        // 1. Get all school years (cached)
        $schoolYears = $this->getSchoolYearsList();

        // 2. Get selected school year from query, default to active
        $selectedSchoolYearId = $request->query('term');
        if (!$selectedSchoolYearId || $selectedSchoolYearId === 'current' || $selectedSchoolYearId === 'all') {
            $activeSchoolYear = $this->getActiveSchoolYear();
            if (!$activeSchoolYear) {
                return $this->renderEmptyState($currentUser, 'No active school year is configured.');
            }
            $selectedSchoolYearId = $activeSchoolYear->id;
        } else {
            $selectedSchoolYearId = (int) $selectedSchoolYearId;
        }

        // 3. Fetch the selected school year object (for term label, etc.)
        $selectedSchoolYear = $this->getSchoolYearById($selectedSchoolYearId);
        if (!$selectedSchoolYear) {
            return $this->renderEmptyState($currentUser, 'Selected school year not found.');
        }

        // 4. Get faculty evaluations for the selected school year (optimized batch loading)
        $facultyEvaluations = $this->getFacultyUsersForEvaluation($currentUser, $selectedSchoolYearId, $selectedSchoolYear);

        // 5. Build dropdown options for faculty names
        $subjects = collect($facultyEvaluations)
            ->map(fn($f) => isset($f['instructor']) ? ['label' => $f['instructor'], 'value' => $f['instructor']] : null)
            ->filter()
            ->prepend(['label' => 'Select a name to evaluate', 'value' => ''])
            ->values()
            ->all();

        // 6. Get already submitted evaluations by the current user
        $evaluatedSubmissions = SupervisorEvaluationSubmission::query()
            ->with(['answers' => function ($q) {
                $q->select('submission_id', 'question_key', 'score');
            }])
            ->where('user_id', $currentUser->id)
            ->where('term_id', $selectedSchoolYearId)
            ->select(['id', 'user_id', 'instructor_id_no', 'college_id', 'unit_id', 'term_id', 'total_score', 'max_score', 'rating_percentage', 'submitted_at', 'status'])
            ->get();

        $evaluatedInstructors = $evaluatedSubmissions->pluck('instructor_id_no')->filter()->unique()->values()->all();
        $latestEvaluationsByInstructor = $evaluatedSubmissions
            ->sortByDesc('submitted_at')
            ->unique('instructor_id_no')
            ->keyBy('instructor_id_no');

        // 7. Build final evaluations array (without heavy subjects array)
        $evaluations = $this->buildEvaluationsArray($facultyEvaluations, $evaluatedInstructors, $latestEvaluationsByInstructor, $selectedSchoolYearId);

        // 8. Apply status filter
        $selectedTerm = $request->query('status', 'all');
        if ($selectedTerm === 'for-evaluation') {
            $evaluations = array_values(array_filter($evaluations, fn($item) => !$item['evaluated']));
        } elseif ($selectedTerm === 'evaluated') {
            $evaluations = array_values(array_filter($evaluations, fn($item) => $item['evaluated']));
        }

        // 9. Apply instructor name filter
        $selectedSubject = $request->query('subject', '');
        if (!empty($selectedSubject)) {
            $evaluations = array_values(array_filter($evaluations, fn($item) => ($item['instructor'] ?? '') === $selectedSubject));
        }

        // 10. Prepare Inertia props
        $evaluationProps = $this->commonInertiaProps($currentUser, [
            'schoolYears' => $schoolYears,
            'terms' => [
                ['label' => 'All', 'value' => 'all'],
                ['label' => 'For Evaluation', 'value' => 'for-evaluation'],
                ['label' => 'Evaluated', 'value' => 'evaluated'],
            ],
            'subjects' => $subjects,
            'evaluations' => $evaluations,
            'evaluatedInstructors' => $evaluatedInstructors,
            'selectedSchoolYear' => (string) $selectedSchoolYearId,
            'selectedTerm' => $selectedTerm,
            'selectedSubject' => $selectedSubject,
            'isEvaluationClosed' => false,
            'evaluationStatusLabel' => 'Open for Evaluation',
            'activeSchoolYear' => [
                'id' => $selectedSchoolYearId,
                'label' => "S.Y. {$selectedSchoolYear->school_year_from}-{$selectedSchoolYear->school_year_to} - " .
                    match ((int) $selectedSchoolYear->semester) {
                        1 => '1st Semester',
                        2 => '2nd Semester',
                        3 => 'Summer',
                        default => 'Semester ' . $selectedSchoolYear->semester,
                    }
            ],
        ]);

        return Inertia::render('EvaluationPage', $evaluationProps);
    }

    /**
     * Get active school year with caching and corruption detection.
     */
    private function getActiveSchoolYear()
    {
        $cached = Cache::get('active_school_year');
        if ($cached instanceof \stdClass && property_exists($cached, 'id') && property_exists($cached, 'school_year_from')) {
            return $cached;
        }

        $fresh = DB::connection('lnu_poes')
            ->table('school_years')
            ->where('is_active', 1)
            ->first();

        if ($fresh) {
            Cache::put('active_school_year', $fresh, self::CACHE_TTL);
        }

        return $fresh;
    }

    /**
     * Get a specific school year by ID.
     */
    private function getSchoolYearById(int $id)
    {
        return DB::connection('lnu_poes')
            ->table('school_years')
            ->where('id', $id)
            ->first();
    }

    /**
     * Get all school years for filter dropdown (cached with validation).
     */
    private function getSchoolYearsList(): array
    {
        $cached = Cache::get('school_years_list');
        if (is_array($cached) && !empty($cached) && isset($cached[0]['label'], $cached[0]['value'])) {
            return $cached;
        }

        $rows = DB::connection('lnu_poes')
            ->table('school_years')
            ->select(['id', 'school_year_from', 'school_year_to', 'semester'])
            ->orderByDesc('school_year_to')
            ->orderByDesc('school_year_from')
            ->orderByDesc('semester')
            ->get();

        if ($rows->isEmpty()) {
            $fallback = [
                ['label' => 'S.Y. 2025-2026 - 2nd Semester', 'value' => '2025-2026-2nd Semester'],
                ['label' => 'S.Y. 2025-2026 - 1st Semester', 'value' => '2025-2026-1st Semester'],
            ];
            Cache::put('school_years_list', $fallback, self::CACHE_TTL);
            return $fallback;
        }

        $result = $rows->map(function ($row) {
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
        })->values()->all();

        Cache::put('school_years_list', $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * Get faculty users eligible for evaluation based on role and selected school year.
     * Uses batch loading for courses to avoid N+1.
     */
    private function getFacultyUsersForEvaluation($user, int $schoolYearId, $schoolYear): array
    {
        if (!$user) {
            return [];
        }

        // SPECIAL CASE: Associate Dean -> Show all Unit Heads of the same college
        if ($user->isAssociateDean()) {
            $associateDean = $user->associateDean;

            if (!$associateDean || !$associateDean->college_id) {
                return [];
            }

            $collegeId = $associateDean->college_id;

            $unitHeadUsers = User::query()
                ->whereHas('unitHead')
                ->where('college_id', $collegeId)
                ->with(['college', 'unit'])
                ->orderBy('lastname')
                ->orderBy('firstname')
                ->get();

            if ($unitHeadUsers->isEmpty()) {
                return [];
            }

            $idNos = $unitHeadUsers->pluck('id_no')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (empty($idNos)) {
                return [];
            }

            $allCourses = DB::connection('lnu_poes')
                ->table('enrollment_courses')
                ->where('school_year_id', $schoolYearId)
                ->whereIn('id_no', $idNos)
                ->select([
                    'id_no',
                    'course_code',
                    'course_description'
                ])
                ->get();

            $coursesByIdNo = $allCourses->groupBy('id_no');

            $termLabel = $this->buildTermLabel($schoolYear);
            $unitHeadEvaluations = [];

            foreach ($unitHeadUsers as $unitHeadUser) {
                $courses = $coursesByIdNo->get($unitHeadUser->id_no);

                if (!$courses || $courses->isEmpty()) {
                    continue;
                }

                $firstCourse = $courses->first();

                $displayName = $this->buildDisplayName($unitHeadUser);
                $initials = $this->buildInitials($displayName, $unitHeadUser->id_no);

                $unitHeadEvaluations[] = [
                    'initials' => $initials,
                    'instructor' => $displayName,
                    'id_no' => $unitHeadUser->id_no,
                    'user_id' => $unitHeadUser->id,
                    'college_id' => $unitHeadUser->college_id,
                    'unit_id' => $unitHeadUser->unit_id,
                    'term' => $termLabel,
                    'academic_rank' => $unitHeadUser->academic_rank ?? 'N/A',
                    'college' => $unitHeadUser->college?->name ?? 'N/A',
                    'program' => $unitHeadUser->unit?->name ?? 'N/A',
                    'course_code' => $firstCourse->course_code ?? '',
                    'course_title' => $firstCourse->course_description ?? '',
                ];
            }

            return $unitHeadEvaluations;
        }

        // NORMAL CASE: Admin, Unit Head (Dean sees nothing)
        $facultyQuery = User::query()
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->with(['college', 'unit'])
            ->orderBy('lastname')
            ->orderBy('firstname'); ;  // eager load college and unit

        if ($user->isDean()) {
            return [];
        } elseif ($user->isUnitHead()) {
            $unitHead = $user->unitHead;
            if (!$unitHead || !$unitHead->unit_id) {
                return [];
            }
            $facultyQuery->where('unit_id', $unitHead->unit_id);
        } elseif (!$user->isAdmin()) {
            return [];
        }

        $facultyUsers = $facultyQuery->get(['id', 'id_no', 'firstname', 'lastname', 'middlename', 'extname', 'college_id', 'unit_id']);
        if ($facultyUsers->isEmpty()) {
            return [];
        }

        $idNos = $facultyUsers->pluck('id_no')->filter()->unique()->values()->all();
        if (empty($idNos)) {
            return [];
        }

        // Batch load courses
        $allCourses = DB::connection('lnu_poes')
            ->table('enrollment_courses')
            ->where('school_year_id', $schoolYearId)
            ->whereIn('id_no', $idNos)
            ->select(['id_no', 'course_code', 'course_description'])
            ->get();

        $coursesByIdNo = $allCourses->groupBy('id_no');
        $termLabel = $this->buildTermLabel($schoolYear);
        $facultyEvaluations = [];

        foreach ($facultyUsers as $faculty) {
            $courses = $coursesByIdNo->get($faculty->id_no);
            if (!$courses || $courses->isEmpty()) {
                continue;
            }

            $firstCourse = $courses->first();
            $displayName = $this->buildDisplayName($faculty);
            $initials = $this->buildInitials($displayName, $faculty->id_no);

            $facultyEvaluations[] = [
                'initials' => $initials,
                'instructor' => $displayName,
                'id_no' => $faculty->id_no,
                'user_id' => $faculty->id,
                'college_id' => $faculty->college_id,
                'unit_id' => $faculty->unit_id,
                'term' => $termLabel,
                'academic_rank' => $faculty->academic_rank ?? 'N/A',
                'college' => $faculty->college?->name ?? 'N/A',
                'program' => $faculty->unit?->name ?? 'N/A',
                // Minimal course info for submission
                'course_code' => $firstCourse->course_code ?? '',
                'course_title' => $firstCourse->course_description ?? '',
            ];
        }

        return $facultyEvaluations;
    }

    /**
     * Merge evaluation submissions into faculty list.
     */
    private function buildEvaluationsArray(array $facultyEvaluations, array $evaluatedInstructors, $latestEvaluationsByInstructor, int $selectedSchoolYearId): array
    {
        if (empty($facultyEvaluations)) {
            return [];
        }

        $evaluations = [];
        foreach ($facultyEvaluations as $faculty) {
            $latestEvaluation = $latestEvaluationsByInstructor->get($faculty['id_no']);

            $scores = [];
            $totalScore = 0;
            if ($latestEvaluation && $latestEvaluation->answers) {
                $answers = $latestEvaluation->answers->sortBy(fn($a) => (int) preg_replace('/[^0-9]/', '', $a->question_key));
                foreach ($answers as $answer) {
                    $scores[] = ['benchmark' => $answer->question_key, 'score' => $answer->score];
                    $totalScore += $answer->score;
                }
            }

            $maxScore = $latestEvaluation?->max_score ?? 75;
            $ratingPercentage = $latestEvaluation?->rating_percentage
                ?? ($maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0);

            $evaluations[] = [
                'initials' => $faculty['initials'],
                'instructor' => $faculty['instructor'],
                'instructor_id_no' => $faculty['id_no'],
                'id_no' => $faculty['id_no'],
                'user_id' => $faculty['user_id'] ?? null,
                'evaluated_user_id' => $faculty['user_id'] ?? null,
                'term' => $faculty['term'],
                'school_year_id' => $selectedSchoolYearId,
                'college_id' => $faculty['college_id'] ?? null,
                'unit_id' => $faculty['unit_id'] ?? null,
                'academic_rank' => $faculty['academic_rank'],
                'college' => $faculty['college'],
                'program' => $faculty['program'],
                'evaluated' => in_array($faculty['id_no'], $evaluatedInstructors, true),
                'code' => $faculty['course_code'] ?? '',
                'title' => $faculty['course_title'] ?? '',
                'evaluation_result' => $latestEvaluation ? [
                    'id' => $latestEvaluation->id,
                    'instructor_id_no' => $latestEvaluation->instructor_id_no,
                    'instructor' => $faculty['instructor'],
                    'course_code' => $latestEvaluation->course_code,
                    'course_title' => $latestEvaluation->course_title,
                    'college_id' => $latestEvaluation->college_id,
                    'unit_id' => $latestEvaluation->unit_id,
                    'term' => $latestEvaluation->term,
                    'scores' => $scores,
                    'total_score' => $latestEvaluation->total_score,
                    'max_score' => $latestEvaluation->max_score,
                    'rating_percentage' => $latestEvaluation->rating_percentage,
                    'submitted_at' => $latestEvaluation->submitted_at,
                    'status' => $latestEvaluation->status,
                ] : null,
            ];
        }

        return $evaluations;
    }

    private function buildDisplayName($faculty): string
    {
        $firstName = trim($faculty->firstname ?? '');
        $lastName = trim($faculty->lastname ?? '');
        $middleName = trim($faculty->middlename ?? '');
        $extName = trim($faculty->extname ?? '');

        $displayName = trim($firstName . ' ' . $lastName);
        // if (!empty($middleName)) {
        //     $displayName .= ' ' . $middleName;
        // }
        if (!empty($extName)) {
            $displayName .= ' ' . $extName;
        }
        return $displayName ?: $faculty->id_no;
    }

    private function buildInitials(string $displayName, string $idNo): string
    {
        $initials = '';
        $nameWords = preg_split('/\s+/', trim($displayName));
        foreach ($nameWords as $word) {
            if ($word !== '' && !preg_match('/^(Jr|Sr|III|IV|II)$/i', $word)) {
                $initials .= strtoupper(mb_substr($word, 0, 1));
                if (mb_strlen($initials) >= 2) break;
            }
        }
        return $initials ?: strtoupper(mb_substr($idNo, 0, 2));
    }

    private function buildTermLabel($schoolYear): string
    {
        return "S.Y. {$schoolYear->school_year_from}-{$schoolYear->school_year_to} - " .
            match ((int) $schoolYear->semester) {
                1 => '1st Semester',
                2 => '2nd Semester',
                3 => 'Summer',
                default => 'Semester ' . $schoolYear->semester,
            };
    }

    private function renderEmptyState($currentUser, string $errorMessage)
    {
        $evaluationProps = $this->commonInertiaProps($currentUser, [
            'schoolYears' => [],
            'terms' => [],
            'subjects' => [],
            'evaluations' => [],
            'evaluatedInstructors' => [],
            'selectedSchoolYear' => null,
            'selectedTerm' => 'all',
            'selectedSubject' => '',
            'isEvaluationClosed' => false,
            'evaluationStatusLabel' => 'No Active Semester',
            'error' => $errorMessage,
        ]);
        return Inertia::render('EvaluationPage', $evaluationProps);
    }
}