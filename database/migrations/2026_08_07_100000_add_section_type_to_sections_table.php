<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTELLIGENT IRREGULAR SECTION SCHEDULING.
 *
 * Adds the single new field the Section form needs — 'Section Type'
 * (Regular or Irregular). Deliberately does NOT add a "Preferred
 * Merge Target" field: which Regular section an Irregular section's
 * subjects merge into is decided automatically per-subject by
 * IrregularSectionMergeService during Auto Generate Schedule, not
 * chosen once up front at Section-creation time (a single section
 * rarely has one uniform merge target across all of its subjects).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->enum('section_type', ['Regular', 'Irregular'])
                ->default('Regular')
                ->after('section_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('section_type');
        });
    }
};