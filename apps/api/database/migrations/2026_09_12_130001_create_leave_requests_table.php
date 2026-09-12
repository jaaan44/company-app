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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — independently
            // addressed via /api/v1/leave-requests/{public_id} and
            // /api/v1/me/leave-requests/{public_id}.
            $table->ulid('public_id')->unique();

            // The requester. restrictOnDelete: a Staff member with any
            // Leave Request cannot be deleted (see
            // StaffController::destroy) — HR history is never orphaned
            // via cascade.
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();

            // restrictOnDelete: a Leave Type with any Leave Request
            // cannot be hard-deleted (see LeaveTypeController::destroy) —
            // deactivation is the correct tool instead.
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');

            // Server-computed only, never client-supplied — inclusive
            // calendar-day count between start_date/end_date (see
            // docs/phases/V1_PHASE_13_DEFINITION.md's Leave Quantity —
            // no business-day/holiday-aware calculation, weekends not
            // excluded).
            $table->unsignedInteger('total_days');

            // Required — material to a Manager's approval decision, the
            // same accountability rationale as work_logs.description.
            $table->string('reason', 1000);

            // App\Enums\LeaveRequestStatus — pending/approved/rejected/
            // cancelled. Deliberately no submitted_at/approved_at/
            // rejected_at/cancelled_at columns here — submission time is
            // created_at, and every decision/cancellation timestamp lives
            // only on leave_request_actions (see that migration) to avoid
            // a dual-write risk between two sources of the same fact.
            $table->string('status')->default('pending');

            // Accountability only — distinguishes a self-service request
            // from one an Administrator entered on the staff member's
            // behalf. nullOnDelete mirrors work_logs.created_by_user_id/
            // tasks.created_by_user_id.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['staff_id', 'status']);
            $table->index(['staff_id', 'start_date', 'end_date']);
            $table->index(['leave_type_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
