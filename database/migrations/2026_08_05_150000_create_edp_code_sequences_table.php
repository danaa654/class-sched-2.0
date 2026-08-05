<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One running counter per (Major, Academic Year, Semester) — the
     * scope EDPCodeService draws its next EDP Code sequence number
     * from. Kept in its own table, separate from section_subjects, so
     * deleting a scheduled SectionSubject can never free up its
     * sequence number for reuse: the counter only ever goes up.
     */
    public function up(): void
    {
        Schema::create('edp_code_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('major_id')->constrained('majors')->cascadeOnDelete();
            // e.g. "2026-2027" — matches sections.academic_year verbatim.
            $table->string('academic_year');
            // '1' First Semester, '2' Second Semester, '3' Summer.
            $table->char('semester_code', 1);
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['major_id', 'academic_year', 'semester_code'], 'edp_code_sequences_scope_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('edp_code_sequences');
    }
};