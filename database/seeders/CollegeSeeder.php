<?php

namespace Database\Seeders;

use App\Models\College;
use Illuminate\Database\Seeder;

class CollegeSeeder extends Seeder
{
    /**
     * Real colleges for the Professional Academy of the Philippines.
     */
    public const COLLEGES = [
        ['name' => 'College of Computer Studies', 'code' => 'CCS'],
        ['name' => 'College of Teacher Education', 'code' => 'CTE'],
        ['name' => 'College of Criminology', 'code' => 'COC'],
        ['name' => 'School of Hospitality and Tourism Management', 'code' => 'SHTM'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::COLLEGES as $college) {
            College::firstOrCreate(['code' => $college['code']], $college);
        }
    }
}