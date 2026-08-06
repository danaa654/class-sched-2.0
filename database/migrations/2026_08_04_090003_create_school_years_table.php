<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Alongside the core School Year fields, this table also carries
     * the Scheduling Preferences the Auto Schedule AI reads from the
     * currently Active School Year:
     *
     *   - class_start_time / class_end_time — the earliest/latest a
     *     class may be scheduled.
     *   - time_interval — the increment (in minutes) the engine
     *     slices the day into when generating candidate time slots.
     *   - available_days — which days of the week the engine is
     *     allowed to generate schedules on.
     *   - lunch_start / lunch_end — stored for the record, but the
     *     Lunch Break rule is always enforced in code as a fixed
     *     12:00 PM - 1:00 PM window (see
     *     SchoolYear::LUNCH_BREAK_START/END) and is never editable
     *     through the School Year form regardless of these columns.
     */
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('start_year');
            $table->unsignedSmallInteger('end_year');
            $table->enum('status', ['Active', 'Inactive'])->default('Inactive');

            // Scheduling Preferences — used by the Auto Schedule AI.
            $table->time('class_start_time')->default('07:00:00');
            $table->time('class_end_time')->default('17:00:00');
            $table->unsignedSmallInteger('time_interval')->default(30);
            $table->json('available_days')->nullable();
            $table->time('lunch_start')->default('12:00:00');
            $table->time('lunch_end')->default('13:00:00');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_years');
    }
};