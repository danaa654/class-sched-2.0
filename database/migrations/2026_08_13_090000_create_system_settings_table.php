<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A single, centralized key/value store for SYSTEM-WIDE
     * configuration (see SettingsService). This intentionally does
     * NOT duplicate the School Year / Academic Term scheduling
     * columns (class_start_time, class_end_time, time_interval,
     * available_days, lunch_start/lunch_end) — those remain owned by
     * `school_years` (see App\Models\SchoolYear) and are only linked
     * to from the Settings page, never re-stored here.
     *
     * `group` lets the Settings page and SettingsController query one
     * section at a time without parsing every key. `value` is stored
     * as text and cast by SettingsService (booleans/ints/json are
     * encoded as JSON so a single column can hold any settings type).
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('group')->index();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};