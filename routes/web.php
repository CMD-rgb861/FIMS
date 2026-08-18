<?php

use App\Http\Controllers\AppProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\FEDAController;
use App\Http\Controllers\FacultyEvaluationController;
use App\Http\Controllers\GradesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportEvaluationController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SubjectsController;
use App\Http\Controllers\UnitHeadGradeController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\AnswerController; 
use App\Http\Controllers\Forms\SupervisorEvaluationPDF;
use App\Http\Controllers\Forms\StudentEvaluationPDF;
use App\Http\Controllers\Forms\BatchPDFController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\Forms\IndividualFacultyEvaluationPDF;
use App\Http\Controllers\Forms\FacultyEvaluationDevelopmentAcknowledgmentPDF;
use App\Http\Controllers\IFEController;  // ✅ Add this import
use App\Http\Controllers\SearchController;

Route::get('/sso/validate', [SsoController::class, 'validateToken'])
    ->name('sso.validate');

// ===== EXISTING ROUTES =====
// Route::get('login', function () {
//     return redirect()->away('https://10.10.251.9/ids/fims/home/n');
// })->name('home');

Route::get('login', function () {
    $ip = getHostByName(getHostName());

    return redirect()->away("https://{$ip}/ids/fims/home/n");
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/subjects', [SubjectsController::class, 'index'])->name('subjects');
    Route::get('/evaluation', [EvaluationController::class, 'index'])->name('evaluation');
    Route::post('/evaluations', [FacultyEvaluationController::class, 'store'])->name('evaluations.store');
    Route::get('/grades', [GradesController::class, 'index'])->name('grades');
    Route::post('/unit-head-grades', [UnitHeadGradeController::class, 'store'])->name('unit-head-grades.store');

    Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
    Route::get('/reports/faculty/{instructor}', [ReportsController::class, 'faculty'])->name('reports.faculty');
    Route::get('/reports/faculty/{instructor}/breakdown', [ReportEvaluationController::class, 'breakdown'])->name('reports.faculty.breakdown');
    Route::get('/reports/faculty/subject/{instructor}/{course_code}', [ReportsController::class, 'facultySubjectDetail'])->name('reports.faculty.subject');

    // PDF routes
    Route::get('/supervisor-evaluation/pdf/{id}', [SupervisorEvaluationPDF::class, 'generate'])->name('supervisor.evaluation.pdf');
    Route::post('/student-evaluation/pdf/generate', [StudentEvaluationPDF::class, 'generate'])->name('student.evaluation.pdf.generate');
    Route::post('/student-evaluation/pdf/batch-generate', [BatchPDFController::class, 'generateBatch'])->name('student.evaluation.pdf.batch-generate');
    
    // SEF routes (Supervisor Evaluation)
    Route::get('/sef/faculty/{facultyId}/reports', [SupervisorEvaluationPDF::class, 'getFacultySefData'])->name('sef.faculty.reports');
    Route::post('/sef/pdf/generate', [BatchPDFController::class, 'generateSEF'])->name('sef.pdf.generate');
    Route::post('/sef/batch-reports', [SupervisorEvaluationPDF::class, 'batchReports'])->name('sef.batch-reports');
    
    // PDF display (single route for all PDFs)
    Route::get('/pdf/display/{filename}', [StudentEvaluationPDF::class, 'display'])->name('pdf.display');
    
    // Submission and Answer routes
    Route::get('/submissions', [SubmissionController::class, 'getSubmissions']);
    Route::get('/answers/{submissionId}', [AnswerController::class, 'getAnswers']);
    Route::put('/answers/{submissionId}', [AnswerController::class, 'updateAnswers']);
    Route::post('/answers/batch', [AnswerController::class, 'getBatchAnswers'])->name('answers.batch');

    Route::get('/my-profile', [AppProfileController::class, 'edit'])->name('my-profile.edit');
    Route::put('/my-profile', [AppProfileController::class, 'update'])->name('my-profile.update');
    Route::get('/account-settings', [AppProfileController::class, 'accountSettingsEdit'])->name('account-settings.edit');
    Route::put('/account-settings', [AppProfileController::class, 'accountSettingsUpdate'])->name('account-settings.update');

    // ===== FEDA ROUTES =====
    Route::get('/feda/faculty/{facultyId}/data', [FEDAController::class, 'getFacultyData'])->name('feda.faculty.data');
    
    // FEDA API endpoint to get instructors
    Route::get('/feda/instructors', [FEDAController::class, 'getInstructors'])->name('feda.instructors');
    
    // Save FEDA form data to the database
    Route::post('/feda/save', [FEDAController::class, 'save'])->name('feda.save');
    
    // Get FEDA PDF URL (NEW)
    Route::get('/feda/pdf-url/{facultyId}', [FEDAController::class, 'getPdfUrl'])->name('feda.pdf-url');
    Route::post('/feda/pdf/generate', [BatchPDFController::class, 'generateFEDA'])->name('feda.pdf.generate');//Batch print
    
    // FEDA PDF Generation
    Route::get('/forms/faculty-evaluation-development-acknowledgment-pdf/{id}', [FacultyEvaluationDevelopmentAcknowledgmentPDF::class, 'generate'])->name('feda.form.pdf');
    Route::get('/forms/individual-faculty-evaluation-pdf/{id}', [IndividualFacultyEvaluationPDF::class, 'generate'])->name('individual.faculty.evaluation.pdf');

    // IFE PDF Generation
    Route::post('/individual-faculty-evaluation/pdf/generate', [BatchPDFController::class, 'generateIFE'])->name('individual.faculty.evaluation.pdf.generate');

    // ===== IFE ROUTES =====
    // Get IFE data for a specific faculty
    Route::get('/ife/faculty/{facultyId}', [IFEController::class, 'getFacultyData'])->name('ife.faculty.data');
    
    // Get batch IFE data for multiple faculty
    Route::post('/ife/batch', [IFEController::class, 'batch'])->name('ife.batch');
    
    // Get IFE summary data
    Route::get('/ife/summary', [IFEController::class, 'summary'])->name('ife.summary');

    // ALL Reports Combined (SEF + IFE + FEDA)
    Route::post('/reports/print-all/generate', [BatchPDFController::class, 'generateAll'])->name('reports.print-all.generate');

    // ===== SEARCH ROUTES =====
    Route::post('/search/faculty', [SearchController::class, 'searchFaculty'])->name('search.faculty');
    Route::post('/search/subjects', [SearchController::class, 'searchSubjects'])->name('search.subjects');

    Route::post('logout', [SsoController::class, 'destroy'])->name('logout');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');  
});

