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
        // Foundational authorization system data (Phase 5) — safe,
        // deterministic, run in every environment. See
        // RolePermissionSeeder's own docblock for why this is kept
        // separate from AdminUserSeeder's local-only demo credentials.
        $this->call(RolePermissionSeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
