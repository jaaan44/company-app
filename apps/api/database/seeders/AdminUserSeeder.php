<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a single local development Admin Backoffice account, with the
 * Administrator role (Phase 5 — DEC-028; previously the transitional
 * `is_admin` flag, retired).
 *
 * NOT run automatically by DatabaseSeeder — invoke explicitly:
 *
 *   php artisan db:seed --class=Database\\Seeders\\AdminUserSeeder
 *
 * The email/password below are development-only placeholders, never
 * production credentials. Refuses to run outside a local environment as
 * a safeguard against accidentally seeding a real deployment.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->error('AdminUserSeeder only runs in local/testing environments.');

            return;
        }

        // Guarantees the Administrator role exists regardless of whether
        // RolePermissionSeeder has already run in this environment —
        // idempotent, so calling it again here is harmless.
        $this->call(RolePermissionSeeder::class);

        $administratorRoleId = Role::query()->where('name', Role::ADMINISTRATOR)->value('id');

        // Direct attribute assignment (not mass assignment): 'role_id' is
        // deliberately excluded from the model's fillable list so it can
        // never be set via user-facing input.
        $user = User::query()->firstOrNew(['email' => 'admin@example.test']);
        $user->name = 'Local Admin';
        $user->password = Hash::make('password');
        $user->role_id = $administratorRoleId;
        $user->save();

        $this->command?->info('Development admin account ready: admin@example.test / password (local only).');
    }
}
