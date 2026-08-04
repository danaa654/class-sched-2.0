<?php

use App\Http\Controllers\AcademicCalendarController;
use App\Http\Controllers\AcademicStructureController;
use App\Http\Controllers\AcademicTermController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CollegeController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\CurriculumSubjectController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\MajorController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SchedulingController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\SemesterController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WelcomeController::class, 'index'])->name('welcome');

// Guest-only authentication routes.
// Registration, password reset, and email verification are intentionally
// NOT included — accounts are created only by the Administrator.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

// Authenticated routes.
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [UsersController::class, 'index'])->name('users');
    Route::post('/users', [UsersController::class, 'store'])->name('users.store');
    Route::get('/academic-calendar', [AcademicCalendarController::class, 'index'])->name('academic-calendar');
    Route::post('/school-years', [SchoolYearController::class, 'store'])->name('school-years.store');
    Route::put('/school-years/{schoolYear}', [SchoolYearController::class, 'update'])->name('school-years.update');
    Route::delete('/school-years/{schoolYear}', [SchoolYearController::class, 'destroy'])->name('school-years.destroy');
    Route::put('/school-years/{schoolYear}/restore', [SchoolYearController::class, 'restore'])->name('school-years.restore');
    Route::post('/semesters', [SemesterController::class, 'store'])->name('semesters.store');
    Route::put('/semesters/{semester}', [SemesterController::class, 'update'])->name('semesters.update');
    Route::delete('/semesters/{semester}', [SemesterController::class, 'destroy'])->name('semesters.destroy');
    Route::put('/semesters/{semester}/restore', [SemesterController::class, 'restore'])->name('semesters.restore');
    Route::post('/academic-terms', [AcademicTermController::class, 'store'])->name('academic-terms.store');
    Route::put('/academic-terms/{academicTerm}', [AcademicTermController::class, 'update'])->name('academic-terms.update');
    Route::delete('/academic-terms/{academicTerm}', [AcademicTermController::class, 'destroy'])->name('academic-terms.destroy');
    Route::put('/academic-terms/{academicTerm}/restore', [AcademicTermController::class, 'restore'])->name('academic-terms.restore');
    Route::get('/academic-structure', [AcademicStructureController::class, 'index'])->name('academic-structure');
    Route::post('/colleges', [CollegeController::class, 'store'])->name('colleges.store');
    Route::put('/colleges/{college}', [CollegeController::class, 'update'])->name('colleges.update');
    Route::delete('/colleges/{college}', [CollegeController::class, 'destroy'])->name('colleges.destroy');
    Route::put('/colleges/{college}/restore', [CollegeController::class, 'restore'])->name('colleges.restore');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::put('/departments/{department}/restore', [DepartmentController::class, 'restore'])->name('departments.restore');
    Route::post('/majors', [MajorController::class, 'store'])->name('majors.store');
    Route::put('/majors/{major}', [MajorController::class, 'update'])->name('majors.update');
    Route::delete('/majors/{major}', [MajorController::class, 'destroy'])->name('majors.destroy');
    Route::put('/majors/{major}/restore', [MajorController::class, 'restore'])->name('majors.restore');
    Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects');
    Route::post('/subjects', [SubjectController::class, 'store'])->name('subjects.store');
    Route::put('/subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
    Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');
    Route::get('/curriculums', [CurriculumController::class, 'index'])->name('curriculums');
    Route::post('/curriculums', [CurriculumController::class, 'store'])->name('curriculums.store');
    Route::put('/curriculums/{curriculum}', [CurriculumController::class, 'update'])->name('curriculums.update');
    Route::delete('/curriculums/{curriculum}', [CurriculumController::class, 'destroy'])->name('curriculums.destroy');
    Route::put('/curriculums/{curriculum}/restore', [CurriculumController::class, 'restore'])->name('curriculums.restore');
    Route::get('/curriculums/{curriculum}/subjects', [CurriculumSubjectController::class, 'index'])->name('curriculums.subjects');
    Route::post('/curriculums/{curriculum}/subjects', [CurriculumSubjectController::class, 'store'])->name('curriculums.subjects.store');
    Route::put('/curriculums/{curriculum}/subjects/{curriculumItem}', [CurriculumSubjectController::class, 'update'])->name('curriculums.subjects.update');
    Route::delete('/curriculums/{curriculum}/subjects/{curriculumItem}', [CurriculumSubjectController::class, 'destroy'])->name('curriculums.subjects.destroy');
    Route::get('/scheduling', [SchedulingController::class, 'index'])->name('scheduling');
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});