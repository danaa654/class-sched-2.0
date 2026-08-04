<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Curriculum Item is a placement of one master Subject inside one
     * Curriculum, at a given Year Level / Semester. It never duplicates
     * the Subject record — it only references it, so the same Subject
     * (e.g. NSTP 1) can be placed in many Curriculums independently.
     */
    public function up(): void
    {
        Schema::create('curriculum_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curriculums')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->enum('year_level', ['1st Year', '2nd Year', '3rd Year', '4th Year']);
            $table->enum('semester', ['First Semester', 'Second Semester', 'Summer']);
            // Optional prerequisite — another master Subject that must be
            // taken first. Nullable, and cleared (not cascade-deleted) if
            // that Subject is ever removed from the master list.
            $table->foreignId('prerequisite_subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            // A Subject can only appear once within the same Curriculum.
            $table->unique(['curriculum_id', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curriculum_items');
    }
};