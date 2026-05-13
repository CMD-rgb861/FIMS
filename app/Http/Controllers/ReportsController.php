<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\Poes\PoesEvalSubmissions;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\UnitHeadGrade;
use App\Models\User;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    use FacultyData;

    public function index(Request $request)
    {
        $currentUser = $request->user();
        $facultyEvaluations = $this->getLocalFacultyUsers($currentUser);
        $canAccessEvaluation = $this->canAccessEvaluationForUser($currentUser);
        $profilePhotoUrl = $currentUser?->personalInformation?->profile_photo_path
            ? asset('storage/' . $currentUser->personalInformation->profile_photo_path)
            : null;

        $allSubmissions = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->latest('submitted_at')
            ->get();

        $evaluatedInstructors = $allSubmissions
            ->pluck('instructor')
            ->unique()
            ->values();

        $averageRating = $allSubmissions->count() > 0
            ? round($allSubmissions->avg(function ($submission) {
                $ratings = $submission->ratings ?? [];
                $totalScore = collect($ratings)->sum(function ($score) {
                    return (int) $score;
                });

                return ($totalScore / 75) * 100;
            }), 2)
            : 0;

        $passedGradesCount = UnitHeadGrade::query()
            ->where('user_id', $currentUser->id)
            ->whereNotNull('grade')
            ->count();

        $recentReports = $allSubmissions
            ->take(10)
            ->map(function ($submission) {
                $ratings = $submission->ratings ?? [];
                $totalScore = collect($ratings)->sum(function ($score) {
                    return (int) $score;
                });

                $latestGrade = UnitHeadGrade::query()
                    ->where('user_id', $submission->user_id)
                    ->where('instructor', $submission->instructor)
                    ->where('course_code', $submission->course_code)
                    ->latest('submitted_at')
                    ->first();

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

        $facultyList = collect($facultyEvaluations)
            ->map(function ($faculty, $index) use ($evaluatedInstructors) {
                /*
                |----------------------------------------
                | OVERALL SET RATING FOR FACULTY CARD
                | Calculates aggregate SET rating across all subjects
                | Only calculate if faculty has assigned subjects
                |----------------------------------------
                */
                $overallSetRating = null;
                $overallSefRating = null;
                $subjectsCount = count($faculty['subjects'] ?? []);

                // Only calculate ratings if faculty has subjects
                if ($subjectsCount > 0) {
                    // Extract numeric ID from id_no (same way as in faculty detail page)
                    $instructorIdRaw = $faculty['id_no'] ?? null;
                    $instructorId = null;

                    if ($instructorIdRaw !== null) {
                        $digits = preg_replace('/\D+/', '', (string) $instructorIdRaw);
                        if ($digits !== '') {
                            $instructorId = (int) $digits;
                        }
                    }

                    if ($instructorId !== null) {
                        // Calculate SET rating from PoesEvalSubmissions
                        // Try both numeric ID and full id_no string
                        $totalEvaluators = PoesEvalSubmissions::query()
                            ->where('instructor_id', $instructorId)
                            ->orWhere('instructor_id', $instructorIdRaw)
                            ->distinct('student_id_number')
                            ->count('student_id_number');

                        $totalWeightedScoreQuery = PoesEvalSubmissions::query()
                            ->where(function ($q) use ($instructorId, $instructorIdRaw) {
                                $q->where('instructor_id', $instructorId)
                                  ->orWhere('instructor_id', $instructorIdRaw);
                            });

                        $totalWeightedScore = $totalWeightedScoreQuery
                            ->selectRaw('COUNT(DISTINCT student_id_number) as evaluators')
                            ->selectRaw('AVG(total_score) as avg_score')
                            ->first();

                        if ($totalWeightedScore && $totalEvaluators > 0 && $totalWeightedScore->avg_score !== null) {
                            $weightedScore = $totalWeightedScore->avg_score * $totalEvaluators;
                            $overallSetRating = round(($weightedScore / $totalEvaluators), 2);
                        }
                    }

                    // Calculate SEF rating from SupervisorEvaluationSubmission
                    $sefSubmissions = SupervisorEvaluationSubmission::query()
                        ->where('user_id', $faculty['user_id'])
                        ->get();

                    if ($sefSubmissions->count() > 0) {
                        $averageSefRating = round($sefSubmissions->avg(function ($submission) {
                            $ratings = $submission->ratings ?? [];
                            $totalScore = collect($ratings)->sum(function ($score) {
                                return (int) $score;
                            });
                            return ($totalScore / 75) * 100;
                        }), 2);
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

        $reportsProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('my-profile.edit'),
            'accountSettingsUrl' => route('account-settings.edit'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'profile_photo_url' => $profilePhotoUrl,
                'role' => $currentUser?->role,
            ],
            'reportSummary' => [
                [
                    'label' => 'Submitted Evaluations',
                    'value' => $allSubmissions->count(),
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
            'facultyList' => $facultyList,
            'hasPendingEvaluations' => $evaluatedInstructors->count() < count($facultyEvaluations),
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return Inertia::render('ReportsPage', $reportsProps);
    }

    public function faculty(Request $request, string $instructor)
    {
        $currentUser = $request->user();
        $facultyEvaluations = $this->getLocalFacultyUsers($currentUser);
        $canAccessEvaluation = $this->canAccessEvaluationForUser($currentUser);
        $profilePhotoUrl = $currentUser?->personalInformation?->profile_photo_path
            ? asset('storage/' . $currentUser->personalInformation->profile_photo_path)
            : null;

        $facultyCollection = collect($facultyEvaluations);
        $facultyIndex = $facultyCollection->search(function ($faculty) use ($instructor) {
            return strcasecmp($faculty['instructor'], $instructor) === 0;
        });

        abort_if($facultyIndex === false, 404);

        $facultyMeta = $facultyCollection->get($facultyIndex);
        $employeeIdNo = $facultyMeta['id_no'] ?? 'EMP-' . str_pad((string) ($facultyIndex + 1), 3, '0', STR_PAD_LEFT);

        $facultySubmissions = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->where('instructor', $facultyMeta['instructor'])
            ->latest('submitted_at')
            ->get();


            //GET ALL THE SUBJECTS FROM ENROLLMENT COURSES TABLE FOR THIS INSTRUCTOR; ADD FILTERS HERE LATER (PROGRAM/TERM/STATUS)
        $latestSubmissionByCourse = $facultySubmissions
            ->unique(function ($submission) {
                return (string) ($submission->course_code ?? '');
            })
            ->keyBy(function ($submission) {
                return (string) ($submission->course_code ?? '');
            });

        $latestGradesByCourse = UnitHeadGrade::query()
            ->where('user_id', $currentUser->id)
            ->where('instructor', $facultyMeta['instructor'])
            ->latest('submitted_at')
            ->get()
            ->unique(function ($grade) {
                return (string) ($grade->course_code ?? '');
            })
            ->keyBy(function ($grade) {
                return (string) ($grade->course_code ?? '');
            });
            //END




        $setBreakdown = $facultySubmissions
            ->values()
            ->map(function ($submission, $index) {
                $setGrade = UnitHeadGrade::query()
                    ->where('user_id', $submission->user_id)
                    ->where('instructor', $submission->instructor)
                    ->where('course_code', $submission->course_code)
                    ->latest('submitted_at')
                    ->value('grade');

                $averageSetRating = $setGrade !== null ? (float) $setGrade : null;
                $studentCount = null;
                $weightedSetScore = ($averageSetRating !== null && $studentCount !== null)
                    ? $averageSetRating * $studentCount
                    : null;

                return [
                    'seq' => $index + 1,
                    'course_code' => $submission->course_code,
                    'year_section' => $submission->term,
                    'no_of_students' => $studentCount,
                    'average_set_rating' => $averageSetRating !== null ? number_format($averageSetRating, 2) : '-',
                    'weighted_set_score' => $weightedSetScore !== null ? number_format($weightedSetScore, 2) : '-',
                    'no_of_students_value' => $studentCount,
                    'average_set_rating_value' => $averageSetRating,
                    'weighted_set_score_value' => $weightedSetScore,
                ];
            })
            ->all();

        // Show all assigned subjects for this instructor; add filters here later (program/term/status).
        $allSubjects = collect($facultyMeta['subjects'] ?? [])->values();

        $tableRows = $allSubjects
            ->map(function ($subject, $index) use ($employeeIdNo, $facultyMeta, $latestSubmissionByCourse, $latestGradesByCourse) {
                $courseCode = (string) ($subject['code'] ?? '');
                $subjectId = $subject['subject_id'] ?? $subject['id'] ?? null;
                $instructorIdRaw = $subject['id_no'] ?? null;
                $instructorId = null;

                if ($instructorIdRaw !== null) {
                    $digits = preg_replace('/\D+/', '', (string) $instructorIdRaw);
                    if ($digits !== '') {
                        $instructorId = (int) $digits;
                    }
                }

                $submission = $latestSubmissionByCourse->get($courseCode);
                $grade = $latestGradesByCourse->get($courseCode);

                $ratings = $submission?->ratings ?? [];
                $totalScore = collect($ratings)->sum(function ($score) {
                    return (int) $score;
                });

                $sefScore = $submission ? round(($totalScore / 75) * 100, 2) : null;
                $setGrade = $grade?->grade;
                $status = ($setGrade !== null || $submission !== null) ? 'Evaluated' : 'For Evaluation';
                $term = $subject['term'] ?? '-';
                $setBreakdown = [
                    [
                        'seq' => 1,
                        'course_code' => $courseCode,
                        'year_section' => $term,
                        'no_of_students' => null,
                        'average_set_rating' => $setGrade !== null ? number_format((float) $setGrade, 2) : '-',
                        'weighted_set_score' => '-',
                        'no_of_students_value' => null,
                        'average_set_rating_value' => $setGrade !== null ? (float) $setGrade : null,
                        'weighted_set_score_value' => null,
                    ],
                ];

                return [
                    'id' => $index + 1,
                    'school_year_id_value' => $subject['school_year_id'] ?? null,
                    'course_description' => $subject['course_description'] ?? $subject['title'] ?? '-',
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
                    'set_breakdown' => $setBreakdown,
                ];
            })
            ->values()
            ->all();

        /*
        |----------------------------------------
        | OVERALL SET RATING CALCULATION
        | Aggregates evaluators and weighted scores across all subjects
        | Formula: Total Weighted SET Score / Total Evaluators
        |----------------------------------------
        */
        $totalEvaluators = 0;
        $totalWeightedScore = 0.0;

        foreach ($tableRows as $row) {
            $totalEvaluators += $row['no_of_students_value'] ?? 0;
            $totalWeightedScore += $row['weighted_set_score_value'] ?? 0;
        }

        $overallSetRating = ($totalEvaluators > 0)
            ? round(($totalWeightedScore / $totalEvaluators), 2)
            : null;

            //END
        

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


        $facultyReportProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('my-profile.edit'),
            'accountSettingsUrl' => route('account-settings.edit'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'profile_photo_url' => $profilePhotoUrl,
                'role' => $currentUser?->role,
            ],
            'facultyName' => $facultyMeta['instructor'],
            'schoolYears' => $schoolYears,
            'selectedSchoolYear' => '',
            'tableRows' => $tableRows,
            'overallSetRating' => $overallSetRating,
            'hasPendingEvaluations' => SupervisorEvaluationSubmission::query()
                ->where('user_id', $currentUser->id)
                ->distinct('instructor')
                ->count('instructor') < count($facultyEvaluations),
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return Inertia::render('FacultyReportPage', $facultyReportProps);
    }

    /**
     * Get faculty users from local database based on current user's role
     * - Deans see users in their college
     * - Unit heads see users in their unit
     * - Faculty/others see empty list
     */
    private function getLocalFacultyUsers($user): array
    {
        if (!$user) {
            return [];
        }

        $usersQuery = User::query();

        // Filter based on user role
        if ($user->isDean()) {
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

        // Transform to expected faculty structure and attach subjects from external DB
        return $users->map(function ($localUser) {
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

            // fetch subjects from external lnu_poes enrollment_courses table by id_no
            $subjects = [];
            try {
                if (!empty($localUser->id_no)) {
                    $rows = DB::connection('lnu_poes')
                        ->table('enrollment_courses')
                        ->where('id_no', $localUser->id_no)
                        ->get();

                    $subjects = $rows->map(function ($r) {
                        $courseCode = $r->course_code ?? $r->code ?? $r->subject_code ?? null;
                        $courseDescription = $r->course_description ?? $r->course_title ?? $r->title ?? $r->subject_title ?? null;
                        $yearLevel = trim((string) ($r->year_level ?? ''));
                        $sectionCode = trim((string) ($r->section_code ?? ''));

                        if ($yearLevel !== '' && $sectionCode !== '') {
                            $yearSection = 'Year ' . $yearLevel . '-' . $sectionCode;
                        } elseif ($yearLevel !== '') {
                            $yearSection = 'Year ' . $yearLevel;
                        } elseif ($sectionCode !== '') {
                            $yearSection = $sectionCode;
                        } else {
                            $yearSection = $r->year_section ?? $r->term ?? null;
                        }

                        return [
                            // explicit fields requested for reporting
                            'code' => $courseCode,
                             'school_year_id' => $r->school_year_id ?? null,
                            'year_section' => $yearSection,
                            'year_level' => $yearLevel !== '' ? $yearLevel : null,
                            'section_code' => $sectionCode !== '' ? $sectionCode : null,
                            'subject_id' => $r->subject_id ?? $r->id ?? null,

                            // keep aliases for existing consumers
                            'course_code' => $courseCode,
                            'term' => $yearSection,
                            'title' => $courseDescription,
                            'course_description' => $courseDescription,
                            'raw' => (array) $r,
                        ];
                    })->values()->all();
                }
            } catch (\Exception $e) {
                // if external DB is unavailable, leave subjects empty
                $subjects = [];
            }

            return [
                'initials' => $initials ?: 'N/A',
                'instructor' => $fullName,
                'subjects' => $subjects,
                'user_id' => $localUser->id,
                'id_no' => $localUser->id_no,
            ];
        })->values()->all();
    }
}
