<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\Faculty;
use Illuminate\Database\Seeder;

class FacultySeeder extends Seeder
{
    /**
     * Old-roster employment type label -> current `employment_type` enum value.
     */
    private const EMPLOYMENT_TYPE = [
        'Full-Time' => 'Full-time',
        'Part-Time' => 'Part-time',
    ];

    /**
     * Employment type -> max_teaching_units mapping, per business rule.
     */
    private const MAX_TEACHING_UNITS = [
        'Full-Time' => 24,
        'Part-Time' => 18,
    ];

    /**
     * Honorific tokens with no dedicated column on the faculties table.
     * Stripped out during name parsing so they don't leak into first_name.
     * Preserved instead as a note in `remarks`.
     */
    private const HONORIFICS = ['DR.', 'DR', 'MR.', 'MR', 'MS.', 'MS', 'MRS.', 'MRS'];

    /**
     * Recognized name suffixes (checked against the last token only).
     */
    private const SUFFIXES = ['JR.', 'JR', 'SR.', 'SR', 'II', 'III', 'IV', 'V'];

    /**
     * Running counter used to generate stable, human-readable faculty_id
     * values (e.g. "FAC-0001"). Stable across reseeds because the roster
     * below is processed in the same order every run.
     */
    private int $counter = 0;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Cache college_id lookups by code so we only hit the DB once per
        // college instead of once per faculty row.
        $collegeIds = College::pluck('id', 'code');

        foreach ($this->facultyByCollege() as $code => $facultyList) {
            $collegeId = $code === null ? null : ($collegeIds[$code] ?? null);

            foreach ($facultyList as $row) {
                $this->counter++;

                $nameParts = $this->parseFullName($row['full_name']);
                $employmentType = self::EMPLOYMENT_TYPE[$row['employment_type']];

                $remarksParts = [];

                if ($nameParts['title']) {
                    $remarksParts[] = "Title: {$nameParts['title']}";
                }

                if ($code !== null && $row['faculty_scope'] === 'cross_department') {
                    // Genuinely teaches across the whole college rather
                    // than one specific program — college_id alone
                    // represents them; there's no finer-grained field
                    // to record that against anymore.
                    $remarksParts[] = 'Teaches across multiple programs within the college.';
                }

                Faculty::updateOrCreate(
                    // Match on email — unique per faculty, stable across reseeds.
                    ['email' => $row['email']],
                    [
                        'faculty_id'          => sprintf('FAC-%04d', $this->counter),
                        'first_name'          => $nameParts['first_name'],
                        'middle_name'         => $nameParts['middle_name'],
                        'last_name'           => $nameParts['last_name'],
                        'suffix'              => $nameParts['suffix'],
                        'employment_type'     => $employmentType,
                        // A Faculty member with no College is, by
                        // definition, General Education Faculty — see
                        // Faculty::getFacultyCategoryAttribute(), which
                        // derives the category from this column so the
                        // two can never disagree.
                        'college_id'          => $collegeId,
                        'max_teaching_units'  => self::MAX_TEACHING_UNITS[$row['employment_type']],
                        'status'              => 'Active',
                        'remarks'             => $remarksParts ? implode(' ', $remarksParts) : null,
                    ]
                );
            }
        }
    }

    /**
     * Hardcoded faculty roster, grouped by home college code.
     * Key = colleges.code, or null for General Education faculty
     * (no college/department).
     *
     * @return array<string|null, array<int, array{full_name: string, email: string, employment_type: string, faculty_scope: string}>>
     */
    private function facultyByCollege(): array
    {
        return [

            // ----------------------------------------------------------------
            // GENED — General Education (no college, scope = general)
            // ----------------------------------------------------------------
            null => [
                ['full_name' => 'ABELLANA, JOSHUA', 'email' => 'joshua.abellana@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'ABESIA, ELMER I.', 'email' => 'elmer.abesia@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'ABOYO, VICTOR B.', 'email' => 'victor.aboyo@pap.edu.ph', 'employment_type' => 'Part-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'CABAÑERO, CHRISTOPHER REY R.', 'email' => 'christopher.cabanero@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'CATIG, GAUDENCIO S. JR.', 'email' => 'gaudencio.catig@pap.edu.ph', 'employment_type' => 'Part-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'DAYUJA, RONALD', 'email' => 'ronald.dayuja@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'ENERLAN, EDWIN A.', 'email' => 'edwin.enerlan@pap.edu.ph', 'employment_type' => 'Part-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'EVALLE, SAMUEL', 'email' => 'samuel.evalle@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'GEMINA, JOHN CARLO', 'email' => 'john.gemina@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'GERAT, ARIEL', 'email' => 'ariel.gerat@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'MENDAROS, BENHUR', 'email' => 'benhur.mendaros@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'PANTALLANO, MA. SOCORRO V.', 'email' => 'ma.pantallano@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'PULMONES, OTTO VON A.', 'email' => 'otto.pulmones@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'REYES, AIRESSE E.', 'email' => 'airesse.reyes@pap.edu.ph', 'employment_type' => 'Part-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'SALAGOSTE, JEFFREY V.', 'email' => 'jefrey.salagoste@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'TABURADA, RICHELLE M.', 'email' => 'richelle.taburada@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'general'],
                ['full_name' => 'VELARDE, PABLO', 'email' => 'pablo.velarde@pap.edu.ph', 'employment_type' => 'Part-Time', 'faculty_scope' => 'general'],
            ],

            // ----------------------------------------------------------------
            // CCS — College of Computer Studies
            // ----------------------------------------------------------------
            'CCS' => [
                ['full_name' => 'BACUS, BOBKERI O.', 'email' => 'bobkeri.bacus@pap.edu.ph', 'employment_type' => 'Part-Time', 'faculty_scope' => 'departmental'],
                ['full_name' => 'POLO, BENJIE S.', 'email' => 'benjie.polo@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'departmental'],
                ['full_name' => 'TALAVERA, WHELJINE C.', 'email' => 'wheljine.talavera@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'departmental'],
                ['full_name' => 'VILLACORTA, BERNCE JOEY A.', 'email' => 'bernce.villacorta@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
            ],

            // ----------------------------------------------------------------
            // COC — College of Criminology
            //
            // COC has 4 majors/departments (BSCRIMQD, BSCRIMFI, BSCRIMFB,
            // BSCRIMLD — see DepartmentSeeder), but they're all one college
            // and the source roster doesn't tie any faculty member to just
            // one of the four — so, like SHTM, department_id stays null and
            // college_id alone represents them.
            // ----------------------------------------------------------------
            'COC' => [
                ['full_name' => 'ALFANTE, ADRIAN LI P.', 'email' => 'adrian.alfante@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'ANSAY, MARY GRACE D.', 'email' => 'mary.ansay@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'ARSOLA, CHERRIE MIE V.', 'email' => 'cherrie.arsola@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'AUTENTICO, FRESCEL E.', 'email' => 'frescel.autentico@pap.edu.ph', 'employment_type' => 'Part-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'BERNADOS, MARC', 'email' => 'marc.bernados@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'CALANG, DR. VERONICA V.', 'email' => 'veronica.calang@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'EVALLE, MAELJHEN V.', 'email' => 'maeljhen.evalle@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'GALULA, CHARLIE', 'email' => 'charlie.galula@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'ISRAEL, EDELBERT G.', 'email' => 'edelbert.israel@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'JUAREZ, EFFREY REY A.', 'email' => 'effrey.juarez@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'NOVAL, DR. FILEMON E.', 'email' => 'filemon.noval@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'PADAYAO, RENDON B.', 'email' => 'rendon.padayao@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'PRANGOS, DARYL P.', 'email' => 'daryl.prangos@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'PULVERA, WILFREDO P.', 'email' => 'wilfredo.pulvera@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'VILLAHERMOSA, MARDY B.', 'email' => 'mardy.villahermosa@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
            ],

            // ----------------------------------------------------------------
            // CTE — College of Teacher Education
            // ----------------------------------------------------------------
            'CTE' => [
                ['full_name' => 'ARO, ARTEMIA', 'email' => 'artemia.aro@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'BADECAÑAS, RICARDO C.', 'email' => 'ricardo.badecanas@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'departmental'],
                ['full_name' => 'CANOMON, DR. NENITA S.', 'email' => 'nenita.canomon@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'departmental'],
                ['full_name' => 'GARCIA, VIRRA FLOR T.', 'email' => 'virra.garcia@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'departmental'],
                ['full_name' => 'GEONZON, CARLO ISIDRO', 'email' => 'carlo.geonzon@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'departmental'],
                ['full_name' => 'LABRADOR, MARY GRACE J.', 'email' => 'mary.labrador@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'departmental'],
                ['full_name' => 'NAVIDAD, RONALD N.', 'email' => 'ronald.navidad@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'PAUSAL, NOEL', 'email' => 'noel.pausal@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'RUFILA, RICHARD S.', 'email' => 'richard.rufila@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'SECUYA, JAY T.', 'email' => 'jay.secuya@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'departmental'],
                ['full_name' => 'SEVILLE, REGIL KENT M.', 'email' => 'regil.seville@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
            ],

            // ----------------------------------------------------------------
            // SHTM — School of Hospitality and Tourism Management
            //
            // SHTM has 2 majors/departments (BSHM, BSTM), but they're both
            // one college and the source roster doesn't tie any faculty
            // member to just one of the two — so, like the cross_department
            // rows in CCS/CTE, department_id stays null and college_id
            // alone represents them.
            // ----------------------------------------------------------------
            'SHTM' => [
                ['full_name' => 'CUI, JOECYLIN R.', 'email' => 'joecylin.cui@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'LLANTO, MYRA MAE C.', 'email' => 'myra.llanto@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'MIRAFUENTES, HONEY ANGELU M.', 'email' => 'honey.mirafuentes@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'MONTEFALCON, RHODORA MARTINA', 'email' => 'rhodora.montefalcon@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'UBAS, DR. MARIA GRACITA T.', 'email' => 'maria.ubas@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
                ['full_name' => 'YECYEC, ANABELLE B.', 'email' => 'anabelle.yecyec@pap.edu.ph', 'employment_type' => 'Full-Time', 'faculty_scope' => 'cross_department'],
            ],

        ];
    }

    /**
     * Split a "LAST, FIRST [MIDDLE] [SUFFIX]" style name into the
     * first_name / middle_name / last_name / suffix columns used by
     * the faculties table.
     *
     * Handles:
     *  - Honorific prefixes with no dedicated column ("DR.", "MS.", etc.)
     *    — returned separately as `title` so callers can preserve it
     *    (e.g. in `remarks`) instead of silently discarding it.
     *  - Trailing suffixes ("JR.", "SR.", "III", ...)
     *  - Trailing middle initials ("P.", "V.", ...) — distinguished from
     *    multi-letter tokens like "Ma." which stay part of the first name.
     *
     * @return array{first_name: string, middle_name: ?string, last_name: string, suffix: ?string, title: ?string}
     */
    private function parseFullName(string $fullName): array
    {
        [$lastName, $rest] = array_pad(explode(',', $fullName, 2), 2, '');

        $tokens = array_values(array_filter(
            preg_split('/\s+/', trim($rest)),
            fn (string $token) => $token !== ''
        ));

        // Pull out (and remember) any honorific title — no column for
        // these, but worth preserving as a remark rather than dropping.
        $title = null;
        $tokens = array_values(array_filter(
            $tokens,
            function (string $token) use (&$title) {
                if (in_array(strtoupper($token), self::HONORIFICS, true)) {
                    $title = ucfirst(strtolower(rtrim($token, '.'))).'.';

                    return false;
                }

                return true;
            }
        ));

        $suffix = null;
        if (! empty($tokens)) {
            $last = strtoupper(end($tokens));
            if (in_array($last, self::SUFFIXES, true)) {
                $suffix = array_pop($tokens);
                // Normalize casing, e.g. "JR." -> "Jr."
                $suffix = ucfirst(strtolower($suffix));
            }
        }

        $middleName = null;
        if (! empty($tokens)) {
            $last = end($tokens);
            // A single letter followed by a period, e.g. "P." — a middle
            // initial. Multi-letter tokens like "Ma." are left alone.
            if (preg_match('/^[A-Za-z]\.$/', $last)) {
                $middleName = strtoupper(array_pop($tokens));
            }
        }

        return [
            'first_name'  => trim(implode(' ', $tokens)),
            'middle_name' => $middleName,
            'last_name'   => trim($lastName),
            'suffix'      => $suffix,
            'title'       => $title,
        ];
    }
}