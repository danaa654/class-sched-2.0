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
            $table->string('section_code')->unique();
            $table->string('section_name');
            $table->foreignId('major_id')->constrained('majors');
            $table->foreignId('curriculum_id')->constrained('curriculums');
            $table->string('academic_year');
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