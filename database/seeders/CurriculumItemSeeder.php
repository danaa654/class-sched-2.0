<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * CurriculumItemSeeder
 * ---------------------------------------------------------------------
 * Populates `curriculum_items` (the per-curriculum prospectus) from the
 * official PAP prospectus PDFs, matched against the subject_codes
 * already created by SubjectSeeder and the curriculum codes already
 * created by CurriculumSeeder.
 *
 * All 8 curricula seeded:
 *   1. BSIT
 *   2. BSHM
 *   3. BSTM
 *   4. BSED English
 *   5. BSCRIM - Firearms Identification (curriculum code: BSCRIM-FB-2023-2027)
 *   6. BSCRIM - Fingerprint Identification
 *   7. BSCRIM - Lie Detection
 *   8. BSCRIM - Questioned Documents Examination
 *
 * Every subject_code referenced below was verified against SubjectSeeder
 * before being added — nothing here was invented. No subjects were found
 * missing for any of the 8 curricula.
 *
 * ORDERING: `curriculum_items` has no sort_order column, so display order
 * within a (year_level, semester) group is left to the consuming query
 * (e.g. order by subject_id or subject name). The comments above each
 * group still preserve the original left-to-right / top-to-bottom order
 * the subject appears in its prospectus column, for reference.
 *
 * OJT ITEMS: `curriculum_items` has no item_type or ojt_hours column —
 * Practicum/OJT entries are placed the same way as any other subject,
 * via subject_id pointing at the Practicum entry in the Subjects master
 * list. The required-hours figure itself lives on Subject::required_hours
 * (seeded in SubjectSeeder — 300/600 for BSTM/BSHM phases, null pending
 * confirmation for BSIT's PRAC101, BSED's PRACTICUM, and all four BSCRIM
 * majors' PRACTICUM1-BSCRIM / PRACTICUM2-BSCRIM) and is surfaced directly
 * from there in the Curriculum Subjects UI — it is not duplicated into
 * `remarks` here.
 *
 * Run with: php artisan db:seed --class=CurriculumItemSeeder
 * Safe to re-run: uses CurriculumItem::updateOrCreate() keyed on
 * (curriculum_id, subject_id), so it won't create duplicate rows.
 */
class CurriculumItemSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBsit();
        $this->seedBshm();
        $this->seedBstm();
        $this->seedBsedEnglish();
        $this->seedBscrimFirearms();
        $this->seedBscrimFingerprint();
        $this->seedBscrimLieDetection();
        $this->seedBscrimQuestionedDocuments();
    }

    /**
     * Attaches every [subject_code, year_level, semester] tuple in
     * $items to $curriculum as a curriculum item. Shared by every
     * seedXxx() method below.
     */
    private function attachSubjects(Curriculum $curriculum, array $items): void
    {
        foreach ($items as [$code, $yearLevel, $semester]) {
            $subject = Subject::where('subject_code', $code)->firstOrFail();

            CurriculumItem::updateOrCreate(
                [
                    'curriculum_id' => $curriculum->id,
                    'subject_id' => $subject->id,
                ],
                [
                    'year_level' => $yearLevel,
                    'semester' => $semester,
                ]
            );
        }
    }

    /**
     * Attaches a single Practicum/OJT curriculum item to $curriculum.
     *
     * $ojtHours is accepted for readability at each call site (so it's
     * obvious from the seeder call which phase requires how many hours)
     * but is intentionally NOT written into `remarks` anymore — the
     * authoritative value lives on Subject::required_hours (set by
     * SubjectSeeder) and is now surfaced directly in the Curriculum
     * Subjects UI via a "Required Hours" column. Duplicating it into
     * `remarks` as free text risked the two going out of sync.
     */
    private function attachOjt(
        Curriculum $curriculum,
        string $subjectCode,
        string $yearLevel,
        string $semester,
        ?int $ojtHours
    ): void {
        $subject = Subject::where('subject_code', $subjectCode)->firstOrFail();

        CurriculumItem::updateOrCreate(
            [
                'curriculum_id' => $curriculum->id,
                'subject_id' => $subject->id,
            ],
            [
                'year_level' => $yearLevel,
                'semester' => $semester,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 1. BSIT — BS Information Technology
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)"
    | CMO No. 25 S. 2015, A.Y. 2023-2027 prospectus PDF.
    | Curriculum code: BSIT-2023-2027 (per CurriculumSeeder).
    |
    */
    private function seedBsit(): void
    {
        $curriculum = Curriculum::where('code', 'BSIT-2023-2027')->firstOrFail();

        $this->attachSubjects($curriculum, [
            // First Year - First Semester
            ['UTS', '1st Year', 'First Semester'],
            ['MMW', '1st Year', 'First Semester'],
            ['GENSOC', '1st Year', 'First Semester'],
            ['CC101', '1st Year', 'First Semester'],
            ['CC102', '1st Year', 'First Semester'],
            ['IT-ELECT1', '1st Year', 'First Semester'],
            ['NSTP1', '1st Year', 'First Semester'],
            ['PATHFIT1', '1st Year', 'First Semester'],
            ['NON-ICT1', '1st Year', 'First Semester'],

            // First Year - Second Semester
            ['READINGS', '1st Year', 'Second Semester'],
            ['PCOM', '1st Year', 'Second Semester'],
            ['ITE', '1st Year', 'Second Semester'],
            ['CC103', '1st Year', 'Second Semester'],
            ['HCI101', '1st Year', 'Second Semester'],
            ['IT-ELECT2', '1st Year', 'Second Semester'],
            ['NSTP2', '1st Year', 'Second Semester'],
            ['PATHFIT2', '1st Year', 'Second Semester'],
            ['NON-ICT2', '1st Year', 'Second Semester'],

            // Second Year - First Semester
            ['TCW', '2nd Year', 'First Semester'],
            ['ART', '2nd Year', 'First Semester'],
            ['PPC', '2nd Year', 'First Semester'],
            ['CC104', '2nd Year', 'First Semester'],
            ['CC105', '2nd Year', 'First Semester'],
            ['PF101', '2nd Year', 'First Semester'],
            ['PT101', '2nd Year', 'First Semester'],
            ['MS101', '2nd Year', 'First Semester'],
            ['PATHFIT3', '2nd Year', 'First Semester'],

            // Second Year - Second Semester
            ['STS', '2nd Year', 'Second Semester'],
            ['ETHICS', '2nd Year', 'Second Semester'],
            ['PIC', '2nd Year', 'Second Semester'],
            ['IM101', '2nd Year', 'Second Semester'],
            ['WS101', '2nd Year', 'Second Semester'],
            ['NET101', '2nd Year', 'Second Semester'],
            ['IPT101', '2nd Year', 'Second Semester'],
            ['MS102', '2nd Year', 'Second Semester'],
            ['PATHFIT4', '2nd Year', 'Second Semester'],

            // Third Year - First Semester
            ['CC106', '3rd Year', 'First Semester'],
            ['NET102', '3rd Year', 'First Semester'],
            ['SIA101', '3rd Year', 'First Semester'],
            ['IT-ELECT3', '3rd Year', 'First Semester'],

            // Third Year - Second Semester
            ['IAS101', '3rd Year', 'Second Semester'],
            ['CAP101', '3rd Year', 'Second Semester'],
            ['IT-ELECT4', '3rd Year', 'Second Semester'],
            ['RIZAL', '3rd Year', 'Second Semester'],

            // Fourth Year - First Semester
            ['IAS102', '4th Year', 'First Semester'],
            ['SA101', '4th Year', 'First Semester'],
            ['CAP102', '4th Year', 'First Semester'],
            ['SP101', '4th Year', 'First Semester'],
        ]);

        // Fourth Year - Second Semester: Practicum (OJT)
        // No explicit hour figure printed in the prospectus (just "6" units) —
        // ojt_hours left null. See class docblock.
        $this->attachOjt($curriculum, 'PRAC101', '4th Year', 'Second Semester', null);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. BSHM — BS Hospitality Management
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)"
    | CMO No. 62 S. 2017, A.Y. 2023-2027 prospectus PDF.
    | Curriculum code: BSHM-2023-2027 (per CurriculumSeeder).
    |
    | Note: the prospectus places GENSOC and ITE in Third Year - First
    | Semester (not First/Second Year) — preserved exactly as printed.
    | Second Year - Summer uses the 'Summer' semester enum value.
    |
    */
    private function seedBshm(): void
    {
        $curriculum = Curriculum::where('code', 'BSHM-2023-2027')->firstOrFail();

        $this->attachSubjects($curriculum, [
            // First Year - First Semester
            ['UTS', '1st Year', 'First Semester'],
            ['MMW', '1st Year', 'First Semester'],
            ['THC1', '1st Year', 'First Semester'],
            ['HPC1', '1st Year', 'First Semester'],
            ['HPC2', '1st Year', 'First Semester'],
            ['FLT1', '1st Year', 'First Semester'],
            ['PATHFIT1', '1st Year', 'First Semester'],
            ['NSTP1', '1st Year', 'First Semester'],

            // First Year - Second Semester
            ['READINGS', '1st Year', 'Second Semester'],
            ['PCOM', '1st Year', 'Second Semester'],
            ['BC1', '1st Year', 'Second Semester'],
            ['THC3', '1st Year', 'Second Semester'],
            ['THC4', '1st Year', 'Second Semester'],
            ['HPC3', '1st Year', 'Second Semester'],
            ['FLT2', '1st Year', 'Second Semester'],
            ['PATHFIT2', '1st Year', 'Second Semester'],
            ['NSTP2', '1st Year', 'Second Semester'],
            ['NON-ABM1', '1st Year', 'Second Semester'],

            // Second Year - First Semester
            ['TCW', '2nd Year', 'First Semester'],
            ['ART', '2nd Year', 'First Semester'],
            ['THC5', '2nd Year', 'First Semester'],
            ['PROF-ELECT1-BSHM', '2nd Year', 'First Semester'],
            ['HPC4', '2nd Year', 'First Semester'],
            ['HPC5', '2nd Year', 'First Semester'],
            ['FLT3', '2nd Year', 'First Semester'],
            ['FLT4', '2nd Year', 'First Semester'],
            ['PATHFIT3', '2nd Year', 'First Semester'],
            ['NON-ABM2', '2nd Year', 'First Semester'],

            // Second Year - Second Semester
            ['STS', '2nd Year', 'Second Semester'],
            ['ETHICS', '2nd Year', 'Second Semester'],
            ['BC2', '2nd Year', 'Second Semester'],
            ['PROF-ELECT2-BSHM', '2nd Year', 'Second Semester'],
            ['FLT5', '2nd Year', 'Second Semester'],
            ['THC6', '2nd Year', 'Second Semester'],
            ['THC7', '2nd Year', 'Second Semester'],
            ['HPC6', '2nd Year', 'Second Semester'],
            ['PATHFIT4', '2nd Year', 'Second Semester'],
            ['NON-ABM3', '2nd Year', 'Second Semester'],

            // Third Year - First Semester
            ['GENSOC', '3rd Year', 'First Semester'],
            ['ITE', '3rd Year', 'First Semester'],
            ['THC8', '3rd Year', 'First Semester'],
            ['THC9', '3rd Year', 'First Semester'],
            ['HPC7', '3rd Year', 'First Semester'],
            ['HPC8', '3rd Year', 'First Semester'],
            ['NON-ABM4', '3rd Year', 'First Semester'],

            // Third Year - Second Semester
            ['PPC', '3rd Year', 'Second Semester'],
            ['THC10', '3rd Year', 'Second Semester'],
            ['HPC9', '3rd Year', 'Second Semester'],
            ['PROF-ELECT3-BSHM', '3rd Year', 'Second Semester'],
            ['RESEARCH', '3rd Year', 'Second Semester'],
            ['NON-ABM5', '3rd Year', 'Second Semester'],

            // Fourth Year - First Semester
            ['PIC', '4th Year', 'First Semester'],
            ['HPC10', '4th Year', 'First Semester'],
            ['PROF-ELECT4-BSHM', '4th Year', 'First Semester'],
            ['PROF-ELECT5-BSHM', '4th Year', 'First Semester'],
            ['RIZAL', '4th Year', 'First Semester'],
        ]);

        // Second Year - Summer: Practicum Phase I. Prospectus explicitly
        // prints "(300 hours)".
        $this->attachOjt($curriculum, 'PRACTICUM1-BSHM', '2nd Year', 'Summer', 300);

        // Fourth Year - Second Semester: Practicum Phase II. Prospectus
        // explicitly prints "(600 hours)".
        $this->attachOjt($curriculum, 'PRACTICUM2-BSHM', '4th Year', 'Second Semester', 600);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. BSTM — BS Tourism Management
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)"
    | CMO No. 62 S. 2017, A.Y. 2023-2027 prospectus PDF.
    | Curriculum code: BSTM-2023-2027 (per CurriculumSeeder).
    |
    | Same GENSOC/ITE-in-Third-Year pattern as BSHM — preserved as printed.
    | Second Year - Summer uses the 'Summer' semester enum value.
    |
    */
    private function seedBstm(): void
    {
        $curriculum = Curriculum::where('code', 'BSTM-2023-2027')->firstOrFail();

        $this->attachSubjects($curriculum, [
            // First Year - First Semester
            ['UTS', '1st Year', 'First Semester'],
            ['MMW', '1st Year', 'First Semester'],
            ['THC1', '1st Year', 'First Semester'],
            ['THC2', '1st Year', 'First Semester'],
            ['TPC1', '1st Year', 'First Semester'],
            ['FLT1', '1st Year', 'First Semester'],
            ['PATHFIT1', '1st Year', 'First Semester'],
            ['NSTP1', '1st Year', 'First Semester'],

            // First Year - Second Semester
            ['READINGS', '1st Year', 'Second Semester'],
            ['PCOM', '1st Year', 'Second Semester'],
            ['BC1', '1st Year', 'Second Semester'],
            ['THC3', '1st Year', 'Second Semester'],
            ['TPC2', '1st Year', 'Second Semester'],
            ['FLT2', '1st Year', 'Second Semester'],
            ['PATHFIT2', '1st Year', 'Second Semester'],
            ['NSTP2', '1st Year', 'Second Semester'],
            ['NON-ABM1', '1st Year', 'Second Semester'],

            // Second Year - First Semester
            ['TCW', '2nd Year', 'First Semester'],
            ['ART', '2nd Year', 'First Semester'],
            ['THC5', '2nd Year', 'First Semester'],
            ['PROF-ELECT1-BSTM', '2nd Year', 'First Semester'],
            ['TPC4', '2nd Year', 'First Semester'],
            ['TPC5', '2nd Year', 'First Semester'],
            ['FLT3', '2nd Year', 'First Semester'],
            ['FLT4', '2nd Year', 'First Semester'],
            ['PATHFIT3', '2nd Year', 'First Semester'],
            ['NON-ABM2', '2nd Year', 'First Semester'],

            // Second Year - Second Semester
            ['STS', '2nd Year', 'Second Semester'],
            ['ETHICS', '2nd Year', 'Second Semester'],
            ['BC2', '2nd Year', 'Second Semester'],
            ['PROF-ELECT2-BSTM', '2nd Year', 'Second Semester'],
            ['FLT5', '2nd Year', 'Second Semester'],
            ['THC6', '2nd Year', 'Second Semester'],
            ['TPC6', '2nd Year', 'Second Semester'],
            ['PATHFIT4', '2nd Year', 'Second Semester'],
            ['NON-ABM3', '2nd Year', 'Second Semester'],

            // Third Year - First Semester
            ['GENSOC', '3rd Year', 'First Semester'],
            ['ITE', '3rd Year', 'First Semester'],
            ['THC8', '3rd Year', 'First Semester'],
            ['THC9', '3rd Year', 'First Semester'],
            ['TPC7', '3rd Year', 'First Semester'],
            ['TPC8', '3rd Year', 'First Semester'],
            ['NON-ABM4', '3rd Year', 'First Semester'],

            // Third Year - Second Semester
            ['PPC', '3rd Year', 'Second Semester'],
            ['THC10', '3rd Year', 'Second Semester'],
            ['TPC9', '3rd Year', 'Second Semester'],
            ['PROF-ELECT3-BSTM', '3rd Year', 'Second Semester'],
            ['RESEARCH', '3rd Year', 'Second Semester'],
            ['NON-ABM5', '3rd Year', 'Second Semester'],

            // Fourth Year - First Semester
            ['PIC', '4th Year', 'First Semester'],
            ['TPC10', '4th Year', 'First Semester'],
            ['PROF-ELECT4-BSTM', '4th Year', 'First Semester'],
            ['PROF-ELECT5-BSTM', '4th Year', 'First Semester'],
            ['RIZAL', '4th Year', 'First Semester'],
        ]);

        // Second Year - Summer: Practicum Phase I. Prospectus explicitly
        // prints "(300 hours)".
        $this->attachOjt($curriculum, 'PRACTICUM1-BSTM', '2nd Year', 'Summer', 300);

        // Fourth Year - Second Semester: Practicum Phase II. Prospectus
        // explicitly prints "(600 hours)".
        $this->attachOjt($curriculum, 'PRACTICUM2-BSTM', '4th Year', 'Second Semester', 600);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. BSED — Major in English
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SECONDARY EDUCATION (BSED), Major in English"
    | CMO No. 75 S. 2017, A.Y. 2023-2027 prospectus PDF.
    | Curriculum code: BSED-ENG-2023-2027 (per CurriculumSeeder).
    |
    */
    private function seedBsedEnglish(): void
    {
        $curriculum = Curriculum::where('code', 'BSED-ENG-2023-2027')->firstOrFail();

        $this->attachSubjects($curriculum, [
            // First Year - First Semester
            ['UTS', '1st Year', 'First Semester'],
            ['MMW', '1st Year', 'First Semester'],
            ['GENSOC', '1st Year', 'First Semester'],
            ['EDUC1', '1st Year', 'First Semester'],
            ['EM1', '1st Year', 'First Semester'],
            ['EM2', '1st Year', 'First Semester'],
            ['EM3', '1st Year', 'First Semester'],
            ['PATHFIT1', '1st Year', 'First Semester'],
            ['ELECT', '1st Year', 'First Semester'],
            ['NSTP1', '1st Year', 'First Semester'],

            // First Year - Second Semester
            ['READINGS', '1st Year', 'Second Semester'],
            ['PCOM', '1st Year', 'Second Semester'],
            ['ITE', '1st Year', 'Second Semester'],
            ['EM4', '1st Year', 'Second Semester'],
            ['EM5', '1st Year', 'Second Semester'],
            ['EM6', '1st Year', 'Second Semester'],
            ['EDUC2', '1st Year', 'Second Semester'],
            ['PATHFIT2', '1st Year', 'Second Semester'],
            ['NSTP2', '1st Year', 'Second Semester'],

            // Second Year - First Semester
            ['TCW', '2nd Year', 'First Semester'],
            ['ART', '2nd Year', 'First Semester'],
            ['PPC', '2nd Year', 'First Semester'],
            ['EM7', '2nd Year', 'First Semester'],
            ['EM8', '2nd Year', 'First Semester'],
            ['EM9', '2nd Year', 'First Semester'],
            ['EDUC3', '2nd Year', 'First Semester'],
            ['EDUC4', '2nd Year', 'First Semester'],
            ['PATHFIT3', '2nd Year', 'First Semester'],

            // Second Year - Second Semester
            ['STS', '2nd Year', 'Second Semester'],
            ['ETHICS', '2nd Year', 'Second Semester'],
            ['PIC', '2nd Year', 'Second Semester'],
            ['EM10', '2nd Year', 'Second Semester'],
            ['EM11', '2nd Year', 'Second Semester'],
            ['EM12', '2nd Year', 'Second Semester'],
            ['EM13', '2nd Year', 'Second Semester'],
            ['EDUC5', '2nd Year', 'Second Semester'],
            ['EDUC6', '2nd Year', 'Second Semester'],
            ['PATHFIT4', '2nd Year', 'Second Semester'],

            // Third Year - First Semester
            ['RES', '3rd Year', 'First Semester'],
            ['EM14', '3rd Year', 'First Semester'],
            ['EM15', '3rd Year', 'First Semester'],
            ['EM16', '3rd Year', 'First Semester'],
            ['EM17', '3rd Year', 'First Semester'],
            ['EDUC7', '3rd Year', 'First Semester'],
            ['EDUC8', '3rd Year', 'First Semester'],
            ['COGNATE', '3rd Year', 'First Semester'],

            // Third Year - Second Semester
            ['RIZAL', '3rd Year', 'Second Semester'],
            ['EM18', '3rd Year', 'Second Semester'],
            ['EM19', '3rd Year', 'Second Semester'],
            ['EM20', '3rd Year', 'Second Semester'],
            ['EM21', '3rd Year', 'Second Semester'],
            ['EDUC9', '3rd Year', 'Second Semester'],
            ['EDUC10', '3rd Year', 'Second Semester'],

            // Fourth Year - First Semester
            ['FS1', '4th Year', 'First Semester'],
            ['FS2', '4th Year', 'First Semester'],
        ]);

        // Fourth Year - Second Semester: Teaching Internship (OJT)
        // No explicit hour figure printed in the prospectus (just "6" units) —
        // ojt_hours left null. See class docblock.
        $this->attachOjt($curriculum, 'PRACTICUM', '4th Year', 'Second Semester', null);
    }

    /*
    |--------------------------------------------------------------------------
    | BSCRIM — shared item list builder
    |--------------------------------------------------------------------------
    |
    | All four BSCRIM majors (Firearms, Fingerprint, Lie Detection,
    | Questioned Documents) share an identical GE/Professional/Major-core
    | skeleton and only differ in:
    |   - the six major-specific subject codes dropped into Year 1-2, and
    |   - which two of {FORENSIC2, FORENSIC4, FORENSIC5, FORENSIC6} round
    |     out Third Year (each major skips whichever forensic elective
    |     overlaps its own specialization track).
    | This mirrors SubjectSeeder::bscrimShared()'s own "shared core, majors
    | plug in their own codes" structure, so the four seedBscrimXxx()
    | methods below just supply those six codes plus the two Y3S1/Y3S2
    | forensic electives — verified against each program's own prospectus
    | PDF rather than assumed identical.
    |
    * @param string $major1..$major6  This major's own 6 specialization codes,
    *                                  in prospectus order (Y1S1, Y1S2, Y2S1 x2, Y2S2 x2).
    * @param string $forensicY3S1     The Forensic elective in Year 3 - Sem 1.
    * @param string $forensicY3S2a    First Forensic elective in Year 3 - Sem 2.
    * @param string $forensicY3S2b    Second Forensic elective in Year 3 - Sem 2.
    */
    private function bscrimSharedItems(
        string $major1,
        string $major2,
        string $major3,
        string $major4,
        string $major5,
        string $major6,
        string $forensicY3S1,
        string $forensicY3S2a,
        string $forensicY3S2b
    ): array {
        return [
            // First Year - First Semester
            ['READINGS', '1st Year', 'First Semester'],
            ['PCOM', '1st Year', 'First Semester'],
            ['ITE', '1st Year', 'First Semester'],
            ['LAW1', '1st Year', 'First Semester'],
            ['CRIM1', '1st Year', 'First Semester'],
            [$major1, '1st Year', 'First Semester'],
            ['PATHFIT1', '1st Year', 'First Semester'],
            ['CFLM1', '1st Year', 'First Semester'],
            ['NSTP1', '1st Year', 'First Semester'],

            // First Year - Second Semester
            ['UTS', '1st Year', 'Second Semester'],
            ['MMW', '1st Year', 'Second Semester'],
            ['GENSOC', '1st Year', 'Second Semester'],
            ['PPC', '1st Year', 'Second Semester'],
            ['CRIM2', '1st Year', 'Second Semester'],
            ['LEA1', '1st Year', 'Second Semester'],
            [$major2, '1st Year', 'Second Semester'],
            ['PATHFIT2', '1st Year', 'Second Semester'],
            ['NSTP2', '1st Year', 'Second Semester'],

            // Second Year - First Semester
            ['STS', '2nd Year', 'First Semester'],
            ['ETHICS', '2nd Year', 'First Semester'],
            ['PIC', '2nd Year', 'First Semester'],
            ['CDI1', '2nd Year', 'First Semester'],
            ['LEA2', '2nd Year', 'First Semester'],
            ['CRIM3', '2nd Year', 'First Semester'],
            [$major3, '2nd Year', 'First Semester'],
            [$major4, '2nd Year', 'First Semester'],
            ['PATHFIT3', '2nd Year', 'First Semester'],

            // Second Year - Second Semester
            ['TCW', '2nd Year', 'Second Semester'],
            ['ART', '2nd Year', 'Second Semester'],
            ['CFLM2', '2nd Year', 'Second Semester'],
            ['CRIM4', '2nd Year', 'Second Semester'],
            ['FORENSIC1', '2nd Year', 'Second Semester'],
            ['CDI2', '2nd Year', 'Second Semester'],
            ['CHEM', '2nd Year', 'Second Semester'],
            [$major5, '2nd Year', 'Second Semester'],
            [$major6, '2nd Year', 'Second Semester'],
            ['PATHFIT4', '2nd Year', 'Second Semester'],

            // Third Year - First Semester
            ['CORR1', '3rd Year', 'First Semester'],
            ['LAW2', '3rd Year', 'First Semester'],
            ['LAW3', '3rd Year', 'First Semester'],
            ['CDI3', '3rd Year', 'First Semester'],
            ['CDI4', '3rd Year', 'First Semester'],
            ['CRIM5', '3rd Year', 'First Semester'],
            [$forensicY3S1, '3rd Year', 'First Semester'],

            // Third Year - Second Semester
            ['CORR2', '3rd Year', 'Second Semester'],
            ['LEA3', '3rd Year', 'Second Semester'],
            ['LAW4', '3rd Year', 'Second Semester'],
            ['LAW5', '3rd Year', 'Second Semester'],
            ['CDI5', '3rd Year', 'Second Semester'],
            ['RESEARCH1', '3rd Year', 'Second Semester'],
            ['CDI6', '3rd Year', 'Second Semester'],
            [$forensicY3S2a, '3rd Year', 'Second Semester'],
            [$forensicY3S2b, '3rd Year', 'Second Semester'],

            // Fourth Year - First Semester
            ['CORR3', '4th Year', 'First Semester'],
            ['LEA4', '4th Year', 'First Semester'],
            ['CDI7', '4th Year', 'First Semester'],
            ['CRIM6', '4th Year', 'First Semester'],
            ['RESEARCH2', '4th Year', 'First Semester'],
            ['ENHANCE1', '4th Year', 'First Semester'],

            // Fourth Year - Second Semester
            ['LAW6', '4th Year', 'Second Semester'],
            ['RIZAL', '4th Year', 'Second Semester'],
            ['CDI8', '4th Year', 'Second Semester'],
            ['CDI9', '4th Year', 'Second Semester'],
            ['ENHANCE2', '4th Year', 'Second Semester'],
        ];
    }

    /**
     * Attaches the two BSCRIM Practicum/OJT items (Y4S1 and Y4S2), which
     * are identical in placement across all four majors and
     * share the same underlying PRACTICUM1-BSCRIM / PRACTICUM2-BSCRIM
     * subjects. No prospectus in this set prints an explicit hour figure
     * for these (just "3 FIELD 3" units) — ojt_hours left null.
     */
    private function attachBscrimPracticum(Curriculum $curriculum): void
    {
        $this->attachOjt($curriculum, 'PRACTICUM1-BSCRIM', '4th Year', 'First Semester', null);
        $this->attachOjt($curriculum, 'PRACTICUM2-BSCRIM', '4th Year', 'Second Semester', null);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. BSCRIM — Major in Firearms Identification (Forensic Ballistics)
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM), Major in
    | Firearms Identification" CMO No. 05 S. 2018, A.Y. 2023-2027 PDF.
    | Curriculum code: BSCRIM-FB-2023-2027 (per CurriculumSeeder — note the
    | curriculum uses specialization code "FB" while the subject catalog
    | uses prefix "FAI"; both are correct per their respective seeders).
    |
    | Third Year electives per this program's own prospectus:
    | Y3S1 -> FORENSIC2 (Personal Identification Techniques)
    | Y3S2 -> FORENSIC4 (Questioned Documents Examination) + FORENSIC6
    |         (Lie Detection Techniques) — FAI skips FORENSIC5 (Forensic
    |         Ballistics) since that's its own major's entire focus.
    |
    */
    private function seedBscrimFirearms(): void
    {
        $curriculum = Curriculum::where('code', 'BSCRIM-FB-2023-2027')->firstOrFail();

        $items = $this->bscrimSharedItems(
            'FAI1', 'FAI2', 'FAI3', 'FAI4', 'FAI5', 'FAI6',
            'FORENSIC2', 'FORENSIC4', 'FORENSIC6'
        );

        // Fourth Year - First/Second Semester open with Practicum in the
        // prospectus; Practicum is attached separately below via
        // attachBscrimPracticum().
        $this->attachSubjects($curriculum, $items);
        $this->attachBscrimPracticum($curriculum);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. BSCRIM — Major in Fingerprint Identification
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM), Major in
    | Fingerprint Identification" CMO No. 05 S. 2018, A.Y. 2023-2027 PDF.
    | Curriculum code: BSCRIM-FI-2023-2027 (per CurriculumSeeder).
    |
    | Third Year electives per this program's own prospectus:
    | Y3S1 -> FORENSIC4 (Questioned Documents Examination)
    | Y3S2 -> FORENSIC5 (Forensic Ballistics) + FORENSIC6 (Lie Detection
    |         Techniques) — FI skips FORENSIC2 (Personal Identification
    |         Techniques) entirely; not printed in this program's PDF.
    |
    */
    private function seedBscrimFingerprint(): void
    {
        $curriculum = Curriculum::where('code', 'BSCRIM-FI-2023-2027')->firstOrFail();

        $items = $this->bscrimSharedItems(
            'FI1', 'FI2', 'FI3', 'FI4', 'FI5', 'FI6',
            'FORENSIC4', 'FORENSIC5', 'FORENSIC6'
        );

        $this->attachSubjects($curriculum, $items);
        $this->attachBscrimPracticum($curriculum);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. BSCRIM — Major in Lie Detection (Polygraph)
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM), Major in Lie
    | Detection" CMO No. 05 S. 2018, A.Y. 2023-2027 PDF.
    | Curriculum code: BSCRIM-LD-2023-2027 (per CurriculumSeeder).
    |
    | Third Year electives per this program's own prospectus:
    | Y3S1 -> FORENSIC2 (Personal Identification Techniques)
    | Y3S2 -> FORENSIC4 (Questioned Documents Examination) + FORENSIC5
    |         (Forensic Ballistics) — LD skips FORENSIC6 (Lie Detection
    |         Techniques) since that's its own major's entire focus.
    |
    */
    private function seedBscrimLieDetection(): void
    {
        $curriculum = Curriculum::where('code', 'BSCRIM-LD-2023-2027')->firstOrFail();

        $items = $this->bscrimSharedItems(
            'LD1', 'LD2', 'LD3', 'LD4', 'LD5', 'LD6',
            'FORENSIC2', 'FORENSIC4', 'FORENSIC5'
        );

        $this->attachSubjects($curriculum, $items);
        $this->attachBscrimPracticum($curriculum);
    }

    /*
    |--------------------------------------------------------------------------
    | 8. BSCRIM — Major in Questioned Documents Examination
    |--------------------------------------------------------------------------
    |
    | Source: "BACHELOR OF SCIENCE IN CRIMINOLOGY (BSCRIM), Major in
    | Questioned Documents Examination" CMO No. 05 S. 2018, A.Y. 2023-2027
    | PDF. Curriculum code: BSCRIM-QD-2023-2027 (per CurriculumSeeder).
    |
    | Third Year electives per this program's own prospectus:
    | Y3S1 -> FORENSIC2 (Personal Identification Techniques)
    | Y3S2 -> FORENSIC5 (Forensic Ballistics) + FORENSIC6 (Lie Detection
    |         Techniques) — QD skips FORENSIC4 (Questioned Documents
    |         Examination) since that's its own major's entire focus.
    |
    */
    private function seedBscrimQuestionedDocuments(): void
    {
        $curriculum = Curriculum::where('code', 'BSCRIM-QD-2023-2027')->firstOrFail();

        $items = $this->bscrimSharedItems(
            'QD1', 'QD2', 'QD3', 'QD4', 'QD5', 'QD6',
            'FORENSIC2', 'FORENSIC5', 'FORENSIC6'
        );

        $this->attachSubjects($curriculum, $items);
        $this->attachBscrimPracticum($curriculum);
    }
}