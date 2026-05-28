<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
// use App\Models\Poes\PoesEvalSubmissions;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\UnitHeadGrade;
use App\Models\User;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    use FacultyData;

    public function index(Request $request)
    {
        $currentUser = $request->user();
        
        // Redirect faculty users to their dedicated view
        if ($currentUser && $currentUser->isFaculty()) {
            return $this->facultyIndex($request);
        }
        
        $termParam = $request->query('term', null);
        $facultySearch = $request->query('faculty_search', '');
        $facultyPage = (int) $request->query('faculty_page', 1);
        $perPage = (int) $request->query('faculty_per_page', 12);
        $perPage = max(6, min($perPage, 48));

        $activeTerm = DB::connection('lnu_poes')
            ->table('school_years')
            ->where('is_active', 1)
            ->first();

        $activeTermId = $activeTerm?->id;

        $termId = (!$termParam || $termParam === 'current' || $termParam === 'all')
            ? $activeTermId
            : (is_numeric($termParam) ? (int) $termParam : $activeTermId);

        $facultyEvaluations = $this->getLocalFacultyUsers($currentUser, $termId, $facultySearch);

        $allSubmissionsQuery = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id);

        if ($termId) {
            $allSubmissionsQuery->where('term', $termId);
        }

        $submittedEvaluationsCount = (clone $allSubmissionsQuery)->count();

        $evaluatedInstructors = (clone $allSubmissionsQuery)
            ->select('instructor')
            ->distinct()
            ->pluck('instructor');

        $averageRating = $this->getUserSefAverageRating(
            (int) $currentUser->id,
            $termId
        );

        $passedGradesCount = UnitHeadGrade::query()
            ->where('user_id', $currentUser->id)
            ->whereNotNull('grade')
            ->count();

        $recentReportsPage = SupervisorEvaluationSubmission::query()
            ->with('answers')
            ->where('user_id', $currentUser->id)
            ->when($termId, function ($q) use ($termId) {
                $q->where('term', $termId);
            })
            ->latest('submitted_at')
            ->paginate(10);

        $recentItems = collect($recentReportsPage->items());
        $recentGradeKeys = $recentItems
            ->map(fn ($s) => (string) ($s->instructor . '||' . $s->course_code))
            ->unique()
            ->values();

        $unitHeadGrades = collect();
        if ($recentItems->isNotEmpty()) {
            $unitHeadGrades = UnitHeadGrade::query()
                ->where('user_id', $currentUser->id)
                ->where(function ($q) use ($recentItems) {
                    foreach ($recentItems as $item) {
                        $q->orWhere(function ($nested) use ($item) {
                            $nested->where('instructor', $item->instructor)
                                ->where('course_code', $item->course_code);
                        });
                    }
                })
                ->orderByDesc('submitted_at')
                ->get()
                ->unique(fn ($g) => $g->instructor . '||' . $g->course_code)
                ->keyBy(fn ($g) => (string) ($g->instructor . '||' . $g->course_code));
        }

        $recentReports = $recentItems
            ->map(function ($submission) use ($unitHeadGrades, $recentGradeKeys) {
                // Use pre-calculated rating_percentage from the submission
                $ratingPercentage = $submission->rating_percentage ?? 0;

                $gradeKey = (string) ($submission->instructor . '||' . $submission->course_code);
                $latestGrade = $recentGradeKeys->contains($gradeKey)
                    ? $unitHeadGrades->get($gradeKey)
                    : null;

                return [
                    'instructor' => $submission->instructor,
                    'course_code' => $submission->course_code,
                    'course_title' => $submission->course_title,
                    'rating_percentage' => $ratingPercentage,
                    'final_grade' => $latestGrade ? number_format((float) $latestGrade->grade, 1) : null,
                    'submitted_at' => optional($submission->submitted_at)->format('M d, Y h:i A') ?? '-',
                ];
            })
            ->values()
            ->all();

        $facultyUserIds = collect($facultyEvaluations)
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $sefAveragesByUser = $this->getFacultySefAverages($facultyUserIds, $termId);

        // PAGINATE FACULTY LIST - FIXED PAGINATION
        $facultyCollection = collect($facultyEvaluations);

        $totalFaculty = $facultyCollection->count();
        $lastPage = max(1, (int) ceil($totalFaculty / $perPage));

        $facultyPage = max(1, min($facultyPage, $lastPage));

        $offset = ($facultyPage - 1) * $perPage;

        $paginatedFaculty = $facultyCollection
            ->slice($offset, $perPage)
            ->values();

        $facultyList = $paginatedFaculty
            ->map(function ($faculty, $index) use ($evaluatedInstructors, $sefAveragesByUser, $termId, $offset) {

                $subjectsCount = (int) ($faculty['subjects_count'] ?? 0);

                $overallSetRating = null;
                $overallSefRating = null;

                if ($subjectsCount > 0) {
                    $overallSetRating = $this->getFacultyOverallSetRating($faculty['instructor'] ?? null, $termId);

                    $averageSefRating = $sefAveragesByUser->get((int) ($faculty['user_id'] ?? 0));
                    if ($averageSefRating !== null) {
                        $overallSefRating = $averageSefRating;
                    }
                }

                return [
                    'initials' => $faculty['initials'],
                    'instructor' => $faculty['instructor'],
                    'subjects_count' => $subjectsCount,
                    'evaluated' => $evaluatedInstructors->contains($faculty['instructor']),

                    'employee_id_no' =>
                        $faculty['id_no']
                        ?? 'EMP-' . str_pad((string) ($offset + $index + 1), 3, '0', STR_PAD_LEFT),

                    'detail_url' => route('reports.faculty', [
                        'instructor' => $faculty['id_no']
                    ]),

                    'overall_set_rating' => $overallSetRating,
                    'overall_sef_rating' => $overallSefRating,
                ];
            })
            ->values()
            ->all();

        $schoolYears = DB::connection('lnu_poes')
            ->table('school_years')
            ->select(
                'id as value',
                DB::raw("
                    CONCAT(
                        school_year_from,
                        '-',
                        school_year_to,
                        ' - ',
                        CASE
                            WHEN semester = 1 THEN '1st Semester'
                            WHEN semester = 2 THEN '2nd Semester'
                            WHEN semester = 3 THEN 'Summer'
                            ELSE CONCAT('Semester ', semester)
                        END
                    ) as label
                ")
            )
            ->orderByDesc('school_year_to')
            ->orderByDesc('school_year_from')
            ->orderByDesc('semester')
            ->get()
            ->toArray();

        $reportsProps = $this->commonInertiaProps($currentUser, [
            'reportSummary' => [
                [
                    'label' => 'Submitted Evaluations',
                    'value' => $submittedEvaluationsCount,
                    'helper' => 'Total evaluation forms submitted.',
                ],
                [
                    'label' => 'Instructors Evaluated',
                    'value' => $evaluatedInstructors->count(),
                    'helper' => 'Unique faculty members evaluated.',
                ],
                [
                    'label' => 'Average Rating',
                    'value' => $averageRating . '%',
                    'helper' => 'Average score across all evaluations.',
                ],
                [
                    'label' => 'Posted Grades',
                    'value' => $passedGradesCount,
                    'helper' => 'Unit head grades recorded.',
                ],
            ],
            'recentReports' => $recentReports,
            'recentReportsPagination' => [
                'current_page' => $recentReportsPage->currentPage(),
                'last_page' => $recentReportsPage->lastPage(),
                'per_page' => $recentReportsPage->perPage(),
                'total' => $recentReportsPage->total(),
            ],
            'facultyList' => [
                'data' => $facultyList,
                'current_page' => $facultyPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $totalFaculty,
            ],
            'hasPendingEvaluations' => $evaluatedInstructors->count() < count($facultyEvaluations),
            'schoolYears' => $schoolYears,
            'selectedSchoolYear' => $termId,
        ]);

        return Inertia::render('ReportsPage', $reportsProps);
    }

    public function faculty(Request $request, string $instructor)
    {
        $currentUser = $request->user();
        $termParam = $request->query('term', null);
        
        $activeTerm = DB::connection('lnu_poes')
            ->table('school_years')
            ->where('is_active', 1)
            ->first();

        $activeTermId = $activeTerm?->id;
        
        $selectedSchoolYear = (!$termParam || $termParam === 'current' || $termParam === 'all')
            ? $activeTermId
            : (is_numeric($termParam) ? (int) $termParam : $activeTermId);

        $facultyEvaluations = $this->getLocalFacultyUsers($currentUser, $selectedSchoolYear);

        $facultyCollection = collect($facultyEvaluations);
        $facultyIndex = $facultyCollection->search(function ($faculty) use ($instructor) {
            return strcasecmp($faculty['id_no'], $instructor) === 0;
        });

        abort_if($facultyIndex === false, 404);

        $facultyMeta = $facultyCollection->get($facultyIndex);
        $employeeIdNo = $facultyMeta['id_no'] ?? 'EMP-' . str_pad((string) ($facultyIndex + 1), 3, '0', STR_PAD_LEFT);

        $perPage = (int) $request->input('per_page', 10);
        $perPage = max(10, min($perPage, 200));

        $subjectsQuery = DB::connection('lnu_poes')
            ->table('enrollment_courses as ec');

        $instructorTokens = $this->extractInstructorTokens($facultyMeta['instructor'] ?? null);

        $subjectsQuery->where(function ($nested) use ($instructorTokens, $facultyMeta) {
            $hasTokens = ! empty($instructorTokens);

            if ($hasTokens) {
                foreach ($instructorTokens as $token) {
                    $nested->where('ec.instructor', 'like', '%' . $token . '%');
                }
            }

            if (! empty($facultyMeta['id_no'])) {
                if ($hasTokens) {
                    $nested->orWhere('ec.id_no', $facultyMeta['id_no']);
                } else {
                    $nested->where('ec.id_no', $facultyMeta['id_no']);
                }
            }
        });

        if ($selectedSchoolYear !== '') {
            $subjectsQuery->where('school_year_id', $selectedSchoolYear);
        }

        $sectionExpr = DB::raw('COALESCE(ec.section_code, "NO_SECTION")');

        $subjectsPage = $subjectsQuery
            ->selectRaw('MIN(ec.id) as id')
            ->select('ec.course_code', 'ec.school_year_id')
            ->selectRaw('MAX(ec.course_description) as course_description')
            ->selectRaw("MAX(NULLIF(TRIM(ec.year_level), '')) as year_level")
            ->selectRaw('COALESCE(ec.section_code, "NO_SECTION") as section_code')
            ->where('ec.id_no', $instructor)
            ->groupBy('ec.course_code', $sectionExpr, 'ec.school_year_id')
            ->orderBy('ec.course_code')
            ->paginate($perPage)
            ->appends($request->query());
        $subjectRows = collect($subjectsPage->items());
        $courseCodes = $subjectRows
            ->pluck('course_code')
            ->filter()
            ->unique()
            ->values();

        $latestSubmissionByCourse = collect();
        $latestGradesByCourse = collect();

        if ($courseCodes->isNotEmpty()) {
            $latestSubmissionIds = SupervisorEvaluationSubmission::query()
                ->where('user_id', $currentUser->id)
                ->where('instructor', $facultyMeta['instructor'])
                ->whereIn('course_code', $courseCodes->all())
                ->selectRaw('MAX(id) as latest_id')
                ->groupBy('course_code')
                ->pluck('latest_id');

            if ($latestSubmissionIds->isNotEmpty()) {
                $latestSubmissionByCourse = SupervisorEvaluationSubmission::query()
                    ->with('answers')
                    ->whereIn('id', $latestSubmissionIds->all(), 'and', false)
                    ->get()
                    ->keyBy(fn ($submission) => (string) ($submission->course_code ?? ''));
            }

            $latestGradeIds = UnitHeadGrade::query()
                ->where('user_id', $currentUser->id)
                ->where('instructor', $facultyMeta['instructor'])
                ->whereIn('course_code', $courseCodes->all())
                ->selectRaw('MAX(id) as latest_id')
                ->groupBy('course_code')
                ->pluck('latest_id');

            if ($latestGradeIds->isNotEmpty()) {
                $latestGradesByCourse = UnitHeadGrade::query()
                    ->whereIn('id', $latestGradeIds->all(), 'and', false)
                    ->get()
                    ->keyBy(fn ($grade) => (string) ($grade->course_code ?? ''));
            }
        }

        $instructorId = null;
        if (($facultyMeta['id_no'] ?? null) !== null) {
            $digits = preg_replace('/\D+/', '', (string) $facultyMeta['id_no']);
            if ($digits !== '') {
                $instructorId = (int) $digits;
            }
        }

        $tableRows = $subjectRows
            ->values()
            ->map(function ($subject, $index) use ($employeeIdNo, $facultyMeta, $latestSubmissionByCourse, $latestGradesByCourse, $subjectsPage, $instructorId, $selectedSchoolYear) {
                $courseCode = (string) ($subject->course_code ?? '');
                $subjectId = $subject->id ?? null;

                $submission = $latestSubmissionByCourse->get($courseCode);
                $grade = $latestGradesByCourse->get($courseCode);

                // Use pre-calculated rating_percentage from the submission
                $sefScore = $submission?->rating_percentage ?? null;
                $totalScore = $submission?->total_score ?? 0;

                $setGrade = $grade?->grade;
                $status = ($setGrade !== null || $submission !== null) ? 'Evaluated' : 'For Evaluation';

                $sectionCode = trim((string) ($subject->section_code ?? ''));
                $yearLevel = $this->extractYearLevelFromSectionCode($sectionCode) ?? trim((string) ($subject->year_level ?? ''));
                if ($yearLevel !== '' && $sectionCode !== '') {
                    $term = $yearLevel . '-' . $sectionCode;
                } elseif ($yearLevel !== '') {
                    $term = $yearLevel;
                } elseif ($sectionCode !== '') {
                    $term = $sectionCode;
                } else {
                    $term = $subject->year_section ?? $subject->term ?? '-';
                }

                $rowId = ((int) ($subjectsPage->firstItem() ?? 1)) + $index;

                return [
                    'id' => $rowId,
                    'school_year_id_value' => $subject->school_year_id ?? null,
                    'course_description' => $subject->course_description ?? '-',
                    'employee_id_no' => $employeeIdNo,
                    'employee_name' => $facultyMeta['instructor'],
                    'course_code' => $courseCode,
                    'year_section' => $term,
                    'set_score' => $setGrade !== null ? number_format((float) $setGrade, 2) : '-',
                    'sef_score' => $sefScore !== null ? number_format($sefScore, 2) . '%' : '-',
                    'sef_total_score' => $sefScore !== null ? $totalScore : null,
                    'sef_rating' => $sefScore !== null ? number_format($sefScore, 2) . '%' : '-',
                    'status' => $status,
                    'action_url' => route('evaluation', [
                        'subject' => $facultyMeta['instructor'],
                        'term' => 'all',
                    ]),
                    'action_label' => 'View',
                    'breakdown_url' => route('reports.faculty.breakdown', ['instructor' => $facultyMeta['instructor']]) . '?' . http_build_query([
                        'instructor_id' => $instructorId,
                        'subject_id' => $subjectId,
                        'course_code' => $courseCode,
                        'year_section' => $term,
                        'term' => $selectedSchoolYear,
                    ]),
                    'set_breakdown' => [
                        [
                            'seq' => 1,
                            'course_code' => $courseCode,
                            'year_section' => $term,
                            'no_of_students' => null,
                            'average_set_rating' => $setGrade !== null ? number_format((float) $setGrade, 2) : '-',
                            'weighted_set_score' => '-',
                            'total_set' => '-',
                            'no_of_students_value' => null,
                            'average_set_rating_value' => $setGrade !== null ? (float) $setGrade : null,
                            'weighted_set_score_value' => null,
                            'total_set_value' => null,
                        ],
                    ],
                ];
            })
            ->all();

        $overallSetRatingValue = $latestGradesByCourse
            ->pluck('grade')
            ->filter(fn ($grade) => $grade !== null)
            ->avg();
            
        $overallSetRating = $overallSetRatingValue !== null ? round((float) $overallSetRatingValue, 2) : null;
        

            //SCHOOL YEARS FOR FILTERS
        $schoolYears = DB::connection('lnu_poes')
            ->table('school_years')
            ->select(
                'id as value',
                DB::raw("
                    CONCAT(
                        school_year_from,
                        '-',
                        school_year_to,
                        ' - ',
                        CASE
                            WHEN semester = 1 THEN '1st Semester'
                            WHEN semester = 2 THEN '2nd Semester'
                            WHEN semester = 3 THEN 'Summer'
                            ELSE CONCAT('Semester ', semester)
                        END
                    ) as label
                ")
            )
            ->orderByDesc('school_year_to')
            ->orderByDesc('school_year_from')
            ->orderByDesc('semester')
            ->get()
            ->toArray();


        $facultyReportProps = $this->commonInertiaProps($currentUser, [
            'facultyIdNo' => $facultyMeta['id_no'], 
            'facultyName' => $facultyMeta['instructor'],
            'schoolYears' => $schoolYears,
            'selectedSchoolYear' => $selectedSchoolYear,
            'tableRows' => $tableRows,
            'tablePagination' => [
                'current_page' => $subjectsPage->currentPage(),
                'last_page' => $subjectsPage->lastPage(),
                'per_page' => $subjectsPage->perPage(),
                'total' => $subjectsPage->total(),
            ],
            'overallSetRating' => $overallSetRating,
            'hasPendingEvaluations' => SupervisorEvaluationSubmission::query()
                ->where('user_id', $currentUser->id)
                ->distinct('instructor')
                ->count('instructor') < count($facultyEvaluations),
        ]);

        return Inertia::render('FacultyReportPage', $facultyReportProps);
    }

    /**
     * Get faculty users from local database based on current user's role
     * - Deans see users in their college
     * - Unit heads see users in their unit
     * - Faculty/others see empty list
     */
    private function getLocalFacultyUsers($user, ?int $selectedSchoolYearId = null, string $search = ''): array
    {
        if (!$user) {
            return [];
        }

        $usersQuery = User::query()
            ->select(
                'id',
                'id_no',
                'firstname',
                'lastname',
                'college_id',
                'unit_id'
            );

        // Role filtering
        if ($user->isAdmin()) {

            // No filter

        } elseif ($user->isDean() || $user->isAssociateDean()) {

            $collegeId = $user->dean?->college_id ?? $user->associateDean?->college_id ?? $user->college_id;

            if ($collegeId === null) {
                return [];
            }

            $usersQuery->where('college_id', $collegeId);

        } elseif ($user->isUnitHead()) {

            $unitId = $user->unit_id ?? $user->unitHead?->unit_id ?? null;

            if ($unitId === null) {
                return [];
            }

            $usersQuery->where('unit_id', $unitId);

        } else {

            return [];
        }

        /*
        |--------------------------------------------------------------------------
        | Get valid instructor id_no from external DB
        |--------------------------------------------------------------------------
        */

        $validIdNosQuery = DB::connection('lnu_poes')
            ->table('enrollment_courses')
            ->select('id_no')
            ->distinct();

        if ($selectedSchoolYearId !== null) {
            $validIdNosQuery->where('school_year_id', $selectedSchoolYearId);
        }

        $validIdNos = $validIdNosQuery
            ->pluck('id_no')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Exclude users without enrollment_courses records
        if (empty($validIdNos)) {
            return [];
        }

        $usersQuery->whereIn('id_no', $validIdNos);

        // Add search filter
        if (!empty($search)) {
            $usersQuery->where(function($q) use ($search) {
                $q->where('firstname', 'like', '%' . $search . '%')
                ->orWhere('lastname', 'like', '%' . $search . '%')
                ->orWhere('id_no', 'like', '%' . $search . '%');
            });
        }

        $users = $usersQuery->get();

        /*
        |--------------------------------------------------------------------------
        | Subject counts
        |--------------------------------------------------------------------------
        */

        $idNos = $users->pluck('id_no')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $subjectCountsByIdNo = collect();

        if (!empty($idNos)) {

            try {

                $subjectCountsQuery = DB::connection('lnu_poes')
                    ->table('enrollment_courses')
                    ->whereIn('id_no', $idNos);

                if ($selectedSchoolYearId !== null) {
                    $subjectCountsQuery->where('school_year_id', $selectedSchoolYearId);
                }

                $subjectCountsByIdNo = $subjectCountsQuery
                ->select(
                    'id_no',
                    DB::raw("
                        COUNT(
                            DISTINCT CONCAT(
                                course_code,
                                '|',
                                COALESCE(section_code, 'NO_SECTION'),
                                '|',
                                school_year_id
                            )
                        ) as subjects_count
                    ")
                )
                ->groupBy('id_no')
                ->pluck('subjects_count', 'id_no');

            } catch (\Exception $e) {

                $subjectCountsByIdNo = collect();
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Transform response
        |--------------------------------------------------------------------------
        */

        return $users->map(function ($localUser) use ($subjectCountsByIdNo) {

            $firstName = trim((string) ($localUser->firstname ?? ''));
            $lastName = trim((string) ($localUser->lastname ?? ''));

            $fullName = trim($firstName . ' ' . $lastName);

            if (empty($fullName)) {
                $fullName = $localUser->id_no ?? 'Unknown';
            }

            $initials = '';

            foreach (explode(' ', $fullName) as $word) {

                if ($word !== '') {

                    $initials .= strtoupper(mb_substr($word, 0, 1));

                    if (mb_strlen($initials) >= 3) {
                        break;
                    }
                }
            }

            $subjectsCount = !empty($localUser->id_no)
                ? (int) ($subjectCountsByIdNo[$localUser->id_no] ?? 0)
                : 0;

            return [
                'initials' => $initials ?: 'N/A',
                'instructor' => $fullName,
                'subjects' => [],
                'subjects_count' => $subjectsCount,
                'user_id' => $localUser->id,
                'id_no' => $localUser->id_no,
            ];

        })->values()->all();
    }

    private function extractInstructorTokens(?string $instructor): array
    {
        if ($instructor === null) {
            return [];
        }

        $tokens = preg_split('/[^\pL\pN]+/u', mb_strtoupper(trim($instructor))) ?: [];

        return array_values(array_filter($tokens, function ($token) {
            return mb_strlen($token) > 1;
        }));
    }

    private function getFacultyOverallSetRating(?string $instructor, ?int $termId = null): ?float
        {
            if ($instructor === null || trim($instructor) === '') {
                return null;
            }

            static $cache = [];
            $cacheKey = mb_strtoupper(trim($instructor)) . '|' . ($termId ?? 'all');

            if (array_key_exists($cacheKey, $cache)) {
                return $cache[$cacheKey];
            }

            // Tokenize instructor name
            $tokens = preg_split('/[^\pL\pN]+/u', mb_strtoupper(trim($instructor))) ?: [];
            $tokens = array_values(array_filter($tokens, fn($token) => mb_strlen($token) > 1));

            // Get all subjects and their submissions for this instructor
            $query = DB::connection('lnu_poes')
                ->table('enrollment_courses as ec')
                ->join('student_evaluation_submissions as ses', 'ec.id', '=', 'ses.subject_id')
                ->select('ec.course_code', 'ec.section_code')
                ->selectRaw('COUNT(DISTINCT ses.student_id_number) as student_count')
                ->selectRaw('AVG(ses.rating_percentage) as avg_rating')
                ->whereNotNull('ses.rating_percentage')
                ->groupBy('ec.course_code', 'ec.section_code');

            // Apply instructor filter
            if (!empty($tokens)) {
                foreach ($tokens as $token) {
                    $query->where('ec.instructor', 'like', '%' . $token . '%');
                }
            }

            // Apply term filter
            if ($termId !== null && $termId !== '' && $termId !== 'all') {
                $query->where('ec.school_year_id', $termId);
                $query->where('ses.term_id', $termId);
            }

            $subjects = $query->get();

            if ($subjects->isEmpty()) {
                return null;
            }

            // Calculate weighted average
            $totalWeightedScore = 0;
            $totalStudents = 0;

            foreach ($subjects as $subject) {
                $studentCount = (int) $subject->student_count;
                $avgRating = (float) $subject->avg_rating;
                
                $totalWeightedScore += $studentCount * $avgRating;
                $totalStudents += $studentCount;
            }

            if ($totalStudents === 0) {
                return null;
            }

            $overallRating = round($totalWeightedScore / $totalStudents, 2);
            $cache[$cacheKey] = $overallRating;

            return $overallRating;
        }

    private function getUserSefAverageRating(int $userId, ?int $termId = null): float
        {
            $query = SupervisorEvaluationSubmission::query()
                ->where('user_id', $userId);

            if ($termId) {
                $query->where('term', $termId);
            }

            $avg = $query->avg('rating_percentage');
            return $avg !== null ? round((float) $avg, 2) : 0.0;
        }

    private function getFacultySefAverages(array $facultyUserIds, ?int $termId = null)
        {
            if (empty($facultyUserIds)) {
                return collect();
            }

            $query = SupervisorEvaluationSubmission::query()
                ->whereIn('user_id', $facultyUserIds)
                ->select('user_id')
                ->selectRaw('AVG(rating_percentage) as avg_rating');

            if ($termId) {
                $query->where('term', $termId);
            }

            $rows = $query->groupBy('user_id')->get();
            return $rows->pluck('avg_rating', 'user_id');
        }

    private function extractYearLevelFromSectionCode(?string $sectionCode): ?string
    {
        if ($sectionCode === null || $sectionCode === '') {
            return null;
        }

        preg_match_all('/\d/', $sectionCode, $matches);
        $digits = $matches[0] ?? [];

        if (count($digits) >= 2) {
            return $digits[count($digits) - 2];
        }

        if (count($digits) === 1) {
            return $digits[0];
        }

        return null;
    }


    /**
     * Faculty-specific view - shows only their own subjects as cards
     */
    private function facultyIndex(Request $request)
    {
        $currentUser = $request->user();
        $termParam = $request->query('term', null);
        
        $activeTerm = DB::connection('lnu_poes')
            ->table('school_years')
            ->where('is_active', 1)
            ->first();

        $activeTermId = $activeTerm?->id;

        $termId = (!$termParam || $termParam === 'current' || $termParam === 'all')
            ? $activeTermId
            : (is_numeric($termParam) ? (int) $termParam : $activeTermId);

        // Get faculty's own subjects
        $facultySubjects = $this->getFacultyOwnSubjects($currentUser, $termId);
        
        // Get statistics for this faculty only
        $submittedEvaluationsCount = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->when($termId, fn($q) => $q->where('term', $termId))
            ->count();
        
        $averageRating = $this->getUserSefAverageRating($currentUser->id, $termId);
        
        $passedGradesCount = UnitHeadGrade::query()
            ->where('user_id', $currentUser->id)
            ->whereNotNull('grade')
            ->when($termId, fn($q) => $q->where('term', $termId))
            ->count();

        // Get school years for filter
        $schoolYears = DB::connection('lnu_poes')
            ->table('school_years')
            ->select(
                'id as value',
                DB::raw("
                    CONCAT(
                        school_year_from,
                        '-',
                        school_year_to,
                        ' - ',
                        CASE
                            WHEN semester = 1 THEN '1st Semester'
                            WHEN semester = 2 THEN '2nd Semester'
                            WHEN semester = 3 THEN 'Summer'
                            ELSE CONCAT('Semester ', semester)
                        END
                    ) as label
                ")
            )
            ->orderByDesc('school_year_to')
            ->orderByDesc('school_year_from')
            ->orderByDesc('semester')
            ->get()
            ->toArray();

        $reportsProps = $this->commonInertiaProps($currentUser, [
            'reportSummary' => [
                [
                    'label' => 'Submitted Evaluations',
                    'value' => $submittedEvaluationsCount,
                    'helper' => 'Total evaluation forms submitted.',
                ],
                [
                    'label' => 'Subjects',
                    'value' => count($facultySubjects),
                    'helper' => 'Total subjects handled.',
                ],
                [
                    'label' => 'Average Rating',
                    'value' => $averageRating . '%',
                    'helper' => 'Average score across all evaluations.',
                ],
                [
                    'label' => 'Posted Grades',
                    'value' => $passedGradesCount,
                    'helper' => 'Unit head grades recorded.',
                ],
            ],
            'facultySubjects' => $facultySubjects,
            'schoolYears' => $schoolYears,
            'selectedSchoolYear' => $termId,
            'isFacultyView' => true,
        ]);

        return Inertia::render('FacultySubjectReportPage', $reportsProps);
    }

    /**
     * Get faculty's own subjects with evaluation data
     */
    private function getFacultyOwnSubjects($user, ?int $termId = null): array
    {
        if (!$user) {
            return [];
        }

        // Get the faculty's subjects from enrollment_courses
        // Group by course_code, section_code, and school_year_id to prevent duplication
        $subjectsQuery = DB::connection('lnu_poes')
            ->table('enrollment_courses as ec')
            ->select(
                'ec.course_code',
                'ec.course_description',
                'ec.section_code',
                'ec.year_level',
                'ec.school_year_id'
            )
            ->selectRaw('MIN(ec.id) as id')
            ->groupBy('ec.course_code', 'ec.course_description', 'ec.section_code', 'ec.year_level', 'ec.school_year_id');

        // Filter by instructor ID number
        if (!empty($user->id_no)) {
            $subjectsQuery->where('ec.id_no', $user->id_no);
        } else {
            // Fallback to name matching
            $fullName = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
            if (!empty($fullName)) {
                $nameTokens = $this->extractInstructorTokens($fullName);
                if (!empty($nameTokens)) {
                    $subjectsQuery->where(function ($q) use ($nameTokens) {
                        foreach ($nameTokens as $token) {
                            $q->orWhere('ec.instructor', 'like', '%' . $token . '%');
                        }
                    });
                }
            }
        }

        if ($termId !== null && $termId !== '') {
            $subjectsQuery->where('ec.school_year_id', $termId);
        }

        $subjects = $subjectsQuery->get();
        
        if ($subjects->isEmpty()) {
            return [];
        }

        // Create a unique key for each subject (course_code + section_code + school_year_id)
        $subjectsWithKeys = $subjects->map(function ($subject) {
            $uniqueKey = $subject->course_code . '|' . ($subject->section_code ?? 'NO_SECTION') . '|' . $subject->school_year_id;
            return [
                'unique_key' => $uniqueKey,
                'subject' => $subject
            ];
        });
        
        // Remove duplicates by unique_key (just to be safe, though groupBy should handle it)
        $uniqueSubjects = collect();
        foreach ($subjectsWithKeys as $item) {
            if (!$uniqueSubjects->has($item['unique_key'])) {
                $uniqueSubjects->put($item['unique_key'], $item['subject']);
            }
        }
        
        $subjects = $uniqueSubjects->values();
        
        if ($subjects->isEmpty()) {
            return [];
        }

        // Get course codes for fetching evaluations
        $courseCodes = $subjects->pluck('course_code')->unique()->values()->all();
        
        // Get latest submissions for each course
        $latestSubmissions = collect();
        $latestGrades = collect();
        
        if (!empty($courseCodes)) {
            $latestSubmissionIds = SupervisorEvaluationSubmission::query()
                ->where('user_id', $user->id)
                ->whereIn('course_code', $courseCodes)
                ->when($termId, fn($q) => $q->where('term', $termId))
                ->selectRaw('MAX(id) as latest_id')
                ->groupBy('course_code')
                ->pluck('latest_id');

            if ($latestSubmissionIds->isNotEmpty()) {
                $latestSubmissions = SupervisorEvaluationSubmission::query()
                    ->whereIn('id', $latestSubmissionIds)
                    ->get()
                    ->keyBy('course_code');
            }

            $latestGradeIds = UnitHeadGrade::query()
                ->where('user_id', $user->id)
                ->whereIn('course_code', $courseCodes)
                ->when($termId, fn($q) => $q->where('term', $termId))
                ->selectRaw('MAX(id) as latest_id')
                ->groupBy('course_code')
                ->pluck('latest_id');

            if ($latestGradeIds->isNotEmpty()) {
                $latestGrades = UnitHeadGrade::query()
                    ->whereIn('id', $latestGradeIds)
                    ->get()
                    ->keyBy('course_code');
            }
        }

        // Build the subjects list with evaluation data
        $fullName = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
        $initials = $this->getFacultyInitials($user->firstname ?? '', $user->lastname ?? '');
        
        return $subjects->map(function ($subject) use ($latestSubmissions, $latestGrades, $fullName, $initials, $termId, $user) {
            $courseCode = $subject->course_code;
            $submission = $latestSubmissions->get($courseCode);
            $grade = $latestGrades->get($courseCode);
            
            // Format section and year
            $sectionCode = trim((string) ($subject->section_code ?? ''));
            $yearLevel = $this->extractYearLevelFromSectionCode($sectionCode) ?? trim((string) ($subject->year_level ?? ''));
            
            if ($yearLevel !== '' && $sectionCode !== '') {
                $classSection = $yearLevel . '-' . $sectionCode;
            } elseif ($yearLevel !== '') {
                $classSection = $yearLevel;
            } elseif ($sectionCode !== '') {
                $classSection = $sectionCode;
            } else {
                $classSection = '-';
            }
            
            $sefScore = $submission?->rating_percentage;
            $setGrade = $grade?->grade;
            
            // Get SET rating for this specific subject (including section)
            $setRating = $this->getSubjectSetRating($subject, $termId);
            
            // Create a unique identifier for the URL parameters
            $uniqueId = urlencode($courseCode . '_' . $sectionCode . '_' . $subject->school_year_id);
            
            return [
                'initials' => $initials,
                'instructor' => $fullName,
                'course_code' => $courseCode,
                'course_title' => $subject->course_description ?? $courseCode,
                'class_section' => $classSection,
                'section_code' => $sectionCode,
                'school_year_id' => $subject->school_year_id,
                'detail_url' => route('reports.faculty', [
                    'instructor' => $user->id_no ?? $fullName,
                    'term' => $termId ?? 'current'
                ]) . '?course=' . $courseCode . '&section=' . urlencode($sectionCode),
                
                'breakdown_url' => route('reports.faculty.breakdown', [
                    'instructor' => $user->id_no ?? $fullName
                ]) . '?' . http_build_query([
                    'course_code' => $courseCode,
                    'section_code' => $sectionCode,
                    'term' => $termId ?? 'current',
                    'school_year_id' => $subject->school_year_id,
                    'instructor_id' => $user->id_no,
                ]),
                'set_rating' => $setRating !== null ? round($setRating, 2) : null,
                'set_rating_formatted' => $setRating !== null ? round($setRating, 2) . '%' : 'No data',
                'sef_score' => $sefScore !== null ? round($sefScore, 2) : null,
                'sef_score_formatted' => $sefScore !== null ? round($sefScore, 2) . '%' : 'Not yet evaluated',
                'final_grade' => $setGrade !== null ? number_format((float) $setGrade, 1) : null,
                'status' => $setGrade !== null ? 'Graded' : ($submission !== null ? 'Evaluated' : 'For Evaluation'),
            ];
        })->values()->all();
    }

    /**
     * Get SET rating for a specific subject
     */
    private function getSubjectSetRating($subject, ?int $termId = null): ?float
    {
        try {
            $query = DB::connection('lnu_poes')
                ->table('student_evaluation_submissions as ses')
                ->join('enrollment_courses as ec', 'ses.subject_id', '=', 'ec.id')
                ->where('ec.course_code', $subject->course_code);
            
            // Add section filter if available
            if (!empty($subject->section_code)) {
                $query->where('ec.section_code', $subject->section_code);
            }
            
            // Add school year filter if available
            if (!empty($subject->school_year_id)) {
                $query->where('ec.school_year_id', $subject->school_year_id);
                $query->where('ses.term_id', $subject->school_year_id);
            } elseif ($termId) {
                $query->where('ses.term_id', $termId);
            }
            
            $avgRating = $query->avg('ses.rating_percentage');
            
            return $avgRating !== null ? (float) $avgRating : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get initials from first and last name for faculty
     */
    private function getFacultyInitials(string $firstname, string $lastname): string
    {
        $initials = '';
        
        if (!empty($firstname)) {
            $initials .= strtoupper(mb_substr(trim($firstname), 0, 1));
        }
        
        if (!empty($lastname)) {
            $initials .= strtoupper(mb_substr(trim($lastname), 0, 1));
        }
        
        if (mb_strlen($initials) < 2 && !empty($firstname)) {
            $parts = explode(' ', trim($firstname));
            foreach ($parts as $part) {
                if (!empty($part) && mb_strlen($initials) < 2) {
                    $initials .= strtoupper(mb_substr($part, 0, 1));
                }
            }
        }
        
        return $initials ?: 'NA';
    }

    /**
     * Show detailed view for a specific subject (faculty view)
     * Redirects back to faculty reports with the modal open
     */
    public function facultySubjectDetail(Request $request, string $instructor, string $course_code)
    {
        $currentUser = $request->user();
        
        // Ensure only faculty can access and only their own data
        if (!$currentUser || !$currentUser->isFaculty()) {
            abort(403);
        }
        
        $termParam = $request->query('term', null);
        $sectionParam = $request->query('section', null);
        $schoolYearParam = $request->query('school_year_id', null);
        
        // Redirect back to faculty reports with parameters to open the modal
        return redirect()->route('reports', [
            'term' => $termParam ?? 'current',
            'open_modal' => $course_code,
            'section' => $sectionParam,
            'school_year_id' => $schoolYearParam,
        ]);
    }
}
