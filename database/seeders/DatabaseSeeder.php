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

        User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'username' => 'superadmin',
            'phone' => '+2348136834496',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'username' => 'johndoe',
            'phone' => '+1234567890',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
         User::factory(10)->create();
    }
}
