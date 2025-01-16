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
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'iskandar',
            'email' => 'iskandarisabrkov@example.com',
            'password' => Hash::make(12345678),
            'chat_id' => '6784560209',
            'role' => 'admin',
            'status' => '1',
        ]);
    }
}
