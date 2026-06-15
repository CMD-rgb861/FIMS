<?php

namespace App\Http\Controllers;

use App\Models\SupervisorEvaluationSubmission;
use App\Models\User;
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
            'instructor'   => ['required', 'string', 'max:255'],
            'instructor_id_no' => ['nullable', 'integer'],
            'course_code'  => ['nullable', 'string', 'max:100'],
            'course_title' => ['nullable', 'string', 'max:255'],
            // Supports both legacy term (string label) and new term_id (school year id)
            'term'         => ['nullable', 'string', 'max:255'],
            'term_id'      => ['nullable', 'integer', 'exists:lnu_poes.school_years,id'],
            // Supports both payload styles: ratings ({q1: 5}) and answers ([5,4,...])
            'ratings'      => ['nullable', 'array', 'min:1'],
            'ratings.*'    => ['required_with:ratings', 'numeric', 'between:1,5'],
            'answers'      => ['nullable', 'array', 'min:1'],
            'answers.*'    => ['required_with:answers', 'numeric', 'between:1,5'],
            'comments'     => ['nullable', 'string', 'max:2000'],
            'evaluated_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'college_id'   => ['nullable', 'integer', 'exists:colleges,id'],
            'unit_id'      => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $answersInput = $validated['answers'] ?? $validated['ratings'] ?? [];
        if (empty($answersInput)) {
            return response()->json([
                'message' => 'Please provide at least one rating.',
            ], 422);
        }

        // Resolve term_id: prefer explicit term_id, otherwise use active school year
        if (!empty($validated['term_id'])) {
            $termId = (int) $validated['term_id'];
        } else {
            $termId = DB::connection('lnu_poes')
                ->table('school_years')
                ->where('is_active', 1)
                ->value('id');
        }

        if (empty($termId)) {
            return response()->json([
                'message' => 'Term (school year) is required.',
            ], 422);
        }

        $courseCode = trim((string) ($validated['course_code'] ?? ''));
        if (in_array(strtoupper($courseCode), ['', 'N/A', 'NA', '-'], true)) {
            $courseCode = null;
        }

        $courseTitle = trim((string) ($validated['course_title'] ?? ''));
        if (in_array(strtoupper($courseTitle), ['', 'N/A', 'NA', '-'], true)) {
            $courseTitle = null;
        }

        $evaluatedUser = null;
        if (!empty($validated['evaluated_user_id'])) {
            $evaluatedUser = User::query()
                ->select(['id', 'id_no', 'college_id', 'unit_id'])
                ->find($validated['evaluated_user_id']);
        }

        $collegeId = $validated['college_id'] ?? $evaluatedUser?->college_id;
        $unitId = $validated['unit_id'] ?? $evaluatedUser?->unit_id;

        $instructorIdNo = $validated['instructor_id_no']
            ?? ($evaluatedUser?->id_no ?? null);

        if ($instructorIdNo === null) {
            return response()->json([
                'message' => 'Unable to resolve instructor id_no for this submission.',
            ], 422);
        }

        // Check for duplicate submission for the same evaluator/instructor/term
        $duplicateQuery = SupervisorEvaluationSubmission::query()
            ->where('user_id', $request->user()->id)
            ->where('instructor_id_no', (int) $instructorIdNo)
            ->where('term_id', $termId);

        $existingSubmission = $duplicateQuery->first();

        if ($existingSubmission) {
            return response()->json([
                'message' => 'You have already evaluated this instructor for this term.',
            ], 409);
        }

        // Calculate scores
        $normalizedAnswers = [];
        $runningIndex = 1;
        foreach ($answersInput as $key => $score) {
            $normalizedScore = max(1, min(5, (int) $score));
            $questionKey = is_string($key)
                ? trim($key)
                : 'q' . $runningIndex;

            if ($questionKey === '' || is_numeric($questionKey)) {
                $questionKey = 'q' . $runningIndex;
            }

            $normalizedAnswers[] = [
                'question_key' => $questionKey,
                'score' => $normalizedScore,
            ];
            $runningIndex++;
        }

        $totalScore = array_sum(array_column($normalizedAnswers, 'score'));
        $questionCount = count($normalizedAnswers);
        $maxScore = $questionCount * 5;
        $ratingPercentage = $maxScore > 0 ? ($totalScore / $maxScore) * 100 : 0;

        DB::beginTransaction();

        try {
            $submission = SupervisorEvaluationSubmission::create([
                'user_id'           => $request->user()->id,
                'instructor_id_no'  => (int) $instructorIdNo,
                'college_id'        => $collegeId,
                'unit_id'           => $unitId,
                'term_id'           => $termId,
                'total_score'       => $totalScore,
                'max_score'         => $maxScore,
                'rating_percentage' => round($ratingPercentage, 2),
                'comments'          => $validated['comments'] ?? null,
                'submitted_at'      => now(),
                'status'            => 'submitted',
            ]);

            foreach ($normalizedAnswers as $answer) {
                $submission->answers()->create([
                    'question_key' => $answer['question_key'],
                    'score'        => $answer['score'],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Evaluation submitted successfully.',
                'data'    => [
                    'id'                => $submission->id,
                    'instructor_id_no'  => $submission->instructor_id_no,
                    'rating_percentage' => $submission->rating_percentage,
                    'total_score'       => $submission->total_score,
                    'max_score'         => $submission->max_score,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to submit evaluation.',
                'error'   => $e->getMessage(),
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
                    'id'                => $submission->id,
                    'instructor_id_no'  => $submission->instructor_id_no,
                    'course_code'       => null,
                    'course_title'      => null,
                    'college_id'        => $submission->college_id,
                    'unit_id'           => $submission->unit_id,
                    'term_id'           => $submission->term_id,
                    'rating_percentage' => $submission->rating_percentage,
                    'comments'          => $submission->comments,
                    'submitted_at'      => $submission->submitted_at,
                    'status'            => $submission->status,
                    'answers'           => $submission->answers->map(function ($answer) {
                        return [
                            'question_key' => $answer->question_key,
                            'score'        => $answer->score,
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
                'id'                => $submission->id,
                'user_id'           => $submission->user_id,
                'instructor_id_no'  => $submission->instructor_id_no,
                'course_code'       => null,
                'course_title'      => null,
                'college_id'        => $submission->college_id,
                'unit_id'           => $submission->unit_id,
                'term_id'           => $submission->term_id,
                'total_score'       => $submission->total_score,
                'max_score'         => $submission->max_score,
                'rating_percentage' => $submission->rating_percentage,
                'comments'          => $submission->comments,
                'submitted_at'      => $submission->submitted_at,
                'status'            => $submission->status,
                'created_at'        => $submission->created_at,
                'updated_at'        => $submission->updated_at,
                'answers'           => $submission->answers->map(function ($answer) {
                    return [
                        'question_key' => $answer->question_key,
                        'score'        => $answer->score,
                    ];
                }),
            ],
        ]);
    }
}