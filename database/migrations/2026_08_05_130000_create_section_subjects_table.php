<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Section Subject is the placement of one master Subject inside
     * one Section's subject list. This is NOT the schedule — no
     * faculty, room, or time is attached here. The scheduling engine
     * reads from this table later to know which subjects a Section
     * still needs scheduled.
     */
    public function up(): void
    {
        Schema::create('section_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->enum('source', ['Curriculum', 'Manual'])->default('Manual');
            $table->text('remarks')->nullable();
            $table->timestamps();

            // A Subject cannot be duplicated within the same Section.
            // (The same Subject may still belong to many Sections.)
            $table->unique(['section_id', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('section_subjects');
    }
};