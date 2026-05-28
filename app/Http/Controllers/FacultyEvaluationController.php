<?php

namespace App\Http\Controllers;

use App\Models\SupervisorEvaluationSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FacultyEvaluationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $canAccessEvaluation = method_exists($user, 'canEvaluateFaculty')
            ? $user->canEvaluateFaculty()
            : $user->isUnitHead();

        abort_if(! $canAccessEvaluation, 403);

        $validated = $request->validate([
            'instructor' => ['required', 'string', 'max:255'],
            'course_code' => ['required', 'string', 'max:100'],
            'course_title' => ['nullable', 'string', 'max:255'],
            'term' => ['required', 'string', 'max:255'],
            'answers' => ['required', 'array', 'min:1'],
            'answers.*' => ['required', 'numeric', 'between:1,5'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        // Check if user already submitted for this instructor, course, and term
        $existingSubmission = SupervisorEvaluationSubmission::where([
            'user_id' => $request->user()->id,
            'instructor' => $validated['instructor'],
            'course_code' => $validated['course_code'],
            'term' => $validated['term'],
        ])->first();

        if ($existingSubmission) {
            return response()->json([
                'message' => 'You have already evaluated this instructor for this course and term.',
            ], 409);
        }

        // Calculate scores
        $answersArray = $validated['answers'];
        $totalScore = array_sum($answersArray);
        $questionCount = count($answersArray);
        $maxScore = $questionCount * 5;
        $ratingPercentage = $maxScore > 0 ? ($totalScore / $maxScore) * 100 : 0;

        DB::beginTransaction();

        try {
            // Create submission
            $submission = SupervisorEvaluationSubmission::create([
                'user_id' => $request->user()->id,
                'instructor' => $validated['instructor'],
                'course_code' => $validated['course_code'],
                'course_title' => trim((string) ($validated['course_title'] ?? '')),
                'term' => $validated['term'],
                'total_score' => $totalScore,
                'max_score' => $maxScore,
                'rating_percentage' => round($ratingPercentage, 2),
                'comments' => $validated['comments'] ?? null,
                'submitted_at' => now(),
                'status' => 'submitted',
            ]);

            // Create answers (using question_key like q1, q2, q3...)
            foreach ($answersArray as $index => $score) {
                $submission->answers()->create([
                    'question_key' => 'q' . ($index + 1),
                    'score' => (int) $score,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Evaluation submitted successfully.',
                'data' => [
                    'id' => $submission->id,
                    'rating_percentage' => $submission->rating_percentage,
                    'total_score' => $submission->total_score,
                    'max_score' => $submission->max_score,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to submit evaluation.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all submissions for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $submissions = SupervisorEvaluationSubmission::with('answers')
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'submissions' => $submissions->map(function ($submission) {
                return [
                    'id' => $submission->id,
                    'instructor' => $submission->instructor,
                    'course_code' => $submission->course_code,
                    'course_title' => $submission->course_title,
                    'term' => $submission->term,
                    'rating_percentage' => $submission->rating_percentage,
                    'comments' => $submission->comments,
                    'submitted_at' => $submission->submitted_at,
                    'status' => $submission->status,
                    'answers' => $submission->answers->map(function ($answer) {
                        return [
                            'question_key' => $answer->question_key,
                            'score' => $answer->score,
                        ];
                    }),
                ];
            }),
        ]);
    }

    /**
     * Get a specific submission
     */
    public function show(int $id): JsonResponse
    {
        $submission = SupervisorEvaluationSubmission::with('answers')
            ->findOrFail($id);

        return response()->json([
            'submission' => [
                'id' => $submission->id,
                'user_id' => $submission->user_id,
                'instructor' => $submission->instructor,
                'course_code' => $submission->course_code,
                'course_title' => $submission->course_title,
                'term' => $submission->term,
                'total_score' => $submission->total_score,
                'max_score' => $submission->max_score,
                'rating_percentage' => $submission->rating_percentage,
                'comments' => $submission->comments,
                'submitted_at' => $submission->submitted_at,
                'status' => $submission->status,
                'created_at' => $submission->created_at,
                'updated_at' => $submission->updated_at,
                'answers' => $submission->answers->map(function ($answer) {
                    return [
                        'question_key' => $answer->question_key,
                        'score' => $answer->score,
                    ];
                }),
            ],
        ]);
    }
}