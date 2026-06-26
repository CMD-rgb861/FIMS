<?php

use App\Http\Controllers\AppProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\FacultyEvaluationController;
use App\Http\Controllers\GradesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportEvaluationController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SubjectsController;
use App\Http\Controllers\UnitHeadGradeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\AnswerController; 
use App\Http\Controllers\Forms\SupervisorEvaluationPDF;
use App\Http\Controllers\Forms\StudentEvaluationPDF;
use App\Http\Controllers\Forms\BatchPDFController;
use App\Http\Controllers\Auth\SsoController;

Route::get('/sso/validate', [SsoController::class, 'validateToken'])
    ->name('sso.validate');

// ===== EXISTING ROUTES =====
// Route::get('/', function () {
//     return redirect()->route('login');
// });
Route::get('login', function () {
    return redirect()->away('https://10.5.70.45/ids/fims/home/n');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
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
    Route::post('/sef/pdf/generate', [SupervisorEvaluationPDF::class, 'generate'])->name('sef.pdf.generate');
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
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';