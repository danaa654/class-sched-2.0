<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FACULTY MISMATCH — PERSIST THE CONFIRMATION.
 * ------------------------------------------------------------------
 * Mirrors room_type_confirmed/room_college_confirmed
 * (2026_08_24_180000) exactly. Faculty Mismatch (see
 * SectionSubject::getFacultyMismatchAttribute() — a manually-assigned
 * Faculty who isn't Teaching-Qualification-linked to the Subject and
 * isn't from its academic home College/GenEd pool, e.g. a CCS faculty
 * member placed on a BSED Minor subject) was originally shipped as a
 * purely advisory, always-recomputed flag with no way to acknowledge
 * it — it would show up in the "Scheduling Issues" panel and the Room
 * Grid badge forever, even after the Registrar had knowingly confirmed
 * the manual override and clicked Save Schedule, unlike Hours/Room
 * Type/Room College Mismatch which all clear once confirmed.
 *
 * This column closes that gap: default false (nothing is confirmed
 * until a save says so), reset to false client-side the moment the
 * Faculty assignment actually changes (see Show.vue's
 * onFacultyChange), and read the same way hours_confirmed/
 * room_type_confirmed/room_college_confirmed already are — by
 * performScheduleAssignmentUpdate()/batchUpdateSchedule() to gate the
 * confirmable-warning 422, and by the Subjects tab's own
 * "Scheduling Issues" panel to stop re-surfacing an already-confirmed
 * mismatch on every reload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->boolean('faculty_mismatch_confirmed')->default(false)->after('room_college_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropColumn('faculty_mismatch_confirmed');
        });
    }
};