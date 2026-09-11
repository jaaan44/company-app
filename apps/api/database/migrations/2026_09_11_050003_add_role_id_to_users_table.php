<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Retires the Phase 4 transitional `is_admin` boolean (DEC-024) in
     * favor of Phase 5's role-based authorization (DEC-028): adds a
     * nullable `role_id` foreign key, migrates any existing
     * `is_admin = true` account onto the Administrator role, then drops
     * `is_admin` — no two competing authorization mechanisms survive
     * this migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable: a user with no role assigned yet has no
            // permissions (default-deny), never an implicit one.
            $table->foreignId('role_id')->nullable()->after('is_admin')
                ->constrained('roles')->nullOnDelete();
        });

        // The 'administrator' role (App\Models\Role::ADMINISTRATOR) is the
        // only one this backfill needs. It's created here defensively
        // (idempotent — matched by its unique 'name') so this migration is
        // self-contained and correct regardless of whether
        // RolePermissionSeeder has already run in this environment; the
        // seeder's own idempotent insert simply finds this row already
        // present when it runs.
        $administratorId = DB::table('roles')->where('name', 'administrator')->value('id');

        if ($administratorId === null) {
            $administratorId = DB::table('roles')->insertGetId([
                'name' => 'administrator',
                'label' => 'Administrator',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('users')->where('is_admin', true)->update(['role_id' => $administratorId]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('status');
        });

        DB::table('users')
            ->whereIn('role_id', DB::table('roles')->where('name', 'administrator')->select('id'))
            ->update(['is_admin' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
