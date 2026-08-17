<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEDULING NOTIFICATION SYSTEM — priority levels (audit spec
 * Section 13). Edits the existing notifications migration in place
 * per project convention... except this one ships AFTER
 * create_notifications_table already migrated in earlier work, so a
 * follow-up add_ migration is used instead of rewriting history —
 * same pattern as add_finalization_fields_to_sections_table following
 * create_sections_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // INFO / IMPORTANT / WARNING / CRITICAL — see
            // NotificationService::PRIORITY_* constants. Defaults to
            // IMPORTANT since that's what most of today's events are;
            // every dispatch call sets it explicitly regardless.
            $table->string('priority')->default('IMPORTANT')->after('type');
            $table->index(['recipient_user_id', 'is_read', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['recipient_user_id', 'is_read', 'priority']);
            $table->dropColumn('priority');
        });
    }
};