<?php
// app\Http\Controllers\Forms\StudentEvaluationPDF.php
namespace App\Http\Controllers\Forms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use TCPDF;

class StudentEvaluationPDF extends Controller
{
    
    public function generate(Request $request)
    {
        // Increase execution limits
        set_time_limit(120); // 2 minutes
        ini_set('memory_limit', '256M');
        
        // Get data from request
        $facultyId = $request->faculty_id;
        $facultyName = $request->faculty_name;
        $term = $request->term;
        $courseCode = $request->course_code;

        // Get faculty college and department
        $faculty = User::with(['college', 'unit'])
            ->where('id_no', $facultyId)
            ->first();

        $collegeName = $faculty?->college?->name ?? '';
        $departmentName = $faculty?->unit?->name ?? '';

        $collegeDepartment = collect([
            $collegeName,
            $departmentName
        ])->filter()->implode(' / ');
        
        // Get Program Level from database
        $dbProgramLevel = $this->getProgramLevel($courseCode, $facultyId, $term);
        
        // Get offset parameters from request (optional, with defaults)
        $x_offset = $request->input('x_offset', 13);
        $y_offset = $request->input('y_offset', 26);
        
        // Prepare data for the template
        $data = [
            'faculty_name' => $facultyName ?? 'Faculty Member',
            'college' => $collegeDepartment,
            'course_code' => $courseCode ?? '',
            'course_title' => $request->course_title ?? '',
            'program_level' => $dbProgramLevel ?? $request->program_level ?? 'Undergraduate',
            'semester' => $term,
            'academic_year' => $request->academic_year ?? date('Y') . '-' . (date('Y') + 1),
            'ratings' => $request->ratings ?? array_fill(0, 15, 4),
            'comments' => $request->comments ?? '',
            'evaluator_name' => $request->evaluator_name ?? 'Student',
            'evaluator_id' => $request->evaluator_id ?? '',
            'date' => isset($student['submitted_at']) ? date('F j, Y', strtotime($student['submitted_at'])) : date('F j, Y')
        ];
        
        // Register Times New Roman fonts
        $this->registerFonts();
        
        // Define benchmark statements
        $statements = $this->getBenchmarkStatements();
        
        // Create PDF document
        $pdf = new TCPDF('P', 'mm', 'LEGAL', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins($x_offset, $y_offset, $x_offset);
        $pdf->SetAutoPageBreak(false, $y_offset);
        $pdf->SetCompression(true);
        
        // Get term details
        $termDetails = $this->getTermDetails($term);
        
        // Add page and generate form
        $pdf->AddPage();
        $this->addWatermark($pdf);
        $this->generateSETForm($pdf, $data, $x_offset, $y_offset, $termDetails, $statements);
        
        // Generate PDF output
        $pdfOutput = $pdf->Output('', 'S');
        
        // Save PDF to temporary storage
        $filename = 'set_' . ($facultyId ?? 'export') . '_' . time() . '.pdf';
        Storage::disk('local')->put('temp/pdf/' . $filename, $pdfOutput);
        
        return response()->json([
            'pdf_url' => route('pdf.display', ['filename' => $filename])
        ]);
    }
    
    /**
     * Get Program Level from enrollment_courses via programs -> program_levels
     */
    private function getProgramLevel($courseCode, $facultyId, $term)
    {
        try {
            // Query enrollment_courses to get program_id
            $enrollment = DB::connection('lnu_poes')
                ->table('enrollment_courses as ec')
                ->where('ec.course_code', $courseCode)
                ->where('ec.id_no', $facultyId)
                ->when($term && $term !== 'null' && $term !== 'undefined', function($query) use ($term) {
                    return $query->where('ec.school_year_id', $term);
                })
                ->first();
            
            if (!$enrollment || !$enrollment->program_id) {
                Log::info('No enrollment found or missing program_id', [
                    'course_code' => $courseCode,
                    'faculty_id' => $facultyId,
                    'term' => $term
                ]);
                return null;
            }
            
            // Get program level name by joining programs and program_levels
            $programLevel = DB::connection('lnu_poes')
                ->table('programs as p')
                ->join('program_levels as pl', 'p.program_level_id', '=', 'pl.id')
                ->where('p.id', $enrollment->program_id)
                ->select('pl.name')
                ->first();
            
            if ($programLevel) {
                Log::info('Program level found', [
                    'program_level' => $programLevel->name,
                    'program_id' => $enrollment->program_id
                ]);
                return $programLevel->name;
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Failed to get program level: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get benchmark statements
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
     * Get term details
     */
    private function getTermDetails($term)
    {
        $semesterDisplay = '';
        $academicYearDisplay = '';
        
        if ($term && $term !== 'null' && $term !== 'undefined') {
            $termData = DB::connection('lnu_poes')
                ->table('school_years')
                ->where('id', $term)
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
            $semesterDisplay = $term ?? 'Current Semester';
            $academicYearDisplay = date('Y') . '-' . (date('Y') + 1);
        }
        
        return [
            'semester_display' => $semesterDisplay,
            'academic_year_display' => $academicYearDisplay
        ];
    }
    
    /**
     * Generate the SET form
     */
    private function generateSETForm($pdf, $data, $x_offset, $y_offset, $termDetails, $statements)
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
        // TITLE
        // ============================================
        $pdf->SetX($x_offset);
        $pdf->SetFont('times', 'B', $title_font_size);
        $pdf->Cell(0, 8, 'STUDENT EVALUATION OF TEACHERS (SET)', 0, 1, 'C');
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
        $pdf->SetFont('times', 'U', $body_font_size);
        $pdf->Cell(0, $row_height, ' ' . $data['course_code'] . ' - ' . $data['course_title'], 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
        // Program Level
        $pdf->SetX($current_x);
        $label_text = 'Program Level:';
        $label_width = $pdf->GetStringWidth($label_text) + 0.5;
        $pdf->Cell($label_width, $row_height, $label_text, 0, 0, 'L');
        $pdf->SetFont('times', 'U', $body_font_size);
        $pdf->Cell(0, $row_height, ' ' . $data['program_level'], 0, 1, 'L');
        $pdf->SetFont('times', '', $body_font_size);
        
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
        $pdf->Cell(0, 4, 'Read the benchmark statements carefully. Please rate the faculty on each of the following', 0, 1, 'L');
        $pdf->SetX($current_x);
        $pdf->Cell(0, 4, 'statements using the above-listed rating scale. Encircle your rating.', 0, 1, 'L');
        $pdf->Ln(1);
        
        // ============================================
        // BENCHMARK STATEMENTS TABLE
        // ============================================
        $rating_index = 0;
        $fill = false;
        
        foreach ($statements as $idx => $statement) {
            if (empty($statement)) {
                $pdf->Ln(1);
                continue;
            }
            
            // Benchmark title row
            if ($idx === 0 && $statement === 'Benchmark Statements for Faculty Teaching Effectiveness') {
                $pdf->SetFont('times', 'B', $body_font_size);
                $pdf->SetFillColor(200, 200, 200);
                $pdf->SetX($x_offset);
                
                $text_height = $pdf->getStringHeight($statement_width, $statement);
                $title_row_height = max(6, $text_height);
                
                $start_x = $pdf->GetX();
                $start_y = $pdf->GetY();
                
                $pdf->SetXY($start_x, $start_y);
                $pdf->Cell($statement_width, $title_row_height, $statement, 1, 0, 'C', true);
                $pdf->SetXY($start_x + $statement_width, $start_y);
                $pdf->SetFont('times', 'B', $body_font_size);
                $pdf->Cell($rating_width, $title_row_height, 'Rating', 1, 1, 'C', true);
                
                $pdf->SetFont('times', '', $body_font_size);
                $fill = !$fill;
                continue;
            }
            
            // Section headers (A., B., C.)
            if (preg_match('/^[A-C]\.\s/', $statement) && !preg_match('/^\d+\./', $statement)) {
                $pdf->SetFont('times', 'B', $body_font_size);
                $pdf->SetFillColor(220, 220, 220);
                $pdf->SetX($x_offset);
                
                $text_height = $pdf->getStringHeight($total_table_width, $statement);
                $header_height = max(5, $text_height);
                $pdf->Cell($total_table_width, $header_height, $statement, 1, 1, 'L', true);
                
                $pdf->SetFont('times', '', $body_font_size);
                $fill = !$fill;
                continue;
            }
            
            // Definition paragraphs
            if (!preg_match('/^\d+\./', $statement) && strlen($statement) > 50 && strpos($statement, '.') !== false) {
                if (strpos($statement, ' refers to ') !== false || strpos($statement, ' refer to ') !== false) {
                    $pdf->SetFillColor(245, 245, 245);
                    $pdf->SetX($x_offset);
                    
                    $separator = (strpos($statement, ' refers to ') !== false) ? ' refers to ' : ' refer to ';
                    $parts = explode($separator, $statement, 2);
                    $title_part = $parts[0];
                    $rest_part = isset($parts[1]) ? $parts[1] : '';
                    $html = '<i>' . htmlspecialchars($title_part) . '</i>' . htmlspecialchars($separator . $rest_part);
                    
                    $text_height = $pdf->getStringHeight($total_table_width - 2, strip_tags($html));
                    $row_height_def = max(8, $text_height + 2);
                    
                    $start_x = $pdf->GetX();
                    $start_y = $pdf->GetY();
                    
                    $pdf->Cell($total_table_width, $row_height_def, '', 1, 0, 'L', true);
                    $pdf->writeHTMLCell($total_table_width - 2, $row_height_def - 1, $start_x + 1, $start_y + 1, $html, 0, 0, false, true, 'L', true);
                    $pdf->SetXY($x_offset, $start_y + $row_height_def);
                    
                    $fill = !$fill;
                    continue;
                }
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
                
                $pdf->SetXY($start_x, $start_y);
                $pdf->Cell($statement_width, $row_height_total, $statement, 1, 0, 'R', true);
                $pdf->SetXY($start_x + $statement_width, $start_y);
                
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
            
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->SetFont('times', '', 9);
            
            $text_height = $pdf->getStringHeight($statement_width, $statement);
            $row_height_stmt = max(5, $text_height);
            
            $start_x = $pdf->GetX();
            $start_y = $pdf->GetY();
            
            $pdf->SetXY($start_x, $start_y);
            $pdf->Cell($statement_width, $row_height_stmt, '', 1, 0, 'L', $fill);
            $pdf->SetXY($start_x, $start_y);
            $pdf->MultiCell($statement_width, $row_height_stmt, $statement, 0, 'L', false, 0);
            $pdf->SetXY($start_x + $statement_width, $start_y);
            
            $rating_width_each = $rating_width / 5;
            $start_x_rating = $pdf->GetX();
            $start_y_rating = $pdf->GetY();
            
            for ($i = 5; $i >= 1; $i--) {
                $cell_x = $start_x_rating + ($rating_width_each * (5 - $i));
                $cell_y = $start_y_rating;
                $circle_center_x = $cell_x + ($rating_width_each / 2);
                $circle_center_y = $cell_y + ($row_height_stmt / 2);
                
                $pdf->SetXY($cell_x, $cell_y);
                $pdf->Cell($rating_width_each, $row_height_stmt, (string)$i, 1, 0, 'C', $fill);
                
                if ($rating == $i) {
                    $pdf->SetDrawColor(0, 0, 0);
                    $pdf->SetLineWidth(0.3);
                    $pdf->Circle($circle_center_x, $circle_center_y, 2.5, 0, 360, 'D');
                }
            }
            
            $pdf->SetY($start_y_rating + $row_height_stmt);
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

        // Get comment from multiple possible keys
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
                // First line begins after the label
                $pdf->SetX($x_offset + $right_indent + $label_width);
                $pdf->Cell(
                    $available_width - $label_width,
                    4,
                    '',
                    'B',
                    1
                );
            } else {
                // Remaining lines span the full comment area
                $pdf->SetX($x_offset + $right_indent);
                $pdf->Cell(
                    $total_table_width - $right_indent - 20,
                    4,
                    '',
                    'B',
                    1
                );
            }
        }

        // If there's a comment, overlay it on top of the lines
        if (!empty(trim($comments))) {
            // Store current position
            $current_y = $pdf->GetY();

            $pdf->SetFont('times', '', 9);
            $pdf->SetTextColor(0, 0, 0);

            $commentText = trim($comments);

            // Width available on first line (after label)
            $firstLineWidth = $available_width - $label_width - 4;

            // Split text into first line and remaining text
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

            // Print first line after the label
            $pdf->SetY($line_y_positions[0] + 0.2);
            $pdf->SetX($x_offset + $right_indent + $label_width + 2);
            $pdf->Cell($firstLineWidth, 4, $firstLine, 0, 1);

            // Print remaining text aligned with the label
            if (!empty($remainingText)) {
                $pdf->SetX($x_offset + $right_indent);
                $pdf->MultiCell(
                    $total_table_width - $right_indent - 20,
                    4,
                    $remainingText,
                    0,
                    'L'
                );
            }

            // Restore position after comment
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
        $pdf->Cell($line_width - 3, $sig_row_height, '', 'B', 1);
        
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