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
        abort_if(! $canAccessEvaluation, 403); // Backend access gate remains

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

        $gradesProps = $this->commonInertiaProps($currentUser, [
            'evaluations' => $evaluations,
        ]);

        return Inertia::render('GradesPage', $gradesProps);
    }
}
