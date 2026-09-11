<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Foundational authorization system data (Phase 5 — Roles & Permissions,
 * DEC-028): the V1 role catalog and the minimal permission catalog that
 * proves the authorization mechanism. Deliberately separate from
 * AdminUserSeeder — this is safe, deterministic system data intended to
 * run in every environment (local, testing, staging, production), never
 * gated behind an environment check and never containing credentials.
 *
 * Idempotent throughout (firstOrCreate/updateOrCreate matched on the
 * unique 'name' column) — safe to run repeatedly, and safe regardless of
 * whether the 'administrator' row was already created defensively by the
 * 2026_09_11_050003_add_role_id_to_users_table migration's is_admin
 * backfill.
 *
 * Administrator deliberately receives no explicit permission rows here —
 * it holds every ability via the centralized Gate::before override in
 * App\Providers\AppServiceProvider::boot(), not by attaching every
 * permission to it (see DEC-028). Manager and Staff intentionally start
 * with no permissions in V1 (CLAUDE.md §10) — later phases attach real
 * permissions as their modules are built.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        Role::query()->firstOrCreate(
            ['name' => Role::ADMINISTRATOR],
            ['label' => 'Administrator'],
        );

        Role::query()->firstOrCreate(
            ['name' => Role::MANAGER],
            ['label' => 'Manager'],
        );

        Role::query()->firstOrCreate(
            ['name' => Role::STAFF],
            ['label' => 'Staff'],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'admin.access'],
            ['label' => 'Access the Admin Backoffice'],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'authorization.manage'],
            ['label' => "Manage users' roles and permissions"],
        );

        $this->command?->info('Role/permission catalog ready (Administrator, Manager, Staff).');
    }
}
