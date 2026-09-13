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
        // Append-only workflow/assignment history (DEC-010), structurally
        // mirroring service_report_actions (Phase 18) exactly, but with
        // an event set matching the investigation-oriented lifecycle
        // (App\Enums\IncidentReportActionType: reported/assigned/
        // reassigned/investigation_started/resolved/closed/reopened).
        // Every assignment/reassignment and every workflow transition is
        // recorded here — an ordinary content PATCH is never logged as
        // an action (docs/phases/V1_PHASE_19_DEFINITION.md's Action
        // History). This is a scoped, per-module history table, never a
        // stand-in for the still-unbuilt, project-wide Audit Logging
        // concern (DEC-009).
        Schema::create('incident_report_actions', function (Blueprint $table): void {
            // Deliberately no public_id (DEC-017) — an action entry is
            // never independently addressed by URL, only read as part of
            // its parent Incident Report's own resource shape. Unlike
            // service_report_actions (which in practice rarely cascades,
            // since only a still-`draft` Service Report can be deleted),
            // this cascade fires routinely: an Incident Report's own
            // `reported` action is created alongside the record itself,
            // so even the earliest deletable (reported, unassigned)
            // incident already carries at least one action row.
            $table->foreignId('incident_report_id')->constrained('incident_reports')->cascadeOnDelete();

            // App\Enums\IncidentReportActionType.
            $table->string('action');

            // The acting User — nullOnDelete so the history row itself
            // is never deleted merely because the acting account later
            // is. Nullable only for that reason; always set in practice.
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // An optional note on any transition (mirrors
            // service_report_actions.note/leave_request_actions.note).
            $table->string('note', 1000)->nullable();

            $table->timestamps();

            $table->index(['incident_report_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_report_actions');
    }
};
