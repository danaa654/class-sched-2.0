<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates the single Administrator account. This system does not allow
     * public registration — all further accounts must be created by an
     * Administrator through the application itself.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $admin = User::firstOrCreate(
            ['email' => 'admin@classly.pap'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('admin@classly.pap12'),
                'email_verified_at' => now(),
            ]
        );

        if (! $admin->hasRole('Administrator')) {
            $admin->assignRole('Administrator');
        }
    }
}