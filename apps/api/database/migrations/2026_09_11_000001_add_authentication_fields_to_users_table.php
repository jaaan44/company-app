<?php

use App\Enums\AccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Externally addressable identifier (DEC-017). Nullable+unique
            // rather than not-null: avoids a backfill/DBAL column-modify
            // step for a table that may already have rows, while the User
            // model always assigns one on creation for every new row.
            $table->ulid('public_id')->nullable()->unique()->after('id');

            // Account state foundation (Phase 4). String, not a native
            // MySQL ENUM, for portability across MySQL/SQLite and so the
            // valid-value set stays owned by App\Enums\AccountStatus rather
            // than the schema.
            $table->string('status')->default(AccountStatus::Active->value)->after('password');

            // Deliberately minimal, transitional mechanism to distinguish
            // Admin Backoffice access from ordinary (e.g. future mobile
            // employee) accounts, pending Phase 5's granular RBAC — see
            // DEC-023. Not a permissions system.
            $table->boolean('is_admin')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['public_id', 'status', 'is_admin']);
        });
    }
};
