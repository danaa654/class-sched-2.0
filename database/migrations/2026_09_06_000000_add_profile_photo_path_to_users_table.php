<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds users.profile_photo_path — the storage disk path (relative
     * to the "public" disk) of an uploaded profile photo, e.g.
     * "profile-photos/3-a1b2c3.jpg". Nullable: most users won't have
     * uploaded one, and the UI falls back to their initials (see
     * User::getProfilePhotoUrlAttribute()). Available to every role
     * via the shared "Manage Account" tab (Settings for
     * Registrar/Dean/OIC/Assistant Dean, Users for Administrator).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_photo_path')->nullable()->after('suffix');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_photo_path');
        });
    }
};