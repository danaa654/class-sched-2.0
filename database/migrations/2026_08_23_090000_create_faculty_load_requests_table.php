<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faculty Load Change Requests.
 *
 * Problem this closes: previously ANY role that could edit a Faculty
 * record (Admin, Registrar, Dean, OIC, Assistant Dean) could freely
 * change max_teaching_units / max_weekly_hours, which is a scheduling
 * -integrity hazard once Auto Schedule and the workload service trust
 * that number.
 *
 * New model: only Administrator/Registrar may edit the field directly
 * (see FacultyPolicy::changeMaxLoad()). Dean / OIC / Assistant Dean
 * instead submit a request here with a required justification note;
 * an Administrator/Registrar reviews and approves/denies it. Approval
 * is what actually updates Faculty::max_teaching_units /
 * max_weekly_hours (see FacultyLoadRequestController::approve()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_load_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('faculty_id')->constrained('faculties')->cascadeOnDelete();

            // Snapshot of what's being requested, independent of the
            // Faculty's live workload_type — a request always states
            // both so the reviewer can see the full picture.
            $table->unsignedSmallInteger('current_max_teaching_units');
            $table->unsignedSmallInteger('requested_max_teaching_units');
            $table->unsignedSmallInteger('current_max_weekly_hours')->nullable();
            $table->unsignedSmallInteger('requested_max_weekly_hours')->nullable();

            // Required justification — e.g. "BSIT short 2 faculty this
            // sem, requesting overload for Networking subjects."
            $table->text('reason');

            $table->enum('status', ['Pending', 'Approved', 'Denied'])->default('Pending');

            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_load_requests');
    }
};