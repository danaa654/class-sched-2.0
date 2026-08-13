<?php

use App\Http\Controllers\AcademicCalendarController;
use App\Http\Controllers\AcademicStructureController;
use App\Http\Controllers\AcademicTermController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\CollegeController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\CurriculumSubjectController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\FacultyAvailabilityController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\MajorController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomRecommendationController;
use App\Http\Controllers\SchedulingController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SectionSubjectController;
use App\Http\Controllers\SemesterController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TeachingQualificationController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WelcomeController::class, 'index'])->name('welcome');

// Guest-only authentication routes.
// Registration and email verification are intentionally NOT included —
// accounts are created only by the Administrator. Password reset IS
// included so users who forget their password can recover access
// without needing an Administrator to reset it for them.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

// Authenticated routes.
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [UsersController::class, 'index'])->name('users');
    Route::post('/users', [UsersController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UsersController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/status', [UsersController::class, 'updateStatus'])->name('users.status');
    Route::delete('/users/{user}', [UsersController::class, 'destroy'])->name('users.destroy');
    Route::put('/account', [UsersController::class, 'updateAccount'])->name('account.update');
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
    Route::get('/scheduling/faculty', [FacultyController::class, 'index'])->name('scheduling.faculty');
    Route::post('/scheduling/faculty', [FacultyController::class, 'store'])->name('scheduling.faculty.store');
    Route::get('/scheduling/faculty/{faculty}', [FacultyController::class, 'show'])->name('scheduling.faculty.show');
    Route::put('/scheduling/faculty/{faculty}', [FacultyController::class, 'update'])->name('scheduling.faculty.update');
    Route::delete('/scheduling/faculty/{faculty}', [FacultyController::class, 'destroy'])->name('scheduling.faculty.destroy');

    Route::post('/scheduling/faculty/{faculty}/availability', [FacultyAvailabilityController::class, 'store'])->name('scheduling.faculty.availability.store');
    Route::put('/scheduling/faculty/{faculty}/availability/{faculty_availability}', [FacultyAvailabilityController::class, 'update'])->name('scheduling.faculty.availability.update');
    Route::delete('/scheduling/faculty/{faculty}/availability/{faculty_availability}', [FacultyAvailabilityController::class, 'destroy'])->name('scheduling.faculty.availability.destroy');
    Route::put('/scheduling/teaching-qualifications/{faculty}', [TeachingQualificationController::class, 'update'])->name('scheduling.teaching-qualifications.update');
    Route::get('/scheduling/rooms', [RoomController::class, 'index'])->name('scheduling.rooms');
    Route::get('/scheduling/rooms/{room}/schedule', [RoomController::class, 'schedule'])->name('scheduling.rooms.schedule');
    Route::post('/scheduling/rooms', [RoomController::class, 'store'])->name('scheduling.rooms.store');
    Route::put('/scheduling/rooms/{room}', [RoomController::class, 'update'])->name('scheduling.rooms.update');
    Route::delete('/scheduling/rooms/{room}', [RoomController::class, 'destroy'])->name('scheduling.rooms.destroy');
    // Room Recommendation — "Recommended Subjects" on the Room Details
    // page (source of truth for room-subject soft preferences, read
    // by RecommendationService::recommendRooms() as a scoring bonus).
    Route::get('/scheduling/rooms/{room}/recommendations', [RoomRecommendationController::class, 'index'])->name('scheduling.rooms.recommendations');
    Route::get('/scheduling/rooms/{room}/recommendations/subjects', [RoomRecommendationController::class, 'searchableSubjects'])->name('scheduling.rooms.recommendations.subjects');
    Route::post('/scheduling/rooms/{room}/recommendations', [RoomRecommendationController::class, 'store'])->name('scheduling.rooms.recommendations.store');
    Route::delete('/scheduling/rooms/{room}/recommendations/{recommendation}', [RoomRecommendationController::class, 'destroy'])->name('scheduling.rooms.recommendations.destroy');
    Route::get('/scheduling/sections', [SectionController::class, 'index'])->name('scheduling.sections');
    Route::post('/scheduling/sections', [SectionController::class, 'store'])->name('scheduling.sections.store');
    Route::post('/scheduling/sections/preview-batch', [SectionController::class, 'previewBatch'])->name('scheduling.sections.preview-batch');
    Route::post('/scheduling/sections/batch', [SectionController::class, 'storeBatch'])->name('scheduling.sections.store-batch');
    Route::put('/scheduling/sections/{section}', [SectionController::class, 'update'])->name('scheduling.sections.update');
    Route::delete('/scheduling/sections/{section}', [SectionController::class, 'destroy'])->name('scheduling.sections.destroy');
    Route::get('/scheduling/section-subjects', [SectionSubjectController::class, 'index'])->name('scheduling.section-subjects');
    Route::get('/scheduling/section-subjects/{section}', [SectionSubjectController::class, 'show'])->name('scheduling.section-subjects.show');
    Route::post('/scheduling/section-subjects/{section}/generate-curriculum', [SectionSubjectController::class, 'generateCurriculumSubjects'])->name('scheduling.section-subjects.generate-curriculum');
    Route::get('/scheduling/section-subjects/{section}/curriculum-preview', [SectionSubjectController::class, 'curriculumPreview'])->name('scheduling.section-subjects.curriculum-preview');
    Route::post('/scheduling/section-subjects/{section}', [SectionSubjectController::class, 'store'])->name('scheduling.section-subjects.store');
    Route::delete('/scheduling/section-subjects/{section}/{subject}', [SectionSubjectController::class, 'destroy'])->name('scheduling.section-subjects.destroy');
    // Inline scheduling-cell auto-save (Faculty/Room/Days/Time/Capacity) — the
    // Section Subjects page (scheduling.section-subjects.show) IS the
    // scheduling workspace; there is no separate workspace page/route.
    Route::patch('/scheduling/section-subjects/{section}/{subject}/schedule', [SectionSubjectController::class, 'updateSchedule'])->name('scheduling.section-subjects.schedule');
    // Smart Assignment Recommendation Engine (Prompt 8.6) — ranked
    // Faculty/Room/Time suggestions for one row, never auto-assigns.
    Route::get('/scheduling/section-subjects/{section}/{subject}/recommend', [SectionSubjectController::class, 'recommend'])->name('scheduling.section-subjects.recommend');
    Route::get('/scheduling/section-subjects/{section}/{subject}/faculty-options', [SectionSubjectController::class, 'facultyOptions'])->name('scheduling.section-subjects.faculty-options');
    Route::post('/scheduling/section-subjects/{section}/{subject}/faculty-override', [SectionSubjectController::class, 'overrideFaculty'])->name('scheduling.section-subjects.faculty-override');
    Route::get('/scheduling/section-subjects/{section}/{subject}/room-options', [SectionSubjectController::class, 'roomOptions'])->name('scheduling.section-subjects.room-options');
    Route::post('/scheduling/section-subjects/{section}/{subject}/room-override', [SectionSubjectController::class, 'overrideRoom'])->name('scheduling.section-subjects.room-override');
    Route::post('/scheduling/section-subjects/{section}/{subject}/time-override', [SectionSubjectController::class, 'overrideTime'])->name('scheduling.section-subjects.time-override');
    // Smart Day & Time Recommendation modal — ranked, conflict-free
    // alternatives when a manually-picked Day/Time fails validation.
    // Reuses RecommendationService::recommendTimes() (the exact same
    // engine Auto Generate itself uses), never a separate simplified
    // algorithm — see SectionSubjectController::timeRecommendations().
    Route::get('/scheduling/section-subjects/{section}/{subject}/time-recommendations', [SectionSubjectController::class, 'timeRecommendations'])->name('scheduling.section-subjects.time-recommendations');

    // INTELLIGENT IRREGULAR SECTION SCHEDULING — merge recommendation
    // modal + Administrator override actions (see IrregularSectionMergeService).
    Route::get('/scheduling/section-subjects/{section}/{subject}/merge-recommendation', [SectionSubjectController::class, 'mergeRecommendation'])->name('scheduling.section-subjects.merge-recommendation');
    Route::post('/scheduling/section-subjects/{section}/{subject}/merge', [SectionSubjectController::class, 'applyMerge'])->name('scheduling.section-subjects.merge');
    Route::post('/scheduling/section-subjects/{section}/{subject}/schedule-independently', [SectionSubjectController::class, 'scheduleIndependently'])->name('scheduling.section-subjects.schedule-independently');

    // ⚡ Auto Generate Schedule (Prompt 8.9).
    Route::post('/scheduling/section-subjects/{section}/auto-generate', [SectionSubjectController::class, 'autoGenerate'])->name('scheduling.section-subjects.auto-generate');
    Route::post('/scheduling/section-subjects/{section}/auto-generate/regenerate', [SectionSubjectController::class, 'regenerateSchedule'])->name('scheduling.section-subjects.auto-generate.regenerate');
    Route::post('/scheduling/section-subjects/{section}/auto-generate/clear', [SectionSubjectController::class, 'clearAutoGenerated'])->name('scheduling.section-subjects.auto-generate.clear');
    // "Save Schedule" batch save — the Registrar edits Faculty/Room/Days/
    // Start/End Time across multiple rows locally in the table, then
    // submits every row at once here; all rows save in a single
    // transaction (Prompt 8.4 — Manual Scheduling Per Subject).
    Route::post('/scheduling/section-subjects/{section}/schedule/batch', [SectionSubjectController::class, 'batchUpdateSchedule'])->name('scheduling.section-subjects.schedule.batch');
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports');

    // SETTINGS — system-wide configuration only (see SettingsController
    // and App\Services\SettingsService). GET renders the page; each PUT
    // saves one tab's group of settings; the POST is a non-destructive
    // "refresh configuration cache" action for Administrators.
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::put('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general.update');
    Route::put('/settings/academic', [SettingsController::class, 'updateAcademic'])->name('settings.academic.update');
    Route::put('/settings/meeting-frequency', [SettingsController::class, 'updateMeetingFrequency'])->name('settings.meeting.update');
    Route::put('/settings/workload', [SettingsController::class, 'updateWorkload'])->name('settings.workload.update');
    Route::put('/settings/rooms', [SettingsController::class, 'updateRooms'])->name('settings.rooms.update');
    Route::put('/settings/auto-schedule', [SettingsController::class, 'updateAutoSchedule'])->name('settings.autoschedule.update');
    Route::put('/settings/irregular', [SettingsController::class, 'updateIrregular'])->name('settings.irregular.update');
    Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
    Route::post('/settings/system/refresh-cache', [SettingsController::class, 'refreshCache'])->name('settings.system.refresh-cache');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// Catch-all for any URL that doesn't match a route above (stale links,
// bookmarks to removed pages, typos, etc). Instead of Laravel's raw 404
// page, send the user somewhere useful: logged-in users go to their
// dashboard, guests go to the login page.
Route::fallback(function () {
    return redirect(auth()->check() ? route('dashboard') : route('login'));
});