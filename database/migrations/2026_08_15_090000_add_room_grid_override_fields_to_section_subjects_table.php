<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Room Scheduler (Room-Centric Time Grid) — two flags this feature
     * needs on the existing SectionSubject row, which stays the single
     * source of truth for both the Subjects List and the new Room
     * Grid view (spec Section 13). No new scheduling table is created.
     */
    public function up(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            // Room Override (spec Section 7) — true when the assigned
            // Room falls outside the recommended pool for this Subject
            // /Section (wrong department, or simply not in the
            // recommended list) and a user knowingly proceeded anyway.
            // Persisted rather than recomputed on every read, so the
            // Room Grid can render the "Orange = Warning/Override"
            // state for a block without re-running room-compatibility
            // scoring just to display it. Reset to false whenever the
            // Room actually changes to one that IS recommended.
            $table->boolean('room_is_manual_override')->default(false)->after('room_id');

            // Manually Modified (spec Section 10) — true once a row
            // that Auto Generate produced (is_auto_generated = true)
            // has since been hand-edited (drag/resize/room-or-faculty
            // change) via the Room Grid or the Subjects List. Auto
            // Generate / Regenerate must check this flag and leave
            // such rows alone unless the user explicitly confirms
            // overwriting them — see AutoScheduleService, updated in a
            // later slice of this feature. Distinct from
            // is_auto_generated itself: that flag stays true as a
            // record of *where the row originally came from*, while
            // this one separately tracks *whether it's since been
            // touched by hand*.
            $table->boolean('is_manually_modified')->default(false)->after('is_auto_generated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropColumn(['room_is_manual_override', 'is_manually_modified']);
        });
    }
};