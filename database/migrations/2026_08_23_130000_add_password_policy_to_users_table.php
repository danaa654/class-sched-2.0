<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY / PASSWORD POLICY — adds the two columns the new Settings
 * > Security tab actually enforces against (see App\Http\Middleware\
 * EnsurePasswordIsCurrent and App\Services\PasswordPolicyService):
 *
 * - `password_changed_at`: stamped every time a password is actually
 *   set (admin create/edit, self-service Manage Account, reset-link
 *   flow). Used to compute expiry against
 *   security.password_expiry_days.
 * - `must_change_password`: the per-user "require password change on
 *   next login" toggle an Administrator can flip from User
 *   Management.
 *
 * Backfill choice for existing rows: `password_changed_at` is set to
 * `now()` rather than each row's original `created_at`. Backfilling
 * to `created_at` would mean the moment an Administrator turns on
 * password expiry for the first time, every account older than the
 * expiry window is instantly forced to change its password on its
 * very next request — effectively a surprise mass lockout the first
 * time the feature is used. Backfilling to `now()` instead gives
 * every existing account a fresh, full expiry window starting from
 * whenever this migration runs, so turning expiry on is never itself
 * the thing that locks people out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('password');
            $table->boolean('must_change_password')->default(false)->after('password_changed_at');
        });

        DB::table('users')->whereNull('password_changed_at')->update([
            'password_changed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_changed_at', 'must_change_password']);
        });
    }
};