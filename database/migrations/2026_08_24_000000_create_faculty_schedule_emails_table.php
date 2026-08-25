<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery history for the Faculty Schedule Email System (Reports ->
 * Faculty Schedule -> Send via Email). One row per email attempt, so a
 * faculty member can be notified more than once (schedule updates,
 * resends) and every attempt is auditable — see spec sections 10-12.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_schedule_emails', function (Blueprint $table) {
            $table->id();

            $table->foreignId('faculty_id')->constrained('faculties')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();

            // Who triggered the send (Administrator/Registrar). Nullable so
            // history survives if the sending user account is later removed.
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('recipient_email');

            // Bumped whenever a finalized schedule changes after a previous
            // successful send for the same faculty + term (spec section 12).
            $table->unsignedInteger('schedule_version')->default(1);

            // 'initial' | 'updated' | 'resend'
            $table->string('email_type')->default('initial');

            // 'pending' | 'sent' | 'failed'
            $table->string('status')->default('pending');

            $table->text('error_message')->nullable();

            // Snapshot of the schedule rows (as sent) so a later "View
            // Details" / resend doesn't depend on the live schedule, which
            // may have changed again since.
            $table->json('schedule_snapshot')->nullable();

            $table->string('pdf_filename')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['faculty_id', 'academic_term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_schedule_emails');
    }
};