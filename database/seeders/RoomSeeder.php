<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\Department;
use App\Models\Room;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Source: PAP room inventory list (room_code / room_type / program(s) /
     * floor). The source's own "Lecture" / "Laboratory" split matches the
     * Room Master's room_type options directly, so it's used as-is.
     *
     * Rooms are now wired to real `colleges` / `departments` records
     * (seeded by CollegeSeeder / DepartmentSeeder, which must run before
     * this seeder — see DatabaseSeeder) instead of a free-text remark.
     *
     * Mapping used:
     *   BSED    -> College of Teacher Education (CTE)            / BSED
     *   BSIT    -> College of Computer Studies (CCS)              / BSIT
     *   BSHM    -> School of Hospitality and Tourism Mgmt (SHTM)  / BSHM
     *   BSTM    -> School of Hospitality and Tourism Mgmt (SHTM)  / BSTM
     *   COC     -> College of Criminology (COC), department left
     *              null — the source doesn't say which of the 4
     *              BSCRIM specializations (BSCRIMQD/FI/FB/LD) owns
     *              the room, so it's scoped to the college only
     *              ("all programs" within Criminology).
     *   General -> college_id / department_id both left null
     *              ("All Colleges" / "All Programs").
     *
     * "building" isn't in the source list — every row uses "Main Building"
     * as a placeholder. Update the BUILDING constant below once the real
     * building name/names are known. Likewise "capacity" wasn't in the
     * source — every row defaults to 40 as a placeholder.
     */
    private const BUILDING = 'Main Building';

    private const DEFAULT_CAPACITY = 40;

    /**
     * Program note -> department code (from DepartmentSeeder::DEPARTMENTS).
     * 'General' and 'COC' are handled separately (see resolveScope()).
     *
     * @var array<string, string>
     */
    private const PROGRAM_DEPARTMENT_CODES = [
        'BSED' => 'CTE-BSED',
        'BSIT' => 'CCS-BSIT',
        'BSHM' => 'SHTM-BSHM',
        'BSTM' => 'SHTM-BSTM',
    ];

    public function run(): void
    {
        $rooms = [

            // 1st Floor
            ['room_code' => 'Room 108', 'room_type' => 'Lecture', 'programs' => ['BSED'], 'floor' => '1st Floor'],
            ['room_code' => 'Room 109', 'room_type' => 'Lecture', 'programs' => ['BSED'], 'floor' => '1st Floor'],
            ['room_code' => 'Room 110', 'room_type' => 'Lecture', 'programs' => ['General'], 'floor' => '1st Floor'],
            ['room_code' => 'Room 111', 'room_type' => 'Lecture', 'programs' => ['General'], 'floor' => '1st Floor'],
            ['room_code' => 'Ground Zero', 'room_type' => 'Laboratory', 'programs' => ['COC'], 'floor' => '1st Floor'],

            // 2nd Floor
            ['room_code' => 'MEZ 110', 'room_type' => 'Lecture', 'programs' => ['General'], 'floor' => '2nd Floor'],
            ['room_code' => 'MEZ 111', 'room_type' => 'Lecture', 'programs' => ['General'], 'floor' => '2nd Floor'],
            ['room_code' => 'Room 201 (Forensic BSCRIM Lab)', 'room_type' => 'Laboratory', 'programs' => ['COC'], 'floor' => '2nd Floor'],
            ['room_code' => 'Room 202 (Forensic Chemistry Lab)', 'room_type' => 'Laboratory', 'programs' => ['COC'], 'floor' => '2nd Floor'],
            ['room_code' => 'Room 203', 'room_type' => 'Lecture', 'programs' => ['COC'], 'floor' => '2nd Floor'],
            ['room_code' => 'Room 204', 'room_type' => 'Lecture', 'programs' => ['COC'], 'floor' => '2nd Floor'],
            ['room_code' => 'Room 205', 'room_type' => 'Lecture', 'programs' => ['COC'], 'floor' => '2nd Floor'],

            // 3rd Floor
            ['room_code' => 'Room 301 (BSHM Lab)', 'room_type' => 'Laboratory', 'programs' => ['BSHM'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 302 (Travel Agency Office)', 'room_type' => 'Laboratory', 'programs' => ['BSTM'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 303', 'room_type' => 'Lecture', 'programs' => ['General'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 304 (ICT Workshop)', 'room_type' => 'Laboratory', 'programs' => ['BSIT'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 305 (Lab 2)', 'room_type' => 'Laboratory', 'programs' => ['BSIT'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 306 (Lab 1)', 'room_type' => 'Laboratory', 'programs' => ['BSIT'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 307 (Accre)', 'room_type' => 'Lecture', 'programs' => ['General'], 'floor' => '3rd Floor'],

            // 4th Floor
            ['room_code' => 'FBS/BSHM Function Hall', 'room_type' => 'Laboratory', 'programs' => ['BSHM'], 'floor' => '4th Floor'],
            ['room_code' => 'Foods/Cookery Lab', 'room_type' => 'Laboratory', 'programs' => ['BSHM'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 401 Functional Hall', 'room_type' => 'Lecture', 'programs' => ['COC'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 403', 'room_type' => 'Lecture', 'programs' => ['General'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 404 (BSCRIM1)', 'room_type' => 'Lecture', 'programs' => ['COC'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 405 (BSCRIM2)', 'room_type' => 'Lecture', 'programs' => ['COC'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 406 (BSCRIM3)', 'room_type' => 'Lecture', 'programs' => ['COC'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 407 (BSCRIM4)', 'room_type' => 'Lecture', 'programs' => ['COC'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 408 (BSTM)', 'room_type' => 'Lecture', 'programs' => ['BSTM'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 409 (BSTM)', 'room_type' => 'Lecture', 'programs' => ['BSTM'], 'floor' => '4th Floor'],

        ];

        foreach ($rooms as $data) {
            [$collegeId, $departmentId, $remarks] = $this->resolveScope($data['programs']);

            Room::updateOrCreate(
                ['room_code' => $data['room_code']],
                [
                    // room_name has no separate source value — the code
                    // itself is already a readable label (e.g. "Room 304
                    // (ICT Workshop)"), so it doubles as the name for now.
                    'room_name' => $data['room_code'],
                    'building' => self::BUILDING,
                    'floor' => $data['floor'],
                    'room_type' => $data['room_type'],
                    'college_id' => $collegeId,
                    'department_id' => $departmentId,
                    'capacity' => self::DEFAULT_CAPACITY,
                    'status' => 'Active',
                    'remarks' => $remarks,
                ]
            );
        }
    }

    /**
     * Resolve a room's program note(s) into a [college_id, department_id, remarks] tuple.
     *
     * - 'General' -> no college, no department ("All Colleges" / "All Programs").
     * - 'COC'     -> College of Criminology, no specific department (which of
     *                the 4 BSCRIM specializations isn't specified by the source).
     * - Anything else (BSED/BSIT/BSHM/BSTM) -> resolved via
     *   PROGRAM_DEPARTMENT_CODES to its department, and that department's college.
     *
     * @param  list<string>  $programs
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private function resolveScope(array $programs): array
    {
        $program = $programs[0] ?? 'General';

        if ($program === 'General') {
            return [null, null, null];
        }

        if ($program === 'COC') {
            $college = College::where('code', 'COC')->first();

            return [$college?->id, null, 'All Programs (College of Criminology — specialization not specified)'];
        }

        $departmentCode = self::PROGRAM_DEPARTMENT_CODES[$program] ?? null;
        $department = $departmentCode ? Department::where('code', $departmentCode)->first() : null;

        return [$department?->college_id, $department?->id, null];
    }
}