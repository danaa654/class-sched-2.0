<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Room → Subject soft recommendations.
     *
     * A row here means "this Subject is a good fit for this Room" —
     * a preference the Registrar/Dean configures on the Room Details
     * page. It is consumed by RecommendationService::recommendRooms()
     * and AutoScheduleService as a scoring bonus only; it is never a
     * hard constraint and never locks a Subject to a Room.
     */
    public function up(): void
    {
        Schema::create('room_subject_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['room_id', 'subject_id']);
            $table->index(['subject_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_subject_recommendations');
    }
};