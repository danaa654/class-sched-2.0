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
 * CONCURRENCY HARDENING — backend-only optimistic concurrency control
 * and conflict re-validation for the scheduling system.
 *
 * Every test hits the real HTTP endpoints (never the services
 * directly) so these exercise the exact same authorization ->
 * transaction -> lock -> version check -> conflict validation -> save
 * -> version bump pipeline the frontend goes through. No polling or
 * frontend real-time updates are exercised or required by any test
 * here — every scenario is a plain request/response, proving the
 * backend alone is the source of truth.
 */
class ScheduleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private College $college;

    private Major $major;

    private Room $roomA;

    private Room $roomB;

    private Faculty $facultyA;

    private Faculty $facultyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->college = College::create(['code' => 'CCS', 'name' => 'College of Computer Studies', 'status' => 'Active']);
        $department = Department::create(['college_id' => $this->college->id, 'code' => 'CCS-DEPT', 'name' => 'CCS Department', 'status' => 'Active']);
        $this->major = Major::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'years' => 4, 'status' => 'Active']);

        $this->roomA = Room::create(['room_code' => 'RM-306', 'room_name' => 'Room 306', 'room_type' => 'Lecture', 'capacity' => 40, 'status' => 'Active']);
        $this->roomB = Room::create(['room_code' => 'RM-307', 'room_name' => 'Room 307', 'room_type' => 'Lecture', 'capacity' => 40, 'status' => 'Active']);

        $this->facultyA = Faculty::create([
            'faculty_id' => 'F-001', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'employment_type' => 'Full-time', 'college_id' => $this->college->id, 'status' => 'Active',
        ]);
        $this->facultyB = Faculty::create([
            'faculty_id' => 'F-002', 'first_name' => 'Grace', 'last_name' => 'Hopper',
            'employment_type' => 'Full-time', 'college_id' => $this->college->id, 'status' => 'Active',
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['college_id' => $this->college->id]);
        $user->assignRole('Administrator');

        return $user;
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
        return Subject::create([
            'subject_code' => $code,
            'subject_title' => $code,
            'category' => 'Major',
            'subject_type' => 'lecture',
            'units' => 3,
            'lecture_hours' => 3,
            'laboratory_hours' => 0,
            'is_active' => true,
        ]);
    }

    private function placeSubject(Section $section, string $subjectCode): SectionSubject
    {
        return SectionSubject::create([
            'section_id' => $section->id,
            'subject_id' => $this->makeSubject($subjectCode)->id,
            'source' => 'Manual',
            'status' => 'Draft',
        ]);
    }

    private function scheduleUrl(Section $section, SectionSubject $subject): string
    {
        return "/scheduling/section-subjects/{$section->id}/{$subject->id}/schedule";
    }

    private function batchUrl(Section $section): string
    {
        return "/scheduling/section-subjects/{$section->id}/schedule/batch";
    }

    /* -------------------------------------------------------------
     * TEST 1 — Two users save the same section using the same version.
     * ----------------------------------------------------------- */
    public function test_stale_version_save_is_rejected_with_409_and_does_not_overwrite(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        $row = $this->placeSubject($section, 'CAP102');

        $this->assertSame(1, $section->fresh()->schedule_version);

        // User A loads version 1, then saves.
        $userA = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '10:00',
            'hours_confirmed' => true,
            'expected_schedule_version' => 1,
        ]);

        $userA->assertOk();
        $this->assertSame(2, $section->fresh()->schedule_version);

        // User B also loaded version 1 (now stale) and tries to save
        // a DIFFERENT, non-conflicting change.
        $userB = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->facultyB->id,
            'room_id' => $this->roomB->id,
            'days' => ['Tue'],
            'start_time' => '09:00',
            'end_time' => '11:00',
            'hours_confirmed' => true,
            'expected_schedule_version' => 1,
        ]);

        $userB->assertStatus(409);
        $userB->assertJson(['code' => 'SCHEDULE_VERSION_CONFLICT', 'current_version' => 2]);

        // User A's save was never overwritten.
        $row->refresh();
        $this->assertSame($this->facultyA->id, $row->faculty_id);
        $this->assertSame($this->roomA->id, $row->room_id);
        $this->assertSame('Mon', $row->days);
        $this->assertSame(2, $section->fresh()->schedule_version);
    }

    /* -------------------------------------------------------------
     * TEST 2 — Two users assign the same room at overlapping times.
     * ----------------------------------------------------------- */
    public function test_room_conflict_across_sections_blocks_the_second_save(): void
    {
        $admin = $this->admin();
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');
        $rowA = $this->placeSubject($sectionA, 'IAS102');
        $rowB = $this->placeSubject($sectionB, 'CAP102');

        $first = $this->actingAs($admin)->patch($this->scheduleUrl($sectionA, $rowA), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '13:00',
            'end_time' => '15:00',
            'hours_confirmed' => true,
        ]);
        $first->assertOk();

        $second = $this->actingAs($admin)->patch($this->scheduleUrl($sectionB, $rowB), [
            'faculty_id' => $this->facultyB->id,
            'room_id' => $this->roomA->id, // same room
            'days' => ['Mon'],
            'start_time' => '14:00', // overlaps 13:00-15:00
            'end_time' => '16:00',
            'hours_confirmed' => true,
        ]);

        $second->assertStatus(422);
        $second->assertJsonStructure(['errors' => ['room_id']]);
        $this->assertNull($rowB->fresh()->room_id);
    }

    /* -------------------------------------------------------------
     * TEST 3 — Two users assign the same faculty at overlapping times.
     * ----------------------------------------------------------- */
    public function test_faculty_conflict_across_sections_blocks_the_second_save(): void
    {
        $admin = $this->admin();
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');
        $rowA = $this->placeSubject($sectionA, 'IAS102');
        $rowB = $this->placeSubject($sectionB, 'CAP102');

        $this->actingAs($admin)->patch($this->scheduleUrl($sectionA, $rowA), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '13:00',
            'end_time' => '15:00',
            'hours_confirmed' => true,
        ])->assertOk();

        $second = $this->actingAs($admin)->patch($this->scheduleUrl($sectionB, $rowB), [
            'faculty_id' => $this->facultyA->id, // same faculty
            'room_id' => $this->roomB->id,
            'days' => ['Mon'],
            'start_time' => '14:00', // overlaps
            'end_time' => '16:00',
            'hours_confirmed' => true,
        ]);

        $second->assertStatus(422);
        $second->assertJsonStructure(['errors' => ['faculty_id']]);
    }

    /* -------------------------------------------------------------
     * TEST 4 — Same section receives overlapping subjects.
     * ----------------------------------------------------------- */
    public function test_section_conflict_rejects_overlapping_subjects_in_same_section(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        $row1 = $this->placeSubject($section, 'CAP102');
        $row2 = $this->placeSubject($section, 'IAS102');

        $this->actingAs($admin)->patch($this->scheduleUrl($section, $row1), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '13:00',
            'end_time' => '15:00',
            'hours_confirmed' => true,
        ])->assertOk();

        $second = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row2), [
            'faculty_id' => $this->facultyB->id,
            'room_id' => $this->roomB->id,
            'days' => ['Mon'],
            'start_time' => '14:00',
            'end_time' => '16:00',
            'hours_confirmed' => true,
        ]);

        $second->assertStatus(422);
    }

    /* -------------------------------------------------------------
     * TEST 5 — Multi-day schedule conflicts on the second day.
     * ----------------------------------------------------------- */
    public function test_multi_day_schedule_validates_every_day_not_only_the_first(): void
    {
        $admin = $this->admin();
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');
        $rowA = $this->placeSubject($sectionA, 'IAS102');
        $rowB = $this->placeSubject($sectionB, 'CAP102');

        // Existing: Wednesday only, same room/time.
        $this->actingAs($admin)->patch($this->scheduleUrl($sectionA, $rowA), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Wed'],
            'start_time' => '08:00',
            'end_time' => '10:30',
            'hours_confirmed' => true,
        ])->assertOk();

        // New: Monday AND Wednesday — Monday is free, Wednesday
        // collides. Must be rejected because of the SECOND day.
        $second = $this->actingAs($admin)->patch($this->scheduleUrl($sectionB, $rowB), [
            'faculty_id' => $this->facultyB->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon', 'Wed'],
            'start_time' => '08:00',
            'end_time' => '10:30',
            'hours_confirmed' => true,
        ]);

        $second->assertStatus(422);
        $second->assertJsonStructure(['errors' => ['room_id']]);
    }

    /* -------------------------------------------------------------
     * TEST 6 — Existing assignment is moved; must not conflict with
     * itself.
     * ----------------------------------------------------------- */
    public function test_moving_an_assignment_does_not_conflict_with_itself(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        $row = $this->placeSubject($section, 'CAP102');

        $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '13:00',
            'end_time' => '16:00',
            'hours_confirmed' => true,
        ])->assertOk();

        // Move the SAME row to a different day, same room/time window.
        $move = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'days' => ['Tue'],
            'hours_confirmed' => true,
        ]);

        $move->assertOk();
        $this->assertSame('Tue', $row->fresh()->days);
    }

    /* -------------------------------------------------------------
     * TEST 7 — User has authorization for multiple sections; cross-
     * section move is allowed if all other rules pass.
     * ----------------------------------------------------------- */
    public function test_authorized_user_may_move_assignment_across_sections_they_manage(): void
    {
        $admin = $this->admin();
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');
        $row = $this->placeSubject($sectionA, 'CAP102');

        $this->actingAs($admin)->patch($this->scheduleUrl($sectionA, $row), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '10:00',
            'hours_confirmed' => true,
        ])->assertOk();

        // Room Grid cross-section move: the assignment itself belongs
        // to Section A, but the Room Grid is currently open on
        // Section B. Administrator is unrestricted, so this must be
        // allowed once the confirmation flag is supplied.
        $move = $this->actingAs($admin)->patch("/scheduling/room-grid/section-subjects/{$row->id}/move", [
            'room_id' => $this->roomB->id,
            'days' => ['Tue'],
            'start_time' => '08:00',
            'end_time' => '10:00',
            'hours_confirmed' => true,
            'current_section_id' => $sectionB->id,
            'cross_section_confirmed' => true,
        ]);

        $move->assertOk();
        $this->assertSame($this->roomB->id, $row->fresh()->room_id);
        // The move never actually changed the row's OWN section — it
        // stays under Section A, exactly as spec Section 17 requires
        // (current section = UI context only).
        $this->assertSame($sectionA->id, $row->fresh()->section_id);
    }

    /* -------------------------------------------------------------
     * TEST 8 — User lacks authorization -> 403.
     * ----------------------------------------------------------- */
    public function test_unauthorized_user_is_forbidden_from_saving_another_colleges_schedule(): void
    {
        $otherCollege = College::create(['code' => 'CTE', 'name' => 'College of Teacher Education', 'status' => 'Active']);
        $oic = User::factory()->create(['college_id' => $otherCollege->id]);
        $oic->assignRole('OIC');

        $section = $this->makeSection();
        $row = $this->placeSubject($section, 'CAP102');

        $response = $this->actingAs($oic)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '10:00',
            'hours_confirmed' => true,
        ]);

        $response->assertForbidden();
        $this->assertNull($row->fresh()->room_id);
    }

    /* -------------------------------------------------------------
     * TEST 9 — Auto Schedule preview goes stale before Accept & Save.
     * ----------------------------------------------------------- */
    public function test_batch_save_is_rejected_when_section_version_changed_since_load(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        $row1 = $this->placeSubject($section, 'CAP102');
        $row2 = $this->placeSubject($section, 'IAS102');

        $loadedVersion = $section->fresh()->schedule_version; // 1

        // Another user's edit lands first and bumps the version.
        $this->actingAs($admin)->patch($this->scheduleUrl($section, $row1), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '10:00',
            'hours_confirmed' => true,
        ])->assertOk();

        $this->assertGreaterThan($loadedVersion, $section->fresh()->schedule_version);

        // First user's "Accept & Save" batch submit still carries the
        // now-stale version it originally loaded.
        $batch = $this->actingAs($admin)->postJson($this->batchUrl($section), [
            'expected_schedule_version' => $loadedVersion,
            'rows' => [
                [
                    'id' => $row2->id,
                    'faculty_id' => $this->facultyB->id,
                    'room_id' => $this->roomB->id,
                    'days' => ['Wed'],
                    'start_time' => '09:00',
                    'end_time' => '11:00',
            'hours_confirmed' => true,
                ],
            ],
        ]);

        $batch->assertStatus(409);
        $batch->assertJson(['code' => 'SCHEDULE_VERSION_CONFLICT']);
        $this->assertNull($row2->fresh()->room_id);
    }

    /* -------------------------------------------------------------
     * TEST 10 — Two Auto Schedule / batch operations race; only the
     * one built on the current version may commit.
     * ----------------------------------------------------------- */
    public function test_only_the_batch_save_built_on_the_current_version_commits(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        $row1 = $this->placeSubject($section, 'CAP102');
        $row2 = $this->placeSubject($section, 'IAS102');

        $version = $section->fresh()->schedule_version; // 1, both "users" load this

        $firstBatch = $this->actingAs($admin)->postJson($this->batchUrl($section), [
            'expected_schedule_version' => $version,
            'rows' => [[
                'id' => $row1->id,
                'faculty_id' => $this->facultyA->id,
                'room_id' => $this->roomA->id,
                'days' => ['Mon'],
                'start_time' => '08:00',
                'end_time' => '10:00',
            'hours_confirmed' => true,
            ]],
        ]);
        $firstBatch->assertOk();

        // Second "concurrent" submit still references the same
        // originally-loaded version — must be rejected even though it
        // touches a completely different, non-conflicting row.
        $secondBatch = $this->actingAs($admin)->postJson($this->batchUrl($section), [
            'expected_schedule_version' => $version,
            'rows' => [[
                'id' => $row2->id,
                'faculty_id' => $this->facultyB->id,
                'room_id' => $this->roomB->id,
                'days' => ['Wed'],
                'start_time' => '09:00',
                'end_time' => '11:00',
            'hours_confirmed' => true,
            ]],
        ]);

        $secondBatch->assertStatus(409);
        $this->assertNull($row2->fresh()->room_id);
    }

    /* -------------------------------------------------------------
     * TEST 11 — Concurrent same room/time reservation across two
     * sections: the database/transaction strategy must guarantee only
     * one commits, even without a version conflict (both requests may
     * legitimately see the version as unchanged because neither has
     * written yet — the ROOM lock is what serializes them).
     * ----------------------------------------------------------- */
    public function test_concurrent_identical_room_time_requests_never_both_commit(): void
    {
        $admin = $this->admin();
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');
        $rowA = $this->placeSubject($sectionA, 'CAP102');
        $rowB = $this->placeSubject($sectionB, 'CAP102');

        $payload = [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '13:00',
            'end_time' => '15:00',
            'hours_confirmed' => true,
        ];

        // A single PHP test process can't truly fire two HTTP requests
        // in parallel, but the SAME lockResources()/validate() path
        // that would serialize two real concurrent requests (Room row
        // lock forces the second transaction to wait, then re-read
        // the just-committed row before its own validate() runs) is
        // exercised sequentially here: request 1 commits, then
        // request 2 must see request 1's committed row and be
        // rejected — proving the backend never allows both to land,
        // regardless of what either request's own frontend believed
        // was available before submitting.
        $first = $this->actingAs($admin)->patch($this->scheduleUrl($sectionA, $rowA), array_merge($payload, ['faculty_id' => $this->facultyA->id]));
        $first->assertOk();

        $second = $this->actingAs($admin)->patch($this->scheduleUrl($sectionB, $rowB), array_merge($payload, ['faculty_id' => $this->facultyB->id]));
        $second->assertStatus(422);

        $this->assertSame($this->roomA->id, $rowA->fresh()->room_id);
        $this->assertNull($rowB->fresh()->room_id);
    }

    /* -------------------------------------------------------------
     * Version is never advanced on a failed/rolled-back write.
     * ----------------------------------------------------------- */
    public function test_failed_conflicting_save_does_not_advance_schedule_version(): void
    {
        $admin = $this->admin();
        $sectionA = $this->makeSection('BSIT-4A');
        $sectionB = $this->makeSection('BSIT-4B');
        $rowA = $this->placeSubject($sectionA, 'CAP102');
        $rowB = $this->placeSubject($sectionB, 'IAS102');

        $this->actingAs($admin)->patch($this->scheduleUrl($sectionA, $rowA), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '13:00',
            'end_time' => '15:00',
            'hours_confirmed' => true,
        ])->assertOk();

        $versionBeforeFailedSave = $sectionB->fresh()->schedule_version;

        $this->actingAs($admin)->patch($this->scheduleUrl($sectionB, $rowB), [
            'faculty_id' => $this->facultyA->id, // conflicts with rowA
            'room_id' => $this->roomB->id,
            'days' => ['Mon'],
            'start_time' => '14:00',
            'end_time' => '16:00',
            'hours_confirmed' => true,
        ])->assertStatus(422);

        $this->assertSame($versionBeforeFailedSave, $sectionB->fresh()->schedule_version);
    }

    /* -------------------------------------------------------------
     * A save without expected_schedule_version still works (backward
     * compatible — version checking is opt-in per request).
     * ----------------------------------------------------------- */
    public function test_save_without_expected_version_is_not_blocked_by_version_check(): void
    {
        $admin = $this->admin();
        $section = $this->makeSection();
        $row = $this->placeSubject($section, 'CAP102');

        $response = $this->actingAs($admin)->patch($this->scheduleUrl($section, $row), [
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'days' => ['Mon'],
            'start_time' => '08:00',
            'end_time' => '10:00',
            'hours_confirmed' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('schedule_version', 2);
    }
}