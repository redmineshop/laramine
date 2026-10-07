<?php

namespace Database\Seeders;

use App\Domain\Auth\AdministratorSeed;
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

        // Placeholder digest unless LARAMINE_ADMIN_PASSWORD is supplied outside the repository.
        app(AdministratorSeed::class)->apply();
    }
}
