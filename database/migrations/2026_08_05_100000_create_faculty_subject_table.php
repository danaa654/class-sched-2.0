<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Pure many-to-many relationship between Faculty and Subject — this
     * table stores nothing but the link itself. A row here means "this
     * faculty member is qualified to teach this subject." The
     * scheduling engine's Genetic Algorithm will later read this table
     * to ensure it never assigns a faculty member to a subject they
     * are not qualified for.
     */
    public function up(): void
    {
        Schema::create('faculty_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['faculty_id', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculty_subject');
    }
};