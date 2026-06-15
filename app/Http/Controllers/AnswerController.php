<?php

namespace App\Http\Controllers;

use App\Models\Poes\PoesEvalAnswers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AnswerController extends Controller
{
    /**
     * Cache question texts to avoid repeated DB lookups
     */
    protected $questionTexts = [];
    
    /**
     * Get answers for a specific submission
     */
    public function getAnswers(Request $request, $submissionId)
    {
        try {
            // Convert term_id to integer if provided
            $termId = $request->get('term_id');
            if ($termId !== null) {
                $termId = (int) $termId;
            }
            
            // Use a single query with join instead of two separate queries
            $result = DB::connection('lnu_poes')
                ->table('student_evaluation_submissions as s')
                ->leftJoin('student_evaluation_submission_answers as a', 's.id', '=', 'a.submission_id')
                ->where('s.id', $submissionId)
                ->select([
                    's.id as submission_id',
                    's.student_id_number',
                    's.subject_id',
                    's.instructor_id',
                    's.rating_percentage',
                    's.total_score',
                    's.max_score',
                    's.comment',
                    's.submitted_at',
                    'a.id as answer_id',
                    'a.question_key',
                    'a.score',
                    'a.created_at as answer_created_at',
                    'a.updated_at as answer_updated_at'
                ])
                ->get();
                
            if ($result->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Submission not found'
                ], 404);
            }
            
            // Extract submission data from first row
            $firstRow = $result->first();
            $submissionData = [
                'id' => $firstRow->submission_id,
                'student_id_number' => $firstRow->student_id_number,
                'subject_id' => $firstRow->subject_id,
                'instructor_id' => $firstRow->instructor_id,
                'rating_percentage' => $firstRow->rating_percentage,
                'total_score' => $firstRow->total_score,
                'max_score' => $firstRow->max_score,
                'comment' => $firstRow->comment,
                'submitted_at' => $firstRow->submitted_at
            ];
            
            // Get all question keys from the result to fetch texts in batch
            $questionKeys = $result->pluck('question_key')->filter()->values()->all();
            $questionTexts = $this->getBulkQuestionTexts($questionKeys);
            
            // Format answers
            $formattedAnswers = [];
            foreach ($result as $row) {
                if ($row->answer_id) { // Only if answer exists
                    $formattedAnswers[] = [
                        'id' => $row->answer_id,
                        'question_id' => $row->question_key,
                        'rating_value' => $row->score,
                        'selected_option' => $row->score,
                        'answer_text' => $row->score,
                        'created_at' => $row->answer_created_at,
                        'updated_at' => $row->answer_updated_at,
                        'question_text' => $questionTexts[$row->question_key] ?? "Question {$row->question_key}",
                        'question_type' => 'rating',
                        'display_order' => $this->getQuestionOrder($row->question_key)
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'answers' => $formattedAnswers,
                'submission_id' => $submissionId,
                'submission' => $submissionData
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch answers: ' . $e->getMessage(),
                'answers' => [],
            ], 500);
        }
    }
    
    /**
     * Get answers for multiple submissions in batch (FIXED)
     * POST /answers/batch
     */
    public function getBatchAnswers(Request $request)
    {
        $validated = $request->validate([
            'submission_ids' => 'required|array',
            'submission_ids.*' => 'integer', // Removed exists validation to avoid errors
            'term_id' => 'nullable|integer',
        ]);

        try {
            $submissionIds = $validated['submission_ids'];
            
            if (empty($submissionIds)) {
                return response()->json([
                    'success' => true,
                    'answers_by_submission' => []
                ]);
            }
            
            // Fetch all answers for all submission IDs in ONE query
            $allAnswers = DB::connection('lnu_poes')
                ->table('student_evaluation_submission_answers as a')
                ->join('student_evaluation_submissions as s', 'a.submission_id', '=', 's.id')
                ->whereIn('a.submission_id', $submissionIds)
                ->select([
                    'a.submission_id',
                    'a.question_key',
                    'a.score',
                    's.comment',
                    's.rating_percentage',
                    's.total_score',
                    's.max_score',
                    's.submitted_at'
                ])
                ->get();
            
            // Get all unique question keys for batch text fetching
            $questionKeys = $allAnswers->pluck('question_key')->unique()->values()->toArray();
            $questionTexts = $this->getBulkQuestionTexts($questionKeys);
            
            // Initialize results for all submission IDs with default values
            $results = [];
            foreach ($submissionIds as $submissionId) {
                $results[$submissionId] = [
                    'ratings' => array_fill(0, 15, 4),
                    'comment' => '',
                    'rating_percentage' => null,
                    'total_score' => null,
                    'max_score' => null,
                    'submitted_at' => null
                ];
            }
            
            // Group answers by submission_id and populate results
            foreach ($allAnswers as $answer) {
                $submissionId = $answer->submission_id;
                
                // Set submission data if not already set
                if ($results[$submissionId]['comment'] === '') {
                    $results[$submissionId]['comment'] = $answer->comment ?? '';
                    $results[$submissionId]['rating_percentage'] = $answer->rating_percentage;
                    $results[$submissionId]['total_score'] = $answer->total_score;
                    $results[$submissionId]['max_score'] = $answer->max_score;
                    $results[$submissionId]['submitted_at'] = $answer->submitted_at;
                }
                
                // Map question to rating index
                $ratingIndex = $this->getRatingIndex($answer->question_key);
                if ($ratingIndex !== null && $answer->score >= 1 && $answer->score <= 5) {
                    $results[$submissionId]['ratings'][$ratingIndex] = (int) $answer->score;
                }
            }
            
            return response()->json([
                'success' => true,
                'answers_by_submission' => $results
            ]);
            
        } catch (\Exception $e) {
            Log::error('Batch answers error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'submission_ids' => $submissionIds ?? []
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch answers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get rating index from question key
     */
    private function getRatingIndex($questionKey)
    {
        $questionMap = [
            // Section A (s0_i0 to s0_i5) - 6 questions
            's0_i0' => 0, 's0_i1' => 1, 's0_i2' => 2, 's0_i3' => 3, 's0_i4' => 4, 's0_i5' => 5,
            // Section B (s1_i0 to s1_i4) - 5 questions
            's1_i0' => 6, 's1_i1' => 7, 's1_i2' => 8, 's1_i3' => 9, 's1_i4' => 10,
            // Section C (s2_i0 to s2_i3) - 4 questions
            's2_i0' => 11, 's2_i1' => 12, 's2_i2' => 13, 's2_i3' => 14
        ];
        
        return $questionMap[$questionKey] ?? null;
    }
    
    /**
     * Convert answers to ratings array (15 items)
     */
    private function convertAnswersToRatingsArray($answers)
    {
        $ratings = array_fill(0, 15, 4); // Default to 4
        
        foreach ($answers as $answer) {
            $questionKey = $answer['question_key'];
            $score = (int) $answer['score'];
            
            $ratingIndex = $this->getRatingIndex($questionKey);
            if ($ratingIndex !== null && $score >= 1 && $score <= 5) {
                $ratings[$ratingIndex] = $score;
            }
        }
        
        return $ratings;
    }

    /**
     * Update answers for a submission
     */
    public function updateAnswers(Request $request, $submissionId)
    {
        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*' => 'nullable|integer|min:1|max:5',
            'term_id' => 'nullable|integer',
        ]);
        
        // Filter out null/empty answers early
        $answersToUpdate = array_filter($validated['answers'], function($score) {
            return $score !== null && $score !== '' && $score >= 1 && $score <= 5;
        });
        
        if (empty($answersToUpdate)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid answers to update'
            ], 422);
        }
        
        DB::connection('lnu_poes')->beginTransaction();
        
        try {
            $updatedCount = 0;
            $now = now();
            
            foreach ($answersToUpdate as $questionKey => $score) {
                // Use updateOrInsert which is the Query Builder equivalent of updateOrCreate
                DB::connection('lnu_poes')
                    ->table('student_evaluation_submission_answers')
                    ->updateOrInsert(
                        [
                            'submission_id' => $submissionId,
                            'question_key' => $questionKey
                        ],
                        [
                            'score' => (int) $score,
                            'updated_at' => $now,
                            'created_at' => DB::raw("COALESCE(created_at, '$now')")
                        ]
                    );
                $updatedCount++;
            }
            
            // Recalculate rating (optimized)
            $this->recalculateRatingOptimized($submissionId);
            
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
    
    /**
     * Get student answers with pagination for better performance
     */
    public function getStudentAnswers(Request $request, $studentIdNumber)
    {
        try {
            $termId = $request->get('term_id');
            $perPage = $request->get('per_page', 10);
            
            // Use pagination for large datasets
            $query = DB::connection('lnu_poes')
                ->table('student_evaluation_submissions')
                ->where('student_id_number', $studentIdNumber)
                ->orderBy('submitted_at', 'desc');
            
            if ($termId) {
                $query->where('term_id', $termId);
            }
            
            $submissions = $query->paginate($perPage);
            
            // Get all submission IDs
            $submissionIds = $submissions->pluck('id')->toArray();
            
            // Batch load all answers for these submissions in one query
            $allAnswers = PoesEvalAnswers::whereIn('submission_id', $submissionIds)
                ->get()
                ->groupBy('submission_id');
            
            // Get all unique question keys for batch text fetching
            $allQuestionKeys = $allAnswers->flatMap(function($answers) {
                return $answers->pluck('question_key');
            })->unique()->values()->toArray();
            
            $questionTexts = $this->getBulkQuestionTexts($allQuestionKeys);
            
            $results = [];
            foreach ($submissions as $submission) {
                $answersData = $allAnswers->get($submission->id, collect());
                
                $answers = [];
                foreach ($answersData as $answer) {
                    $answers[$answer->question_key] = [
                        'score' => $answer->score,
                        'question_text' => $questionTexts[$answer->question_key] ?? "Question {$answer->question_key}"
                    ];
                }
                
                $results[] = [
                    'submission_id' => $submission->id,
                    'subject_id' => $submission->subject_id,
                    'term_id' => $submission->term_id,
                    'rating_percentage' => $submission->rating_percentage,
                    'total_score' => $submission->total_score,
                    'max_score' => $submission->max_score,
                    'answers' => $answers
                ];
            }
            
            return response()->json([
                'success' => true,
                'submissions' => $results,
                'pagination' => [
                    'current_page' => $submissions->currentPage(),
                    'per_page' => $submissions->perPage(),
                    'total' => $submissions->total(),
                    'last_page' => $submissions->lastPage()
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch student answers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Optimized recalculation using raw query
     */
    private function recalculateRatingOptimized($submissionId)
    {
        // Use raw query for better performance
        $result = DB::connection('lnu_poes')
            ->table('student_evaluation_submission_answers')
            ->where('submission_id', $submissionId)
            ->select(
                DB::raw('SUM(score) as total_score'),
                DB::raw('COUNT(*) as total_questions'),
                DB::raw('AVG(score) as avg_score')
            )
            ->first();
        
        if (!$result || $result->total_questions == 0) {
            return null;
        }
        
        $totalScore = (int) $result->total_score;
        $totalQuestions = (int) $result->total_questions;
        $maxPossibleScore = $totalQuestions * 5;
        $percentage = round(($totalScore / $maxPossibleScore) * 100, 2);
        
        // Update in one query
        DB::connection('lnu_poes')
            ->table('student_evaluation_submissions')
            ->where('id', $submissionId)
            ->update([
                'rating_percentage' => $percentage,
                'total_score' => $totalScore,
                'max_score' => $maxPossibleScore,
                'updated_at' => now(),
            ]);
        
        return $percentage;
    }
    
    /**
     * Get question texts in batch with caching
     */
    private function getBulkQuestionTexts(array $questionKeys)
    {
        if (empty($questionKeys)) {
            return [];
        }
        
        $uniqueKeys = array_unique($questionKeys);
        $result = [];
        $uncachedKeys = [];
        
        // Check cache first
        foreach ($uniqueKeys as $key) {
            $cached = Cache::get("question_text_{$key}");
            if ($cached !== null) {
                $result[$key] = $cached;
            } else {
                $uncachedKeys[] = $key;
            }
        }
        
        if (!empty($uncachedKeys)) {
            // Try to fetch from database
            try {
                $dbQuestions = DB::connection('lnu_poes')
                    ->table('evaluation_questions')
                    ->whereIn('question_key', $uncachedKeys)
                    ->get()
                    ->keyBy('question_key');
                
                foreach ($uncachedKeys as $key) {
                    if (isset($dbQuestions[$key])) {
                        $text = $dbQuestions[$key]->question_text;
                        $result[$key] = $text;
                        Cache::put("question_text_{$key}", $text, 3600);
                    } else {
                        $text = $this->getDefaultQuestionText($key);
                        $result[$key] = $text;
                        Cache::put("question_text_{$key}", $text, 600);
                    }
                }
            } catch (\Exception $e) {
                // Table doesn't exist, use defaults for all
                foreach ($uncachedKeys as $key) {
                    $text = $this->getDefaultQuestionText($key);
                    $result[$key] = $text;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Get default question text based on key pattern
     */
    private function getDefaultQuestionText($questionKey)
    {
        $questions = [
            // Section A
            's0_i0' => 'Comes to class on time.',
            's0_i1' => 'Explains learning outcomes, expectations, grading system, and various requirements of the subject/course.',
            's0_i2' => 'Maximizes the allocated time/learning hours effectively.',
            's0_i3' => 'Facilitates students to think critically and creatively by providing appropriate learning activities.',
            's0_i4' => 'Guides students to learn on their own, reflect on new ideas and experiences, and make decisions in accomplishing given tasks.',
            's0_i5' => 'Communicates constructive feedback to students for their academic growth.',
            
            // Section B
            's1_i0' => 'Demonstrates extensive and broad knowledge of the subject/course.',
            's1_i1' => 'Simplifies complex ideas in the lesson for ease of understanding.',
            's1_i2' => 'Relates the subject matter to contemporary issues and developments in the discipline and/or daily life activities.',
            's1_i3' => 'Promotes active learning and student engagement by using appropriate teaching and learning resources including ICT tools and platforms.',
            's1_i4' => 'Uses appropriate assessments (projects, exams, quizzes, assignments, etc.) aligned with the learning outcomes.',
            
            // Section C
            's2_i0' => 'Recognizes and values the unique diversity and individual differences among students.',
            's2_i1' => 'Assists students with their learning challenges during consultation hours.',
            's2_i2' => 'Provides immediate feedback on student outputs and performance.',
            's2_i3' => 'Provides transparent and clear criteria in rating student\'s performance.'
        ];
        
        return $questions[$questionKey] ?? "Question {$questionKey}";
    }
    
    /**
     * Get question order from key
     */
    private function getQuestionOrder($questionKey)
    {
        if (preg_match('/s(\d+)_i(\d+)/', $questionKey, $matches)) {
            $section = (int) $matches[1];
            $index = (int) $matches[2];
            return ($section * 100) + $index;
        }
        
        $num = (int) str_replace('q', '', $questionKey);
        return $num;
    }
}