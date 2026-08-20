<?php
// app\Http\Controllers\Forms\BatchPDFController.php

namespace App\Http\Controllers\Forms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\FacultyDevelopmentForm;
use App\Models\SupervisorEvaluationSubmission;
use TCPDF;
use Carbon\Carbon;

class BatchPDFController extends Controller
{
    /**
     * ================================================================
     * SECTION 1: STUDENT EVALUATION OF TEACHERS (SET) - BATCH GENERATION
     * ================================================================
     * This generates batch PDFs for Student Evaluation of Teachers (SET)
     * Used for: Student evaluation forms for faculty
     * POST /student-evaluation/pdf/batch-generate
     * ================================================================
     */
    public function generateBatch(Request $request)
    {
        // Increase execution limits for large PDFs (50-60 pages)
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '512M');
        
        // Validate request
        $validated = $request->validate([
            'faculty_id' => 'required',
            'faculty_name' => 'required',
            'term' => 'nullable',
            'subjects' => 'required|array',
            'subjects.*.id' => 'required',
            'subjects.*.course_code' => 'required',
            'subjects.*.course_description' => 'required',
            'subjects.*.year_section' => 'required',
            'subjects.*.students' => 'nullable|array',
            'x_offset' => 'nullable|numeric',
            'y_offset' => 'nullable|numeric',
        ]);

        // Convert values to proper types
        $facultyId = (string) $validated['faculty_id'];
        $facultyName = (string) $validated['faculty_name'];
        $term = $validated['term'] ? (string) $validated['term'] : null;
        $subjects = $validated['subjects'];
        $x_offset = $validated['x_offset'] ?? 13;
        $y_offset = $validated['y_offset'] ?? 26;

        // Register Times New Roman fonts (only once)
        $this->registerFonts();

        // Create new PDF document with compression enabled
        $pdf = new TCPDF('P', 'mm', 'LEGAL', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins($x_offset, $y_offset, $x_offset);
        $pdf->SetAutoPageBreak(false, $y_offset);
        $pdf->SetCompression(true); // Enable compression for smaller file size
        
        // Cache the statements array to avoid redeclaring
        $statements = $this->getBenchmarkStatements();
        
        // Cache term details once
        $termDetails = $this->getTermDetails($term);
        
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
        
        // Process each subject and each student submission
        $totalPages = 0;
        $pageData = []; // Store page data for batch processing
        
        // First, collect all page data (faster than generating PDF immediately)
        foreach ($subjects as $subject) {
            $students = $subject['students'] ?? [];
            
            // Get program level for this subject
            $programLevel = $this->getProgramLevel($subject['course_code'], $facultyId, $term) ?? $subject['program_level'] ?? 'Undergraduate';
            
            foreach ($students as $student) {
                // Get ratings for this student
                $ratings = $this->extractRatings($student);
                
                // Prepare data for the template
                $data = [
                    'faculty_name' => $facultyName,
                    'college' => $collegeDepartment,
                    'course_code' => $subject['course_code'],
                    'course_title' => $subject['course_description'],
                    'program_level' => $programLevel,
                    'semester' => $term,
                    'academic_year' => $termDetails['academic_year_display'],
                    'ratings' => $ratings,
                    'comments' => $student['comments'] ?? $student['suggestions'] ?? '',
                    'evaluator_name' => $student['student_name'] ?? 'Student',
                    'evaluator_id' => $student['student_id_number'] ?? $student['student_id'] ?? '',
                    'date' => isset($student['submitted_at']) ? date('F j, Y', strtotime($student['submitted_at'])) : date('F j, Y')
                ];
                
                $pageData[] = [
                    'data' => $data,
                    'subject' => $subject
                ];
                $totalPages++;
            }
        }
        
        // Generate all pages with progress tracking
        $progressStep = max(1, floor($totalPages / 10)); // Show progress every 10%
        $currentProgress = 0;
        
        foreach ($pageData as $index => $page) {
            // Add a new page for this submission
            $pdf->AddPage();
            $this->addWatermark($pdf);
            
            // Generate the form page
            $this->generateSETFormPageOptimized($pdf, $page['data'], $x_offset, $y_offset, $termDetails, $statements);
            
            // Free memory periodically (every 10 pages)
            if (($index + 1) % 10 === 0) {
                $pdf->getPage(); // Force PDF to flush buffers
                gc_collect_cycles(); // Force garbage collection
            }
            
            // Log progress for debugging (optional)
            if (($index + 1) >= $currentProgress + $progressStep) {
                $currentProgress = $index + 1;
                Log::info("PDF Generation Progress: {$currentProgress}/{$totalPages} pages");
            }
        }
        
        // Generate PDF output
        $pdfOutput = $pdf->Output('', 'S');
        
        // Save PDF to temporary storage
        $filename = 'batch_evaluation_' . $facultyId . '_' . time() . '.pdf';
        $filepath = 'temp/pdf/' . $filename;
        Storage::disk('local')->put($filepath, $pdfOutput);
        
        // Get file size for response
        $fileSize = Storage::disk('local')->size($filepath);
        
        return response()->json([
            'success' => true,
            'pdf_url' => route('pdf.display', ['filename' => $filename]),
            'message' => "Batch PDF generated successfully with {$totalPages} pages",
            'total_pages' => $totalPages,
            'total_subjects' => count($subjects),
            'total_students' => $totalPages,
            'file_size_kb' => round($fileSize / 1024, 2)
        ]);
    }

    /**
     * ================================================================
     * SECTION 2: SUPERVISOR EVALUATION OF FACULTY (SEF) - BATCH GENERATION
     * ================================================================
     * This generates batch PDFs for Supervisor Evaluation of Faculty (SEF)
     * Used for: Supervisor/head evaluation forms for faculty
     * POST /sef/pdf/generate
     * ================================================================
     */
    public function generateSEF(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        
        $validated = $request->validate([
            'term_id' => 'required|string',
            'faculty_list' => 'required|array',
            'school_year_label' => 'nullable|string'
        ]);
        
        $termId = $validated['term_id'];
        $facultyList = $validated['faculty_list'];
        $schoolYearLabel = $validated['school_year_label'] ?? '';
        
        // Get the supervisor evaluation controller instance
        $sefController = new SupervisorEvaluationPDF();
        
        // Generate PDF for each faculty and combine
        $pdf = new TCPDF('P', 'mm', 'LEGAL', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(13, 26, 13);
        $pdf->SetAutoPageBreak(false, 26);
        $pdf->SetCompression(true);
        
        // Register fonts
        $sefController->registerFonts();
        
        $totalPages = 0;
        $generatedCount = 0;
        
        // Get benchmark statements and term details
        $statements = $sefController->getBenchmarkStatements();
        $termDetails = $sefController->getTermDetails($termId);
        
        foreach ($facultyList as $facultyData) {
            $facultyId = $facultyData['employee_id_no'] ?? null;
            if (!$facultyId) continue;
            
            // ✅ Get the SEF data properly
            $sefResponse = $sefController->getFacultySefData($facultyId, new Request(['term_id' => $termId]));
            $sefData = $sefResponse->getData(true); // Convert JSON response to array
            
            // ✅ Check if we have data
            if (!$sefData || !($sefData['has_data'] ?? false)) {
                continue;
            }
            
            // Get faculty college info
            $facultyInfo = User::with(['college', 'unit'])
                ->where('id_no', $facultyId)
                ->first();
            
            $collegeName = $facultyInfo?->college?->name ?? '';
            $departmentName = $facultyInfo?->unit?->name ?? '';
            $collegeDepartment = collect([$collegeName, $departmentName])->filter()->implode(' / ');
            
            if (empty($collegeDepartment)) {
                $collegeDepartment = $facultyData['department'] ?? $facultyData['college'] ?? 'College of Arts and Sciences';
            }
            
            // ✅ Get ratings from the SEF data
            $ratings = $sefData['ratings_breakdown'] ?? $sefData['details']['ratings_breakdown'] ?? array_fill(0, 15, 0);
            
            // ✅ Get comments
            $comments = $facultyData['comments'] ?? $sefData['comments'] ?? '';
            
            // Prepare data for the template
            $templateData = [
                'faculty_name' => $facultyData['instructor'] ?? $facultyData['faculty_name'] ?? '',
                'college' => $collegeDepartment,
                'course_code' => $facultyData['course_code'] ?? '',
                'course_title' => $facultyData['course_title'] ?? '',
                'program_level' => $facultyData['program_level'] ?? 'Undergraduate',
                'semester' => $termId,
                'academic_year' => $termDetails['academic_year_display'] ?? '',
                'ratings' => $ratings,
                'comments' => $comments,
                'evaluator_name' => $facultyData['evaluator_name'] ?? 'Supervisor',
                'evaluator_id' => $facultyData['evaluator_id'] ?? '',
                'date' => date('F j, Y')
            ];
            
            // Add page and generate form
            $pdf->AddPage();
            $sefController->addWatermark($pdf);
            $sefController->generateSEFForm($pdf, $templateData, 13, 26, $termDetails, $statements);
            $totalPages++;
            $generatedCount++;
        }
        
        if ($totalPages === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No SEF data found for the selected faculty.'
            ], 404);
        }
        
        // Generate and save PDF
        $pdfOutput = $pdf->Output('', 'S');
        $filename = 'sef_batch_' . time() . '.pdf';
        $filepath = 'temp/pdf/' . $filename;
        Storage::disk('local')->put($filepath, $pdfOutput);
        
        return response()->json([
            'success' => true,
            'pdf_url' => route('pdf.display', ['filename' => $filename]),
            'message' => "SEF report generated successfully",
            'total_pages' => $totalPages,
            'generated_count' => $generatedCount
        ]);
    }

    /**
     * ================================================================
     * SECTION 3: INDIVIDUAL FACULTY EVALUATION (IFE) - BATCH GENERATION
     * ================================================================
     * This generates batch PDFs for Individual Faculty Evaluation (IFE)
     * Used for: Combined SET + SEF evaluation forms for faculty
     * POST /individual-faculty-evaluation/pdf/generate
     * ================================================================
     */
    public function generateIFE(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        
        $validated = $request->validate([
            'term_id' => 'required|string',
            'faculty_list' => 'required|array',
            'school_year_label' => 'nullable|string'
        ]);
        
        $termId = $validated['term_id'];
        $facultyList = $validated['faculty_list'];
        $schoolYearLabel = $validated['school_year_label'] ?? '';
        
        // Use the existing IndividualFacultyEvaluationPDF controller
        $ifeController = new IndividualFacultyEvaluationPDF();
        
        // Register fonts
        $ifeController->registerFonts();
        
        // Create PDF
        $pdf = new TCPDF('P', 'mm', 'LEGAL', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(13, 26, 13);
        $pdf->SetAutoPageBreak(false, 26);
        $pdf->SetCompression(true);
        
        // Get logged-in user for signature
        $loggedInUser = auth()->user();
        $preparedByName = $loggedInUser ? trim(($loggedInUser->firstname ?? '') . ' ' . ($loggedInUser->lastname ?? '')) : 'Staff';
        
        // Get term details
        $termDetails = $ifeController->getTermDetails($termId);
        
        $totalPages = 0;
        $generatedCount = 0;
        
        // Get the IFE Data Controller for SET data
        $dataController = new \App\Http\Controllers\Forms\IFE\IFEDataController();
        
        foreach ($facultyList as $facultyData) {
            $facultyId = $facultyData['employee_id_no'] ?? null;
            if (!$facultyId) continue;
            
            // Get faculty info
            $facultyInfo = User::with(['college', 'unit'])
                ->where('id_no', $facultyId)
                ->first();
            
            $collegeName = $facultyInfo?->college?->name ?? '';
            $departmentName = $facultyInfo?->unit?->name ?? '';
            $academicRank = $facultyInfo?->academic_rank ?? 'N/A';
            $collegeDepartment = collect([$departmentName, $collegeName])->filter()->implode(' / ');
            
            if (empty($collegeDepartment)) {
                $collegeDepartment = $facultyData['department'] ?? $facultyData['college'] ?? 'N/A';
            }
            
            // Get SET data from IFEDataController
            $facultyFullData = $dataController->getFacultyData($facultyId, (int) $termId);
            
            // Get SEF data using batchReports from SupervisorEvaluationPDF
            $sefController = new SupervisorEvaluationPDF();
            $batchRequest = new Request([
                'term_id' => $termId,
                'faculty_ids' => [$facultyId]
            ]);
            $batchResponse = $sefController->batchReports($batchRequest);
            $batchData = $batchResponse->getData(true);
            
            // Check if we have SEF data
            if (!isset($batchData[$facultyId]) || !$batchData[$facultyId]['has_data']) {
                continue;
            }
            
            $sefData = $batchData[$facultyId];
            $sefRatings = $sefData['ratings_breakdown'] ?? array_fill(0, 15, 0);
            $sefComments = $sefData['comments'] ?? '';
            $overallSefRating = $sefData['overall_sef_rating'] ?? null;
            
            // Prepare SET data
            $setData = [
                'rows' => $facultyFullData['set_data']['rows'] ?? [],
                'overall_rating' => $facultyFullData['set_data']['overall_rating'] ?? null,
                'comments' => $facultyFullData['comments']['student'] ?? []
            ];
            
            // Get supervisor comments
            $supervisorComments = $facultyFullData['comments']['supervisor'] ?? [];
            
            // Get dean and associate dean names
            $deanName = $facultyFullData['faculty_info']['dean_name'] ?? '';
            $associateDeanName = $facultyFullData['faculty_info']['associate_dean_name'] ?? '';
            $facultyName = $facultyData['instructor'] ?? $facultyFullData['faculty_info']['name'] ?? 'Faculty Member';
            
            // Generate IFE page
            $pdf->AddPage();
            $ifeController->addWatermark($pdf);
            
            // Prepare data for IFE template (matches IndividualFacultyEvaluationPDF::generateIFEForm)
            $ifeTemplateData = [
                'faculty_name' => $facultyName,
                'college' => $collegeDepartment,
                'academic_rank' => $academicRank,
                'dean_name' => $deanName,
                'associate_dean_name' => $associateDeanName,
                'set_rows' => $setData['rows'],
                'overall_set_rating' => $setData['overall_rating'],
                'overall_sef_rating' => $overallSefRating,
                'student_comments' => $setData['comments'],
                'supervisor_comments' => $supervisorComments,
                'prepared_by' => $preparedByName,
                'term' => $termId,
                'term_details' => $termDetails,
                'date' => date('F j, Y'),
                'ratings_breakdown' => $sefRatings,
                'sef_comments' => $sefComments
            ];
            
            // Use the full IFE template from IndividualFacultyEvaluationPDF
            $ifeController->generateIFEForm($pdf, $ifeTemplateData, 13, 26, $termDetails);
            
            $totalPages++;
            $generatedCount++;
        }
        
        if ($totalPages === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No IFE data found for the selected faculty.'
            ], 404);
        }
        
        $pdfOutput = $pdf->Output('', 'S');
        $filename = 'ife_batch_' . time() . '.pdf';
        $filepath = 'temp/pdf/' . $filename;
        Storage::disk('local')->put($filepath, $pdfOutput);
        
        return response()->json([
            'success' => true,
            'pdf_url' => route('pdf.display', ['filename' => $filename]),
            'message' => "IFE reports generated successfully for {$generatedCount} faculty members",
            'total_pages' => $totalPages,
            'generated_count' => $generatedCount
        ]);
    }

    /**
     * ================================================================
     * SECTION 4: FACULTY EVALUATION DEVELOPMENT ACKNOWLEDGMENT (FEDA) - BATCH GENERATION
     * ================================================================
     * This generates batch PDFs for Faculty Evaluation Development Acknowledgment (FEDA)
     * Used for: Final acknowledgment forms requiring SET + SEF + FEDA submitted
     * POST /feda/pdf/generate
     * ================================================================
     */
    public function generateFEDA(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        
        $validated = $request->validate([
            'term_id' => 'required|string',
            'faculty_list' => 'required|array',
            'school_year_label' => 'nullable|string'
        ]);
        
        $termId = $validated['term_id'];
        $facultyList = $validated['faculty_list'];
        $schoolYearLabel = $validated['school_year_label'] ?? '';
        
        $pdf = new TCPDF('P', 'mm', 'LEGAL', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(13, 26, 13);
        $pdf->SetAutoPageBreak(false, 26);
        $pdf->SetCompression(true);
        
        $this->registerFonts();
        
        // Get controllers
        $dataController = new \App\Http\Controllers\Forms\IFE\IFEDataController();
        $sefController = new SupervisorEvaluationPDF();
        $fedaFormController = new FacultyEvaluationDevelopmentAcknowledgmentPDF();
        
        // Get logged-in user for program head name
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
                $programHeadName = $evaluatorNameDisplay;
            }
        }
        
        // Get term details
        $termDetails = $this->getTermDetails($termId);
        
        $totalPages = 0;
        $generatedCount = 0;
        
        foreach ($facultyList as $facultyData) {
            $facultyId = $facultyData['employee_id_no'] ?? null;
            if (!$facultyId) continue;
            
            // ✅ CHECK FEDA SUBMISSION FROM DATABASE
            $fedaSubmitted = \App\Models\FacultyDevelopmentForm::hasSubmittedFormFor($facultyId, $termId);
            if (!$fedaSubmitted) {
                Log::info("FEDA not submitted for faculty {$facultyId} in term {$termId}");
                continue;
            }
            
            // ✅ FETCH FEDA DEVELOPMENT PLAN FROM DATABASE
            $fedaForm = \App\Models\FacultyDevelopmentForm::where('id_no', $facultyId)
                ->where('term_id', $termId)
                ->first();
            
            // ✅ Build the development plan array
            $developmentPlan = [
                'areas_for_improvement' => $fedaForm?->areas_for_improvement ?? '',
                'proposed_activities' => $fedaForm?->proposed_learning_and_development_activities ?? '',
                'action_plan' => $fedaForm?->action_plan ?? ''
            ];
            
            Log::info("FEDA Development Plan for {$facultyId}", $developmentPlan);
            
            // Get data using IFEDataController
            $facultyFullData = $dataController->getFacultyData($facultyId, (int) $termId);
            
            // Get SEF data
            $sefRequest = new Request(['term_id' => $termId]);
            $sefResponse = $sefController->getFacultySefData($facultyId, $sefRequest);
            $sefData = $sefResponse->getData(true);
            
            // Get faculty info for college
            $facultyInfo = User::with(['college', 'unit'])
                ->where('id_no', $facultyId)
                ->first();
            
            $collegeName = $facultyInfo?->college?->name ?? '';
            $departmentName = $facultyInfo?->unit?->name ?? '';
            $academicRank = $facultyInfo?->academic_rank ?? '';
            $collegeDepartment = collect([$departmentName, $collegeName])->filter()->implode(' / ');
            
            if (empty($collegeDepartment)) {
                $collegeDepartment = $facultyData['department'] ?? $facultyData['college'] ?? 'College of Arts and Sciences';
            }
            
            // Get overall SET rating
            $overallSetRating = $fedaFormController->getFacultyOverallSetRating(
                $facultyId, 
                $facultyData['instructor'] ?? '', 
                (int) $termId
            );
            
            // Get overall SEF rating
            $overallSefRating = $fedaFormController->getFacultyOverallSefRating($facultyId, (int) $termId);
            
            // Use SEF data if available, otherwise use fallback
            if ($sefData && ($sefData['has_data'] ?? false)) {
                $sefRatings = $sefData['ratings_breakdown'] ?? array_fill(0, 15, 0);
                $sefComments = $sefData['comments'] ?? '';
                $overallSefRating = $sefData['overall_sef_rating'] ?? $overallSefRating;
            } else {
                // Use ratings from faculty data if provided
                $sefRatings = $facultyData['ratings_breakdown'] ?? array_fill(0, 15, 0);
                $sefComments = $facultyData['comments'] ?? '';
            }
            
            // ✅ CRITICAL FIX: Build the data array with 'development_plan' key
            $pdfData = [
                'faculty_name' => $facultyData['instructor'] ?? $facultyFullData['faculty_info']['name'] ?? 'Faculty Member',
                'faculty_id_no' => $facultyId,
                'college' => $collegeDepartment,
                'academic_rank' => $academicRank,
                'evaluator_name' => 'Supervisor',
                'evaluator_id' => '',
                'ratings' => $sefRatings,  // ✅ This should be 'ratings' not 'sef_ratings'
                'comments' => $sefComments,
                'overall_set_rating' => $overallSetRating,
                'overall_sef_rating' => $overallSefRating,
                // ✅ DEVELOPMENT PLAN MUST BE UNDER 'development_plan' KEY
                'development_plan' => $developmentPlan,  // ✅ This is the key change!
                'program_head_name' => $programHeadName,
                'evaluator_name_display' => $evaluatorNameDisplay,
                'term' => $termId,
                'school_year_label' => $schoolYearLabel,
                'date' => Carbon::now()->format('F j, Y'),
                'term_details' => $termDetails
            ];
            
            // Log the data to verify
            Log::info("FEDA PDF Data for {$facultyId}", [
                'development_plan' => $pdfData['development_plan'],
                'overall_set_rating' => $pdfData['overall_set_rating'],
                'overall_sef_rating' => $pdfData['overall_sef_rating']
            ]);
            
            // Generate FEDA page
            $pdf->AddPage();
            $this->addWatermark($pdf);
            
            // ✅ Use the FEDA form generation method
            $fedaFormController->generateFEDAForm($pdf, $pdfData, 13, 26, $termDetails);
            
            $totalPages++;
            $generatedCount++;
        }
        
        if ($totalPages === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No FEDA data found for the selected faculty.'
            ], 404);
        }
        
        $pdfOutput = $pdf->Output('', 'S');
        $filename = 'feda_batch_' . time() . '.pdf';
        $filepath = 'temp/pdf/' . $filename;
        Storage::disk('local')->put($filepath, $pdfOutput);
        
        return response()->json([
            'success' => true,
            'pdf_url' => route('pdf.display', ['filename' => $filename]),
            'message' => "FEDA reports generated successfully for {$generatedCount} faculty members",
            'total_pages' => $totalPages,
            'generated_count' => $generatedCount
        ]);
    }

    /**
     * ================================================================
     * SECTION 5: ALL REPORTS COMBINED (SEF + IFE + FEDA) - BATCH GENERATION
     * ================================================================
     * This generates a single PDF containing SEF, IFE, and FEDA for each faculty
     * Used for: Complete evaluation package for faculty
     * POST /reports/print-all/generate
     * ================================================================
     */
    public function generateAll(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        
        $validated = $request->validate([
            'term_id' => 'required|string',
            'faculty_list' => 'required|array',
            'school_year_label' => 'nullable|string'
        ]);
        
        $termId = $validated['term_id'];
        $facultyList = $validated['faculty_list'];
        $schoolYearLabel = $validated['school_year_label'] ?? '';
        
        $pdf = new TCPDF('P', 'mm', 'LEGAL', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(13, 26, 13);
        $pdf->SetAutoPageBreak(false, 26);
        $pdf->SetCompression(true);
        
        $this->registerFonts();
        
        // Get controllers - we'll use their full template methods
        $dataController = new \App\Http\Controllers\Forms\IFE\IFEDataController();
        $sefController = new SupervisorEvaluationPDF();
        $ifeController = new IndividualFacultyEvaluationPDF();
        $fedaController = new FacultyEvaluationDevelopmentAcknowledgmentPDF();
        
        // Get logged-in user for signatures
        $loggedInUser = auth()->user();
        $preparedByName = 'Staff';

        if ($loggedInUser) {
            $firstName = trim($loggedInUser->firstname ?? '');
            $lastName = trim($loggedInUser->lastname ?? '');
            $extName = trim($loggedInUser->extname ?? ''); 
            
            $middleName = trim($loggedInUser->middlename ?? ''); 
            $middleInitial = '';
            if (!empty($middleName)) {
                $middleInitial = mb_strtoupper(mb_substr($middleName, 0, 1)) . '.';
            }
            
            $nameParts = array_filter([$firstName, $middleInitial, $lastName, $extName]);
            $preparedByName = implode(' ', $nameParts);
            
            if (empty($preparedByName)) {
                $preparedByName = $loggedInUser->name ?? 'Staff';
            }
        }

        $programHeadName = $preparedByName;
        $evaluatorNameDisplay = $preparedByName;
        
        // Get term details
        $termDetails = $this->getTermDetails($termId);
        
        // Get benchmark statements for SEF
        $sefStatements = $this->getBenchmarkStatements();
        
        $totalPages = 0;
        $processedFaculty = 0;
        
        foreach ($facultyList as $facultyData) {
            $facultyId = $facultyData['employee_id_no'] ?? null;
            if (!$facultyId) continue;
            
            // Get data using IFEDataController
            $facultyFullData = $dataController->getFacultyData($facultyId, (int) $termId);
            
            // Get SEF data using batchReports
            $batchRequest = new Request([
                'term_id' => $termId,
                'faculty_ids' => [$facultyId]
            ]);
            $batchResponse = $sefController->batchReports($batchRequest);
            $batchData = $batchResponse->getData(true);
            
            // Check if we have SEF data
            if (!isset($batchData[$facultyId]) || !$batchData[$facultyId]['has_sef_data']) {
                continue;
            }

            $submission = SupervisorEvaluationSubmission::with('user')
                ->where('instructor_id_no', $facultyId)
                ->where('term_id', $termId)
                ->latest('submitted_at')
                ->first();

            $actualEvaluatorName = 'Supervisor';
            $actualEvaluatorId = '';
            
            if ($submission && $submission->user) {
                $user = $submission->user;
                
                $firstName = trim($user->firstname ?? '');
                $lastName = trim($user->lastname ?? '');
                
                // Check for extname (e.g., Jr., Sr., III) - adjust the column name if your DB uses 'extension' or 'suffix'
                $extName = trim($user->extname ?? ''); 
                
                // Get the first letter of the middle name and append a period
                $middleName = trim($user->middlename ?? ''); // adjust to 'middle_name' if that is your DB column
                $middleInitial = '';
                if (!empty($middleName)) {
                    $middleInitial = mb_strtoupper(mb_substr($middleName, 0, 1)) . '.';
                }
                
                // Combine the parts, filtering out any empty strings to avoid double spaces
                $nameParts = array_filter([$firstName, $middleInitial, $lastName, $extName]);
                $actualEvaluatorName = implode(' ', $nameParts);
                
                // Fallback just in case all fields are completely blank
                if (empty($actualEvaluatorName)) {
                    $actualEvaluatorName = $user->name ?? 'Supervisor';
                }
                
                $actualEvaluatorId = $user->id_no ?? '';
            }
            
            // Get the SEF data for this faculty
            $sefData = $batchData[$facultyId];
            
            // Check FEDA status
            $fedaSubmitted = $sefData['feda_submitted'] ?? false;
            
            // Get faculty info
            $facultyInfo = User::with(['college', 'unit'])
                ->where('id_no', $facultyId)
                ->first();
            
            $collegeName = $facultyInfo?->college?->name ?? '';
            $departmentName = $facultyInfo?->unit?->name ?? '';
            $academicRank = $facultyInfo?->academic_rank ?? '';
            $collegeDepartment = collect([$departmentName, $collegeName])->filter()->implode(' / ');
            
            if (empty($collegeDepartment)) {
                $collegeDepartment = $facultyData['department'] ?? $facultyData['college'] ?? 'College of Arts and Sciences';
            }
            
            // Prepare SET data for IFE
            $setData = [
                'rows' => $facultyFullData['set_data']['rows'] ?? [],
                'overall_rating' => $facultyFullData['set_data']['overall_rating'] ?? null,
                'comments' => $facultyFullData['comments']['student'] ?? []
            ];
            
            // Prepare SEF comments
            $sefComments = $facultyFullData['comments']['supervisor'] ?? [];
            
            // Get ratings breakdown from batch data
            $sefRatings = $sefData['ratings_breakdown'] ?? array_fill(0, 15, 0);
            $sefComments = $sefData['comments'] ?? '';
            
            // ============================================
            // 1. SEF REPORT - Use the FULL template from SupervisorEvaluationPDF
            // ============================================
            $pdf->AddPage();
            $this->addWatermark($pdf);
            
            // Prepare data for SEF template
            $sefTemplateData = [
                'faculty_name' => $facultyData['instructor'] ?? $facultyFullData['faculty_info']['name'] ?? '',
                'college' => $collegeDepartment,
                'course_code' => $facultyData['course_code'] ?? '',
                'course_title' => $facultyData['course_title'] ?? '',
                'program_level' => $facultyData['program_level'] ?? '',
                'semester' => $termId,
                'academic_year' => $termDetails['academic_year_display'],
                'ratings' => $sefRatings,
                'comments' => $sefComments,
                'evaluator_name' => $actualEvaluatorName,
                'evaluator_id' => $actualEvaluatorId,
                'date' => Carbon::now()->format('F j, Y')
            ];
            
            // Use the full SEF template from SupervisorEvaluationPDF
            $sefController->generateSEFForm($pdf, $sefTemplateData, 13, 26, $termDetails, $sefStatements);
            $totalPages++;
            
            // ============================================
            // 2. IFE REPORT - Use the FULL template from IndividualFacultyEvaluationPDF
            // ============================================
            if (!empty($facultyFullData)) {
                $pdf->AddPage();
                $this->addWatermark($pdf);
                
                // Prepare data for IFE template
                $ifeTemplateData = [
                    'faculty_name' => $facultyData['instructor'] ?? $facultyFullData['faculty_info']['name'] ?? '',
                    'college' => $collegeDepartment,
                    'academic_rank' => $academicRank,
                    'dean_name' => $facultyFullData['faculty_info']['dean_name'] ?? '',
                    'associate_dean_name' => $facultyFullData['faculty_info']['associate_dean_name'] ?? '',
                    'set_rows' => $setData['rows'],
                    'overall_set_rating' => $setData['overall_rating'],
                    'overall_sef_rating' => $sefData['overall_sef_rating'] ?? null,
                    'student_comments' => $setData['comments'],
                    'supervisor_comments' => $sefComments,
                    'prepared_by' => $preparedByName,
                    'semester' => $termId,
                    'academic_year' => $termDetails['academic_year_display'],
                    'date' => Carbon::now()->format('F j, Y'),
                    'term_details' => $termDetails
                ];
                
                // Use the full IFE template from IndividualFacultyEvaluationPDF
                $ifeController->generateIFEForm($pdf, $ifeTemplateData, 13, 26, $termDetails);
                $totalPages++;
            }
            
            // ============================================
            // 3. FEDA REPORT - Use the FULL template from FacultyEvaluationDevelopmentAcknowledgmentPDF
            // ============================================
            if ($fedaSubmitted) {
                $pdf->AddPage();
                $this->addWatermark($pdf);
                
                // ✅ FETCH DEVELOPMENT PLAN FROM DATABASE
                // Fetch development plan from faculty_development_forms using fims connection
                $developmentPlan = [
                    'areas_for_improvement' => '',
                    'proposed_activities' => '',
                    'action_plan' => ''
                ];

                try {
                    $fedaForm = \App\Models\FacultyDevelopmentForm::where('id_no', $facultyId)
                        ->where('term_id', $termId)
                        ->first();
                    
                    if ($fedaForm) {
                        $developmentPlan = [
                            'areas_for_improvement' => $fedaForm->areas_for_improvement ?? '',
                            'proposed_activities' => $fedaForm->proposed_learning_and_development_activities ?? '',
                            'action_plan' => $fedaForm->action_plan ?? ''
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to fetch FEDA development plan: ' . $e->getMessage());
                }
                
                // Also try to get from the faculty data if provided from frontend
                if (empty($developmentPlan['areas_for_improvement']) && !empty($facultyData['areas_for_improvement'])) {
                    $developmentPlan['areas_for_improvement'] = $facultyData['areas_for_improvement'];
                }
                if (empty($developmentPlan['proposed_activities']) && !empty($facultyData['proposed_activities'])) {
                    $developmentPlan['proposed_activities'] = $facultyData['proposed_activities'];
                }
                if (empty($developmentPlan['action_plan']) && !empty($facultyData['action_plan'])) {
                    $developmentPlan['action_plan'] = $facultyData['action_plan'];
                }
                
                // Prepare data for FEDA template
                $fedaTemplateData = [
                    'faculty_name' => $facultyData['instructor'] ?? $facultyFullData['faculty_info']['name'] ?? '',
                    'faculty_id_no' => $facultyId,
                    'college' => $collegeDepartment,
                    'academic_rank' => $academicRank,
                    'course_code' => $facultyData['course_code'] ?? '',
                    'course_title' => $facultyData['course_title'] ?? '',
                    'program_level' => $facultyData['program_level'] ?? '',
                    'semester' => $termId,
                    'academic_year' => $termDetails['academic_year_display'],
                    'ratings' => $sefRatings,
                    'comments' => $sefComments,
                    'evaluator_name' => $actualEvaluatorName,
                    'evaluator_id' => $actualEvaluatorId,
                    'date' => Carbon::now()->format('F j, Y'),
                    'development_plan' => $developmentPlan,  // ✅ Now has data
                    'program_head_name' => $programHeadName,
                    'evaluator_name_display' => $evaluatorNameDisplay,
                    'overall_set_rating' => $setData['overall_rating'] !== null ? number_format($setData['overall_rating'], 2) : 'N/A',
                    'overall_sef_rating' => $sefData['overall_sef_rating'] !== null ? number_format($sefData['overall_sef_rating'], 2) : 'N/A',
                ];
                
                // Log the data for debugging
                Log::info('FEDA Template Data', [
                    'faculty_name' => $fedaTemplateData['faculty_name'],
                    'development_plan' => $fedaTemplateData['development_plan'],
                    'overall_set_rating' => $fedaTemplateData['overall_set_rating'],
                    'overall_sef_rating' => $fedaTemplateData['overall_sef_rating']
                ]);
                
                // Use the full FEDA template from FacultyEvaluationDevelopmentAcknowledgmentPDF
                $fedaController->generateFEDAForm($pdf, $fedaTemplateData, 13, 26, $termDetails);
                $totalPages++;
            }
            $processedFaculty++;
        }
        
        if ($totalPages === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No report data found for the selected faculty.'
            ], 404);
        }
        
        $pdfOutput = $pdf->Output('', 'S');
        $filename = 'all_reports_batch_' . time() . '.pdf';
        $filepath = 'temp/pdf/' . $filename;
        Storage::disk('local')->put($filepath, $pdfOutput);
        
        return response()->json([
            'success' => true,
            'pdf_url' => route('pdf.display', ['filename' => $filename]),
            'message' => "All reports generated successfully for {$processedFaculty} faculty members",
            'total_pages' => $totalPages,
            'processed_faculty' => $processedFaculty
        ]);
    }

    /**
     * ================================================================
     * SECTION 6: HELPER METHODS
     * ================================================================
     */

    /**
     * Check if FEDA is submitted for a faculty
     * Used by: generateFEDA() and generateAll()
     */
    private function checkFedaSubmitted($facultyId, $termId)
    {
        try {
            // Use the model's built-in method that checks submitted_at
            return \App\Models\FacultyDevelopmentForm::hasSubmittedFormFor($facultyId, $termId);
        } catch (\Exception $e) {
            Log::error('Failed to check FEDA submission: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate SEF form page (Simple version for batch)
     * Used by: generateSEF() and generateAll()
     */
    private function generateSEFFormPage($pdf, $data)
    {
        $pdf->SetFont('times', 'B', 14);
        $pdf->Cell(0, 10, 'SUPERVISOR EVALUATION OF FACULTY (SEF)', 0, 1, 'C');
        $pdf->Ln(5);
        
        $pdf->SetFont('times', '', 11);
        $pdf->Cell(40, 6, 'Faculty: ' . $data['faculty_name'], 0, 1);
        $pdf->Cell(40, 6, 'College/Department: ' . $data['college'], 0, 1);
        $pdf->Cell(40, 6, 'Evaluator: ' . $data['evaluator_name'] . ' (' . $data['evaluator_id'] . ')', 0, 1);
        $pdf->Cell(40, 6, 'Date: ' . $data['date'], 0, 1);
        $pdf->Ln(5);
        
        // Ratings table
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(120, 6, 'Benchmark Statements', 1, 0, 'C');
        $pdf->Cell(60, 6, 'Rating', 1, 1, 'C');
        
        $pdf->SetFont('times', '', 9);
        $statements = [
            '1. Demonstrates mastery of subject matter',
            '2. Organizes content logically',
            '3. Uses effective teaching strategies',
            '4. Engages students in learning',
            '5. Provides constructive feedback',
            '6. Shows enthusiasm for teaching',
            '7. Communicates clearly',
            '8. Uses appropriate assessment methods',
            '9. Creates positive learning environment',
            '10. Shows respect for students',
            '11. Is accessible for consultation',
            '12. Updates course materials',
            '13. Uses technology effectively',
            '14. Encourages critical thinking',
            '15. Promotes independent learning'
        ];
        
        foreach ($statements as $index => $statement) {
            $rating = $data['ratings'][$index] ?? '-';
            $pdf->Cell(120, 5, $statement, 1);
            $pdf->Cell(60, 5, $rating, 1, 1, 'C');
        }
        
        $pdf->Ln(5);
        $pdf->SetFont('times', '', 10);
        $pdf->MultiCell(0, 5, 'Comments: ' . ($data['comments'] ?? ''));
    }

    /**
     * Generate IFE full page (Matches your IndividualFacultyEvaluationPDF layout)
     * Used by: generateIFE() and generateAll()
     */
    private function generateIFEFullPage($pdf, $data)
    {
        // This is a simplified version - you should copy the full IFE layout
        // from your IndividualFacultyEvaluationPDF::generateIFEForm() method
        $pdf->SetFont('times', 'B', 14);
        $pdf->Cell(0, 10, 'INDIVIDUAL FACULTY EVALUATION (IFE)', 0, 1, 'C');
        $pdf->Ln(5);
        
        $pdf->SetFont('times', '', 11);
        $pdf->Cell(40, 6, 'Faculty: ' . $data['faculty_name'], 0, 1);
        $pdf->Cell(40, 6, 'College/Department: ' . $data['college'], 0, 1);
        $pdf->Cell(40, 6, 'Academic Rank: ' . $data['academic_rank'], 0, 1);
        $pdf->Cell(40, 6, 'Date: ' . $data['date'], 0, 1);
        $pdf->Ln(5);
        
        // SET Rating Summary
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 6, 'Student Evaluation of Teaching (SET) Ratings:', 0, 1);
        $pdf->SetFont('times', '', 9);
        
        $setRows = $data['set_rows'] ?? [];
        if (!empty($setRows)) {
            $pdf->Cell(30, 5, 'Course Code', 1);
            $pdf->Cell(25, 5, 'Year/Section', 1);
            $pdf->Cell(25, 5, 'Students', 1);
            $pdf->Cell(30, 5, 'Avg Rating', 1);
            $pdf->Cell(35, 5, 'Weighted Score', 1, 1);
            
            foreach ($setRows as $row) {
                $pdf->Cell(30, 5, $row['course_code'] ?? '', 1);
                $pdf->Cell(25, 5, $row['year_section'] ?? '', 1);
                $pdf->Cell(25, 5, $row['student_count'] ?? '', 1);
                $pdf->Cell(30, 5, $row['avg_set_rating'] ?? '', 1);
                $pdf->Cell(35, 5, $row['weighted_score'] ?? '', 1, 1);
            }
        }
        
        $pdf->Ln(3);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(0, 5, 'Overall SET Rating: ' . ($data['overall_set_rating'] ?? 'N/A'), 0, 1);
        $pdf->Cell(0, 5, 'Overall SEF Rating: ' . ($data['overall_sef_rating'] ?? 'N/A'), 0, 1);
        
        $pdf->Ln(3);
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 6, 'Comments:', 0, 1);
        $pdf->SetFont('times', '', 9);
        
        $studentComments = $data['student_comments'] ?? [];
        if (!empty($studentComments)) {
            foreach ($studentComments as $comment) {
                $pdf->MultiCell(0, 4, '- ' . ($comment['comment'] ?? ''), 0, 'L');
            }
        }
        
        $supervisorComments = $data['supervisor_comments'] ?? [];
        if (!empty($supervisorComments)) {
            $pdf->Ln(2);
            $pdf->SetFont('times', 'B', 10);
            $pdf->Cell(0, 5, 'Supervisor Comments:', 0, 1);
            $pdf->SetFont('times', '', 9);
            foreach ($supervisorComments as $comment) {
                $pdf->MultiCell(0, 4, '- ' . ($comment['comment'] ?? ''), 0, 'L');
            }
        }
        
        // Signature section
        $pdf->Ln(5);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(0, 6, 'Prepared by: ' . $data['prepared_by'], 0, 1);
        $pdf->Cell(0, 6, 'Date: ' . $data['date'], 0, 1);
    }

    /**
     * Generate FEDA full page (Matches your FacultyEvaluationDevelopmentAcknowledgmentPDF layout)
     * Used by: generateFEDA() and generateAll()
     */
    private function generateFEDAFullPage($pdf, $data)
    {
        // This is a simplified version - you should copy the full FEDA layout
        // from your FacultyEvaluationDevelopmentAcknowledgmentPDF::generateFEDAForm() method
        $pdf->SetFont('times', 'B', 14);
        $pdf->Cell(0, 10, 'FACULTY EVALUATION AND DEVELOPMENT ACKNOWLEDGMENT (FEDA)', 0, 1, 'C');
        $pdf->Ln(5);
        
        $pdf->SetFont('times', '', 11);
        $pdf->Cell(40, 6, 'Faculty: ' . $data['faculty_name'], 0, 1);
        $pdf->Cell(40, 6, 'College/Department: ' . $data['college'], 0, 1);
        $pdf->Cell(40, 6, 'Academic Rank: ' . $data['academic_rank'], 0, 1);
        $pdf->Cell(40, 6, 'Date: ' . $data['date'], 0, 1);
        $pdf->Ln(5);
        
        // Evaluation Summary
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 6, 'Evaluation Summary:', 0, 1);
        $pdf->SetFont('times', '', 10);
        $pdf->Cell(60, 5, 'Overall SET Rating:', 0);
        $pdf->Cell(60, 5, number_format($data['overall_set_rating'] ?? 0, 2) . '%', 0, 1);
        $pdf->Cell(60, 5, 'Overall SEF Rating:', 0);
        $pdf->Cell(60, 5, number_format($data['overall_sef_rating'] ?? 0, 2) . '%', 0, 1);
        
        $pdf->Ln(5);
        
        // Development Plan
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 6, 'Development Plan:', 0, 1);
        $pdf->SetFont('times', '', 10);
        
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(0, 5, 'Areas for Improvement:', 0, 1);
        $pdf->SetFont('times', '', 10);
        $pdf->MultiCell(0, 5, $data['areas_for_improvement'] ?? '');
        
        $pdf->Ln(2);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(0, 5, 'Proposed Activities:', 0, 1);
        $pdf->SetFont('times', '', 10);
        $pdf->MultiCell(0, 5, $data['proposed_activities'] ?? '');
        
        $pdf->Ln(2);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(0, 5, 'Action Plan:', 0, 1);
        $pdf->SetFont('times', '', 10);
        $pdf->MultiCell(0, 5, $data['action_plan'] ?? '');
        
        // Signature section
        $pdf->Ln(5);
        $pdf->SetFont('times', 'B', 10);
        $pdf->Cell(0, 6, 'Program Head/Supervisor: ' . $data['program_head_name'], 0, 1);
        $pdf->Cell(0, 6, 'Faculty Signature: _________________________', 0, 1);
        $pdf->Cell(0, 6, 'Date: _________________________', 0, 1);
    }

    /**
     * Get Program Level from enrollment_courses via programs -> program_levels
     * Used by: generateBatch() - SET
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
                return $programLevel->name;
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Failed to get program level: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get cached benchmark statements
     * Used by: generateBatch() - SET
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
     * Get term details with caching
     * Used by: generateBatch() - SET
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
     * Extract ratings from student data (optimized)
     * Used by: generateBatch() - SET
     */
    private function extractRatings($student)
    {
        $ratings = [];
        
        // Try to get ratings from various possible formats
        if (isset($student['ratings']) && is_array($student['ratings'])) {
            $ratings = $student['ratings'];
        } elseif (isset($student['rating_details']) && is_array($student['rating_details'])) {
            $ratings = $student['rating_details'];
        } elseif (isset($student['answers']) && is_array($student['answers'])) {
            foreach ($student['answers'] as $answer) {
                if (isset($answer['rating'])) {
                    $ratings[] = (int) $answer['rating'];
                }
            }
        } elseif (isset($student['rating_percentage'])) {
            $percentage = floatval($student['rating_percentage']);
            $averageRating = ($percentage / 100) * 5;
            $ratings = array_fill(0, 15, round($averageRating));
        }
        
        // Ensure we have exactly 15 ratings (optimized)
        $ratingCount = count($ratings);
        if ($ratingCount < 15) {
            $ratings = array_pad($ratings, 15, 4);
        } elseif ($ratingCount > 15) {
            $ratings = array_slice($ratings, 0, 15);
        }
        
        return $ratings;
    }
    
    /**
     * Generate a single SET form page (optimized version)
     * Used by: generateBatch() - SET
     */
    private function generateSETFormPageOptimized($pdf, $data, $x_offset, $y_offset, $termDetails, $statements)
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
     * Used by: All generate methods
     */
    private function registerFonts()
    {
        static $fontsRegistered = false;
        
        if ($fontsRegistered) {
            return; // Skip if already registered
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
     * Add watermark to PDF page (optimized with static cache)
     * Used by: All generate methods
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
     * Used by: All generate methods to display the PDF
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