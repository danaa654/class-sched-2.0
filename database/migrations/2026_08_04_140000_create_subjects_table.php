<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ownership model: a Subject belongs to exactly one College
     * (required for Major subjects; nullable/"institution-wide" for
     * shared GenEd/Minor subjects) and may be APPLICABLE to several
     * Majors within that College via the subject_major pivot below —
     * never duplicated per Major.
     *
     * `major_id` is kept as a nullable "primary major" convenience
     * column, mirrored from the first row of subject_major on every
     * write (see SubjectController) — some read paths
     * (RecommendationService, RoomRecommendationController) still use
     * the single Subject::major relation.
     */
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_code')->unique();
            $table->string('subject_title');
            // Nullable — General Education/Minor subjects are not tied to a single Major.
            $table->foreignId('major_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('category', ['Major', 'General Education', 'Minor']);
            $table->unsignedTinyInteger('units')->default(0);
            $table->unsignedTinyInteger('lecture_hours')->default(0);
            $table->unsignedTinyInteger('laboratory_hours')->default(0);
            $table->string('preferred_room_category')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Many-to-many: which Major(s) a Subject is applicable to.
        // A subject is never duplicated across majors — this pivot is
        // the join instead.
        Schema::create('subject_major', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('major_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['subject_id', 'major_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subject_major');
        Schema::dropIfExists('subjects');
    }
};