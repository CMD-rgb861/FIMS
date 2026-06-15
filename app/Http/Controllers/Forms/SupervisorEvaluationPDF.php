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

class SupervisorEvaluationPDF extends Controller
{
    /**
     * Generate SEF PDF (supports both single and batch generation)
     * POST /sef/pdf/generate
     */
    public function generate(Request $request)
    {
        // Increase execution limits for batch generation
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        
        // Validate request
        $validated = $request->validate([
            'term_id' => 'required|string',
            'faculty_list' => 'required|array',
            'faculty_list.*.employee_id_no' => 'required',
            'faculty_list.*.instructor' => 'required|string',
            'faculty_list.*.ratings_breakdown' => 'nullable|array', // Add this
            'school_year_label' => 'nullable|string'
        ]);
        
        $termId = $validated['term_id'];
        $facultyList = $validated['faculty_list'];
        $schoolYearLabel = $validated['school_year_label'] ?? '';
        
        // Register Times New Roman fonts
        $this->registerFonts();
        
        // Define benchmark statements (for SEF)
        $statements = $this->getBenchmarkStatements();
        
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
        
        // Get faculty college and department from database
        $facultyInfo = User::with(['college', 'unit'])
            ->where('id_no', $faculty['employee_id_no'])
            ->first();
        
        $collegeName = $facultyInfo?->college?->name ?? '';
        $departmentName = $facultyInfo?->unit?->name ?? '';
        
        // Combine college and department (if both exist)
        $collegeDepartment = collect([
            $collegeName,
            $departmentName
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

        // Prepare data for the template
        $data = [
            'faculty_name' => $faculty['instructor'] ?? 'Faculty Member',
            'college' => $collegeDepartment,
            'course_code' => $faculty['course_code'] ?? '',
            'course_title' => $faculty['course_title'] ?? '',
            'program_level' => $faculty['program_level'] ?? '',
            'semester' => $termId,
            'academic_year' => $termDetails['academic_year_display'],
            'ratings' => $ratings,
            'comments' => $comments,
            'evaluator_name' => $faculty['evaluator_name'] ?? 'Supervisor',
            'evaluator_id' => $faculty['evaluator_id'] ?? '',
            'date' => date('F j, Y')
        ];
            
            // Add page and generate form
            $pdf->AddPage();
            $this->addWatermark($pdf);
            $this->generateSEFForm($pdf, $data, 13, 26, $termDetails, $statements);
            $totalPages++;
        }
        
        // Generate PDF output
        $pdfOutput = $pdf->Output('', 'S');
        
        // Generate filename
        $filename = 'sef_report_' . time() . '.pdf';
        $filepath = 'temp/pdf/' . $filename;
        Storage::disk('local')->put($filepath, $pdfOutput);
        
        // Get file size
        $fileSize = Storage::disk('local')->size($filepath);
        
        return response()->json([
            'success' => true,
            'pdf_url' => route('pdf.display', ['filename' => $filename]),
            'message' => $totalPages > 1 
                ? "SEF reports generated successfully for {$totalPages} faculty members"
                : "SEF report generated successfully",
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
        set_time_limit(120); // 2 minutes should be enough
        
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
                    // Extract question number from keys like 'q1', 'q2', 'Benchmark 1', etc.
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
                    $ratings[$i] = null; // No data for this benchmark
                }
            }
            
            // Calculate total score and rating percentage from ratings
            $totalScore = array_sum(array_filter($ratings));
            $maxPossibleScore = 15 * 5; // 15 questions * max 5 points = 75
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
        // Handle formats like 'q1', 'q2', 'q10'
        if (preg_match('/^q(\d+)$/i', $questionKey, $matches)) {
            return (int) $matches[1];
        }
        
        // Handle formats like 'Benchmark 1', 'Question 1'
        if (preg_match('/(?:benchmark|question)\s*(\d+)/i', $questionKey, $matches)) {
            return (int) $matches[1];
        }
        
        // Handle formats like '1', '2', '10'
        if (is_numeric($questionKey)) {
            return (int) $questionKey;
        }
        
        // Default: try to extract any number from the string
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
        
        // Get all submissions for this faculty (as instructor being evaluated)
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
        
        // Calculate average rating percentage across all submissions
        $totalPercentage = 0;
        foreach ($submissions as $submission) {
            $totalPercentage += $submission->rating_percentage ?? 0;
        }
        
        $overallPercentage = $respondentCount > 0 ? round($totalPercentage / $respondentCount, 2) : null;
        
        // Calculate ratings breakdown for 15 benchmarks
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
     * Get benchmark statements for SEF
     */
    private function getBenchmarkStatements()
    {
        return [
            'Benchmark Statements for Faculty Teaching Effectiveness',
            'A. Management of Teaching and Learning',
            'Management of Teaching and Learning refers to the intentional and organized handling of classroom presence, clear communication of academic expectations, efficient use of time, and the purposeful use of student-centered activities that promote critical thinking, independent learning, reflection, decision-making, and continuous academic improvement through constructive feedback.',
            '1. Comes to class on time.',
            '2. Explains learning outcomes, expectations, grading system, and requirements of the subject/course.',
            '3. Maximizes the allocated time/learning hours effectively.',
            '4. Facilitates students to think critically and creatively by providing appropriate learning activities.',
            '5. Guides students to learn on their own, reflect on new ideas and experiences, and make decisions in accomplishing given tasks.',
            '6. Communicates constructive feedback to students for their academic growth.',
            'B. Content Knowledge, Pedagogy and Technology',
            'Content Knowledge, Pedagogy, and Technology refer to a teacher\'s ability to demonstrate a strong grasp of subject matter, present complex concepts in a clear and accessible way, relate content to real-world contexts and current developments, engage students through appropriate instructional strategies and digital tools, and apply assessment methods aligned with intended learning outcomes.',
            '7. Demonstrates extensive and broad knowledge of the subject/course.',
            '8. Simplifies complex ideas for ease of understanding.',
            '9. Relates subject matter to contemporary issues and developments in the discipline and/or daily life activities.',
            '10. Promotes active learning and student engagement by using appropriate teaching and learning resources including ICT tools and platforms.',
            '11. Uses appropriate assessments (project, exams, quizzes, assignments, etc.) aligned with learning outcomes.',
            'C. Commitment and Transparency',
            'Commitment and Transparency refer to the teacher\'s consistent dedication to supporting student learning by acknowledging learner diversity, offering timely academic support and feedback, and upholding fairness and accountability through the use of clear and openly communicated performance criteria.',
            '12. Recognizes and values the unique diversity and individual differences among students.',
            '13. Assists students with their learning challenges during consultations hours.',
            '14. Provides immediate feedback on student outputs and performance.',
            '15. Provides transparent and clear criteria in rating student\'s performance.',
            'TOTAL SCORE = SUM OF ALL SCORES',
            'RATING = (Total Score/75) x 100',
        ];
    }

    /**
     * Get Suggested Means of Verification text based on statement number
     */
    private function getMeansOfVerification($statementNumber)
    {
        $movList = [
            1 => "• Daily time record\n• Faculty schedule and timetable\n• Informal interview with students",
            2 => "• Documents submission log\n• Submission Receipts or Acknowledgment Emails",
            3 => "• Class Schedules & Timetables\n• LMS Logs\n• Informal interview with students",
            4 => "• Course syllabus\n• Learning Plan\n• Classroom Observation\n• Informal interview with students\n• LMS Logs",
            5 => "• Course Syllabus\n• Learning Plan\n• Student Work Samples\n• Classroom Observation\n• LMS Logs\n• Informal interview with students\n• Faculty Consultation Log",
            6 => "• Graded Student Work with Feedback\n• Faculty Consultation Log\n• Informal interview with students\n• Emails or Official correspondence\n• LMS Logs",
            7 => "• Course Syllabus\n• Learning Plan\n• IMs developed by the faculty\n• Informal interview with students\n• Mentorship or Thesis/Dissertation Advisory records",
            8 => "• Learning Plan\n• Course Syllabus\n• Classroom Observation\n• Informal interview with students\n• Lecture notes and presentations\n• LMS Logs",
            9 => "• Course Syllabus\n• Learning Plan\n• Classroom Observation\n• Informal interview with students\n• LMS Logs\n• IMs developed by the faculty\n• Participation in Conferences, Webinars, and Training",
            10 => "• Course Syllabus\n• Learning Plan\n• Classroom Observation\n• Informal interview with students\n• LMS Logs\n• Multimedia Lecture Materials\n• Student Work Samples",
            11 => "• Course Syllabus\n• Learning Plan\n• Informal interview with students\n• Assessment tools and rubrics\n• Exam and Quiz Samples\n• Graded Student Work Samples\n• LMS records",
            12 => "• Course Syllabus\n• Learning Plan\n• IMs developed by the faculty\n• Classroom Observation\n• Informal interview with students",
            13 => "• Course Syllabus\n• Faculty Consultation Log\n• Advisory Records\n• LMS Logs\n• Emails or Official Correspondence",
            14 => "• Graded Student Work Samples\n• Assessment tools and rubrics\n• Informal interview with students\n• LMS Logs\n• Emails or Official Correspondence",
            15 => "• Course Syllabus\n• Assessment Tools and Rubrics\n• Informal interview with students\n• LMS Records\n• Grade Sheets and Records",
        ];

        return $movList[$statementNumber] ?? "• Supporting documentation as evidence";
    }
    
    /**
     * Get faculty ratings from SEF submissions (returns 15 ratings)
     */
    private function getFacultyRatings($facultyId, $termId)
    {
        // Get all submissions for this faculty
        $submissions = SupervisorEvaluationSubmission::query()
            ->where('instructor_id_no', $facultyId)
            ->where('term_id', $termId)
            ->with('answers')
            ->get();
        
        $respondentCount = $submissions->count();
        
        if ($respondentCount === 0) {
            return array_fill(0, 15, 4);
        }
        
        // Calculate averages for 15 positions
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
        
        // Calculate final averages
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
     * Generate the SEF form (Supervisor's Evaluation of Faculty)
     */
    private function generateSEFForm($pdf, $data, $x_offset, $y_offset, $termDetails, $statements)
    {
        $body_font_size = 10;
        $title_font_size = 10;
        $header_font_size = 10;
        $row_height = 5;
        $scale_row_height = 5;
        
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
        // TITLE (REWORDED FOR SEF)
        // ============================================
        $pdf->SetX($x_offset);
        $pdf->SetFont('times', 'B', $title_font_size);
        $pdf->Cell(0, 8, 'SUPERVISOR\'S EVALUATION OF FACULTY (SEF)', 0, 1, 'C');
        $pdf->Ln(3);
        
        // ============================================
        // SECTION A: Faculty Information
        // ============================================
        $pdf->SetX($current_x);
        $pdf->SetFont('times', 'B', $header_font_size);
        $pdf->Cell(0, 6, 'A. Faculty Information (to be accomplished by the Designated Office)', 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        // Name of Faculty
        $pdf->SetX($current_x);
        $label_text = 'Name of Faculty being Evaluated:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', 'U', $body_font_size);
        $pdf->Cell(0, $row_height, ' ' . $data['faculty_name'], 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        // College/Department
        $pdf->SetX($current_x);
        $label_text = 'College/Department:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', 'U', $body_font_size);
        $pdf->Cell(0, $row_height, ' ' . $data['college'], 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        // Course Code/Title
        $pdf->SetX($current_x);
        $label_text = 'Course Code/Title:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell(80, $row_height, '', 'B', 1, 'L'); // Bottom border only

        // Program Level
        $pdf->SetX($current_x);
        $label_text = 'Program Level:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell(80, $row_height, '', 'B', 1, 'L'); // Bottom border only
        
        // Semester or Term/Academic Year
        $pdf->SetX($current_x);
        $label_text = 'Semester or Term/Academic Year:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', 'U', $body_font_size);
        $pdf->Cell(0, $row_height, ' ' . $termDetails['semester_display'] . ' - S.Y. ' . $termDetails['academic_year_display'], 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        $pdf->Ln(1);
        
        // ============================================
        // SECTION B: Rating Scale
        // ============================================
        $pdf->SetX($current_x);
        $pdf->SetFont('times', 'B', $header_font_size);
        $pdf->Cell(0, 6, 'B. Rating Scale', 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        // Center the Rating Scale table
        $rating_scale_x_offset = $x_offset + (($total_table_width - $rating_scale_width) / 2);
        
        // Rating scale table headers
        $pdf->SetFillColor(200, 200, 200);
        $pdf->SetX($rating_scale_x_offset);
        $pdf->Cell($scale_col1, $scale_row_height, 'Scale', 1, 0, 'C', true);
        $pdf->Cell($scale_col2, $scale_row_height, '    Qualitative Description', 1, 0, 'C', true);
        $pdf->Cell($scale_col3, $scale_row_height, '         Operational Definition', 1, 1, 'L', true);
        
        // Rating scale rows
        $scale_data = [
            ['5', 'Always manifested', 'Evident in nearly all relevant situations (91-100% of instances)'],
            ['4', 'Often manifested', 'Evident most of the time, with occasional lapses (61-90%)'],
            ['3', 'Sometimes manifested', 'Evident about half the time (31-60%)'],
            ['2', 'Seldom manifested', 'Infrequently Demonstrated: Rarely evident in relevant situations (11-30%)'],
            ['1', 'Never/Rarely manifested', 'Seldom Demonstrated: Almost never evident, with only isolated cases (0-10%)']
        ];
        
        $pdf->SetFillColor(255, 255, 255);
        foreach ($scale_data as $row) {
            $pdf->SetX($rating_scale_x_offset);
            $pdf->Cell($scale_col1, $scale_row_height, $row[0], 1, 0, 'C');
            $pdf->Cell($scale_col2, $scale_row_height, '       ' . $row[1], 1, 0, 'L');
            $pdf->Cell($scale_col3, $scale_row_height, '         ' . $row[2], 1, 1, 'L');
        }
        
        $pdf->Ln(2);
        
        // ============================================
        // SECTION C: Instruction
        // ============================================
        $pdf->SetX($current_x);
        $pdf->SetFont('times', 'B', $body_font_size);
        $header = 'C. Instruction:';
        $pdf->Cell($pdf->GetStringWidth($header) + 1, 4, $header, 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell(0, 4, 'Carefully read each benchmark statement and rate the faculty member by encircling the', 0, 1, 'L');
        $pdf->SetX($current_x);
        $pdf->Cell(0, 4, 'appropriate rating based on the scale above. The "Suggested Means of Verification" column may guide the supervisor in', 0, 1, 'L');
        $pdf->SetX($current_x);
        $pdf->Cell(0, 4, 'conducting an objective assessment.', 0, 1, 'L');
        $pdf->Ln(1);
        
        // ============================================
        // BENCHMARK STATEMENTS TABLE
        // ============================================
        $rating_index = 0;
        $fill = false;
        $page_break_added = false;
        
        // Define column widths for 3-column layout: Statement | MOV | Rating
        $statement_width = 85;   // Width for benchmark statement
        $mov_width = 70;         // Width for Means of Verification
        $rating_width = 37;      // Width for rating (1-5 scale)
        $total_table_width_new = $statement_width + $mov_width + $rating_width;
        
        // Print column headers first
        $pdf->SetFont('times', 'B', 10);
        $pdf->SetFillColor(200, 200, 200);
        $pdf->SetX($x_offset);
        
        $pdf->Cell($statement_width, 5, 'Benchmark Statements', 1, 0, 'C', true);
        $pdf->Cell($mov_width, 5, 'Suggested Means of Verification', 1, 0, 'C', true);
        $pdf->Cell($rating_width, 5, 'Rating (1-5)', 1, 1, 'C', true);
        
        $pdf->SetFont('times', '', $body_font_size);
        $fill = !$fill;
        
        foreach ($statements as $idx => $statement) {
            if (empty($statement)) {
                $pdf->Ln(1);
                continue;
            }
            
            // Section headers (A., B., C.)
            if (preg_match('/^[A-C]\.\s/', $statement) && !preg_match('/^\d+\./', $statement)) {
                $pdf->SetFont('times', 'B', $body_font_size);
                $pdf->SetFillColor(220, 220, 220);
                $pdf->SetX($x_offset);
                
                $text_height = $pdf->getStringHeight($total_table_width_new, $statement);
                $header_height = max(5, $text_height);
                $pdf->Cell($total_table_width_new, $header_height, $statement, 1, 1, 'L', true);
                
                $pdf->SetFont('times', '', $body_font_size);
                $fill = !$fill;
                continue;
            }
            
            // Skip the first title statement
            if ($idx === 0 && $statement === 'Benchmark Statements for Faculty Teaching Effectiveness') {
                continue;
            }
            
            // Definition paragraphs (skip these in the table view)
            if (!preg_match('/^\d+\./', $statement) && strlen($statement) > 50 && strpos($statement, '.') !== false) {
                continue;
            }
            
            // TOTAL SCORE and RATING rows
            if (strpos($statement, 'TOTAL SCORE') !== false || strpos($statement, 'RATING =') !== false) {
                $pdf->SetFont('times', 'B', 9);
                $pdf->SetFillColor(240, 240, 240);
                $pdf->SetX($x_offset);
                
                $text_height = $pdf->getStringHeight($statement_width, $statement);
                $row_height_total = max(5, $text_height);
                
                $start_x = $pdf->GetX();
                $start_y = $pdf->GetY();
                
                // Statement column - leave empty or put a dash
                $pdf->SetXY($start_x, $start_y);
                $pdf->Cell($statement_width, $row_height_total, '', 1, 0, 'C', true);
                
                // Means of Verification column - put the TOTAL SCORE or RATING text here
                $pdf->SetXY($start_x + $statement_width, $start_y);
                $pdf->Cell($mov_width, $row_height_total, $statement, 1, 0, 'R', true);
                
                // Rating column - put the value
                $pdf->SetXY($start_x + $statement_width + $mov_width, $start_y);
                
                $total_score = array_sum($data['ratings']);
                $rating_percentage = ($total_score / 75) * 100;
                $value = (strpos($statement, 'TOTAL SCORE') !== false) ? $total_score . ' / 75' : number_format($rating_percentage, 2) . '%';
                
                $pdf->Cell($rating_width, $row_height_total, $value, 1, 1, 'C', true);
                
                $pdf->SetFont('times', '', 9);
                $fill = !$fill;
                continue;
            }
            
            // Regular numbered statements
            $rating = '';
            if ($rating_index < count($data['ratings']) && preg_match('/^\d+\./', $statement)) {
                $rating = $data['ratings'][$rating_index];
                $rating_index++;
            }
            
            // ADD PAGE BREAK BEFORE STATEMENT 10
            if ($rating_index == 10 && !$page_break_added) {
                $pdf->AddPage();
                $this->addWatermark($pdf);
                
                $current_y = $pdf->GetY();
                $pdf->SetY($current_y + 15);
                $pdf->SetX($x_offset);
                $fill = false;
                $page_break_added = true;
            }
            
            // Get the Means of Verification text based on the statement number
            $movText = $this->getMeansOfVerification($rating_index);
            
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->SetFont('times', '', 9);
            
            $text_height = $pdf->getStringHeight($statement_width, $statement);
            $mov_height = $pdf->getStringHeight($mov_width, $movText);
            $row_height_stmt = max(8, $text_height, $mov_height);
            
            $start_x = $pdf->GetX();
            $start_y = $pdf->GetY();
            
            // Statement cell (Column 1)
            $pdf->SetXY($start_x, $start_y);
            $pdf->Cell($statement_width, $row_height_stmt, '', 1, 0, 'L', $fill);
            $pdf->SetXY($start_x, $start_y);
            $pdf->MultiCell($statement_width, $row_height_stmt, $statement, 0, 'L', false, 0);
            
            // Means of Verification cell (Column 2)
            $pdf->SetXY($start_x + $statement_width, $start_y);
            $pdf->Cell($mov_width, $row_height_stmt, '', 1, 0, 'L', $fill);
            $pdf->SetXY($start_x + $statement_width, $start_y);
            $pdf->MultiCell($mov_width, $row_height_stmt, $movText, 0, 'L', false, 0);
            
            // Rating cell with circles (Column 3)
            $pdf->SetXY($start_x + $statement_width + $mov_width, $start_y);
            $pdf->Cell($rating_width, $row_height_stmt, '', 1, 0, 'C', $fill);
            
            // Draw the rating options (1-5) inside the rating cell with borderlines
            $rating_cell_width = $rating_width / 5;
            for ($i = 5; $i >= 1; $i--) {
                $cell_x = $start_x + $statement_width + $mov_width + ($rating_cell_width * (5 - $i));
                $cell_y = $start_y;
                $circle_center_x = $cell_x + ($rating_cell_width / 2);
                $circle_center_y = $cell_y + ($row_height_stmt / 2);
                
                $pdf->SetXY($cell_x, $cell_y);
                $pdf->Cell($rating_cell_width, $row_height_stmt, (string)$i, 1, 0, 'C', $fill);
                
                if ($rating && round($rating) == $i) {
                    $pdf->SetDrawColor(0, 0, 0);
                    $pdf->SetLineWidth(0.3);
                    $pdf->Circle($circle_center_x, $circle_center_y, 2.5, 0, 360, 'D');
                }
            }
            
            $pdf->SetY($start_y + $row_height_stmt);
            $pdf->SetX($x_offset);
            $fill = !$fill;
        }
        
        // ============================================
        // Other Comments and Suggestions
        // ============================================
        $right_indent = 10;
        $pdf->Ln(2);
        
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', 'B', 9);
        $label_text = 'Other Comments and Suggestions:';
        $label_width = $pdf->GetStringWidth($label_text);
        $pdf->Cell($label_width, 4, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', '', 9);
        
        $available_width = $total_table_width - $right_indent - 20;
        
        $comments = '';
        if (isset($data['comments']) && !empty($data['comments'])) {
            $comments = $data['comments'];
        } elseif (isset($data['comment']) && !empty($data['comment'])) {
            $comments = $data['comment'];
        } elseif (isset($data['suggestions']) && !empty($data['suggestions'])) {
            $comments = $data['suggestions'];
        }
        
        // Draw the three underlined lines first (as background)
        $line_y_positions = [];
        
        for ($i = 0; $i < 3; $i++) {
            $line_y = $pdf->GetY();
            $line_y_positions[] = $line_y;
            
            if ($i === 0) {
                $pdf->SetX($x_offset + $right_indent + $label_width);
                $pdf->Cell($available_width - $label_width, 4, '', 'B', 1);
            } else {
                $pdf->SetX($x_offset + $right_indent);
                $pdf->Cell($total_table_width - $right_indent - 20, 4, '', 'B', 1);
            }
        }
        
        // If there's a comment, overlay it on top of the lines
        if (!empty(trim($comments))) {
            $current_y = $pdf->GetY();
            $pdf->SetFont('times', '', 9);
            $pdf->SetTextColor(0, 0, 0);
            
            $commentText = trim($comments);
            $firstLineWidth = $available_width - $label_width - 4;
            
            $words = explode(' ', $commentText);
            $firstLine = '';
            $remainingText = '';
            
            foreach ($words as $index => $word) {
                $testLine = trim($firstLine . ' ' . $word);
                if ($pdf->GetStringWidth($testLine) <= $firstLineWidth) {
                    $firstLine = $testLine;
                } else {
                    $remainingText = implode(' ', array_slice($words, $index));
                    break;
                }
            }
            
            $pdf->SetY($line_y_positions[0] + 0.2);
            $pdf->SetX($x_offset + $right_indent + $label_width + 2);
            $pdf->Cell($firstLineWidth, 4, $firstLine, 0, 1);
            
            if (!empty($remainingText)) {
                $pdf->SetX($x_offset + $right_indent);
                $pdf->MultiCell($total_table_width - $right_indent - 20, 4, $remainingText, 0, 'L');
            }
            
            $pdf->SetY($current_y);
            $pdf->SetTextColor(0, 0, 0);
        }
        
        $pdf->Ln(2);
        
        // ============================================
        // Signature Section
        // ============================================
        $label_width = 60;
        $line_width = 100;
        $sig_row_height = 3;
        
        // Signature of Evaluator
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', 'B', $body_font_size);
        $pdf->Cell($label_width, $sig_row_height, 'Signature of Evaluator', 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell(3, $sig_row_height, ':', 0, 0, 'R');
        $pdf->Cell($line_width - 3, $sig_row_height, ' ', 'B', 1);
        
        // Name of Evaluator/ID number
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', 'B', $body_font_size);
        $pdf->Cell($label_width, $sig_row_height, 'Name of Evaluator/ID number', 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell(3, $sig_row_height, ':', 0, 0, 'R');
        // $pdf->Cell($line_width - 3, $sig_row_height, ' ' . $data['evaluator_name'] . ' / ' . $data['evaluator_id'], 'B', 1);
        $pdf->Cell($line_width - 3, $sig_row_height, ' ', 'B', 1);

        
        // Date
        $pdf->SetX($x_offset + $right_indent);
        $pdf->SetFont('times', 'B', $body_font_size);
        $pdf->Cell($label_width, $sig_row_height, 'Date', 0, 0, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        $pdf->Cell(3, $sig_row_height, ':', 0, 0, 'R');
        $pdf->Cell($line_width - 3, $sig_row_height, ' ' . $data['date'], 'B', 1);
        
        $pdf->Ln(2);
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