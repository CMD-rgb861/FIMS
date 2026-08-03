<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\SupervisorEvaluationSubmission;
use Illuminate\Support\Facades\Log;

class IFEController extends Controller
{
    /**
     * Get IFE data for a specific faculty
     * GET /ife/faculty/{facultyId}
     */
    public function getFacultyData($facultyId, Request $request)
    {
        $termId = $request->query('term_id');
        
        if (!$termId) {
            return response()->json([
                'success' => false,
                'message' => 'Term ID is required'
            ], 400);
        }

        try {
            // Get faculty information
            $facultyInfo = User::with(['college', 'unit'])
                ->where('id_no', $facultyId)
                ->first();

            if (!$facultyInfo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Faculty not found'
                ], 404);
            }

            // Get SET data
            $setData = $this->getSetData($facultyId, $termId);
            
            // Get SEF data
            $sefData = $this->getSefData($facultyId, $termId);
            
            // Get comments - FIXED
            $comments = $this->getComments($facultyId, $termId);

            // Get dean and associate dean names - FIXED
            $deanName = $this->getDeanName($facultyInfo);
            $associateDeanName = $this->getAssociateDeanName($facultyInfo);

            $response = [
                'success' => true,
                'faculty_info' => [
                    'id_no' => $facultyInfo->id_no,
                    'name' => trim(($facultyInfo->firstname ?? '') . ' ' . ($facultyInfo->lastname ?? '')),
                    'college' => $facultyInfo?->college?->name ?? 'N/A',
                    'academic_rank' => $facultyInfo->academic_rank ?? 'N/A',
                    'dean_name' => $deanName,
                    'associate_dean_name' => $associateDeanName,
                ],
                'set_data' => $setData,
                'sef_data' => $sefData,
                'comments' => $comments,
                'has_complete_data' => $setData['has_data'] && $sefData['has_data'],
            ];

            Log::info('IFE Data Response for faculty ' . $facultyId, [
                'has_complete_data' => $response['has_complete_data'],
                'student_comments_count' => count($comments['student'] ?? []),
                'supervisor_comments_count' => count($comments['supervisor'] ?? []),
            ]);

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Error fetching IFE data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch IFE data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get SET data for a faculty
     */
    private function getSetData($facultyId, $termId)
    {
        try {
            // ✅ FIX: Get SET data grouped by course_code only (combining all sections)
            // This properly combines 3-AI31 and 4-AI31 into one row per course
            $setRows = DB::connection('lnu_poes')
                ->table('enrollment_courses as ec')
                ->join('student_evaluation_submissions as ses', 'ec.id', '=', 'ses.subject_id')
                ->select(
                    'ec.course_code',
                    DB::raw("GROUP_CONCAT(DISTINCT ec.section_code ORDER BY ec.section_code SEPARATOR ', ') as year_section"),
                    DB::raw('COUNT(DISTINCT ses.student_id_number) as student_count'),
                    DB::raw('AVG(ses.rating_percentage) as avg_set_rating')
                )
                ->where('ec.id_no', $facultyId)
                ->where('ec.school_year_id', $termId)
                ->whereNotNull('ses.rating_percentage')
                ->groupBy('ec.course_code')
                ->orderBy('ec.course_code')
                ->get();

            $hasData = $setRows->isNotEmpty();
            
            $overallSetRating = null;
            $totalStudents = 0;
            $totalWeightedScore = 0;
            $totalAvgRating = 0;
            $rowCount = 0;

            $formattedRows = [];
            $seq = 1;
            
            foreach ($setRows as $row) {
                $studentCount = (int) $row->student_count;
                $avgRating = (float) $row->avg_set_rating;
                
                // Calculate weighted score for this row
                $weightedScore = $studentCount * $avgRating;
                
                $totalStudents += $studentCount;
                $totalWeightedScore += $weightedScore;
                $totalAvgRating += $avgRating;
                $rowCount++;
                
                $formattedRows[] = [
                    'seq' => $seq++,
                    'course_code' => $row->course_code ?? '',
                    'year_section' => $row->year_section ?? '',
                    'student_count' => $studentCount,
                    'avg_set_rating' => round($avgRating, 2),
                    'weighted_score' => round($weightedScore, 2),
                ];
            }

            // Calculate overall SET rating using weighted average
            if ($totalStudents > 0) {
                $overallSetRating = round($totalWeightedScore / $totalStudents, 2);
            }

            return [
                'has_data' => $hasData,
                'rows' => $formattedRows,
                'overall_rating' => $overallSetRating,
                'total_students' => $totalStudents,
                'total_weighted_score' => round($totalWeightedScore, 2),
                'simple_avg' => $rowCount > 0 ? round($totalAvgRating / $rowCount, 2) : null,
            ];

        } catch (\Exception $e) {
            Log::error('Error getting SET data: ' . $e->getMessage());
            return [
                'has_data' => false,
                'rows' => [],
                'overall_rating' => null,
                'total_students' => 0,
                'total_weighted_score' => 0,
                'simple_avg' => null,
            ];
        }
    }

    /**
     * Get SEF data for a faculty
     */
    private function getSefData($facultyId, $termId)
    {
        try {
            $submissions = SupervisorEvaluationSubmission::query()
                ->where('instructor_id_no', $facultyId)
                ->where('term_id', $termId)
                ->with(['answers' => function($q) {
                    $q->select('submission_id', 'question_key', 'score')
                    ->orderBy('question_key');
                }])
                ->get();

            $respondentCount = $submissions->count();
            $hasData = $respondentCount > 0;

            $overallSefRating = null;
            $ratingsBreakdown = array_fill(0, 15, null);

            Log::info('SEF Data for faculty ' . $facultyId . ' - Term ' . $termId, [
                'submissions_found' => $respondentCount,
                'has_data' => $hasData,
            ]);

            if ($hasData) {
                $totalPercentage = 0;
                foreach ($submissions as $submission) {
                    $totalPercentage += $submission->rating_percentage ?? 0;
                }
                $overallSefRating = round($totalPercentage / $respondentCount, 2);

                $ratings = array_fill(0, 15, 0);
                $ratingCounts = array_fill(0, 15, 0);

                foreach ($submissions as $submission) {
                    foreach ($submission->answers as $answer) {
                        $questionNum = $this->extractQuestionNumber($answer->question_key);
                        if ($questionNum >= 1 && $questionNum <= 15) {
                            $index = $questionNum - 1;
                            $ratings[$index] += $answer->score;
                            $ratingCounts[$index]++;
                        }
                    }
                }

                for ($i = 0; $i < 15; $i++) {
                    if ($ratingCounts[$i] > 0) {
                        $ratingsBreakdown[$i] = round($ratings[$i] / $ratingCounts[$i], 2);
                    }
                }

                $comments = $submissions
                    ->pluck('comments')
                    ->filter()
                    ->values()
                    ->toArray();

                Log::info('Supervisor Comments for faculty ' . $facultyId, [
                    'comment_count' => count($comments),
                ]);
            }

            return [
                'has_data' => $hasData,
                'overall_rating' => $overallSefRating,
                'total_evaluators' => $respondentCount,
                'ratings_breakdown' => $ratingsBreakdown,
                'comments' => $hasData ? $comments : [],
            ];

        } catch (\Exception $e) {
            Log::error('Error getting SEF data: ' . $e->getMessage());
            return [
                'has_data' => false,
                'overall_rating' => null,
                'total_evaluators' => 0,
                'ratings_breakdown' => array_fill(0, 15, null),
                'comments' => [],
            ];
        }
    }

    /**
     * Get comments for a faculty - FIXED column name from 'comments' to 'comment'
     */
    private function getComments($facultyId, $termId)
    {
        try {
            $studentComments = [];
            $supervisorComments = [];

            // ✅ FIX: The column name is 'comment' (singular), not 'comments' (plural)
            $studentCommentsRaw = DB::connection('lnu_poes')
                ->table('student_evaluation_submissions as ses')
                ->join('enrollment_courses as ec', 'ses.subject_id', '=', 'ec.id')
                ->select(
                    'ses.comment as comment',  // ✅ FIXED: 'comment' not 'comments'
                    'ses.submitted_at'
                )
                ->where('ec.id_no', $facultyId)
                ->where('ec.school_year_id', $termId)
                ->whereNotNull('ses.comment')  // ✅ FIXED: 'comment' not 'comments'
                ->where('ses.comment', '!=', '')  // ✅ FIXED: 'comment' not 'comments'
                ->where('ses.comment', 'NOT LIKE', '%N/A%')  // ✅ FIXED: 'comment' not 'comments'
                ->where('ses.comment', 'NOT LIKE', '%na%')  // ✅ FIXED: 'comment' not 'comments'
                ->orderBy('ses.submitted_at')
                ->get();

            Log::info('Student Comments Query for faculty ' . $facultyId . ' - Term ' . $termId, [
                'comments_found' => $studentCommentsRaw->count(),
            ]);

            $seq = 1;
            foreach ($studentCommentsRaw as $comment) {
                if (!empty(trim($comment->comment))) {
                    $studentComments[] = [
                        'seq' => $seq++,
                        'comment' => trim($comment->comment)
                    ];
                }
            }

            // Supervisor comments - keep as is (column name is correct)
            $supervisorCommentsRaw = SupervisorEvaluationSubmission::query()
                ->where('instructor_id_no', $facultyId)
                ->where('term_id', $termId)
                ->whereNotNull('comments')
                ->where('comments', '!=', '')
                ->where('comments', 'NOT LIKE', '%N/A%')
                ->where('comments', 'NOT LIKE', '%na%')
                ->select('comments')
                ->orderBy('created_at')
                ->get();

            Log::info('Supervisor Comments Query for faculty ' . $facultyId . ' - Term ' . $termId, [
                'comments_found' => $supervisorCommentsRaw->count(),
            ]);

            $seq = 1;
            foreach ($supervisorCommentsRaw as $comment) {
                if (!empty(trim($comment->comments))) {
                    $supervisorComments[] = [
                        'seq' => $seq++,
                        'comment' => trim($comment->comments)
                    ];
                }
            }

            if (empty($studentComments)) {
                $studentComments[] = [
                    'seq' => '',
                    'comment' => 'No student comments available'
                ];
            }

            if (empty($supervisorComments)) {
                $supervisorComments[] = [
                    'seq' => '',
                    'comment' => 'No supervisor comments available'
                ];
            }

            return [
                'student' => $studentComments,
                'supervisor' => $supervisorComments,
            ];

        } catch (\Exception $e) {
            Log::error('Error getting comments: ' . $e->getMessage());
            return [
                'student' => [['seq' => '', 'comment' => 'No student comments available']],
                'supervisor' => [['seq' => '', 'comment' => 'No supervisor comments available']],
            ];
        }
    }

    /**
     * Get dean name from faculty's college - FIXED to handle missing table
     */
    private function getDeanName($faculty)
    {
        try {
            if (!$faculty || !$faculty->college_id) {
                return '';
            }

            // Try to get from the main database (not lnu_poes)
            // The users table might be in the main database
            $dean = DB::table('college_deans as cd')
                ->join('users as u', 'cd.user_id', '=', 'u.id')
                ->where('cd.college_id', $faculty->college_id)
                ->where('cd.is_active', 1)
                ->select('u.firstname', 'u.lastname')
                ->first();

            if ($dean) {
                return trim(($dean->firstname ?? '') . ' ' . ($dean->lastname ?? ''));
            }

            return '';

        } catch (\Exception $e) {
            Log::warning('Dean name not found (table may not exist): ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Get associate dean name from faculty's college - FIXED to handle missing table
     */
    private function getAssociateDeanName($faculty)
    {
        try {
            if (!$faculty || !$faculty->college_id) {
                return '';
            }

            // Try to get from the main database (not lnu_poes)
            $associateDean = DB::table('college_deans as cd')
                ->join('users as u', 'cd.user_id', '=', 'u.id')
                ->where('cd.college_id', $faculty->college_id)
                ->where('cd.is_active', 1)
                ->where('cd.role', 'associate_dean')
                ->select('u.firstname', 'u.lastname')
                ->first();

            if ($associateDean) {
                return trim(($associateDean->firstname ?? '') . ' ' . ($associateDean->lastname ?? ''));
            }

            return '';

        } catch (\Exception $e) {
            Log::warning('Associate dean name not found (table may not exist): ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Extract question number from various question key formats
     */
    private function extractQuestionNumber($questionKey)
    {
        if (preg_match('/^q(\d+)$/i', $questionKey, $matches)) {
            return (int) $matches[1];
        }
        
        if (preg_match('/(?:benchmark|question)\s*(\d+)/i', $questionKey, $matches)) {
            return (int) $matches[1];
        }
        
        if (is_numeric($questionKey)) {
            return (int) $questionKey;
        }
        
        if (preg_match('/(\d+)/', $questionKey, $matches)) {
            return (int) $matches[1];
        }
        
        return null;
    }

    /**
     * Get batch IFE data for multiple faculty
     * POST /ife/batch
     */
    public function batch(Request $request)
    {
        $validated = $request->validate([
            'term_id' => 'required',
            'faculty_ids' => 'required|array',
            'faculty_ids.*' => 'required'
        ]);

        $termId = $validated['term_id'];
        $facultyIds = $validated['faculty_ids'];

        $results = [];

        foreach ($facultyIds as $facultyId) {
            $facultyData = $this->getFacultyDataInternal($facultyId, $termId);
            $results[$facultyId] = $facultyData;
        }

        return response()->json([
            'success' => true,
            'data' => $results
        ]);
    }

    /**
     * Internal method to get faculty data (used by batch)
     */
    private function getFacultyDataInternal($facultyId, $termId)
    {
        try {
            $facultyInfo = User::with(['college', 'unit'])
                ->where('id_no', $facultyId)
                ->first();

            if (!$facultyInfo) {
                return [
                    'success' => false,
                    'message' => 'Faculty not found'
                ];
            }

            $setData = $this->getSetData($facultyId, $termId);
            $sefData = $this->getSefData($facultyId, $termId);
            $comments = $this->getComments($facultyId, $termId);

            return [
                'success' => true,
                'faculty_info' => [
                    'id_no' => $facultyInfo->id_no,
                    'name' => trim(($facultyInfo->firstname ?? '') . ' ' . ($facultyInfo->lastname ?? '')),
                    'college' => $facultyInfo?->college?->name ?? 'N/A',
                    'academic_rank' => $facultyInfo->academic_rank ?? 'N/A',
                ],
                'set_data' => $setData,
                'sef_data' => $sefData,
                'comments' => $comments,
                'has_complete_data' => $setData['has_data'] && $sefData['has_data'],
            ];

        } catch (\Exception $e) {
            Log::error('Error getting faculty data: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get summary data for all faculty
     * GET /ife/summary
     */
    public function summary(Request $request)
    {
        $termId = $request->query('term_id');
        
        if (!$termId) {
            return response()->json([
                'success' => false,
                'message' => 'Term ID is required'
            ], 400);
        }

        try {
            $facultyList = User::whereHas('evaluations', function($query) use ($termId) {
                $query->where('term_id', $termId);
            })->get();

            $summary = [];
            foreach ($facultyList as $faculty) {
                $setData = $this->getSetData($faculty->id_no, $termId);
                $sefData = $this->getSefData($faculty->id_no, $termId);
                
                $summary[] = [
                    'employee_id_no' => $faculty->id_no,
                    'instructor' => trim(($faculty->firstname ?? '') . ' ' . ($faculty->lastname ?? '')),
                    'overall_set_rating' => $setData['overall_rating'],
                    'overall_sef_rating' => $sefData['overall_rating'],
                    'has_complete_data' => $setData['has_data'] && $sefData['has_data'],
                    'has_set_data' => $setData['has_data'],
                    'has_sef_data' => $sefData['has_data'],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting IFE summary: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get IFE summary'
            ], 500);
        }
    }
}