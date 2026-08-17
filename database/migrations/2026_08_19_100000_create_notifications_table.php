<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEDULING NOTIFICATION SYSTEM.
 *
 * Backend-driven notifications for scheduling events (finalize/unlock/
 * schedule updates/conflicts). The backend is the source of truth —
 * a row here is only ever inserted from inside the same DB transaction
 * as the operation it reports on (see NotificationService + the
 * SectionController::finalize()/unlock() and
 * SectionSubjectController::performScheduleAssignmentUpdate() call
 * sites), never created directly from the frontend.
 *
 * Deliberately separate from any audit log (see the
 * create_schedule_audit_logs_table migration) — this table is for
 * INFORMING users, the audit log is for TRACKING what happened. A
 * notification can be deleted/expire without losing history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // Who this notification is for. No onDelete cascade —
            // deleting a user should not silently wipe the
            // notification history other rows/reports may still
            // reference; the FK is nullable so a deleted user's old
            // notifications remain queryable if ever needed.
            $table->foreignId('recipient_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // e.g. SCHEDULE_FINALIZED, SCHEDULE_UNLOCKED,
            // SCHEDULE_UPDATED, SCHEDULE_CONFLICT — see
            // NotificationService::TYPE_* constants for the full list.
            $table->string('type');

            $table->string('title');
            $table->text('message');

            // Structured payload (change list for SCHEDULE_UPDATED,
            // subject counts for SCHEDULE_FINALIZED, etc.) so the
            // frontend can render richer detail than the plain-text
            // message without a second query.
            $table->json('data')->nullable();

            // Reference back to the relevant Section/SectionSubject so
            // clicking the notification can route straight to
            // scheduling.section-subjects.show — see
            // NotificationController::redirect(). No onDelete
            // cascade FK here on purpose: a deleted Section shouldn't
            // silently delete a Dean/OIC's notification history, the
            // click-through just becomes a no-op fallback to the
            // Sections list in that (rare) case.
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('section_subject_id')->nullable();

            // Who/what triggered this notification, for display
            // ("finalized by Administrator") without an extra join in
            // the common case where the actor account still exists.
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['recipient_user_id', 'is_read']);
            $table->index(['section_id']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};