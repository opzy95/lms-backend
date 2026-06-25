<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create admin user
        User::create([
            'name' => 'Admin User',
            'email' => 'opzyenterprise95@gmail.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_approved' => true,
        ]);

        // Create tutor users
        User::create([
            'name' => 'Tutor One',
            'email' => 'opzyenterprise95+tutor1@gmail.com',
            'password' => Hash::make('password'),
            'role' => 'tutor',
            'is_approved' => true,
        ]);

        User::create([
            'name' => 'Tutor Two',
            'email' => 'opzyenterprise95+tutor2@gmail.com',
            'password' => Hash::make('password'),
            'role' => 'tutor',
            'is_approved' => true,
        ]);

        // Create student users
        User::create([
            'name' => 'Student One',
            'email' => 'opzyenterprise95+student1@gmail.com',
            'password' => Hash::make('password'),
            'role' => 'student',
            'is_approved' => true,
        ]);

        User::create([
            'name' => 'Student Two',
            'email' => 'opzyenterprise95+student2@gmail.com',
            'password' => Hash::make('password'),
            'role' => 'student',
            'is_approved' => true,
        ]);

        User::create([
            'name' => 'Student Three',
            'email' => 'opzyenterprise95+student3@gmail.com',
            'password' => Hash::make('password'),
            'role' => 'student',
            'is_approved' => true,
        ]);
    }
}
