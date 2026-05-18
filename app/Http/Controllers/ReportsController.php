<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
// use App\Models\Poes\PoesEvalSubmissions;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\UnitHeadGrade;
use App\Models\User;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    use FacultyData;

    public function index(Request $request)
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

        $facultyEvaluations = $this->getLocalFacultyUsers($currentUser, $termId);

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
                $ratings = $submission->ratings ?? [];
                $totalScore = collect($ratings)->sum(fn ($score) => (int) $score);

                // Use preloaded grades where possible
                $gradeKey = (string) ($submission->instructor . '||' . $submission->course_code);
                $latestGrade = $recentGradeKeys->contains($gradeKey)
                    ? $unitHeadGrades->get($gradeKey)
                    : null;


                return [
                    'instructor' => $submission->instructor,
                    'course_code' => $submission->course_code,
                    'course_title' => $submission->course_title,
                    'rating_percentage' => round(($totalScore / 75) * 100, 2),
                    'final_grade' => $latestGrade ? number_format((float) $latestGrade->grade, 1) : null,
                    'submitted_at' => optional($submission->submitted_at)->format('M d, Y h:i A') ?? '-',
                ];
            })
            ->values()
            ->all();

        // Batch SET statistics for all faculty (single external query)
        $allIdNos = collect($facultyEvaluations)
            ->pluck('id_no')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $numericIds = collect($allIdNos)->map(function ($idNo) {
            $digits = preg_replace('/\D+/', '', (string) $idNo);
            return $digits !== '' ? $digits : null;
        })->filter()->unique()->values()->all();

        $allInstructorIds = array_values(array_unique(array_merge($allIdNos, $numericIds)));

        $setStats = collect();
        if (!empty($allInstructorIds)) {
            $subjectIdsByIdNo = collect();
            $allSubjectIds = [];

            if (!empty($allIdNos)) {
                $subjectRowsQuery = DB::connection('lnu_poes')
                    ->table('enrollment_courses')
                    ->whereIn('id_no', $allIdNos);

                if ($termId !== null) {
                    $subjectRowsQuery->where('school_year_id', $termId);
                }

                $subjectRows = $subjectRowsQuery
                    ->select('id', 'id_no')
                    ->get();

                $subjectIdsByIdNo = $subjectRows
                    ->groupBy('id_no')
                    ->map(fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id)->values());

                $allSubjectIds = $subjectRows
                    ->pluck('id')
                    ->filter()
                    ->unique()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
            }

            if (!empty($allSubjectIds)) {
                $setRows = DB::connection('lnu_poes')
                    ->table('student_evaluation_submissions')
                    ->select(
                        'instructor_id',
                        'subject_id',
                        DB::raw('COUNT(DISTINCT student_id_number) as students'),
                        DB::raw('AVG(total_score) as avg_score')
                    )
                    ->whereIn('instructor_id', $allInstructorIds)
                    ->whereIn('subject_id', $allSubjectIds)
                    ->groupBy('instructor_id')
                    ->groupBy('subject_id')
                    ->get();

                $acc = [];
                foreach ($setRows as $row) {
                    $iid = (string) $row->instructor_id;
                    $students = (int) ($row->students ?? 0);
                    $avg = $row->avg_score !== null ? (float) $row->avg_score : null;

                    if (!isset($acc[$iid])) {
                        $acc[$iid] = [
                            'students_sum' => 0,
                            'weighted_sum' => 0,
                        ];
                    }

                    if ($avg !== null && $students > 0) {
                        $acc[$iid]['students_sum'] += $students;
                        $acc[$iid]['weighted_sum'] += $students * $avg;
                    }
                }

                foreach ($acc as $iid => $data) {
                    $overall = null;
                    if ($data['students_sum'] > 0) {
                        $overall = round($data['weighted_sum'] / $data['students_sum'], 2);
                    }

                    $setStats[$iid] = (object) [
                        'students_sum' => $data['students_sum'],
                        'weighted_sum' => $data['weighted_sum'],
                        'overall_score' => $overall,
                    ];
                }
            } else {
                // Fallback when we cannot map subjects for the selected term.
                $setRows = DB::connection('lnu_poes')
                    ->table('student_evaluation_submissions')
                    ->select('instructor_id', DB::raw('COUNT(DISTINCT student_id_number) as total_evaluators'), DB::raw('AVG(total_score) as average_score'))
                    ->whereIn('instructor_id', $allInstructorIds)
                    ->groupBy('instructor_id')
                    ->get();

                $setStats = $setRows->keyBy(fn ($r) => (string) $r->instructor_id);
            }
        }

        $facultyUserIds = collect($facultyEvaluations)
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $sefAveragesByUser = $this->getFacultySefAverages($facultyUserIds, $termId);

        $facultyList = collect($facultyEvaluations)
            ->map(function ($faculty, $index) use ($evaluatedInstructors, $setStats, $sefAveragesByUser) {
                /*
                |----------------------------------------
                | OVERALL SET RATING FOR FACULTY CARD
                | Calculates aggregate SET rating across all subjects
                | Only calculate if faculty has assigned subjects
                |----------------------------------------
                */
                $overallSetRating = null;
                $overallSefRating = null;
                $subjectsCount = (int) ($faculty['subjects_count'] ?? 0);

                // Only calculate ratings if faculty has subjects
                if ($subjectsCount > 0) {
                        // Extract numeric ID from id_no (same way as in faculty detail page)
                        $instructorIdRaw = $faculty['id_no'] ?? null;
                        $instructorId = null;

                        if ($instructorIdRaw !== null) {
                            $digits = preg_replace('/\D+/', '', (string) $instructorIdRaw);
                            if ($digits !== '') {
                                $instructorId = $digits;
                            }
                        }

                        // Lookup pre-fetched SET stats by either numeric or raw id
                        $setRow = null;
                        if ($instructorId !== null && isset($setStats[(string) $instructorId])) {
                            $setRow = $setStats[(string) $instructorId];
                        } elseif ($instructorIdRaw !== null && isset($setStats[(string) $instructorIdRaw])) {
                            $setRow = $setStats[(string) $instructorIdRaw];
                        }

                        if ($setRow) {
                            if (isset($setRow->overall_score) && $setRow->overall_score !== null) {
                                $overallSetRating = $setRow->overall_score;
                            } elseif (isset($setRow->average_score) && $setRow->average_score !== null && ($setRow->total_evaluators ?? 0) > 0) {
                                $overallSetRating = round($setRow->average_score, 2);
                            }
                        }

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
                    'employee_id_no' => $faculty['id_no'] ?? 'EMP-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'detail_url' => route('reports.faculty', ['instructor' => $faculty['instructor']]),
                    'overall_set_rating' => $overallSetRating,
                    'overall_sef_rating' => $overallSefRating,
                ];
            })
            ->values()
            ->all();

        // Get school years for filters
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
            'facultyList' => $facultyList,
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
            return strcasecmp($faculty['instructor'], $instructor) === 0;
        });

        abort_if($facultyIndex === false, 404);

        $facultyMeta = $facultyCollection->get($facultyIndex);
        $employeeIdNo = $facultyMeta['id_no'] ?? 'EMP-' . str_pad((string) ($facultyIndex + 1), 3, '0', STR_PAD_LEFT);

        $perPage = (int) $request->input('per_page', 10);
        $perPage = max(10, min($perPage, 200));

        $subjectsQuery = DB::connection('lnu_poes')
            ->table('enrollment_courses')
            ->where('id_no', $facultyMeta['id_no'] ?? '');

        if ($selectedSchoolYear !== '') {
            $subjectsQuery->where('school_year_id', $selectedSchoolYear);
        }

        $subjectsPage = $subjectsQuery
        ->selectRaw('MIN(id) as id')
        ->select('course_code', 'course_description', 'school_year_id')
        ->selectRaw("MAX(NULLIF(TRIM(year_level), '')) as year_level")
        ->selectRaw("COALESCE(NULLIF(TRIM(section_code), ''), '') as section_code")
        ->groupBy('course_code', 'course_description', 'school_year_id')
        ->groupByRaw("COALESCE(NULLIF(TRIM(section_code), ''), '')")
        ->orderBy('course_code')
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
            ->map(function ($subject, $index) use ($employeeIdNo, $facultyMeta, $latestSubmissionByCourse, $latestGradesByCourse, $subjectsPage, $instructorId) {
                $courseCode = (string) ($subject->course_code ?? '');
                $subjectId = $subject->id ?? null;

                $submission = $latestSubmissionByCourse->get($courseCode);
                $grade = $latestGradesByCourse->get($courseCode);

                $ratings = $submission?->ratings ?? [];
                $totalScore = collect($ratings)->sum(fn ($score) => (int) $score);

                $sefScore = $submission ? round(($totalScore / 75) * 100, 2) : null;
                $setGrade = $grade?->grade;
                $status = ($setGrade !== null || $submission !== null) ? 'Evaluated' : 'For Evaluation';

                $yearLevel = trim((string) ($subject->year_level ?? ''));
                $sectionCode = trim((string) ($subject->section_code ?? ''));
                if ($yearLevel !== '' && $sectionCode !== '') {
                    $term = 'Year ' . $yearLevel . '-' . $sectionCode;
                } elseif ($yearLevel !== '') {
                    $term = 'Year ' . $yearLevel;
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
    private function getLocalFacultyUsers($user, ?int $selectedSchoolYearId = null): array
    {
        if (!$user) {
            return [];
        }

        $usersQuery = User::query()->select('id', 'id_no', 'firstname', 'lastname', 'college_id', 'unit_id');

        // Filter based on user role
        // Admins see all users; Deans see users by their college; Unit heads see users by their unit
        if ($user->isAdmin()) {
            // Admin sees all users, no filtering needed
        } elseif ($user->isDean()) {
            // prefer the college_id stored on the dean record (deans table)
            $collegeId = $user->dean?->college_id ?? $user->college_id;

            // if we still don't have a college id, return empty — nothing to filter
            if ($collegeId === null) {
                return [];
            }

            $usersQuery->where('college_id', $collegeId);
        } elseif ($user->isUnitHead()) {
            $usersQuery->where('unit_id', $user->unit_id);
        } else {
            // Faculty users don't see other faculty in this context
            return [];
        }

        $users = $usersQuery->get();

        // Batch fetch subject counts from external DB (avoid loading full subject rows here)
        $idNos = $users->pluck('id_no')->filter()->unique()->values()->all();
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
                        DB::raw('COUNT(DISTINCT course_code, course_description, year_level, section_code, school_year_id) as subjects_count')
                    )
                    ->groupBy('id_no')
                    ->pluck('subjects_count', 'id_no');
            } catch (\Exception $e) {
                $subjectCountsByIdNo = collect();
            }
        }

        // Transform to expected faculty structure
        return $users->map(function ($localUser) use ($subjectCountsByIdNo) {
            $firstName = trim((string) ($localUser->firstname ?? ''));
            $lastName = trim((string) ($localUser->lastname ?? ''));
            $fullName = trim($firstName . ' ' . $lastName) ?: $localUser->id_no ?? 'Unknown';

            // Generate initials
            $initials = '';
            foreach (explode(' ', $fullName) as $word) {
                if ($word !== '') {
                    $initials .= strtoupper(mb_substr($word, 0, 1));
                    if (mb_strlen($initials) >= 3) break;
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

    private function ratingsToPercent($ratings): float
    {
        $totalScore = collect($ratings ?? [])->sum(fn ($score) => (int) $score);
        return ($totalScore / 75) * 100;
    }

    private function getUserSefAverageRating(int $userId, ?int $termId = null): float
    {
        try {
            $query = DB::table('supervisor_evaluation_submissions')
                    ->where('user_id', $userId);

                if ($termId) {
                    $query->where('term', $termId);
                }

                $row = $query
                ->selectRaw(
                    "ROUND(AVG((SELECT COALESCE(SUM(j.value), 0) FROM JSON_TABLE(ratings, '$.*' COLUMNS (value DECIMAL(10,2) PATH '$')) AS j) / 75 * 100), 2) as avg_rating"
                )
                ->first();

            return isset($row->avg_rating) && $row->avg_rating !== null
                ? (float) $row->avg_rating
                : 0.0;
        } catch (\Throwable $e) {
                $sum = 0.0;
                $count = 0;

                $query = SupervisorEvaluationSubmission::query()
                    ->where('user_id', $userId);

                if ($termId) {
                    $query->where('term', $termId);
                }

                foreach (
                    $query->select('ratings')->cursor() as $submission
                ) {
                    $sum += $this->ratingsToPercent($submission->ratings ?? []);
                    $count++;
                }

                return $count > 0 ? round($sum / $count, 2) : 0.0;
            }
        }

    private function getFacultySefAverages(array $facultyUserIds, ?int $termId = null)
    {
        if (empty($facultyUserIds)) {
            return collect();
        }

        try {
            $query = DB::table('supervisor_evaluation_submissions')
                ->whereIn('user_id', $facultyUserIds, 'and', false)
                ->select('user_id')
                ->selectRaw(
                    "ROUND(AVG((SELECT COALESCE(SUM(j.value), 0) FROM JSON_TABLE(ratings, '$.*' COLUMNS (value DECIMAL(10,2) PATH '$')) AS j) / 75 * 100), 2) as avg_rating"
                );
            
            if ($termId) {
                $query->where('term', $termId);
            }
            
            $rows = $query->groupBy('user_id')->get();

            return $rows->pluck('avg_rating', 'user_id');
        } catch (\Throwable $e) {
            $accumulator = [];
            $baseQuery = SupervisorEvaluationSubmission::query()
                ->whereIn('user_id', $facultyUserIds, 'and', false)
                ->select('user_id', 'ratings');
            
            if ($termId) {
                $baseQuery->where('term', $termId);
            }
            
            foreach ($baseQuery->cursor() as $row) {
                $uid = (int) $row->user_id;
                if (!isset($accumulator[$uid])) {
                    $accumulator[$uid] = ['sum' => 0.0, 'count' => 0];
                }
                $accumulator[$uid]['sum'] += $this->ratingsToPercent($row->ratings ?? []);
                $accumulator[$uid]['count']++;
            }

            return collect($accumulator)->map(function ($v) {
                if (($v['count'] ?? 0) <= 0) {
                    return null;
                }
                return round(((float) $v['sum']) / ((int) $v['count']), 2);
            });
        }
    }
}
