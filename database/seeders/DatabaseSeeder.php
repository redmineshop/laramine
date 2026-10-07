<?php

namespace Database\Seeders;

use App\Models\User;
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
        $this->call(DefaultAccessSeeder::class);

        // Placeholder digest, admin = false. This row cannot sign in.
        User::factory()->create([
            'login' => 'admin',
            'firstname' => 'Test',
            'lastname' => 'User',
        ]);
    }
}
