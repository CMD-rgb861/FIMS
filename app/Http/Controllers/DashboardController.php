<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\UnitHeadGrade;
use Illuminate\Http\Request;

class DashboardController extends Controller
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

        $firstName = trim((string) ($currentUser?->firstname ?? ''));
        $middleName = trim((string) ($currentUser?->middlename ?? ''));
        $lastName = trim((string) ($currentUser?->lastname ?? ''));
        $extName = trim((string) ($currentUser?->extname ?? ''));

        $nameCandidates = collect([
            trim(collect([$firstName, $lastName])->filter()->implode(' ')),
            trim(collect([$firstName, $middleName, $lastName])->filter()->implode(' ')),
            trim(collect([$firstName, $middleName, $lastName, $extName])->filter()->implode(' ')),
            trim(collect([$firstName, $lastName, $extName])->filter()->implode(' ')),
            trim($lastName !== '' && $firstName !== '' ? ($lastName . ', ' . $firstName) : ''),
            trim($lastName !== '' && $firstName !== '' ? ($lastName . ', ' . collect([$firstName, $middleName])->filter()->implode(' ')) : ''),
            trim($lastName !== '' && $firstName !== '' ? ($lastName . ', ' . collect([$firstName, $middleName, $extName])->filter()->implode(' ')) : ''),
        ])
            ->filter(function ($value) {
                return is_string($value) && trim($value) !== '';
            })
            ->map(function ($value) {
                return trim((string) $value);
            })
            ->unique(function ($value) {
                return mb_strtolower($value);
            })
            ->values()
            ->all();

        $submissionBaseQuery = SupervisorEvaluationSubmission::query();
        $gradeBaseQuery = UnitHeadGrade::query();

        if ($canAccessEvaluation) {
            $submissionBaseQuery->where('user_id', $currentUser->id);
            $gradeBaseQuery->where('user_id', $currentUser->id);
        } else {
            if (!empty($nameCandidates)) {
                $submissionBaseQuery->where(function ($query) use ($nameCandidates) {
                    foreach ($nameCandidates as $name) {
                        $query->orWhere('instructor', $name);
                    }
                });

                $gradeBaseQuery->where(function ($query) use ($nameCandidates) {
                    foreach ($nameCandidates as $name) {
                        $query->orWhere('instructor', $name);
                    }
                });
            } else {
                $submissionBaseQuery->where('id', 0);
                $gradeBaseQuery->where('id', 0);
            }
        }

        $totalInstructors = count($facultyEvaluations);
        $evaluatedInstructors = (clone $submissionBaseQuery)
            ->pluck('instructor')
            ->unique()
            ->values()
            ->all();

        $evaluatedCount = count($evaluatedInstructors);
        $pendingCount = max($totalInstructors - $evaluatedCount, 0);
        $completionRate = $totalInstructors > 0
            ? round(($evaluatedCount / $totalInstructors) * 100, 2)
            : 0;

        $recentEvaluations = (clone $submissionBaseQuery)
            ->latest('submitted_at')
            ->take(5)
            ->get()
            ->map(function ($submission) {
                $ratings = $submission->ratings ?? [];
                $totalScore = collect($ratings)->sum(function ($score) {
                    return (int) $score;
                });

                return [
                    'instructor' => $submission->instructor,
                    'course_code' => $submission->course_code,
                    'course_title' => $submission->course_title,
                    'rating_percentage' => round(($totalScore / 75) * 100, 2),
                    'submitted_at' => optional($submission->submitted_at)->format('M d, Y h:i A') ?? '-',
                ];
            })
            ->values()
            ->all();

        $latestEvaluation = $recentEvaluations[0] ?? null;
        $unitHeadEvaluationRating = $latestEvaluation
            ? ($latestEvaluation['rating_percentage'] . '%')
            : 'N/A';
        $unitHeadEvaluationHelper = $latestEvaluation
            ? (($latestEvaluation['course_code'] ?: 'N/A') . ' · ' . $latestEvaluation['instructor'])
            : 'Unit Head evaluation rating will appear once submitted.';

        $unitHeadGrades = (clone $gradeBaseQuery)
            ->latest('submitted_at')
            ->get()
            ->unique(function ($grade) {
                return $grade->instructor . '|' . $grade->course_code;
            })
            ->values()
            ->map(function ($grade) {
                return [
                    'instructor' => $grade->instructor,
                    'course_code' => $grade->course_code,
                    'course_title' => $grade->course_title,
                    'term' => $grade->term,
                    'grade' => (float) $grade->grade,
                    'submitted_at' => optional($grade->submitted_at)->format('M d, Y h:i A') ?? '-',
                ];
            })
            ->all();

        $averageGrade = count($unitHeadGrades) > 0
            ? round(collect($unitHeadGrades)->avg('grade'), 2)
            : null;

        $latestUnitHeadGrade = $unitHeadGrades[0] ?? null;

        $dashboardProps = [
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
                'role' => method_exists($currentUser, 'resolveRole') ? $currentUser->resolveRole() : null,
                'isAdmin' => method_exists($currentUser, 'isAdmin') ? $currentUser->isAdmin() : false,
                'isUnitHead' => $canAccessEvaluation,
            ],
            'summaryCards' => [
                [
                    'label' => 'Total Instructors',
                    'value' => $totalInstructors,
                    'helper' => 'Faculty members assigned this term.',
                ],
                [
                    'label' => 'Evaluated',
                    'value' => $evaluatedCount,
                    'helper' => 'Instructors you already evaluated.',
                ],
                [
                    'label' => 'Pending',
                    'value' => $pendingCount,
                    'helper' => 'Instructors left to evaluate.',
                ],
                [
                    'label' => 'Completion Rate',
                    'value' => $completionRate . '%',
                    'helper' => 'Overall evaluation completion.',
                ],
            ],
            'unitHeadGrades' => $unitHeadGrades,
            'unitHeadEvaluationRating' => $unitHeadEvaluationRating,
            'unitHeadEvaluationHelper' => $unitHeadEvaluationHelper,
            'gradeSummaryCards' => [
                [
                    'label' => $canAccessEvaluation ? 'Unit Head Grade' : 'Faculty Grade',
                    'value' => isset($latestUnitHeadGrade['grade'])
                        ? number_format((float) $latestUnitHeadGrade['grade'], 2)
                        : 'N/A',
                    'helper' => $latestUnitHeadGrade
                        ? $latestUnitHeadGrade['course_code'] . ' · ' . $latestUnitHeadGrade['instructor']
                        : ($canAccessEvaluation ? 'No grade issued yet.' : 'No grade received yet.'),
                ],
                [
                    'label' => 'Subjects Graded',
                    'value' => count($unitHeadGrades),
                    'helper' => 'Unique subject and instructor entries.',
                ],
                [
                    'label' => 'Average Grade',
                    'value' => $averageGrade !== null ? number_format($averageGrade, 2) : 'N/A',
                    'helper' => 'Average of your recorded grades.',
                ],
            ],
            'recentEvaluations' => $recentEvaluations,
            'hasPendingEvaluations' => $pendingCount > 0,
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return view('dashboard', ['dashboardProps' => $dashboardProps]);
    }
}
