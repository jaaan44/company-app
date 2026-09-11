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
        Schema::create('staff_statuses', function (Blueprint $table) {
            $table->id();

            // Deliberately no public_id (DEC-017) — a status entry is never
            // independently addressed by URL, only read via its parent
            // Staff's own collection (GET /me/status, GET /staff/{id}/status).
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();

            // App\Enums\OperationalStatus — 'available'/'busy'/'in_meeting'/
            // 'in_field'/'off_duty'. Deliberately distinct from
            // staff.status (App\Enums\StaffStatus, employment lifecycle,
            // Phase 7) — this table is never read or written by that
            // concept and vice versa.
            $table->string('status');

            // Who performed this change — the staff member themselves
            // (self-service) or an Administrator correction on their
            // behalf. Lightweight traceability only, not a general audit
            // subsystem. nullOnDelete: the history entry itself is never
            // deleted just because the acting user account later is.
            $table->foreignId('changed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // "Current status" is derived as the latest row per staff
            // member (Staff::latestOperationalStatus(), latestOfMany()) —
            // this index supports that lookup and ordinary history
            // pagination without a denormalized cache column.
            $table->index(['staff_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_statuses');
    }
};
