<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROOM TYPE / ROOM COLLEGE MISMATCH — PERSIST THE CONFIRMATION.
 * ------------------------------------------------------------------
 * `capacity_confirmed` and `hours_confirmed` (2026_08_05_130000 /
 * 2026_08_13_120000) already persist the Registrar's "Save Anyway"
 * choice so a confirmed warning stays confirmed after reload. Room
 * Type Mismatch and Room College Mismatch were validated with the
 * exact same "confirm to save anyway" gate
 * (SectionSubjectController::updateSchedule()/batchUpdateSchedule(),
 * UpdateSectionSubjectScheduleRequest / BatchUpdateSectionSubjectScheduleRequest)
 * but the confirmation was never written to a column — only read off
 * the request and then discarded. That's why "Save Anyway" on a Room
 * Type Mismatch appeared to work (the row saved) but the exact same
 * warning came right back on the next page load / Dashboard refresh:
 * there was nothing durable recording that the Registrar had already
 * acknowledged it.
 *
 * These two columns close that gap, mirroring hours_confirmed
 * exactly: default false (nothing is confirmed until a save says so),
 * reset to false client-side the moment the Room actually changes
 * (see Show.vue's onRoomChange — already did this for the session-only
 * flag; now it also invalidates the persisted one), and read by
 * DashboardService's roomTypeMismatchDetails() so an already-confirmed
 * mismatch stops appearing in the "Room Conflicts" Dashboard tile too,
 * not just the Section Subjects page's own "Scheduling Issues" panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->boolean('room_type_confirmed')->default(false)->after('hours_confirmed');
            $table->boolean('room_college_confirmed')->default(false)->after('room_type_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropColumn(['room_type_confirmed', 'room_college_confirmed']);
        });
    }
};