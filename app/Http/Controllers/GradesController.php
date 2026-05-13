<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\FacultyData;
use App\Models\UnitHeadGrade;
use Inertia\Inertia;
use Illuminate\Http\Request;

class GradesController extends Controller
{
    use FacultyData;

    public function index(Request $request)
    {
        $facultyEvaluations = $this->getFacultyEvaluations();
        $currentUser = $request->user();
        $canAccessEvaluation = $this->canAccessEvaluationForUser($currentUser);
        abort_if(! $canAccessEvaluation, 403);

        $profilePhotoUrl = $currentUser?->personalInformation?->profile_photo_path
            ? asset('storage/' . $currentUser->personalInformation->profile_photo_path)
            : null;

        $existingGrades = UnitHeadGrade::query()
            ->where('user_id', $currentUser->id)
            ->latest('submitted_at')
            ->get()
            ->keyBy(function ($grade) {
                return $grade->instructor . '|' . $grade->course_code;
            });

        $evaluations = collect($facultyEvaluations)
            ->map(function ($faculty) use ($existingGrades) {
                $primarySubject = $faculty['subjects'][0] ?? ['code' => '', 'title' => '', 'term' => ''];
                $gradeKey = $faculty['instructor'] . '|' . ($primarySubject['code'] ?? '');
                $existingGrade = $existingGrades->get($gradeKey);

                return [
                    'initials' => $faculty['initials'],
                    'instructor' => $faculty['instructor'],
                    'code' => $primarySubject['code'] ?? '',
                    'title' => $primarySubject['title'] ?? '',
                    'term' => $primarySubject['term'] ?? '',
                    'final_grade' => $existingGrade ? (float) $existingGrade->grade : null,
                ];
            })
            ->values()
            ->all();

        $gradedCount = collect($evaluations)
            ->filter(function ($item) {
                return $item['final_grade'] !== null;
            })
            ->count();

        $gradesProps = [
            'appName' => config('app.name', 'FIMS'),
            'dashboardUrl' => route('dashboard'),
            'subjectsUrl' => route('subjects'),
            'evaluationUrl' => route('evaluation'),
            'gradesUrl' => route('grades'),
            'reportsUrl' => route('reports'),
            'profileUrl' => route('my-profile.edit'),
            'accountSettingsUrl' => route('account-settings.edit'),
            'unitHeadGradeStoreUrl' => route('unit-head-grades.store'),
            'logoutUrl' => route('logout'),
            'csrfToken' => csrf_token(),
            'user' => [
                'id_no' => $currentUser?->id_no,
                'firstname' => $currentUser?->firstname,
                'lastname' => $currentUser?->lastname,
                'profile_photo_url' => $profilePhotoUrl,
            ],
            'evaluations' => $evaluations,
            'hasPendingEvaluations' => $gradedCount < count($facultyEvaluations),
            'canAccessEvaluation' => $canAccessEvaluation,
        ];

        return Inertia::render('GradesPage', $gradesProps);
    }
}
