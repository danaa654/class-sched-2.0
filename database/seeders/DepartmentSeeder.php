<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Real departments (programs/specializations) keyed by their college code.
     */
    public const DEPARTMENTS = [
        'CCS' => [
            ['name' => 'BSIT', 'code' => 'CCS-BSIT'],
        ],
        'CTE' => [
            ['name' => 'BSED', 'code' => 'CTE-BSED'],
        ],
        'COC' => [
            ['name' => 'BSCRIMQD', 'code' => 'COC-BSCRIMQD'],
            ['name' => 'BSCRIMFI', 'code' => 'COC-BSCRIMFI'],
            ['name' => 'BSCRIMFB', 'code' => 'COC-BSCRIMFB'],
            ['name' => 'BSCRIMLD', 'code' => 'COC-BSCRIMLD'],
        ],
        'SHTM' => [
            ['name' => 'BSHM', 'code' => 'SHTM-BSHM'],
            ['name' => 'BSTM', 'code' => 'SHTM-BSTM'],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::DEPARTMENTS as $collegeCode => $departments) {
            $college = College::where('code', $collegeCode)->first();

            if (! $college) {
                continue;
            }

            foreach ($departments as $department) {
                Department::firstOrCreate(
                    ['code' => $department['code']],
                    [...$department, 'college_id' => $college->id]
                );
            }
        }
    }
}