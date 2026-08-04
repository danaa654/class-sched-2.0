<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * The roles available in CLASSLY.
     *
     * @var list<string>
     */
    public const ROLES = [
        'Administrator',
        'Registrar',
        'Assistant Dean',
        'Dean',
        'OIC',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }
    }
}