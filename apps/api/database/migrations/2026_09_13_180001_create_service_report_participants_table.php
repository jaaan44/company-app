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
        // A plain many-to-many pivot with no surrogate key (mirrors
        // schedule_entry_participants/role_permissions) — a participant
        // has no role/status hierarchy, RSVP, or workflow-management
        // authority (docs/phases/V1_PHASE_18_DEFINITION.md's
        // Participants) — it means only "gains visibility into this
        // report." The creator/primary performer is deliberately never
        // duplicated into this table — their standing authority is
        // derived from service_reports.creator_staff_id directly,
        // mirroring Schedule Entry Participants' identical precedent.
        Schema::create('service_report_participants', function (Blueprint $table) {
            // Child data with no independent meaning apart from its
            // Service Report — cascadeOnDelete (only a still-draft
            // report may ever be deleted, so this fires only for a
            // draft's own participant rows).
            $table->foreignId('service_report_id')->constrained('service_reports')->cascadeOnDelete();

            // restrictOnDelete: a Staff member who is still a participant
            // on any Service Report cannot be deleted (see
            // StaffController::destroy) — mirrors
            // schedule_entry_participants.staff_id.
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();

            $table->primary(['service_report_id', 'staff_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_report_participants');
    }
};
