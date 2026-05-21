<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnswerController extends Controller
{
    public function getAnswers(Request $request, $submissionId)
    {
        try {
            $validated = $request->validate([
                'term_id' => 'nullable|string',
            ]);

            $answers = DB::connection('lnu_poes')
                ->table('student_evaluation_answers as sea')
                ->join('evaluation_questions as eq', 'sea.question_id', '=', 'eq.id')
                ->select(
                    'sea.id',
                    'sea.question_id',
                    'eq.question_text',
                    'eq.question_type',
                    'eq.display_order',
                    'sea.answer_text',
                    'sea.selected_option',
                    'sea.rating_value'
                )
                ->where('sea.submission_id', $submissionId)
                ->orderBy('eq.display_order')
                ->orderBy('eq.id')
                ->get();

            // Get submission details for context
            $submission = DB::connection('lnu_poes')
                ->table('student_evaluation_submissions')
                ->select('id', 'student_id', 'subject_id', 'instructor_id', 'rating_percentage', 'total_score')
                ->where('id', $submissionId)
                ->first();

            return response()->json([
                'success' => true,
                'answers' => $answers,
                'submission_id' => $submissionId,
                'submission' => $submission,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch answers: ' . $e->getMessage(),
                'answers' => [],
            ], 500);
        }
    }

    public function updateAnswers(Request $request, $submissionId)
    {
        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*' => 'nullable|string',
            'term_id' => 'nullable|string',
        ]);

        DB::connection('lnu_poes')->beginTransaction();

        try {
            $updatedCount = 0;
            
            foreach ($validated['answers'] as $questionId => $answerValue) {
                // Skip empty answers
                if ($answerValue === null || $answerValue === '') {
                    continue;
                }
                
                // Determine if this is a rating question
                $question = DB::connection('lnu_poes')
                    ->table('evaluation_questions')
                    ->where('id', $questionId)
                    ->first();
                
                $updateData = [
                    'updated_at' => now(),
                ];
                
                // Handle different question types
                if ($question && $question->question_type === 'rating') {
                    $updateData['rating_value'] = (int) $answerValue;
                    $updateData['selected_option'] = $answerValue;
                } elseif ($question && $question->question_type === 'text') {
                    $updateData['answer_text'] = $answerValue;
                } else {
                    // Default - store in both fields
                    $updateData['answer_text'] = $answerValue;
                    $updateData['selected_option'] = $answerValue;
                }
                
                $updated = DB::connection('lnu_poes')
                    ->table('student_evaluation_answers')
                    ->where('submission_id', $submissionId)
                    ->where('question_id', $questionId)
                    ->update($updateData);
                    
                if ($updated) {
                    $updatedCount++;
                }
            }

            // Recalculate rating percentage after updates
            $this->recalculateRating($submissionId);

            DB::connection('lnu_poes')->commit();

            return response()->json([
                'success' => true,
                'message' => 'Answers updated successfully',
                'updated_count' => $updatedCount,
            ]);
            
        } catch (\Exception $e) {
            DB::connection('lnu_poes')->rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update answers: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function recalculateRating($submissionId)
    {
        // Get all answers for this submission
        $answers = DB::connection('lnu_poes')
            ->table('student_evaluation_answers as sea')
            ->join('evaluation_questions as eq', 'sea.question_id', '=', 'eq.id')
            ->select('sea.*', 'eq.question_type')
            ->where('sea.submission_id', $submissionId)
            ->get();

        $totalScore = 0;
        $maxPossibleScore = 0;
        $totalQuestions = 0;

        foreach ($answers as $answer) {
            $totalQuestions++;
            
            // Calculate based on question type
            if ($answer->question_type === 'rating') {
                $ratingValue = $answer->rating_value ?? (int)($answer->selected_option ?? 0);
                if ($ratingValue > 0) {
                    $totalScore += $ratingValue;
                    $maxPossibleScore += 5; // Max rating of 5
                }
            } elseif ($answer->question_type === 'multiple_choice') {
                // For multiple choice, check if correct (you might have a different scoring system)
                // This is a placeholder - adjust based on your actual scoring
                $totalScore += 1; // Assume each correct answer gives 1 point
                $maxPossibleScore += 1;
            }
            // For text questions, they might not contribute to score
        }

        // Calculate percentage
        $percentage = $maxPossibleScore > 0 
            ? round(($totalScore / $maxPossibleScore) * 100, 2)
            : null;

        // Update submission with new scores
        if ($percentage !== null) {
            DB::connection('lnu_poes')
                ->table('student_evaluation_submissions')
                ->where('id', $submissionId)
                ->update([
                    'rating_percentage' => $percentage,
                    'total_score' => $totalScore,
                    'updated_at' => now(),
                ]);
        }
        
        return $percentage;
    }
}