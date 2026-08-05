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
        Schema::create('faculties', function (Blueprint $table) {
            $table->id();

            // Human-assigned identifier (e.g. employee number). Not the
            // primary key on purpose — registrar staff manage/reassign this
            // independently of the internal row id.
            $table->string('faculty_id')->unique();

            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();

            $table->enum('employment_type', ['Full-time', 'Part-time', 'Contractual']);

            // Department Faculty belong to a College/Department (both
            // required below by the app's validation). General Education
            // Faculty (GenEd/Minor — English, Math, Filipino, NSTP, PE,
            // etc.) don't belong to one, so college_id/department_id are
            // nullable and left null for them.
            $table->enum('faculty_category', ['Department Faculty', 'General Education Faculty'])
                ->default('Department Faculty');

            $table->foreignId('college_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('specialization')->nullable();

            // Teaching load ceiling used later by the scheduling module to
            // prevent overloading a faculty member. Defaults to 21 units.
            $table->unsignedTinyInteger('max_teaching_units')->default(21);

            $table->enum('status', ['Active', 'Inactive'])->default('Active');

            $table->string('email')->nullable();
            $table->string('contact_number')->nullable();
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
        Schema::dropIfExists('faculties');
    }
};