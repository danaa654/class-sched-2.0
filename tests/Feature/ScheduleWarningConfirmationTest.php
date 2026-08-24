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
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CONFIRMABLE WARNINGS — Room Capacity, Room Type, and Weekly Hours
 * Mismatch are all "flagged, not blocked": SectionSubjectController's
 * update() rejects the save with a 422 the FIRST time, then allows it
 * through once the matching *_confirmed flag is sent back true (see
 * that method's own comments). Unlike Faculty/Room/Section double-
 * booking (covered by ScheduleConcurrencyTest), these three can
 * always be saved — the confirmation flag is the only thing that
 * changes between the two requests.
 *
 * This file proves both halves of that contract for each warning:
 * unconfirmed -> 422 with no write, confirmed -> 200 with the row
 * actually persisted and the corresponding *_confirmed column set.
 */
class ScheduleWarningConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private College $college;

    private Major $major;

    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->college = College::create(['code' => 'CCS', 'name' => 'College of Computer Studies', 'status' => 'Active']);
        $department = Department::create(['college_id' => $this->college->id, 'code' => 'CCS-DEPT', 'name' => 'CCS Department', 'status' => 'Active']);
        $this->major = Major::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'years' => 4, 'status' => 'Active']);

        $this->faculty = Faculty::create([
            'faculty_id' => 'F-001', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'employment_type' => 'Full-time', 'college_id' => $this->college->id, 'status' => 'Active',
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['college_id' => $this->college->id]);
        $user->assignRole('Administrator');

        return $user;
    }

    private function makeSection(int $estimatedStudents = 30): Section
    {
        return Section::create([
            'section_code' => 'BSIT-4A',
            'section_name' => 'BSIT-4A',
            'section_type' => 'Regular',
            'major_id' => $this->major->id,
            'year_level' => 'Fourth Year',
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
            'estimated_students' => $estimatedStudents,
            'status' => 'Active',
        ]);
    }

    private function makeSubject(array $overrides = []): Subject
    {
        return Subject::create(array_merge([
            'subject_code' => 'CAP102',
            'subject_title' => 'Capstone Project and Research',
            'major_id' => $this->major->id,
            'category' => 'Major',
            'subject_type' => 'regular',
            'units' => 3,
            'lecture_hours' => 3,
            'laboratory_hours' => 0,
            'is_active' => true,
        ], $overrides));
    }

    private function placeSubject(Section $section, Subject $subject): SectionSubject
    {
        return SectionSubject::create([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'source' => 'Manual',
            'status' => 'Draft',
        ]);
    }

    private function scheduleUrl(Section $section, SectionSubject $row): string
    {
        return "/scheduling/section-subjects/{$section->id}/{$row->id}/schedule";
    }

    /* -------------------------------------------------------------
     * ROOM CAPACITY
     * ----------------------------------------------------------- */
    public function test_room_capacity_overflow_is_blocked_without_confirmation(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection(estimatedStudents: 50);
        $row = $this->placeSubject($section, $this->makeSubject());
        $room = Room::create(['room_code' => 'RM-SMALL', 'room_name' => 'Room Small', 'building' => 'Main', 'room_type' => 'Lecture', 'capacity' => 30, 'status' => 'Active']);

        $response = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->faculty->id,
            'room_id' => $room->id,
            'capacity' => 50,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '11:00',
            'hours_confirmed' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['capacity']]);
        $this->assertNull($row->fresh()->room_id);
    }

    public function test_room_capacity_overflow_saves_once_confirmed(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection(estimatedStudents: 50);
        $row = $this->placeSubject($section, $this->makeSubject());
        $room = Room::create(['room_code' => 'RM-SMALL', 'room_name' => 'Room Small', 'building' => 'Main', 'room_type' => 'Lecture', 'capacity' => 30, 'status' => 'Active']);

        $response = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->faculty->id,
            'room_id' => $room->id,
            'capacity' => 50,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '11:00',
            'capacity_confirmed' => true,
            'hours_confirmed' => true,
        ]);

        $response->assertOk();
        $row->refresh();
        $this->assertSame($room->id, $row->room_id);
        $this->assertTrue((bool) $row->capacity_confirmed);
    }

    /* -------------------------------------------------------------
     * ROOM TYPE MISMATCH
     * ----------------------------------------------------------- */
    public function test_room_type_mismatch_is_blocked_without_confirmation(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        // Lecture-only subject (laboratory_hours = 0) placed in a Lab room.
        $row = $this->placeSubject($section, $this->makeSubject());
        $labRoom = Room::create(['room_code' => 'RM-LAB', 'room_name' => 'Lab 1', 'building' => 'Main', 'room_type' => 'Laboratory', 'capacity' => 40, 'status' => 'Active']);

        $response = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->faculty->id,
            'room_id' => $labRoom->id,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '11:00',
            'hours_confirmed' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['room_type']]);
        $this->assertNull($row->fresh()->room_id);
    }

    public function test_room_type_mismatch_saves_once_confirmed(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        $row = $this->placeSubject($section, $this->makeSubject());
        $labRoom = Room::create(['room_code' => 'RM-LAB', 'room_name' => 'Lab 1', 'building' => 'Main', 'room_type' => 'Laboratory', 'capacity' => 40, 'status' => 'Active']);

        $response = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->faculty->id,
            'room_id' => $labRoom->id,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '11:00',
            'room_type_confirmed' => true,
            'hours_confirmed' => true,
        ]);

        $response->assertOk();
        $this->assertSame($labRoom->id, $row->fresh()->room_id);
    }

    /* -------------------------------------------------------------
     * WEEKLY HOURS MISMATCH
     * ----------------------------------------------------------- */
    public function test_hours_mismatch_is_blocked_without_confirmation(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        // Subject requires 3 hrs/week (lecture_hours = 3).
        $row = $this->placeSubject($section, $this->makeSubject());
        $room = Room::create(['room_code' => 'RM-306', 'room_name' => 'Room 306', 'building' => 'Main', 'room_type' => 'Lecture', 'capacity' => 40, 'status' => 'Active']);

        $response = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->faculty->id,
            'room_id' => $room->id,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '09:00', // only 1 hr/week, subject needs 3
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['hours']]);
        $this->assertNull($row->fresh()->room_id);
    }

    public function test_hours_mismatch_saves_once_confirmed_and_sets_hours_confirmed_column(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        $row = $this->placeSubject($section, $this->makeSubject());
        $room = Room::create(['room_code' => 'RM-306', 'room_name' => 'Room 306', 'building' => 'Main', 'room_type' => 'Lecture', 'capacity' => 40, 'status' => 'Active']);

        $response = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->faculty->id,
            'room_id' => $room->id,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '09:00',
            'hours_confirmed' => true,
        ]);

        $response->assertOk();
        $row->refresh();
        $this->assertSame($room->id, $row->room_id);
        $this->assertTrue((bool) $row->hours_confirmed);
    }

    public function test_hours_that_exactly_match_the_requirement_need_no_confirmation(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        // Subject needs exactly 3 hrs/week — a single 3-hour Monday
        // block matches it exactly, so no warning should fire at all.
        $row = $this->placeSubject($section, $this->makeSubject());
        $room = Room::create(['room_code' => 'RM-306', 'room_name' => 'Room 306', 'building' => 'Main', 'room_type' => 'Lecture', 'capacity' => 40, 'status' => 'Active']);

        $response = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->faculty->id,
            'room_id' => $room->id,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '11:00', // exactly 3 hrs
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $row->fresh()->hours_confirmed);
    }
}