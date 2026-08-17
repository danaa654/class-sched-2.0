<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEDULING NOTIFICATION SYSTEM — audit trail.
 *
 * Deliberately separate from `notifications` (spec Section 8):
 * notifications are for INFORMING users and may be transient, this
 * table is the permanent record of WHAT ACTUALLY HAPPENED — one row
 * per field-level change (or per discrete action like finalize/
 * unlock), always written inside the same DB transaction as the
 * change it records, so a rolled-back operation never leaves an
 * orphaned audit row behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // e.g. FINALIZED, UNLOCKED, SCHEDULE_UPDATED — see
            // NotificationService::TYPE_* constants, reused here so
            // the two tables never drift on naming.
            $table->string('action');

            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('section_subject_id')->nullable();

            // What changed, e.g. "Room", "Faculty", "Time", "Day".
            // Null for section-level actions (finalize/unlock) that
            // aren't a single-field change.
            $table->string('field')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['section_id']);
            $table->index(['action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_audit_logs');
    }
};