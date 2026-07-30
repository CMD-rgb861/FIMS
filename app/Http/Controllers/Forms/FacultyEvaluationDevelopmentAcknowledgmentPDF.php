<?php

namespace App\Http\Controllers\Forms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\SupervisorEvaluationSubmission;
use App\Models\User;
use TCPDF;

class FacultyEvaluationDevelopmentAcknowledgmentPDF extends Controller
{
    /**
     * Generate FEDA PDF (supports both single and batch generation)
     * POST /feda/pdf/generate
     */
    public function generate(Request $request, $id = null)
    {
        // Increase execution limits for batch generation
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        
        // Get the logged-in user (program head/supervisor)
        $loggedInUser = auth()->user();
        $programHeadName = '';
        $evaluatorNameDisplay = 'Supervisor';
        
        if ($loggedInUser) {
            $evaluatorNameDisplay = trim(($loggedInUser->firstname ?? '') . ' ' . ($loggedInUser->lastname ?? ''));
            if (empty($evaluatorNameDisplay)) {
                $evaluatorNameDisplay = $loggedInUser->name ?? 'Supervisor';
            }
            
            // Check if the logged-in user is a unit head
            $isUnitHead = method_exists($loggedInUser, 'isUnitHead') && $loggedInUser->isUnitHead();
            if (!$isUnitHead) {
                $isUnitHead = in_array($loggedInUser->role ?? '', ['unit_head', 'department_head', 'program_head']);
            }
            
            if ($isUnitHead) {
                $programHeadName = $evaluatorNameDisplay;
            } else {
                // If not a unit head, still use the user's name but as evaluator
                $programHeadName = $evaluatorNameDisplay;
            }
        }
        
        if ($request->isMethod('get') || !$request->has('faculty_list')) {
            $targetId = $id ?? $request->query('id') ?? auth()->id();
            
            $user = null;
            if ($targetId) {
                $user = User::where('id', $targetId)
                    ->orWhere('id_no', $targetId)
                    ->first();
            }
            if (!$user) {
                $user = auth()->user();
            }

            $employeeIdNo = $user?->id_no ?? (string)($targetId ?? 1);
            $instructorName = $user ? trim(implode(' ', array_filter([$user->firstname, $user->lastname]))) : 'Faculty Member';
            if (empty($instructorName)) {
                $instructorName = $user?->name ?? 'Faculty Member';
            }

            $termId = (string) ($request->query('term_id') ?? '1');
            $schoolYearLabel = $request->query('school_year_label') ?? '';

            // Calculate overall SET and SEF ratings
            $overallSetRating = $this->getFacultyOverallSetRating($employeeIdNo, $instructorName, (int) $termId);
            $overallSefRating = $this->getFacultyOverallSefRating($employeeIdNo, (int) $termId);

            $facultyList = [[
                'employee_id_no' => $employeeIdNo,
                'instructor' => $instructorName,
                'ratings_breakdown' => null,
                'comments' => '',
                'overall_set_rating' => $overallSetRating,
                'overall_sef_rating' => $overallSefRating,
            ]];
        } else {
            // Validate request
            $validated = $request->validate([
                'term_id' => 'required|string',
                'faculty_list' => 'required|array',
                'faculty_list.*.employee_id_no' => 'required',
                'faculty_list.*.instructor' => 'required|string',
                'faculty_list.*.ratings_breakdown' => 'nullable|array',
                'faculty_list.*.areas_for_improvement' => 'nullable|string',
                'faculty_list.*.proposed_activities' => 'nullable|string',
                'faculty_list.*.action_plan' => 'nullable|string',
                'school_year_label' => 'nullable|string'
            ]);
            
            $termId = $validated['term_id'];
            $facultyList = $validated['faculty_list'];
            $schoolYearLabel = $validated['school_year_label'] ?? '';
        }
        
        // Register Times New Roman fonts
        $this->registerFonts();
        
        // Create PDF document
        $pdf = new TCPDF('P', 'mm', 'LEGAL', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(13, 26, 13);
        $pdf->SetAutoPageBreak(false, 26);
        $pdf->SetCompression(true);
        
        // Get term details
        $termDetails = $this->getTermDetails($termId);
        
        // Generate page for each faculty
        $totalPages = 0;
        
        foreach ($facultyList as $faculty) {
            // Check if ratings_breakdown is provided from frontend (batch mode)
            if (isset($faculty['ratings_breakdown']) && is_array($faculty['ratings_breakdown']) && count($faculty['ratings_breakdown']) === 15) {
                // Use ratings from frontend (already calculated)
                $ratings = $faculty['ratings_breakdown'];
            } else {
                // Fetch SEF data for this faculty (legacy mode)
                $ratings = $this->getFacultyRatings($faculty['employee_id_no'], $termId);
            }
            
            // Get faculty college, department, and academic rank from database
            $facultyInfo = User::with(['college','unit'])
                ->where('id_no', $faculty['employee_id_no'])
                ->first();

            $collegeName = $facultyInfo?->college?->name ?? '';
            $departmentName = $facultyInfo?->unit?->name ?? '';
            $academicRank = $facultyInfo?->academic_rank ?? '';

            // Combine college and department (if both exist)
            $collegeDepartment = collect([
                $departmentName,
                $collegeName
            ])->filter()->implode(' / ');
            
            // If still empty, fallback to what was provided from frontend
            if (empty($collegeDepartment)) {
                $collegeDepartment = $faculty['department'] ?? $faculty['college'] ?? 'College of Arts and Sciences';
            }
            
            // Determine comments: prefer frontend-provided, otherwise fetch from submissions
            if (!empty($faculty['comments'])) {
                $comments = $faculty['comments'];
            } else {
                $comments = $this->getFacultyComments($faculty['employee_id_no'], $termId);
            }

            // Get development plan data from request or database
            $developmentPlan = [];

            // First, check if it's in the faculty array (batch mode)
            if (isset($faculty['areas_for_improvement']) || isset($faculty['proposed_activities']) || isset($faculty['action_plan'])) {
                $developmentPlan = [
                    'areas_for_improvement' => $faculty['areas_for_improvement'] ?? '',
                    'proposed_activities' => $faculty['proposed_activities'] ?? '',
                    'action_plan' => $faculty['action_plan'] ?? ''
                ];
            } else {
                // Check request query (single mode)
                $developmentPlan = [
                    'areas_for_improvement' => $request->query('areas_for_improvement', ''),
                    'proposed_activities' => $request->query('proposed_activities', ''),
                    'action_plan' => $request->query('action_plan', '')
                ];
            }

            // If still empty, try to get from database
            if (empty($developmentPlan['areas_for_improvement']) && 
                empty($developmentPlan['proposed_activities']) && 
                empty($developmentPlan['action_plan'])) {
                $facultyIdNo = $faculty['employee_id_no'] ?? null;
                if ($facultyIdNo) {
                    $fedaForm = \App\Models\FacultyDevelopmentForm::where('id_no', $facultyIdNo)
                        ->where('term_id', $termId)
                        ->first();
                    
                    if ($fedaForm) {
                        $developmentPlan = [
                            'areas_for_improvement' => $fedaForm->areas_for_improvement ?? '',
                            'proposed_activities' => $fedaForm->proposed_learning_and_development_activities ?? '',
                            'action_plan' => $fedaForm->action_plan ?? ''
                        ];
                    }
                }
            }

            // Get overall ratings from faculty data or calculate
            $overallSetRating = $faculty['overall_set_rating'] ?? $this->getFacultyOverallSetRating(
                $faculty['employee_id_no'], 
                $faculty['instructor'] ?? '', 
                (int) $termId
            );
            $overallSefRating = $faculty['overall_sef_rating'] ?? $this->getFacultyOverallSefRating(
                $faculty['employee_id_no'], 
                (int) $termId
            );

            // Prepare data for the template
            $data = [
                'faculty_name' => $faculty['instructor'] ?? 'Faculty Member',
                'faculty_id_no' => $faculty['employee_id_no'] ?? '',
                'college' => $collegeDepartment,
                'academic_rank' => $academicRank,
                'course_code' => $faculty['course_code'] ?? '',
                'course_title' => $faculty['course_title'] ?? '',
                'program_level' => $faculty['program_level'] ?? '',
                'semester' => $termId,
                'academic_year' => $termDetails['academic_year_display'],
                'ratings' => $ratings,
                'comments' => $comments,
                'evaluator_name' => $faculty['evaluator_name'] ?? 'Supervisor',
                'evaluator_id' => $faculty['evaluator_id'] ?? '',
                'date' => date('F j, Y'),
                'development_plan' => $developmentPlan,
                // Add program head/supervisor name
                'program_head_name' => $programHeadName,
                // Add evaluator name (logged-in user)
                'evaluator_name_display' => $evaluatorNameDisplay,
                // Add overall SET and SEF ratings
                'overall_set_rating' => $overallSetRating !== null ? number_format($overallSetRating, 2) : 'N/A',
                'overall_sef_rating' => $overallSefRating !== null ? number_format($overallSefRating, 2) : 'N/A',
            ];
            
            // Add page and generate form
            $pdf->AddPage();
            $this->addWatermark($pdf);
            $this->generateFEDAForm($pdf, $data, 13, 26, $termDetails);
            $totalPages++;
        }
        
        // Generate PDF output
        $pdfOutput = $pdf->Output('', 'S');
        
        if ($request->isMethod('get') || !$request->has('faculty_list')) {
            return response()->make($pdfOutput, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="feda_form.pdf"'
            ]);
        }
        
        // Generate filename
        $filename = 'feda_report_' . time() . '.pdf';
        $filepath = 'temp/pdf/' . $filename;
        Storage::disk('local')->put($filepath, $pdfOutput);
        
        // Get file size
        $fileSize = Storage::disk('local')->size($filepath);
        
        return response()->json([
            'success' => true,
            'pdf_url' => route('pdf.display', ['filename' => $filename]),
            'message' => $totalPages > 1 
                ? "FEDA reports generated successfully for {$totalPages} faculty members"
                : "FEDA report generated successfully",
            'total_pages' => $totalPages,
            'file_size_kb' => round($fileSize / 1024, 2)
        ]);
    }

    /**
     * Get faculty overall SET rating
     */
    public function getFacultyOverallSetRating(?string $idNo, ?string $instructor = null, ?int $termId = null): ?float
    {
        $normalizedIdNo = trim((string) ($idNo ?? ''));
        $normalizedInstructor = trim((string) ($instructor ?? ''));

        if ($normalizedIdNo === '' && $normalizedInstructor === '') {
            return null;
        }

        try {
            // Get all subjects and their submissions for this instructor
            $query = DB::connection('lnu_poes')
                ->table('enrollment_courses as ec')
                ->join('student_evaluation_submissions as ses', 'ec.id', '=', 'ses.subject_id')
                ->select('ec.course_code', 'ec.section_code')
                ->selectRaw('COUNT(DISTINCT ses.student_id_number) as student_count')
                ->selectRaw('AVG(ses.rating_percentage) as avg_rating')
                ->whereNotNull('ses.rating_percentage')
                ->groupBy('ec.course_code', 'ec.section_code');

            // Apply instructor filter using id_no first for strict matching.
            if ($normalizedIdNo !== '') {
                $query->where('ec.id_no', $normalizedIdNo);
            } elseif ($normalizedInstructor !== '') {
                $tokens = preg_split('/[^\pL\pN]+/u', mb_strtoupper($normalizedInstructor)) ?: [];
                $tokens = array_values(array_filter($tokens, fn($token) => mb_strlen($token) > 1));

                foreach ($tokens as $token) {
                    $query->where('ec.instructor', 'like', '%' . $token . '%');
                }
            }

            // Apply term filter
            if ($termId !== null && $termId !== '' && $termId !== 'all') {
                $query->where('ec.school_year_id', $termId);
                $query->where('ses.term_id', $termId);
            }

            $subjects = $query->get();

            if ($subjects->isEmpty()) {
                return null;
            }

            // Calculate weighted average
            $totalWeightedScore = 0;
            $totalStudents = 0;

            foreach ($subjects as $subject) {
                $studentCount = (int) $subject->student_count;
                $avgRating = (float) $subject->avg_rating;
                
                $totalWeightedScore += $studentCount * $avgRating;
                $totalStudents += $studentCount;
            }

            if ($totalStudents === 0) {
                return null;
            }

            return round($totalWeightedScore / $totalStudents, 2);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error getting SET rating: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get faculty overall SEF rating
     */
    public function getFacultyOverallSefRating(?string $idNo, ?int $termId = null): ?float
    {
        if (empty($idNo)) {
            return null;
        }

        try {
            $query = SupervisorEvaluationSubmission::query()
                ->where('instructor_id_no', $idNo);

            if ($termId !== null && $termId !== '' && $termId !== 'all') {
                $query->where('term_id', $termId);
            }

            $avg = $query->avg('rating_percentage');
            return $avg !== null ? round((float) $avg, 2) : null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error getting SEF rating: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get development plan for a faculty member
     */
    private function getDevelopmentPlan($facultyId, $termId)
    {
        $plan = DB::connection('lnu_poes')
            ->table('development_plans')
            ->where('faculty_id_no', $facultyId)
            ->where('term_id', $termId)
            ->first();
        
        if ($plan) {
            return [
                'areas_for_improvement' => $plan->areas_for_improvement ?? '',
                'proposed_activities' => $plan->proposed_activities ?? '',
                'action_plan' => $plan->action_plan ?? ''
            ];
        }
        
        return [
            'areas_for_improvement' => '',
            'proposed_activities' => '',
            'action_plan' => ''
        ];
    }

    /**
     * Batch fetch FEDA data for multiple faculty
     * POST /feda/batch-reports
     */
    public function batchReports(Request $request)
    {
        set_time_limit(120);
        
        $validated = $request->validate([
            'term_id' => 'required',
            'faculty_ids' => 'required|array',
            'faculty_ids.*' => 'required'
        ]);
        
        $termId = (string) $validated['term_id'];
        $facultyIds = array_map('strval', $validated['faculty_ids']);
        
        $results = [];
        
        $allSubmissions = SupervisorEvaluationSubmission::query()
            ->whereIn('instructor_id_no', $facultyIds)
            ->where('term_id', $termId)
            ->with(['answers' => function($q) {
                $q->select('submission_id', 'question_key', 'score')
                ->orderBy('question_key');
            }])
            ->get()
            ->groupBy('instructor_id_no');
        
        foreach ($facultyIds as $facultyId) {
            $submissions = $allSubmissions->get($facultyId, collect());
            $respondentCount = $submissions->count();
            
            if ($respondentCount === 0) {
                $results[$facultyId] = [
                    'has_data' => false,
                    'overall_sef_rating' => null,
                    'total_evaluators' => 0,
                    'details' => null
                ];
                continue;
            }
            
            $totalPercentage = 0;
            foreach ($submissions as $submission) {
                $totalPercentage += $submission->rating_percentage ?? 0;
            }
            
            $overallPercentage = round($totalPercentage / $respondentCount, 2);
            
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
                    $ratings[$i] = round($ratings[$i] / $ratingCounts[$i], 2);
                } else {
                    $ratings[$i] = null;
                }
            }
            
            $totalScore = array_sum(array_filter($ratings));
            $maxPossibleScore = 15 * 5;
            $calculatedPercentage = $maxPossibleScore > 0 ? round(($totalScore / $maxPossibleScore) * 100, 2) : 0;
            
            $comments = $submissions
                ->pluck('comments')
                ->filter()
                ->implode("\n");

            $developmentPlan = $this->getDevelopmentPlan($facultyId, $termId);

            $results[$facultyId] = [
                'has_data' => true,
                'overall_sef_rating' => $overallPercentage ?: $calculatedPercentage,
                'total_evaluators' => $respondentCount,
                'overall_average' => $overallPercentage ? round($overallPercentage / 20, 2) : round($calculatedPercentage / 20, 2),
                'ratings_breakdown' => $ratings,
                'comments' => $comments,
                'total_score' => $totalScore,
                'max_score' => $maxPossibleScore,
                'development_plan' => $developmentPlan,
                'details' => [
                    'ratings_breakdown' => $ratings,
                    'total_score' => $totalScore,
                    'percentage' => $overallPercentage ?: $calculatedPercentage,
                    'respondent_count' => $respondentCount
                ]
            ];
        }
        
        return response()->json($results);
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
     * Get SEF data for a specific faculty (for modal display)
     */
    public function getFacultySefData($facultyId, Request $request)
    {
        $termId = $request->query('term_id');
        
        if (!$termId) {
            return response()->json([
                'success' => false,
                'has_data' => false,
                'overall_sef_rating' => null,
                'total_evaluators' => 0,
                'message' => 'Term ID is required'
            ]);
        }
        
        $submissions = SupervisorEvaluationSubmission::query()
            ->where('instructor_id_no', $facultyId)
            ->where('term_id', $termId)
            ->with(['answers' => function($q) {
                $q->select('submission_id', 'question_key', 'score')
                ->orderBy('question_key');
            }])
            ->get();
        
        $respondentCount = $submissions->count();
        
        if ($respondentCount === 0) {
            return response()->json([
                'success' => true,
                'has_data' => false,
                'overall_sef_rating' => null,
                'total_evaluators' => 0,
                'message' => 'No SEF data found for this faculty'
            ]);
        }
        
        $totalPercentage = 0;
        foreach ($submissions as $submission) {
            $totalPercentage += $submission->rating_percentage ?? 0;
        }
        
        $overallPercentage = $respondentCount > 0 ? round($totalPercentage / $respondentCount, 2) : null;
        
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
                $ratings[$i] = round($ratings[$i] / $ratingCounts[$i], 2);
            } else {
                $ratings[$i] = null;
            }
        }
        
        return response()->json([
            'success' => true,
            'has_data' => true,
            'overall_sef_rating' => $overallPercentage,
            'total_evaluators' => $respondentCount,
            'overall_average' => $overallPercentage ? round($overallPercentage / 20, 2) : null,
            'ratings_breakdown' => $ratings,
        ]);
    }
    
    /**
     * Get faculty ratings from SEF submissions (returns 15 ratings)
     */
    private function getFacultyRatings($facultyId, $termId)
    {
        $submissions = SupervisorEvaluationSubmission::query()
            ->where('instructor_id_no', $facultyId)
            ->where('term_id', $termId)
            ->with('answers')
            ->get();
        
        $respondentCount = $submissions->count();
        
        if ($respondentCount === 0) {
            return array_fill(0, 15, 4);
        }
        
        $averages = array_fill(0, 15, 0);
        
        foreach ($submissions as $submission) {
            foreach ($submission->answers as $answer) {
                $questionNum = (int) str_replace('q', '', $answer->question_key);
                $index = $questionNum - 1;
                if ($index >= 0 && $index < 15) {
                    $averages[$index] += $answer->score;
                }
            }
        }
        
        for ($i = 0; $i < 15; $i++) {
            $averages[$i] = round($averages[$i] / $respondentCount, 2);
        }
        
        return $averages;
    }

    /**
     * Get concatenated comments for a faculty from submissions
     */
    private function getFacultyComments($facultyId, $termId)
    {
        $comments = SupervisorEvaluationSubmission::query()
            ->where('instructor_id_no', $facultyId)
            ->where('term_id', $termId)
            ->pluck('comments')
            ->filter()
            ->map(function ($c) { return trim($c); })
            ->filter()
            ->unique()
            ->implode("\n\n");

        return $comments;
    }
    
    /**
     * Get term details
     */
    private function getTermDetails($termId)
    {
        $semesterDisplay = '';
        $academicYearDisplay = '';
        
        if ($termId && $termId !== 'null' && $termId !== 'undefined') {
            $termData = DB::connection('lnu_poes')
                ->table('school_years')
                ->where('id', $termId)
                ->first();
                
            if ($termData) {
                switch ($termData->semester) {
                    case 1:
                        $semesterDisplay = '1st Semester';
                        break;
                    case 2:
                        $semesterDisplay = '2nd Semester';
                        break;
                    case 3:
                        $semesterDisplay = 'Summer';
                        break;
                    default:
                        $semesterDisplay = 'Semester ' . $termData->semester;
                }
                $academicYearDisplay = $termData->school_year_from . '-' . $termData->school_year_to;
            }
        }
        
        if (empty($semesterDisplay)) {
            $semesterDisplay = $termId ?? 'Current Semester';
            $academicYearDisplay = date('Y') . '-' . (date('Y') + 1);
        }
        
        return [
            'semester_display' => $semesterDisplay,
            'academic_year_display' => $academicYearDisplay
        ];
    }
    
    /**
     * Check if there's enough space for content and add page break if needed
     */
    private function checkPageBreak($pdf, $needed_height, $bottom_margin = 25.4, $add_watermark = true, $start_y = 15)
    {
        $page_height = $pdf->getPageHeight();
        $current_y = $pdf->GetY();
        $max_y = $page_height - $bottom_margin;
        
        if (($current_y + $needed_height) > $max_y) {
            $pdf->AddPage();
            if ($add_watermark) {
                $this->addWatermark($pdf);
            }
            $pdf->SetY($start_y);
            return true;
        }
        return false;
    }

    /**
     * Calculate the height needed for a table row
     */
    private function calculateRowHeight($pdf, $row, $col_widths, $cell_width_padding = 2, $line_height = 4)
    {
        $max_height = 5;
        
        foreach ($row as $i => $cell) {
            $available_width = $col_widths[$i] - $cell_width_padding;
            $lines = $this->wrapText($pdf, (string)$cell, $available_width);
            $line_count = count($lines);
            $height_needed = max(5, $line_count * $line_height + 1);
            $max_height = max($max_height, $height_needed);
        }
        
        return $max_height + 2;
    }

    /**
     * Calculate total height needed for a table
     */
    private function calculateTableHeight($pdf, $data_rows, $col_widths, $has_header = true, $has_total_row = true)
    {
        $total_height = 0;
        $header_height = 6;
        $row_height = 5;
        $padding = 2;
        
        if ($has_header) {
            $total_height += $header_height;
        }
        
        foreach ($data_rows as $row) {
            $total_height += $this->calculateRowHeight($pdf, $row, $col_widths);
        }
        
        if ($has_total_row) {
            $total_height += $row_height + $padding;
        }
        
        $total_height += 5;
        
        return $total_height;
    }

    /**
     * Wrap text function
     */
    private function wrapText($pdf, $text, $maxWidth) {
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';
        
        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            $testWidth = $pdf->getStringWidth($testLine);
            
            if ($testWidth > $maxWidth) {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                    $currentLine = $word;
                } else {
                    $lines[] = $word;
                    $currentLine = '';
                }
            } else {
                $currentLine = $testLine;
            }
        }
        
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }
        
        return $lines;
    }

    /**
     * Generate the FEDA form (Faculty Evaluation and Development Acknowledgment)
     */
    public function generateFEDAForm($pdf, $data, $x_offset, $y_offset, $termDetails)
    {
        $body_font_size = 11;
        $title_font_size = 11;
        $header_font_size = 11;
        $row_height = 5;
        $bottom_margin = 15;
        $page_break_start_y = 40;
        
        $total_table_width = 192;
        
        // Apply Y offset
        $current_y = $pdf->GetY();
        $pdf->SetY($current_y + ($y_offset / 2));
        $pdf->SetFont('times', '', $body_font_size);
        
        $section_indent = 4.5;
        $current_x = $x_offset + $section_indent;
        
        // ============================================
        // TITLE
        // ============================================
        $pdf->Ln(2);
        $pdf->SetX($x_offset);
        $pdf->SetFont('times', 'B', $title_font_size);
        $pdf->Cell(0, 8, 'FACULTY EVALUATION AND DEVELOPMENT ACKNOWLEDGMENT FORM', 0, 1, 'C');
        $pdf->Ln(3);
        
        // ============================================
        // SECTION A: Faculty Information
        // ============================================
        $section_ab_x_offset = $x_offset + 17;
        
        // Check space before Section A
        $this->checkPageBreak($pdf, 60, $bottom_margin, true, $page_break_start_y);
        
        $pdf->SetX($section_ab_x_offset);
        $pdf->SetFont('times', 'B', $header_font_size);
        $pdf->Cell(0, 6, 'A. FACULTY MEMBER INFORMATION', 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        $indent = 5;
        
        // Name of Faculty
        $pdf->SetX($section_ab_x_offset + $indent);
        $label_text = 'Name of Faculty:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', 'U', $body_font_size);
        $pdf->Cell(0, $row_height, ' ' . $data['faculty_name'], 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        // College/Department
        $pdf->SetX($section_ab_x_offset + $indent);
        $label_text = 'Department/College:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', 'U', $body_font_size);
        $pdf->Cell(0, $row_height, ' ' . $data['college'], 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        // Current Faculty Rank
        $pdf->SetX($section_ab_x_offset + $indent);
        $label_text = 'Current Faculty Rank:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', 'U', $body_font_size);
        $pdf->Cell(0, $row_height, ' ' . ($data['academic_rank'] ?? 'N/A'), 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        // Semester or Term/Academic Year
        $pdf->SetX($section_ab_x_offset + $indent);
        $label_text = 'Semester or Term/Academic Year:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', 'U', $body_font_size);
        $pdf->Cell(0, $row_height, ' ' . $termDetails['semester_display'] . ' - S.Y. ' . $termDetails['academic_year_display'], 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        $pdf->Ln(1.5);
        
        // ============================================
        // SECTION B: FACULTY EVALUATION SUMMARY
        // ============================================
        
        // Check space before Section B
        $this->checkPageBreak($pdf, 50, $bottom_margin, true, $page_break_start_y);
        
        $pdf->SetX($section_ab_x_offset);
        $pdf->SetFont('times', 'B', $header_font_size);
        $pdf->Cell(0, 6, 'B. FACULTY EVALUATION SUMMARY', 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Ln(2);
        
        // ============================================
        // TABLE: Overall Rating Summary
        // ============================================
        
        $benchmark_total_width = 165;
        
        $col_widths = [
            85,
            82
        ];
        
        $current_total = array_sum($col_widths);
        $scale_factor = $benchmark_total_width / $current_total;
        
        foreach ($col_widths as &$width) {
            $width = round($width * $scale_factor);
        }
        
        $total_width = array_sum($col_widths);
        
        // Check if we need a page break for the table
        $this->checkPageBreak($pdf, 30, $bottom_margin, true, $page_break_start_y);
        
        $table_x_offset = $x_offset + 15;
        $pdf->SetX($table_x_offset);
        $current_x = $table_x_offset;
        
        // ROW 1: Overall Rating (Merged Cell) - Transparent Background
        $pdf->SetFont('times', 'B', $body_font_size);
        $row_height = 6;
        $start_x = $pdf->GetX();
        $start_y = $pdf->GetY();
        $pdf->SetXY($start_x, $start_y);
        $pdf->Cell($total_width, $row_height, 'Overall Rating', 1, 1, 'C', false);
        $pdf->SetY($start_y + $row_height);
        $pdf->SetX($current_x);
        
        // ROW 2: Headers
        $headers = [
            'Student Evaluation of Teachers (SET)',
            "Supervisor's Evaluation of Faculty (SEF)"
        ];
        
        $pdf->SetFont('times', 'B', $body_font_size);
        $pdf->SetFillColor(200, 200, 200);
        $header_max_height = 5;
        $header_lines_data = [];
        $cell_width_padding = 2;
        $cell_height_padding = 0.5;
        
        foreach ($headers as $i => $text) {
            $available_width = $col_widths[$i] - $cell_width_padding;
            $lines = $this->wrapText($pdf, $text, $available_width);
            $header_lines_data[] = $lines;
            $line_count = count($lines);
            $height_needed = max(4, $line_count * 3.5 + 0.5);
            $header_max_height = max($header_max_height, $height_needed);
        }
        
        $header_max_height += $cell_height_padding;
        $start_x = $pdf->GetX();
        $start_y = $pdf->GetY();
        $current_x_pos = $start_x;
        
        foreach ($headers as $i => $text) {
            $pdf->SetXY($current_x_pos, $start_y);
            $pdf->Cell($col_widths[$i], $header_max_height, '', 1, 0, 'L', true);
            $lines = $header_lines_data[$i];
            $line_height = 3.5;
            $total_text_height = count($lines) * $line_height;
            $start_text_y = $start_y + ($header_max_height - $total_text_height) / 2;
            $pdf->SetFont('times', 'B', $body_font_size);
            foreach ($lines as $line_index => $line) {
                $y_pos = $start_text_y + ($line_index * $line_height);
                $pdf->SetXY($current_x_pos + 1, $y_pos);
                $pdf->Cell($col_widths[$i] - 2, $line_height, trim($line), 0, 0, 'L');
            }
            $current_x_pos += $col_widths[$i];
        }
        
        $pdf->SetY($start_y + $header_max_height);
        $pdf->SetX($current_x);
        
        // ROW 3: Data Row - Now using actual ratings from $data
        $pdf->SetFont('times', '', $body_font_size);
        $fill = false;
        
        // Get overall ratings from data
        $setRating = $data['overall_set_rating'] ?? 'N/A';
        $sefRating = $data['overall_sef_rating'] ?? 'N/A';
        
        $data_row = [
            $setRating,
            $sefRating
        ];
        
        $max_height = 4;
        $cell_lines = [];
        
        foreach ($data_row as $i => $cell) {
            $available_width = $col_widths[$i] - $cell_width_padding;
            $lines = $this->wrapText($pdf, (string)$cell, $available_width);
            $cell_lines[] = $lines;
            $line_count = count($lines);
            $height_needed = max(4, $line_count * 3.5 + 0.5);
            $max_height = max($max_height, $height_needed);
        }
        
        $max_height += $cell_height_padding;
        $start_x = $pdf->GetX();
        $start_y = $pdf->GetY();
        $current_x_pos = $start_x;
        
        $pdf->SetFont('times', 'B', $body_font_size);
        
        foreach ($data_row as $i => $cell) {
            $pdf->SetXY($current_x_pos, $start_y);
            $pdf->Cell($col_widths[$i], $max_height, '', 1, 0, 'C', false);
            if (!empty($cell) && $cell !== 'N/A') {
                $lines = $cell_lines[$i];
                $line_height = 3.5;
                $total_text_height = count($lines) * $line_height;
                $start_text_y = $start_y + ($max_height - $total_text_height) / 2;
                $pdf->SetFont('times', 'B', $body_font_size);
                foreach ($lines as $line_index => $line) {
                    $y_pos = $start_text_y + ($line_index * $line_height);
                    $pdf->SetXY($current_x_pos + 1, $y_pos);
                    $pdf->Cell($col_widths[$i] - 2, $line_height, trim($line), 0, 0, 'C');
                }
            } else {
                // Show N/A in the cell
                $pdf->SetFont('times', 'B', $body_font_size);
                $pdf->SetXY($current_x_pos + 1, $start_y + 1);
                $pdf->Cell($col_widths[$i] - 2, $max_height - 2, 'N/A', 0, 0, 'C');
            }
            $current_x_pos += $col_widths[$i];
        }
        
        $pdf->SetY($start_y + $max_height);
        $pdf->SetX($current_x);
        $pdf->Ln(4);
        
        // ============================================
        // SECTION C: Development Plan
        // ============================================
        $section_c_height = 200;
        
        // Check if we need a page break before Section C
        $this->checkPageBreak($pdf, $section_c_height, $bottom_margin, true, $page_break_start_y);
        
        $pdf->SetX($section_ab_x_offset);
        $pdf->SetFont('times', 'B', $body_font_size);
        $header = "C. Development Plan (to be jointly accomplished by the Program Head/Supervisor and\n";
        $header .= " Faculty)";
        $page_width = $pdf->getPageWidth();
        $right_margin = 13;
        $available_width = $page_width - $section_ab_x_offset - $right_margin;
        $pdf->MultiCell($available_width, 4, $header, 0, 'L');
        $pdf->Ln(5);
        
        // Development Plan Table
        $table_x_offset = $x_offset + 17;
        $pdf->SetX($table_x_offset);
        $current_x = $table_x_offset;
        
        $set_col_widths = $col_widths;
        $total_width = array_sum($set_col_widths);
        $reduced_width = $total_width - 10;
        $computation_col_widths = [$reduced_width];
        
        $pdf->SetFont('times', '', $body_font_size);
        $fill = false;
        $min_row_height = 30;
        
        $developmentPlan = $data['development_plan'] ?? [
            'areas_for_improvement' => '',
            'proposed_activities' => '',
            'action_plan' => ''
        ];
        
        $data_rows = [
            [
                'text' => 'Areas for Improvement', 
                'data' => $developmentPlan['areas_for_improvement'] ?? ''
            ],
            [
                'text' => 'Proposed Learning and Development Activities', 
                'data' => $developmentPlan['proposed_activities'] ?? ''
            ],
            [
                'text' => 'Action Plan', 
                'data' => $developmentPlan['action_plan'] ?? ''
            ]
        ];
        
        foreach ($data_rows as $row_index => $data_row) {
            $cell_text = $data_row['text'];
            $cell_data = $data_row['data'];
            
            $x_offset_text = 1;
            $top_padding = 1;
            $line_height = 4;
            $label_height = $line_height;
            $spacing = 1;
            
            $label_total_height = $top_padding + $label_height + $spacing;
            $data_height = 0;
            if (!empty($cell_data)) {
                $available_width = $computation_col_widths[0] - ($x_offset_text * 2);
                $lines = $this->wrapText($pdf, (string)$cell_data, $available_width);
                $data_height = count($lines) * $line_height;
            }
            
            $required_height = $label_total_height + $data_height + $top_padding;
            $row_height = max($min_row_height, $required_height);
            
            $start_x = $pdf->GetX();
            $start_y = $pdf->GetY();
            $current_x_pos = $start_x;
            
            $pdf->SetFont('times', '', $body_font_size);
            $pdf->SetXY($current_x_pos, $start_y);
            $pdf->Cell($computation_col_widths[0], $row_height, '', 1, 0, 'L', false);
            
            if (!empty($cell_text)) {
                $pdf->SetFont('times', 'B', $body_font_size);
                $align = 'L';
                $pdf->SetXY($current_x_pos + $x_offset_text, $start_y + $top_padding);
                $pdf->Cell($computation_col_widths[0] - ($x_offset_text * 2), $line_height, trim($cell_text), 0, 1, $align);
                
                if (!empty($cell_data)) {
                    $pdf->SetFont('times', '', $body_font_size);
                    $lines = $this->wrapText($pdf, (string)$cell_data, $computation_col_widths[0] - ($x_offset_text * 2));
                    $start_text_y = $start_y + $top_padding + $line_height + $spacing;
                    foreach ($lines as $line_index => $line) {
                        $y_pos = $start_text_y + ($line_index * $line_height);
                        $pdf->SetXY($current_x_pos + $x_offset_text, $y_pos);
                        $pdf->Cell($computation_col_widths[0] - ($x_offset_text * 2), $line_height, trim($line), 0, 0, $align);
                    }
                }
            }
            
            $pdf->SetY($start_y + $row_height);
            $pdf->SetX($current_x);
        }
        
        $pdf->Ln(3);
        
        // ============================================
        // ACKNOWLEDGMENT TEXT
        // ============================================
        $acknowledgment_x_offset = $x_offset + 22;
        $pdf->SetX($acknowledgment_x_offset);
        $pdf->SetFont('times', '', $body_font_size);
        
        $page_width = $pdf->getPageWidth();
        $right_margin = 23;
        $available_width = $page_width - $acknowledgment_x_offset - $right_margin;
        
        $header = "I acknowledge that I have received and reviewed the faculty evaluation conducted for the period mentioned above. I understand that my signature below does not necessarily indicate agreement with the evaluation but confirms that I have been given the opportunity to discuss it with my supervisor.\n";
        
        $pdf->MultiCell($available_width, 4, $header, 0, 'L');
        $pdf->Ln(3);
        
        // ============================================
        // SECTION D: Signature Section
        // ============================================
        $section_d_height = 60;
        
        // Check if we need a page break before Section D
        $this->checkPageBreak($pdf, $section_d_height, $bottom_margin, true, $page_break_start_y);
        
        $pdf->Ln(3);
        
        // Signature Table
        $pdf->SetX($table_x_offset);
        $current_x = $table_x_offset;
        
        $comments_table_width = array_sum($col_widths);
        $comments_col_widths = [
            $comments_table_width * 0.15,
            $comments_table_width * 0.02,
            $comments_table_width * 0.83
        ];
        foreach ($comments_col_widths as &$width) {
            $width = round($width, 2);
        }
        
        $pdf->SetFont('times', '', $body_font_size);
        $fill = false;
        $cell_width_padding = 2;
        $cell_height_padding = 1;
        
        // Get names from data
        $programHeadName = $data['program_head_name'] ?? '_________________________';
        $facultyName = $data['faculty_name'] ?? '_________________________';
        
        $data_rows = [
            ['PROGRAM HEAD/SUPERVISOR', '', ''],
            ['Signature', ':', ''],
            ['Name', ':', $programHeadName],
            ['Date Signed', ':', ''],
            ['FACULTY', '', ''],
            ['Signature', ':', ''],
            ['Name', ':', $facultyName],
            ['Date Signed', ':', '']
        ];
        
        foreach ($data_rows as $row_index => $row) {
            $is_merged_row = ($row_index == 0 || $row_index == 4);
            
            if ($is_merged_row) {
                $merged_text = $row[0];
                $max_height = 7;
                
                $start_x = $pdf->GetX();
                $start_y = $pdf->GetY();
                
                $pdf->SetXY($start_x, $start_y);
                $pdf->SetFillColor(230, 230, 230);
                $pdf->Cell($comments_table_width, $max_height, '', 1, 0, 'L', true);
                
                if (!empty($merged_text)) {
                    $pdf->SetFont('times', 'B', $body_font_size);
                    $align = 'L';
                    $x_offset_text = 1.5;
                    $line_height = 4;
                    $lines = $this->wrapText($pdf, (string)$merged_text, $comments_table_width - ($x_offset_text * 2));
                    $total_text_height = count($lines) * $line_height;
                    $start_text_y = $start_y + ($max_height - $total_text_height) / 2;
                    foreach ($lines as $line_index => $line) {
                        $y_pos = $start_text_y + ($line_index * $line_height);
                        $pdf->SetXY($start_x + $x_offset_text, $y_pos);
                        $pdf->Cell($comments_table_width - ($x_offset_text * 2), $line_height, trim($line), 0, 0, $align);
                    }
                }
                
                $pdf->SetY($start_y + $max_height);
                $pdf->SetX($current_x);
            } else {
                $max_height = 5;
                $cell_lines = [];
                
                foreach ($row as $i => $cell) {
                    $available_width = $comments_col_widths[$i] - $cell_width_padding;
                    $lines = $this->wrapText($pdf, (string)$cell, $available_width);
                    $cell_lines[] = $lines;
                    $line_count = count($lines);
                    $height_needed = max(5, $line_count * 4 + 1);
                    $max_height = max($max_height, $height_needed);
                }
                $max_height += $cell_height_padding;
                
                $start_x = $pdf->GetX();
                $start_y = $pdf->GetY();
                $current_x_pos = $start_x;
                
                foreach ($row as $i => $cell) {
                    $pdf->SetXY($current_x_pos, $start_y);
                    $pdf->Cell($comments_col_widths[$i], $max_height, '', 1, 0, 'L', false);
                    
                    if (!empty($cell)) {
                        $lines = $cell_lines[$i];
                        $line_height = 4;
                        $total_text_height = count($lines) * $line_height;
                        $start_text_y = $start_y + ($max_height - $total_text_height) / 2;
                        $pdf->SetFont('times', '', $body_font_size);
                        
                        if ($i == 0) {
                            $align = 'L';
                            $x_offset_text = 1;
                        } elseif ($i == 1) {
                            $align = 'C';
                            $x_offset_text = 0;
                        } else {
                            $align = 'L';
                            $x_offset_text = 3;
                        }
                        
                        foreach ($lines as $line_index => $line) {
                            $y_pos = $start_text_y + ($line_index * $line_height);
                            $pdf->SetXY($current_x_pos + $x_offset_text, $y_pos);
                            $pdf->Cell($comments_col_widths[$i] - ($x_offset_text * 2), $line_height, trim($line), 0, 0, $align);
                        }
                    }
                    $current_x_pos += $comments_col_widths[$i];
                }
                
                $pdf->SetY($start_y + $max_height);
                $pdf->SetX($current_x);
            }
        }
        
        $pdf->Ln(4);
    }
    
    /**
     * Register Times New Roman fonts
     */
    private function registerFonts()
    {
        static $fontsRegistered = false;
        
        if ($fontsRegistered) {
            return;
        }
        
        $font_path = public_path('fonts/times_new_roman_fonts');
        
        $fonts = ['TIMES.TTF', 'TIMESBD.TTF', 'TIMESBI.TTF', 'TIMESI.TTF'];
        foreach ($fonts as $font) {
            if (file_exists($font_path . '/' . $font)) {
                \TCPDF_FONTS::addTTFfont($font_path . '/' . $font, 'TrueTypeUnicode', '', 32);
            }
        }
        
        $fontsRegistered = true;
    }
    
    /**
     * Add watermark to PDF page
     */
    private function addWatermark($pdf)
    {
        static $watermark_path = null;
        static $page_width = null;
        static $page_height = null;
        
        if ($watermark_path === null) {
            $watermark_path = public_path('image/lnu_watermark.png');
            $page_width = $pdf->getPageWidth();
            $page_height = $pdf->getPageHeight();
        }
        
        if (file_exists($watermark_path)) {
            $pdf->Image(
                $watermark_path,
                0,
                0,
                $page_width,
                $page_height,
                '',
                '',
                '',
                false,
                300,
                '',
                false,
                false,
                0,
                false,
                false,
                false
            );
        }
    }
    
    /**
     * Display PDF file
     */
    public function display($filename)
    {
        $path = 'temp/pdf/' . $filename;
        
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'PDF not found');
        }
        
        $file = Storage::disk('local')->get($path);
        
        return response()->make($file, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"'
        ]);
    }
}