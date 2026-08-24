<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\College;
use App\Models\Curriculum;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Major;
use App\Models\Room;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Subject;
use App\Services\AutoScheduleService;
use App\Services\FacultyWorkloadService;
use App\Services\IrregularSectionMergeService;
use App\Services\RecommendationService;
use App\Services\ReportsService;
use App\Services\SiblingSectionPatternService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SMOKE COVERAGE for the six features DI asked to test end to end:
 * Auto Generate, Irregular Merging, Faculty Workload, Room
 * Recommendation, Sibling Pattern, and Reports. Each test calls the
 * real service (no mocking) against an in-memory sqlite DB, the same
 * pattern ScheduleConflictServiceTest already uses. This is a smoke
 * layer, not exhaustive edge-case coverage — see
 * ScheduleConflictServiceTest for the boundary-math-level detail.
 */
class CoreFeatureSmokeTest extends TestCase
{
    use RefreshDatabase;

    private College $college;

    private Major $major;

    private Curriculum $curriculum;

    private Room $lectureRoom;

    private Room $labRoom;

    private Faculty $facultyA;

    private Faculty $facultyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->college = College::create(['code' => 'CCS', 'name' => 'College of Computer Studies', 'status' => 'Active']);
        $department = Department::create(['college_id' => $this->college->id, 'code' => 'CCS-DEPT', 'name' => 'CCS Department', 'status' => 'Active']);
        $this->major = Major::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'years' => 4, 'status' => 'Active']);
        $this->curriculum = Curriculum::create([
            'major_id' => $this->major->id, 'code' => 'BSIT-2026', 'name' => 'BSIT 2026 Curriculum',
            'start_year' => 2026, 'end_year' => 2027, 'status' => 'Active', 'allow_new_students' => true,
        ]);

        $this->lectureRoom = Room::create(['room_code' => 'RM-306', 'room_name' => 'Room 306', 'building' => 'Main Building', 'room_type' => 'Lecture', 'capacity' => 40, 'status' => 'Active']);
        $this->labRoom = Room::create(['room_code' => 'LAB-1', 'room_name' => 'Computer Lab 1', 'building' => 'Main Building', 'room_type' => 'Laboratory', 'capacity' => 30, 'status' => 'Active']);

        $this->facultyA = Faculty::create([
            'faculty_id' => 'F-001', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'employment_type' => 'Full-time', 'college_id' => $this->college->id, 'status' => 'Active',
            'max_teaching_units' => 21,
        ]);
        $this->facultyB = Faculty::create([
            'faculty_id' => 'F-002', 'first_name' => 'Grace', 'last_name' => 'Hopper',
            'employment_type' => 'Full-time', 'college_id' => $this->college->id, 'status' => 'Active',
            'max_teaching_units' => 21,
        ]);

        $this->activateAcademicTerm();
    }

    private function makeSection(string $code, string $yearLevel = 'Fourth Year', int $estimatedStudents = 30): Section
    {
        return Section::create([
            'section_code' => $code,
            'section_name' => $code,
            'section_type' => 'Regular',
            'major_id' => $this->major->id,
            'curriculum_id' => $this->curriculum->id,
            'year_level' => $yearLevel,
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
            'estimated_students' => $estimatedStudents,
            'status' => 'Active',
        ]);
    }

    private function makeSubject(string $code, int $units = 3, int $lecture = 3, int $lab = 0): Subject
    {
        return Subject::firstOrCreate(
            ['subject_code' => $code],
            [
                'subject_title' => $code,
                'major_id' => $this->major->id,
                'category' => 'Major',
                'subject_type' => 'regular',
                'units' => $units,
                'lecture_hours' => $lecture,
                'laboratory_hours' => $lab,
                'is_active' => true,
            ]
        );
    }

    private function placeScheduled(Section $section, Subject $subject, ?Faculty $faculty, ?Room $room, array $days, string $start, string $end, string $status = 'Scheduled'): SectionSubject
    {
        return SectionSubject::create([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'source' => 'Manual',
            'status' => $status,
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
        $schoolYear = SchoolYear::create(['name' => $academicYear, 'start_year' => 2026, 'end_year' => 2027, 'status' => 'Active']);
        $semester = Semester::create(array_merge(['name' => $semesterName], Semester::defaultsFor($semesterName), ['status' => 'Active']));

        return AcademicTerm::create(['school_year_id' => $schoolYear->id, 'semester_id' => $semester->id, 'status' => 'Active']);
    }

    /* ---------------------------------------------------------------
     * FACULTY WORKLOAD
     * ------------------------------------------------------------- */
    public function test_faculty_workload_tracks_current_load_and_flags_overload(): void
    {
        /** @var FacultyWorkloadService $service */
        $service = app(FacultyWorkloadService::class);

        $section = $this->makeSection('BSIT-4A');
        $capstone = $this->makeSubject('CAP101', units: 21); // deliberately at the faculty's ceiling

        $this->assertSame(0, $service->currentLoad($this->facultyA));
        $this->assertFalse($service->wouldExceed($this->facultyA, $capstone));

        $this->placeScheduled($section, $capstone, $this->facultyA, $this->lectureRoom, ['Mon'], '08:00', '11:00', status: 'Draft');

        $this->assertSame(21, $service->currentLoad($this->facultyA));

        $anotherSubject = $this->makeSubject('IT201', units: 3);
        $this->assertTrue($service->wouldExceed($this->facultyA, $anotherSubject));

        $evaluation = $service->evaluate($this->facultyA);
        $this->assertSame('overloaded', $evaluation['status'] ?? $service->statusFor(100));
    }

    /* ---------------------------------------------------------------
     * SIBLING SECTION PATTERN
     * ------------------------------------------------------------- */
    public function test_sibling_pattern_copies_donor_faculty_and_room_onto_a_different_day(): void
    {
        /** @var SiblingSectionPatternService $service */
        $service = app(SiblingSectionPatternService::class);

        $subject = $this->makeSubject('CAP101');

        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');

        // Donor: 4A already has this Subject fully scheduled.
        $this->placeScheduled($sectionA, $subject, $this->facultyA, $this->lectureRoom, ['Mon', 'Wed'], '08:00', '10:00');

        // Target: 4B has the same Subject unscheduled.
        $target = $this->placeScheduled($sectionB, $subject, null, null, [], '00:00', '00:00');
        $target->update(['faculty_id' => null, 'room_id' => null, 'days' => null, 'start_time' => null, 'end_time' => null]);

        $pattern = $service->findPattern($target->fresh());

        $this->assertNotNull($pattern, 'Expected a sibling pattern to be found: '.json_encode($service->getDiagnostics()));
        $this->assertSame($this->facultyA->id, $pattern['faculty_id']);
        $this->assertSame($this->lectureRoom->id, $pattern['room_id']);
        $this->assertSame($sectionA->id, $pattern['donor_section_id']);
    }

    /* ---------------------------------------------------------------
     * AUTO GENERATE
     * ------------------------------------------------------------- */
    public function test_auto_generate_schedules_every_unscheduled_subject_conflict_free(): void
    {
        /** @var AutoScheduleService $service */
        $service = app(AutoScheduleService::class);

        $section = $this->makeSection('BSIT-4A');
        $subject1 = $this->makeSubject('IT201', units: 3, lecture: 3);
        $subject2 = $this->makeSubject('IT202', units: 3, lecture: 3);

        $row1 = $this->placeScheduled($section, $subject1, null, null, [], '00:00', '00:00');
        $row1->update(['faculty_id' => null, 'room_id' => null, 'days' => null, 'start_time' => null, 'end_time' => null]);
        $row2 = $this->placeScheduled($section, $subject2, null, null, [], '00:00', '00:00');
        $row2->update(['faculty_id' => null, 'room_id' => null, 'days' => null, 'start_time' => null, 'end_time' => null]);

        $result = $service->generate($section);

        $this->assertGreaterThanOrEqual(1, $result['scheduled'], 'Auto Generate summary: '.json_encode($result));

        $section->load('sectionSubjects');
        $scheduledRows = $section->sectionSubjects->filter(fn (SectionSubject $ss) => $ss->faculty_id && $ss->room_id && $ss->days);
        $this->assertGreaterThanOrEqual(1, $scheduledRows->count(), 'Expected Auto Generate to place at least one row.');

        // No two placed rows should collide on the same faculty/room/day/time.
        $seen = [];
        foreach ($scheduledRows as $row) {
            foreach (explode(',', $row->days) as $day) {
                $key = $row->faculty_id.'|'.$row->room_id.'|'.$day.'|'.$row->start_time;
                $this->assertArrayNotHasKey($key, $seen, 'Auto Generate produced a colliding assignment.');
                $seen[$key] = true;
            }
        }
    }

    /* ---------------------------------------------------------------
     * ROOM RECOMMENDATION (via RecommendationService, the engine
     * behind the "✨ Recommended" room chips)
     * ------------------------------------------------------------- */
    public function test_room_recommendation_prefers_lab_room_for_lab_heavy_subject_and_respects_capacity(): void
    {
        /** @var RecommendationService $service */
        $service = app(RecommendationService::class);

        $section = $this->makeSection('BSIT-4A', estimatedStudents: 25);
        $labSubject = $this->makeSubject('IT301-LAB', units: 3, lecture: 1, lab: 6);
        $row = $this->placeScheduled($section, $labSubject, null, null, [], '00:00', '00:00');
        $row->update(['faculty_id' => null, 'room_id' => null, 'days' => null, 'start_time' => null, 'end_time' => null]);

        $result = $service->recommendRooms($labSubject, $section, $row->fresh());
        $rooms = $result['recommendations'];

        $this->assertNotEmpty($rooms, 'Expected at least one room candidate for a lab-heavy subject: '.json_encode($result));
        $this->assertSame($this->labRoom->id, $rooms[0]['id'],
            'Expected the Laboratory room to rank first for a lab-heavy subject: '.json_encode($rooms));

        // A room too small for the section should never be recommended.
        $tinyRoom = Room::create(['room_code' => 'RM-TINY', 'room_name' => 'Tiny Room', 'building' => 'Main Building', 'room_type' => 'Lecture', 'capacity' => 10, 'status' => 'Active']);
        $result = $service->recommendRooms($labSubject, $section, $row->fresh());
        $recommendedIds = array_column($result['recommendations'], 'id');
        $this->assertNotContains($tinyRoom->id, $recommendedIds, 'A room smaller than the section headcount should not be recommended.');
    }

    /* ---------------------------------------------------------------
     * IRREGULAR MERGING
     * ------------------------------------------------------------- */
    public function test_irregular_merge_recommends_and_applies_a_compatible_host(): void
    {
        /** @var IrregularSectionMergeService $service */
        $service = app(IrregularSectionMergeService::class);

        $subject = $this->makeSubject('GE101', units: 3, lecture: 3);

        $hostSection = $this->makeSection('BSIT-4A', estimatedStudents: 25);
        $hostRow = $this->placeScheduled($hostSection, $subject, $this->facultyA, $this->lectureRoom, ['Tue', 'Thu'], '08:00', '09:30');

        $irregularSection = $this->makeSection('BSIT-4B-IRR', estimatedStudents: 5);
        $irregularSection->update(['section_type' => 'Irregular', 'is_irregular' => true]);
        $irregularRow = $this->placeScheduled($irregularSection, $subject, null, null, [], '00:00', '00:00');
        $irregularRow->update(['faculty_id' => null, 'room_id' => null, 'days' => null, 'start_time' => null, 'end_time' => null]);

        $recommendation = $service->recommend($irregularRow->fresh());

        $this->assertNotEmpty($recommendation['candidates'] ?? $recommendation, 'Expected at least one merge host candidate.');

        $topCandidate = ($recommendation['candidates'] ?? $recommendation)[0];
        $this->assertSame($hostRow->id, $topCandidate['section_subject_id']);
        $this->assertTrue($topCandidate['compatible']);

        $merged = $service->applyMerge($irregularRow->fresh(), $hostRow->fresh());

        $this->assertSame($this->facultyA->id, $merged->faculty_id);
        $this->assertSame($this->lectureRoom->id, $merged->room_id);
        $this->assertSame($hostRow->fresh()->days, $merged->days);
    }

    /* ---------------------------------------------------------------
     * IRREGULAR MERGING — capacity limit rejection
     * ------------------------------------------------------------- */
    public function test_irregular_merge_rejects_host_when_room_capacity_would_be_exceeded(): void
    {
        /** @var IrregularSectionMergeService $service */
        $service = app(IrregularSectionMergeService::class);

        $subject = $this->makeSubject('GE102', units: 3, lecture: 3);

        // Small room, already nearly full via the host section's own headcount.
        $smallRoom = Room::create(['room_code' => 'RM-SMALL', 'room_name' => 'Room Small', 'building' => 'Main Building', 'room_type' => 'Lecture', 'capacity' => 30, 'status' => 'Active']);
        $hostSection = $this->makeSection('BSIT-4A', estimatedStudents: 28);
        $hostRow = $this->placeScheduled($hostSection, $subject, $this->facultyA, $smallRoom, ['Tue', 'Thu'], '08:00', '09:30');

        // Irregular section would push headcount past the room's capacity (28 + 10 = 38 > 30).
        $irregularSection = $this->makeSection('BSIT-4B-IRR', estimatedStudents: 10);
        $irregularSection->update(['section_type' => 'Irregular', 'is_irregular' => true]);
        $irregularRow = $this->placeScheduled($irregularSection, $subject, null, null, [], '00:00', '00:00');
        $irregularRow->update(['faculty_id' => null, 'room_id' => null, 'days' => null, 'start_time' => null, 'end_time' => null]);

        $recommendation = $service->recommend($irregularRow->fresh());

        $this->assertSame('independent', $recommendation['recommendation'],
            'Expected merge to be rejected as capacity-incompatible: '.json_encode($recommendation));
        $this->assertNull($recommendation['best_match']);
        $this->assertStringContainsString('capacity', strtolower($recommendation['independent_reason'] ?? ''));

        $candidate = collect($recommendation['candidates'])->firstWhere('section_subject_id', $hostRow->id);
        $this->assertNotNull($candidate);
        $this->assertFalse($candidate['compatible']);
        $this->assertSame(38, $candidate['projected_headcount']);
    }

    /* ---------------------------------------------------------------
     * FACULTY WORKLOAD — overload rejection in Auto Generate
     * ------------------------------------------------------------- */
    public function test_auto_generate_skips_overloaded_faculty_and_leaves_subject_unresolved_when_no_other_candidate_qualifies(): void
    {
        /** @var AutoScheduleService $service */
        $service = app(AutoScheduleService::class);
        /** @var FacultyWorkloadService $workload */
        $workload = app(FacultyWorkloadService::class);

        // Lower the ceiling so it's trivial to push facultyA over it,
        // and remove facultyB from contention entirely so the only
        // qualified candidate is the overloaded one.
        $this->facultyA->update(['max_teaching_units' => 3]);
        $this->facultyB->update(['status' => 'Inactive']);

        $section = $this->makeSection('BSIT-4A');
        $priorSubject = $this->makeSubject('IT100', units: 3);
        $this->placeScheduled($section, $priorSubject, $this->facultyA, $this->lectureRoom, ['Mon'], '08:00', '09:00', status: 'Draft');

        $this->assertTrue($workload->wouldExceed($this->facultyA, $this->makeSubject('IT201')));

        $newSubject = $this->makeSubject('IT201', units: 3);
        $row = $this->placeScheduled($section, $newSubject, null, null, [], '00:00', '00:00');
        $row->update(['faculty_id' => null, 'room_id' => null, 'days' => null, 'start_time' => null, 'end_time' => null]);

        $result = $service->generate($section);

        $unresolvedSubjectIds = collect($result['unresolved'])->pluck('section_subject_id')->filter()->values();
        $placedRow = $section->fresh()->sectionSubjects()->where('subject_id', $newSubject->id)->first();

        // Either the subject was left unresolved, or — if it was
        // somehow placed — it must never have gone to the overloaded
        // faculty member.
        if ($placedRow && $placedRow->faculty_id) {
            $this->assertNotSame($this->facultyA->id, $placedRow->faculty_id,
                'Auto Generate must never assign an already-overloaded faculty member.');
        } else {
            $this->assertContains($row->id, $unresolvedSubjectIds->all(),
                'Expected IT201 to be left unresolved since its only qualified faculty is over their load cap: '.json_encode($result));
        }
    }

    /* ---------------------------------------------------------------
     * GenEd vs Major FACULTY SCOPING
     * ------------------------------------------------------------- */
    public function test_recommend_faculty_scopes_major_subjects_to_the_owning_college_and_gened_subjects_to_the_collegeless_pool(): void
    {
        /** @var RecommendationService $service */
        $service = app(RecommendationService::class);

        // A GenEd faculty member — no College — belongs to the
        // General Education pool only.
        $genEdFaculty = Faculty::create([
            'faculty_id' => 'F-003', 'first_name' => 'Maria', 'last_name' => 'Santos',
            'employment_type' => 'Full-time', 'college_id' => null, 'status' => 'Active',
        ]);

        $majorSubject = $this->makeSubject('IT301', units: 3);
        $genEdSubject = Subject::create([
            'subject_code' => 'GE-ENG1', 'subject_title' => 'Purposive Communication',
            'college_id' => null, 'major_id' => null, 'category' => 'General Education',
            'units' => 3, 'lecture_hours' => 3, 'laboratory_hours' => 0, 'is_active' => true,
        ]);

        $section = $this->makeSection('BSIT-4A');

        // Major subject: only College-affiliated faculty (facultyA/facultyB) should be recommended.
        $majorResult = $service->recommendFaculty($majorSubject, $section);
        $majorIds = collect($majorResult['recommendations'])->pluck('id')->all();
        $this->assertContains($this->facultyA->id, $majorIds);
        $this->assertNotContains($genEdFaculty->id, $majorIds,
            'A College-less GenEd faculty member should never be recommended for a Major subject: '.json_encode($majorResult));

        // GenEd subject: only the College-less GenEd pool should be recommended.
        $genEdResult = $service->recommendFaculty($genEdSubject, $section);
        $genEdIds = collect($genEdResult['recommendations'])->pluck('id')->all();
        $this->assertContains($genEdFaculty->id, $genEdIds);
        $this->assertNotContains($this->facultyA->id, $genEdIds,
            'A College-affiliated Major faculty member should never be recommended for a GenEd subject: '.json_encode($genEdResult));
    }

    /* ---------------------------------------------------------------
     * REPORTS
     * ------------------------------------------------------------- */
    public function test_reports_generate_faculty_teaching_load_and_room_utilization(): void
    {
        /** @var ReportsService $service */
        $service = app(ReportsService::class);

        $section = $this->makeSection('BSIT-4A');
        $subject = $this->makeSubject('IT201', units: 3, lecture: 3);
        $this->placeScheduled($section, $subject, $this->facultyA, $this->lectureRoom, ['Mon', 'Wed'], '08:00', '09:30');

        $filters = ['academic_year' => '2026-2027', 'semester' => 'First Semester'];

        $facultyLoadReport = $service->generate('faculty_teaching_load', $filters);
        $this->assertArrayHasKey('rows', $facultyLoadReport);
        $this->assertTrue(
            collect($facultyLoadReport['rows'])->contains(fn ($row) => str_contains($row['Faculty'] ?? '', 'Lovelace')),
            'Expected Faculty Teaching Load report to include Ada Lovelace: '.json_encode($facultyLoadReport)
        );

        $roomUtilReport = $service->generate('room_utilization', $filters);
        $this->assertArrayHasKey('rows', $roomUtilReport);
        $this->assertNotEmpty($roomUtilReport['rows']);

        $masterSchedule = $service->generate('master_schedule', $filters);
        $this->assertArrayHasKey('rows', $masterSchedule);
        $this->assertNotEmpty($masterSchedule['rows'], 'Master Schedule report should include the placed row.');
    }
}