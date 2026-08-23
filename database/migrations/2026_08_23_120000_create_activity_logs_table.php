<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACTIVITY LOG — a general, cross-cutting "who did what, when" log
 * for the Settings > Activity Log tab (Administrator-only).
 *
 * Deliberately separate from `schedule_audit_logs`: that table stays
 * exactly as-is for field-level scheduling diffs tied to a Section/
 * SectionSubject (see create_schedule_audit_logs_table migration's
 * docblock). This table is coarser-grained and generic — one row per
 * notable action anywhere in the app (Settings changes, Faculty
 * added/removed, Section created/deleted, a forced logout, a
 * password reset request, etc.) — and is never queried by the
 * scheduling engine, only by the Activity Log tab.
 *
 * `subject_type`/`subject_id` is a plain (non-Eloquent) polymorphic
 * pair — just enough to remember "which record this was about" for
 * display, without pulling in Eloquent's morph-map machinery for a
 * single read-only admin screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // e.g. FACULTY_CREATED, SECTION_DELETED, SETTINGS_UPDATED,
            // SESSION_FORCE_LOGOUT, PASSWORD_RESET_REQUESTED — see
            // App\Services\ActivityLogService for the canonical list.
            $table->string('action');

            // What the action was about, e.g. App\Models\Faculty / 42.
            // Nullable — some actions (settings changes, a password
            // reset request before the user is known to exist) have
            // no single model instance to point at.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // The human-readable line shown in the Activity Log tab,
            // e.g. "Jane Cruz added faculty member Mark Reyes."
            // Built once at write time so the tab never needs to
            // reconstruct sentences from raw action codes.
            $table->text('description');

            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};