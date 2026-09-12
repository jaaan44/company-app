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
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — an
            // admin-manageable business entity, mirroring
            // Department/Team/Position/Client's pattern, not the fixed
            // internal roles/permissions catalog.
            $table->ulid('public_id')->unique();

            $table->string('name')->unique();

            // Nullable, unique when present, admin-supplied — mirrors
            // clients.client_code (DEC-031): a code may not be assigned
            // immediately when a Leave Type is first created.
            $table->string('code')->nullable()->unique();

            $table->string('description')->nullable();

            // The only "policy" flag this phase adds: whether balance/
            // allocation checks apply to this type at all. Never a
            // payroll computation (see docs/phases/
            // V1_PHASE_13_DEFINITION.md's Critical Domain Boundary).
            $table->boolean('is_paid')->default(true);

            // App\Enums\LeaveTypeStatus — a dedicated two-state enum
            // (not OrganizationStatus/ClientStatus) mirroring
            // ClientStatus/ContactStatus's reasoning: Leave Type is HR
            // master data, a distinct domain.
            $table->string('status')->default('active');

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
