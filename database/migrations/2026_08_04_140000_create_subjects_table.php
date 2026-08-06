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
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('subject_code')->unique();
            $table->string('subject_title');
            // Nullable — General Education subjects are not tied to a Major.
            $table->foreignId('major_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('category', ['Major', 'General Education']);
            $table->unsignedTinyInteger('units')->default(0);
            $table->unsignedTinyInteger('lecture_hours')->default(0);
            $table->unsignedTinyInteger('laboratory_hours')->default(0);
            $table->string('preferred_room_category')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};