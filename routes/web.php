<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\FacultyEvaluationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UnitHeadGradeController;
use App\Http\Controllers\SubjectsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\GradesController;
use App\Http\Controllers\ReportEvaluationController;
use App\Http\Controllers\ReportsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/my-profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/my-profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/account-settings', [ProfileController::class, 'accountSettings'])->name('account.settings.edit');
    Route::put('/account-settings', [ProfileController::class, 'updateAccountSettings'])->name('account.settings.update');

    Route::get('/subjects', [SubjectsController::class, 'index'])->name('subjects');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/evaluation', [EvaluationController::class, 'index'])->name('evaluation');

    Route::get('/grades', [GradesController::class, 'index'])->name('grades');

    Route::get('/reports', [ReportsController::class, 'index'])->name('reports');

    Route::get('/reports/faculty/{instructor}', [ReportsController::class, 'faculty'])->name('reports.faculty');
    Route::get('/reports/faculty/{instructor}/breakdown', [ReportEvaluationController::class, 'breakdown'])->name('reports.faculty.breakdown');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/evaluations', [FacultyEvaluationController::class, 'store'])->name('evaluations.store');
    Route::post('/unit-head-grades', [UnitHeadGradeController::class, 'store'])->name('unit-head-grades.store');
});
