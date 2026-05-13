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

Route::get('/', function () {
    return redirect()->route('login');
});

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
