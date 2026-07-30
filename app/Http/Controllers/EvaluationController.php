<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\User;
use App\Models\Dean;
use App\Models\Unit;
use App\Models\FacultyDevelopmentForm;
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
                'statusOptions' => [
                    ['label' => 'All', 'value' => 'all'],
                    ['label' => 'For Evaluation', 'value' => 'for-evaluation'],
                    ['label' => 'Evaluated', 'value' => 'evaluated'],
                ],
                'units' => [],
                'subjects' => [],
                'evaluations' => [],
                'evaluatedInstructors' => [],
                'selectedSchoolYear' => null,
                'selectedTerm' => 'all',
                'selectedUnit' => '',
                'selectedSubject' => '',
                'searchQuery' => '',
                'currentPage' => 1,
                'totalEvaluations' => 0,
                'lastPage' => 1,
                'perPage' => 10,
                'showUnitFilter' => false,
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

        // 4. Get units for the filter dropdown
        $units = $this->getUnitsList($currentUser);

        // 5. Get faculty evaluations for the selected school year (optimized batch loading)
        $facultyEvaluations = $this->getFacultyUsersForEvaluation($currentUser, $selectedSchoolYearId, $selectedSchoolYear);

        // 6. Get filter and pagination params
        $searchQuery = $request->query('search', '');
        $currentPage = (int) $request->query('page', 1);
        $perPage = 10; // Max 10 items per page
        $selectedUnit = $request->query('unit', '');
        $selectedTerm = $request->query('status', 'all');
        $selectedSubject = $request->query('subject', '');

        // 7. Filter faculty evaluations by unit if selected
        if (!empty($selectedUnit)) {
            $facultyEvaluations = array_values(array_filter(
                $facultyEvaluations, 
                fn($f) => isset($f['unit_id']) && (string) $f['unit_id'] === $selectedUnit
            ));
        }

        // 8. Filter by search query (name or ID)
        if (!empty($searchQuery)) {
            $searchLower = strtolower($searchQuery);
            $facultyEvaluations = array_values(array_filter(
                $facultyEvaluations,
                fn($f) => 
                    strpos(strtolower($f['instructor'] ?? ''), $searchLower) !== false ||
                    strpos(strtolower($f['id_no'] ?? ''), $searchLower) !== false
            ));
        }

        // 9. Build dropdown options for faculty names (based on filtered results)
        $subjects = collect($facultyEvaluations)
            ->map(fn($f) => isset($f['instructor']) ? ['label' => $f['instructor'], 'value' => $f['instructor']] : null)
            ->filter()
            ->prepend(['label' => 'Select a name to evaluate', 'value' => ''])
            ->values()
            ->all();

        // 10. Get already submitted evaluations by the current user
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

        // 11. Build final evaluations array
        $allEvaluations = $this->buildEvaluationsArray($facultyEvaluations, $evaluatedInstructors, $latestEvaluationsByInstructor, $selectedSchoolYearId);

        // 12. Apply status filter
        if ($selectedTerm === 'for-evaluation') {
            $allEvaluations = array_values(array_filter($allEvaluations, fn($item) => !$item['evaluated']));
        } elseif ($selectedTerm === 'evaluated') {
            $allEvaluations = array_values(array_filter($allEvaluations, fn($item) => $item['evaluated']));
        }

        // 13. Apply instructor name filter
        if (!empty($selectedSubject)) {
            $allEvaluations = array_values(array_filter($allEvaluations, fn($item) => ($item['instructor'] ?? '') === $selectedSubject));
        }

        // 14. Paginate evaluations
        $totalEvaluations = count($allEvaluations);
        $lastPage = max(1, (int) ceil($totalEvaluations / $perPage));
        $currentPage = max(1, min($currentPage, $lastPage));
        $offset = ($currentPage - 1) * $perPage;
        $paginatedEvaluations = array_slice($allEvaluations, $offset, $perPage);

        // 15. Prepare Inertia props
        $showUnitFilter = $currentUser->isAdmin() || $currentUser->isDean() || $currentUser->isAssociateDean();

        $statusOptions = [
            ['label' => 'All', 'value' => 'all'],
            ['label' => 'For Evaluation', 'value' => 'for-evaluation'],
            ['label' => 'Evaluated', 'value' => 'evaluated'],
        ];

        $evaluationProps = $this->commonInertiaProps($currentUser, [
            'schoolYears' => $schoolYears,
            'statusOptions' => $statusOptions,
            'units' => $units,
            'subjects' => $subjects,
            'evaluations' => $paginatedEvaluations,
            'evaluatedInstructors' => $evaluatedInstructors,
            'selectedSchoolYear' => (string) $selectedSchoolYearId,
            'selectedTerm' => $selectedTerm,
            'selectedUnit' => $selectedUnit,
            'selectedSubject' => $selectedSubject,
            'searchQuery' => $searchQuery,
            'currentPage' => $currentPage,
            'totalEvaluations' => $totalEvaluations,
            'lastPage' => $lastPage,
            'perPage' => $perPage,
            'showUnitFilter' => $showUnitFilter,
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
     * Get units list for filter dropdown based on user role
     */
    private function getUnitsList($user): array
    {
        if ($user->isAdmin()) {
            $units = Unit::orderBy('name')->get();
            return $units->map(fn($unit) => [
                'label' => $unit->name,
                'value' => (string) $unit->id,
            ])->prepend(['label' => 'All Units', 'value' => ''])->values()->all();
        }

        if ($user->isUnitHead()) {
            $unitHead = $user->unitHead;
            if (!$unitHead || !$unitHead->unit_id) {
                return [];
            }
            
            $unit = Unit::find($unitHead->unit_id);
            return $unit ? [
                ['label' => $unit->name, 'value' => (string) $unit->id]
            ] : [];
        }

        if ($user->isAssociateDean()) {
            $associateDean = $user->associateDean;
            if (!$associateDean || !$associateDean->college_id) {
                return [];
            }

            $units = Unit::where('department_id', $associateDean->college_id)
                ->orderBy('name')
                ->get();
            
            return $units->map(fn($unit) => [
                'label' => $unit->name,
                'value' => (string) $unit->id,
            ])->prepend(['label' => 'All Units', 'value' => ''])->values()->all();
        }

        return [];
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

        if (!$fresh) {
            $fresh = DB::connection('lnu_poes')
                ->table('school_years')
                ->orderByDesc('school_year_from')
                ->orderByDesc('semester')
                ->first();
        }

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

        $minYear = 2025;
        $minSemester = 2;

        $rows = DB::connection('lnu_poes')
            ->table('school_years')
            ->select(['id', 'school_year_from', 'school_year_to', 'semester'])
            ->where(function($query) use ($minYear, $minSemester) {
                $query->where('school_year_from', '>', $minYear)
                    ->orWhere(function($q) use ($minYear, $minSemester) {
                        $q->where('school_year_from', '=', $minYear)
                            ->where('semester', '>=', $minSemester);
                    });
            })
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
     */
    private function getFacultyUsersForEvaluation($user, int $schoolYearId, $schoolYear): array
    {
        if (!$user) {
            return [];
        }

        if ($user->isAssociateDean()) {
            $associateDean = $user->associateDean;
            if (!$associateDean || !$associateDean->college_id) {
                return [];
            }

            $collegeId = $associateDean->college_id;
            $unitHeadUsers = User::query()
                ->whereHas('unitHead')
                ->where('college_id', $collegeId)
                ->where('id', '!=', $user->id)
                ->with(['college', 'unit'])
                ->orderBy('lastname')
                ->orderBy('firstname')
                ->get();

            if ($unitHeadUsers->isEmpty()) {
                return [];
            }

            $idNos = $unitHeadUsers->pluck('id_no')->filter()->unique()->values()->all();
            if (empty($idNos)) {
                return [];
            }

            $allCourses = DB::connection('lnu_poes')
                ->table('enrollment_courses')
                ->where('school_year_id', $schoolYearId)
                ->whereIn('id_no', $idNos)
                ->select(['id_no', 'course_code', 'course_description'])
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
                $fedaSubmitted = FacultyDevelopmentForm::hasSubmittedFormFor($unitHeadUser->id_no, $schoolYearId);

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
                    'feda_submitted' => $fedaSubmitted,
                ];
            }

            return $unitHeadEvaluations;
        }

        $facultyQuery = User::query()
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->with(['college', 'unit'])
            ->orderBy('lastname')
            ->orderBy('firstname');

        if ($user->isDean()) {
            return [];
        } elseif ($user->isUnitHead()) {
            $unitHead = $user->unitHead;
            if (!$unitHead || !$unitHead->unit_id) {
                return [];
            }
            $facultyQuery->where('unit_id', $unitHead->unit_id)->where('id', '!=', $user->id);
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
            $fedaSubmitted = FacultyDevelopmentForm::hasSubmittedFormFor($faculty->id_no, $schoolYearId);

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
                'course_code' => $firstCourse->course_code ?? '',
                'course_title' => $firstCourse->course_description ?? '',
                'feda_submitted' => $fedaSubmitted,
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

            $fedaSubmitted = FacultyDevelopmentForm::hasSubmittedFormFor($faculty['id_no'], $selectedSchoolYearId);

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
                'feda_submitted' => $fedaSubmitted,
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
        $extName = trim($faculty->extname ?? '');

        $displayName = trim($firstName . ' ' . $lastName);
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
            'statusOptions' => [
                ['label' => 'All', 'value' => 'all'],
                ['label' => 'For Evaluation', 'value' => 'for-evaluation'],
                ['label' => 'Evaluated', 'value' => 'evaluated'],
            ],
            'units' => [],
            'subjects' => [],
            'evaluations' => [],
            'evaluatedInstructors' => [],
            'selectedSchoolYear' => null,
            'selectedTerm' => 'all',
            'selectedUnit' => '',
            'selectedSubject' => '',
            'searchQuery' => '',
            'currentPage' => 1,
            'totalEvaluations' => 0,
            'lastPage' => 1,
            'perPage' => 10,
            'showUnitFilter' => false,
            'isEvaluationClosed' => false,
            'evaluationStatusLabel' => 'No Active Semester',
            'error' => $errorMessage,
        ]);
        return Inertia::render('EvaluationPage', $evaluationProps);
    }
}