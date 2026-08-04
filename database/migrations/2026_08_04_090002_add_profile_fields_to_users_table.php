<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the fields needed by the User Management module on top of
     * Laravel's default users table. The existing `name` column is left
     * in place (used by AdminSeeder / Spatie) so nothing breaks; it can
     * be backfilled from first/middle/last name via an accessor later.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id')->unique()->nullable()->after('id');
            $table->string('first_name')->nullable()->after('employee_id');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('suffix')->nullable()->after('last_name');
            $table->string('status')->default('Active')->after('password');

            $table->foreignId('college_id')->nullable()->after('status')
                ->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('college_id')
                ->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('college_id');
            $table->dropColumn([
                'employee_id',
                'first_name',
                'middle_name',
                'last_name',
                'suffix',
                'status',
            ]);
        });
    }
};