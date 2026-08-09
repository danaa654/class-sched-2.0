<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTELLIGENT IRREGULAR SECTION SCHEDULING.
 *
 * A merged Irregular-section placement doesn't get its own Faculty/
 * Room/Time booking — it literally rides along on an existing Regular
 * section's class session (see IrregularSectionMergeService). These
 * columns record that relationship and keep the last computed
 * recommendation around for the "Merge Recommendation" modal so the
 * Administrator can review candidates, scores, and validation
 * results without re-running the search:
 *
 *  - is_merged: true once this row has actually been folded into
 *    another SectionSubject's class session (vs. scheduled
 *    independently).
 *  - merged_into_section_subject_id: which SectionSubject (always
 *    belonging to a Regular section) this row's schedule was copied
 *    from. Self-referencing FK, nulled (not cascaded) if that row is
 *    ever deleted — this row simply reverts to "needs scheduling"
 *    rather than disappearing.
 *  - merge_recommendation: the full recommendation payload (best
 *    match, every compatible candidate considered, scores, and the
 *    reason independent scheduling was recommended when no candidate
 *    qualified) — see IrregularSectionMergeService::recommend().
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->boolean('is_merged')->default(false)->after('is_auto_generated');
            $table->foreignId('merged_into_section_subject_id')
                ->nullable()
                ->after('is_merged')
                ->constrained('section_subjects')
                ->nullOnDelete();
            $table->json('merge_recommendation')->nullable()->after('merged_into_section_subject_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('section_subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_section_subject_id');
            $table->dropColumn(['is_merged', 'merge_recommendation']);
        });
    }
};