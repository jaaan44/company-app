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
        Schema::create('leave_request_actions', function (Blueprint $table) {
            $table->id();

            // Deliberately no public_id (DEC-017) — an action entry is
            // never independently addressed by URL, only read as part of
            // its parent Leave Request's own resource shape (mirrors
            // project_memberships/staff_statuses's identical reasoning).
            // cascadeOnDelete: child data with no independent meaning; in
            // practice a Leave Request is never itself deleted, so this
            // never actually fires.
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();

            // App\Enums\LeaveRequestActionType — submitted/approved/
            // rejected/cancelled. Append-only (DEC-010) — never updated
            // in place.
            $table->string('action');

            // The acting User — nullOnDelete so the history row itself
            // is never deleted merely because the acting account later
            // is. Nullable only for that reason; always set in practice.
            $table->foreignId('acted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // The rejection/cancellation reason, or an optional note on
            // submission/approval. No threaded comments.
            $table->string('note', 1000)->nullable();

            $table->timestamps();

            $table->index(['leave_request_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_request_actions');
    }
};
