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
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();

            // Deliberately no public_id (DEC-017) — never independently
            // addressed by URL; addressed only via its Staff member's
            // public_id plus leave_type_id/year in an upsert-style
            // management request body (mirrors project_memberships'
            // identical reasoning — not a standalone business entity
            // with its own detail page).
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();

            // Calendar year (docs/phases/V1_PHASE_13_DEFINITION.md's
            // Leave Balances — the narrowest period model, no
            // fiscal-year complexity).
            $table->unsignedSmallInteger('year');

            // The entitlement an Administrator has set for this Staff
            // member/Leave Type/year. Usage (approved/pending days
            // consumed) is deliberately NOT stored here — it is always
            // derived live from leave_requests (see
            // LeaveBalanceResource) to avoid a dual-write risk between a
            // cached remaining-balance column and the requests that
            // actually consume it.
            $table->unsignedInteger('allocated_days');

            $table->string('notes', 500)->nullable();

            // Accountability — who set/last updated this allocation.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['staff_id', 'leave_type_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
