<?php

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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — Client is
            // admin-manageable business data, never addressed by clients
            // via its internal numeric id.
            $table->ulid('public_id')->unique();

            // Optional business reference (e.g. an internal account
            // number). Nullable and admin-supplied, not system-generated
            // (docs/phases/V1_PHASE_08_DEFINITION.md) — deliberately
            // different from Staff.employee_number (required): a client
            // record is often created before any internal code is
            // assigned.
            $table->string('client_code')->nullable()->unique();

            $table->string('name');

            // App\Enums\ClientStatus — 'active'/'inactive'. No
            // SoftDeletes — one lifecycle mechanism, matching the
            // Department/Team/Position precedent (DEC-029).
            $table->string('status')->default('active');

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();

            // A small structured address, not a polymorphic/multi-address
            // subsystem (out of scope for Phase 8).
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state_province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
