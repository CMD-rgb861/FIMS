<?php

namespace App\Http\Controllers;

use App\Models\SupervisorEvaluationSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacultyEvaluationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'instructor' => ['required', 'string', 'max:255'],
            'course_code' => ['required', 'string', 'max:100'],
            'course_title' => ['required', 'string', 'max:255'],
            'term' => ['required', 'string', 'max:255'],
            'ratings' => ['required', 'array', 'min:1'],
            'ratings.*' => ['required', 'integer', 'between:1,5'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        $alreadyEvaluated = SupervisorEvaluationSubmission::query()
            ->where('user_id', $request->user()->id)
            ->where('instructor', $validated['instructor'])
            ->exists();

        if ($alreadyEvaluated) {
            return response()->json([
                'message' => 'You have already evaluated this instructor.',
            ], 422);
        }

        SupervisorEvaluationSubmission::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Evaluation submitted successfully.',
        ], 201);
    }
}
