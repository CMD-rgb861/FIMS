<?php

namespace App\Http\Controllers;

use App\Models\SupervisorEvaluationSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacultyEvaluationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $canAccessEvaluation = $request->user()->isUnitHead();

        abort_if(! $canAccessEvaluation, 403);

        $validated = $request->validate([
            'instructor' => ['required', 'string', 'max:255'],
            'course_code' => ['required', 'string', 'max:100'],
            'course_title' => ['nullable', 'string', 'max:255'],
            'term' => ['required', 'string', 'max:255'],
            'ratings' => ['required', 'array', 'min:1'],
            'ratings.*' => ['required', 'numeric', 'between:1,5'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        $submission = SupervisorEvaluationSubmission::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'instructor' => $validated['instructor'],
                'course_code' => $validated['course_code'],
            ],
            [
                'course_title' => trim((string) ($validated['course_title'] ?? '')),
                'term' => $validated['term'],
                'ratings' => $validated['ratings'],
                'comments' => $validated['comments'] ?? null,
                'submitted_at' => now(),
            ]
        );

        return response()->json([
            'message' => $submission->wasRecentlyCreated
                ? 'Evaluation submitted successfully.'
                : 'Evaluation updated successfully.',
        ], $submission->wasRecentlyCreated ? 201 : 200);
    }
}
