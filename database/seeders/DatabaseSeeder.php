<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            CollegeSeeder::class,
            DepartmentSeeder::class,
            MajorSeeder::class,
            SubjectSeeder::class,
            RoomSeeder::class,
            CurriculumSeeder::class,
            CurriculumItemSeeder::class,
            
        ]);
    }
}