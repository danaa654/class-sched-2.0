<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faculty Workload Validation System (Prompt: "Implement Faculty
 * Workload Validation and Smart Faculty Assignment").
 *
 * Adds:
 *   - faculties.workload_type          — 'units' (default) or 'hours'.
 *     Whichever the institution measures teaching load by. All
 *     scheduling code reads this instead of assuming Units.
 *   - faculties.max_weekly_hours       — the Weekly Hours ceiling,
 *     used only when workload_type = 'hours'. max_teaching_units
 *     already exists (created 2026_08_05_090000) and is used when
 *     workload_type = 'units'.
 *   - section_subjects.is_workload_override — true when this
 *     placement was saved despite exceeding the assigned faculty
 *     member's max teaching load, via an explicit Administrator
 *     override ("Override & Save").
 *   - section_subjects.workload_override_by   — the User who approved
 *     the override, for audit purposes.
 *   - section_subjects.workload_override_note — optional free-text
 *     reason captured at override time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->enum('workload_type', ['units', 'hours'])
                ->default('units')
                ->after('max_teaching_units');

            $table->unsignedSmallInteger('max_weekly_hours')
                ->nullable()
                ->after('workload_type');
        });

        Schema::table('section_subjects', function (Blueprint $table) {
            $table->boolean('is_workload_override')->default(false)->after('is_auto_generated');

            $table->foreignId('workload_override_by')
                ->nullable()
                ->after('is_workload_override')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('workload_override_note')->nullable()->after('workload_override_by');
        });
    }

    public function down(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workload_override_by');
            $table->dropColumn(['is_workload_override', 'workload_override_note']);
        });

        Schema::table('faculties', function (Blueprint $table) {
            $table->dropColumn(['workload_type', 'max_weekly_hours']);
        });
    }
};