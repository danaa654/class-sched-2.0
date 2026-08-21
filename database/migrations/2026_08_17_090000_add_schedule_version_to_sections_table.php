<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONCURRENCY HARDENING — Optimistic Concurrency Control.
 *
 * Adds a monotonically increasing `schedule_version` counter to every
 * Section. This is the single source of truth the backend uses to
 * detect stale writes:
 *
 *   - The Scheduling workspace (SectionSubjectController::show()) and
 *     every write response return the Section's CURRENT
 *     schedule_version.
 *   - Any request that writes to that Section's schedule (manual cell
 *     edit, Room Grid move, Save Schedule batch submit, Auto
 *     Generate) may optionally submit `expected_schedule_version`.
 *   - The write is only committed if the submitted version still
 *     matches the CURRENT database version, checked under a row lock
 *     inside the same transaction as the write (see
 *     ScheduleConflictService::lockResources() /
 *     checkSectionVersion() / bumpScheduleVersion()).
 *   - On success, schedule_version is incremented by exactly 1 as
 *     part of the same transaction. On any failure (conflict or stale
 *     version) the transaction rolls back and the counter is left
 *     untouched.
 *
 * Defaults to 1 (not 0) so "no version supplied yet" (null) can never
 * be confused with "loaded version 0" by an older/partial frontend
 * payload.
 *
 * ACTOR-AWARE VERSION — `schedule_version_updated_by` records WHO
 * most recently advanced schedule_version. Plain version-number
 * polling can't tell "a different user changed this Section" apart
 * from "the SAME user bumped it from a second tab, or a different
 * page like the Subject Assignment screen" — both look identical as
 * a bare integer change, and the latter would otherwise fire a false
 * "Another user changed this schedule" warning on a Section nobody
 * else has touched. The version-check endpoint
 * (SectionSubjectController::scheduleVersion()) returns this
 * alongside schedule_version so the frontend can compare it against
 * the logged-in user and only warn when it's genuinely someone else.
 * Nullable: a version bump from a system/console context with no
 * authenticated user has no known actor — treated as "unknown" and
 * still warned about (fail toward caution, not toward silence).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_version')->default(1)->after('remarks');
            $table->foreignId('schedule_version_updated_by')
                ->nullable()
                ->after('schedule_version')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_version_updated_by');
            $table->dropColumn('schedule_version');
        });
    }
};