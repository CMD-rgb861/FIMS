<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\UnitHeadGrade;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    use FacultyData;

    public function index(Request $request)
    {
        $facultyEvaluations = $this->getFacultyEvaluations();
        $currentUser = $request->user();
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
                return [
                    'initials' => $faculty['initials'],
                    'instructor' => $faculty['instructor'],
                    'subjects_count' => count($faculty['subjects'] ?? []),
                    'evaluated' => $evaluatedInstructors->contains($faculty['instructor']),
                    'employee_id_no' => 'EMP-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'detail_url' => route('reports.faculty', ['instructor' => $faculty['instructor']]),
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
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'profile_photo_url' => $profilePhotoUrl,
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

        return view('reports', ['reportsProps' => $reportsProps]);
    }

    public function faculty(Request $request, string $instructor)
    {
        $facultyEvaluations = $this->getFacultyEvaluations();
        $currentUser = $request->user();
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
        $employeeIdNo = 'EMP-' . str_pad((string) ($facultyIndex + 1), 3, '0', STR_PAD_LEFT);

        $facultySubmissions = SupervisorEvaluationSubmission::query()
            ->where('user_id', $currentUser->id)
            ->where('instructor', $facultyMeta['instructor'])
            ->latest('submitted_at')
            ->get();

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

        $tableRows = $facultySubmissions
            ->map(function ($submission) use ($employeeIdNo, $facultyMeta, $setBreakdown) {
                $ratings = $submission->ratings ?? [];
                $totalScore = collect($ratings)->sum(function ($score) {
                    return (int) $score;
                });

                $sefScore = round(($totalScore / 75) * 100, 2);

                $setGrade = UnitHeadGrade::query()
                    ->where('user_id', $submission->user_id)
                    ->where('instructor', $submission->instructor)
                    ->where('course_code', $submission->course_code)
                    ->latest('submitted_at')
                    ->value('grade');

                $status = $setGrade !== null ? 'Evaluated' : 'For Evaluation';

                return [
                    'id' => $submission->id,
                    'employee_id_no' => $employeeIdNo,
                    'employee_name' => $facultyMeta['instructor'],
                    'set_score' => $setGrade !== null ? number_format((float) $setGrade, 2) : '-',
                    'sef_score' => number_format($sefScore, 2) . '%',
                    'sef_total_score' => $totalScore,
                    'sef_rating' => number_format($sefScore, 2) . '%',
                    'status' => $status,
                    'action_url' => route('evaluation', [
                        'subject' => $facultyMeta['instructor'],
                        'term' => 'all',
                    ]),
                    'action_label' => 'View',
                    'set_breakdown' => $setBreakdown,
                ];
            })
            ->values()
            ->all();

        $facultyReportProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('profile.edit'),
            'accountSettingsUrl' => route('account.settings.edit'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'profile_photo_url' => $profilePhotoUrl,
            ],
            'facultyName' => $facultyMeta['instructor'],
            'tableRows' => $tableRows,
            'hasPendingEvaluations' => SupervisorEvaluationSubmission::query()
                ->where('user_id', $currentUser->id)
                ->distinct('instructor')
                ->count('instructor') < count($facultyEvaluations),
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return view('faculty-report', ['facultyReportProps' => $facultyReportProps]);
    }
}
