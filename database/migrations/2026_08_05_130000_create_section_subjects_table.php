<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Section Subject is the placement of one master Subject inside
     * one Section's subject list. This is NOT the schedule — no
     * faculty, room, or time is attached here. The scheduling engine
     * reads from this table later to know which subjects a Section
     * still needs scheduled.
     */
    public function up(): void
    {
        Schema::create('section_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->enum('source', ['Curriculum', 'Manual'])->default('Manual');

            // EDP Code — auto-generated the moment this Subject is placed
            // into the Section (see EDPCodeService), before any Faculty,
            // Room, Days, or Time is assigned. Never regenerated or
            // edited once set. Unique so two rows can never end up
            // sharing the same code.
            $table->string('edp_code', 20)->nullable()->unique();

            // Schedule placeholders. Left empty when the subject is
            // added — Faculty, Room, and Time are assigned later by
            // the scheduling engine, never automatically here.
            $table->unsignedInteger('capacity')->nullable();

            // Room Capacity Warning (Section Capacity > Room Capacity)
            // is a confirmable, non-blocking warning — the Registrar
            // can "Save Anyway". This persists that acknowledgment so
            // it stays resolved across page loads instead of
            // re-flagging the exact same, already-confirmed mismatch
            // every time the schedule is reloaded. Reset to false the
            // moment the Room actually changes (see overrideRoom() /
            // onRoomChange()), since a new Room may or may not still
            // fit.
            $table->boolean('capacity_confirmed')->default(false);

            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('days')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            // Weekly Hours Mismatch (scheduled Days x Start/End total
            // doesn't equal the Subject's required weekly hours) — the
            // same confirmable/non-blocking/persisted pattern as
            // capacity_confirmed above. Reset to false whenever
            // Days/Start/End actually change.
            $table->boolean('hours_confirmed')->default(false);

            $table->enum('status', ['Draft', 'Scheduled', 'Conflict'])->default('Draft');

            // Auto Generate Schedule (Prompt 8.9) — set when the Faculty,
            // Room, and Time on this row came from AutoScheduleService
            // rather than a manual edit. Lets "Clear Generated Schedule"
            // and "Regenerate" safely touch only rows the engine itself
            // produced, never anything the Registrar assigned by hand.
            $table->boolean('is_auto_generated')->default(false);

            // Recommendation Score breakdown (Faculty/Room/Time scores +
            // reasons) captured at the moment this row was auto-generated,
            // so the review panel can redisplay "why" without re-running
            // the engine. Cleared whenever the row is cleared/edited.
            $table->json('auto_generated_meta')->nullable();

            $table->text('remarks')->nullable();
            $table->timestamps();

            // A Subject cannot be duplicated within the same Section.
            // (The same Subject may still belong to many Sections.)
            $table->unique(['section_id', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('section_subjects');
    }
};