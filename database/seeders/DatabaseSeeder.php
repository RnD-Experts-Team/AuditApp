<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'audit',
                'email' => 'audit@example.com',
                'role' => 'Admin',
                'password' => 'auditpassword',
                'email_verified_at' => now(),
            ]

        );
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'ebaa',
                'email' => 'ebaa@example.com',
                'role' => 'User',
                'password' => '12345678',
                'email_verified_at' => now(),
            ]

        );

        $this->call([
            CameraFormTablesSeeder::class,
        ]);
    }
}
