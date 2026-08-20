<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            // No ->unique() here — a plain DB unique index doesn't know
            // about soft deletes, so a deleted row's section_code would
            // permanently block reuse (the app-level "unique" validation
            // rule excludes trashed rows via whereNull('deleted_at'), but
            // the raw index doesn't, so the insert itself would still
            // fail). The real uniqueness constraint is added below, after
            // `deleted_at` exists, via a generated column.
            $table->string('section_code');
            $table->string('section_name');
            $table->foreignId('major_id')->constrained('majors');
            // Nullable — Regular sections require a Prospectus
            // (enforced in StoreSectionRequest/StoreSectionBatchRequest,
            // not here), but Irregular sections don't necessarily
            // follow one Prospectus, so this stays optional at the DB
            // level.
            $table->foreignId('curriculum_id')->nullable()->constrained('curriculums');
            $table->string('academic_year');
            $table->enum('semester', ['First Semester', 'Second Semester', 'Summer'])->default('First Semester');
            $table->enum('year_level', [
                'First Year',
                'Second Year',
                'Third Year',
                'Fourth Year',
            ]);
            $table->unsignedInteger('estimated_students');
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Soft-delete-aware uniqueness: this generated column mirrors
            // section_code while the row is active, and collapses to NULL
            // once deleted_at is set. MySQL's unique index then only ever
            // sees one live "section_code" per code, WITHIN THE SAME
            // academic_year + semester — any number of deleted rows can
            // share it (they all read as NULL, and NULLs aren't
            // considered duplicates in a unique index), and the same
            // code is free to be reused in a different academic_year or
            // semester (e.g. "BSIT-4A" existing in 2026-2027 · First
            // Semester does not block "BSIT-4A" in 2026-2027 · Second
            // Semester — that's a separate, valid Section).
            // CASE WHEN (not MySQL's IF()) so this generated column works
            // identically on MySQL (production) and SQLite (tests).
            $table->string('section_code_active')
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN section_code ELSE NULL END')
                ->nullable();

            $table->unique(['section_code_active', 'academic_year', 'semester']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};