<?php

namespace Tests\Unit;

use App\Models\AcademicTerm;
use App\Models\College;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Major;
use App\Models\Room;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Subject;
use App\Services\ScheduleConflictService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OVERLAP / CONFLICT-DETECTION LOGIC — direct unit coverage of
 * ScheduleConflictService, calling validate()/findFacultyConflict()/
 * findRoomConflict()/findSectionConflict() straight, with no HTTP
 * round-trip. ScheduleConcurrencyTest (Feature) already proves the
 * end-to-end save pipeline correctly REACHES this service; this file
 * proves the service's own overlap math is correct in every edge
 * case, including the exact-boundary case that has broken before
 * (see the 12:00 PM end-time exclusion bug in project history) — a
 * regression here would previously have gone completely unnoticed
 * since no test exercised the boundary condition directly.
 */
class ScheduleConflictServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleConflictService $service;

    private College $college;

    private Major $major;

    private Room $roomA;

    private Faculty $facultyA;

    private Faculty $facultyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ScheduleConflictService::class);

        $this->college = College::create(['code' => 'CCS', 'name' => 'College of Computer Studies', 'status' => 'Active']);
        $department = Department::create(['college_id' => $this->college->id, 'code' => 'CCS-DEPT', 'name' => 'CCS Department', 'status' => 'Active']);
        $this->major = Major::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'years' => 4, 'status' => 'Active']);

        $this->roomA = Room::create(['room_code' => 'RM-306', 'room_name' => 'Room 306', 'building' => 'Main Building', 'room_type' => 'Lecture', 'capacity' => 40, 'status' => 'Active']);

        $this->facultyA = Faculty::create([
            'faculty_id' => 'F-001', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'employment_type' => 'Full-time', 'college_id' => $this->college->id, 'status' => 'Active',
        ]);
        $this->facultyB = Faculty::create([
            'faculty_id' => 'F-002', 'first_name' => 'Grace', 'last_name' => 'Hopper',
            'employment_type' => 'Full-time', 'college_id' => $this->college->id, 'status' => 'Active',
        ]);
    }

    private function makeSection(string $code = 'BSIT-4A'): Section
    {
        return Section::create([
            'section_code' => $code,
            'section_name' => $code,
            'section_type' => 'Regular',
            'major_id' => $this->major->id,
            'year_level' => 'Fourth Year',
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
            'estimated_students' => 30,
            'status' => 'Active',
        ]);
    }

    private function makeSubject(string $code): Subject
    {
        return Subject::firstOrCreate(
            ['subject_code' => $code],
            [
                'subject_title' => $code,
                'major_id' => $this->major->id,
                'category' => 'Major',
                'subject_type' => 'regular',
                'units' => 3,
                'lecture_hours' => 3,
                'laboratory_hours' => 0,
                'is_active' => true,
            ]
        );
    }

    /**
     * Places an already-scheduled row directly (bypassing the HTTP
     * layer) so each test can set up exactly the "existing"
     * assignment it needs to conflict-check a new slot against.
     */
    private function placeScheduled(
        Section $section,
        string $subjectCode,
        ?Faculty $faculty,
        ?Room $room,
        array $days,
        string $start,
        string $end
    ): SectionSubject {
        return SectionSubject::create([
            'section_id' => $section->id,
            'subject_id' => $this->makeSubject($subjectCode)->id,
            'source' => 'Manual',
            'status' => 'Scheduled',
            'faculty_id' => $faculty?->id,
            'room_id' => $room?->id,
            'days' => implode(',', $days),
            'start_time' => $start,
            'end_time' => $end,
            'hours_confirmed' => true,
        ]);
    }

    private function activateAcademicTerm(string $academicYear = '2026-2027', string $semesterName = '1st Semester'): AcademicTerm
    {
        $schoolYear = SchoolYear::create([
            'name' => $academicYear,
            'start_year' => 2026,
            'end_year' => 2027,
            'status' => 'Active',
        ]);

        $semester = Semester::create(array_merge(
            ['name' => $semesterName],
            Semester::defaultsFor($semesterName),
            ['status' => 'Active'],
        ));

        return AcademicTerm::create([
            'school_year_id' => $schoolYear->id,
            'semester_id' => $semester->id,
            'status' => 'Active',
        ]);
    }

    /* -------------------------------------------------------------
     * TEST 1 — EXACT-BOUNDARY, BACK-TO-BACK CLASSES. One class
     * ending exactly when another begins (10:00-12:00 then
     * 12:00-14:00, same room) must NOT be flagged — this is the
     * regression case for the previously-fixed 12:00 PM end-time
     * exclusion bug. overlaps() uses strict `<`/`>`, so an equal
     * boundary must fall through as non-overlapping.
     * ----------------------------------------------------------- */
    public function test_back_to_back_classes_sharing_a_boundary_do_not_conflict(): void
    {
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');

        $this->placeScheduled($sectionA, 'CAP101', $this->facultyA, $this->roomA, ['Mon'], '10:00', '12:00');

        $errors = $this->service->validate([
            'section_id' => $sectionB->id,
            'faculty_id' => $this->facultyB->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '12:00',
            'end_time' => '14:00',
        ], excludingSectionSubjectId: 0);

        $this->assertSame([], $errors);
    }

    /* -------------------------------------------------------------
     * TEST 2 — PARTIAL OVERLAP must conflict.
     * ----------------------------------------------------------- */
    public function test_partially_overlapping_times_conflict(): void
    {
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');

        $this->placeScheduled($sectionA, 'CAP101', $this->facultyA, $this->roomA, ['Mon'], '10:00', '12:00');

        $errors = $this->service->validate([
            'section_id' => $sectionB->id,
            'faculty_id' => $this->facultyB->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '11:00',
            'end_time' => '13:00',
        ], excludingSectionSubjectId: 0);

        $this->assertArrayHasKey('room_id', $errors);
    }

    /* -------------------------------------------------------------
     * TEST 3 — ONE WINDOW FULLY CONTAINS THE OTHER must conflict,
     * even though neither boundary lines up with the other.
     * ----------------------------------------------------------- */
    public function test_fully_containing_window_conflicts(): void
    {
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');

        $this->placeScheduled($sectionA, 'CAP101', $this->facultyA, $this->roomA, ['Mon'], '08:00', '17:00');

        $errors = $this->service->validate([
            'section_id' => $sectionB->id,
            'faculty_id' => $this->facultyB->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '11:00',
            'end_time' => '12:00',
        ], excludingSectionSubjectId: 0);

        $this->assertArrayHasKey('room_id', $errors);
    }

    /* -------------------------------------------------------------
     * TEST 4 — DIFFERENT DAYS, identical Room and Time, must NOT
     * conflict — sharesDay() requires at least one common Day
     * token.
     * ----------------------------------------------------------- */
    public function test_different_days_same_room_and_time_do_not_conflict(): void
    {
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');

        $this->placeScheduled($sectionA, 'CAP101', $this->facultyA, $this->roomA, ['Mon'], '10:00', '12:00');

        $errors = $this->service->validate([
            'section_id' => $sectionB->id,
            'faculty_id' => $this->facultyB->id,
            'room_id' => $this->roomA->id,
            'days' => ['Tue'],
            'start_time' => '10:00',
            'end_time' => '12:00',
        ], excludingSectionSubjectId: 0);

        $this->assertSame([], $errors);
    }

    /* -------------------------------------------------------------
     * TEST 5 — MULTI-DAY PARTIAL OVERLAP. An existing Mon/Wed class
     * and a proposed Wed/Fri class share only Wed — the conflict
     * must still be caught (proves the Day-matching OR-chain in
     * findOverlap() isn't only checking the first day).
     * ----------------------------------------------------------- */
    public function test_multi_day_lists_with_only_a_partial_day_overlap_still_conflict(): void
    {
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');

        $this->placeScheduled($sectionA, 'CAP101', $this->facultyA, $this->roomA, ['Mon', 'Wed'], '10:00', '12:00');

        $errors = $this->service->validate([
            'section_id' => $sectionB->id,
            'faculty_id' => $this->facultyB->id,
            'room_id' => $this->roomA->id,
            'days' => ['Wed', 'Fri'],
            'start_time' => '10:00',
            'end_time' => '12:00',
        ], excludingSectionSubjectId: 0);

        $this->assertArrayHasKey('room_id', $errors);
    }

    /* -------------------------------------------------------------
     * TEST 6 — DIFFERENT ACADEMIC TERM SCOPE. Faculty/Room conflicts
     * are scoped to the Active Academic Term only (see
     * activeSemesterSectionIds()) — an identical Room/Time/Day
     * booking that belongs to a PAST term's Section must never block
     * a new placement in the current term.
     * ----------------------------------------------------------- */
    public function test_identical_room_and_time_in_a_different_academic_term_does_not_conflict(): void
    {
        // Existing booking sits on a Section from a past term
        // (2025-2026), never included in activeSemesterSectionIds()
        // once 2026-2027 is made Active below.
        $pastSection = Section::create([
            'section_code' => 'BSIT-3A-OLD',
            'section_name' => 'BSIT-3A-OLD',
            'section_type' => 'Regular',
            'major_id' => $this->major->id,
            'year_level' => 'Third Year',
            'academic_year' => '2025-2026',
            'semester' => 'First Semester',
            'estimated_students' => 30,
            'status' => 'Active',
        ]);
        $this->placeScheduled($pastSection, 'CAP101', $this->facultyA, $this->roomA, ['Mon'], '10:00', '12:00');

        // Make 2026-2027 · First Semester the Active term.
        $this->activateAcademicTerm('2026-2027', '1st Semester');

        $currentSection = $this->makeSection('BSIT-4A');

        $errors = $this->service->validate([
            'section_id' => $currentSection->id,
            'faculty_id' => $this->facultyA->id, // same faculty as the past-term booking
            'room_id' => $this->roomA->id, // same room
            'days' => ['Mon'],
            'start_time' => '10:00',
            'end_time' => '12:00',
        ], excludingSectionSubjectId: 0);

        $this->assertSame([], $errors);
    }

    /* -------------------------------------------------------------
     * TEST 7 — SELF-EXCLUSION. Re-validating a row against its own
     * existing Faculty/Room/Time (e.g. re-saving the same values, or
     * checking while editing) must never flag itself as a conflict
     * with itself.
     * ----------------------------------------------------------- */
    public function test_a_row_never_conflicts_with_its_own_existing_placement(): void
    {
        $section = $this->makeSection('BSIT-4A');
        $row = $this->placeScheduled($section, 'CAP101', $this->facultyA, $this->roomA, ['Mon'], '10:00', '12:00');

        $errors = $this->service->validate([
            'section_id' => $section->id,
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '10:00',
            'end_time' => '12:00',
        ], excludingSectionSubjectId: $row->id);

        $this->assertSame([], $errors);
    }

    /* -------------------------------------------------------------
     * TEST 8 — INACTIVE FACULTY blocked outright, before any
     * Day/Time window is even considered.
     * ----------------------------------------------------------- */
    public function test_inactive_faculty_is_blocked_regardless_of_time_slot(): void
    {
        $inactiveFaculty = Faculty::create([
            'faculty_id' => 'F-999', 'first_name' => 'Retired', 'last_name' => 'Professor',
            'employment_type' => 'Full-time', 'college_id' => $this->college->id, 'status' => 'Inactive',
        ]);

        $section = $this->makeSection('BSIT-4A');

        $errors = $this->service->validate([
            'section_id' => $section->id,
            'faculty_id' => $inactiveFaculty->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '10:00',
            'end_time' => '12:00',
        ], excludingSectionSubjectId: 0);

        $this->assertArrayHasKey('faculty_id', $errors);
    }

    /* -------------------------------------------------------------
     * TEST 9 — INACTIVE ROOM blocked outright, same reasoning as
     * inactive Faculty above.
     * ----------------------------------------------------------- */
    public function test_inactive_room_is_blocked_regardless_of_time_slot(): void
    {
        $inactiveRoom = Room::create(['room_code' => 'RM-OLD', 'room_name' => 'Condemned Room', 'building' => 'Main Building', 'room_type' => 'Lecture', 'capacity' => 40, 'status' => 'Inactive']);

        $section = $this->makeSection('BSIT-4A');

        $errors = $this->service->validate([
            'section_id' => $section->id,
            'faculty_id' => $this->facultyA->id,
            'room_id' => $inactiveRoom->id,
            'days' => ['Mon'],
            'start_time' => '10:00',
            'end_time' => '12:00',
        ], excludingSectionSubjectId: 0);

        $this->assertArrayHasKey('room_id', $errors);
    }

    /* -------------------------------------------------------------
     * TEST 10 — LUNCH BREAK (fixed 12:00 PM - 1:00 PM window) blocks
     * any slot that overlaps it in any way, independent of whether
     * an Active School Year exists at all.
     * ----------------------------------------------------------- */
    public function test_a_slot_overlapping_the_lunch_break_is_blocked(): void
    {
        $section = $this->makeSection('BSIT-4A');

        $errors = $this->service->validate([
            'section_id' => $section->id,
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '11:30',
            'end_time' => '12:30',
        ], excludingSectionSubjectId: 0);

        $this->assertArrayHasKey('days', $errors);
        $this->assertStringContainsString('Lunch Break', $errors['days']);
    }

    /* -------------------------------------------------------------
     * TEST 11 — DAY OUTSIDE THE ACADEMIC CALENDAR'S ALLOWED DAYS is
     * blocked once an Active School Year restricts which days are
     * schedulable (e.g. Sunday excluded).
     * ----------------------------------------------------------- */
    public function test_a_day_not_allowed_by_the_active_school_year_is_blocked(): void
    {
        SchoolYear::create([
            'name' => '2026-2027',
            'start_year' => 2026,
            'end_year' => 2027,
            'status' => 'Active',
            'available_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], // no Sunday
        ]);

        $section = $this->makeSection('BSIT-4A');

        $errors = $this->service->validate([
            'section_id' => $section->id,
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Sun'],
            'start_time' => '09:00',
            'end_time' => '11:00',
        ], excludingSectionSubjectId: 0);

        $this->assertArrayHasKey('days', $errors);
        $this->assertStringContainsString('not an allowed class day', $errors['days']);
    }

    /* -------------------------------------------------------------
     * TEST 12 — TIME OUTSIDE THE ACTIVE SCHOOL YEAR'S CONFIGURED
     * CLASS HOURS is blocked.
     * ----------------------------------------------------------- */
    public function test_a_time_outside_configured_class_hours_is_blocked(): void
    {
        SchoolYear::create([
            'name' => '2026-2027',
            'start_year' => 2026,
            'end_year' => 2027,
            'status' => 'Active',
            'class_start_time' => '07:00:00',
            'class_end_time' => '17:00:00',
        ]);

        $section = $this->makeSection('BSIT-4A');

        $errors = $this->service->validate([
            'section_id' => $section->id,
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '18:00',
            'end_time' => '20:00',
        ], excludingSectionSubjectId: 0);

        $this->assertArrayHasKey('days', $errors);
        $this->assertStringContainsString('outside the allowed class hours', $errors['days']);
    }

    /* -------------------------------------------------------------
     * TEST 13 — SLOT SHORTER THAN THE SUBJECT'S REQUIRED DURATION is
     * blocked when the caller supplies expected_minutes.
     * ----------------------------------------------------------- */
    public function test_a_slot_shorter_than_the_required_duration_is_blocked(): void
    {
        $section = $this->makeSection('BSIT-4A');

        $errors = $this->service->validate([
            'section_id' => $section->id,
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '10:00',
            'end_time' => '11:00', // 60 minutes
            'expected_minutes' => 180, // subject needs 3 hours
        ], excludingSectionSubjectId: 0);

        $this->assertArrayHasKey('days', $errors);
        $this->assertStringContainsString('shorter than', $errors['days']);
    }

    /* -------------------------------------------------------------
     * TEST 14 — PRIORITY ORDER. When a slot has BOTH a Faculty
     * conflict AND a Room conflict at once, validate() must report
     * the Faculty conflict first — the fixed priority order
     * documented on validate() itself.
     * ----------------------------------------------------------- */
    public function test_faculty_conflict_is_reported_before_room_conflict_when_both_fail(): void
    {
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');
        $roomB = Room::create(['room_code' => 'RM-307', 'room_name' => 'Room 307', 'building' => 'Main Building', 'room_type' => 'Lecture', 'capacity' => 40, 'status' => 'Active']);

        // facultyA is booked in roomA, Mon 10-12.
        $this->placeScheduled($sectionA, 'CAP101', $this->facultyA, $this->roomA, ['Mon'], '10:00', '12:00');
        // roomB is ALSO booked at the exact same Mon 10-12, by a
        // different faculty — so the proposed slot below collides
        // with BOTH an existing Faculty booking (facultyA, in roomA)
        // and an existing Room booking (roomB, by facultyB) at once.
        $this->placeScheduled($sectionB, 'CAP102', $this->facultyB, $roomB, ['Mon'], '10:00', '12:00');

        $sectionC = $this->makeSection('BSIT-4C');

        $errors = $this->service->validate([
            'section_id' => $sectionC->id,
            'faculty_id' => $this->facultyA->id, // conflicts via facultyA's existing booking
            'room_id' => $roomB->id, // conflicts via roomB's existing booking
            'days' => ['Mon'],
            'start_time' => '10:00',
            'end_time' => '12:00',
        ], excludingSectionSubjectId: 0);

        $this->assertArrayHasKey('faculty_id', $errors);
        $this->assertArrayNotHasKey('room_id', $errors);
    }
}