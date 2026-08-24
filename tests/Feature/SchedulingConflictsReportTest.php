<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Major;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use App\Models\User;
use App\Services\ReportsService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SCHEDULING CONFLICTS REPORT — Status column.
 *
 * ReportsService::schedulingConflicts() always reflects the exact
 * conflict state on disk (it never checks *_confirmed at all for
 * Faculty/Room/Section rows — those are hard-blocked at save time and
 * can never legitimately exist as "acknowledged"). Hours Mismatch
 * rows are the one type that CAN be saved via "Save Anyway"
 * (hours_confirmed), so those rows now carry a Status of
 * 'Acknowledged' or 'Unresolved' depending on that column — added so
 * a Registrar auditing this report later can tell a knowingly-
 * accepted mismatch apart from one nobody has looked at yet.
 *
 * These bypass the HTTP layer's own warning-confirmation gate
 * (covered by ScheduleWarningConfirmationTest) and write the
 * SectionSubject rows directly, since this file is only testing what
 * the REPORT does with rows that already exist in a given state.
 */
class SchedulingConflictsReportTest extends TestCase
{
    use RefreshDatabase;

    private ReportsService $reports;

    private College $college;

    private Major $major;

    private Faculty $faculty;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->reports = app(ReportsService::class);

        $this->college = College::create(['code' => 'CCS', 'name' => 'College of Computer Studies', 'status' => 'Active']);
        $department = Department::create(['college_id' => $this->college->id, 'code' => 'CCS-DEPT', 'name' => 'CCS Department', 'status' => 'Active']);
        $this->major = Major::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'years' => 4, 'status' => 'Active']);

        $this->faculty = Faculty::create([
            'faculty_id' => 'F-001', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'employment_type' => 'Full-time', 'college_id' => $this->college->id, 'status' => 'Active',
        ]);
        $this->room = Room::create(['room_code' => 'RM-306', 'room_name' => 'Room 306', 'building' => 'Main', 'room_type' => 'Lecture', 'capacity' => 40, 'status' => 'Active']);
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

    private function makeSubject(string $code = 'CAP102'): Subject
    {
        return Subject::firstOrCreate(
            ['subject_code' => $code],
            [
                'subject_title' => $code,
                'major_id' => $this->major->id,
                'category' => 'Major',
                'subject_type' => 'regular',
                'units' => 3,
                'lecture_hours' => 3, // requires 3 hrs/week
                'laboratory_hours' => 0,
                'is_active' => true,
            ]
        );
    }

    /** A scheduled-but-mismatched row, written directly (bypassing the HTTP save-time warning gate). */
    private function placeMismatchedSubject(Section $section, bool $hoursConfirmed, string $subjectCode = 'CAP102'): SectionSubject
    {
        return SectionSubject::create([
            'section_id' => $section->id,
            'subject_id' => $this->makeSubject($subjectCode)->id,
            'source' => 'Manual',
            'status' => 'Scheduled',
            'faculty_id' => $this->faculty->id,
            'room_id' => $this->room->id,
            'days' => 'Mon',
            'start_time' => '08:00',
            'end_time' => '09:00', // 1 hr/week vs. the 3 hrs/week required — a mismatch either way
            'hours_confirmed' => $hoursConfirmed,
        ]);
    }

    private function rowsFor(array $report): \Illuminate\Support\Collection
    {
        return collect($report['rows']);
    }

    public function test_unconfirmed_hours_mismatch_is_reported_as_unresolved(): void
    {
        $section = $this->makeSection();
        $this->placeMismatchedSubject($section, hoursConfirmed: false);

        $report = $this->reports->generate('scheduling_conflicts', [
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
        ]);

        $row = $this->rowsFor($report)->firstWhere('Conflict Type', 'Hours Mismatch');
        $this->assertNotNull($row);
        $this->assertSame('Unresolved', $row['Status']);
    }

    public function test_confirmed_hours_mismatch_is_reported_as_acknowledged(): void
    {
        $section = $this->makeSection();
        $this->placeMismatchedSubject($section, hoursConfirmed: true);

        $report = $this->reports->generate('scheduling_conflicts', [
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
        ]);

        $row = $this->rowsFor($report)->firstWhere('Conflict Type', 'Hours Mismatch');
        $this->assertNotNull($row);
        $this->assertSame('Acknowledged', $row['Status']);
    }

    public function test_hours_mismatch_still_appears_when_acknowledged_not_hidden(): void
    {
        // "Save Anyway" only silences the warning in the scheduling
        // UI — the report must still surface it (just labeled
        // differently), never drop it entirely. See ReportsService's
        // own docblock on this: acknowledging doesn't make it "not a
        // conflict".
        $section = $this->makeSection();
        $this->placeMismatchedSubject($section, hoursConfirmed: true);

        $report = $this->reports->generate('scheduling_conflicts', [
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
        ]);

        $this->assertCount(1, $this->rowsFor($report)->where('Conflict Type', 'Hours Mismatch'));
    }

    public function test_faculty_double_booking_is_always_unresolved_regardless_of_hours_confirmed(): void
    {
        // Faculty/Room/Section conflicts have no confirmable "Save
        // Anyway" anywhere in the app (SectionSubjectController hard-
        // blocks them with a 422) — hours_confirmed being true on one
        // of the two colliding rows must have no bearing on their
        // Status at all.
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');

        SectionSubject::create([
            'section_id' => $sectionA->id,
            'subject_id' => $this->makeSubject('CAP102')->id,
            'source' => 'Manual',
            'status' => 'Scheduled',
            'faculty_id' => $this->faculty->id,
            'room_id' => $this->room->id,
            'days' => 'Mon',
            'start_time' => '13:00',
            'end_time' => '16:00', // matches CAP102's 3 hrs/week exactly, no Hours Mismatch here
            'hours_confirmed' => true,
        ]);
        SectionSubject::create([
            'section_id' => $sectionB->id,
            'subject_id' => $this->makeSubject('IAS102')->id,
            'source' => 'Manual',
            'status' => 'Scheduled',
            'faculty_id' => $this->faculty->id, // same faculty, overlapping time
            'room_id' => $this->room->id,
            'days' => 'Mon',
            'start_time' => '14:00',
            'end_time' => '17:00',
            'hours_confirmed' => true,
        ]);

        $report = $this->reports->generate('scheduling_conflicts', [
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
        ]);

        $facultyConflict = $this->rowsFor($report)->firstWhere('Conflict Type', 'Faculty');
        $this->assertNotNull($facultyConflict);
        $this->assertSame('Unresolved', $facultyConflict['Status']);
    }

    public function test_no_hours_mismatch_row_when_scheduled_hours_match_requirement(): void
    {
        $section = $this->makeSection();
        SectionSubject::create([
            'section_id' => $section->id,
            'subject_id' => $this->makeSubject('CAP102')->id,
            'source' => 'Manual',
            'status' => 'Scheduled',
            'faculty_id' => $this->faculty->id,
            'room_id' => $this->room->id,
            'days' => 'Mon',
            'start_time' => '13:00',
            'end_time' => '16:00', // exactly 3 hrs/week
            'hours_confirmed' => false,
        ]);

        $report = $this->reports->generate('scheduling_conflicts', [
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
        ]);

        $this->assertCount(0, $this->rowsFor($report)->where('Conflict Type', 'Hours Mismatch'));
    }
}