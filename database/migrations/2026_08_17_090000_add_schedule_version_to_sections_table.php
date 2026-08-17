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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_version')->default(1)->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('schedule_version');
        });
    }
};