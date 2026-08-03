<?php

namespace App\Http\Controllers\Forms;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Forms\IFE\IFEDataController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use App\Models\SupervisorEvaluationSubmission;
use TCPDF;

class IndividualFacultyEvaluationPDF extends Controller
{
    /**
     * Generate IFE PDF (supports both single and batch generation)
     * POST /individual-faculty-evaluation/pdf/generate
     */
    public function generate(Request $request, $id = null)
    {
        // Increase execution limits for batch generation
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        
        // Get the data controller
        $dataController = new IFEDataController();
        
        if ($request->isMethod('get') || !$request->has('faculty_list')) {
            $targetId = $id ?? $request->query('id') ?? Auth::id();
            
            $user = null;
            if ($targetId) {
                $user = User::where('id', $targetId)
                    ->orWhere('id_no', $targetId)
                    ->first();
            }
            if (!$user) {
                $user = Auth::user();
            }

            $employeeIdNo = $user?->id_no ?? (string)($targetId ?? 1);
            $instructorName = $user ? trim(implode(' ', array_filter([$user->firstname, $user->lastname]))) : 'Faculty Member';
            if (empty($instructorName)) {
                $instructorName = $user?->name ?? 'Faculty Member';
            }

            $termId = (string) ($request->query('term_id') ?? '1');
            $schoolYearLabel = $request->query('school_year_label') ?? '';

            // Fetch data from data controller
            $facultyData = $dataController->getFacultyData($employeeIdNo, (int) $termId);

            Log::info('Faculty Data Debug:', [
                'faculty_id' => $employeeIdNo,
                'term_id' => $termId,
                'set_rows_count' => count($facultyData['set_data']['rows'] ?? []),
                'set_rows' => $facultyData['set_data']['rows'] ?? [],
            ]);

            $facultyList = [[
                'employee_id_no' => $employeeIdNo,
                'instructor' => $instructorName,
                'faculty_data' => $facultyData,
            ]];
        } else {
            // Validate request
            $validated = $request->validate([
                'term_id' => 'required|string',
                'faculty_list' => 'required|array',
                'faculty_list.*.employee_id_no' => 'required',
                'faculty_list.*.instructor' => 'required|string',
                'school_year_label' => 'nullable|string'
            ]);
            
            $termId = $validated['term_id'];
            $facultyList = $validated['faculty_list'];
            $schoolYearLabel = $validated['school_year_label'] ?? '';
            
            // Fetch data for each faculty
            foreach ($facultyList as &$faculty) {
                $faculty['faculty_data'] = $dataController->getFacultyData(
                    $faculty['employee_id_no'], 
                    (int) $termId
                );
            }
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
        
        // Get logged-in user for signature
        $loggedInUser = Auth::user();
        $preparedByName = $loggedInUser ? trim(($loggedInUser->firstname ?? '') . ' ' . ($loggedInUser->lastname ?? '')) : 'Staff';
        
        // Generate page for each faculty
        $totalPages = 0;
        
        foreach ($facultyList as $faculty) {
            $facultyData = $faculty['faculty_data'];
            
            // Prepare data for the template
            $data = [
                'faculty_name' => $faculty['instructor'] ?? 'Faculty Member',
                'college' => $facultyData['faculty_info']['college'] ?? 'N/A',
                'academic_rank' => $facultyData['faculty_info']['academic_rank'] ?? 'N/A',
                'semester' => $termId,
                'academic_year' => $termDetails['academic_year_display'],
                'date' => date('F j, Y'),
                'prepared_by' => $preparedByName,
                // Dean and Associate Dean names
                'dean_name' => $facultyData['faculty_info']['dean_name'] ?? '',
                'associate_dean_name' => $facultyData['faculty_info']['associate_dean_name'] ?? '',
                // SET Data
                'set_rows' => $facultyData['set_data']['rows'] ?? [],
                'overall_set_rating' => $facultyData['set_data']['overall_rating'] ?? null,
                // SEF Data
                'overall_sef_rating' => $facultyData['sef_data']['overall_rating'] ?? null,
                // Comments
                'student_comments' => $facultyData['comments']['student'] ?? [],
                'supervisor_comments' => $facultyData['comments']['supervisor'] ?? [],
            ];
            
            // Add page and generate form
            $pdf->AddPage();
            $this->addWatermark($pdf);
            $this->generateIFEForm($pdf, $data, 13, 26, $termDetails);
            $totalPages++;
        }
        
        // Generate PDF output
        $pdfOutput = $pdf->Output('', 'S');
        
        if ($request->isMethod('get') || !$request->has('faculty_list')) {
            return response()->make($pdfOutput, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="individual_faculty_evaluation.pdf"'
            ]);
        }
        
        // Generate filename
        $filename = 'ife_report_' . time() . '.pdf';
        $filepath = 'temp/pdf/' . $filename;
        Storage::disk('local')->put($filepath, $pdfOutput);
        
        // Get file size
        $fileSize = Storage::disk('local')->size($filepath);
        
        return response()->json([
            'success' => true,
            'pdf_url' => route('pdf.display', ['filename' => $filename]),
            'message' => $totalPages > 1 
                ? "IFE reports generated successfully for {$totalPages} faculty members"
                : "IFE report generated successfully",
            'total_pages' => $totalPages,
            'file_size_kb' => round($fileSize / 1024, 2)
        ]);
    }

    /**
     * Batch fetch SEF data for multiple faculty
     * POST /sef/batch-reports
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
        
        // Fetch all submissions for all faculty in ONE query with answers
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
            
            // Calculate average rating percentage across all submissions
            $totalPercentage = 0;
            foreach ($submissions as $submission) {
                $totalPercentage += $submission->rating_percentage ?? 0;
            }
            
            $overallPercentage = round($totalPercentage / $respondentCount, 2);
            
            // Calculate individual ratings for the 15 benchmarks
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
            
            // Average the ratings
            for ($i = 0; $i < 15; $i++) {
                if ($ratingCounts[$i] > 0) {
                    $ratings[$i] = round($ratings[$i] / $ratingCounts[$i], 2);
                } else {
                    $ratings[$i] = null;
                }
            }
            
            // Calculate total score and rating percentage from ratings
            $totalScore = array_sum(array_filter($ratings));
            $maxPossibleScore = 15 * 5;
            $calculatedPercentage = $maxPossibleScore > 0 ? round(($totalScore / $maxPossibleScore) * 100, 2) : 0;
            
            $comments = $submissions
                ->pluck('comments')
                ->filter()
                ->implode("\n");

            $results[$facultyId] = [
                'has_data' => true,
                'overall_sef_rating' => $overallPercentage ?: $calculatedPercentage,
                'total_evaluators' => $respondentCount,
                'overall_average' => $overallPercentage ? round($overallPercentage / 20, 2) : round($calculatedPercentage / 20, 2),
                'ratings_breakdown' => $ratings,
                'comments' => $comments,
                'total_score' => $totalScore,
                'max_score' => $maxPossibleScore,
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
     * GET /sef/faculty/{facultyId}/reports
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
     * Get term details
     */
    public function getTermDetails($termId)
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
        // If row is not an array or is empty, return a default height
        if (!is_array($row) || empty($row)) {
            return 5;
        }
        
        $max_height = 5;
        
        // Convert associative array to indexed array if needed
        $rowValues = array_values($row);
        
        // If row has fewer columns than needed, pad it
        $colCount = count($col_widths);
        if (count($rowValues) < $colCount) {
            $rowValues = array_pad($rowValues, $colCount, '');
        }
        
        foreach ($rowValues as $i => $cell) {
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
        
        if (!empty($data_rows) && is_array($data_rows)) {
            foreach ($data_rows as $row) {
                if (is_array($row) || is_object($row)) {
                    // Convert object to array if needed
                    if (is_object($row)) {
                        $row = (array) $row;
                    }
                    $total_height += $this->calculateRowHeight($pdf, $row, $col_widths);
                } else {
                    $total_height += $row_height + $padding;
                }
            }
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
     * Generate the IFE form (Individual Faculty Evaluation)
     */
    public function generateIFEForm($pdf, $data, $x_offset, $y_offset, $termDetails)
    {
        $body_font_size = 10;
        $title_font_size = 10;
        $header_font_size = 10;
        $row_height = 5;
        $scale_row_height = 5;
        $bottom_margin = 15;
        $page_break_start_y = 40;
        
        $total_table_width = 192;
        $rating_scale_width = 190;  
        $statement_width = 126;
        $rating_width = 66;
        $scale_col1 = 18;
        $scale_col2 = 45;
        $scale_col3 = ($total_table_width - $scale_col1 - $scale_col2) - 2;
        
        // Apply Y offset
        $current_y = $pdf->GetY();
        $pdf->SetY($current_y + ($y_offset / 2));
        $pdf->SetFont('times', '', $body_font_size);
        
        $section_indent = 4.5;
        $current_x = $x_offset + $section_indent;
        
        // ============================================
        // TITLE
        // ============================================
        $pdf->SetX($x_offset);
        $pdf->SetFont('times', 'B', $title_font_size);
        $pdf->Cell(0, 8, 'INDIVIDUAL FACULTY EVALUATION REPORT', 0, 1, 'C');
        $pdf->Ln(3);
        
        // ============================================
        // SECTION A: Faculty Information
        // ============================================

        // Create a new X position for Section A and B (moved to the right)
        $section_ab_x_offset = $x_offset + 20;

        // Check space before Section A
        $this->checkPageBreak($pdf, 60, $bottom_margin, true, $page_break_start_y);

        $pdf->SetX($section_ab_x_offset);
        $pdf->SetFont('times', 'B', $header_font_size);
        $pdf->Cell(0, 6, 'A. Faculty Information', 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);

        $indent = 5;

        // Name of Faculty
        $pdf->SetX($section_ab_x_offset + $indent);
        $label_text = 'Name of Faculty Evaluated:';
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
        $label_width = $pdf->GetStringWidth($label_text) + 0.6;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell(80, $row_height - 2, ' ' . $data['academic_rank'], 'B', 1, 'L');

        // Semester or Term/Academic Year
        $pdf->SetX($section_ab_x_offset + $indent);
        $label_text = 'Semester or Term/Academic Year:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', 'U', $body_font_size);
        $pdf->Cell(0, $row_height, ' ' . $termDetails['semester_display'] . ' - S.Y. ' . $termDetails['academic_year_display'], 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);

        $pdf->Ln(1);

        // ============================================
        // SECTION B: Summary of Average SET Rating Computation
        // ============================================

        $this->checkPageBreak($pdf, 50, $bottom_margin, true, $page_break_start_y);

        $pdf->SetX($section_ab_x_offset);
        $pdf->SetFont('times', 'B', $header_font_size);
        $pdf->Cell(0, 6, 'B. Summary of Average SET Rating Computation', 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);

        $indent = 5;

        $steps = [
            'Step 1: Get the average SET rating for each class.',
            'Step 2: Multiply the number of students in each class with its average SET rating to get the Weighted SET Score per class.',
            'Step 3: Get the total number of students and the total weighted SET score.'
        ];

        foreach ($steps as $step) {
            $pdf->SetX($section_ab_x_offset + $indent);
            $pdf->MultiCell(0, 5, $step, 0, 'L');
        }

        $pdf->Ln(3);

        // ============================================
        // TABLE: SET Computation (Dynamic Version)
        // ============================================

        $statement_width = 75;
        $mov_width = 72;
        $rating_width = 32;
        $benchmark_total_width = $statement_width + $mov_width + $rating_width;

        $col_widths = [
            8, 15, 25, 18, 22, 25
        ];

        $current_total = array_sum($col_widths);
        $scale_factor = $benchmark_total_width / $current_total;

        foreach ($col_widths as &$width) {
            $width = round($width * $scale_factor);
        }

        $total_width = array_sum($col_widths);

        // Prepare data rows - USE REAL DATA FROM $data['set_rows']
        $data_rows = $data['set_rows'] ?? [];

        // Normalize all rows to ensure they have the 'seq' key
        $normalizedRows = [];
        if (!empty($data_rows) && is_array($data_rows)) {
            foreach ($data_rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                
                // If row has numeric keys (no 'seq'), convert to associative
                if (!isset($row['seq']) && !isset($row['course_code'])) {
                    $normalizedRows[] = [
                        'seq' => $row[0] ?? '',
                        'course_code' => $row[1] ?? '',
                        'year_section' => $row[2] ?? '',
                        'student_count' => $row[3] ?? '',
                        'avg_set_rating' => $row[4] ?? '',
                        'weighted_score' => $row[5] ?? ''
                    ];
                } else {
                    // Already associative, keep as is
                    $normalizedRows[] = $row;
                }
            }
        }

        // Use the normalized rows directly (deduplication is already handled in IFEController)
        if (empty($normalizedRows)) {
            $normalizedRows = [
                [
                    'seq' => '',
                    'course_code' => 'No data available',
                    'year_section' => '',
                    'student_count' => '',
                    'avg_set_rating' => '',
                    'weighted_score' => ''
                ]
            ];
        }

        // ✅ PADDING LOGIC: Ensure at least 8 data rows (excluding header and total)
        $minRows = 8;
        $currentRowCount = count($normalizedRows);

        // Only pad if we have data (not the "No data available" case)
        $hasRealData = !(count($normalizedRows) === 1 && $normalizedRows[0]['course_code'] === 'No data available');

        if ($hasRealData && $currentRowCount < $minRows) {
            $rowsToAdd = $minRows - $currentRowCount;
            // Use the current count as the base for seq numbers
            $lastSeq = $currentRowCount;
            
            for ($i = 0; $i < $rowsToAdd; $i++) {
                $normalizedRows[] = [
                    'seq' => (string)($lastSeq + $i + 1),
                    'course_code' => '',
                    'year_section' => '',
                    'student_count' => '',
                    'avg_set_rating' => '',
                    'weighted_score' => ''
                ];
            }
        }

        $data_rows = $normalizedRows;

        // Calculate table height AFTER normalization
        $table_height = $this->calculateTableHeight($pdf, $data_rows, $col_widths, true, true);

        $this->checkPageBreak($pdf, $table_height + 20, $bottom_margin, true, $page_break_start_y);

        $table_x_offset = $x_offset + 6;
        $pdf->SetX($table_x_offset);
        $current_x = $table_x_offset;

        // HEADER ROW
        $headers = [
            'Seq',
            '(1) Course Code',
            '(2) Year/Section',
            '(3) No. of Students',
            '(4) Average SET Rating',
            '(3x4) Weighted SET Score'
        ];

        $pdf->SetFont('times', 'B', 10);

        $header_max_height = 6;
        $header_lines_data = [];
        $cell_width_padding = 2;
        $cell_height_padding = 1;

        foreach ($headers as $i => $text) {
            $available_width = $col_widths[$i] - $cell_width_padding;
            $lines = $this->wrapText($pdf, $text, $available_width);
            $header_lines_data[] = $lines;
            
            $line_count = count($lines);
            $height_needed = max(5, $line_count * 4 + 1);
            $header_max_height = max($header_max_height, $height_needed);
        }

        $header_max_height += $cell_height_padding;
        $start_x = $pdf->GetX();
        $start_y = $pdf->GetY();
        $current_x_pos = $start_x;

        foreach ($headers as $i => $text) {
            $pdf->SetXY($current_x_pos, $start_y);
            $pdf->Cell($col_widths[$i], $header_max_height, '', 1, 0, 'C', false);
            
            $lines = $header_lines_data[$i];
            $line_height = 4;
            $total_text_height = count($lines) * $line_height;
            $start_text_y = $start_y + ($header_max_height - $total_text_height) / 2;
            
            $pdf->SetFont('times', 'B', 10);
            
            foreach ($lines as $line_index => $line) {
                $y_pos = $start_text_y + ($line_index * $line_height);
                $pdf->SetXY($current_x_pos + 1, $y_pos);
                $pdf->Cell($col_widths[$i] - 2, $line_height, trim($line), 0, 0, 'C');
            }
            
            $current_x_pos += $col_widths[$i];
        }

        $pdf->SetY($start_y + $header_max_height);
        $pdf->SetX($current_x);

        // BODY ROWS
        $pdf->SetFont('times', '', 9);

        foreach ($data_rows as $row_index => $row) {
            // Ensure row is an array
            if (!is_array($row)) {
                continue;
            }

            $max_height = 5;
            $cell_lines = [];
            
            // Now all rows are normalized to associative arrays, so we can access with keys
            $rowValues = [
                'seq' => $row['seq'] ?? '',
                'course_code' => $row['course_code'] ?? '',
                'year_section' => $row['year_section'] ?? '',
                'student_count' => $row['student_count'] ?? '',
                'avg_set_rating' => $row['avg_set_rating'] ?? '',
                'weighted_score' => $row['weighted_score'] ?? ''
            ];
            
            // Check if this is an empty padding row (has seq but no other data)
            $hasSeqOnly = !empty($row['seq']) && empty($row['course_code']) && empty($row['year_section']) && 
                        empty($row['student_count']) && empty($row['avg_set_rating']) && 
                        empty($row['weighted_score']);
            
            // Check if completely empty (no seq either)
            $isEmptyRow = empty($row['seq']) && empty($row['course_code']) && empty($row['year_section']) && 
                        empty($row['student_count']) && empty($row['avg_set_rating']) && 
                        empty($row['weighted_score']);
            
            // Calculate row height
            foreach ($rowValues as $key => $cell) {
                $i = array_search($key, array_keys($rowValues));
                $available_width = $col_widths[$i] - $cell_width_padding;
                $lines = $this->wrapText($pdf, (string)$cell, $available_width);
                $cell_lines[$key] = $lines;
                
                $line_count = count($lines);
                $height_needed = max(5, $line_count * 4 + 1);
                $max_height = max($max_height, $height_needed);
            }
            
            $max_height += $cell_height_padding;
            
            $start_x = $pdf->GetX();
            $start_y = $pdf->GetY();
            $current_x_pos = $start_x;
            
            // Render each cell
            $colIndex = 0;
            foreach ($rowValues as $key => $cell) {
                $pdf->SetXY($current_x_pos, $start_y);
                $pdf->Cell($col_widths[$colIndex], $max_height, '', 1, 0, 'C', false);
                
                // ✅ For seq column (index 0) - ALWAYS display the seq number, centered
                if ($colIndex === 0) {
                    $displaySeq = !empty($cell) ? $cell : '';
                    if (!empty($displaySeq)) {
                        // Calculate vertical centering
                        $pdf->SetXY($current_x_pos + 1, $start_y + ($max_height - 5) / 2);
                        $pdf->SetFont('times', '', 9);
                        $pdf->Cell($col_widths[$colIndex] - 2, 5, $displaySeq, 0, 0, 'C');
                    }
                }
                // For non-empty data rows
                elseif (!$isEmptyRow && !$hasSeqOnly && !empty($cell) && $cell !== 'No data available') {
                    $lines = $cell_lines[$key] ?? [];
                    $line_height = 4;
                    $total_text_height = count($lines) * $line_height;
                    $start_text_y = $start_y + ($max_height - $total_text_height) / 2;
                    
                    $pdf->SetFont('times', '', 9);
                    
                    foreach ($lines as $line_index => $line) {
                        $y_pos = $start_text_y + ($line_index * $line_height);
                        $pdf->SetXY($current_x_pos + 1, $y_pos);
                        $pdf->Cell($col_widths[$colIndex] - 2, $line_height, trim($line), 0, 0, 'C');
                    }
                } 
                elseif ($cell === 'No data available') {
                    // Show message in the cell
                    $pdf->SetXY($current_x_pos + 1, $start_y + 2);
                    $pdf->SetFont('times', '', 9);
                    $pdf->Cell($col_widths[$colIndex] - 2, 5, 'No data', 0, 0, 'C');
                }
                // Empty padding rows with seq only: we already showed the seq above
                
                $current_x_pos += $col_widths[$colIndex];
                $colIndex++;
            }
            
            $pdf->SetY($start_y + $max_height);
            $pdf->SetX($current_x);
        }

        // TOTAL ROW
        $pdf->SetFont('times', 'B', 9);

        $start_x = $pdf->GetX();
        $start_y = $pdf->GetY();

        $merged_width = $col_widths[0] + $col_widths[1] + $col_widths[2];

        $pdf->SetXY($start_x, $start_y);
        $pdf->Cell($merged_width, 5, 'TOTAL', 1, 0, 'R', false);

        // Calculate totals from the data rows (excluding empty padding rows)
        $totalStudents = 0;
        $totalWeightedScore = 0;
        $totalAvgRating = 0;
        $rowCount = 0;

        if (!empty($data_rows) && is_array($data_rows)) {
            foreach ($data_rows as $row) {
                // Skip the total row itself if it exists
                if (isset($row['course_code']) && $row['course_code'] === 'TOTAL') {
                    continue;
                }
                
                // Skip empty padding rows
                if (empty($row['course_code']) && empty($row['year_section']) && 
                    empty($row['student_count']) && empty($row['avg_set_rating']) && 
                    empty($row['weighted_score'])) {
                    continue;
                }
                
                // Helper function to clean numeric values from various formats
                $cleanNumber = function($value) {
                    if (is_numeric($value)) {
                        return (float) $value;
                    }
                    if (is_string($value)) {
                        // Remove commas, percent signs, and other non-numeric characters
                        $cleaned = preg_replace('/[^0-9.]/', '', $value);
                        return $cleaned !== '' ? (float) $cleaned : 0;
                    }
                    return 0;
                };
                
                $studentCount = isset($row['student_count']) 
                    ? $cleanNumber($row['student_count']) 
                    : 0;
                
                $avgRating = isset($row['avg_set_rating']) 
                    ? $cleanNumber($row['avg_set_rating']) 
                    : 0;
                
                $weightedScore = isset($row['weighted_score']) 
                    ? $cleanNumber($row['weighted_score']) 
                    : 0;
                
                // If weighted_score is empty but we have student_count and avg_rating, calculate it
                if ($weightedScore == 0 && $studentCount > 0 && $avgRating > 0) {
                    $weightedScore = $studentCount * $avgRating;
                }
                
                $totalStudents += $studentCount;
                $totalWeightedScore += $weightedScore;
                
                if ($avgRating > 0) {
                    $totalAvgRating += $avgRating;
                    $rowCount++;
                }
            }
        }

        // Calculate the simple average for the "Average SET Rating" column (Column 4)
        $simpleAvg = 0;
        if ($rowCount > 0) {
            $simpleAvg = round($totalAvgRating / $rowCount, 2);
        }

        // Calculate the weighted average for the "Overall SET Rating" (Section C)
        $weightedAvg = 0;
        if ($totalStudents > 0 && $totalWeightedScore > 0) {
            $weightedAvg = round($totalWeightedScore / $totalStudents, 2);
        }

        // Column 3: No. of Students (index 3)
        $pdf->Cell($col_widths[3], 5, $totalStudents, 1, 0, 'C', false);

        // Column 4: Average SET Rating - Use SIMPLE AVERAGE (index 4)
        $pdf->Cell($col_widths[4], 5, number_format($simpleAvg, 2), 1, 0, 'C', false);

        // Column 5: Weighted SET Score (index 5)
        $pdf->Cell($col_widths[5], 5, number_format($totalWeightedScore, 2), 1, 0, 'C', false);

        $pdf->Ln(3);

        // ============================================
        // SECTION C: SET and SEF Ratings Computation
        // ============================================

        $section_c_height = 60;

        $this->checkPageBreak($pdf, $section_c_height, $bottom_margin, true, $page_break_start_y);

        $pdf->Ln(5);

        $pdf->SetX($section_ab_x_offset);

        $pdf->SetFont('times', 'B', $body_font_size);
        $header = 'C. SET and SEF Ratings Computation:';
        $pdf->Cell($pdf->GetStringWidth($header) + 1, 4, $header, 0, 0, 'L');

        $pdf->SetFont('times', '', $body_font_size);
        $instruction_first_line = ' Calculate the Overall SET Rating by dividing the total Weighted';
        $pdf->Cell(0, 4, $instruction_first_line, 0, 1, 'L');

        $pdf->SetX($section_ab_x_offset);
        $instruction_second_line = 'SET Score by the total number of students';
        $pdf->Cell(0, 4, $instruction_second_line, 0, 1, 'L');

        $pdf->Ln(3);

        $pdf->SetX($table_x_offset);
        $current_x = $table_x_offset;

        $set_col_widths = $col_widths;
        $total_width = array_sum($set_col_widths);

        $equal_col_width = $total_width / 3;
        $computation_col_widths = [
            $equal_col_width,
            $equal_col_width,
            $equal_col_width
        ];

        foreach ($computation_col_widths as &$width) {
            $width = round($width, 2);
        }

        // HEADER ROW
        $pdf->SetFont('times', 'B', 10);

        $computation_headers = [
            ' ',
            'SET Rating',
            'SEF Rating'
        ];

        $header_max_height = 6;
        $header_lines_data = [];
        $cell_width_padding = 2;
        $cell_height_padding = 1;

        foreach ($computation_headers as $i => $text) {
            $available_width = $computation_col_widths[$i] - $cell_width_padding;
            $lines = $this->wrapText($pdf, $text, $available_width);
            $header_lines_data[] = $lines;
            
            $line_count = count($lines);
            $height_needed = max(5, $line_count * 4 + 1);
            $header_max_height = max($header_max_height, $height_needed);
        }

        $header_max_height += $cell_height_padding;
        $start_x = $pdf->GetX();
        $start_y = $pdf->GetY();
        $current_x_pos = $start_x;

        foreach ($computation_headers as $i => $text) {
            $pdf->SetXY($current_x_pos, $start_y);
            $pdf->Cell($computation_col_widths[$i], $header_max_height, '', 1, 0, 'C', false);
            
            $lines = $header_lines_data[$i];
            $line_height = 4;
            $total_text_height = count($lines) * $line_height;
            $start_text_y = $start_y + ($header_max_height - $total_text_height) / 2;
            
            $pdf->SetFont('times', 'B', 10);
            
            foreach ($lines as $line_index => $line) {
                $y_pos = $start_text_y + ($line_index * $line_height);
                $pdf->SetXY($current_x_pos + 1, $y_pos);
                $pdf->Cell($computation_col_widths[$i] - 2, $line_height, trim($line), 0, 0, 'C');
            }
            
            $current_x_pos += $computation_col_widths[$i];
        }

        $pdf->SetY($start_y + $header_max_height);
        $pdf->SetX($current_x);

        // DATA ROW - Use real ratings
        $pdf->SetFont('times', '', 9);

        $setRating = $data['overall_set_rating'] !== null ? number_format($data['overall_set_rating'], 2) : 'N/A';
        $sefRating = $data['overall_sef_rating'] !== null ? number_format($data['overall_sef_rating'], 2) : 'N/A';

        $data_row = [
            'OVERALL RATING',
            $setRating,
            $sefRating
        ];

        $max_height = 5;
        $cell_lines = [];

        foreach ($data_row as $i => $cell) {
            $available_width = $computation_col_widths[$i] - $cell_width_padding;
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

        $pdf->SetFont('times', 'B', 9);

        foreach ($data_row as $i => $cell) {
            $pdf->SetXY($current_x_pos, $start_y);
            $pdf->Cell($computation_col_widths[$i], $max_height, '', 1, 0, 'C', false);
            
            if (!empty($cell)) {
                $lines = $cell_lines[$i];
                $line_height = 4;
                $total_text_height = count($lines) * $line_height;
                $start_text_y = $start_y + ($max_height - $total_text_height) / 2;
                $pdf->SetFont('times', 'B', 9);
                $align = ($i == 0) ? 'L' : 'C';
                $x_offset_text = ($i == 0) ? 3 : 1;
                
                foreach ($lines as $line_index => $line) {
                    $y_pos = $start_text_y + ($line_index * $line_height);
                    $pdf->SetXY($current_x_pos + $x_offset_text, $y_pos);
                    $pdf->Cell($computation_col_widths[$i] - ($x_offset_text * 2), $line_height, trim($line), 0, 0, $align);
                }
            }
            $current_x_pos += $computation_col_widths[$i];
        }

        $pdf->SetY($start_y + $max_height);
        $pdf->SetX($current_x);

        $right_indent = 10;
        $pdf->Ln(3);

        // ============================================
        // D. Summary of Qualitative Comments and Suggestions
        // ============================================

        // Use real comments from data
        $comments_data = $data['student_comments'] ?? [];
        $supervisor_comments_data = $data['supervisor_comments'] ?? [];

        // Normalize student comments
        $normalizedStudentComments = [];
        if (!empty($comments_data) && is_array($comments_data)) {
            foreach ($comments_data as $row) {
                if (!is_array($row) && !is_object($row)) {
                    continue;
                }
                if (is_object($row)) {
                    $row = (array) $row;
                }
                
                // Ensure row has seq and comment keys
                if (!isset($row['seq']) && !isset($row['comment'])) {
                    $normalizedStudentComments[] = [
                        'seq' => $row[0] ?? '',
                        'comment' => $row[1] ?? ''
                    ];
                } else {
                    $normalizedStudentComments[] = [
                        'seq' => $row['seq'] ?? '',
                        'comment' => $row['comment'] ?? ''
                    ];
                }
            }
        }
        
        // If no student comments, add a default row with seq = 1
        if (empty($normalizedStudentComments)) {
            $comments_data = [['seq' => '1', 'comment' => '']];
        } else {
            $comments_data = $normalizedStudentComments;
        }

        // Normalize supervisor comments (ONLY ONCE!)
        $normalizedSupervisorComments = [];
        if (!empty($supervisor_comments_data) && is_array($supervisor_comments_data)) {
            foreach ($supervisor_comments_data as $row) {
                if (!is_array($row) && !is_object($row)) {
                    continue;
                }
                if (is_object($row)) {
                    $row = (array) $row;
                }
                
                // Ensure row has seq and comment keys
                if (!isset($row['seq']) && !isset($row['comment'])) {
                    $normalizedSupervisorComments[] = [
                        'seq' => $row[0] ?? '',
                        'comment' => $row[1] ?? ''
                    ];
                } else {
                    $normalizedSupervisorComments[] = [
                        'seq' => $row['seq'] ?? '',
                        'comment' => $row['comment'] ?? ''
                    ];
                }
            }
        }
        
        // If no supervisor comments, add a default row with seq = 1
        if (empty($normalizedSupervisorComments)) {
            $supervisor_comments_data = [['seq' => '1', 'comment' => '']];
        } else {
            $supervisor_comments_data = $normalizedSupervisorComments;
        }

        $comments_table_width = 172;
        $comments_col_widths = [
            $comments_table_width * 0.10,
            $comments_table_width * 0.90
        ];
        foreach ($comments_col_widths as &$width) {
            $width = round($width, 2);
        }

        // Remove the old table height calculations and single page break
        // We'll use row-by-row page breaking instead

        $pdf->SetX($section_ab_x_offset);
        $pdf->SetFont('times', 'B', $body_font_size);
        $header = 'D. Summary of Qualitative Comments and Suggestions';
        $pdf->Cell($pdf->GetStringWidth($header) + 1, 4, $header, 0, 1, 'L');
        $pdf->Ln(3);

        $comments_x_offset = $table_x_offset;
        $pdf->SetX($comments_x_offset);
        $current_x = $comments_x_offset;

        // ============================================
        // STUDENT COMMENTS TABLE - Row by Row Page Breaking
        // ============================================
        
        // HEADER ROW for Student Comments
        $headers = ['Seq', 'Comments and Suggestions from the Students'];
        $pdf->SetFont('times', 'B', 10);

        $header_max_height = 6;
        $header_lines_data = [];
        $cell_width_padding = 2;
        $cell_height_padding = 1;

        // Calculate header height first
        foreach ($headers as $i => $text) {
            $available_width = $comments_col_widths[$i] - $cell_width_padding;
            $lines = $this->wrapText($pdf, $text, $available_width);
            $header_lines_data[] = $lines;
            $line_count = count($lines);
            $height_needed = max(5, $line_count * 4 + 1);
            $header_max_height = max($header_max_height, $height_needed);
        }
        $header_max_height += $cell_height_padding;

        // Check if header fits on current page
        $this->checkPageBreak($pdf, $header_max_height + 5, $bottom_margin, true, $page_break_start_y);

        // Render header
        $start_x = $pdf->GetX();
        $start_y = $pdf->GetY();
        $current_x_pos = $start_x;

        foreach ($headers as $i => $text) {
            $pdf->SetXY($current_x_pos, $start_y);
            $pdf->Cell($comments_col_widths[$i], $header_max_height, '', 1, 0, 'C', false);
            $lines = $header_lines_data[$i];
            $line_height = 4;
            $total_text_height = count($lines) * $line_height;
            $start_text_y = $start_y + ($header_max_height - $total_text_height) / 2;
            $pdf->SetFont('times', 'B', 10);
            foreach ($lines as $line_index => $line) {
                $y_pos = $start_text_y + ($line_index * $line_height);
                $pdf->SetXY($current_x_pos + 1, $y_pos);
                // Center the "Seq" header, left-align the comment header
                $align = ($i == 0) ? 'C' : 'L';
                $pdf->Cell($comments_col_widths[$i] - 2, $line_height, trim($line), 0, 0, $align);
            }
            $current_x_pos += $comments_col_widths[$i];
        }

        $pdf->SetY($start_y + $header_max_height);
        $pdf->SetX($current_x);

        // ✅ PADDING LOGIC: Ensure at least 6 rows (including the "add additional rows" row)
        // This works for BOTH cases: when there is data AND when there is no data
        $minRows = 6;
        $currentRowCount = count($comments_data);
        $hasRealComments = false;
        $hasNoDataPlaceholder = false;

        // Check what type of data we have
        foreach ($comments_data as $row) {
            if (isset($row['comment'])) {
                if ($row['comment'] === 'No student comments available') {
                    $hasNoDataPlaceholder = true;
                } else {
                    $hasRealComments = true;
                }
            }
        }

        // Prepare the padded data
        $paddedCommentsData = [];

        // If there's no data (only the "No student comments available" placeholder)
        if ($hasNoDataPlaceholder && !$hasRealComments) {
            // Keep the "No student comments available" row as the first row
            $paddedCommentsData[] = [
                'seq' => '1',
                'comment' => ''
            ];
            
            // Add empty rows to reach minRows - 1 (since we already have 1 row)
            for ($i = 2; $i <= $minRows - 1; $i++) {
                $paddedCommentsData[] = [
                    'seq' => (string)$i,
                    'comment' => ''
                ];
            }
            
            // Add the last row with the "(add additional rows if necessary)" text
            $paddedCommentsData[] = [
                'seq' => '',
                'comment' => '(add additional rows if necessary)'
            ];
        }
        // If there is real data but less than minRows
        elseif ($hasRealComments && $currentRowCount < $minRows) {
            // Start with the existing data
            $paddedCommentsData = $comments_data;
            
            $rowsToAdd = $minRows - $currentRowCount;
            // Get the last seq number
            $lastSeq = 0;
            foreach ($paddedCommentsData as $row) {
                if (!empty($row['seq']) && is_numeric($row['seq'])) {
                    $lastSeq = max($lastSeq, (int)$row['seq']);
                }
            }
            
            // Add empty rows
            for ($i = 0; $i < $rowsToAdd - 1; $i++) {
                $paddedCommentsData[] = [
                    'seq' => (string)($lastSeq + $i + 1),
                    'comment' => ''
                ];
            }
            
            // Add the last row with the "(add additional rows if necessary)" text
            $paddedCommentsData[] = [
                'seq' => (string)($lastSeq + $rowsToAdd),
                'comment' => '(add additional rows if necessary)'
            ];
        }
        // If there is real data and it's already >= minRows, use it as-is
        else {
            $paddedCommentsData = $comments_data;
        }

        // BODY ROWS for Student Comments - ROW BY ROW PAGE BREAK CHECKING
        $pdf->SetFont('times', '', 9);

        foreach ($paddedCommentsData as $row_index => $row) {
            // Ensure row is an array
            if (!is_array($row)) {
                continue;
            }

            // Calculate row height first
            $max_height = 5;
            $cell_lines = [];
            $rowValues = [
                $row['seq'] ?? '',
                $row['comment'] ?? ''
            ];
            
            // Check if this is the "add additional rows" row
            $isAddRowsRow = isset($row['comment']) && $row['comment'] === '(add additional rows if necessary)';
            // Check if this is the "No data" row
            $isNoDataRow = isset($row['comment']) && $row['comment'] === '';
            
            foreach ($rowValues as $i => $cell) {
                $available_width = $comments_col_widths[$i] - $cell_width_padding;
                $lines = $this->wrapText($pdf, (string)$cell, $available_width);
                $cell_lines[] = $lines;
                $line_count = count($lines);
                $height_needed = max(5, $line_count * 4 + 1);
                $max_height = max($max_height, $height_needed);
            }
            $max_height += $cell_height_padding;

            // CHECK IF THIS ROW FITS ON THE CURRENT PAGE
            // If not, add a page break and render the row on the new page
            if ($this->checkPageBreak($pdf, $max_height + 2, $bottom_margin, true, $page_break_start_y)) {
                // After page break, reset the X position to the table's starting position
                $pdf->SetX($comments_x_offset);
                $current_x = $comments_x_offset;
            }

            // Render the row
            $start_x = $pdf->GetX();
            $start_y = $pdf->GetY();
            $current_x_pos = $start_x;
            
            foreach ($rowValues as $i => $cell) {
                $pdf->SetXY($current_x_pos, $start_y);
                $pdf->Cell($comments_col_widths[$i], $max_height, '', 1, 0, 'L', false);
                
                // Check if this is an empty row (no comment)
                $isEmptyRow = empty($row['comment']) && !$isAddRowsRow && !$isNoDataRow;
                
                // For seq column (index 0) - always show the number if it exists
                if ($i === 0 && !empty($cell)) {
                    $pdf->SetXY($current_x_pos + 1, $start_y + ($max_height - 5) / 2);
                    $pdf->SetFont('times', '', 9);
                    $pdf->Cell($comments_col_widths[$i] - 2, 5, $cell, 0, 0, 'C');
                }
                // For the "add additional rows" row - skip seq column, show text in comment column
                elseif ($isAddRowsRow) {
                    if ($i === 0) {
                        // Seq column - leave empty
                        // Do nothing, just skip
                    } elseif ($i === 1) {
                        // Comment column - show the text
                        $pdf->SetXY($current_x_pos + 1, $start_y + ($max_height - 5) / 2);
                        $pdf->SetFont('times', '', 9);
                        $pdf->Cell($comments_col_widths[$i] - 2, 5, $cell, 0, 0, 'L');
                    }
                }
                // For the "No data" row
                elseif ($isNoDataRow && $i === 1) {
                    $pdf->SetXY($current_x_pos + 1, $start_y + ($max_height - 5) / 2);
                    $pdf->SetFont('times', '', 9);
                    $pdf->Cell($comments_col_widths[$i] - 2, 5, $cell, 0, 0, 'L');
                }
                // For non-empty data rows
                elseif (!empty($cell) && $cell !== ' ' && !$isEmptyRow) {
                    $lines = $cell_lines[$i];
                    $line_height = 4;
                    $total_text_height = count($lines) * $line_height;
                    $start_text_y = $start_y + ($max_height - $total_text_height) / 2;
                    $pdf->SetFont('times', '', 9);
                    $align = ($i == 0) ? 'C' : 'L';
                    $x_offset_text = ($i == 0) ? 1 : 1;
                    foreach ($lines as $line_index => $line) {
                        $y_pos = $start_text_y + ($line_index * $line_height);
                        $pdf->SetXY($current_x_pos + $x_offset_text, $y_pos);
                        $pdf->Cell($comments_col_widths[$i] - ($x_offset_text * 2), $line_height, trim($line), 0, 0, $align);
                    }
                }
                // Empty rows with seq only: we already showed the seq above
                
                $current_x_pos += $comments_col_widths[$i];
            }
            $pdf->SetY($start_y + $max_height);
            $pdf->SetX($current_x);
        }

        $pdf->Ln(4);

        // ============================================
        // SUPERVISOR COMMENTS TABLE - Row by Row Page Breaking
        // ============================================
        
        $pdf->SetX($comments_x_offset);
        $current_x = $comments_x_offset;

        $supervisor_col_widths = [
            $comments_table_width * 0.10,
            $comments_table_width * 0.90
        ];
        foreach ($supervisor_col_widths as &$width) {
            $width = round($width, 2);
        }

        $headers = ['Seq', 'Comments and Suggestions from the Supervisor'];
        $pdf->SetFont('times', 'B', 10);

        // Calculate header height first
        $header_max_height = 6;
        $header_lines_data = [];
        foreach ($headers as $i => $text) {
            $available_width = $supervisor_col_widths[$i] - $cell_width_padding;
            $lines = $this->wrapText($pdf, $text, $available_width);
            $header_lines_data[] = $lines;
            $line_count = count($lines);
            $height_needed = max(5, $line_count * 4 + 1);
            $header_max_height = max($header_max_height, $height_needed);
        }
        $header_max_height += $cell_height_padding;

        // Check if header fits on current page
        $this->checkPageBreak($pdf, $header_max_height + 5, $bottom_margin, true, $page_break_start_y);

        // Render header
        $start_x = $pdf->GetX();
        $start_y = $pdf->GetY();
        $current_x_pos = $start_x;

        foreach ($headers as $i => $text) {
            $pdf->SetXY($current_x_pos, $start_y);
            $pdf->Cell($supervisor_col_widths[$i], $header_max_height, '', 1, 0, 'C', false);
            $lines = $header_lines_data[$i];
            $line_height = 4;
            $total_text_height = count($lines) * $line_height;
            $start_text_y = $start_y + ($header_max_height - $total_text_height) / 2;
            $pdf->SetFont('times', 'B', 10);
            foreach ($lines as $line_index => $line) {
                $y_pos = $start_text_y + ($line_index * $line_height);
                $pdf->SetXY($current_x_pos + 1, $y_pos);
                // Center the "Seq" header, left-align the comment header
                $align = ($i == 0) ? 'C' : 'L';
                $pdf->Cell($supervisor_col_widths[$i] - 2, $line_height, trim($line), 0, 0, $align);
            }
            $current_x_pos += $supervisor_col_widths[$i];
        }

        $pdf->SetY($start_y + $header_max_height);
        $pdf->SetX($current_x);

        // ✅ PADDING LOGIC: Ensure at least 6 rows (including the "add additional rows" row)
        // This works for BOTH cases: when there is data AND when there is no data
        $minRows = 6;
        $currentRowCount = count($supervisor_comments_data);
        $hasRealComments = false;
        $hasNoDataPlaceholder = false;

        // Check what type of data we have
        foreach ($supervisor_comments_data as $row) {
            if (isset($row['comment'])) {
                if ($row['comment'] === '') {
                    $hasNoDataPlaceholder = true;
                } else {
                    $hasRealComments = true;
                }
            }
        }

        // Prepare the padded data
        $paddedSupervisorComments = [];

        // If there's no data (only the "No supervisor comments available" placeholder)
        if ($hasNoDataPlaceholder && !$hasRealComments) {
            // Keep the "No supervisor comments available" row as the first row
            $paddedSupervisorComments[] = [
                'seq' => '1',
                'comment' => ''
            ];
            
            // Add empty rows to reach minRows - 1 (since we already have 1 row)
            for ($i = 2; $i <= $minRows - 1; $i++) {
                $paddedSupervisorComments[] = [
                    'seq' => (string)$i,
                    'comment' => ''
                ];
            }
            
            // Add the last row with the "(add additional rows if necessary)" text
            $paddedSupervisorComments[] = [
                'seq' => '',
                'comment' => '(add additional rows if necessary)'
            ];
        }
        // If there is real data but less than minRows
        elseif ($hasRealComments && $currentRowCount < $minRows) {
            // Start with the existing data
            $paddedSupervisorComments = $supervisor_comments_data;
            
            $rowsToAdd = $minRows - $currentRowCount;
            // Get the last seq number
            $lastSeq = 0;
            foreach ($paddedSupervisorComments as $row) {
                if (!empty($row['seq']) && is_numeric($row['seq'])) {
                    $lastSeq = max($lastSeq, (int)$row['seq']);
                }
            }
            
            // Add empty rows
            for ($i = 0; $i < $rowsToAdd - 1; $i++) {
                $paddedSupervisorComments[] = [
                    'seq' => (string)($lastSeq + $i + 1),
                    'comment' => ''
                ];
            }
            
            // Add the last row with the "(add additional rows if necessary)" text
            $paddedSupervisorComments[] = [
                'seq' => '',
                'comment' => '(add additional rows if necessary)'
            ];
        }
        // If there is real data and it's already >= minRows, use it as-is
        else {
            $paddedSupervisorComments = $supervisor_comments_data;
        }

        // BODY ROWS for Supervisor Comments - ROW BY ROW PAGE BREAK CHECKING
        $pdf->SetFont('times', '', 9);

        foreach ($paddedSupervisorComments as $row_index => $row) {
            // Ensure row is an array
            if (!is_array($row)) {
                continue;
            }

            // Calculate row height first
            $max_height = 5;
            $cell_lines = [];
            $rowValues = [
                $row['seq'] ?? '',
                $row['comment'] ?? ''
            ];
            
            // Check if this is the "add additional rows" row
            $isAddRowsRow = isset($row['comment']) && $row['comment'] === '(add additional rows if necessary)';
            // Check if this is the "No data" row
            $isNoDataRow = isset($row['comment']) && $row['comment'] === ' ';
            
            foreach ($rowValues as $i => $cell) {
                $available_width = $supervisor_col_widths[$i] - $cell_width_padding;
                $lines = $this->wrapText($pdf, (string)$cell, $available_width);
                $cell_lines[] = $lines;
                $line_count = count($lines);
                $height_needed = max(5, $line_count * 4 + 1);
                $max_height = max($max_height, $height_needed);
            }
            $max_height += $cell_height_padding;

            // CHECK IF THIS ROW FITS ON THE CURRENT PAGE
            // If not, add a page break and render the row on the new page
            if ($this->checkPageBreak($pdf, $max_height + 2, $bottom_margin, true, $page_break_start_y)) {
                // After page break, reset the X position to the table's starting position
                $pdf->SetX($comments_x_offset);
                $current_x = $comments_x_offset;
            }

            // Render the row
            $start_x = $pdf->GetX();
            $start_y = $pdf->GetY();
            $current_x_pos = $start_x;
            
            foreach ($rowValues as $i => $cell) {
                $pdf->SetXY($current_x_pos, $start_y);
                $pdf->Cell($supervisor_col_widths[$i], $max_height, '', 1, 0, 'L', false);
                
                // Check if this is an empty row (no comment)
                $isEmptyRow = empty($row['comment']) && !$isAddRowsRow && !$isNoDataRow;
                
                // For seq column (index 0) - always show the number if it exists
                if ($i === 0 && !empty($cell)) {
                    $pdf->SetXY($current_x_pos + 1, $start_y + ($max_height - 5) / 2);
                    $pdf->SetFont('times', '', 9);
                    $pdf->Cell($supervisor_col_widths[$i] - 2, 5, $cell, 0, 0, 'C');
                }
                // For the "add additional rows" row - skip seq column, show text in comment column
                elseif ($isAddRowsRow) {
                    if ($i === 0) {
                        // Seq column - leave empty
                        // Do nothing, just skip
                    } elseif ($i === 1) {
                        // Comment column - show the text
                        $pdf->SetXY($current_x_pos + 1, $start_y + ($max_height - 5) / 2);
                        $pdf->SetFont('times', '', 9);
                        $pdf->Cell($supervisor_col_widths[$i] - 2, 5, $cell, 0, 0, 'L');
                    }
                }
                // For the "No data" row
                elseif ($isNoDataRow && $i === 1) {
                    $pdf->SetXY($current_x_pos + 1, $start_y + ($max_height - 5) / 2);
                    $pdf->SetFont('times', '', 9);
                    $pdf->Cell($supervisor_col_widths[$i] - 2, 5, $cell, 0, 0, 'L');
                }
                // For non-empty data rows
                elseif (!empty($cell) && $cell !== ' ' && !$isEmptyRow) {
                    $lines = $cell_lines[$i];
                    $line_height = 4;
                    $total_text_height = count($lines) * $line_height;
                    $start_text_y = $start_y + ($max_height - $total_text_height) / 2;
                    $pdf->SetFont('times', '', 9);
                    $align = ($i == 0) ? 'C' : 'L';
                    $x_offset_text = ($i == 0) ? 1 : 1;
                    foreach ($lines as $line_index => $line) {
                        $y_pos = $start_text_y + ($line_index * $line_height);
                        $pdf->SetXY($current_x_pos + $x_offset_text, $y_pos);
                        $pdf->Cell($supervisor_col_widths[$i] - ($x_offset_text * 2), $line_height, trim($line), 0, 0, $align);
                    }
                }
                // Empty rows with seq only: we already showed the seq above
                
                $current_x_pos += $supervisor_col_widths[$i];
            }
            $pdf->SetY($start_y + $max_height);
            $pdf->SetX($current_x);
        }

        $pdf->Ln(3);
        
        // ============================================
        // Signature Section - Individual Sections
        // ============================================

        $sig_row_height = 5;
        $fixed_line_width = 70;
        $right_indent = 10;

        // Prepared by Section
        $prepared_by_height = 6;
        $prepared_by_height += (3 * ($sig_row_height + 1));
        $prepared_by_height += 4;

        $this->checkPageBreak($pdf, $prepared_by_height, $bottom_margin, true, $page_break_start_y);

        $pdf->SetX($current_x + 4);
        $pdf->SetFont('times', 'B', $header_font_size);
        $pdf->Cell(0, 6, 'Prepared by:', 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);

        $pdf->Ln(1.5);

        // Signature of Staff
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', '', $body_font_size);
        $label = 'Signature of Staff:';
        $label_width = $pdf->GetStringWidth($label);
        $pdf->Cell($label_width, $sig_row_height, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell($fixed_line_width, $sig_row_height, '', 'B', 1);

        // Name of Staff - Use logged-in user's name
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', '', $body_font_size);
        $label = 'Name of Staff:';
        $label_width = $pdf->GetStringWidth($label);
        $pdf->Cell($label_width, $sig_row_height, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell($fixed_line_width, $sig_row_height, ' ' . ($data['prepared_by'] ?? '_________________________'), 'B', 1);

        // Date
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', '', $body_font_size);
        $label = 'Date:';
        $label_width = $pdf->GetStringWidth($label);
        $pdf->Cell($label_width, $sig_row_height, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell($fixed_line_width, $sig_row_height, ' ' . $data['date'], 'B', 1);

        $pdf->Ln(2);

        // Reviewed by Section
        $reviewed_by_height = 6;
        $reviewed_by_height += (3 * ($sig_row_height + 1));
        $reviewed_by_height += 4;

        $this->checkPageBreak($pdf, $reviewed_by_height, $bottom_margin, true, $page_break_start_y);

        $pdf->SetX($current_x + 4);
        $pdf->SetFont('times', 'B', $header_font_size);
        $pdf->Cell(0, 6, 'Reviewed by:', 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);

        $pdf->Ln(1.5);

        // Signature of College Associate Dean
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', '', $body_font_size);
        $label = 'Signature of College Associate Dean:';
        $label_width = $pdf->GetStringWidth($label);
        $pdf->Cell($label_width, $sig_row_height, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell($fixed_line_width, $sig_row_height, '', 'B', 1);

        // Name of College Associate Dean
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', '', $body_font_size);
        $label = 'Name of College Associate Dean:';
        $label_width = $pdf->GetStringWidth($label);
        $pdf->Cell($label_width, $sig_row_height, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell($fixed_line_width, $sig_row_height, ' ' . ($data['associate_dean_name'] ?? '_________________________'), 'B', 1);

        // Date
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', '', $body_font_size);
        $label = 'Date:';
        $label_width = $pdf->GetStringWidth($label);
        $pdf->Cell($label_width, $sig_row_height, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell($fixed_line_width, $sig_row_height, ' ' . $data['date'], 'B', 1);

        $pdf->Ln(2);

        // Concurred by Section
        $concurred_by_height = 6;
        $concurred_by_height += (3 * ($sig_row_height + 1));
        $concurred_by_height += 4;

        $this->checkPageBreak($pdf, $concurred_by_height, $bottom_margin, true, $page_break_start_y);

        $pdf->SetX($current_x + 4);
        $pdf->SetFont('times', 'B', $header_font_size);
        $pdf->Cell(0, 6, 'Concurred by:', 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);

        $pdf->Ln(1.5);

        // Signature of College Dean
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', '', $body_font_size);
        $label = 'Signature of College Dean:';
        $label_width = $pdf->GetStringWidth($label);
        $pdf->Cell($label_width, $sig_row_height, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell($fixed_line_width, $sig_row_height, '', 'B', 1);

        // Name of College Dean
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', '', $body_font_size);
        $label = 'Name of College Dean:';
        $label_width = $pdf->GetStringWidth($label);
        $pdf->Cell($label_width, $sig_row_height, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell($fixed_line_width, $sig_row_height, ' ' . ($data['dean_name'] ?? '_________________________'), 'B', 1);

        // Date
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', '', $body_font_size);
        $label = 'Date:';
        $label_width = $pdf->GetStringWidth($label);
        $pdf->Cell($label_width, $sig_row_height, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell($fixed_line_width, $sig_row_height, ' ' . $data['date'], 'B', 1);

        $pdf->Ln(2);
    }
    
    /**
     * Register Times New Roman fonts
     */
    public function registerFonts()
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
    public function addWatermark($pdf)
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