<?php

namespace Database\Seeders;

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
     * The source also grouped rooms by the program(s) that use them
     * (BSED, BSIT, BSHM, BSTM, COC, General). Room-to-program matching
     * isn't part of the schema yet, so that's preserved as a note in
     * `remarks` for now rather than dropped — wire it into a real
     * relationship once that feature exists.
     *
     * "building" isn't in the source list — every row uses "Main Building"
     * as a placeholder. Update the BUILDING constant below once the real
     * building name/names are known. Likewise "capacity" wasn't in the
     * source — every row defaults to 40 as a placeholder.
     */
    private const BUILDING = 'Main Building';

    private const DEFAULT_CAPACITY = 40;

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
                    'capacity' => self::DEFAULT_CAPACITY,
                    'status' => 'Active',
                    'remarks' => 'Program(s): '.implode(', ', $data['programs']),
                ]
            );
        }
    }
}