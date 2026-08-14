<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds Practicum/OJT support to the Subject Library (see prompt
     * "Add Practicum/OJT Subject Type to Classly").
     *
     * `subject_type` is the delivery-type switch the rest of the
     * system (Curriculum, Section Subjects, Scheduling, Auto Generate
     * Schedule, Reports) keys off of:
     *
     *  - regular    -> classroom/laboratory subject, requires a Room.
     *  - practicum  -> Practicum/OJT/Internship/Fieldwork/Clinical
     *                  Practice, conducted off-campus. Never assigned
     *                  a classroom or laboratory Room and never
     *                  participates in Room conflict detection.
     *
     * The three columns below only apply when subject_type =
     * 'practicum' and are nullable for every 'regular' row.
     */
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->enum('subject_type', ['regular', 'practicum'])
                ->default('regular')
                ->after('category');

            // Total hours the student must complete off-campus (e.g.
            // 240 for a standard OJT block). Distinct from
            // lecture_hours/laboratory_hours, which describe weekly
            // in-classroom meeting time and don't apply here.
            $table->unsignedSmallInteger('required_hours')->nullable()->after('laboratory_hours');

            // On-Campus vs Off-Campus deployment. Only meaningful for
            // Practicum/OJT subjects.
            $table->enum('deployment_type', ['on_campus', 'off_campus'])
                ->nullable()
                ->after('required_hours');

            // Free-text deployment notes (partner company, supervisor,
            // dates, etc. can be layered on top of this later without
            // another migration).
            $table->text('deployment_remarks')->nullable()->after('deployment_type');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn(['subject_type', 'required_hours', 'deployment_type', 'deployment_remarks']);
        });
    }
};