<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\College;
use App\Models\Curriculum;
use App\Models\Department;
use App\Models\Major;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Semester;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Section Creation Role/College Authorization fix — the
 * exact test matrix from the ticket:
 *
 *  Admin      -> CCS program           -> Create section -> ALLOWED
 *  Registrar  -> any program            -> Create section -> ALLOWED
 *  CCS OIC    -> CCS program            -> Create section -> ALLOWED
 *  CCS OIC    -> another college's prog -> Create section -> BLOCKED
 *  CCS Dean   -> CCS program            -> Create section -> ALLOWED
 *  CCS Dean   -> another college's prog -> Create section -> BLOCKED
 *  CCS OIC manually modifies program/college id in the request -> BLOCKED
 *  CCS OIC manually changes a section id belonging to another college -> BLOCKED
 *
 * Also covers the two endpoints the frontend dropdown fix alone did
 * NOT protect: previewBatch() and storeBatch() (spec Section 23).
 */
class SectionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private College $ccs;

    private College $cte;

    private Major $ccsMajor;

    private Major $cteMajor;

    private Curriculum $ccsCurriculum;

    private Curriculum $cteCurriculum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->ccs = College::create(['code' => 'CCS', 'name' => 'College of Computer Studies', 'status' => 'Active']);
        $this->cte = College::create(['code' => 'CTE', 'name' => 'College of Teacher Education', 'status' => 'Active']);

        $ccsDept = Department::create(['college_id' => $this->ccs->id, 'code' => 'CCS-DEPT', 'name' => 'CCS Department', 'status' => 'Active']);
        $cteDept = Department::create(['college_id' => $this->cte->id, 'code' => 'CTE-DEPT', 'name' => 'CTE Department', 'status' => 'Active']);

        $this->ccsMajor = Major::create(['department_id' => $ccsDept->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'years' => 4, 'status' => 'Active']);
        $this->cteMajor = Major::create(['department_id' => $cteDept->id, 'code' => 'BSED', 'name' => 'BS Education', 'years' => 4, 'status' => 'Active']);

        $this->ccsCurriculum = Curriculum::create([
            'major_id' => $this->ccsMajor->id,
            'code' => 'BSIT-2024',
            'name' => 'BSIT 2024 Curriculum',
            'start_year' => 2024,
            'end_year' => 2025,
            'status' => 'Active',
            'allow_new_students' => true,
        ]);

        $this->cteCurriculum = Curriculum::create([
            'major_id' => $this->cteMajor->id,
            'code' => 'BSED-2024',
            'name' => 'BSED 2024 Curriculum',
            'start_year' => 2024,
            'end_year' => 2025,
            'status' => 'Active',
            'allow_new_students' => true,
        ]);

        // StoreSectionRequest cross-validates academic_year/semester
        // against a real AcademicTerm (see its withValidator()) — every
        // payload in this file uses '2026-2027' / 'First Semester', so
        // a matching term must exist or every "ALLOWED" case fails
        // validation before authorization is ever reached.
        $schoolYear = SchoolYear::create([
            'name' => '2026-2027',
            'start_year' => 2026,
            'end_year' => 2027,
            'status' => 'Active',
        ]);

        $semester = Semester::create(array_merge(
            ['name' => '1st Semester'],
            Semester::defaultsFor('1st Semester'),
            ['status' => 'Active'],
        ));

        AcademicTerm::create([
            'school_year_id' => $schoolYear->id,
            'semester_id' => $semester->id,
            'status' => 'Active',
        ]);
    }

    private function makeUser(string $role, ?College $college = null): User
    {
        $user = User::factory()->create(['college_id' => $college?->id]);
        $user->assignRole($role);

        return $user;
    }

    private function sectionPayload(Major $major, ?Curriculum $curriculum = null): array
    {
        return [
            'section_code' => 'TEST-' . uniqid(),
            'section_name' => 'TEST-' . uniqid(),
            'section_type' => 'Regular',
            'major_id' => $major->id,
            'curriculum_id' => $curriculum?->id,
            'year_level' => 'First Year',
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
            'estimated_students' => 30,
            'status' => 'Active',
        ];
    }

    public function test_admin_can_create_section_for_any_college(): void
    {
        $admin = $this->makeUser('Administrator');

        $response = $this->actingAs($admin)->post('/scheduling/sections', $this->sectionPayload($this->ccsMajor, $this->ccsCurriculum));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sections', 1);
    }

    public function test_registrar_can_create_section_for_any_college(): void
    {
        $registrar = $this->makeUser('Registrar');

        $response = $this->actingAs($registrar)->post('/scheduling/sections', $this->sectionPayload($this->cteMajor, $this->cteCurriculum));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sections', 1);
    }

    public function test_ccs_oic_can_create_section_for_ccs_program(): void
    {
        $oic = $this->makeUser('OIC', $this->ccs);

        $response = $this->actingAs($oic)->post('/scheduling/sections', $this->sectionPayload($this->ccsMajor, $this->ccsCurriculum));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sections', 1);
    }

    public function test_ccs_oic_is_blocked_from_creating_section_for_another_college(): void
    {
        $oic = $this->makeUser('OIC', $this->ccs);

        $response = $this->actingAs($oic)->post('/scheduling/sections', $this->sectionPayload($this->cteMajor, $this->cteCurriculum));

        $response->assertForbidden();
        $this->assertDatabaseCount('sections', 0);
    }

    public function test_ccs_dean_can_create_section_for_ccs_program(): void
    {
        $dean = $this->makeUser('Dean', $this->ccs);

        $response = $this->actingAs($dean)->post('/scheduling/sections', $this->sectionPayload($this->ccsMajor, $this->ccsCurriculum));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sections', 1);
    }

    public function test_ccs_dean_is_blocked_from_creating_section_for_another_college(): void
    {
        $dean = $this->makeUser('Dean', $this->ccs);

        $response = $this->actingAs($dean)->post('/scheduling/sections', $this->sectionPayload($this->cteMajor, $this->cteCurriculum));

        $response->assertForbidden();
        $this->assertDatabaseCount('sections', 0);
    }

    /**
     * Simulates a manually tampered request: the OIC's browser sends a
     * major_id belonging to another college, bypassing whatever the
     * dropdown showed them.
     */
    public function test_ccs_oic_manually_submitting_another_colleges_major_id_is_blocked(): void
    {
        $oic = $this->makeUser('OIC', $this->ccs);

        $payload = $this->sectionPayload($this->ccsMajor, $this->ccsCurriculum);
        $payload['major_id'] = $this->cteMajor->id; // tampered
        $payload['curriculum_id'] = $this->cteCurriculum->id; // kept consistent with the tampered major so validation doesn't reject it before the authorization check ever runs — a real attacker tampers both together

        $response = $this->actingAs($oic)->post('/scheduling/sections', $payload);

        $response->assertForbidden();
        $this->assertDatabaseCount('sections', 0);
    }

    public function test_ccs_oic_cannot_view_or_modify_another_colleges_section_by_url_id(): void
    {
        $oic = $this->makeUser('OIC', $this->ccs);

        $foreignSection = Section::create(array_merge(
            $this->sectionPayload($this->cteMajor),
            ['section_code' => 'CTE-1A', 'section_name' => 'CTE-1A']
        ));

        $update = $this->actingAs($oic)->put("/scheduling/sections/{$foreignSection->id}", [
            'section_code' => 'HACKED-1A',
            'section_name' => 'HACKED-1A',
            'section_type' => 'Regular',
            'major_id' => $this->cteMajor->id,
            'curriculum_id' => $this->cteCurriculum->id,
            'year_level' => 'First Year',
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
            'estimated_students' => 30,
            'status' => 'Active',
        ]);
        $update->assertForbidden();

        $delete = $this->actingAs($oic)->delete("/scheduling/sections/{$foreignSection->id}");
        $delete->assertForbidden();

        $this->assertDatabaseHas('sections', ['id' => $foreignSection->id, 'section_code' => 'CTE-1A']);
    }

    public function test_ccs_oic_batch_add_is_blocked_for_another_colleges_program(): void
    {
        $oic = $this->makeUser('OIC', $this->ccs);

        $response = $this->actingAs($oic)->post('/scheduling/sections/batch', [
            'major_id' => $this->cteMajor->id,
            'curriculum_id' => $this->cteCurriculum->id,
            'section_type' => 'Regular',
            'year_level' => 'First Year',
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
            'status' => 'Active',
            'sections' => [
                ['section_code' => 'CTE-1A', 'estimated_students' => 30],
            ],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('sections', 0);
    }

    public function test_ccs_oic_preview_batch_is_blocked_for_another_colleges_program(): void
    {
        $oic = $this->makeUser('OIC', $this->ccs);

        $response = $this->actingAs($oic)->post('/scheduling/sections/preview-batch', [
            'major_id' => $this->cteMajor->id,
            'curriculum_id' => $this->cteCurriculum->id,
            'section_type' => 'Regular',
            'year_level' => 'First Year',
            'academic_year' => '2026-2027',
            'semester' => 'First Semester',
            'section_prefix' => 'CTE',
            'number_of_blocks' => 1,
            'estimated_students_per_block' => 30,
        ]);

        $response->assertForbidden();
    }

    public function test_sections_index_only_returns_scoped_majors_for_ccs_oic(): void
    {
        $oic = $this->makeUser('OIC', $this->ccs);

        $response = $this->actingAs($oic)->get('/scheduling/sections');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('activeMajors', 1)
            ->where('activeMajors.0.id', $this->ccsMajor->id)
        );
    }
}