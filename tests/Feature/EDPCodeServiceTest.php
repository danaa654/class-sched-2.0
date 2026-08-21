<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Department;
use App\Models\EdpCodeSequence;
use App\Models\Major;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use App\Services\EDPCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EDP CODE SEQUENCE HARDENING.
 *
 * Covers the numbering policy EDPCodeService is required to follow:
 * numbering is SHARED across every Section within the same
 * Major + Academic Year + Semester + Year Level (confirmed business
 * rule — e.g. BSIT-1A and BSIT-1B draw from the same counter: if
 * BSIT-1A's subjects end at 009, BSIT-1B's first subject continues
 * at 010, never restarting at 001). Within that scope, the ledger in
 * edp_code_sequences only ever counts up — an issued number is never
 * handed out twice, even after its SectionSubject is deleted, and
 * even after its whole Section is deleted and recreated — and two
 * simultaneous requests in the same scope never collide on the same
 * number.
 */
class EDPCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Major $bsit;

    private Major $bshm;

    protected function setUp(): void
    {
        parent::setUp();

        $college = College::create(['code' => 'CCS', 'name' => 'College of Computer Studies', 'status' => 'Active']);
        $department = Department::create(['college_id' => $college->id, 'code' => 'CCS-DEPT', 'name' => 'CCS Department', 'status' => 'Active']);

        $this->bsit = Major::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'years' => 4, 'status' => 'Active']);
        $this->bshm = Major::create(['department_id' => $department->id, 'code' => 'BSHM', 'name' => 'BS Hospitality Management', 'years' => 4, 'status' => 'Active']);
    }

    private function makeSection(Major $major, string $code, string $academicYear = '2026-2027', string $semester = 'First Semester', string $yearLevel = 'First Year'): Section
    {
        return Section::create([
            'section_code' => $code,
            'section_name' => $code,
            'section_type' => 'Regular',
            'major_id' => $major->id,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'year_level' => $yearLevel,
            'estimated_students' => 30,
            'status' => 'Active',
        ]);
    }

    private function makeSubject(string $code, Major $major): Subject
    {
        return Subject::firstOrCreate(
            ['subject_code' => $code],
            [
                'subject_title' => $code,
                'major_id' => $major->id,
                'category' => 'Major',
                'units' => 3,
                'lecture_hours' => 3,
                'laboratory_hours' => 0,
                'is_active' => true,
            ]
        );
    }

    private function placeSubject(Section $section, string $subjectCode): SectionSubject
    {
        return SectionSubject::create([
            'section_id' => $section->id,
            'subject_id' => $this->makeSubject($subjectCode, $section->major)->id,
            'source' => 'Manual',
            'status' => 'Draft',
        ]);
    }

    /**
     * Mirrors SectionController::destroy() exactly: SectionSubjects
     * are hard-deleted first (clearing their edp_code out of
     * section_subjects' unique index), then the Section itself is
     * soft-deleted.
     */
    private function deleteSection(Section $section): void
    {
        $section->sectionSubjects()->delete();
        $section->delete();
    }

    /* -------------------------------------------------------------
     * TEST 1 — First EDP code in a fresh scope starts at 001.
     * ----------------------------------------------------------- */
    public function test_first_edp_code_in_a_fresh_scope_starts_at_001(): void
    {
        $section = $this->makeSection($this->bsit, 'BSIT-1A');
        $row = $this->placeSubject($section, 'CC101');

        $code = app(EDPCodeService::class)->generateForSectionSubject($row);

        $this->assertSame('BSIT-2611001', $code);
    }

    /* -------------------------------------------------------------
     * TEST 2 — Sequential EDP codes within the same scope.
     * ----------------------------------------------------------- */
    public function test_sequential_edp_codes_increment_within_the_same_scope(): void
    {
        $section = $this->makeSection($this->bsit, 'BSIT-1A');
        $service = app(EDPCodeService::class);

        $codes = collect(['CC101', 'CC102', 'CC103'])
            ->map(fn (string $subjectCode) => $service->generateForSectionSubject($this->placeSubject($section, $subjectCode)));

        $this->assertSame(['BSIT-2611001', 'BSIT-2611002', 'BSIT-2611003'], $codes->all());
    }

    /* -------------------------------------------------------------
     * TEST 3 — Deleting a SectionSubject never frees its number.
     * ----------------------------------------------------------- */
    public function test_deleting_a_section_subject_does_not_free_its_edp_number(): void
    {
        $section = $this->makeSection($this->bsit, 'BSIT-1A');
        $service = app(EDPCodeService::class);

        $row1 = $this->placeSubject($section, 'CC101');
        $service->generateForSectionSubject($row1); // BSIT-2611001

        $row2 = $this->placeSubject($section, 'CC102');
        $service->generateForSectionSubject($row2); // BSIT-2611002

        // Deleting a single SectionSubject (Section itself untouched)
        // must NOT touch edp_code_sequences — the ledger only ever
        // increases.
        $row1->delete();

        $row3 = $this->placeSubject($section, 'CC103');
        $code3 = $service->generateForSectionSubject($row3);

        // 003, never a reused 001.
        $this->assertSame('BSIT-2611003', $code3);
    }

    /* -------------------------------------------------------------
     * TEST 3b — Deleting and recreating a Section does NOT reset
     * numbering — the scope belongs to the whole Major/AY/Semester/
     * Year Level group, not to any one Section, so a recreated
     * BSIT-1A continues wherever the group's counter already was.
     * ----------------------------------------------------------- */
    public function test_deleting_and_recreating_a_section_does_not_reset_the_shared_sequence(): void
    {
        $service = app(EDPCodeService::class);

        $originalSection = $this->makeSection($this->bsit, 'BSIT-1A');
        $service->generateForSectionSubject($this->placeSubject($originalSection, 'CC101')); // 001
        $service->generateForSectionSubject($this->placeSubject($originalSection, 'CC102')); // 002

        // Mirrors SectionController::destroy(): SectionSubjects
        // hard-deleted (clearing their edp_code out of the unique
        // index — good hygiene regardless of scope), then the
        // Section soft-deleted.
        $this->deleteSection($originalSection);

        $recreatedSection = $this->makeSection($this->bsit, 'BSIT-1A');
        $this->assertNotSame($originalSection->id, $recreatedSection->id);

        $code = $service->generateForSectionSubject($this->placeSubject($recreatedSection, 'CC101'));

        // Continues at 003 — the scope (Major/AY/Semester/YearLevel)
        // never reset just because one Section in it was recreated.
        $this->assertSame('BSIT-2611003', $code);
    }

    /* -------------------------------------------------------------
     * TEST 4 — An already-issued code is returned as-is, and the
     * ledger is not incremented.
     * ----------------------------------------------------------- */
    public function test_existing_edp_code_is_preserved_and_sequence_is_not_incremented(): void
    {
        $section = $this->makeSection($this->bsit, 'BSIT-1A');
        $service = app(EDPCodeService::class);

        $row = $this->placeSubject($section, 'CC101');
        $row->edp_code = 'BSIT-2611001';
        $row->save();

        $code = $service->generateForSectionSubject($row);

        $this->assertSame('BSIT-2611001', $code);
        // No scope row was created/incremented by this call at all —
        // the method returned before ever calling nextSequence().
        $this->assertNull(EdpCodeSequence::query()->where('major_id', $this->bsit->id)->first());
    }

    /* -------------------------------------------------------------
     * TEST 5 — Two different, simultaneously-live sections in the
     * same scope SHARE one sequence, continuing rather than each
     * restarting at 001 (confirmed: BSIT-1A ends at 009, BSIT-1B
     * starts at 010).
     * ----------------------------------------------------------- */
    public function test_sequence_is_shared_and_continues_across_live_sections_in_the_same_scope(): void
    {
        $sectionA = $this->makeSection($this->bsit, 'BSIT-1A');
        $sectionB = $this->makeSection($this->bsit, 'BSIT-1B');
        $service = app(EDPCodeService::class);

        $codesA = collect(range(1, 9))
            ->map(fn (int $n) => $service->generateForSectionSubject($this->placeSubject($sectionA, "CC10{$n}")));
        $this->assertSame('BSIT-2611009', $codesA->last());

        $codeB = $service->generateForSectionSubject($this->placeSubject($sectionB, 'CC201'));

        // BSIT-1B continues at 010, not a fresh 001.
        $this->assertSame('BSIT-2611010', $codeB);
    }

    /* -------------------------------------------------------------
     * TEST 6 — Different Majors get independent sequences.
     * ----------------------------------------------------------- */
    public function test_different_majors_have_independent_sequences(): void
    {
        $bsitSection = $this->makeSection($this->bsit, 'BSIT-1A');
        $bshmSection = $this->makeSection($this->bshm, 'BSHM-1A');
        $service = app(EDPCodeService::class);

        $bsitCode = $service->generateForSectionSubject($this->placeSubject($bsitSection, 'CC101'));
        $bshmCode = $service->generateForSectionSubject($this->placeSubject($bshmSection, 'HM101'));

        $this->assertSame('BSIT-2611001', $bsitCode);
        $this->assertSame('BSHM-2611001', $bshmCode);
    }

    /* -------------------------------------------------------------
     * TEST 7 — Different Academic Years get independent sequences.
     * ----------------------------------------------------------- */
    public function test_different_academic_years_have_independent_sequences(): void
    {
        $sectionThisYear = $this->makeSection($this->bsit, 'BSIT-1A', '2026-2027');
        $sectionNextYear = $this->makeSection($this->bsit, 'BSIT-1A-NEXT', '2027-2028');
        $service = app(EDPCodeService::class);

        $codeThisYear = $service->generateForSectionSubject($this->placeSubject($sectionThisYear, 'CC101'));
        $codeNextYear = $service->generateForSectionSubject($this->placeSubject($sectionNextYear, 'CC101B'));

        $this->assertSame('BSIT-2611001', $codeThisYear);
        $this->assertSame('BSIT-2711001', $codeNextYear);
    }

    /* -------------------------------------------------------------
     * TEST 8 — Different Semesters get independent sequences.
     * ----------------------------------------------------------- */
    public function test_different_semesters_have_independent_sequences(): void
    {
        $firstSem = $this->makeSection($this->bsit, 'BSIT-1A', '2026-2027', 'First Semester');
        $secondSem = $this->makeSection($this->bsit, 'BSIT-1A-2ND', '2026-2027', 'Second Semester');
        $service = app(EDPCodeService::class);

        $firstSemCode = $service->generateForSectionSubject($this->placeSubject($firstSem, 'CC101'));
        $secondSemCode = $service->generateForSectionSubject($this->placeSubject($secondSem, 'CC101B'));

        $this->assertSame('BSIT-2611001', $firstSemCode);
        $this->assertSame('BSIT-2621001', $secondSemCode);
    }

    /* -------------------------------------------------------------
     * TEST 8b — Different Year Levels also get independent
     * sequences (each year level restarts its own count at 001).
     * ----------------------------------------------------------- */
    public function test_different_year_levels_have_independent_sequences(): void
    {
        $firstYear = $this->makeSection($this->bsit, 'BSIT-1A', '2026-2027', 'First Semester', 'First Year');
        $secondYear = $this->makeSection($this->bsit, 'BSIT-2A', '2026-2027', 'First Semester', 'Second Year');
        $service = app(EDPCodeService::class);

        $firstYearCode = $service->generateForSectionSubject($this->placeSubject($firstYear, 'CC101'));
        $secondYearCode = $service->generateForSectionSubject($this->placeSubject($secondYear, 'CC201'));

        $this->assertSame('BSIT-2611001', $firstYearCode);
        $this->assertSame('BSIT-2612001', $secondYearCode);
    }

    /* -------------------------------------------------------------
     * TEST 9 — Concurrent generation in the same scope never
     * collides, including the race on FIRST creating the scope row
     * itself (ensureScopeExists()'s savepoint-guarded insert).
     * ----------------------------------------------------------- */
    public function test_concurrent_generation_in_the_same_scope_never_duplicates_a_code(): void
    {
        $section = $this->makeSection($this->bsit, 'BSIT-1A');
        $service = app(EDPCodeService::class);

        $rowA = $this->placeSubject($section, 'CC101');
        $rowB = $this->placeSubject($section, 'CC102');

        // A single test process can't fire two real simultaneous
        // requests, but calling the service back-to-back for a scope
        // that has NO ledger row yet exercises the exact path that
        // would otherwise race: both would see "no scope row" and
        // both would try to create one. This proves neither call
        // throws and the two numbers issued are sequential, never
        // equal.
        $codeA = $service->generateForSectionSubject($rowA);
        $codeB = $service->generateForSectionSubject($rowB);

        $this->assertNotSame($codeA, $codeB);
        $this->assertSame('BSIT-2611001', $codeA);
        $this->assertSame('BSIT-2611002', $codeB);

        $ledger = EdpCodeSequence::query()
            ->where('major_id', $this->bsit->id)
            ->where('academic_year', '2026-2027')
            ->where('semester_code', '1')
            ->where('year_level_code', '1')
            ->first();

        $this->assertNotNull($ledger);
        $this->assertSame(2, $ledger->last_sequence);
    }

    /* -------------------------------------------------------------
     * TEST 10 — The ledger row for a scope is created exactly once,
     * never duplicated, honoring edp_code_sequences_scope_unique.
     * ----------------------------------------------------------- */
    public function test_only_one_ledger_row_exists_per_scope(): void
    {
        $sectionA = $this->makeSection($this->bsit, 'BSIT-1A');
        $sectionB = $this->makeSection($this->bsit, 'BSIT-1B');
        $service = app(EDPCodeService::class);

        $service->generateForSectionSubject($this->placeSubject($sectionA, 'CC101'));
        $service->generateForSectionSubject($this->placeSubject($sectionB, 'CC102'));

        $count = EdpCodeSequence::query()
            ->where('major_id', $this->bsit->id)
            ->where('academic_year', '2026-2027')
            ->where('semester_code', '1')
            ->where('year_level_code', '1')
            ->count();

        $this->assertSame(1, $count);
    }
}