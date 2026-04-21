<?php

namespace App\Http\Controllers;

use App\Models\UnitHeadGrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitHeadGradeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'instructor' => ['required', 'string', 'max:255'],
            'course_code' => ['required', 'string', 'max:100'],
            'course_title' => ['required', 'string', 'max:255'],
            'term' => ['required', 'string', 'max:255'],
            'grade' => ['required', 'numeric', 'between:1,5'],
        ]);

        $grade = UnitHeadGrade::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'instructor' => $validated['instructor'],
                'course_code' => $validated['course_code'],
            ],
            [
                'course_title' => $validated['course_title'],
                'term' => $validated['term'],
                'grade' => round((float) $validated['grade'], 2),
                'submitted_at' => now(),
            ]
        );

        return response()->json([
            'message' => $grade->wasRecentlyCreated
                ? 'Grade submitted successfully.'
                : 'Grade updated successfully.',
            'grade' => (float) $grade->grade,
        ], $grade->wasRecentlyCreated ? 201 : 200);
    }
}
