<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops users.employee_id (added by
     * 2026_08_04_090002_add_profile_fields_to_users_table). Removed
     * from User Management by request — it was never linked to
     * anything else in the system (Faculty has its own, unrelated
     * faculty_id sequence on a separate table), so this is a clean
     * removal with no other data to migrate.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id')->unique()->nullable()->after('id');
        });
    }
};