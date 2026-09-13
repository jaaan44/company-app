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
        // Append-only workflow-transition history (DEC-010), mirroring
        // leave_request_actions' exact shape (Phase 13) — see
        // docs/phases/V1_PHASE_18_DEFINITION.md's Workflow Action
        // History. This is a scoped, per-module history table, never a
        // stand-in for the still-unbuilt, project-wide Audit Logging
        // concern (DEC-009).
        Schema::create('service_report_actions', function (Blueprint $table) {
            // Deliberately no public_id (DEC-017) — an action entry is
            // never independently addressed by URL, only read as part of
            // its parent Service Report's own resource shape (mirrors
            // leave_request_actions.leave_request_id's identical
            // reasoning). cascadeOnDelete: child data with no
            // independent meaning; a Service Report may only ever be
            // deleted while still a draft (before it has any action
            // history at all in practice), so this rarely fires.
            $table->foreignId('service_report_id')->constrained('service_reports')->cascadeOnDelete();

            // App\Enums\ServiceReportActionType — submitted/rejected/
            // returned_to_draft/resubmitted/reviewed.
            $table->string('action');

            // The acting User — nullOnDelete so the history row itself
            // is never deleted merely because the acting account later
            // is. Nullable only for that reason; always set in practice.
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // The rejection reason, or an optional note on any other
            // transition (mirrors leave_request_actions.note).
            $table->string('note', 1000)->nullable();

            $table->timestamps();

            $table->index(['service_report_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_report_actions');
    }
};
