<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SECTION-LEVEL SCHEDULE FINALIZATION.
 *
 * Distinct from term_college_finalizations (which locks an entire
 * college's schedule for a term) and from schedule_version (which
 * only guards against *concurrent* writes). This is a per-Section
 * workflow gate: once a Dean/Registrar finalizes a Section's
 * schedule, no further edits to that Section's SectionSubjects are
 * allowed (Room Grid drag, manual cell edit, Auto Generate) until an
 * Admin/Registrar explicitly unlocks it.
 *
 * finalized_by is intentionally nullable + no onDelete cascade
 * concern here since we set it to `nullOnDelete` — losing the actor
 * shouldn't unlock the schedule or break the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->boolean('is_finalized')->default(false)->after('status');
            $table->timestamp('finalized_at')->nullable()->after('is_finalized');
            $table->foreignId('finalized_by')
                ->nullable()
                ->after('finalized_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['is_finalized', 'finalized_at']);
        });
    }
};